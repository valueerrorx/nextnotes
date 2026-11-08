<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service;

use OCA\NextNotes\Db\Attachment;
use OCA\NextNotes\Db\Folder;
use OCA\NextNotes\Db\FolderMapper;
use OCA\NextNotes\Db\Note;
use OCA\NextNotes\Db\NoteMapper;
use OCA\NextNotes\Db\NoteTagMapper;
use OCA\NextNotes\Db\Workspace;
use OCA\NextNotes\Service\Exception\NotFoundException;
use OCA\NextNotes\Service\Exception\TooManyRequestsException;
use OCP\IDateTimeZone;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * ZIP-Exporte (Upstream api_export_folder.php, api_export_entries.php, api_export_structured.php, api_export_attachments.php;
 * Plan M4 §1.3, M4d). Aufbau wie Upstream, damit Poznote sie importiert. Umfang: Ordner und Workspace (?workspace=) mit
 * Leserecht (Q2 vorläufig), „alle“ nur eigene Workspaces (ADR 0008); Papierkorb nie (B45). Ins ZIP kommen nur Anhänge der
 * exportierten Notizen, Verweise auf Fremdes bleiben Text (B47); [[…]] bleiben wie im Upstream unaufgelöst.
 * Verknüpfte Notizen: Ziel lesbar → dessen Inhalt, sonst leere Hülle. Höchstens ein ZIP-Export je Benutzer gleichzeitig.
 */
class ZipExportService {
	// Inhalte stückweise laden: höchstens so viele Byte bzw. Notizen je Abfrage
	private const CHUNK_BYTES = 33554432;
	private const CHUNK_IDS = 500;

	public function __construct(
		private AccessService $access,
		private WorkspaceService $workspaces,
		private NoteMapper $notes,
		private NoteTagMapper $tags,
		private FolderMapper $folderMapper,
		private AttachmentService $attachments,
		private HtmlSanitizer $sanitizer,
		private ExportService $export,
		private ExportArchive $archive,
		private ILockingProvider $locks,
		private IDateTimeZone $timeZone,
	) {
	}

	/**
	 * GET api_export_folder.php: Ordner mit Unterordnern
	 * @return array<string, mixed>
	 */
	public function folder(string $uid, mixed $rawId, mixed $wsKey): array {
		if (!is_string($rawId) || !ctype_digit($rawId)) {
			throw new NotFoundException('Folder not found');
		}
		$folder = $this->access->requireFolder($uid, (int)$rawId, 'read');
		$ws = $this->workspaces->resolveOptional($uid, $wsKey);
		if ($ws !== null && $ws->getId() !== $folder->getWorkspaceId()) {
			throw new NotFoundException('Folder not found');
		}
		$rootId = (int)$folder->getId();
		$byId = $this->foldersById([(int)$folder->getWorkspaceId()]);
		$subtree = FolderService::subtree($rootId, $byId);
		$items = $this->collect($uid, [(int)$folder->getWorkspaceId()], ['folder_ids' => $subtree], true);
		usort($items, static fn (array $a, array $b): int => [(int)$a['note']->getFolderId(), (string)$a['note']->getHeading()] <=> [(int)$b['note']->getFolderId(), (string)$b['note']->getHeading()]);
		$name = (string)$folder->getName();
		$readme = "# Poznote Folder Export: " . $name . "\n\n"
			. "This archive contains the folder '" . $name . "' and all its contents from Poznote.\n\n"
			. 'Export date: ' . $this->now() . "\n\n## Structure\n\n"
			. "- Subfolders are preserved in the archive\n"
			. "- Notes are saved with their original type (.html or .md)\n"
			. "- Markdown files include YAML front matter with metadata (title, tags, etc.)\n\n";
		return $this->locked($uid, fn (): array => $this->archive->build(ExportFormatter::zipName($name) . '_export.zip',
			function (ExportArchive $zip) use ($items, $byId, $rootId, $subtree, $readme): void {
				$zip->addString('README.md', $readme);
				$used = [];
				$this->writeNotes($zip, $items, $byId, function (array $item) use ($byId, $rootId, &$used): array {
					$note = $item['note'];
					$dir = self::zipDir($note->getFolderId(), $byId, $rootId);
					$used[$dir] = true;
					$ext = $item['type'] === 'markdown' ? 'md' : 'html';
					return [$dir . ExportFormatter::zipName((string)($note->getHeading() ?: 'New note')) . '_' . (int)$note->getId() . '.' . $ext, $dir, $ext, false];
				});
				$this->gitkeep($zip, array_diff($subtree, [$rootId]), $byId, $rootId, $used);
			}));
	}

