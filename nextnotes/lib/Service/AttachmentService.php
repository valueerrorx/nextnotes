<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service;

use OCA\NextNotes\AppInfo\Application;
use OCA\NextNotes\Db\Attachment;
use OCA\NextNotes\Db\AttachmentMapper;
use OCA\NextNotes\Db\Note;
use OCA\NextNotes\Db\NoteMapper;
use OCA\NextNotes\Db\WorkspaceMapper;
use OCA\NextNotes\Service\Exception\BadRequestException;
use OCA\NextNotes\Service\Exception\NotFoundException;
use OCA\NextNotes\Service\Exception\TooLargeException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IAppData;
use OCP\Files\IMimeTypeDetector;
use OCP\Files\NotFoundException as FilesNotFoundException;
use OCP\Files\NotPermittedException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IAppConfig;

// Anhänge (Upstream AttachmentsController): Zeile in nextnotes_attachments, Datei in IAppData attachments/<note_id>/<id>
class AttachmentService {
	private const MB = 1048576;
	// Grenzen ab Werk; Admin ändert sie in der NC-Verwaltung (AdminSettingsController)
	public const DEFAULT_MAX_FILE_MB = 20;
	public const DEFAULT_QUOTA_MB = 500;
	// Upstream lib/attachments.php: ausführbare Typen (Server und Client) werden abgelehnt
	private const BLOCKED_EXT = ['asp', 'aspx', 'cgi', 'fcgi', 'jsp', 'jspx', 'phar', 'pht', 'phtml', 'shtml',
		'bash', 'bat', 'cmd', 'com', 'dll', 'dylib', 'exe', 'fish', 'jar', 'ksh', 'pl', 'ps1', 'psm1', 'py', 'rb', 'sh', 'so', 'zsh'];
	private const BLOCKED_MIME = ['application/php', 'application/x-cgi', 'application/x-httpd-cgi', 'application/x-httpd-php',
		'application/x-php', 'text/x-cgi', 'text/x-php', 'application/java-archive', 'application/vnd.microsoft.portable-executable',
		'application/x-dosexec', 'application/x-executable', 'application/x-java-archive', 'application/x-mach-binary',
		'application/x-ms-dos-executable', 'application/x-msdownload', 'application/x-perl', 'application/x-python',
		'application/x-python-code', 'application/x-ruby', 'application/x-sh', 'application/x-sharedlib', 'application/x-shellscript',
		'text/x-perl', 'text/x-python', 'text/x-ruby', 'text/x-script.python', 'text/x-sh', 'text/x-shellscript'];
	private const AUDIO_EXT = ['mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'flac' => 'audio/flac', 'aac' => 'audio/aac',
		'm4a' => 'audio/mp4', 'weba' => 'audio/webm', 'oga' => 'audio/ogg', 'opus' => 'audio/ogg'];

	public function __construct(
		private AttachmentMapper $attachments,
		private NoteMapper $notes,
		private WorkspaceMapper $workspaceMapper,
		private AccessService $access,
		private WorkspaceService $workspaces,
		private SearchTextNormalizer $search,
		private ContentUrlRewriter $urls,
		private Transaction $tx,
		private FileCleanupQueue $cleanup,
		private IAppData $appData,
		private IAppConfig $appConfig,
		private IMimeTypeDetector $mimeDetector,
		private ITimeFactory $time,
	) {
	}

	// ---------- Dateien ----------

	private function root(): ISimpleFolder {
		try {
			return $this->appData->getFolder('attachments');
		} catch (FilesNotFoundException) {
			try {
				return $this->appData->newFolder('attachments');
			} catch (NotPermittedException) {
				return $this->appData->getFolder('attachments');
			}
		}
	}

	private function noteFolder(int $noteId): ISimpleFolder {
		return $this->root()->getOrCreateFolder((string)$noteId);
	}

	private function fileOf(Attachment $a): ISimpleFile {
		try {
			return $this->root()->getFolder((string)$a->getNoteId())->getFile((string)$a->getId());
		} catch (FilesNotFoundException) {
			throw new NotFoundException('File not found');
		}
	}

