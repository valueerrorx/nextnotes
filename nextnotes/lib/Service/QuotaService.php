<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service;

use OCA\NextNotes\AppInfo\Application;
use OCA\NextNotes\Db\AssignmentMapper;
use OCA\NextNotes\Db\AttachmentMapper;
use OCA\NextNotes\Db\Note;
use OCA\NextNotes\Db\TeamMapper;
use OCA\NextNotes\Db\WorkspaceMapper;
use OCA\NextNotes\Service\Exception\NotFoundException;
use OCA\NextNotes\Service\Exception\TooLargeException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;

// Speicherquote je Besitzer (SPEC §2.2): Benutzer = eigene Workspaces ohne kind=assignments; Team = Team-Workspaces (M5a) + Vorlagen/Kopien seiner Aufgaben (M6d)
class QuotaService {
	private const MB = 1048576;

	public function __construct(
		private WorkspaceMapper $workspaceMapper,
		private AttachmentMapper $attachments,
		private IAppConfig $appConfig,
		private TeamMapper $teams,
		private AssignmentMapper $assignments,
		private ITimeFactory $time,
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
		if ($quota['type'] === 'team') {
			$this->ensureTeamRow($quota['id']);
		}
		$this->checkSum($quota);
		return $quota;
	}

	/**
	 * Quote der Notiz: mit assignment_id (Vorlage, Kopie, abgelöste Kopie) beim Team der Aufgabe, sonst beim Workspace-Besitzer (Plan M6 §2.3a)
	 * @return array{type: string, id: string, ws: int, bytes: int}|null
	 */
	public function quotaForNote(Note $note, int $bytes): ?array {
		if ($note->getAssignmentId() === null) {
			return $note->getWorkspaceId() === null ? null : $this->quotaFor((int)$note->getWorkspaceId(), $bytes);
		}
		return $this->quotaForAssignment((int)$note->getAssignmentId(), $bytes);
	}

	/** @return array{type: string, id: string, ws: int, bytes: int}|null Vorlage/Austeilen einer bestehenden Aufgabe */
	public function quotaForAssignment(int $assignmentId, int $bytes): ?array {
		if ($bytes <= 0) {
			return null;
		}
		try {
			$teamId = (string)$this->assignments->find($assignmentId)->getTeamId();
		} catch (DoesNotExistException) {
			throw new NotFoundException('Assignment not found');
		}
		return $this->quotaForTeam($teamId, $bytes);
	}

	/** @return array{type: string, id: string, ws: int, bytes: int}|null Team-Quote; Team-Zeile legt der Aufrufer an (Aufgabe anlegen) */
	public function quotaForTeam(string $teamId, int $bytes): ?array {
		if ($bytes <= 0) {
			return null;
		}
		$quota = ['type' => 'team', 'id' => $teamId, 'ws' => 0, 'bytes' => $bytes];
		$this->checkSum($quota);
		return $quota;
	}

	// Team-Workspace ohne Zeile (Altbestand): Zeile vor der Transaktion anlegen; nie für ein Team ohne Workspace (Review M6-Plan r2 Hinweis 7)
	private function ensureTeamRow(string $teamId): void {
		if ($this->teams->findByTeamId($teamId) === null) {
			$this->teams->insertIgnore($teamId, null, $this->time->getTime());
		}
	}

	/**
	 * Besitzerwechsel (Verschieben aus requireLeave-Wegen): Anhänge der verschobenen Notizen zählen beim Ziel-Besitzer.
	 * Vorprüfung ohne Sperre; enforce() sperrt Quell- und Ziel-Besitzer und zählt die Bytes unter Sperre neu (auch bei 0 in der Vorprüfung)
	 * @return array{type: string, id: string, ws: int, bytes: int, leave?: LeaveRequest}|null
	 */
	public function forLeave(LeaveRequest $r): ?array {
		if (!$r->leaves() || !$r->changesOwner()) {
			return null;
		}
		$quota = ['type' => (string)$r->target->getOwnerType(), 'id' => (string)$r->target->getOwnerId(), 'ws' => (int)$r->target->getId(), 'bytes' => 0, 'leave' => $r];
		$this->checkSum($quota);
		return $quota;
	}

