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

	/**
	 * Anhangsgröße der Notizen, die einen Workspace verlassen (Auswahl wie NoteMapper::leaveRows; Quote bei Besitzerwechsel)
	 * @param int[]|null $folderIds null = ganzer Workspace
	 * @param int[]|null $noteIds
	 */
	public function sizeLeaving(int $workspaceId, ?array $folderIds = null, ?array $noteIds = null): int {
		$qb = $this->db->getQueryBuilder();
		$expr = $qb->expr();
		$qb->select($qb->func()->sum('a.size'))->from(self::TABLE, 'a')
			->innerJoin('a', NoteMapper::TABLE, 'n', $expr->eq('n.id', 'a.note_id'))
			->where($expr->eq('n.workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)));
		$limits = [];
		if ($folderIds !== null && $folderIds !== []) {
			$limits[] = $expr->in('n.folder_id', $qb->createNamedParameter(array_values(array_unique($folderIds)), IQueryBuilder::PARAM_INT_ARRAY));
		}
		if ($noteIds !== null && $noteIds !== []) {
			$limits[] = $expr->in('n.id', $qb->createNamedParameter(array_values(array_unique($noteIds)), IQueryBuilder::PARAM_INT_ARRAY));
		}
		if ($limits !== []) {
			$qb->andWhere($expr->orX(...$limits));
		} elseif ($folderIds !== null || $noteIds !== null) {
			return 0;
		}
		$res = $qb->executeQuery();
		$sum = (int)$res->fetchOne();
		$res->closeCursor();
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

	/** @return Attachment[] Anhänge einer Herkunft nach ID, älter als $createdBefore (CleanupJob, Index nn_att_origin) */
	public function findByOriginAfter(string $origin, int $afterId, int $createdBefore, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('origin', $qb->createNamedParameter($origin)))
			->andWhere($qb->expr()->gt('id', $qb->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lte('created_at', $qb->createNamedParameter($createdBefore, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}
}
