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

/** @extends QBMapper<Snapshot> */
class SnapshotMapper extends QBMapper {
	public const TABLE = 'nextnotes_snapshots';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE, Snapshot::class);
	}

	/** @return Snapshot[] neueste zuerst */
	public function findForNote(int $noteId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('note_id', $qb->createNamedParameter($noteId, IQueryBuilder::PARAM_INT)))
			->orderBy('created_at', 'DESC')
			->addOrderBy('id', 'DESC');
		return $this->findEntities($qb);
	}

	public function newest(int $noteId): ?Snapshot {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('note_id', $qb->createNamedParameter($noteId, IQueryBuilder::PARAM_INT)))
			->orderBy('created_at', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults(1);
		$list = $this->findEntities($qb);
		return $list[0] ?? null;
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

	/** @param int[] $noteIds */
	public function deleteForNotes(array $noteIds): void {
		foreach (array_chunk(array_values(array_unique($noteIds)), 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete(self::TABLE)
				->where($qb->expr()->in('note_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->executeStatement();
		}
	}

	// Alle Snapshots älter als $cutoff (Höchstalter)
	public function deleteOlderThan(int $cutoff): int {
		$qb = $this->db->getQueryBuilder();
		return $qb->delete(self::TABLE)
			->where($qb->expr()->lt('created_at', $qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/** @return int[] Snapshot-IDs, deren Notiz nicht mehr existiert */
	public function orphanIds(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('s.id')->from(self::TABLE, 's')
			->leftJoin('s', NoteMapper::TABLE, 'n', $qb->expr()->eq('n.id', 's.note_id'))
			->where($qb->expr()->isNull('n.id'));
		$res = $qb->executeQuery();
		$ids = [];
		while (($v = $res->fetchOne()) !== false) {
			$ids[] = (int)$v;
		}
		$res->closeCursor();
		return $ids;
	}

	/**
	 * Besitzer-UID der Workspaces der Notizen mit automatischen Snapshots vor $cutoff (Team-Workspaces: null)
	 * @return array<int, ?string> note_id → UID
	 */
	public function autoNoteOwnersBefore(int $cutoff): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct(['s.note_id', 'w.owner_type', 'w.owner_id'])->from(self::TABLE, 's')
			->innerJoin('s', NoteMapper::TABLE, 'n', $qb->expr()->eq('n.id', 's.note_id'))
			->innerJoin('n', WorkspaceMapper::TABLE, 'w', $qb->expr()->eq('w.id', 'n.workspace_id'))
			->where($qb->expr()->eq('s.kind', $qb->createNamedParameter('auto')))
			->andWhere($qb->expr()->lt('s.created_at', $qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_INT)));
		$res = $qb->executeQuery();
		$out = [];
		while (($row = $res->fetch()) !== false) {
			$out[(int)$row['note_id']] = $row['owner_type'] === 'user' ? (string)$row['owner_id'] : null;
		}
		$res->closeCursor();
		return $out;
	}
}
