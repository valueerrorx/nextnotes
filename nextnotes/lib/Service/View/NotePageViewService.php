<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service\View;

use OCA\NextNotes\Service\AccessService;
use OCA\NextNotes\Service\Exception\NotFoundException;
use OCA\NextNotes\Service\WorkspaceService;
use OCP\IDateTimeZone;

/** Gemeinsame Daten der Notiz-Unterseiten (revisions.php, attachments.php) */
class NotePageViewService {
	public function __construct(
		private AccessService $access,
		private WorkspaceService $workspaces,
		private IDateTimeZone $timeZone,
	) {
	}

	/**
	 * @return array{note: array{id: int, type: string, heading: string}, workspace: string, canWrite: bool, backUrl: string, today: string, yesterday: string}
	 * @throws NotFoundException fremde/unbekannte Notiz oder ?workspace= passt nicht zur Notiz
	 */
	public function note(string $uid, mixed $rawNoteId, mixed $wsKey, string $baseUrl): array {
		if (!is_string($rawNoteId) || !ctype_digit($rawNoteId)) {
			throw new NotFoundException('Note not found');
		}
		$note = $this->access->requireNote($uid, (int)$rawNoteId, 'read');
		$wsId = (int)$note->getWorkspaceId();
		if (is_string($wsKey) && trim($wsKey) !== '' && $this->workspaces->resolveOptional($uid, $wsKey)?->getId() !== $wsId) {
			throw new NotFoundException('Note not found');
		}
		$key = (string)$this->workspaces->keyFor($uid, $wsId);
		$now = new \DateTimeImmutable('now', $this->timeZone->getTimeZone());
		return [
			'note' => ['id' => (int)$note->getId(), 'type' => (string)($note->getType() ?: 'note'), 'heading' => (string)($note->getHeading() ?? '')],
			'workspace' => $key,
			'canWrite' => $this->access->noteLevel($uid, $note) >= AccessService::WRITE,
			'backUrl' => $baseUrl . 'index.php?' . http_build_query(['note' => (int)$note->getId(), 'workspace' => $key]),
			'today' => $now->format('Y-m-d'),
			'yesterday' => $now->modify('-1 day')->format('Y-m-d'),
		];
	}
}
