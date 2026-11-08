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

/** @extends QBMapper<Submission> */
class SubmissionMapper extends QBMapper {
	public const TABLE = 'nextnotes_submissions';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE, Submission::class);
	}

	/** @throws \OCP\AppFramework\Db\DoesNotExistException */
	public function find(int $id): Submission {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * @param int[] $ids
	 * @return array<int, Submission> id → Abgabe
	 */
	public function findByIds(array $ids): array {
		$out = [];
		foreach (array_chunk(array_values(array_unique($ids)), 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')->from(self::TABLE)
				->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			foreach ($this->findEntities($qb) as $s) {
				$out[(int)$s->getId()] = $s;
			}
		}
		return $out;
	}

	/** @return Submission[] Abgaben einer Aufgabe nach Name, dann ID */
	public function findByAssignment(int $assignmentId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('assignment_id', $qb->createNamedParameter($assignmentId, IQueryBuilder::PARAM_INT)))
			->orderBy('student_name', 'ASC')->addOrderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * Eigene Abgaben (§9.6 c)
	 * @param int[]|null $assignmentIds null = alle
	 * @return array<int, Submission> assignment_id → Abgabe
	 */
	public function findOwn(string $uid, ?array $assignmentIds = null): array {
		if ($assignmentIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('student_uid', $qb->createNamedParameter($uid)));
		if ($assignmentIds !== null) {
			$qb->andWhere($qb->expr()->in('assignment_id', $qb->createNamedParameter(array_values(array_unique($assignmentIds)), IQueryBuilder::PARAM_INT_ARRAY)));
		}
		$out = [];
		foreach ($this->findEntities($qb) as $s) {
			$out[(int)$s->getAssignmentId()] = $s;
		}
		return $out;
	}

	/** @return int[] Aufgaben mit eigener Abgabe (§9.6 c) */
	public function assignmentIdsOf(string $uid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('assignment_id')->from(self::TABLE)
			->where($qb->expr()->eq('student_uid', $qb->createNamedParameter($uid)));
		return array_map('intval', $qb->executeQuery()->fetchFirstColumn());
	}

	/** @return list<array{assignment: int, team: string, left: bool}> eigene Abgaben mit Team und Austrittsmarke (§9.6 c, M7h) */
	public function ownWithTeam(string $uid): array {
		$qb = $this->db->getQueryBuilder();
		$expr = $qb->expr();
		$qb->select('s.assignment_id', 's.left_team_at', 'a.team_id')->from(self::TABLE, 's')
			->innerJoin('s', AssignmentMapper::TABLE, 'a', $expr->eq('a.id', 's.assignment_id'))
			->where($expr->eq('s.student_uid', $qb->createNamedParameter($uid)));
		$out = [];
		$res = $qb->executeQuery();
		while ($row = $res->fetch()) {
			$out[] = ['assignment' => (int)$row['assignment_id'], 'team' => (string)$row['team_id'], 'left' => $row['left_team_at'] !== null];
		}
		$res->closeCursor();
		return $out;
	}

	// Austrittsmarke des Schülers in einer Aufgabe dieses Teams (Schüler-Workspace bei verwaistem/eingefrorenem Team, M7h)
	public function hasLeftIn(string $uid, string $teamId): bool {
		$qb = $this->db->getQueryBuilder();
		$expr = $qb->expr();
		$qb->select('s.id')->from(self::TABLE, 's')
			->innerJoin('s', AssignmentMapper::TABLE, 'a', $expr->eq('a.id', 's.assignment_id'))
			->where($expr->eq('s.student_uid', $qb->createNamedParameter($uid)))
			->andWhere($expr->eq('a.team_id', $qb->createNamedParameter($teamId)))
			->andWhere($expr->isNotNull('s.left_team_at'))
			->setMaxResults(1);
		$res = $qb->executeQuery();
		$found = $res->fetchOne() !== false;
		$res->closeCursor();
		return $found;
	}

	public function existsFor(int $assignmentId, string $uid): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from(self::TABLE)
			->where($qb->expr()->eq('assignment_id', $qb->createNamedParameter($assignmentId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('student_uid', $qb->createNamedParameter($uid)))
			->setMaxResults(1);
		$res = $qb->executeQuery();
		$found = $res->fetchOne() !== false;
		$res->closeCursor();
		return $found;
	}

	// Fristverlängerung (§9.2): missed ohne Abgabezeit und ohne eigene Frist → assigned
	public function resetMissed(int $assignmentId, int $now): void {
		$qb = $this->db->getQueryBuilder();
		$expr = $qb->expr();
		$qb->update(self::TABLE)
			->set('status', $qb->createNamedParameter('assigned'))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($expr->eq('assignment_id', $qb->createNamedParameter($assignmentId, IQueryBuilder::PARAM_INT)))
			->andWhere($expr->eq('status', $qb->createNamedParameter('missed')))
			// gelöschte Konten bleiben verpasst
			->andWhere($expr->isNotNull('student_uid'))
			->andWhere($expr->isNull('submitted_at'))
			->andWhere($expr->isNull('due_override_at'))
			->executeStatement();
	}

	public function deleteByAssignment(int $assignmentId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->eq('assignment_id', $qb->createNamedParameter($assignmentId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/** @return array<int, array{total: int, submitted: int, by_status: array<string, int>}> assignment_id → Zähler (Lehrkraft-Liste) */
	public function counts(array $assignmentIds): array {
		$out = [];
		foreach (array_chunk(array_values(array_unique($assignmentIds)), 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('assignment_id', 'status')->selectAlias($qb->func()->count('id'), 'n')->from(self::TABLE)
				->where($qb->expr()->in('assignment_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->groupBy('assignment_id', 'status');
			$res = $qb->executeQuery();
			while ($row = $res->fetch()) {
				$id = (int)$row['assignment_id'];
				$out[$id] ??= ['total' => 0, 'submitted' => 0, 'by_status' => []];
				$out[$id]['total'] += (int)$row['n'];
				$out[$id]['by_status'][(string)$row['status']] = (int)$row['n'];
				if (in_array($row['status'], ['submitted', 'returned'], true)) {
					$out[$id]['submitted'] += (int)$row['n'];
				}
			}
			$res->closeCursor();
		}
		return $out;
	}

	/**
	 * Bedingter Übergang (SPEC §9.2): nur wenn der Status noch $from ist; Rückgabe true = genau diese Zeile geändert
	 * @param array<string, int|string|null> $values Spalte → Wert
	 */
	public function updateIfStatus(int $id, string $from, array $values): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE);
		foreach ($values as $col => $v) {
			$qb->set($col, $qb->createNamedParameter($v, $v === null ? IQueryBuilder::PARAM_NULL : (is_int($v) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR)));
		}
		$qb->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($from)));
		return $qb->executeStatement() === 1;
	}

	/**
	 * Materialisierung (§9.3) als Massen-UPDATE: assigned nach Aufgabenfrist, reopened nach eigener Frist → missed
	 * @param int[]|null $ids null = alle Abgaben der Aufgabe
	 */
	public function materialize(int $assignmentId, int $dueAt, int $now, ?array $ids = null): int {
		$qb = $this->db->getQueryBuilder();
		$expr = $qb->expr();
		$open = [$expr->andX($expr->eq('status', $qb->createNamedParameter('reopened')), $expr->isNotNull('due_override_at'),
			$expr->lt('due_override_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)))];
		if ($dueAt < $now) {
			$open[] = $expr->eq('status', $qb->createNamedParameter('assigned'));
		}
		$qb->update(self::TABLE)
			->set('status', $qb->createNamedParameter('missed'))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($expr->eq('assignment_id', $qb->createNamedParameter($assignmentId, IQueryBuilder::PARAM_INT)))
			->andWhere($expr->orX(...$open));
		if ($ids !== null) {
			if ($ids === []) {
				return 0;
			}
			$qb->andWhere($expr->in('id', $qb->createNamedParameter(array_values($ids), IQueryBuilder::PARAM_INT_ARRAY)));
		}
		return $qb->executeStatement();
	}

	// Schließen (§9.2): offene Abgaben (assigned, reopened) → missed
	public function closeOpen(int $assignmentId, int $now): void {
		$qb = $this->db->getQueryBuilder();
		$expr = $qb->expr();
		$qb->update(self::TABLE)
			->set('status', $qb->createNamedParameter('missed'))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($expr->eq('assignment_id', $qb->createNamedParameter($assignmentId, IQueryBuilder::PARAM_INT)))
			->andWhere($expr->in('status', $qb->createNamedParameter(['assigned', 'reopened'], IQueryBuilder::PARAM_STR_ARRAY)))
			->executeStatement();
	}

	/** @return array<string, int> student_uid → Abgabe-ID (ohne gelöschte Konten) */
	public function studentsOf(int $assignmentId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'student_uid')->from(self::TABLE)
			->where($qb->expr()->eq('assignment_id', $qb->createNamedParameter($assignmentId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNotNull('student_uid'));
		$out = [];
		$res = $qb->executeQuery();
		while ($row = $res->fetch()) {
			$out[(string)$row['student_uid']] = (int)$row['id'];
		}
		$res->closeCursor();
		return $out;
	}

	/** @return Submission[] Abgaben mit Austrittsmarke (reconcile Schritt a) */
	public function findLeft(int $assignmentId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('assignment_id', $qb->createNamedParameter($assignmentId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNotNull('left_team_at'))
			->andWhere($qb->expr()->isNotNull('student_uid'));
		return $this->findEntities($qb);
	}

	public function clearLeft(int $id, int $now): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('left_team_at', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNotNull('left_team_at'))
			->executeStatement();
	}

	/**
	 * Austrittsmarken aktueller Mitglieder löschen (Team-Löschung, M7h-fix): wer beim Verwaisen Mitglied ist, behält den Zugriff
	 * @param int[] $assignmentIds
	 * @param string[] $uids
	 */
	public function clearLeftFor(array $assignmentIds, array $uids, int $now): int {
		$n = 0;
		$uids = array_values(array_unique($uids));
		foreach (array_chunk(array_values(array_unique($assignmentIds)), 500) as $aChunk) {
			foreach (array_chunk($uids, 500) as $uChunk) {
				$qb = $this->db->getQueryBuilder();
				$expr = $qb->expr();
				$n += $qb->update(self::TABLE)
					->set('left_team_at', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
					->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
					->where($expr->in('assignment_id', $qb->createNamedParameter($aChunk, IQueryBuilder::PARAM_INT_ARRAY)))
					->andWhere($expr->in('student_uid', $qb->createNamedParameter($uChunk, IQueryBuilder::PARAM_STR_ARRAY)))
					->andWhere($expr->isNotNull('left_team_at'))
					->executeStatement();
			}
		}
		return $n;
	}

	/** @return int[] Notiz-IDs der Kopien eines Schülers */
	public function noteIdsOf(string $uid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('note_id')->from(self::TABLE)
			->where($qb->expr()->eq('student_uid', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->isNotNull('note_id'));
		return array_map('intval', $qb->executeQuery()->fetchFirstColumn());
	}

	// Kontolöschung (SPEC §8): Abgaben bleiben, UID wird gelöst (eine neu vergebene UID sieht sie nicht)
	public function detachStudent(string $uid, ?string $name, int $now): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('student_uid_former', $qb->createNamedParameter($uid))
			->set('student_uid', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('student_deleted_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('student_uid', $qb->createNamedParameter($uid)));
		if ($name !== null && $name !== '') {
			$qb->set('student_name', $qb->createNamedParameter(mb_substr($name, 0, 255)));
		}
		$qb->executeStatement();
	}

	public function clearFeedbackBy(string $uid): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('feedback_by', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($qb->expr()->eq('feedback_by', $qb->createNamedParameter($uid)))
			->executeStatement();
	}

	/** @return int[] Aufgaben mit offener Abgabe nach Frist (assigned nach Aufgabenfrist, reopened nach eigener Frist) */
	public function assignmentIdsOverdue(int $now): array {
		$qb = $this->db->getQueryBuilder();
		$expr = $qb->expr();
		$qb->selectDistinct('s.assignment_id')->from(self::TABLE, 's')
			->innerJoin('s', AssignmentMapper::TABLE, 'a', $expr->eq('a.id', 's.assignment_id'))
			->where($expr->orX(
				$expr->andX($expr->eq('s.status', $qb->createNamedParameter('assigned')), $expr->lt('a.due_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))),
				$expr->andX($expr->eq('s.status', $qb->createNamedParameter('reopened')), $expr->isNotNull('s.due_override_at'),
					$expr->lt('s.due_override_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))),
			));
		return array_map('intval', $qb->executeQuery()->fetchFirstColumn());
	}

	/** @return int[] Aufgaben aktiver Teams mit Austrittsmarke (reconcile Schritt a im Job; verwaiste Teams ohne Circles-Abfragen) */
	public function assignmentIdsWithLeft(): array {
		$qb = $this->db->getQueryBuilder();
		$expr = $qb->expr();
		$qb->selectDistinct('s.assignment_id')->from(self::TABLE, 's')
			->innerJoin('s', AssignmentMapper::TABLE, 'a', $expr->eq('a.id', 's.assignment_id'))
			->leftJoin('a', TeamMapper::TABLE, 't', $expr->eq('t.team_id', 'a.team_id'))
			->where($expr->isNotNull('s.left_team_at'))
			->andWhere($expr->isNotNull('s.student_uid'))
			->andWhere($expr->orX($expr->isNull('t.state'), $expr->eq('t.state', $qb->createNamedParameter('active'))));
		return array_map('intval', $qb->executeQuery()->fetchFirstColumn());
	}

	/**
	 * Austritt (ADR 0008 e): Austrittsmarke für alle Abgaben des Schülers in diesen Aufgaben, nie löschen; nur noch nicht markierte
	 * @param int[] $assignmentIds
	 */
	public function markLeft(array $assignmentIds, string $uid, int $now): int {
		$n = 0;
		foreach (array_chunk(array_values(array_unique($assignmentIds)), 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$expr = $qb->expr();
			$n += $qb->update(self::TABLE)
				->set('left_team_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
				->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
				->where($expr->in('assignment_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->andWhere($expr->eq('student_uid', $qb->createNamedParameter($uid)))
				->andWhere($expr->isNull('left_team_at'))
				->executeStatement();
		}
		return $n;
	}

	/** @return array<string, int> Status → Anzahl einer Aufgabe (Sammelmeldung) */
	public function statusCounts(int $assignmentId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('status')->selectAlias($qb->func()->count('id'), 'n')->from(self::TABLE)
			->where($qb->expr()->eq('assignment_id', $qb->createNamedParameter($assignmentId, IQueryBuilder::PARAM_INT)))
			->groupBy('status');
		$out = [];
		$res = $qb->executeQuery();
		while ($row = $res->fetch()) {
			$out[(string)$row['status']] = (int)$row['n'];
		}
		$res->closeCursor();
		return $out;
	}
}
