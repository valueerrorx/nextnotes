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

/** @extends QBMapper<Assignment> */
class AssignmentMapper extends QBMapper {
	public const TABLE = 'nextnotes_assignments';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE, Assignment::class);
	}

	/** @throws \OCP\AppFramework\Db\DoesNotExistException */
	public function find(int $id): Assignment {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * @param int[] $ids
	 * @return Assignment[] nach Frist, dann ID
	 */
	public function findByIds(array $ids): array {
		$result = [];
		foreach (array_chunk(array_values(array_unique($ids)), 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')->from(self::TABLE)
				->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			array_push($result, ...$this->findEntities($qb));
		}
		usort($result, static fn (Assignment $a, Assignment $b) => [(int)$a->getDueAt(), (int)$a->getId()] <=> [(int)$b->getDueAt(), (int)$b->getId()]);
		return $result;
	}

	// Zeilensperre bis Transaktionsende (Update ohne Wertänderung); Rückgabe: 0 = Aufgabe gelöscht
	public function lockRow(int $id): int {
		$qb = $this->db->getQueryBuilder();
		return $qb->update(self::TABLE)->set('id', 'id')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/**
	 * §9.6 (a): Aufgaben der Teams mit Zustand active (Zeile fehlt oder state=active); Level prüft der Aufrufer
	 * @param string[] $teamIds
	 * @return array<int, string> id → team_id
	 */
	public function idsInActiveTeams(array $teamIds): array {
		$out = [];
		foreach (array_chunk(array_values(array_unique($teamIds)), 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$expr = $qb->expr();
			$qb->select('a.id', 'a.team_id')->from(self::TABLE, 'a')
				->leftJoin('a', TeamMapper::TABLE, 't', $expr->eq('t.team_id', 'a.team_id'))
				->where($expr->in('a.team_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)))
				->andWhere($expr->orX($expr->isNull('t.state'), $expr->eq('t.state', $qb->createNamedParameter('active'))));
			$res = $qb->executeQuery();
			while ($row = $res->fetch()) {
				$out[(int)$row['id']] = (string)$row['team_id'];
			}
			$res->closeCursor();
		}
		return $out;
	}

	/**
	 * §9.6 (b): owner_uid = uid in verwaisten Teams; $frozen (Teams-App aus): in allen Teams
	 * @return int[]
	 */
	public function idsOwnedInactive(string $uid, bool $frozen): array {
		$qb = $this->db->getQueryBuilder();
		$expr = $qb->expr();
		$qb->select('a.id')->from(self::TABLE, 'a')
			->where($expr->eq('a.owner_uid', $qb->createNamedParameter($uid)));
		if (!$frozen) {
			$qb->innerJoin('a', TeamMapper::TABLE, 't', $expr->eq('t.team_id', 'a.team_id'))
				->andWhere($expr->eq('t.state', $qb->createNamedParameter('orphaned')));
		}
		return array_map('intval', $qb->executeQuery()->fetchFirstColumn());
	}

	public function deleteById(int $id): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/**
	 * Ausgeteilte Aufgaben mit offener Frist in diesen Teams (Nachzügler beim Zugriff des Schülers, §9.4)
	 * @param string[] $teamIds
	 * @return Assignment[]
	 */
	public function findOpenDistributed(array $teamIds, int $now): array {
		$out = [];
		foreach (array_chunk(array_values(array_unique($teamIds)), 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$expr = $qb->expr();
			$qb->select('*')->from(self::TABLE)
				->where($expr->in('team_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)))
				->andWhere($expr->eq('status', $qb->createNamedParameter('distributed')))
				->andWhere($expr->gte('due_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)));
			array_push($out, ...$this->findEntities($qb));
		}
		return $out;
	}
}