	/**
	 * GET api_export_entries.php: alle Notizen als <id>.html|md mit index.html (Upstream-Rücksicherung)
	 * @return array<string, mixed>
	 */
	public function entries(string $uid, mixed $wsKey): array {
		$wsIds = $this->scope($uid, $wsKey);
		$items = $this->collect($uid, $wsIds, [], false);
		$byId = $this->foldersById($wsIds);
		usort($items, static fn (array $a, array $b): int => ((int)$b['note']->getUpdatedAt() <=> (int)$a['note']->getUpdatedAt()) ?: ((int)$b['note']->getId() <=> (int)$a['note']->getId()));
		$index = '<html><head><meta charset="UTF-8"><title>Note Index</title></head><body><h1>Poznote Notes Export</h1><ul>';
		foreach ($items as $item) {
			$note = $item['note'];
			$folderId = $note->getFolderId();
			$folder = $folderId !== null ? (FolderService::pathOf((int)$folderId, $byId) ?? 'Default') : 'Default';
			$tags = $item['tags'] !== [] ? ' - ' . ExportFormatter::h(implode(',', $item['tags'])) : '';
			$index .= '<li><a href="./' . (int)$note->getId() . '.' . ($item['type'] === 'markdown' ? 'md' : 'html') . '">'
				. ExportFormatter::h((string)($note->getHeading() ?: 'New note')) . '</a> (' . ExportFormatter::h($folder) . $tags . ')</li>';
		}
		$index .= '</ul><p>Export date: ' . $this->now() . '</p></body></html>';
		return $this->locked($uid, fn (): array => $this->archive->build('poznote_notes_export.zip',
			function (ExportArchive $zip) use ($items, $index, $byId): void {
				$zip->addString('index.html', $index);
				$this->writeNotes($zip, $items, $byId, static function (array $item): array {
					$ext = $item['type'] === 'markdown' ? 'md' : 'html';
					return [(int)$item['note']->getId() . '.' . $ext, '', $ext, false];
				}, false);
			}));
	}

	/**
	 * GET api_export_structured.php: Ordnerstruktur, Notizen ohne Ordner unter Uncategorized/, Anhänge unter attachments/
	 * @return array<string, mixed>
	 */
	public function structured(string $uid, mixed $wsKey): array {
		return $this->structuredOf($uid, $this->workspaces->resolveOptional($uid, $wsKey), $this->scope($uid, $wsKey));
	}

	/**
	 * Strukturierter Export eines eigenen Workspace ohne Schlüssel (occ export-user, IMigrator; Recht prüft der Aufrufer)
	 * @return array<string, mixed>
	 */
	public function structuredWorkspace(string $uid, Workspace $ws): array {
		return $this->structuredOf($uid, $ws, [(int)$ws->getId()]);
	}

	/**
	 * @param int[] $wsIds
	 * @return array<string, mixed>
	 */
	private function structuredOf(string $uid, ?Workspace $ws, array $wsIds): array {
		$items = $this->collect($uid, $wsIds, [], true);
		$byId = $this->foldersById($wsIds);
		usort($items, static fn (array $a, array $b): int => [(int)$a['note']->getFolderId(), (string)$a['note']->getHeading()] <=> [(int)$b['note']->getFolderId(), (string)$b['note']->getHeading()]);
		$wsName = $ws !== null ? (string)$ws->getName() : 'all';
		$readme = "# Poznote Structured Export\n\n"
			. "This archive contains your Poznote notes organized in folders matching your Poznote folder structure.\n\n"
			. '**Workspace:** ' . $wsName . "\n\n**Export date:** " . $this->now() . "\n\n## Structure\n\n"
			. "- Each folder from Poznote is represented as a directory in this archive\n"
			. "- Notes are saved with their original type (.html or .md)\n"
			. "- Markdown files include YAML front matter with metadata (title, tags, etc.)\n"
			. "- Notes without a folder are placed in the 'Uncategorized' directory\n\n";
		$stamp = (new \DateTime('now', $this->timeZone->getTimeZone()))->format('Y-m-d_His');
		return $this->locked($uid, fn (): array => $this->archive->build('poznote_structured_' . ExportFormatter::asciiName($wsName) . '_' . $stamp . '.zip',
			function (ExportArchive $zip) use ($items, $byId, $readme): void {
				$zip->addString('README.md', $readme);
				$used = [];
				$this->writeNotes($zip, $items, $byId, function (array $item) use ($byId, &$used): array {
					$note = $item['note'];
					$dir = self::zipDir($note->getFolderId(), $byId, null);
					$dir = $dir !== '' ? $dir : 'Uncategorized/';
					$used[$dir] = true;
					$ext = in_array($item['type'], ['markdown', 'tasklist'], true) ? 'md' : 'html';
					return [$dir . ExportFormatter::zipName((string)($note->getHeading() ?: 'New note')) . '.' . $ext, $dir, $ext, true];
				});
				$this->gitkeep($zip, array_keys($byId), $byId, null, $used);
			}));
	}

