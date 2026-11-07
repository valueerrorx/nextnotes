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

/** @extends QBMapper<WsShare> */
class WsShareMapper extends QBMapper {
	public const TABLE = 'nextnotes_ws_shares';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE, WsShare::class);
	}

	public function findUserShare(int $workspaceId, string $uid): ?WsShare {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('share_type', $qb->createNamedParameter('user')))
			->andWhere($qb->expr()->eq('share_with', $qb->createNamedParameter($uid)));
		$all = $this->findEntities($qb);
		return $all[0] ?? null;
	}

	// Idempotent dank Unique-Index nn_wss_uniq
	public function insertIgnore(int $workspaceId, string $uid, string $permission, string $createdBy, int $now): void {
		$this->db->insertIgnoreConflict(self::TABLE, [
			'workspace_id' => $workspaceId,
			'share_type' => 'user',
			'share_with' => $uid,
			'permission' => $permission,
			'created_by' => $createdBy,
			'created_at' => $now,
		]);
	}

	// Rechtewechsel eines bestehenden Empfängers (Plan M5 §2.7: Update statt insertIgnore)
	public function updatePermission(int $workspaceId, string $uid, string $permission): int {
		$qb = $this->db->getQueryBuilder();
		return $qb->update(self::TABLE)
			->set('permission', $qb->createNamedParameter($permission))
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('share_type', $qb->createNamedParameter('user')))
			->andWhere($qb->expr()->eq('share_with', $qb->createNamedParameter($uid)))
			->executeStatement();
	}

	/**
	 * Benutzerfreigaben mehrerer Workspaces (Einstellungen, Freigabe-Dialog)
	 * @param int[] $workspaceIds
	 * @return array<int, array<string, string>> workspace_id → [uid → permission], Reihenfolge der Anlage
	 */
	public function permissionsFor(array $workspaceIds): array {
		$out = [];
		foreach (array_chunk(array_values(array_unique($workspaceIds)), 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('workspace_id', 'share_with', 'permission')->from(self::TABLE)
				->where($qb->expr()->in('workspace_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->andWhere($qb->expr()->eq('share_type', $qb->createNamedParameter('user')))
				->orderBy('id', 'ASC');
			$res = $qb->executeQuery();
			while ($row = $res->fetch()) {
				$out[(int)$row['workspace_id']][(string)$row['share_with']] = (string)$row['permission'];
			}
			$res->closeCursor();
		}
		return $out;
	}

	public function deleteUserShare(int $workspaceId, string $uid): int {
		$qb = $this->db->getQueryBuilder();
		return $qb->delete(self::TABLE)
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('share_type', $qb->createNamedParameter('user')))
			->andWhere($qb->expr()->eq('share_with', $qb->createNamedParameter($uid)))
			->executeStatement();
	}

	/** @return string[] UIDs der Benutzerfreigaben eines Workspace */
	public function recipients(int $workspaceId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('share_with')->from(self::TABLE)
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('share_type', $qb->createNamedParameter('user')))
			->orderBy('id', 'ASC');
		return array_map('strval', $qb->executeQuery()->fetchFirstColumn());
	}

	public function deleteForWorkspace(int $workspaceId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/**
	 * Workspaces mit mindestens einer Freigabe
	 * @param int[] $workspaceIds
	 * @return int[]
	 */
	public function sharedWorkspaceIds(array $workspaceIds): array {
		$out = [];
		foreach (array_chunk(array_values(array_unique($workspaceIds)), 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->selectDistinct('workspace_id')->from(self::TABLE)
				->where($qb->expr()->in('workspace_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$res = $qb->executeQuery();
			while (($id = $res->fetchOne()) !== false) {
				$out[] = (int)$id;
			}
			$res->closeCursor();
		}
		return $out;
	}

	/** @return int[] Workspaces, die an $uid freigegeben sind */
	public function workspaceIdsSharedWith(string $uid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('workspace_id')->from(self::TABLE)
			->where($qb->expr()->eq('share_type', $qb->createNamedParameter('user')))
			->andWhere($qb->expr()->eq('share_with', $qb->createNamedParameter($uid)));
		return array_map('intval', $qb->executeQuery()->fetchFirstColumn());
	}
}
