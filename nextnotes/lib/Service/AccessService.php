<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service;

use OCA\NextNotes\Db\Folder;
use OCA\NextNotes\Db\FolderMapper;
use OCA\NextNotes\Db\Note;
use OCA\NextNotes\Db\NoteMapper;
use OCA\NextNotes\Db\Workspace;
use OCA\NextNotes\Db\WorkspaceMapper;
use OCA\NextNotes\Db\WsShareMapper;
use OCA\NextNotes\Service\Exception\BadRequestException;
use OCA\NextNotes\Service\Exception\ForbiddenException;
use OCA\NextNotes\Service\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;

// Einziger Prüfpunkt für Rechte (SPEC §3); ohne Leserecht 404, lesbar ohne Recht 403
class AccessService {
	public const NONE = 0;
	public const READ = 1;
	public const WRITE = 2;
	public const MANAGE = 3;

	private const PERMS = ['read' => self::READ, 'write' => self::WRITE, 'manage' => self::MANAGE];

	// Team-Level ab Moderator = verwalten (ADR 0003)
	private const TEAM_MANAGE_LEVEL = 4;

	/** @var array<string, int> Cache uid|wsId → Stufe für diesen Request */
	private array $levels = [];
	/** @var array<string, array<int, int>> Cache uid → [wsId → Stufe] aller sichtbaren Workspaces */
	private array $visibility = [];

	public function __construct(
		private WorkspaceMapper $workspaces,
		private WsShareMapper $shares,
		private NoteMapper $notes,
		private FolderMapper $folders,
		private TeamService $teams,
		private TeamStateService $teamState,
	) {
	}

	public function workspaceLevel(string $uid, Workspace $ws): int {
		$key = $uid . '|' . $ws->getId();
		if (!isset($this->levels[$key])) {
			$this->levels[$key] = $this->computeLevel($uid, $ws);
		}
		return $this->levels[$key];
	}

	private function computeLevel(string $uid, Workspace $ws): int {
		if ($ws->getOwnerType() === 'user') {
			if ($ws->getOwnerId() === $uid) {
				return self::MANAGE;
			}
			$share = $this->shares->findUserShare((int)$ws->getId(), $uid);
			if ($share === null) {
				return self::NONE;
			}
			return $share->getPermission() === 'write' ? self::WRITE : self::READ;
		}
		if ($ws->getOwnerType() === 'team') {
			return $this->teamLevel($uid, $ws);
		}
		// deleted und alles andere: kein Rechteweg
		return self::NONE;
	}

	// SPEC §3: aktiv → Mitglied schreibt (verwalten lazy in requireWorkspace); verwaist/eingefroren → nur created_by liest; Freigaben zählen nie
	private function teamLevel(string $uid, Workspace $ws): int {
		$teamId = (string)$ws->getOwnerId();
		if ($this->teamState->stateOf($teamId) !== TeamStateService::ACTIVE) {
			return $ws->getCreatedBy() !== null && $ws->getCreatedBy() === $uid ? self::READ : self::NONE;
		}
		return isset($this->teams->memberTeams($uid)[$teamId]) ? self::WRITE : self::NONE;
	}

	// Nach Änderung von Freigaben, Besitz oder Team-Mitgliedschaft im selben Request (auch Jobs, occ, Tests)
	public function resetCache(): void {
		$this->levels = [];
		$this->visibility = [];
		$this->teams->resetCache();
		$this->teamState->resetCache();
	}

	/**
	 * Alle sichtbaren Workspaces mit Stufe: eigene, Freigaben (nur Benutzer-Workspaces), Team-Workspaces (eine Circles-Abfrage, eine SQL-Abfrage)
	 * @return array<int, int>
	 */
	private function visibility(string $uid): array {
		if (isset($this->visibility[$uid])) {
			return $this->visibility[$uid];
		}
		$map = [];
		foreach ($this->workspaces->findOwned($uid) as $w) {
			$map[(int)$w->getId()] = self::MANAGE;
		}
		foreach ($this->workspaces->findSharedWith($uid) as $id => $permission) {
			$map[$id] ??= $permission === 'write' ? self::WRITE : self::READ;
		}
		$available = $this->teams->isAvailable();
		$teamIds = $available ? array_map('strval', array_keys($this->teams->memberTeams($uid))) : [];
		foreach ($this->workspaces->teamAccess($uid, $teamIds, $available) as $id => $permission) {
			$map[$id] ??= $permission === 'write' ? self::WRITE : self::READ;
		}
		return $this->visibility[$uid] = $map;
	}

	/** @return int[] */
	public function visibleWorkspaceIds(string $uid): array {
		return array_keys($this->visibility($uid));
	}

	/** @return int[] eigene Workspaces (owner_type=user), ohne Freigaben (ADR 0008: „alle“-Exporte) */
	public function ownWorkspaceIds(string $uid): array {
		return array_map(static fn (Workspace $w) => (int)$w->getId(), $this->workspaces->findOwned($uid));
	}