	/**
	 * GET api_export_attachments.php: Anhänge eigener Workspaces unter files/ mit poznote_attachments_metadata.json
	 * @return array<string, mixed>
	 */
	public function attachments(string $uid): array {
		$notes = $this->notes->findList($this->access->ownWorkspaceIds($uid));
		$byNote = $this->attachments->forNotes(array_map(static fn (Note $n): int => (int)$n->getId(), $notes));
		$total = 0;
		foreach ($byNote as $list) {
			foreach ($list as $a) {
				$total += (int)$a->getSize();
			}
		}
		ExportArchive::checkSize($total);
		return $this->locked($uid, fn (): array => $this->archive->build('poznote_attachments_export.zip',
			function (ExportArchive $zip) use ($notes, $byNote): void {
				$meta = [];
				$count = 0;
				foreach ($notes as $note) {
					foreach (ExportService::attachmentEntries($byNote[(int)$note->getId()] ?? []) as $a) {
						if (!$this->export->addAttachment($zip, 'files/' . $a['file'], $a['entity'])) {
							continue;
						}
						$count++;
						$meta[] = ['note_id' => (int)$note->getId(), 'note_heading' => (string)$note->getHeading(),
							'attachment_data' => array_merge(AttachmentService::view($a['entity']), ['filename' => $a['file']])];
					}
				}
				if ($meta !== []) {
					$zip->addString('poznote_attachments_metadata.json', (string)json_encode($meta, JSON_PRETTY_PRINT));
				}
				$zip->addString('index.html', '<html><head><title>Attachments Index</title></head><body><h1>Poznote Attachments Export</h1>'
					. '<p>Total attachments: ' . $count . '</p><p>Total notes with attachments: ' . count($meta) . '</p>'
					. '<p>Export date: ' . $this->now() . '</p><p><strong>Note:</strong> This export includes metadata file for proper restoration.</p></body></html>');
			}));
	}

	/** @return int[] ?workspace= mit Leserecht, sonst eigene Workspaces */
	private function scope(string $uid, mixed $wsKey): array {
		$ws = $this->workspaces->resolveOptional($uid, $wsKey);
		return $ws !== null ? [(int)$ws->getId()] : $this->access->ownWorkspaceIds($uid);
	}

