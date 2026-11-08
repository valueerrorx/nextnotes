<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service;

use OCA\NextNotes\AppInfo\Application;
use OCA\NextNotes\Db\AssignmentMapper;
use OCA\NextNotes\Db\FolderMapper;
use OCA\NextNotes\Db\NoteMapper;
use OCA\NextNotes\Db\SubmissionMapper;
use OCA\NextNotes\Db\TeamMapper;
use OCA\NextNotes\Db\WorkspaceMapper;
use OCA\NextNotes\Db\WsShareMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Notification\IManager as INotificationManager;

// Einziger Löschpfad einer Aufgabe (DELETE, Team-Purge M6f, Tests): Rechte prüft der Aufrufer
class AssignmentDeletion {
	public function __construct(
		private AssignmentMapper $assignments,
		private SubmissionMapper $submissions,
		private NoteMapper $notes,
		private FolderMapper $folders,
		private WorkspaceMapper $workspaces,
		private WsShareMapper $shares,
		private TeamMapper $teams,
		private NoteService $noteService,
		private Transaction $tx,
		private INotificationManager $notifications,
	) {
	}

	/**
	 * Aufgabe, Vorlage, Abgaben, Kopien (Anhänge, Snapshots, Erinnerungen, Sperren, Erwähnungen), Aufgaben-Ordner, leere Schüler-Workspaces;
	 * eine Transaktion, Dateien nach dem Commit. Sperren: Team → Aufgabe → Schüler-Workspaces → Notizen → Abgaben (Plan M6 §2.4)
	 * @return bool false = Aufgabe gab es schon nicht mehr
	 */
	public function purge(int $assignmentId): bool {
		try {
			$teamId = (string)$this->assignments->find($assignmentId)->getTeamId();
		} catch (DoesNotExistException) {
			return false;
		}
		$submissionIds = [];
		$done = $this->tx->run(function () use ($assignmentId, $teamId, &$submissionIds): bool {
			$this->teams->lockRow($teamId);
			if ($this->assignments->lockRow($assignmentId) === 0) {
				return false;
			}
			$folders = $this->folders->findByAssignment($assignmentId);
			$wsIds = array_values(array_unique(array_map(static fn ($f) => (int)$f->getWorkspaceId(), $folders)));
			if ($wsIds !== []) {
				$this->workspaces->lockRows($wsIds);
			}
			$this->noteService->purge($this->notes->idsForAssignment($assignmentId));
			$submissionIds = array_map(static fn ($s) => (int)$s->getId(), $this->submissions->findByAssignment($assignmentId));
			$this->submissions->deleteByAssignment($assignmentId);
			$this->folders->deleteByIds(array_map(static fn ($f) => (int)$f->getId(), $folders));
			$this->assignments->deleteById($assignmentId);
			foreach ($this->workspaces->findByIds($wsIds) as $ws) {
				$id = (int)$ws->getId();
				if ($ws->getKind() === 'assignments' && $this->notes->idsInWorkspace($id) === [] && $this->folders->findInWorkspace($id) === []) {
					$this->shares->deleteForWorkspace($id);
					$this->workspaces->deleteById($id);
				}
			}
			return true;
		});
		if ($done) {
			foreach (array_merge([['assignment', $assignmentId]], array_map(static fn (int $id) => ['submission', $id], $submissionIds)) as [$type, $id]) {
				$n = $this->notifications->createNotification();
				$n->setApp(Application::APP_ID)->setObject($type, (string)$id);
				$this->notifications->markProcessed($n);
			}
		}
		return $done;
	}
}
