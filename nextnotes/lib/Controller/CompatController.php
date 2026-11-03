<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Controller;

use OCA\NextNotes\Db\Note;
use OCA\NextNotes\Db\NoteTagMapper;
use OCA\NextNotes\Http\AttachmentResponse;
use OCA\NextNotes\Service\AccessService;
use OCA\NextNotes\Service\AttachmentService;
use OCA\NextNotes\Service\Exception\BadRequestException;
use OCA\NextNotes\Service\Exception\LockedException;
use OCA\NextNotes\Service\Exception\NotImplementedException;
use OCA\NextNotes\Service\Exception\NotFoundException;
use OCA\NextNotes\Service\Exception\TooLargeException;
use OCA\NextNotes\Service\ExcalidrawService;
use OCA\NextNotes\Service\FolderService;
use OCA\NextNotes\Service\SettingsService;
use OCA\NextNotes\Service\View\PageTranslator;
use OCA\NextNotes\Service\WorkspaceService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\FileDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IDateTimeFormatter;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;

// Upstream-Einzeldateien unter gleichem Namen (SPEC §4.2); Rechte nur über AccessService, fremd/unbekannt → 404
class CompatController extends ApiController {
	public function __construct(
		IRequest $request,
		IUserSession $userSession,
		WorkspaceService $workspaces,
		private AccessService $access,
		private AttachmentService $attachments,
		private FolderService $folders,
		private NoteTagMapper $tags,
		private SettingsService $settings,
		private PageTranslator $tr,
		private IDateTimeFormatter $dates,
		private IUserManager $users,
		private ExcalidrawService $excalidraw,
	) {
		parent::__construct($request, $userSession, $workspaces);
	}

	// Notiz per ID-Parameter mit Leserecht; ?workspace= muss (falls gesetzt) der Workspace der Notiz sein
	private function note(string $param): Note {
		$raw = $this->param($param);
		if (!is_string($raw) || !ctype_digit($raw)) {
			throw new NotFoundException('Note not found');
		}
		$note = $this->access->requireNote($this->uid(), (int)$raw, 'read');
		$ws = $this->workspaces->resolveOptional($this->uid(), $this->param('workspace'));
		if ($ws !== null && $ws->getId() !== $note->getWorkspaceId()) {
			throw new NotFoundException('Note not found');
		}
		return $note;
	}

	// Upstream api_note_info.php: Werte fertig formatiert für #noteInfoModal (note-info-modal.js)
	#[NoAdminRequired]
	public function noteInfo(): JSONResponse {
		$note = $this->note('note_id');
		$uid = $this->uid();
		$this->tr->setLanguage($this->tr->languageFor($this->settings->read($uid, 'language')));
		$id = (int)$note->getId();
		$na = $this->tr->t('common.not_available', [], 'Not available');
		$date = fn (?int $ts) => $ts === null ? $na : $this->dates->formatDateTime($ts, 'short', 'short');
		$user = fn (?string $u) => $u === null || $u === '' ? $na : ($this->users->getDisplayName($u) ?? $na);
		$folder = $note->getFolderId() !== null ? FolderService::pathOf((int)$note->getFolderId(), $this->folders->byId((int)$note->getWorkspaceId())) : null;
		$tags = $this->tags->tagsFor([$id])[$id] ?? [];
		$content = (string)($note->getContent() ?? '');
		$count = 0;
		foreach ($this->attachments->forNotes([$id])[$id] ?? [] as $a) {
			if (!str_contains($content, 'attachments/' . (int)$a->getId())) {
				$count++;
			}
		}
		$heading = (string)($note->getHeading() ?? '');
		$favorite = (int)$note->getFavorite() === 1;
		return $this->ok(['info' => [
			'title' => $heading !== '' ? $heading : $this->tr->t('index.note.new_note', [], 'New note'),
			'workspace' => (string)$this->workspaces->keyFor($uid, (int)$note->getWorkspaceId()),
			'folder' => $folder ?? $this->tr->t('modals.folder.no_folder', [], 'No folder'),
			'created' => $date($note->getCreatedAt()),
			'created_by' => $user($note->getCreatedBy()),
			'updated' => $date($note->getUpdatedAt()),
			'updated_by' => $user($note->getUpdatedBy() ?? $note->getCreatedBy()),
			'tags' => $tags === [] ? $this->tr->t('info.empty.no_tags', [], 'No tags') : implode(', ', $tags),
			'favorite' => $favorite,
			'favorite_text' => $favorite ? $this->tr->t('common.yes', [], 'Yes') : $this->tr->t('common.no', [], 'No'),
			'attachments' => $count === 1
				? $this->tr->t('info.attachments.count_singular', ['count' => $count], '1 file')
				: $this->tr->t('info.attachments.count_plural', ['count' => $count], '{{count}} files'),
			'note_id' => $id,
		]]);
	}

