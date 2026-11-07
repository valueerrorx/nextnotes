<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service;

use OCA\NextNotes\Db\FolderMapper;
use OCA\NextNotes\Db\NoteMapper;
use OCA\NextNotes\Db\Workspace;
use OCA\NextNotes\Db\WorkspaceMapper;
use OCA\NextNotes\Db\WsShareMapper;
use OCA\NextNotes\Service\Exception\BadRequestException;
use OCA\NextNotes\Service\Exception\ForbiddenException;

// Workspace löschen (DELETE /workspaces, workspaces.php delete) und endgültiger Löschpfad (Team-Workspace ohne Ziel, M5c-Purge)
class WorkspaceDeletion {
	public function __construct(
		private WorkspaceService $workspaceService,
		private AccessService $access,
		private NoteService $noteService,
		private NoteMapper $notes,
		private FolderMapper $folders,
		private WsShareMapper $shares,
		private WorkspaceMapper $workspaces,
		private BackgroundFiles $backgrounds,
		private Transaction $tx,
	) {
	}

	/**
	 * Benutzer-Workspace: wie bisher (B18). Team-Workspace (Q2, Thomas 2026-11-07): verwalten; Inhalte in einen Workspace desselben Teams
	 * (Ziel wählbar, sonst kleinste ID); gibt es keinen, nur mit confirm endgültig löschen; nie in einen privaten Workspace.
	 */
	public function delete(string $uid, string $key, mixed $targetKey = null, bool $confirm = false): void {
		$ws = $this->workspaceService->requireByKey($uid, $key, 'manage');
		if ($ws->getOwnerType() !== 'team') {
			$this->workspaceService->delete($uid, $key);
			return;
		}
		$chosen = null;
		if (is_string($targetKey) && trim($targetKey) !== '') {
			$chosen = $this->workspaceService->requireByKey($uid, trim($targetKey), 'write');
			if ($chosen->getId() === $ws->getId()) {
				throw new BadRequestException('Source and target workspaces must differ');
			}
			if ($chosen->getOwnerType() !== 'team' || $chosen->getOwnerId() !== $ws->getOwnerId() || $chosen->getKind() !== 'normal') {
				throw new ForbiddenException('Content of a team workspace can only be moved within the same team');
			}
		}
		$teamId = (string)$ws->getOwnerId();
		$this->tx->run(function () use ($uid, $ws, $chosen, $teamId, $confirm) {
			// alle Workspaces des Teams sperren, dann Quelle und Ziel neu lesen
			$this->workspaces->lockOwner('team', $teamId);
			$source = $this->access->requireWorkspace($uid, (int)$ws->getId(), 'manage');
			$candidates = array_values(array_filter(
				$this->workspaces->findByOwner('team', $teamId, 'normal'),
				static fn (Workspace $w) => $w->getId() !== $source->getId(),
			));
			$target = $chosen !== null ? $this->access->requireWorkspace($uid, (int)$chosen->getId(), 'write') : ($candidates[0] ?? null);
			if ($target !== null) {
				$this->access->requireLeave($uid, new LeaveRequest($source, $target, LeaveRequest::WORKSPACE));
				$this->workspaceService->moveContents($source, $target);
				$this->shares->deleteForWorkspace((int)$source->getId());
				$this->workspaces->deleteById((int)$source->getId());
				$this->backgrounds->queueWorkspace((int)$source->getId());
				return;
			}
			if (!$confirm) {
				throw new BadRequestException('Deleting the last workspace of a team removes all its notes; confirmation required', ['code' => 'confirmation_required']);
			}
			$this->purge((int)$source->getId());
		});
		$this->workspaceService->reset();
	}

	// Endgültig wie UserDataService::purgeWorkspaces: Notizen samt Anhängen/Snapshots/Erinnerungen/Sperren/Erwähnungen, Ordner, Freigaben, Hintergründe; Dateien nach Commit (Team-Purge M5c)
	public function purge(int $workspaceId): void {
		$this->tx->run(function () use ($workspaceId) {
			$this->noteService->purge($this->notes->idsInWorkspace($workspaceId));
			$this->folders->deleteByIds(array_map(static fn ($f) => (int)$f->getId(), $this->folders->findInWorkspace($workspaceId)));
			$this->shares->deleteForWorkspace($workspaceId);
			$this->workspaces->deleteById($workspaceId);
			$this->backgrounds->queueWorkspace($workspaceId);
		});
	}
}
