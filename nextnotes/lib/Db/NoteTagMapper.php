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

/** @extends QBMapper<NoteTag> */
class NoteTagMapper extends QBMapper {
	public const TABLE = 'nextnotes_note_tags';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE, NoteTag::class);
	}

	/**
	 * Tags je Notiz in Einfügereihenfolge
	 * @param int[] $noteIds
	 * @return array<int, string[]>
	 */
	public function tagsFor(array $noteIds): array {
		$out = [];
		foreach (array_chunk(array_values(array_unique($noteIds)), 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('note_id', 'tag')->from(self::TABLE)
				->where($qb->expr()->in('note_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->orderBy('id', 'ASC');
			$res = $qb->executeQuery();
			while ($row = $res->fetch()) {
				$out[(int)$row['note_id']][] = (string)$row['tag'];
			}
			$res->closeCursor();
		}
		return $out;
	}

	/**
	 * Tags (eindeutig) bzw. Notiz-IDs mit Tag über Notizen der Workspaces, ohne Papierkorb
	 * @param int[] $workspaceIds
	 * @return string[]|int[]
	 */
	public function findInWorkspaces(array $workspaceIds, ?string $tag = null): array {
		$out = [];
		foreach (array_chunk(array_values(array_unique($workspaceIds)), 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->from(self::TABLE, 't')
				->innerJoin('t', NoteMapper::TABLE, 'n', $qb->expr()->eq('n.id', 't.note_id'))
				->where($qb->expr()->in('n.workspace_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->andWhere($qb->expr()->isNull('n.trashed_at'))
				->andWhere($qb->expr()->eq('n.role', $qb->createNamedParameter('note')));
			if ($tag === null) {
				$qb->selectDistinct('t.tag');
			} else {
				$qb->selectDistinct('t.note_id')->andWhere($qb->expr()->eq('t.tag', $qb->createNamedParameter($tag)));
			}
			$res = $qb->executeQuery();
			while (($v = $res->fetchOne()) !== false) {
				$out[] = $tag === null ? (string)$v : (int)$v;
			}
			$res->closeCursor();
		}
		return array_values(array_unique($out));
	}

	/**
	 * Notizen je Tag in einem Workspace, ohne Papierkorb (Tag-Seite)
	 * @return array<string, int>
	 */
	public function countsInWorkspace(int $workspaceId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('t.tag')->selectAlias($qb->func()->count('t.note_id'), 'cnt')
			->from(self::TABLE, 't')
			->innerJoin('t', NoteMapper::TABLE, 'n', $qb->expr()->eq('n.id', 't.note_id'))
			->where($qb->expr()->eq('n.workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('n.trashed_at'))
			->andWhere($qb->expr()->eq('n.role', $qb->createNamedParameter('note')))
			->groupBy('t.tag');
		$out = [];
		$res = $qb->executeQuery();
		while ($row = $res->fetch()) {
			$out[(string)$row['tag']] = (int)$row['cnt'];
		}
		$res->closeCursor();
		return $out;
	}

	public function add(int $noteId, string $tag): void {
		$this->db->insertIgnoreConflict(self::TABLE, ['note_id' => $noteId, 'tag' => $tag]);
	}

	public function remove(int $noteId, string $tag): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->eq('note_id', $qb->createNamedParameter($noteId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('tag', $qb->createNamedParameter($tag)))
			->executeStatement();
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
