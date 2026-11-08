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

	/** @return array<int, array{total: int, submitted: int}> assignment_id → Zähler (Lehrkraft-Liste) */
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
				$out[$id] ??= ['total' => 0, 'submitted' => 0];
				$out[$id]['total'] += (int)$row['n'];
				if (in_array($row['status'], ['submitted', 'returned'], true)) {
					$out[$id]['submitted'] += (int)$row['n'];
				}
			}
			$res->closeCursor();
		}
		return $out;
	}
}