	// Upstream api_download_note.php: gespeicherte Form als Datei (Export mit Formatierung: M4, Plan-O2)
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function downloadNote(): DataDownloadResponse {
		$note = $this->note('id');
		$type = (string)($note->getType() ?: 'note');
		[$ext, $mime] = match ($type) {
			'markdown' => ['md', 'text/markdown'],
			'tasklist', 'excalidraw' => ['json', 'application/json'],
			default => ['html', 'text/html'],
		};
		$base = preg_replace('/[^a-zA-Z0-9_\-]/', '_', (string)($note->getHeading() ?? '')) ?: 'note_' . (int)$note->getId();
		$response = new DataDownloadResponse((string)($note->getContent() ?? ''), $base . '.' . $ext, $mime . '; charset=utf-8');
		$response->addHeader('X-Content-Type-Options', 'nosniff');
		$response->addHeader('Cache-Control', 'no-cache, must-revalidate');
		return $response;
	}

	// Upstream api_export_note.php: Rechteprüfung, Export folgt mit ExportService (M4, Plan-O2)
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function exportNote(): JSONResponse {
		$this->note('id');
		throw new NotImplementedException('not_implemented');
	}

	// Upstream api_export_folder.php: Rechteprüfung, Export folgt mit ExportService (M4, Plan-O2)
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function exportFolder(): JSONResponse {
		$raw = $this->param('folder_id');
		if (!is_string($raw) || !ctype_digit($raw)) {
			throw new NotFoundException('Folder not found');
		}
		$folder = $this->access->requireFolder($this->uid(), (int)$raw, 'read');
		$ws = $this->workspaces->resolveOptional($this->uid(), $this->param('workspace'));
		if ($ws !== null && $ws->getId() !== $folder->getWorkspaceId()) {
			throw new NotFoundException('Folder not found');
		}
		throw new NotImplementedException('not_implemented');
	}

	// Alt-URL api_attachments.php?action=download (SPEC §10): gleiche Auslieferung wie GET /notes/{n}/attachments/{a}
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function attachments(): FileDisplayResponse {
		$note = $this->param('note_id');
		$attachment = $this->param('attachment_id');
		if ($this->param('action') !== 'download' || !is_string($note) || !ctype_digit($note) || !is_string($attachment) || !ctype_digit($attachment)) {
			throw new NotFoundException('Attachment not found');
		}
		[$a, $file] = $this->attachments->download($this->uid(), (int)$note, (int)$attachment, $this->param('workspace'));
		return AttachmentResponse::build($a, $file, false);
	}
	// Upstream api_save_excalidraw.php (multipart): Schreibrecht (Lesefreigabe → 403), fremde Sperre → 423, Vorschau als Anhang
	#[NoAdminRequired]
	public function saveExcalidraw(): JSONResponse {
		$uid = $this->uid();
		try {
			if ($this->param('action') === 'save_embedded_diagram') {
				return $this->ok($this->excalidraw->saveEmbedded($uid, $this->param('note_id'), $this->param('diagram_id'), $this->param('workspace'),
					$this->param('diagram_data'), $this->param('preview_svg'), $this->param('cursor_position')));
			}
			return $this->ok($this->excalidraw->saveFull($uid, $this->param('note_id'), $this->param('workspace'), $this->param('diagram_data'), $this->param('preview_svg')));
		} catch (LockedException $e) {
			// Editor zeigt message direkt: in Sprache des Kontos (Upstream englisch fest, B38)
			$holder = $e->getExtra()['lock']['holder_username'] ?? null;
			if (!is_string($holder)) {
				throw $e;
			}
			$this->tr->setLanguage($this->tr->languageFor($this->settings->read($uid, 'language')));
			throw new LockedException($this->tr->t('note_lock.lost_to_user', ['user' => $holder],
				'You no longer hold the edit lock for this note. {{user}} is now editing it.'), $e->getExtra());
		} catch (TooLargeException $e) {
			// Editor zeigt message direkt: Grenze in Sprache des Kontos (M4a)
			$max = $e->getExtra()['max_mb'] ?? null;
			if (!is_int($max)) {
				throw $e;
			}
			$this->tr->setLanguage($this->tr->languageFor($this->settings->read($uid, 'language')));
			throw new TooLargeException($this->tr->t('attachments.errors.file_too_large', ['maxSize' => $max . ' MB'],
				'The file is too large (max: {{maxSize}}).'), $e->getExtra());
		}
	}

