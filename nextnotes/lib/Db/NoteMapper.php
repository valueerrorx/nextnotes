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

/** @extends QBMapper<Note> */
class NoteMapper extends QBMapper {
	public const TABLE = 'nextnotes_notes';
	// Spalten für Listen (ohne Inhalt)
	public const LIST_COLUMNS = ['id', 'workspace_id', 'folder_id', 'heading', 'type', 'role', 'linked_note_id',
		'favorite', 'pinned', 'icon', 'icon_color', 'color', 'title_color', 'title_size', 'content_width',
		'display_order', 'dashboard_order', 'speed_dial_order', 'kanban_order', 'kanban_completed', 'trashed_at',
		'created_by', 'updated_by', 'created_at', 'updated_at'];

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE, Note::class);
	}

	/** @throws \OCP\AppFramework\Db\DoesNotExistException */
	public function find(int $id): Note {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * Notizen (role=note) in den Workspaces, ohne Inhalt.
	 * Filter: folder_id (int|null für Wurzel), favorite, trashed (bool), search (normalisiert), created_from/created_to (Unix), linked_note_id, folder_ids (int[]), types (string[]), content_any (Teilzeichenketten, ODER)
	 * @param int[] $workspaceIds
	 * @param array<string, mixed> $filter
	 * @return Note[]
	 */
	public function findList(array $workspaceIds, array $filter = [], bool $withContent = false): array {
		if ($workspaceIds === []) {
			return [];
		}
		$result = [];
		foreach (array_chunk(array_values(array_unique($workspaceIds)), 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select(...($withContent ? ['*'] : self::LIST_COLUMNS))->from(self::TABLE)
				->where($qb->expr()->in('workspace_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->andWhere($qb->expr()->eq('role', $qb->createNamedParameter('note')))
				->orderBy('id', 'ASC');
			$this->applyFilter($qb, $filter);
			array_push($result, ...$this->findEntities($qb));
		}
		return $result;
	}

	/** @param array<string, mixed> $filter */
	private function applyFilter(IQueryBuilder $qb, array $filter): void {
		$expr = $qb->expr();
		$trashed = $filter['trashed'] ?? false;
		if ($trashed !== null) {
			$qb->andWhere($trashed ? $expr->isNotNull('trashed_at') : $expr->isNull('trashed_at'));
		}
		if (array_key_exists('folder_id', $filter)) {
			$qb->andWhere($filter['folder_id'] === null
				? $expr->isNull('folder_id')
				: $expr->eq('folder_id', $qb->createNamedParameter((int)$filter['folder_id'], IQueryBuilder::PARAM_INT)));
		}
		if (isset($filter['folder_ids'])) {
			$ids = array_values(array_unique(array_map('intval', $filter['folder_ids'])));
			$qb->andWhere($ids === [] ? $expr->eq($qb->createNamedParameter(1), $qb->createNamedParameter(0)) : $expr->in('folder_id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
		}
		if (isset($filter['favorite'])) {
			$qb->andWhere($expr->eq('favorite', $qb->createNamedParameter((int)$filter['favorite'], IQueryBuilder::PARAM_INT)));
		}
		if (isset($filter['linked_note_id'])) {
			$qb->andWhere($expr->eq('linked_note_id', $qb->createNamedParameter((int)$filter['linked_note_id'], IQueryBuilder::PARAM_INT)));
		}
		if (isset($filter['heading'])) {
			$qb->andWhere($expr->eq('heading', $qb->createNamedParameter((string)$filter['heading'])));
		}
		if (isset($filter['search']) && $filter['search'] !== '') {
			$qb->andWhere($expr->like('search_text', $qb->createNamedParameter('%' . $this->db->escapeLikeParameter((string)$filter['search']) . '%')));
		}
		if (isset($filter['created_from'])) {
			$qb->andWhere($expr->gte('created_at', $qb->createNamedParameter((int)$filter['created_from'], IQueryBuilder::PARAM_INT)));
		}
		if (isset($filter['created_to'])) {
			$qb->andWhere($expr->lte('created_at', $qb->createNamedParameter((int)$filter['created_to'], IQueryBuilder::PARAM_INT)));
		}
		if (isset($filter['types'])) {
			$qb->andWhere($expr->in('type', $qb->createNamedParameter(array_values($filter['types']), IQueryBuilder::PARAM_STR_ARRAY)));
		}
		if (isset($filter['content_any'])) {
			$or = [];
			foreach ($filter['content_any'] as $needle) {
				$or[] = $expr->like('content', $qb->createNamedParameter('%' . $this->db->escapeLikeParameter((string)$needle) . '%'));
			}
			$qb->andWhere($or === [] ? $expr->eq($qb->createNamedParameter(1), $qb->createNamedParameter(0)) : $expr->orX(...$or));
		}
		if (isset($filter['exclude_id'])) {
			$qb->andWhere($expr->neq('id', $qb->createNamedParameter((int)$filter['exclude_id'], IQueryBuilder::PARAM_INT)));
		}
	}

	// Gleicher Titel im selben Ordner (nicht im Papierkorb)
	public function headingExists(int $workspaceId, ?int $folderId, string $heading, ?int $excludeId = null): bool {
		$filter = ['folder_id' => $folderId, 'heading' => $heading];
		if ($excludeId !== null) {
			$filter['exclude_id'] = $excludeId;
		}
		return $this->findList([$workspaceId], $filter) !== [];
	}

	/**
	 * Notizen per ID (jede Rolle, mit Inhalt)
	 * @param int[] $ids
	 * @return Note[]
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

	/**
	 * Notizen in Ordnern auf anderen Workspace umhängen
	 * @param int[] $folderIds
	 */
	public function moveFolderNotesToWorkspace(array $folderIds, int $workspaceId): void {
		foreach (array_chunk(array_values(array_unique($folderIds)), 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->update(self::TABLE)
				->set('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT))
				->where($qb->expr()->in('folder_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->executeStatement();
		}
	}

	// Alle Notizen eines Workspace umhängen (Workspace löschen)
	public function moveWorkspaceNotes(int $fromWorkspaceId, int $toWorkspaceId): int {
		$qb = $this->db->getQueryBuilder();
		return $qb->update(self::TABLE)
			->set('workspace_id', $qb->createNamedParameter($toWorkspaceId, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($fromWorkspaceId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/**
	 * updated_at/updated_by setzen (ändert Version/ETag)
	 * @param int[] $ids
	 */
	public function touch(array $ids, string $uid, int $now): void {
		foreach (array_chunk(array_values(array_unique($ids)), 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->update(self::TABLE)
				->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
				->set('updated_by', $qb->createNamedParameter($uid))
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
			->andWhere($qb->expr()->eq('role', $qb->createNamedParameter('note')))
			->executeStatement();
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
}