	/**
	 * Zwei Prüfungen gegen denselben Ziel-Workspace zusammenfassen (z. B. neue Bilder + Umzug beim PATCH)
	 * @param array{type: string, id: string, ws: int, bytes: int, leave?: LeaveRequest}|null $a
	 * @param array{type: string, id: string, ws: int, bytes: int, leave?: LeaveRequest}|null $b
	 * @return array{type: string, id: string, ws: int, bytes: int, leave?: LeaveRequest}|null
	 */
	public function combine(?array $a, ?array $b): ?array {
		if ($a === null || $b === null) {
			return $a ?? $b;
		}
		$a['bytes'] += $b['bytes'];
		if (isset($b['leave'])) {
			$a['leave'] = $b['leave'];
		}
		$this->checkSum($a);
		return $a;
	}

	/**
	 * Erste Anweisung der Transaktion: Workspace-Zeilen der Besitzer sperren (SQLite: Schreibsperre vor jedem Lesen), dann unter Sperre erneut prüfen.
	 * Feste Reihenfolge über alle Besitzer (Typ, ID), damit gegenläufiges Verschieben nicht verklemmt (M5a-r2 H1); Aufrufer sperren danach nur noch Zeilen dieser Besitzer.
	 * Mehrere Besitzer mit Team-Zeilen (ab M6d): erst alle Team-Zeilen in dieser Reihenfolge, danach alle lockOwner, nie abwechselnd je Besitzer (Review M6-Plan r2 Hinweis 6).
	 * @param array{type: string, id: string, ws: int, bytes: int, leave?: LeaveRequest}|null $quota
	 */
	public function enforce(?array $quota): void {
		if ($quota === null) {
			return;
		}
		$owners = [$quota['type'] . "\0" . $quota['id'] => [$quota['type'], $quota['id']]];
		if (isset($quota['leave'])) {
			$src = $quota['leave']->source;
			$owners[$src->getOwnerType() . "\0" . $src->getOwnerId()] = [(string)$src->getOwnerType(), (string)$src->getOwnerId()];
		}
		ksort($owners, SORT_STRING);
		// Team-Zeile vor allen Workspace-Zeilen (Sperr-Reihenfolge Team → Aufgabe → Workspace → Notiz, Plan M6 §2.4); fehlt sie, gibt es das Team nicht mehr
		foreach ($owners as [$type, $id]) {
			if ($type === 'team' && $this->teams->lockRow($id) === 0) {
				throw new NotFoundException('Team not found');
			}
		}
		foreach ($owners as [$type, $id]) {
			$this->workspaceMapper->lockOwner($type, $id);
		}
		$this->checkSum($quota);
	}

	public function limit(): int {
		return $this->appConfig->getValueInt(Application::APP_ID, 'attachment_quota_mb', AttachmentService::DEFAULT_QUOTA_MB) * self::MB;
	}

	// Anhänge, die mit dem Verschieben den Besitzer wechseln (gleiche Zeilen wie AccessService::requireLeave)
	private function leavingBytes(LeaveRequest $r): int {
		$src = (int)$r->source->getId();
		$folders = array_map(static fn ($f) => (int)$f->getId(), $r->folders);
		return match ($r->kind) {
			LeaveRequest::WORKSPACE => $this->attachments->sizeLeaving($src),
			LeaveRequest::FOLDER => $this->attachments->sizeLeaving($src, $folders, null),
			LeaveRequest::NOTES => $this->attachments->sizeLeaving($src, $folders === [] ? null : $folders, $r->noteIds),
			default => 0,
		};
	}

	/** @param array{type: string, id: string, ws: int, bytes: int, leave?: LeaveRequest} $quota */
	private function checkSum(array $quota): void {
		$bytes = $quota['bytes'] + (isset($quota['leave']) ? $this->leavingBytes($quota['leave']) : 0);
		if ($bytes <= 0) {
			return;
		}
		if ($this->used($quota['type'], $quota['id'], $quota['ws']) + $bytes > $this->limit()) {
			throw new TooLargeException('Storage quota exceeded');
		}
	}

	// Belegung eines Besitzers (Einstellungen → Speicher nutzt denselben Weg für Benutzer)
	public function used(string $type, string $id, int $wsId = 0): int {
		$wsIds = match ($type) {
			'user' => array_map(static fn ($w) => (int)$w->getId(), $this->workspaceMapper->findQuotaOwned($id)),
			'team' => array_map(static fn ($w) => (int)$w->getId(), $this->workspaceMapper->findByOwner('team', $id)),
			default => [$wsId],
		};
		$used = $wsIds === [] ? 0 : $this->attachments->sizeInWorkspaces($wsIds);
		return $type === 'team' ? $used + $this->attachments->sizeForTeamAssignments($id) : $used;
	}
}