	/**
	 * Upstream workspaces.php (POST-Aktionen der Workspace-Liste); Schlüssel wie SPEC §4.4, nur Besitzer (manage).
	 * Abweichungen: Fehler mit HTTP-Status statt 200; delete verschiebt Inhalte wie DELETE /workspaces (B18); Teilen bis M5 → 501.
	 */
	#[NoAdminRequired]
	public function workspaces(): JSONResponse {
		$uid = $this->uid();
		$action = $this->param('action');
		$name = is_string($this->param('name')) ? trim($this->param('name')) : '';
		$text = fn (string $key, string $default, array $vars = []) => $this->tr->t($key, $vars, $default);
		$this->tr->setLanguage($this->tr->languageFor($this->settings->read($uid, 'language')));
		if ($action === 'reorder') {
			$raw = $this->param('names');
			$names = array_values(array_filter(array_map(static fn ($n) => is_scalar($n) ? trim((string)$n) : '', is_array($raw) ? $raw : [$raw]), static fn (string $n) => $n !== ''));
			if ($names === []) {
				throw new BadRequestException('Workspace name required');
			}
			$this->workspaces->reorder($uid, $names);
			return $this->ok(['message' => $text('workspaces.order.saved', 'Workspace order updated'), 'names' => $names]);
		}
		if (!in_array($action, ['delete', 'set_tags', 'set_color', 'rename', 'move_notes', 'share_workspace', 'unshare_workspace'], true)) {
			throw new BadRequestException('Unknown action');
		}
		if ($name === '') {
			throw new BadRequestException('Workspace name required');
		}
		switch ($action) {
			case 'delete':
				$this->workspaces->delete($uid, $name);
				return $this->ok(['message' => 'Workspace deleted']);
			case 'set_tags':
				$r = $this->workspaces->update($uid, $name, ['tags' => $this->param('tags') ?? '']);
				return $this->ok(['message' => $text('workspaces.tags.saved', 'Tags updated'), 'name' => $name, 'tags' => $r['tags']]);
			case 'set_color':
				$r = $this->workspaces->update($uid, $name, ['color' => $this->param('color') ?? '']);
				return $this->ok(['message' => $text('workspaces.color.saved', 'Color updated'), 'name' => $name, 'color' => $r['color']]);
			case 'rename':
				$new = is_string($this->param('new_name')) ? trim($this->param('new_name')) : '';
				if ($new === '' || $new === $name) {
					$this->workspaces->requireByKey($uid, $name, 'manage');
					return $this->ok(['message' => '', 'name' => $name]);
				}
				$r = $this->workspaces->update($uid, $name, ['new_name' => $new]);
				return $this->ok(['message' => $text('workspaces.messages.renamed', 'Workspace renamed across notes and labels'), 'name' => $r['new_name']]);
			case 'move_notes':
				$target = is_string($this->param('target')) ? trim($this->param('target')) : '';
				if ($target === '') {
					throw new BadRequestException('Workspace name and target required');
				}
				$moved = $this->workspaces->moveNotes($uid, $name, $target);
				return $this->ok(['moved' => $moved, 'target' => $target, 'message' => $text('workspaces.messages.notes_moved_to', 'Notes moved to {{target}}', ['target' => $target])]);
			default:
				// Teilen mit NC-Benutzern kommt mit der Freigabe-Oberfläche (M5); Rechte vorher prüfen
				$this->workspaces->requireByKey($uid, $name, 'manage');
				throw new NotImplementedException('not_implemented');
		}
	}
}
