<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service;

use OCA\NextNotes\Db\AssignmentMapper;
use OCA\NextNotes\Db\FolderMapper;
use OCA\NextNotes\Db\NoteMapper;
use OCA\NextNotes\Db\TeamMapper;
use OCA\NextNotes\Db\Workspace;
use OCA\NextNotes\Db\WorkspaceMapper;
use OCA\NextNotes\Db\WsShareMapper;
use OCA\NextNotes\Service\Exception\BadRequestException;
use OCA\NextNotes\Service\Exception\ConflictException;
use OCA\NextNotes\Service\Exception\ForbiddenException;
use Psr\Log\LoggerInterface;

// Workspace löschen (DELETE /workspaces, workspaces.php delete; Aufgaben ADR 0021) und endgültiger Löschpfad (Team-Workspace ohne Ziel, M5c-Purge)
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
		private LoggerInterface $logger,
		private AssignmentMapper $assignments,
		private AssignmentDeletion $assignmentDeletion,
		private TeamMapper $teams,
	) {
	}

	/**
	 * Benutzer-Workspace: wie bisher (B18). Team-Workspace (Q2, Thomas 2026-11-07): verwalten; Inhalte in einen Workspace desselben Teams
	 * (Ziel wählbar, sonst kleinste ID); gibt es keinen, nur mit confirm endgültig löschen; nie in einen privaten Workspace.
	 * Aufgaben (ADR 0021): ziehen mit ins Ziel um; ohne Ziel werden sie samt Abgaben gelöscht (ADR 0008). Ziel unter der Sperre geändert → 409.
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
		$sourceId = (int)$ws->getId();
		$others = fn (): array => array_values(array_filter(
			$this->workspaces->findByOwner('team', $teamId, 'normal'),
			static fn (Workspace $w) => (int)$w->getId() !== $sourceId,
		));
		$first = $others()[0] ?? null;
		$plannedId = $chosen !== null ? (int)$chosen->getId() : ($first === null ? null : (int)$first->getId());
		if ($plannedId === null) {
			if (!$confirm) {
				throw new BadRequestException('Deleting the last workspace of a team removes all its notes and assignments; confirmation required', ['code' => 'confirmation_required']);
			}
			// Aufgaben samt Abgaben endgültig, je eine Transaktion (Recht delete = verwalten im aktiven Team); Teilabbruch zulässig, Wiederholung setzt fort
			foreach ($this->assignments->idsForWorkspace($sourceId) as $id) {
				$this->assignmentDeletion->purge($id);
			}
		}
		$this->tx->run(function () use ($uid, $teamId, $sourceId, $chosen, $plannedId, $others) {
			// Sperr-Reihenfolge Team → Aufgaben → Workspaces des Teams (wie AssignmentService::create), dann Quelle und Ziel neu lesen
			$this->teams->lockRow($teamId);
			if ($plannedId !== null) {
				$this->assignments->rebindWorkspace($sourceId, $plannedId);
			}
			$this->workspaces->lockOwner('team', $teamId);
			$source = $this->access->requireWorkspace($uid, $sourceId, 'manage');
			$candidates = $others();
			$target = $candidates[0] ?? null;
			if ($chosen !== null) {
				$target = array_values(array_filter($candidates, static fn (Workspace $w) => $w->getId() === $chosen->getId()))[0] ?? null;
			}
			if (($target === null ? null : (int)$target->getId()) !== $plannedId || ($target === null && $this->assignments->idsForWorkspace($sourceId) !== [])) {
				throw new ConflictException('The workspace has changed; try again', ['code' => 'workspace_changed']);
			}
			if ($target !== null) {
				$target = $this->access->requireWorkspace($uid, $target, 'write');
				$this->access->requireLeave($uid, new LeaveRequest($source, $target, LeaveRequest::WORKSPACE));
				$this->workspaceService->moveContents($source, $target);
				$this->shares->deleteForWorkspace($sourceId);
				$this->workspaces->deleteById($sourceId);
				$this->backgrounds->queueWorkspace($sourceId);
				return;
			}
			$this->purge($sourceId);
		});
		$this->workspaceService->reset();
	}

	// Endgültig wie UserDataService::purgeWorkspaces: Notizen samt Anhängen/Snapshots/Erinnerungen/Sperren/Erwähnungen, Ordner, Freigaben, Hintergründe; Dateien nach Commit (Team-Purge M5c)
	public function purge(int $workspaceId): void {
		$this->tx->run(function () use ($workspaceId) {
			// nur role=note; ein Workspace mit Abgaben/Vorlagen bleibt (Plan M6 §2.5 H3)
			$this->noteService->purge($this->notes->idsInWorkspace($workspaceId, 'note'));
			if ($this->notes->idsInWorkspace($workspaceId) !== []) {
				$this->logger->warning('nextnotes: workspace still holds system notes, kept', ['workspace' => $workspaceId]);
				return;
			}
			$this->folders->deleteByIds(array_map(static fn ($f) => (int)$f->getId(), $this->folders->findInWorkspace($workspaceId)));
			$this->shares->deleteForWorkspace($workspaceId);
			$this->workspaces->deleteById($workspaceId);
			$this->backgrounds->queueWorkspace($workspaceId);
		});
	}
}