	/** @return int[] Workspaces mit Schreibrecht */
	public function writableWorkspaceIds(string $uid): array {
		return array_keys(array_filter($this->visibility($uid), static fn (int $level) => $level >= self::WRITE));
	}

	public function requireWorkspace(string $uid, int|Workspace $ws, string $perm): Workspace {
		if (is_int($ws)) {
			try {
				$ws = $this->workspaces->find($ws);
			} catch (DoesNotExistException) {
				throw new NotFoundException('Workspace not found');
			}
		}
		$level = $this->workspaceLevel($uid, $ws);
		if ($perm === 'manage' && $level === self::WRITE && $this->canManageTeam($uid, $ws)) {
			$level = self::MANAGE;
		}
		$this->check($level, $perm, 'Workspace not found');
		// Systemverwaltete Schüler-Workspaces verwaltet niemand (SPEC §3)
		if ($perm === 'manage' && $ws->getKind() !== 'normal') {
			throw new ForbiddenException('This workspace is managed by the system');
		}
		return $ws;
	}

	// Team-Workspace verwalten: aktives Team, effektives Level ≥ 4 (nur abgefragt, wenn verwalten gefragt ist)
	public function canManageTeam(string $uid, Workspace $ws): bool {
		return $ws->getOwnerType() === 'team' && $ws->getKind() === 'normal'
			&& $this->workspaceLevel($uid, $ws) === self::WRITE
			&& $this->teams->getLevel((string)$ws->getOwnerId(), $uid) >= self::TEAM_MANAGE_LEVEL;
	}

	/**
	 * Einzige Prüfstelle für Inhalte, die einen Workspace verlassen (B17, Plan M5 §2.2/§2.8); write am Ziel prüft der Aufrufer.
	 * Benutzer-Quelle: verwalten an der Quelle. Team-Quelle: schreiben; Ziel im selben Team, sonst nur eigene Notizen in den eigenen privaten Workspace (Ordner/Workspace zusätzlich verwalten).
	 * Systemverwaltetes (Vorlagen, Abgaben, Schüler-Workspaces, Aufgaben-Ordner) verlässt nie seinen Workspace und wird nicht kopiert (deny by default bis M6).
	 */
	public function requireLeave(string $uid, LeaveRequest $r): void {
		$src = $r->source;
		$leaves = $r->leaves();
		if (!$leaves && $r->kind !== LeaveRequest::COPY && $r->kind !== LeaveRequest::ATTACHMENT) {
			return;
		}
		$rows = match ($r->kind) {
			LeaveRequest::WORKSPACE => $this->notes->leaveRows((int)$src->getId()),
			LeaveRequest::FOLDER => $this->notes->leaveRows((int)$src->getId(), self::folderIds($r->folders), null),
			default => $this->notes->leaveRows((int)$src->getId(), $r->folders === [] ? null : self::folderIds($r->folders), $r->noteIds),
		};
		if ($src->getKind() !== 'normal'
			|| array_filter($r->folders, static fn (Folder $f) => $f->getAssignmentId() !== null) !== []
			|| array_filter($rows, static fn (array $row) => $row['role'] !== 'note') !== []) {
			throw new ForbiddenException('This content is managed by the system');
		}
		if (!$leaves) {
			return;
		}
		if ($src->getOwnerType() !== 'team') {
			$this->requireWorkspace($uid, $src, 'manage');
			return;
		}
		$this->requireWorkspace($uid, $src, 'write');
		$target = $r->target;
		if ($target->getOwnerType() === 'team' && $target->getOwnerId() === $src->getOwnerId()) {
			return;
		}
		if ($target->getOwnerType() === 'user' && $target->getOwnerId() === $uid && $target->getKind() === 'normal') {
			if ($r->kind === LeaveRequest::FOLDER || $r->kind === LeaveRequest::WORKSPACE || $r->folders !== []) {
				$this->requireWorkspace($uid, $src, 'manage');
			}
			// created_by NULL (Konto gelöscht) zählt nie als eigene Notiz
			if (array_filter($rows, static fn (array $row) => $row['created_by'] === null || $row['created_by'] !== $uid) === []) {
				return;
			}
		}
		throw new ForbiddenException('Content of a team workspace can only be moved within the same team');
	}

	/**
	 * Verlassen unter Sperre (Aufrufer hat Quelle und Ziel gesperrt, Reihenfolge Workspace → Notiz): prüfen, verschieben und bei der
	 * Eigene-Notiz-Ausnahme bestätigen, dass nur Notizen des Akteurs angekommen sind (parallel angelegte fremde → 403, Rollback)
	 * @template T
	 * @param callable(): T $move
	 * @return T
	 */
	public function moveLeaving(string $uid, LeaveRequest $r, callable $move): mixed {
		$this->requireLeave($uid, $r);
		$own = $r->leaves() && $r->source->getOwnerType() === 'team'
			&& !($r->target->getOwnerType() === 'team' && $r->target->getOwnerId() === $r->source->getOwnerId());
		$before = $own ? $this->notes->idsInWorkspace((int)$r->target->getId()) : [];
		$result = $move();
		if ($own) {
			$moved = array_values(array_diff($this->notes->idsInWorkspace((int)$r->target->getId()), $before));
			$rows = $moved === [] ? [] : $this->notes->leaveRows((int)$r->target->getId(), null, $moved);
			if (array_filter($rows, static fn (array $row) => $row['created_by'] !== $uid || $row['role'] !== 'note') !== []) {
				throw new ForbiddenException('Content of a team workspace can only be moved within the same team');
			}
		}
		return $result;
	}