	// Ordner attachments/<id> nach dem Commit löschen
	private function queueFolderDeletion(int $noteId): void {
		$this->cleanup->add(function () use ($noteId) {
			try {
				$this->root()->getFolder((string)$noteId)->delete();
			} catch (FilesNotFoundException) {
			}
		});
	}

	private function queueFileDeletion(int $noteId, int $attachmentId): void {
		$this->cleanup->add(fn () => $this->deleteFileNow($noteId, $attachmentId));
	}

	// Datei sofort löschen (Rollback-Aufräumen, Transaction::onRollback); fehlt sie, nichts
	public function deleteFileNow(int $noteId, int $attachmentId): void {
		try {
			$this->root()->getFolder((string)$noteId)->getFile((string)$attachmentId)->delete();
		} catch (FilesNotFoundException) {
		}
	}

	// ---------- Hilfen ----------

	/** @return array<string, mixed> Upstream-Eintrag im JSON-Feld attachments */
	public static function view(Attachment $a): array {
		return [
			'id' => (string)$a->getId(),
			'filename' => (string)$a->getId(),
			'original_filename' => (string)$a->getOriginalName(),
			'file_size' => (int)$a->getSize(),
			'file_type' => (string)$a->getMime(),
			'uploaded_at' => gmdate('Y-m-d H:i:s', (int)$a->getCreatedAt()),
		];
	}

	private function checkWorkspace(string $uid, Note $note, mixed $wsKey): void {
		$ws = $this->workspaces->resolveOptional($uid, $wsKey);
		if ($ws !== null && $ws->getId() !== $note->getWorkspaceId()) {
			throw new NotFoundException('Note not found');
		}
	}

	private function attachmentOn(int $noteId, int $attachmentId): Attachment {
		try {
			return $this->attachments->findOnNote($attachmentId, $noteId);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Attachment not found');
		}
	}

	/**
	 * Quota beim Workspace-Besitzer (Thomas; Team-Workspaces bis M5 einzeln). Vor der Transaktion: Besitzer lesen, Vorprüfung ohne Sperre
	 * @return array{type: string, id: string, ws: int, bytes: int}|null null = nichts zu prüfen
	 */
	public function quotaFor(int $workspaceId, int $bytes): ?array {
		if ($bytes <= 0) {
			return null;
		}
		$ws = $this->workspaceMapper->find($workspaceId);
		$quota = ['type' => (string)$ws->getOwnerType(), 'id' => (string)$ws->getOwnerId(), 'ws' => (int)$ws->getId(), 'bytes' => $bytes];
		$this->checkQuotaSum($quota);
		return $quota;
	}

	/**
	 * Erste Anweisung der Transaktion: Besitzer sperren (SQLite: Schreibsperre vor jedem Lesen), dann unter Sperre erneut prüfen
	 * @param array{type: string, id: string, ws: int, bytes: int}|null $quota
	 */
	public function enforceQuota(?array $quota): void {
		if ($quota === null) {
			return;
		}
		$this->workspaceMapper->lockOwner($quota['type'], $quota['id']);
		$this->checkQuotaSum($quota);
	}

	/** @param array{type: string, id: string, ws: int, bytes: int} $quota */
	private function checkQuotaSum(array $quota): void {
		$limit = $this->appConfig->getValueInt(Application::APP_ID, 'attachment_quota_mb', self::DEFAULT_QUOTA_MB) * self::MB;
		$wsIds = [$quota['ws']];
		if ($quota['type'] === 'user') {
			$wsIds = array_map(static fn ($w) => (int)$w->getId(), $this->workspaceMapper->findOwned($quota['id']));
		}
		if ($this->attachments->sizeInWorkspaces($wsIds) + $quota['bytes'] > $limit) {
			throw new TooLargeException('Storage quota exceeded');
		}
	}

