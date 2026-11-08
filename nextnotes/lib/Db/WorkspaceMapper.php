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

/** @extends QBMapper<Workspace> */
class WorkspaceMapper extends QBMapper {
	public const TABLE = 'nextnotes_workspaces';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE, Workspace::class);
	}

	/** @throws \OCP\AppFramework\Db\DoesNotExistException */
	public function find(int $id): Workspace {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * @param int[] $ids
	 * @return Workspace[]
	 */
	public function findByIds(array $ids): array {
		$result = [];
		foreach (array_chunk(array_values(array_unique($ids)), 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')->from(self::TABLE)
				->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			array_push($result, ...$this->findEntities($qb));
		}
		return $result;
	}

	// Leeres UPDATE auf alle Workspaces eines Besitzers: Zeilensperre bis Transaktionsende (SQLite: Schreibsperre), DB-neutral
	public function lockOwner(string $ownerType, string $ownerId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)->set('owner_id', 'owner_id')
			->where($qb->expr()->eq('owner_type', $qb->createNamedParameter($ownerType)))
			->andWhere($qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)))
			->executeStatement();
	}

	/**
	 * Zeilensperre bis Transaktionsende (leeres UPDATE, DB-neutral); Rückgabe: Zahl noch vorhandener Zeilen
	 * @param int[] $ids
	 */
	public function lockRows(array $ids): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)->set('id', 'id')
			->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
		return $qb->executeStatement();
	}

	// Eigene Workspaces (nur owner_type=user), optional gefiltert nach kind und Name
	/** @return Workspace[] */
	public function findOwned(string $uid, ?string $kind = null, ?string $name = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('owner_type', $qb->createNamedParameter('user')))
			->andWhere($qb->expr()->eq('owner_id', $qb->createNamedParameter($uid)))
			->orderBy('display_order', 'ASC')->addOrderBy('name', 'ASC')->addOrderBy('id', 'ASC');
		if ($kind !== null) {
			$qb->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter($kind)));
		}
		if ($name !== null) {
			$qb->andWhere($qb->expr()->eq('name', $qb->createNamedParameter($name)));
		}
		return $this->findEntities($qb);
	}

	/** @return Workspace[] eigene Workspaces für die Benutzer-Quote: ohne Schüler-Workspaces (kind=assignments zählt beim Team, Plan M6 §2.3a) */
	public function findQuotaOwned(string $uid): array {
		return array_values(array_filter($this->findOwned($uid), static fn (Workspace $w) => $w->getKind() !== 'assignments'));
	}

	// IDs der Workspaces mit Benutzerfreigabe an $uid, nur bei Besitzer owner_type=user
	/** @return array<int, string> workspace_id => permission */
	public function findSharedWith(string $uid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('s.workspace_id', 's.permission')
			->from(WsShareMapper::TABLE, 's')
			->innerJoin('s', self::TABLE, 'w', $qb->expr()->eq('w.id', 's.workspace_id'))
			->where($qb->expr()->eq('s.share_type', $qb->createNamedParameter('user')))
			->andWhere($qb->expr()->eq('s.share_with', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('w.owner_type', $qb->createNamedParameter('user')));
		$res = $qb->executeQuery();
		$out = [];
		while ($row = $res->fetch()) {
			$out[(int)$row['workspace_id']] = (string)$row['permission'];
		}
		$res->closeCursor();
		return $out;
	}

	// Workspaces eines Besitzers (user oder team), optional gefiltert nach kind und Name
	/** @return Workspace[] */
	public function findByOwner(string $ownerType, string $ownerId, ?string $kind = null, ?string $name = null): array {
		if ($ownerType === 'user') {
			return $this->findOwned($ownerId, $kind, $name);
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('owner_type', $qb->createNamedParameter($ownerType)))
			->andWhere($qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)))
			->orderBy('id', 'ASC');
		if ($kind !== null) {
			$qb->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter($kind)));
		}
		if ($name !== null) {
			$qb->andWhere($qb->expr()->eq('name', $qb->createNamedParameter($name)));
		}
		return $this->findEntities($qb);
	}

	/**
	 * Team-Workspaces mit Recht für $uid in einer Abfrage (SPEC §3): Mitglied eines aktiven Teams → write; created_by bei verwaistem Team → read.
	 * Ohne Teams-App ($available false) zählt nur created_by (eingefroren).
	 * @param string[] $teamIds Teams mit Mitgliedschaft (TeamService::memberTeams)
	 * @return array<int, string> workspace_id => write|read
	 */
	public function teamAccess(string $uid, array $teamIds, bool $available): array {
		$out = [];
		$chunks = $teamIds === [] ? [[]] : array_chunk(array_values(array_unique($teamIds)), 1000);
		foreach ($chunks as $chunk) {
			$members = array_flip($chunk);
			$qb = $this->db->getQueryBuilder();
			$expr = $qb->expr();
			$qb->select('w.id', 'w.owner_id', 'w.created_by', 't.state')->from(self::TABLE, 'w')
				->leftJoin('w', TeamMapper::TABLE, 't', $expr->eq('t.team_id', 'w.owner_id'))
				->where($expr->eq('w.owner_type', $qb->createNamedParameter('team')));
			$byCreator = $expr->eq('w.created_by', $qb->createNamedParameter($uid));
			if ($available && $chunk !== []) {
				$qb->andWhere($expr->orX($expr->in('w.owner_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)), $byCreator));
			} else {
				$qb->andWhere($byCreator);
			}
			$res = $qb->executeQuery();
			while ($row = $res->fetch()) {
				$id = (int)$row['id'];
				$orphaned = $row['state'] === 'orphaned';
				if (!$available || $orphaned) {
					if ($row['created_by'] === $uid) {
						$out[$id] = 'read';
					}
				} elseif (isset($members[(string)$row['owner_id']])) {
					$out[$id] = 'write';
				}
			}
			$res->closeCursor();
		}
		return $out;
	}

	/** @return Workspace[] Team-Workspaces eines Teams (ITeamResourceProvider) */
	public function findForTeam(string $teamId): array {
		return $this->findByOwner('team', $teamId, 'normal');
	}

	/**
	 * Team-Workspaces (kind=normal) dieser Teams oder mit created_by = $uid (Kontolöschung: Hintergrundbilder gezielt, Plan M5 §2.4)
	 * @param string[] $teamIds
	 * @return int[]
	 */
	public function teamWorkspaceIdsFor(array $teamIds, string $uid): array {
		$ids = [];
		foreach (array_chunk(array_values(array_unique($teamIds)), 1000) ?: [[]] as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$expr = $qb->expr();
			$byCreator = $expr->eq('created_by', $qb->createNamedParameter($uid));
			$qb->select('id')->from(self::TABLE)
				->where($expr->eq('owner_type', $qb->createNamedParameter('team')))
				->andWhere($expr->eq('kind', $qb->createNamedParameter('normal')))
				->andWhere($chunk === [] ? $byCreator : $expr->orX($expr->in('owner_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)), $byCreator));
			array_push($ids, ...array_map('intval', $qb->executeQuery()->fetchFirstColumn()));
		}
		return array_values(array_unique($ids));
	}

	/**
	 * Team-Workspaces unter diesen IDs (Erwähnungen, M5d)
	 * @param int[] $ids
	 * @return int[]
	 */
	public function teamIdsAmong(array $ids): array {
		$out = [];
		foreach (array_chunk(array_values(array_unique($ids)), 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id')->from(self::TABLE)
				->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->andWhere($qb->expr()->eq('owner_type', $qb->createNamedParameter('team')));
			array_push($out, ...array_map('intval', $qb->executeQuery()->fetchFirstColumn()));
		}
		return $out;
	}

	/** @return Workspace[] Schüler-Workspaces (kind=assignments) eines Teams, jeder Besitzer-Typ (Team-Purge) */
	public function findAssignmentWorkspacesForTeam(string $teamId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('kind', $qb->createNamedParameter('assignments')))
			->andWhere($qb->expr()->eq('linked_team_id', $qb->createNamedParameter($teamId)))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	public function deleteById(int $id): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	// Schüler-Workspace je Team (kind=assignments, UNIQUE nn_ws_asg); idempotent, gleichzeitiges Austeilen legt ihn genau einmal an
	public function ensureAssignmentWorkspace(string $uid, string $teamId, string $name, int $now): Workspace {
		$this->db->insertIgnoreConflict(self::TABLE, [
			'owner_type' => 'user',
			'owner_id' => $uid,
			'kind' => 'assignments',
			'linked_team_id' => $teamId,
			'name' => mb_substr($name, 0, 255),
			'display_order' => 0,
			'created_by' => $uid,
			'created_at' => $now,
			'updated_at' => $now,
		]);
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('owner_type', $qb->createNamedParameter('user')))
			->andWhere($qb->expr()->eq('owner_id', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter('assignments')))
			->andWhere($qb->expr()->eq('linked_team_id', $qb->createNamedParameter($teamId)));
		return $this->findEntity($qb);
	}
}
