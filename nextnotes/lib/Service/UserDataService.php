<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service;

use OCA\NextNotes\BackgroundJob\UserDataDeletionJob;
use OCA\NextNotes\Db\AssignmentMapper;
use OCA\NextNotes\Db\AttachmentMapper;
use OCA\NextNotes\Db\FolderMapper;
use OCA\NextNotes\Db\LockMapper;
use OCA\NextNotes\Db\MentionHandledMapper;
use OCA\NextNotes\Db\NoteMapper;
use OCA\NextNotes\Db\ReminderMapper;
use OCA\NextNotes\Db\SnapshotMapper;
use OCA\NextNotes\Db\SubmissionMapper;
use OCA\NextNotes\Db\WorkspaceMapper;
use OCA\NextNotes\Db\WsShareMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

// Kontolöschung (SPEC §8): Rechte und UIDs sofort neutralisieren, Inhalte später im UserDataDeletionJob löschen
class UserDataService {
	public const DELETED = 'deleted';

	/** @var array<string, string[]> uid → Team-IDs aus BeforeUserDeletedEvent (Circles entfernt die Mitgliedschaft im UserDeletedEvent) */
	private array $teamsBefore = [];

	public function __construct(
		private IDBConnection $db,
		private Transaction $tx,
		private IJobList $jobs,
		private NoteService $noteService,
		private WorkspaceService $workspaceService,
		private NoteMapper $notes,
		private FolderMapper $folders,
		private WsShareMapper $shares,
		private WorkspaceMapper $workspaces,
		private ITimeFactory $time,
		private LoggerInterface $logger,
		private BackgroundFiles $backgrounds,
		private SubmissionMapper $submissions,
		private AssignmentOwnership $ownership,
	) {
	}

	/** @param string[] $teamIds */
	public function rememberTeams(string $uid, array $teamIds): void {
		$this->teamsBefore[$uid] = $teamIds;
	}

	/**
	 * Synchron beim UserDeletedEvent: danach verleiht und trägt keine Zeile mehr die UID (SPEC §2 UID-Regel)
	 * @return string|null Kennung der entkoppelten Workspaces für den Job, null wenn keine
	 */
	public function neutralize(string $uid, ?string $displayName = null): ?string {
		// Kennung statt UID: '#' kommt in NC-UIDs nicht vor; hält nn_ws_asg eindeutig und das Job-Argument klein
		$token = '#' . bin2hex(random_bytes(8));
		// Aufgaben als Lehrkraft (§9.7): Circles-Abfragen vor der Transaktion; wirft nie, Rest wird unten NULL
		$this->ownership->releaseDeleted($uid);
		$found = $this->tx->run(function () use ($uid, $token, $displayName): bool {
			// Erste Anweisung schreibt (SQLite: Schreibsperre vor jedem Lesen)
			$qb = $this->db->getQueryBuilder();
			$moved = $qb->update(WorkspaceMapper::TABLE)
				->set('owner_type', $qb->createNamedParameter(self::DELETED))
				->set('owner_id', $qb->createNamedParameter($token))
				->where($qb->expr()->eq('owner_type', $qb->createNamedParameter('user')))
				->andWhere($qb->expr()->eq('owner_id', $qb->createNamedParameter($uid)))
				->executeStatement();
			// Abgaben bleiben (ADR 0008 a, Plan M6 §2.5 O3): in derselben Transaktion wie owner_type=deleted Kopien lösen (Workspace → Notizen → Abgaben)
			$now = $this->time->getTime();
			$this->notes->detachSubmissions($this->submissions->noteIdsOf($uid), $now);
			$this->submissions->detachStudent($uid, $displayName, $now);
			$this->submissions->clearFeedbackBy($uid);
			$this->clear(AssignmentMapper::TABLE, 'created_by', $uid);
			$this->clear(AssignmentMapper::TABLE, 'owner_uid', $uid);
			// Hintergrundbilder in fremden Workspaces: Freigaben und Team-Workspaces der Teams vor der Löschung bzw. mit created_by (R15); eigene gehen mit dem Workspace im Job
			$teamWs = $this->workspaces->teamWorkspaceIdsFor($this->teamsBefore[$uid] ?? [], $uid);
			$this->backgrounds->queueUser(array_merge($this->shares->workspaceIdsSharedWith($uid), $teamWs), $uid, false);
			$qb = $this->db->getQueryBuilder();
			$qb->delete(WsShareMapper::TABLE)
				->where($qb->expr()->eq('share_type', $qb->createNamedParameter('user')))
				->andWhere($qb->expr()->eq('share_with', $qb->createNamedParameter($uid)))
				->executeStatement();
			$this->deleteWhere(WsShareMapper::TABLE, 'created_by', $uid);
			$this->deleteWhere(ReminderMapper::TABLE, 'user_id', $uid);
			$this->deleteWhere(LockMapper::TABLE, 'holder_uid', $uid);
			$this->deleteWhere(MentionHandledMapper::TABLE, 'user_id', $uid);
			$this->clear(WorkspaceMapper::TABLE, 'created_by', $uid);
			$this->clear(NoteMapper::TABLE, 'created_by', $uid);
			$this->clear(NoteMapper::TABLE, 'updated_by', $uid);
			$this->clear(AttachmentMapper::TABLE, 'created_by', $uid);
			$this->clear(SnapshotMapper::TABLE, 'created_by', $uid);
			// Team-Workspaces bleiben (gehören dem Team, ADR 0003), created_by oben NULL
			if ($moved > 0) {
				$this->jobs->add(UserDataDeletionJob::class, ['token' => $token]);
			}
			return $moved > 0;
		});
		unset($this->teamsBefore[$uid]);
		$this->workspaceService->reset();
		return $found ? $token : null;
	}

