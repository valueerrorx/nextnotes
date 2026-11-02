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

/** @extends QBMapper<Attachment> */
class AttachmentMapper extends QBMapper {
	public const TABLE = 'nextnotes_attachments';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE, Attachment::class);
	}

	/** @throws \OCP\AppFramework\Db\DoesNotExistException */
	public function findOnNote(int $id, int $noteId): Attachment {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('note_id', $qb->createNamedParameter($noteId, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * @param int[] $noteIds
	 * @return array<int, Attachment[]> je Notiz in Upload-Reihenfolge
	 */
	public function findForNotes(array $noteIds): array {
		$out = [];
		foreach (array_chunk(array_values(array_unique($noteIds)), 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')->from(self::TABLE)
				->where($qb->expr()->in('note_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->orderBy('id', 'ASC');
			foreach ($this->findEntities($qb) as $a) {
				$out[(int)$a->getNoteId()][] = $a;
			}
		}
		return $out;
	}

	/**
	 * Notiz-IDs mit mindestens einem Anhang in den Workspaces (nicht im Papierkorb)
	 * @param int[] $workspaceIds
	 * @return int[]
	 */
	public function noteIdsInWorkspaces(array $workspaceIds): array {
		$out = [];
		foreach (array_chunk(array_values(array_unique($workspaceIds)), 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->selectDistinct('a.note_id')->from(self::TABLE, 'a')
				->innerJoin('a', NoteMapper::TABLE, 'n', $qb->expr()->eq('n.id', 'a.note_id'))
				->where($qb->expr()->in('n.workspace_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->andWhere($qb->expr()->isNull('n.trashed_at'))
				->andWhere($qb->expr()->eq('n.role', $qb->createNamedParameter('note')));
			$res = $qb->executeQuery();
			while (($v = $res->fetchOne()) !== false) {
				$out[] = (int)$v;
			}
			$res->closeCursor();
		}
		return $out;
	}

	/**
	 * Belegter Speicher aller Anhänge in den Workspaces (Quota)
	 * @param int[] $workspaceIds
	 */
	public function sizeInWorkspaces(array $workspaceIds): int {
		$sum = 0;
		foreach (array_chunk(array_values(array_unique($workspaceIds)), 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select($qb->func()->sum('a.size'))->from(self::TABLE, 'a')
				->innerJoin('a', NoteMapper::TABLE, 'n', $qb->expr()->eq('n.id', 'a.note_id'))
				->where($qb->expr()->in('n.workspace_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$res = $qb->executeQuery();
			$sum += (int)$res->fetchOne();
			$res->closeCursor();
		}
		return $sum;
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
}
