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

/** @extends QBMapper<Folder> */
class FolderMapper extends QBMapper {
	public const TABLE = 'nextnotes_folders';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE, Folder::class);
	}

	/** @throws \OCP\AppFramework\Db\DoesNotExistException */
	public function find(int $id): Folder {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * @param int[] $workspaceIds
	 * @return Folder[]
	 */
	public function findInWorkspaces(array $workspaceIds): array {
		$result = [];
		foreach (array_chunk(array_values(array_unique($workspaceIds)), 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')->from(self::TABLE)
				->where($qb->expr()->in('workspace_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->orderBy('id', 'ASC');
			array_push($result, ...$this->findEntities($qb));
		}
		return $result;
	}

	/**
	 * Ordner je Workspace (Einstellungen, Workspace-Liste)
	 * @param int[] $workspaceIds
	 * @return array<int, int> workspace_id => Anzahl
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

	/** @return Folder[] */
	public function findInWorkspace(int $workspaceId): array {
		return $this->findInWorkspaces([$workspaceId]);
	}

	// Ordner gleichen Namens auf derselben Ebene (Eindeutigkeit im Service, NULL-Parent)
	public function findSibling(int $workspaceId, ?int $parentId, string $name, ?int $excludeId = null): ?Folder {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('name', $qb->createNamedParameter($name)))
			->orderBy('id', 'ASC');
		if ($parentId === null) {
			$qb->andWhere($qb->expr()->isNull('parent_id'));
		} else {
			$qb->andWhere($qb->expr()->eq('parent_id', $qb->createNamedParameter($parentId, IQueryBuilder::PARAM_INT)));
		}
		if ($excludeId !== null) {
			$qb->andWhere($qb->expr()->neq('id', $qb->createNamedParameter($excludeId, IQueryBuilder::PARAM_INT)));
		}
		$all = $this->findEntities($qb);
		return $all[0] ?? null;
	}

	/** @param int[] $ids */
	public function deleteByIds(array $ids): void {
		foreach (array_chunk(array_values(array_unique($ids)), 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete(self::TABLE)
				->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->executeStatement();
		}
	}

	/** @param int[] $ids */
	public function moveToWorkspace(array $ids, int $workspaceId, int $now): void {
		foreach (array_chunk(array_values(array_unique($ids)), 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->update(self::TABLE)
				->set('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT))
				->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
				->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->executeStatement();
		}
	}

	public function clearFavorites(int $workspaceId): int {
		$qb = $this->db->getQueryBuilder();
		return $qb->update(self::TABLE)
			->set('favorite', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('favorite', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}
}
