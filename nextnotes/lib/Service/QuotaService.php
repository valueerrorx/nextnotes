<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service;

use OCA\NextNotes\AppInfo\Application;
use OCA\NextNotes\Db\AttachmentMapper;
use OCA\NextNotes\Db\WorkspaceMapper;
use OCA\NextNotes\Service\Exception\TooLargeException;
use OCP\IAppConfig;

// Speicherquote je Besitzer (SPEC §2.2): Benutzer = alle eigenen Workspaces, Team = alle Workspaces des Teams (M5a)
class QuotaService {
	private const MB = 1048576;

	public function __construct(
		private WorkspaceMapper $workspaceMapper,
		private AttachmentMapper $attachments,
		private IAppConfig $appConfig,
	) {
	}

	/**
	 * Vor der Transaktion: Besitzer lesen, Vorprüfung ohne Sperre
	 * @return array{type: string, id: string, ws: int, bytes: int}|null null = nichts zu prüfen
	 */
	public function quotaFor(int $workspaceId, int $bytes): ?array {
		if ($bytes <= 0) {
			return null;
		}
		$ws = $this->workspaceMapper->find($workspaceId);
		$quota = ['type' => (string)$ws->getOwnerType(), 'id' => (string)$ws->getOwnerId(), 'ws' => (int)$ws->getId(), 'bytes' => $bytes];
		$this->checkSum($quota);
		return $quota;
	}

	/**
	 * Besitzerwechsel (Verschieben aus requireLeave-Wegen): Anhänge der verschobenen Notizen zählen beim Ziel-Besitzer
	 * @return array{type: string, id: string, ws: int, bytes: int}|null
	 */
	public function forLeave(LeaveRequest $r): ?array {
		if (!$r->leaves() || !$r->changesOwner()) {
			return null;
		}
		$src = (int)$r->source->getId();
		$folders = array_map(static fn ($f) => (int)$f->getId(), $r->folders);
		$bytes = match ($r->kind) {
			LeaveRequest::WORKSPACE => $this->attachments->sizeLeaving($src),
			LeaveRequest::FOLDER => $this->attachments->sizeLeaving($src, $folders, null),
			LeaveRequest::NOTES => $this->attachments->sizeLeaving($src, $folders === [] ? null : $folders, $r->noteIds),
			default => 0,
		};
		return $this->quotaFor((int)$r->target->getId(), $bytes);
	}

	/**
	 * Zwei Prüfungen gegen denselben Ziel-Workspace zusammenfassen (z. B. neue Bilder + Umzug beim PATCH)
	 * @param array{type: string, id: string, ws: int, bytes: int}|null $a
	 * @param array{type: string, id: string, ws: int, bytes: int}|null $b
	 * @return array{type: string, id: string, ws: int, bytes: int}|null
	 */
	public function combine(?array $a, ?array $b): ?array {
		if ($a === null || $b === null) {
			return $a ?? $b;
		}
		$a['bytes'] += $b['bytes'];
		$this->checkSum($a);
		return $a;
	}

	/**
	 * Erste Anweisung der Transaktion: alle Workspace-Zeilen des Besitzers sperren (SQLite: Schreibsperre vor jedem Lesen), dann unter Sperre erneut prüfen
	 * @param array{type: string, id: string, ws: int, bytes: int}|null $quota
	 */
	public function enforce(?array $quota): void {
		if ($quota === null) {
			return;
		}
		$this->workspaceMapper->lockOwner($quota['type'], $quota['id']);
		$this->checkSum($quota);
	}

	public function limit(): int {
		return $this->appConfig->getValueInt(Application::APP_ID, 'attachment_quota_mb', AttachmentService::DEFAULT_QUOTA_MB) * self::MB;
	}

	/** @param array{type: string, id: string, ws: int, bytes: int} $quota */
	private function checkSum(array $quota): void {
		$wsIds = [$quota['ws']];
		if ($quota['type'] === 'user' || $quota['type'] === 'team') {
			$wsIds = array_map(static fn ($w) => (int)$w->getId(), $this->workspaceMapper->findByOwner($quota['type'], $quota['id']));
		}
		if ($this->attachments->sizeInWorkspaces($wsIds) + $quota['bytes'] > $this->limit()) {
			throw new TooLargeException('Storage quota exceeded');
		}
	}
}