	// Upstream poznoteValidateAttachmentFilename
	private static function cleanName(string $name): string {
		$base = trim(basename(str_replace('\\', '/', $name)));
		if ($base === '' || $base === '.' || $base === '..') {
			throw new BadRequestException('Invalid attachment filename');
		}
		if ($base[0] === '.') {
			throw new BadRequestException('Hidden attachment filenames are not allowed');
		}
		if (!mb_check_encoding($base, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $base)) {
			throw new BadRequestException('Attachment filename contains invalid characters');
		}
		if (strlen($base) > 255) {
			throw new BadRequestException('Attachment filename is too long');
		}
		foreach (array_slice(explode('.', $base), 1) as $ext) {
			$ext = strtolower($ext);
			if (preg_match('/^php[0-9]*$/', $ext) || in_array($ext, self::BLOCKED_EXT, true)) {
				throw new BadRequestException('Attachment file type is not allowed');
			}
		}
		$display = trim(str_replace(['<', '>', '"'], '', $base));
		return $display !== '' ? $display : 'attachment';
	}

	private function mimeOf(string $path, string $name): string {
		$detected = strtolower(trim(explode(';', $this->mimeDetector->detectContent($path), 2)[0]));
		if (in_array($detected, self::BLOCKED_MIME, true)) {
			throw new BadRequestException('Attachment MIME type is not allowed');
		}
		$audio = self::AUDIO_EXT[strtolower(pathinfo($name, PATHINFO_EXTENSION))] ?? null;
		if ($audio !== null && in_array($detected, ['', 'application/octet-stream', 'video/webm', 'video/mp4', 'video/ogg', 'video/quicktime'], true)) {
			return $audio;
		}
		// Allgemeines Ergebnis (z. B. SVG als text/plain) → Typ nach Dateiendung
		if (in_array($detected, ['', 'text/plain', 'text/xml', 'application/xml', 'application/octet-stream'], true)) {
			$byName = $this->mimeDetector->detectPath($name);
			if ($byName !== 'application/octet-stream') {
				return $byName;
			}
		}
		return $detected !== '' ? $detected : 'application/octet-stream';
	}

	private function newRow(int $noteId, string $name, string $mime, int $size, string $uid): Attachment {
		$a = new Attachment();
		$a->setNoteId($noteId);
		$a->setOriginalName($name);
		$a->setMime($mime);
		$a->setSize($size);
		$a->setCreatedBy($uid);
		$a->setCreatedAt($this->time->getTime());
		return $this->attachments->insert($a);
	}

	public function maxFileBytes(): int {
		return $this->appConfig->getValueInt(Application::APP_ID, 'attachment_max_file_mb', self::DEFAULT_MAX_FILE_MB) * self::MB;
	}

	// Anzeigename für erzeugte Anhänge (Base64-Bild, M4b); ungültig → $fallback
	public static function generatedName(string $base, string $ext, string $fallback): string {
		try {
			return self::cleanName(trim($base) . '.' . $ext);
		} catch (BadRequestException) {
			return $fallback . '.' . $ext;
		}
	}

	/** Erzeugte Datei (Excalidraw-Vorschau) als Anhang; Aufrufer hält die Transaktion (enforceQuota zuerst), bei Fehler discardNew */
	public function createFromString(int $noteId, string $name, string $mime, string $data, string $uid): Attachment {
		$a = $this->newRow($noteId, $name, $mime, strlen($data), $uid);
		$this->noteFolder($noteId)->newFile((string)$a->getId(), $data);
		return $a;
	}

	// Datei eines zurückgerollten Anhangs sofort entfernen
	public function discardNew(int $noteId, int $attachmentId): void {
		$this->queueFileDeletion($noteId, $attachmentId);
		$this->cleanup->flush();
	}

	// Anhang ohne Verweis entfernen: Zeile sofort, Datei nach dem Commit; unbekannt → nichts
	public function retire(int $noteId, int $attachmentId): void {
		try {
			$a = $this->attachments->findOnNote($attachmentId, $noteId);
		} catch (DoesNotExistException) {
			return;
		}
		$this->attachments->delete($a);
		$this->queueFileDeletion($noteId, $attachmentId);
	}

	// ---------- API ----------

	/** @return array<string, mixed> GET /notes/{noteId}/attachments */
	public function index(string $uid, int $noteId, mixed $wsKey): array {
		$note = $this->access->requireNote($uid, $noteId, 'read', null);
		$this->checkWorkspace($uid, $note, $wsKey);
		$owner = $this->holder($uid, $note);
		$list = $this->attachments->findForNotes([(int)$owner->getId()])[(int)$owner->getId()] ?? [];
		return ['attachments' => array_map([self::class, 'view'], $list), 'entry' => $this->urls->toClient((string)$owner->getContent())];
	}

