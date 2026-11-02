<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @extends QBMapper<Reminder> */
class ReminderMapper extends QBMapper {
	public const TABLE = 'nextnotes_reminders';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE, Reminder::class);
	}

	// Bedingung für Notiz- (task_id NULL) oder Aufgaben-Erinnerung
	private function whereTask(IQueryBuilder $qb, ?string $taskId): void {
		$qb->andWhere($taskId === null
			? $qb->expr()->isNull('task_id')
			: $qb->expr()->eq('task_id', $qb->createNamedParameter($taskId)));
	}

	// Ungesendete Erinnerung des Benutzers zu Notiz bzw. Aufgabe
	public function findPending(string $uid, int $noteId, ?string $taskId): ?Reminder {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('note_id', $qb->createNamedParameter($noteId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->isNull('sent_at'))
			->orderBy('id', 'DESC')
			->setMaxResults(1);
		$this->whereTask($qb, $taskId);
		return $this->findEntities($qb)[0] ?? null;
	}

	// Obergrenze offener Erinnerungen je Benutzer: hält den Job-Lauf für alle kurz
	public const MAX_PENDING = 1000;

	// true, wenn eine neue (nicht ersetzende) Erinnerung die Obergrenze überschreiten würde
	public function atLimit(string $uid, int $noteId, ?string $taskId): bool {
		return $this->findPending($uid, $noteId, $taskId) === null && $this->countPending($uid) >= self::MAX_PENDING;
	}

	// Ungesendete Erinnerungen des Benutzers
	public function countPending(string $uid): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from(self::TABLE)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->isNull('sent_at'));
		$result = $qb->executeQuery();
		$n = (int)$result->fetchOne();
		$result->closeCursor();
		return $n;
	}

	/**
	 * Ungesendete Notiz-Erinnerungen des Benutzers
	 * @param int[] $noteIds
	 * @return array<int, int> note_id → trigger_at
	 */
	public function pendingNoteTimes(string $uid, array $noteIds): array {
		$out = [];
		foreach (array_chunk(array_values(array_unique($noteIds)), 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('note_id', 'trigger_at')->from(self::TABLE)
				->where($qb->expr()->in('note_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
				->andWhere($qb->expr()->isNull('sent_at'))
				->andWhere($qb->expr()->isNull('task_id'));
			$res = $qb->executeQuery();
			while ($row = $res->fetch()) {
				$out[(int)$row['note_id']] = (int)$row['trigger_at'];
			}
			$res->closeCursor();
		}
		return $out;
	}

	// Alle Erinnerungen (auch gesendete) des Benutzers zu Notiz bzw. Aufgabe
	public function deleteFor(string $uid, int $noteId, ?string $taskId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->eq('note_id', $qb->createNamedParameter($noteId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)));
		$this->whereTask($qb, $taskId);
		$qb->executeStatement();
	}

	// Neue Erinnerung; ersetzt alle früheren des Benutzers zu Notiz bzw. Aufgabe
	public function replace(string $uid, int $noteId, ?string $taskId, int $triggerAt, string $message, ?string $recurrence, int $now): Reminder {
		$this->deleteFor($uid, $noteId, $taskId);
		$r = new Reminder();
		$r->setNoteId($noteId);
		$r->setUserId($uid);
		$r->setTaskId($taskId);
		$r->setType('reminder');
		$r->setMessage(mb_substr($message, 0, 1000));
		$r->setTriggerAt($triggerAt);
		$r->setRecurrence($recurrence);
		$r->setCreatedAt($now);
		return $this->insert($r);
	}

	// Ungesendete Erinnerungen aller Benutzer zu einer Aufgabe (Aufgabe gelöscht/erledigt)
	public function deletePendingForTask(int $noteId, string $taskId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->eq('note_id', $qb->createNamedParameter($noteId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('task_id', $qb->createNamedParameter($taskId)))
			->andWhere($qb->expr()->isNull('sent_at'))
			->executeStatement();
	}

	/** @return Reminder[] fällig und ungesendet, älteste zuerst */
	public function findDue(int $now, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->isNull('sent_at'))
			->andWhere($qb->expr()->lte('trigger_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)))
			->orderBy('trigger_at', 'ASC')
			->addOrderBy('id', 'ASC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	// Versand beanspruchen; false, wenn ein paralleler Lauf schneller war
	public function claim(int $id, int $now): bool {
		$qb = $this->db->getQueryBuilder();
		return $qb->update(self::TABLE)
			->set('sent_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('sent_at'))
			->executeStatement() === 1;
	}

	public function deleteById(int $id): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/** @param int[] $noteIds */
	public function deleteForNotes(array $noteIds, ?string $uid = null): void {
		foreach (array_chunk(array_values(array_unique($noteIds)), 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete(self::TABLE)
				->where($qb->expr()->in('note_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			if ($uid !== null) {
				$qb->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)));
			}
			$qb->executeStatement();
		}
	}
}