	/**
	 * @param Folder[] $folders
	 * @return int[]
	 */
	private static function folderIds(array $folders): array {
		return array_map(static fn (Folder $f) => (int)$f->getId(), $folders);
	}

	public function noteLevel(string $uid, Note $note): int {
		// Vorlagen/Abgaben ab M6, bis dahin deny by default
		if ($note->getRole() !== 'note' || $note->getWorkspaceId() === null) {
			return self::NONE;
		}
		try {
			$ws = $this->workspaces->find((int)$note->getWorkspaceId());
		} catch (DoesNotExistException) {
			return self::NONE;
		}
		return min($this->workspaceLevel($uid, $ws), self::WRITE);
	}

	/**
	 * Lesbare Notizen ohne Einzelabfrage je Workspace (gleiche Regel wie noteLevel ≥ READ)
	 * @param Note[] $notes
	 * @return Note[]
	 */
	public function filterReadable(string $uid, array $notes): array {
		return self::inWorkspaces($notes, $this->visibleWorkspaceIds($uid));
	}

	/**
	 * Lesbar neben einer schon lesbar geladenen Notiz (requireNote/resolveLinked): Rechte sind workspaceweit (SPEC §3), daher ohne Abfrage
	 * @param Note[] $notes
	 * @return Note[]
	 */
	public function filterReadableBeside(Note $readable, array $notes): array {
		return $readable->getWorkspaceId() === null ? [] : self::inWorkspaces($notes, [(int)$readable->getWorkspaceId()]);
	}

	/**
	 * @param Note[] $notes
	 * @param int[] $workspaceIds
	 * @return Note[]
	 */
	private static function inWorkspaces(array $notes, array $workspaceIds): array {
		$visible = array_flip($workspaceIds);
		return array_values(array_filter($notes, static fn (Note $n) => $n->getRole() === 'note'
			&& $n->getWorkspaceId() !== null && isset($visible[(int)$n->getWorkspaceId()])));
	}

	/**
	 * @param bool|null $trashed false = nicht im Papierkorb, true = nur Papierkorb, null = egal
	 */
	public function requireNote(string $uid, int $id, string $perm, ?bool $trashed = false): Note {
		try {
			$note = $this->notes->find($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Note not found');
		}
		$level = $this->noteLevel($uid, $note);
		$this->check($level, $perm === 'manage' ? 'write' : $perm, 'Note not found');
		if ($trashed === false && $note->getTrashedAt() !== null) {
			throw new NotFoundException('Note not found');
		}
		if ($trashed === true && $note->getTrashedAt() === null) {
			throw new BadRequestException('Note is not in trash');
		}
		return $note;
	}

	/** Verknüpfte Notiz → Ziel mit Leserecht (B42), eine Stufe; fehlt/fremd/selbst verknüpft → 404; sonst die Notiz selbst */
	public function resolveLinked(string $uid, Note $note): Note {
		if ($note->getType() !== 'linked') {
			return $note;
		}
		if ($note->getLinkedNoteId() === null) {
			throw new NotFoundException('Note not found');
		}
		$target = $this->requireNote($uid, (int)$note->getLinkedNoteId(), 'read');
		if ($target->getType() === 'linked') {
			throw new NotFoundException('Note not found');
		}
		return $target;
	}

	public function canRead(string $uid, int $noteId): bool {
		try {
			return $this->noteLevel($uid, $this->notes->find($noteId)) >= self::READ;
		} catch (DoesNotExistException) {
			return false;
		}
	}

	// manage = Umbenennen/Löschen/Verschieben (Schreibrecht, nicht systemverwaltet)
	public function requireFolder(string $uid, int $id, string $perm): Folder {
		try {
			$folder = $this->folders->find($id);
			$ws = $this->workspaces->find((int)$folder->getWorkspaceId());
		} catch (DoesNotExistException) {
			throw new NotFoundException('Folder not found');
		}
		$level = min($this->workspaceLevel($uid, $ws), self::WRITE);
		$this->check($level, $perm === 'manage' ? 'write' : $perm, 'Folder not found');
		if ($perm === 'manage' && $folder->getAssignmentId() !== null) {
			throw new ForbiddenException('This folder is managed by the system');
		}
		return $folder;
	}

	private function check(int $level, string $perm, string $notFound): void {
		$need = self::PERMS[$perm] ?? self::MANAGE;
		if ($level < self::READ) {
			throw new NotFoundException($notFound);
		}
		if ($level < $need) {
			throw new ForbiddenException('Permission denied');
		}
	}
}
