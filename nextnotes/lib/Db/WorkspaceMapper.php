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

	// Eigene Workspaces (nur owner_type=user), optional gefiltert nach kind und Name
	/** @return Workspace[] */
	// Leeres UPDATE auf alle Workspaces eines Besitzers: Zeilensperre bis Transaktionsende (SQLite: Schreibsperre), DB-neutral
	public function lockOwner(string $ownerType, string $ownerId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)->set('owner_id', 'owner_id')
			->where($qb->expr()->eq('owner_type', $qb->createNamedParameter($ownerType)))
			->andWhere($qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)))
			->executeStatement();
	}

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

	public function deleteById(int $id): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}
}
