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

	/** @var array<string, int> Cache uid|wsId → Stufe für diesen Request */
	private array $levels = [];

	public function __construct(
		private WorkspaceMapper $workspaces,
		private WsShareMapper $shares,
		private NoteMapper $notes,
		private FolderMapper $folders,
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

	// Platzhalter bis M5 (Teams)
	private function teamLevel(string $uid, Workspace $ws): int {
		return self::NONE;
	}

	// Nach Änderung von Freigaben/Besitz im selben Request
	public function resetCache(): void {
		$this->levels = [];
	}

	/** @return int[] */
	public function visibleWorkspaceIds(string $uid): array {
		$ids = array_map(static fn (Workspace $w) => (int)$w->getId(), $this->workspaces->findOwned($uid));
		foreach (array_keys($this->workspaces->findSharedWith($uid)) as $id) {
			$ids[] = $id;
		}
		return array_values(array_unique($ids));
	}

	/** @return int[] Workspaces mit Schreibrecht */
	public function writableWorkspaceIds(string $uid): array {
		$ids = array_map(static fn (Workspace $w) => (int)$w->getId(), $this->workspaces->findOwned($uid));
		foreach ($this->workspaces->findSharedWith($uid) as $id => $permission) {
			if ($permission === 'write') {
				$ids[] = $id;
			}
		}
		return array_values(array_unique($ids));
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
		$this->check($level, $perm, 'Workspace not found');
		// Systemverwaltete Schüler-Workspaces verwaltet niemand (SPEC §3)
		if ($perm === 'manage' && $ws->getKind() !== 'normal') {
			throw new ForbiddenException('This workspace is managed by the system');
		}
		return $ws;
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
