<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Db;

use OCP\AppFramework\Db\Entity;
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

	// Inhalt und search_text in getrennten Statements: sonst beide in einem Paket (MySQL max_allowed_packet, B37)
	public function insert(Entity $entity): Entity {
		$split = $entity instanceof Note && $entity->takeSearchTextUpdate();
		$entity = parent::insert($entity);
		return $split ? $this->writeSearchText($entity) : $entity;
	}

	public function update(Entity $entity): Entity {
		$split = $entity instanceof Note && $entity->takeSearchTextUpdate();
		$entity = parent::update($entity);
		return $split ? $this->writeSearchText($entity) : $entity;
	}

	private function writeSearchText(Note $note): Note {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('search_text', $qb->createNamedParameter($note->getSearchText()))
			->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$note->getId(), IQueryBuilder::PARAM_INT)))
			->executeStatement();
		return $note;
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
	 * Notizen per ID ohne Inhalt (jede Rolle; Export: Ziele verknüpfter Notizen)
	 * @param int[] $ids
	 * @return Note[]
	 */
	public function findListByIds(array $ids): array {
		$result = [];
		foreach (array_chunk(array_values(array_unique($ids)), 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select(...self::LIST_COLUMNS)->from(self::TABLE)
				->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			array_push($result, ...$this->findEntities($qb));
		}
		return $result;
	}

	/**
	 * Inhaltsgröße in Byte je Notiz (Export-Grenze vor dem Packen, Plan M4 §1.3)
	 * @param int[] $ids
	 * @return array<int, int>
	 */
	public function contentBytes(array $ids): array {
		$out = [];
		foreach (array_chunk(array_values(array_unique($ids)), 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id')->selectAlias($qb->func()->octetLength('content'), 'bytes')->from(self::TABLE)
				->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$res = $qb->executeQuery();
			while ($row = $res->fetch()) {
				$out[(int)$row['id']] = (int)$row['bytes'];
			}
			$res->closeCursor();
		}
		return $out;
	}

	/**
	 * Inhalt je Notiz (Export lädt stückweise)
	 * @param int[] $ids
	 * @return array<int, string>
	 */
	public function contentByIds(array $ids): array {
		$out = [];
		foreach (array_chunk(array_values(array_unique($ids)), 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id', 'content')->from(self::TABLE)
				->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$res = $qb->executeQuery();
			while ($row = $res->fetch()) {
				$c = $row['content'];
				$out[(int)$row['id']] = is_resource($c) ? (string)stream_get_contents($c) : (string)$c;
			}
			$res->closeCursor();
		}
		return $out;
	}

	/**
	 * Ziele von [[…]] in einem Workspace (B47): per ID oder Kandidaten per Titel (search_text beginnt mit dem gefalteten Titel), ohne Papierkorb
	 * @param int[] $ids
	 * @param string[] $foldedTitles
	 * @return Note[]
	 */
	public function findReferenced(int $workspaceId, array $ids, array $foldedTitles): array {
		// je Abfrage ≤ 1000 IDs und ≤ 400 Titel (Parametergrenzen SQLite/PostgreSQL); übliche Notizen: eine Abfrage
		$idChunks = array_chunk(array_values(array_unique($ids)), 1000);
		$titleChunks = array_chunk(array_values(array_unique($foldedTitles)), 400);
		$result = [];
		for ($k = 0, $n = max(count($idChunks), count($titleChunks)); $k < $n; $k++) {
			$qb = $this->db->getQueryBuilder();
			$expr = $qb->expr();
			$or = [];
			if (isset($idChunks[$k])) {
				$or[] = $expr->in('id', $qb->createNamedParameter($idChunks[$k], IQueryBuilder::PARAM_INT_ARRAY));
			}
			foreach ($titleChunks[$k] ?? [] as $t) {
				$or[] = $expr->eq('search_text', $qb->createNamedParameter($t));
				$or[] = $expr->like('search_text', $qb->createNamedParameter($this->db->escapeLikeParameter($t) . ' %'));
			}
			$qb->select(...self::LIST_COLUMNS)->from(self::TABLE)
				->where($expr->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
				->andWhere($expr->eq('role', $qb->createNamedParameter('note')))
				->andWhere($expr->isNull('trashed_at'))
				->andWhere($expr->orX(...$or));
			foreach ($this->findEntities($qb) as $note) {
				$result[(int)$note->getId()] = $note;
			}
		}
		ksort($result);
		return array_values($result);
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

	// Zeilensperre bis zum Ende der Transaktion (Update ohne Wertänderung, DB-neutral)
	public function lockRow(int $id): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)->set('id', 'id')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/** @return int[] alle Notiz-IDs eines Workspace (jede Rolle, auch Papierkorb) */
	public function idsInWorkspace(int $workspaceId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from(self::TABLE)
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)));
		return array_map('intval', $qb->executeQuery()->fetchFirstColumn());
	}

	/**
	 * IDs nicht gelöschter Notizen, deren search_text jeden Begriff enthält (eine Abfrage, nur IDs)
	 * @param string[] $terms normalisiert
	 * @return int[]
	 */
	public function idsMatchingAll(int $workspaceId, array $terms): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from(self::TABLE)
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('role', $qb->createNamedParameter('note')))
			->andWhere($qb->expr()->isNull('trashed_at'));
		foreach ($terms as $term) {
			$qb->andWhere($qb->expr()->like('search_text', $qb->createNamedParameter('%' . $this->db->escapeLikeParameter((string)$term) . '%')));
		}
		return array_map('intval', $qb->executeQuery()->fetchFirstColumn());
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
