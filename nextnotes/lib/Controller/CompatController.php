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
use OCA\NextNotes\Service\Exception\NotImplementedException;
use OCA\NextNotes\Service\Exception\NotFoundException;
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
}