	/**
	 * Notizen ohne Inhalt, verknüpfte über einen filterReadable-Aufruf aufgelöst, Gesamtgrenze vorab aus der DB
	 * @param int[] $wsIds
	 * @param array<string, mixed> $filter
	 * @return list<array{note: Note, source: ?int, type: string, tags: list<string>, own: array<int, array{id: int, name: string, mime: string, file: string, size: int, entity: Attachment}>}>
	 */
	private function collect(string $uid, array $wsIds, array $filter, bool $withAttachments): array {
		$notes = $this->notes->findList($wsIds, $filter);
		$targetIds = [];
		foreach ($notes as $n) {
			if ($n->getType() === 'linked' && $n->getLinkedNoteId() !== null) {
				$targetIds[] = (int)$n->getLinkedNoteId();
			}
		}
		$targets = [];
		if ($targetIds !== []) {
			foreach ($this->access->filterReadable($uid, $this->notes->findListByIds($targetIds)) as $t) {
				if ($t->getTrashedAt() === null && $t->getType() !== 'linked') {
					$targets[(int)$t->getId()] = $t;
				}
			}
		}
		$items = [];
		foreach ($notes as $n) {
			if ($n->getType() === 'linked') {
				$t = $targets[(int)$n->getLinkedNoteId()] ?? null;
				$items[] = ['note' => $n, 'source' => $t !== null ? (int)$t->getId() : null, 'type' => $t !== null ? self::typeOf($t) : 'note'];
				continue;
			}
			$items[] = ['note' => $n, 'source' => (int)$n->getId(), 'type' => self::typeOf($n)];
		}
		$sources = array_values(array_unique(array_filter(array_column($items, 'source'), static fn ($id) => $id !== null)));
		$sizes = $this->notes->contentBytes($sources);
		$byNote = $withAttachments ? $this->attachments->forNotes($sources) : [];
		$tags = $this->tags->tagsFor(array_map(static fn (Note $n): int => (int)$n->getId(), $notes));
		$total = 0;
		$seen = [];
		foreach ($items as $i => $item) {
			$items[$i]['tags'] = array_values(array_map('strval', $tags[(int)$item['note']->getId()] ?? []));
			$items[$i]['own'] = $item['source'] !== null ? ExportService::attachmentEntries($byNote[$item['source']] ?? []) : [];
			$total += $item['source'] !== null ? ($sizes[$item['source']] ?? 0) : 0;
			foreach ($items[$i]['own'] as $aid => $a) {
				if (!isset($seen[$aid])) {
					$seen[$aid] = true;
					$total += $a['size'];
				}
			}
		}
		ExportArchive::checkSize($total);
		foreach ($items as $i => $item) {
			$items[$i]['bytes'] = $item['source'] !== null ? ($sizes[$item['source']] ?? 0) : 0;
		}
		return $items;
	}

	/**
	 * Notizen schreiben (Inhalt stückweise geladen) und Anhänge einmal je ID unter attachments/
	 * @param list<array<string, mixed>> $items
	 * @param array<int, Folder> $byId Ordner der Workspaces (Front Matter: voller Pfad)
	 * @param callable(array<string, mixed>): array{0: string, 1: string, 2: string, 3: bool} $place Pfad, Ordner, Endung, download-Attribute
	 */
	private function writeNotes(ExportArchive $zip, array $items, array $byId, callable $place, bool $withAttachments = true): void {
		$attachments = [];
		// B57: Notizen verweisen fest auf attachments/<id>.<ext>; eine Notiz im Ordner „attachments“ weicht aus
		foreach ($withAttachments ? $items : [] as $item) {
			foreach ($item['own'] as $a) {
				$zip->reserve('attachments/' . $a['file']);
			}
		}
		foreach ($this->chunks($items) as $chunk) {
			$content = $this->notes->contentByIds(array_values(array_filter(array_map(static fn (array $i) => $i['source'], $chunk), static fn ($id) => $id !== null)));
			foreach ($chunk as $item) {
				[$path, $dir, $ext, $downloadAttrs] = $place($item);
				$note = $item['note'];
				$text = $item['source'] !== null ? ($content[$item['source']] ?? '') : '';
				$prefix = str_repeat('../', substr_count($dir, '/')) . 'attachments/';
				if ($item['type'] === 'tasklist' && $ext === 'md') {
					$text = ExportFormatter::tasklistToMarkdown($text);
				}
				if ($ext === 'md') {
					$folderPath = $note->getFolderId() !== null ? FolderService::pathOf((int)$note->getFolderId(), $byId) : null;
					$text = $this->export->frontMatter($note, $item['tags'], $folderPath, $item['type'] === 'tasklist') . $text;
				} elseif ($item['type'] !== 'tasklist') {
					$text = $this->clean($text);
				}
				if ($withAttachments && $item['source'] !== null) {
					$text = ExportService::zipPaths($text, $item['source'], $item['own'], $prefix);
					if ($downloadAttrs && $ext === 'html') {
						$text = ExportFormatter::downloadAttributes($text, array_column($item['own'], 'name', 'file'));
					}
					$attachments += $item['own'];
				}
				$zip->addString($path, $text, (string)(int)$note->getId());
			}
		}
		foreach ($attachments as $a) {
			$this->export->addAttachment($zip, 'attachments/' . $a['file'], $a['entity']);
		}
	}

