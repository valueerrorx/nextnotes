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

	/**
	 * Frist abgelaufen, ausgeteilt, Sammelmeldung noch offen (AssignmentDeadlineJob)
	 * @return Assignment[]
	 */
	public function findDeadlinePassed(int $now, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$expr = $qb->expr();
		$qb->select('*')->from(self::TABLE)
			->where($expr->eq('status', $qb->createNamedParameter('distributed')))
			->andWhere($expr->lt('due_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)))
			->andWhere($expr->isNull('deadline_notified_at'))
			->orderBy('id', 'ASC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	// Bedingt: genau ein Lauf sendet die Sammelmeldung (true = dieser Aufruf hat markiert)
	public function markDeadlineNotified(int $id, int $now): bool {
		$qb = $this->db->getQueryBuilder();
		$expr = $qb->expr();
		$qb->update(self::TABLE)
			->set('deadline_notified_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($expr->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($expr->eq('status', $qb->createNamedParameter('distributed')))
			->andWhere($expr->isNull('deadline_notified_at'));
		return $qb->executeStatement() === 1;
	}

	/** @return int[] ausgeteilt mit offener Frist, alle Teams (Nachzügler im Job) */
	public function idsOpenDistributed(int $now): array {
		$qb = $this->db->getQueryBuilder();
		$expr = $qb->expr();
		$qb->select('id')->from(self::TABLE)
			->where($expr->eq('status', $qb->createNamedParameter('distributed')))
			->andWhere($expr->gte('due_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)));
		return array_map('intval', $qb->executeQuery()->fetchFirstColumn());
	}

	/** @return Assignment[] Aufgaben eines Teams nach ID (Team-Purge, Austritt) */
	public function findByTeam(string $teamId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/** @return int[] Aufgaben eines Workspace nach ID (Workspace löschen, ADR 0021) */
	public function idsForWorkspace(int $workspaceId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from(self::TABLE)
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');
		return array_map('intval', $qb->executeQuery()->fetchFirstColumn());
	}

	/**
	 * Zahl der Aufgaben je Workspace (Lösch-Dialog, ADR 0021)
	 * @param int[] $workspaceIds
	 * @return array<int, int>
	 */
	public function countByWorkspace(array $workspaceIds): array {
		$out = [];
		foreach (array_chunk(array_values(array_unique($workspaceIds)), 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('workspace_id')->selectAlias($qb->func()->count('id'), 'cnt')->from(self::TABLE)
				->where($qb->expr()->in('workspace_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->groupBy('workspace_id');
			$res = $qb->executeQuery();
			while ($row = $res->fetch()) {
				$out[(int)$row['workspace_id']] = (int)$row['cnt'];
			}
			$res->closeCursor();
		}
		return $out;
	}

	// Team-Workspace mit Ziel gelöscht: Aufgaben ziehen mit um (ADR 0021); Rückgabe: Zahl umgehängter Aufgaben
	public function rebindWorkspace(int $from, int $to): int {
		$qb = $this->db->getQueryBuilder();
		return $qb->update(self::TABLE)
			->set('workspace_id', $qb->createNamedParameter($to, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($from, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	public function existsForTeam(string $teamId): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from(self::TABLE)
			->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
			->setMaxResults(1);
		return $qb->executeQuery()->fetchOne() !== false;
	}

	/** @return Assignment[] owner_uid = uid, optional nur in einem Team; null = ohne Besitzer (occ --all-unowned) */
	public function findOwned(?string $uid, ?string $teamId = null): array {
		$qb = $this->db->getQueryBuilder();
		$expr = $qb->expr();
		$qb->select('*')->from(self::TABLE)
			->where($uid === null ? $expr->isNull('owner_uid') : $expr->eq('owner_uid', $qb->createNamedParameter($uid)))
			->orderBy('id', 'ASC');
		if ($teamId !== null) {
			$qb->andWhere($expr->eq('team_id', $qb->createNamedParameter($teamId)));
		}
		return $this->findEntities($qb);
	}

	// Übergang (§9.7): bedingt auf den gelesenen Besitzer; true = dieser Aufruf hat geändert
	public function setOwner(int $id, ?string $from, ?string $to, int $now): bool {
		$qb = $this->db->getQueryBuilder();
		$expr = $qb->expr();
		$qb->update(self::TABLE)
			->set('owner_uid', $to === null ? $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL) : $qb->createNamedParameter($to))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($expr->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($from === null ? $expr->isNull('owner_uid') : $expr->eq('owner_uid', $qb->createNamedParameter($from)));
		return $qb->executeStatement() === 1;
	}

	/**
	 * Besitzer je aktivem Team (Herabstufung im CleanupJob), nach Team und UID
	 * @return list<array{team: string, owner: string}>
	 */
	public function ownersInActiveTeams(): array {
		$qb = $this->db->getQueryBuilder();
		$expr = $qb->expr();
		$qb->selectDistinct(['a.team_id', 'a.owner_uid'])->from(self::TABLE, 'a')
			->leftJoin('a', TeamMapper::TABLE, 't', $expr->eq('t.team_id', 'a.team_id'))
			->where($expr->isNotNull('a.owner_uid'))
			->andWhere($expr->orX($expr->isNull('t.state'), $expr->eq('t.state', $qb->createNamedParameter('active'))))
			->orderBy('a.team_id', 'ASC')->addOrderBy('a.owner_uid', 'ASC');
		$out = [];
		$res = $qb->executeQuery();
		while ($row = $res->fetch()) {
			$out[] = ['team' => (string)$row['team_id'], 'owner' => (string)$row['owner_uid']];
		}
		$res->closeCursor();
		return $out;
	}

	// Team-Benachrichtigung: Aufgaben des Besitzers in diesem Team mit mindestens einer Abgabe
	public function countOwnedWithSubmissions(string $teamId, string $uid): int {
		$qb = $this->db->getQueryBuilder();
		$expr = $qb->expr();
		$qb->selectDistinct('a.id')->from(self::TABLE, 'a')
			->innerJoin('a', SubmissionMapper::TABLE, 's', $expr->eq('s.assignment_id', 'a.id'))
			->where($expr->eq('a.team_id', $qb->createNamedParameter($teamId)))
			->andWhere($expr->eq('a.owner_uid', $qb->createNamedParameter($uid)));
		return count($qb->executeQuery()->fetchFirstColumn());
	}
}