	// Verknüpfung zeigt Liste und Dateien des Ziels (wie Upstream-Download; Leserecht auf das Ziel nötig)
	private function holder(string $uid, Note $note): Note {
		return $note->getLinkedNoteId() !== null ? $this->access->requireNote($uid, (int)$note->getLinkedNoteId(), 'read') : $note;
	}

	/**
	 * @param array<string, mixed>|null $file Eintrag aus IRequest::getUploadedFile
	 * @return array<string, mixed> POST /notes/{noteId}/attachments (201)
	 */
	public function store(string $uid, int $noteId, ?array $file, mixed $wsKey): array {
		$note = $this->access->requireNote($uid, $noteId, 'write');
		$this->checkWorkspace($uid, $note, $wsKey);
		if ($note->getLinkedNoteId() !== null) {
			throw new BadRequestException('A shortcut cannot hold attachments of its own');
		}
		if ($file === null || !isset($file['tmp_name'], $file['name'])) {
			throw new BadRequestException('No file uploaded');
		}
		match ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE)) {
			UPLOAD_ERR_OK => null,
			UPLOAD_ERR_NO_FILE => throw new BadRequestException('No file sent'),
			UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => throw new TooLargeException('File too large'),
			UPLOAD_ERR_PARTIAL => throw new BadRequestException('File upload was interrupted'),
			default => throw new BadRequestException('Unknown upload error'),
		};
		$tmp = (string)$file['tmp_name'];
		if (!is_uploaded_file($tmp)) {
			throw new BadRequestException('Invalid uploaded file');
		}
		$size = (int)filesize($tmp);
		$maxMb = intdiv($this->maxFileBytes(), self::MB);
		if ($size > $maxMb * self::MB) {
			throw new TooLargeException('File too large (max ' . $maxMb . 'MB)');
		}
		$original = (string)$file['name'];
		$name = self::cleanName($original);
		$mime = $this->mimeOf($tmp, $name);
		$quota = $this->quotaFor((int)$note->getWorkspaceId(), $size);
		$created = null;
		try {
			$a = $this->tx->run(function () use ($quota, $noteId, $name, $mime, $size, $uid, $tmp, &$created) {
				$this->enforceQuota($quota);
				$a = $this->newRow($noteId, $name, $mime, $size, $uid);
				$created = $a;
				$handle = fopen($tmp, 'rb');
				if ($handle === false) {
					throw new BadRequestException('Invalid uploaded file');
				}
				try {
					$this->noteFolder($noteId)->newFile((string)$a->getId(), $handle);
				} finally {
					if (is_resource($handle)) {
						fclose($handle);
					}
				}
				return $a;
			});
		} catch (\Throwable $e) {
			if ($created !== null) {
				$this->queueFileDeletion($noteId, (int)$created->getId());
				$this->cleanup->flush();
			}
			throw $e;
		}
		return ['message' => 'File uploaded successfully', 'attachment_id' => (string)$a->getId(), 'filename' => $original];
	}

	/** @return array{0: Attachment, 1: ISimpleFile} GET /notes/{noteId}/attachments/{attachmentId} */
	public function download(string $uid, int $noteId, int $attachmentId, mixed $wsKey): array {
		$note = $this->access->requireNote($uid, $noteId, 'read', null);
		$this->checkWorkspace($uid, $note, $wsKey);
		$a = $this->attachmentOn((int)$this->holder($uid, $note)->getId(), $attachmentId);
		return [$a, $this->fileOf($a)];
	}

	/** @return array<string, mixed> DELETE /notes/{noteId}/attachments/{attachmentId} */
	public function destroy(string $uid, int $noteId, int $attachmentId, mixed $wsKey): array {
		$note = $this->access->requireNote($uid, $noteId, 'write');
		$this->checkWorkspace($uid, $note, $wsKey);
		$a = $this->attachmentOn($noteId, $attachmentId);
		$this->tx->run(function () use ($note, $a, $noteId, $attachmentId) {
			$this->attachments->delete($a);
			$content = (string)$note->getContent();
			$stripped = self::stripReferences($content, $attachmentId);
			if ($stripped !== $content) {
				$note->setContent($stripped);
				$note->setSearchText($this->search->build($note->getHeading(), $stripped, (string)$note->getType()));
				$this->notes->update($note);
			}
			$this->queueFileDeletion($noteId, $attachmentId);
		});
		return ['message' => 'Attachment deleted successfully', 'retained_for_snapshots' => false];
	}

	// Upstream: <img>, <a> und Markdown-Verweise auf den Anhang aus dem Inhalt entfernen; Regex-Fehler wirft (B37)
	private static function stripReferences(string $content, int $attachmentId): string {
		if ($content === '') {
			return $content;
		}
		$ref = 'attachments\/' . $attachmentId . '(?![0-9])';
		$content = Pcre::replace('/<img[^>]*src=[\'"][^\'"]*' . $ref . '[^\'"]*+[\'"][^>]*+>/i', '', $content);
		// Linkinhalt bis zum ersten </a> wie .*? upstream, possessiv ohne Backtracking
		$content = Pcre::replace('/<a[^>]*href=[\'"][^\'"]*' . $ref . '[^\'"]*+[\'"][^>]*+>(?:[^<]++|<(?!\/a>))*+<\/a>/i', '', $content);
		return Pcre::replace('/!?\[[^\]]*+\]\([^)]*' . $ref . '[^)]*+\)/i', '', $content);
	}

	private static function referenced(string $content, int $noteId, int $attachmentId): bool {
		return (bool)preg_match('/notes\/' . $noteId . '\/attachments\/' . $attachmentId . '(?![0-9])/', $content);
	}

	/** @return array<string, mixed> POST /notes/{noteId}/attachments/{attachmentId}/move */
	public function move(string $uid, int $noteId, int $attachmentId, array $in): array {
		$targetId = (int)($in['target_note_id'] ?? 0);
		if ($targetId <= 0) {
			throw new BadRequestException('Note ID, attachment ID and target_note_id are required');
		}
		$source = $this->access->requireNote($uid, $noteId, 'write');
		$this->checkWorkspace($uid, $source, $in['workspace'] ?? null);
		if ($targetId === $noteId) {
			throw new BadRequestException('This attachment is already on that note');
		}
		$a = $this->attachmentOn($noteId, $attachmentId);
		try {
			$target = $this->access->requireNote($uid, $targetId, 'write');
		} catch (NotFoundException) {
			throw new NotFoundException('The note to move it to was not found');
		}
		if ($target->getLinkedNoteId() !== null) {
			throw new BadRequestException('A shortcut cannot hold attachments of its own');
		}
		// Inhalte verlassen einen Workspace nur mit manage auf der Quelle (B17)
		if ($target->getWorkspaceId() !== $source->getWorkspaceId()) {
			$this->access->requireWorkspace($uid, (int)$source->getWorkspaceId(), 'manage');
		}
		// Noch im Quellinhalt verwendet → Kopie, sonst verschieben
		$keep = self::referenced((string)$source->getContent(), $noteId, $attachmentId);
		$needsQuota = $keep || $target->getWorkspaceId() !== $source->getWorkspaceId();
		$srcFile = $this->fileOf($a);
		$quota = $needsQuota ? $this->quotaFor((int)$target->getWorkspaceId(), (int)$a->getSize()) : null;
		$created = null;
		try {
			$new = $this->tx->run(function () use ($a, $target, $srcFile, $keep, $uid, $quota, &$created) {
				$this->enforceQuota($quota);
				$new = $this->newRow((int)$target->getId(), (string)$a->getOriginalName(), (string)$a->getMime(), (int)$a->getSize(), $uid);
				$created = $new;
				$this->noteFolder((int)$target->getId())->newFile((string)$new->getId(), $srcFile->read());
				if (!$keep) {
					$this->attachments->delete($a);
					$this->queueFileDeletion((int)$a->getNoteId(), (int)$a->getId());
				}
				return $new;
			});
		} catch (\Throwable $e) {
			if ($created !== null) {
				$this->queueFileDeletion((int)$target->getId(), (int)$created->getId());
				$this->cleanup->flush();
			}
			throw $e;
		}
		return [
			'message' => 'Attachment moved successfully',
			'attachment_id' => (string)$new->getId(),
			'source_note_id' => $noteId,
			'target_note_id' => $targetId,
			'target_note_heading' => (string)$target->getHeading(),
			'target_workspace' => (string)$this->workspaces->keyFor($uid, (int)$target->getWorkspaceId()),
			'kept_in_source' => $keep,
		];
	}

	/** @return array{notes: list<array<string, mixed>>, count: int} GET /notes/with-attachments */
	public function withAttachments(string $uid, mixed $wsKey): array {
		$ws = $this->workspaces->resolveOptional($uid, $wsKey);
		$wsIds = $ws !== null ? [(int)$ws->getId()] : $this->access->visibleWorkspaceIds($uid);
		$ids = $this->attachments->noteIdsInWorkspaces($wsIds);
		$notes = array_values(array_filter($this->notes->findByIds($ids), static fn (Note $n) => $n->getRole() === 'note'
			&& $n->getTrashedAt() === null && in_array((int)$n->getWorkspaceId(), $wsIds, true)));
		usort($notes, static fn (Note $a, Note $b) => ((int)$b->getUpdatedAt() <=> (int)$a->getUpdatedAt()) ?: ((int)$b->getId() <=> (int)$a->getId()));
		$byNote = $this->attachments->findForNotes(array_map(static fn (Note $n) => (int)$n->getId(), $notes));
		$out = [];
		foreach ($notes as $n) {
			$out[] = [
				'id' => (int)$n->getId(),
				'heading' => (string)$n->getHeading(),
				'entry' => $this->urls->toClient((string)$n->getContent()),
				'attachments' => array_map([self::class, 'view'], $byNote[(int)$n->getId()] ?? []),
				'updated' => NoteSerializer::date($n->getUpdatedAt()),
			];
		}
		return ['notes' => $out, 'count' => count($out)];
	}

	// ---------- für andere Services ----------

	/** Datei eines Anhangs (Export); Rechte prüft der Aufrufer, fehlt → NotFoundException */
	public function fileFor(Attachment $a): ISimpleFile {
		return $this->fileOf($a);
	}

	/** @return array<int, Attachment[]> */
	public function forNotes(array $noteIds): array {
		return $this->attachments->findForNotes($noteIds);
	}

	/** Summe der Anhänge der Notizen (Quota beim Duplizieren) */
	public function bytesOf(array $noteIds): int {
		$sum = 0;
		foreach ($this->attachments->findForNotes($noteIds) as $list) {
			foreach ($list as $a) {
				$sum += (int)$a->getSize();
			}
		}
		return $sum;
	}

	/**
	 * Anhänge physisch kopieren (Duplizieren); läuft in der Transaktion des Aufrufers
	 * @return array<int, int> alte → neue Anhang-ID
	 */
	public function copyAll(int $fromNoteId, int $toNoteId, string $uid): array {
		$map = [];
		foreach ($this->attachments->findForNotes([$fromNoteId])[$fromNoteId] ?? [] as $a) {
			try {
				$src = $this->fileOf($a);
			} catch (NotFoundException) {
				continue;
			}
			$new = $this->newRow($toNoteId, (string)$a->getOriginalName(), (string)$a->getMime(), (int)$a->getSize(), $uid);
			$this->noteFolder($toNoteId)->newFile((string)$new->getId(), $src->read());
			$map[(int)$a->getId()] = (int)$new->getId();
		}
		return $map;
	}

	// Dateien einer Notiz sofort entfernen (Rollback beim Duplizieren)
	public function discardFiles(int $noteId): void {
		try {
			$this->root()->getFolder((string)$noteId)->delete();
		} catch (FilesNotFoundException) {
		}
	}

	/** Endgültiges Löschen: Zeilen jetzt, Ordner nach dem Commit (NoteService::purge) */
	public function purge(array $noteIds): void {
		$this->attachments->deleteForNotes($noteIds);
		foreach ($noteIds as $id) {
			$this->queueFolderDeletion((int)$id);
		}
	}
}
