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
	// Spalten für Listen (ohne Inhalt; assignment_id/submission_id für filterReadable)
	public const LIST_COLUMNS = ['id', 'workspace_id', 'folder_id', 'heading', 'type', 'role', 'linked_note_id',
		'favorite', 'pinned', 'icon', 'icon_color', 'color', 'title_color', 'title_size', 'content_width',
		'display_order', 'dashboard_order', 'speed_dial_order', 'kanban_order', 'kanban_completed', 'trashed_at', 'trash_material',
		'assignment_id', 'submission_id', 'created_by', 'updated_by', 'created_at', 'updated_at'];

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
	 * Notizen (role=note, mit roles auch andere Rollen) in den Workspaces, ohne Inhalt.
	 * Filter: roles (string[]), folder_id (int|null für Wurzel), favorite, trashed (bool), search (normalisiert), created_from/created_to, updated_from/updated_to (Unix), linked_note_id, folder_ids (int[]), types (string[]), content_any (Teilzeichenketten, ODER)
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
				->orderBy('id', 'ASC');
			// roles: nur Suche mit eigenen Abgaben (Aufrufer filtert mit filterReadable), sonst role=note
			$roles = $filter['roles'] ?? ['note'];
			$qb->andWhere(count($roles) === 1
				? $qb->expr()->eq('role', $qb->createNamedParameter((string)$roles[0]))
				: $qb->expr()->in('role', $qb->createNamedParameter(array_values($roles), IQueryBuilder::PARAM_STR_ARRAY)));
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
		if (isset($filter['updated_from'])) {
			$qb->andWhere($expr->gte('updated_at', $qb->createNamedParameter((int)$filter['updated_from'], IQueryBuilder::PARAM_INT)));
		}
		if (isset($filter['updated_to'])) {
			$qb->andWhere($expr->lte('updated_at', $qb->createNamedParameter((int)$filter['updated_to'], IQueryBuilder::PARAM_INT)));
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

	/**
	 * Erstellt/geändert je Notiz ohne Papierkorb (Mini-Kalender, Tage gruppiert in PHP nach Zeitzone des Benutzers)
	 * @param int[] $workspaceIds
	 * @return list<array{0: int, 1: int}>
	 */
	public function calendarStamps(array $workspaceIds): array {
		$out = [];
		foreach (array_chunk(array_values(array_unique($workspaceIds)), 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('created_at', 'updated_at')->from(self::TABLE)
				->where($qb->expr()->in('workspace_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->andWhere($qb->expr()->eq('role', $qb->createNamedParameter('note')))
				->andWhere($qb->expr()->isNull('trashed_at'));
			$res = $qb->executeQuery();
			while ($row = $res->fetch()) {
				$out[] = [(int)$row['created_at'], (int)$row['updated_at']];
			}
			$res->closeCursor();
		}
		return $out;
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
	 * Treffer der search_text-Suche ohne Inhalt (Erwähnungszähler, neueste zuerst)
	 * @param int[] $workspaceIds
	 * @return list<array{id: int, type: string, bytes: int}>
	 */
	public function searchSizes(array $workspaceIds, string $search): array {
		$out = [];
		foreach (array_chunk(array_values(array_unique($workspaceIds)), 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id', 'type', 'updated_at')->selectAlias($qb->func()->octetLength('content'), 'bytes')->from(self::TABLE)
				->where($qb->expr()->in('workspace_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->andWhere($qb->expr()->eq('role', $qb->createNamedParameter('note')))
				->andWhere($qb->expr()->isNull('trashed_at'))
				->andWhere($qb->expr()->like('search_text', $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($search) . '%')));
			$res = $qb->executeQuery();
			while ($row = $res->fetch()) {
				$out[] = ['id' => (int)$row['id'], 'type' => (string)($row['type'] ?: 'note'), 'bytes' => (int)$row['bytes'], 'updated' => (int)$row['updated_at']];
			}
			$res->closeCursor();
		}
		usort($out, static fn (array $a, array $b) => [$b['updated'], $b['id']] <=> [$a['updated'], $a['id']]);
		return array_map(static fn (array $r) => ['id' => $r['id'], 'type' => $r['type'], 'bytes' => $r['bytes']], $out);
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
	 * Inhaltsanfang je Notiz (Vorschau der Papierkorb-Seite), höchstens $chars Zeichen aus der DB
	 * @param int[] $ids
	 * @return array<int, string>
	 */
	public function contentPrefixes(array $ids, int $chars): array {
		$out = [];
		foreach (array_chunk(array_values(array_unique($ids)), 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id')
				->selectAlias($qb->func()->substring('content', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT), $qb->createNamedParameter($chars, IQueryBuilder::PARAM_INT)), 'prefix')
				->from(self::TABLE)
				->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$res = $qb->executeQuery();
			while ($row = $res->fetch()) {
				$c = $row['prefix'];
				$out[(int)$row['id']] = is_resource($c) ? (string)stream_get_contents($c) : (string)$c;
			}
			$res->closeCursor();
		}
		return $out;
	}

	/**
	 * Papierkorb eines Workspace: Anzahl (Begriffe UND-verknüpft über search_text), mit $after nur ältere als der Cursor
	 * @param ?array{0: int, 1: int} $after [Zeitstempel, id] der letzten gezeigten Notiz
	 */
	public function countTrashed(int $workspaceId, array $terms, bool $byCreated = false, ?array $after = null): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id', 'cnt'))->from(self::TABLE);
		$this->trashedWhere($qb, $workspaceId, $terms, $byCreated, $after);
		$res = $qb->executeQuery();
		$count = (int)$res->fetchOne();
		$res->closeCursor();
		return $count;
	}

	/**
	 * Papierkorb eines Workspace: nächste $limit nach Löschdatum bzw. Anlage (neueste zuerst, Keyset ab $after), ohne Inhalt
	 * @param string[] $terms gefaltete Suchbegriffe
	 * @param ?array{0: int, 1: int} $after [Zeitstempel, id] der letzten gezeigten Notiz
	 * @return Note[]
	 */
	public function findTrashedPage(int $workspaceId, array $terms, bool $byCreated, int $limit, ?array $after = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select(...self::LIST_COLUMNS)->from(self::TABLE);
		$this->trashedWhere($qb, $workspaceId, $terms, $byCreated, $after);
		$qb->orderBy($byCreated ? 'created_at' : 'trashed_at', 'DESC')->addOrderBy('id', 'DESC')->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	/**
	 * @param string[] $terms
	 * @param ?array{0: int, 1: int} $after
	 */
	private function trashedWhere(IQueryBuilder $qb, int $workspaceId, array $terms, bool $byCreated = false, ?array $after = null): void {
		$qb->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('role', $qb->createNamedParameter('note')))
			->andWhere($qb->expr()->isNotNull('trashed_at'));
		foreach ($terms as $term) {
			$qb->andWhere($qb->expr()->like('search_text', $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($term) . '%')));
		}
		if ($after !== null) {
			// Keyset: (Zeit, id) kleiner als der Cursor; stabil, auch wenn gezeigte Notizen inzwischen fehlen
			$col = $byCreated ? 'created_at' : 'trashed_at';
			$ts = $qb->createNamedParameter($after[0], IQueryBuilder::PARAM_INT);
			$qb->andWhere($qb->expr()->orX(
				$qb->expr()->lt($col, $ts),
				$qb->expr()->andX($qb->expr()->eq($col, $ts), $qb->expr()->lt('id', $qb->createNamedParameter($after[1], IQueryBuilder::PARAM_INT))),
			));
		}
	}

	/**
	 * Notizen je Workspace, ohne bzw. im Papierkorb (Einstellungen: Workspace-Liste, Speicher)
	 * @param int[] $workspaceIds
	 * @return array<int, array{active: int, trash: int}>
	 */
	public function countByWorkspace(array $workspaceIds): array {
		$out = [];
		foreach (array_chunk(array_values(array_unique($workspaceIds)), 1000) as $chunk) {
			foreach (['active' => false, 'trash' => true] as $field => $trashed) {
				$qb = $this->db->getQueryBuilder();
				$qb->select('workspace_id')->selectAlias($qb->func()->count('id'), 'cnt')->from(self::TABLE)
					->where($qb->expr()->in('workspace_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
					->andWhere($qb->expr()->eq('role', $qb->createNamedParameter('note')))
					->andWhere($trashed ? $qb->expr()->isNotNull('trashed_at') : $qb->expr()->isNull('trashed_at'))
					->groupBy('workspace_id');
				$res = $qb->executeQuery();
				while ($row = $res->fetch()) {
					$id = (int)$row['workspace_id'];
					$out[$id] ??= ['active' => 0, 'trash' => 0];
					$out[$id][$field] = (int)$row['cnt'];
				}
				$res->closeCursor();
			}
		}
		return $out;
	}

	/**
	 * Notizen je Ordner eines Workspace (ohne Papierkorb, ohne Wurzel); mit $tag nur Notizen mit diesem Tag
	 * @return array<int, int> folder_id => Anzahl
	 */
	public function countByFolder(int $workspaceId, ?string $tag = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('n.folder_id')->selectAlias($qb->func()->count('n.id'), 'cnt')->from(self::TABLE, 'n')
			->where($qb->expr()->eq('n.workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('n.role', $qb->createNamedParameter('note')))
			->andWhere($qb->expr()->isNull('n.trashed_at'))
			->andWhere($qb->expr()->isNotNull('n.folder_id'))
			->groupBy('n.folder_id');
		if ($tag !== null) {
			$qb->innerJoin('n', NoteTagMapper::TABLE, 't', $qb->expr()->andX(
				$qb->expr()->eq('t.note_id', 'n.id'),
				$qb->expr()->eq('t.tag', $qb->createNamedParameter($tag)),
			));
		}
		$out = [];
		$res = $qb->executeQuery();
		while ($row = $res->fetch()) {
			$out[(int)$row['folder_id']] = (int)$row['cnt'];
		}
		$res->closeCursor();
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

	/** @param int[]|null $excludeFolderIds Ordner ohne Schreibrecht (Material, M6a); gesetzt → auch trash_material ausgelassen */
	public function clearFavorites(int $workspaceId, ?array $excludeFolderIds = null): int {
		$qb = $this->db->getQueryBuilder();
		$expr = $qb->expr();
		$qb->update(self::TABLE)
			->set('favorite', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			->where($expr->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
			->andWhere($expr->eq('favorite', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->andWhere($expr->eq('role', $qb->createNamedParameter('note')));
		if ($excludeFolderIds !== null) {
			$qb->andWhere($expr->eq('trash_material', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
		}
		if ($excludeFolderIds !== null && $excludeFolderIds !== []) {
			$qb->andWhere($expr->orX($expr->isNull('folder_id'),
				$expr->notIn('folder_id', $qb->createNamedParameter(array_values($excludeFolderIds), IQueryBuilder::PARAM_INT_ARRAY))));
		}
		return $qb->executeStatement();
	}

	/**
	 * Papierkorb-Markierung für Notizen aus einem gelöschten Material-Zweig (M6a)
	 * @param int[] $folderIds
	 */
	public function markTrashMaterial(int $workspaceId, array $folderIds): void {
		foreach (array_chunk(array_values(array_unique($folderIds)), 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->update(self::TABLE)
				->set('trash_material', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->in('folder_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->executeStatement();
		}
	}

	/**
	 * Papierkorb-Markierung entfernen (Verlassen des Teams, make_private; Review M6a-r1 Hinweis 2)
	 * @param int[]|null $folderIds null = ganzer Workspace
	 */
	public function clearTrashMaterial(int $workspaceId, ?array $folderIds = null): void {
		foreach ($folderIds === null ? [null] : array_chunk(array_values(array_unique($folderIds)), 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->update(self::TABLE)
				->set('trash_material', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('trash_material', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));
			if ($chunk !== null) {
				$qb->andWhere($qb->expr()->in('folder_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			}
			$qb->executeStatement();
		}
	}

	// Zeilensperre bis zum Ende der Transaktion (Update ohne Wertänderung, DB-neutral)
	public function lockRow(int $id): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)->set('id', 'id')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/**
	 * Autor und Rolle der Notizen, die einen Workspace verlassen würden (jede Rolle, auch Papierkorb; requireLeave)
	 * @param int[]|null $folderIds null = ganzer Workspace
	 * @param int[]|null $noteIds null = ohne Einschränkung
	 * @return list<array{created_by: ?string, role: string}>
	 */
	public function leaveRows(int $workspaceId, ?array $folderIds = null, ?array $noteIds = null): array {
		$qb = $this->db->getQueryBuilder();
		$expr = $qb->expr();
		$qb->selectDistinct(['created_by', 'role'])->from(self::TABLE)
			->where($expr->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)));
		$limits = [];
		if ($folderIds !== null && $folderIds !== []) {
			$limits[] = $expr->in('folder_id', $qb->createNamedParameter(array_values(array_unique($folderIds)), IQueryBuilder::PARAM_INT_ARRAY));
		}
		if ($noteIds !== null && $noteIds !== []) {
			$limits[] = $expr->in('id', $qb->createNamedParameter(array_values(array_unique($noteIds)), IQueryBuilder::PARAM_INT_ARRAY));
		}
		if ($limits !== []) {
			$qb->andWhere($expr->orX(...$limits));
		} elseif ($folderIds !== null || $noteIds !== null) {
			return [];
		}
		$res = $qb->executeQuery();
		$out = [];
		while ($row = $res->fetch()) {
			$out[] = ['created_by' => $row['created_by'] === null ? null : (string)$row['created_by'], 'role' => (string)$row['role']];
		}
		$res->closeCursor();
		return $out;
	}

	/** @return int[] Vorlage und Kopien einer Aufgabe (jede Rolle, auch ohne Workspace und im Papierkorb) */
	public function idsForAssignment(int $assignmentId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from(self::TABLE)
			->where($qb->expr()->eq('assignment_id', $qb->createNamedParameter($assignmentId, IQueryBuilder::PARAM_INT)));
		return array_map('intval', $qb->executeQuery()->fetchFirstColumn());
	}

	/** @return int[] alle Notiz-IDs eines Workspace (jede Rolle, auch Papierkorb); $role = nur diese Rolle */
	public function idsInWorkspace(int $workspaceId, ?string $role = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from(self::TABLE)
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($workspaceId, IQueryBuilder::PARAM_INT)));
		if ($role !== null) {
			$qb->andWhere($qb->expr()->eq('role', $qb->createNamedParameter($role)));
		}
		return array_map('intval', $qb->executeQuery()->fetchFirstColumn());
	}

	/**
	 * Kontolöschung: Kopien aus dem Schüler-Workspace lösen (SPEC §8); Anhänge und Snapshots bleiben an der Notiz
	 * @param int[] $ids
	 */
	public function detachSubmissions(array $ids, int $now): void {
		foreach (array_chunk(array_values(array_unique($ids)), 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->update(self::TABLE)
				->set('workspace_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
				->set('folder_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
				->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
				->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->andWhere($qb->expr()->eq('role', $qb->createNamedParameter('submission')))
				->executeStatement();
		}
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

	// Irgendein Notiz (jede Rolle, auch Papierkorb) enthält $needle (CleanupJob: Verweis auf eine Vorschau, im Zweifel behalten)
	public function anyContentLike(string $needle): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from(self::TABLE)
			->where($qb->expr()->like('content', $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($needle) . '%')))
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$found = $result->fetchOne() !== false;
		$result->closeCursor();
		return $found;
	}
}