	private function deleteWhere(string $table, string $column, string $uid): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($table)
			->where($qb->expr()->eq($column, $qb->createNamedParameter($uid)))
			->executeStatement();
	}

	private function clear(string $table, string $column, string $uid): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($table)
			->set($column, $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($qb->expr()->eq($column, $qb->createNamedParameter($uid)))
			->executeStatement();
	}

	/** Job: löscht die entkoppelten Workspaces samt Inhalt und Dateien; idempotent, berührt nie Daten über die UID; stoppt zwischen Workspaces ab $deadline */
	public function purgeWorkspaces(string $token, ?int $deadline = null): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from(WorkspaceMapper::TABLE)
			->where($qb->expr()->eq('owner_type', $qb->createNamedParameter(self::DELETED)))
			->andWhere($qb->expr()->eq('owner_id', $qb->createNamedParameter($token)))
			->orderBy('id', 'ASC');
		$ids = array_map('intval', $qb->executeQuery()->fetchFirstColumn());
		$done = 0;
		foreach ($ids as $id) {
			if ($deadline !== null && $this->time->getTime() >= $deadline) {
				break;
			}
			// Je Workspace eine Transaktion; Dateien (IAppData) erst nach deren Commit; nur role=note, Abgaben/Vorlagen nie (Plan M6 §2.5 H3)
			$this->tx->run(function () use ($id) {
				$this->noteService->purge($this->notes->idsInWorkspace($id, 'note'));
				if ($this->notes->idsInWorkspace($id) !== []) {
					$this->logger->warning('nextnotes: deleted workspace still holds system notes, kept', ['workspace' => $id]);
					return;
				}
				$this->folders->deleteByIds(array_map(static fn ($f) => (int)$f->getId(), $this->folders->findInWorkspace($id)));
				$this->shares->deleteForWorkspace($id);
				$this->workspaces->deleteById($id);
				$this->backgrounds->queueWorkspace($id);
			});
			$done++;
		}
		return $done;
	}

	/** Nachlauf (täglich): alle übrig gebliebenen owner_type=deleted-Workspaces, unabhängig vom Lösch-Job; begrenzt auf $budget Sekunden */
	public function sweep(int $budget): int {
		$deadline = $this->time->getTime() + $budget;
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('owner_id')->from(WorkspaceMapper::TABLE)
			->where($qb->expr()->eq('owner_type', $qb->createNamedParameter(self::DELETED)))
			->orderBy('owner_id', 'ASC')
			->setMaxResults(1000);
		$tokens = array_map('strval', $qb->executeQuery()->fetchFirstColumn());
		$done = 0;
		foreach ($tokens as $token) {
			if ($this->time->getTime() >= $deadline) {
				break;
			}
			// Fehler je Kennung: Transaction::run hat zurückgerollt; nächste Kennung nicht blockieren
			try {
				$done += $this->purgeWorkspaces($token, $deadline);
			} catch (\Throwable $e) {
				$this->logger->error('nextnotes: purging deleted workspaces failed', ['token' => $token, 'exception' => $e]);
			}
		}
		return $done;
	}
}
