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

/** @extends QBMapper<MentionHandled> */
class MentionHandledMapper extends QBMapper {
	public const TABLE = 'nextnotes_mentions_handled';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE, MentionHandled::class);
	}

	/** @return array<int, int> note_id → handled_count */
	public function countsFor(string $uid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('note_id', 'handled_count')->from(self::TABLE)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)));
		$res = $qb->executeQuery();
		$out = [];
		while ($row = $res->fetch()) {
			$out[(int)$row['note_id']] = (int)$row['handled_count'];
		}
		$res->closeCursor();
		return $out;
	}

	// Anlegen oder Zähler setzen (Unique nn_men_uniq)
	public function store(int $noteId, string $uid, int $count, int $now): void {
		$this->db->insertIgnoreConflict(self::TABLE, [
			'note_id' => $noteId,
			'user_id' => $uid,
			'handled_count' => $count,
			'updated_at' => $now,
		]);
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('handled_count', $qb->createNamedParameter($count, IQueryBuilder::PARAM_INT))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('note_id', $qb->createNamedParameter($noteId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
			->executeStatement();
	}

	public function remove(int $noteId, string $uid): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->eq('note_id', $qb->createNamedParameter($noteId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
			->executeStatement();
	}

	/** @param int[] $noteIds */
	public function deleteForNotes(array $noteIds, ?string $uid = null): void {
		foreach (array_chunk(array_values(array_unique($noteIds)), 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete(self::TABLE)
				->where($qb->expr()->in('note_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			if ($uid !== null) {
				$qb->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)));
			}
			$qb->executeStatement();
		}
	}

	/**
	 * Alle außer $keepUid (Umwandeln in privat, M5b)
	 * @param int[] $noteIds
	 */
	public function deleteForNotesExcept(array $noteIds, string $keepUid): void {
		foreach (array_chunk(array_values(array_unique($noteIds)), 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete(self::TABLE)
				->where($qb->expr()->in('note_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->andWhere($qb->expr()->neq('user_id', $qb->createNamedParameter($keepUid)))
				->executeStatement();
		}
	}
}