	/**
	 * Stücke nach Byte und Anzahl (Speicher: ein Stück, nicht der ganze Export)
	 * @param list<array<string, mixed>> $items
	 * @return list<list<array<string, mixed>>>
	 */
	private function chunks(array $items): array {
		$out = [];
		$cur = [];
		$bytes = 0;
		foreach ($items as $item) {
			if ($cur !== [] && ($bytes + $item['bytes'] > self::CHUNK_BYTES || count($cur) >= self::CHUNK_IDS)) {
				$out[] = $cur;
				$cur = [];
				$bytes = 0;
			}
			$cur[] = $item;
			$bytes += $item['bytes'];
		}
		if ($cur !== []) {
			$out[] = $cur;
		}
		return $out;
	}

	// Bedienelemente der Codeblöcke entfernen (Upstream removeCopyButtonsFromHtml); nur wenn vorhanden, Ergebnis erneut sanitisiert
	private function clean(string $html): string {
		if (!str_contains($html, 'code-block-')) {
			return $html;
		}
		return $this->sanitizer->sanitizeHtml(ExportFormatter::cleanContent($html, null));
	}

	/**
	 * @param int[] $wsIds
	 * @return array<int, Folder>
	 */
	private function foldersById(array $wsIds): array {
		$out = [];
		foreach ($this->folderMapper->findInWorkspaces($wsIds) as $f) {
			$out[(int)$f->getId()] = $f;
		}
		return $out;
	}

	/**
	 * Upstream getFolderZipPath: Pfad unterhalb von $rootId (oder ganz), Teile nach zipName, mit Schluss-/
	 * @param array<int, Folder> $byId
	 */
	private static function zipDir(?int $folderId, array $byId, ?int $rootId): string {
		$parts = [];
		for ($cur = $folderId, $depth = 0; $cur !== null && $cur !== $rootId && isset($byId[$cur]) && $depth < 50; $depth++) {
			array_unshift($parts, ExportFormatter::zipName((string)$byId[$cur]->getName()));
			$cur = $byId[$cur]->getParentId();
		}
		return $parts !== [] ? implode('/', $parts) . '/' : '';
	}

	/**
	 * .gitkeep in leeren Ordnern (Upstream)
	 * @param int[] $folderIds
	 * @param array<int, Folder> $byId
	 * @param array<string, true> $used
	 */
	private function gitkeep(ExportArchive $zip, array $folderIds, array $byId, ?int $rootId, array $used): void {
		foreach ($folderIds as $id) {
			$dir = self::zipDir((int)$id, $byId, $rootId);
			if ($dir === '' || isset($used[$dir])) {
				continue;
			}
			foreach (array_keys($used) as $path) {
				if (str_starts_with($path, $dir)) {
					continue 2;
				}
			}
			$zip->addString($dir . '.gitkeep', '', (string)$id);
		}
	}

	private static function typeOf(Note $note): string {
		$type = (string)($note->getType() ?: 'note');
		return in_array($type, ['note', 'markdown', 'tasklist', 'excalidraw'], true) ? $type : 'note';
	}

	private function now(): string {
		return (new \DateTime('now', $this->timeZone->getTimeZone()))->format('Y-m-d H:i:s');
	}

	// Feste Länge: oc_file_locks.key ist string(64), UIDs bis 64 Zeichen (DBLockingProvider)
	public static function lockKey(string $uid): string {
		return 'nextnotes/export/' . md5($uid);
	}

	/**
	 * Höchstens ein ZIP-Export je Benutzer (Plan M4d, Lead-Auflage r2): NC-Sperre (Memcache oder DB, TTL ≥ max_execution_time)
	 * @param callable(): array<string, mixed> $fn
	 * @return array<string, mixed>
	 */
	public function locked(string $uid, callable $fn): array {
		$key = self::lockKey($uid);
		try {
			$this->locks->acquireLock($key, ILockingProvider::LOCK_EXCLUSIVE);
		} catch (LockedException) {
			throw new TooManyRequestsException('Another export is still running');
		}
		try {
			return $fn();
		} finally {
			$this->locks->releaseLock($key, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}
}
