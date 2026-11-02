<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service;

use OCA\NextNotes\Db\Folder;
use OCA\NextNotes\Db\FolderMapper;
use OCA\NextNotes\Db\Note;
use OCA\NextNotes\Db\NoteMapper;
use OCA\NextNotes\Db\NoteTagMapper;
use OCA\NextNotes\Db\Workspace;
use OCA\NextNotes\Service\Exception\BadRequestException;
use OCA\NextNotes\Service\Exception\ConflictException;
use OCA\NextNotes\Service\Exception\NotFoundException;
use OCP\AppFramework\Utility\ITimeFactory;

// Ordner (Upstream FoldersController, ordnerbezogene Routen)
class FolderService {
	public function __construct(
		private FolderMapper $folders,
		private NoteMapper $notes,
		private NoteTagMapper $tags,
		private AccessService $access,
		private WorkspaceService $workspaces,
		private SettingsService $settings,
		private InputValidator $validator,
		private Transaction $tx,
		private ITimeFactory $time,
	) {
	}

	// ---------- Hilfen ----------

	/** @return array<int, Folder> */
	public function byId(int $workspaceId): array {
		$out = [];
		foreach ($this->folders->findInWorkspace($workspaceId) as $f) {
			$out[(int)$f->getId()] = $f;
		}
		return $out;
	}

	/** @param array<int, Folder> $byId */
	public static function pathOf(int $id, array $byId): ?string {
		if (!isset($byId[$id])) {
			return null;
		}
		$parts = [];
		for ($cur = $id, $depth = 0; $cur !== null && isset($byId[$cur]) && $depth < 50; $depth++) {
			array_unshift($parts, $byId[$cur]->getName());
			$cur = $byId[$cur]->getParentId();
		}
		return implode('/', $parts);
	}

	/**
	 * Ordner und alle Unterordner
	 * @param array<int, Folder> $byId
	 * @return int[]
	 */
	public static function subtree(int $id, array $byId): array {
		$ids = [$id];
		for ($i = 0; $i < count($ids); $i++) {
			foreach ($byId as $fid => $f) {
				if ($f->getParentId() === $ids[$i] && !in_array($fid, $ids, true)) {
					$ids[] = $fid;
				}
			}
		}
		return $ids;
	}

	// Upstream poznoteCompareFolders
	public static function compare(string $mode, Folder $a, Folder $b): int {
		$byName = static fn () => strnatcasecmp($a->getName(), $b->getName()) ?: ((int)$a->getId() <=> (int)$b->getId());
		if ($mode === 'manual') {
			$oa = (int)$a->getDisplayOrder();
			$ob = (int)$b->getDisplayOrder();
			if ($oa > 0 || $ob > 0) {
				if ($oa <= 0) {
					return 1;
				}
				if ($ob <= 0) {
					return -1;
				}
				if ($oa !== $ob) {
					return $oa <=> $ob;
				}
			}
			return $byName();
		}
		if ($mode === 'updated_desc' || $mode === 'created_desc') {
			return ((int)$b->getCreatedAt() <=> (int)$a->getCreatedAt()) ?: $byName();
		}
		return $byName();
	}

	private function insertFolder(int $workspaceId, ?int $parentId, string $name, array $extra = []): Folder {
		$now = $this->time->getTime();
		$f = new Folder();
		$f->setWorkspaceId($workspaceId);
		$f->setParentId($parentId);
		$f->setName($name);
		$f->setDisplayOrder((int)($extra['display_order'] ?? 0));
		$f->setPinned((int)($extra['pinned'] ?? 0));
		$f->setFavorite((int)($extra['favorite'] ?? 0));
		$f->setKanbanEnabled((int)($extra['kanban_enabled'] ?? 0));
		$f->setIsDiary((int)($extra['is_diary'] ?? 0));
		$f->setIcon($extra['icon'] ?? null);
		$f->setIconColor($extra['icon_color'] ?? null);
		$f->setColor($extra['color'] ?? null);
		$f->setSortSetting($extra['sort_setting'] ?? null);
		$f->setCreatedAt($now);
		$f->setUpdatedAt($now);
		return $this->folders->insert($f);
	}

	private function folderView(Folder $f): array {
		return [
			'id' => (int)$f->getId(),
			'name' => $f->getName(),
			'parent_id' => $f->getParentId(),
			'icon' => $f->getIcon(),
			'icon_color' => $f->getIconColor(),
			'color' => $f->getColor(),
			'color_hex' => $this->validator->colorHex($f->getColor()),
			'display_order' => (int)$f->getDisplayOrder(),
		];
	}

	// Ordner muss im Workspace liegen (Body-ID von anderem Workspace → 404)
	public function requireIn(string $uid, int $folderId, Workspace $ws, string $perm, string $message = 'Folder not found'): Folder {
		$folder = $this->access->requireFolder($uid, $folderId, $perm);
		if ($folder->getWorkspaceId() !== $ws->getId()) {
			throw new NotFoundException($message);
		}
		return $folder;
	}

	// Pfad → Ordner-ID wie resolveFolderPathToId inkl. refuseAmbiguousFolderName (409)
	public function resolvePath(Workspace $ws, string $path, bool $create): ?int {
		$path = trim($path);
		if ($path === '' || strtolower($path) === 'default') {
			return null;
		}
		$segments = array_values(array_filter(array_map('trim', explode('/', $path)), static fn ($s) => $s !== ''));
		if ($segments === []) {
			return null;
		}
		$wsId = (int)$ws->getId();
		if (count($segments) === 1) {
			$root = $this->folders->findSibling($wsId, null, $segments[0]);
			if ($root !== null) {
				return (int)$root->getId();
			}
			$byId = $this->byId($wsId);
			$matches = array_values(array_filter($byId, static fn (Folder $f) => $f->getName() === $segments[0]));
			if (count($matches) === 1) {
				return (int)$matches[0]->getId();
			}
			if (count($matches) > 1) {
				$candidates = array_map(static fn (Folder $f) => ['id' => (int)$f->getId(), 'path' => self::pathOf((int)$f->getId(), $byId)], $matches);
				usort($candidates, static fn ($a, $b) => strcasecmp((string)$a['path'], (string)$b['path']));
				throw new ConflictException('Several folders are named "' . $segments[0] . '" in this workspace. Pass the full path, or folder_id.', [
					'code' => 'ambiguous_folder_name',
					'candidates' => $candidates,
				]);
			}
		}
		$parentId = null;
		foreach ($segments as $seg) {
			$found = $this->folders->findSibling($wsId, $parentId, $seg);
			if ($found !== null) {
				$parentId = (int)$found->getId();
			} elseif ($create) {
				$parentId = (int)$this->insertFolder($wsId, $parentId, $this->validator->folderName($seg))->getId();
			} else {
				return null;
			}
		}
		return $parentId;
	}

	// ---------- Routen ----------

	/** @return array<string, mixed> */
	public function index(string $uid, mixed $wsKey, bool $hierarchical): array {
		$ws = $this->workspaces->resolveOptional($uid, $wsKey) ?? $this->workspaces->defaultWorkspace($uid);
		$byId = $this->byId((int)$ws->getId());
		$items = [];
		foreach ($byId as $id => $f) {
			$item = $this->folderView($f) + ['is_diary' => (int)$f->getIsDiary() === 1];
			if ($hierarchical) {
				$item['created'] = NoteSerializer::date($f->getCreatedAt());
			}
			$item['path'] = self::pathOf($id, $byId);
			$items[$id] = $item;
		}
		$key = $this->workspaces->keyFor($uid, (int)$ws->getId());
		if (!$hierarchical) {
			$flat = array_values($items);
			usort($flat, static fn ($a, $b) => strcasecmp((string)$a['path'], (string)$b['path']));
			return ['workspace' => $key, 'hierarchical' => false, 'folders' => $flat];
		}
		$mode = $this->settings->noteListSort($uid);
		$build = function (?int $parent) use (&$build, $byId, $items, $mode): array {
			$children = array_filter($byId, static fn (Folder $f) => $f->getParentId() === $parent);
			uasort($children, static fn (Folder $a, Folder $b) => self::compare($mode, $a, $b));
			$out = [];
			foreach ($children as $id => $f) {
				$out[] = $items[$id] + ['children' => $build($id)];
			}
			return $out;
		};
		return ['workspace' => $key, 'hierarchical' => true, 'folders' => $build(null)];
	}

	/** @return array<string, mixed> */
	public function show(string $uid, int $id, mixed $wsKey): array {
		$folder = $this->access->requireFolder($uid, $id, 'read');
		$this->checkWorkspaceParam($uid, $folder, $wsKey);
		$byId = $this->byId((int)$folder->getWorkspaceId());
		return $this->folderView($folder) + [
			'workspace' => $this->workspaces->keyFor($uid, (int)$folder->getWorkspaceId()),
			'path' => self::pathOf($id, $byId),
			'created' => NoteSerializer::date($folder->getCreatedAt()),
		];
	}

	// Optionaler ?workspace= muss zum Ordner passen
	private function checkWorkspaceParam(string $uid, Folder $folder, mixed $wsKey): void {
		$ws = $this->workspaces->resolveOptional($uid, $wsKey);
		if ($ws !== null && $ws->getId() !== $folder->getWorkspaceId()) {
			throw new NotFoundException('Folder not found');
		}
	}

	/** @return array{0: array<string, mixed>, 1: int} Antwort, Status */
	public function create(string $uid, array $data): array {
		$ws = $this->workspaces->targetForWrite($uid, $data['workspace'] ?? null);
		$wsId = (int)$ws->getId();
		$wsKey = $this->workspaces->keyFor($uid, $wsId);
		$folderPath = isset($data['folder_path']) ? trim((string)$data['folder_path']) : '';
		$createParents = !empty($data['create_parents']);
		$folderName = trim((string)($data['folder_name'] ?? $data['name'] ?? ''));
		$isDiary = isset($data['is_diary']) && filter_var($data['is_diary'], FILTER_VALIDATE_BOOLEAN);
		if ($folderPath === '' && $folderName === '') {
			throw new BadRequestException('folder_name or folder_path is required');
		}
		return $this->tx->run(function () use ($uid, $data, $ws, $wsId, $wsKey, $folderPath, $createParents, $folderName, $isDiary) {
			if ($folderPath !== '') {
				$segments = array_values(array_filter(array_map('trim', explode('/', trim($folderPath, "/ \t\n\r\0\x0B"))), static fn ($s) => $s !== ''));
				if ($segments === []) {
					throw new BadRequestException('Invalid folder_path');
				}
				if ($isDiary && count($segments) > 1) {
					throw new BadRequestException('A diary is a root folder: is_diary needs a single-segment folder_path');
				}
				$parentId = null;
				$finalParent = null;
				$created = [];
				$final = null;
				foreach ($segments as $i => $seg) {
					$seg = $this->validator->folderName($seg);
					$last = $i === count($segments) - 1;
					$existing = $this->folders->findSibling($wsId, $parentId, $seg);
					if ($existing !== null) {
						if ($last) {
							throw new ConflictException('Folder already exists', ['folder' => ['id' => (int)$existing->getId()]]);
						}
						$parentId = (int)$existing->getId();
						continue;
					}
					if (!$createParents && !$last) {
						throw new NotFoundException('Parent folder does not exist', ['missing_segment' => $seg]);
					}
					$new = $this->insertFolder($wsId, $parentId, $seg, ['is_diary' => ($isDiary && $last) ? 1 : 0]);
					if ($last) {
						$finalParent = $parentId;
						$final = $new;
					} else {
						$created[] = ['id' => (int)$new->getId(), 'name' => $seg, 'parent_id' => $parentId];
					}
					$parentId = (int)$new->getId();
				}
				return [[
					'message' => 'Folder created successfully',
					'folder' => ['id' => (int)$final->getId(), 'name' => $final->getName(), 'workspace' => $wsKey, 'parent_id' => $finalParent, 'is_diary' => $isDiary, 'path' => trim($folderPath, '/')],
					'folder_id' => (int)$final->getId(),
					'folder_name' => $final->getName(),
					'created_parents' => $created,
				], 201];
			}
			$folderName = $this->validator->folderName($folderName);
			$parentKey = isset($data['parent_folder_key']) ? trim((string)$data['parent_folder_key']) : null;
			$parentFolder = isset($data['parent_folder']) ? trim((string)$data['parent_folder']) : '';
			$parentRaw = $data['parent_folder_id'] ?? $data['parent_id'] ?? null;
			if ($isDiary && ($parentRaw !== null || $parentFolder !== '' || $parentKey !== null)) {
				throw new BadRequestException('A diary is a root folder: is_diary cannot be combined with a parent folder');
			}
			$parentId = null;
			if ($parentKey !== null && str_starts_with($parentKey, 'folder_')) {
				$parentId = (int)$this->requireIn($uid, (int)substr($parentKey, 7), $ws, 'write', 'Parent folder not found')->getId();
			} elseif ($parentRaw !== null && (int)$parentRaw > 0) {
				$parentId = (int)$this->requireIn($uid, (int)$parentRaw, $ws, 'write', 'Parent folder not found')->getId();
			} elseif ($parentFolder !== '') {
				$parentId = $this->resolvePath($ws, $parentFolder, false);
				if ($parentId === null) {
					throw new NotFoundException('Parent folder not found');
				}
			}
			$existing = $this->folders->findSibling($wsId, $parentId, $folderName);
			if ($existing !== null) {
				if ($isDiary && (int)$existing->getIsDiary() !== 1) {
					$existing->setIsDiary(1);
					$this->folders->update($existing);
					return [[
						'message' => 'Existing folder turned into a diary',
						'converted' => true,
						'folder' => ['id' => (int)$existing->getId(), 'name' => $folderName, 'workspace' => $wsKey, 'parent_id' => null, 'is_diary' => true],
						'folder_id' => (int)$existing->getId(),
						'folder_name' => $folderName,
						'parent_id' => null,
					], 200];
				}
				throw new ConflictException('Folder already exists in this location');
			}
			$new = $this->insertFolder($wsId, $parentId, $folderName, ['is_diary' => $isDiary ? 1 : 0]);
			return [[
				'message' => 'Folder created successfully',
				'folder' => ['id' => (int)$new->getId(), 'name' => $folderName, 'workspace' => $wsKey, 'parent_id' => $parentId, 'is_diary' => $isDiary],
				'folder_id' => (int)$new->getId(),
				'folder_name' => $folderName,
				'parent_id' => $parentId,
			], 201];
		});
	}

	/** @return array<string, mixed> */
	public function rename(string $uid, int $id, array $data): array {
		$folder = $this->access->requireFolder($uid, $id, 'manage');
		$this->checkWorkspaceParam($uid, $folder, $data['workspace'] ?? null);
		if (!isset($data['name']) && !isset($data['new_name'])) {
			throw new BadRequestException('No update parameters provided');
		}
		$oldName = $folder->getName();
		if (in_array($oldName, InputValidator::RESERVED_FOLDER_NAMES, true)) {
			throw new BadRequestException('Renaming system folders is not allowed');
		}
		$newName = $this->validator->folderName($data['name'] ?? $data['new_name']);
		if ($this->folders->findSibling((int)$folder->getWorkspaceId(), $folder->getParentId(), $newName, $id) !== null) {
			throw new ConflictException('Folder already exists in this location');
		}
		$folder->setName($newName);
		$folder->setUpdatedAt($this->time->getTime());
		$this->folders->update($folder);
		return ['message' => 'Folder renamed successfully', 'folder' => ['id' => $id, 'old_name' => $oldName, 'name' => $newName]];
	}

	// Wie Upstream: Ordnerzeilen weg, Notizen in den Papierkorb, Wiederherstellung über restore_snapshot
	/** @return array<string, mixed> */
	public function delete(string $uid, int $id, mixed $wsKey): array {
		$folder = $this->access->requireFolder($uid, $id, 'manage');
		$this->checkWorkspaceParam($uid, $folder, $wsKey);
		$wsId = (int)$folder->getWorkspaceId();
		$byId = $this->byId($wsId);
		$ids = self::subtree($id, $byId);
		$notes = $this->notes->findList([$wsId], ['folder_ids' => $ids]);
		$snapshot = [
			'workspace' => $this->workspaces->keyFor($uid, $wsId),
			'folder_id' => $id,
			'folders' => array_map(static fn (int $fid) => [
				'id' => $fid,
				'name' => $byId[$fid]->getName(),
				'parent_id' => $byId[$fid]->getParentId(),
				'icon' => $byId[$fid]->getIcon(),
				'icon_color' => $byId[$fid]->getIconColor(),
				'color' => $byId[$fid]->getColor(),
				'display_order' => (int)$byId[$fid]->getDisplayOrder(),
				'pinned' => (int)$byId[$fid]->getPinned(),
				'favorite' => (int)$byId[$fid]->getFavorite(),
				'kanban_enabled' => (int)$byId[$fid]->getKanbanEnabled(),
				'sort_setting' => $byId[$fid]->getSortSetting(),
			], $ids),
			'notes' => array_map(static fn (Note $n) => ['id' => (int)$n->getId(), 'folder_id' => (int)$n->getFolderId()], $notes),
		];
		$this->tx->run(function () use ($wsId, $ids, $uid) {
			$now = $this->time->getTime();
			foreach ($this->notes->findList([$wsId], ['folder_ids' => $ids, 'trashed' => null]) as $note) {
				$note->setTrashedAt($now);
				$this->notes->update($note);
			}
			$this->folders->deleteByIds($ids);
		});
		return ['message' => 'Folder and all subfolders deleted successfully', 'restore_snapshot' => $snapshot];
	}

	/** @return array<string, mixed> */
	public function restoreDeleted(string $uid, array $data): array {
		$ws = $this->workspaces->resolveOptional($uid, $data['workspace'] ?? null, 'write');
		if ($ws === null) {
			throw new NotFoundException('Workspace not found');
		}
		$wsId = (int)$ws->getId();
		$rows = [];
		foreach (is_array($data['folders'] ?? null) ? $data['folders'] : [] as $row) {
			if (!is_array($row) || !isset($row['id'], $row['name']) || (int)$row['id'] <= 0) {
				continue;
			}
			$rows[(int)$row['id']] = [
				'id' => (int)$row['id'],
				'name' => $this->validator->folderName($row['name']),
				'parent_id' => (isset($row['parent_id']) && $row['parent_id'] !== null && $row['parent_id'] !== '') ? (int)$row['parent_id'] : null,
				'icon' => isset($row['icon']) && $row['icon'] !== '' ? $this->validator->icon($row['icon']) : null,
				'icon_color' => isset($row['icon_color']) && $row['icon_color'] !== '' ? $this->validator->iconColor($row['icon_color']) : null,
				'color' => isset($row['color']) && $row['color'] !== '' ? $this->validator->color($row['color']) : null,
				'display_order' => (int)($row['display_order'] ?? 0),
				'pinned' => !empty($row['pinned']) ? 1 : 0,
				'favorite' => !empty($row['favorite']) ? 1 : 0,
				'kanban_enabled' => !empty($row['kanban_enabled']) ? 1 : 0,
				'sort_setting' => isset($row['sort_setting']) && is_string($row['sort_setting']) && preg_match('/^[a-z_]{1,32}$/', $row['sort_setting']) ? $row['sort_setting'] : null,
			];
		}
		if ($rows === []) {
			throw new BadRequestException('folders is required');
		}
		$rootOld = (int)($data['folder_id'] ?? 0);
		if ($rootOld <= 0 || !isset($rows[$rootOld])) {
			$rootOld = (int)array_key_first($rows);
		}
		// Eltern vor Kindern
		$ordered = [];
		$placed = [];
		$pending = $rows;
		while ($pending !== []) {
			$progress = false;
			foreach ($pending as $oldId => $row) {
				$p = $row['parent_id'];
				if ($p === null || !isset($rows[$p]) || isset($placed[$p])) {
					$ordered[] = $row;
					$placed[$oldId] = true;
					unset($pending[$oldId]);
					$progress = true;
				}
			}
			if (!$progress) {
				throw new BadRequestException('Snapshot folders form a cycle');
			}
		}
		$notes = is_array($data['notes'] ?? null) ? $data['notes'] : [];
		[$idMap, $restored] = $this->tx->run(function () use ($ordered, $wsId, $notes) {
			$idMap = [];
			foreach ($ordered as $row) {
				$parent = null;
				if ($row['parent_id'] !== null) {
					if (isset($idMap[$row['parent_id']])) {
						$parent = $idMap[$row['parent_id']];
					} else {
						// Bestehender Elternordner muss im selben Workspace liegen; gelöscht → Wurzel
						try {
							$p = $this->folders->find($row['parent_id']);
						} catch (\OCP\AppFramework\Db\DoesNotExistException) {
							$p = null;
						}
						if ($p !== null && $p->getWorkspaceId() !== $wsId) {
							throw new NotFoundException('Parent folder not found');
						}
						$parent = $p?->getId();
					}
				}
				$existing = $this->folders->findSibling($wsId, $parent, $row['name']);
				$idMap[$row['id']] = $existing !== null ? (int)$existing->getId() : (int)$this->insertFolder($wsId, $parent, $row['name'], $row)->getId();
			}
			$restored = 0;
			$noteIds = [];
			foreach ($notes as $n) {
				if (is_array($n) && isset($n['id'], $idMap[(int)($n['folder_id'] ?? 0)])) {
					$noteIds[(int)$n['id']] = $idMap[(int)$n['folder_id']];
				}
			}
			// Notizen anderer Workspaces → 404; nur Papierkorb-Notizen werden zurückgeholt
			foreach ($noteIds === [] ? [] : $this->notes->findByIds(array_keys($noteIds)) as $note) {
				if ($note->getWorkspaceId() !== $wsId || $note->getRole() !== 'note') {
					throw new NotFoundException('Note not found');
				}
				if ($note->getTrashedAt() === null) {
					continue;
				}
				$note->setTrashedAt(null);
				$note->setFolderId($noteIds[(int)$note->getId()]);
				$this->notes->update($note);
				$restored++;
			}
			return [$idMap, $restored];
		});
		return ['message' => 'Folder restored successfully', 'folder_id' => $idMap[$rootOld] ?? null, 'folder_id_map' => $idMap, 'restored_notes' => $restored];
	}

	/** @return array<string, mixed> */
	public function move(string $uid, int $id, array $data): array {
		$folder = $this->access->requireFolder($uid, $id, 'manage');
		$source = $this->access->requireWorkspace($uid, (int)$folder->getWorkspaceId(), 'write');
		$target = $this->workspaces->resolveOptional($uid, $data['target_workspace'] ?? null, 'write') ?? $source;
		if ($target->getId() !== $source->getId()) {
			// Inhalte verlassen einen Workspace nur durch dessen Verwalter
			$this->access->requireWorkspace($uid, $source, 'manage');
		}
		$newParentRaw = $data['new_parent_folder_id'] ?? $data['new_parent_id'] ?? $data['parent_id'] ?? null;
		$newParentPath = isset($data['new_parent_folder']) ? trim((string)$data['new_parent_folder']) : '';
		$targetParent = null;
		if ($newParentRaw !== null && (int)$newParentRaw > 0) {
			$p = $this->access->requireFolder($uid, (int)$newParentRaw, 'write');
			if ($p->getWorkspaceId() !== $target->getId()) {
				throw new BadRequestException('New parent folder must be in the target workspace');
			}
			$targetParent = (int)$p->getId();
		} elseif ($newParentRaw === null && $newParentPath !== '') {
			$targetParent = $this->resolvePath($target, $newParentPath, false);
			if ($targetParent === null) {
				throw new NotFoundException('New parent folder not found');
			}
		}
		if ($targetParent === $id) {
			throw new BadRequestException('Folder cannot be its own parent');
		}
		$byId = $this->byId((int)$source->getId());
		if ($targetParent !== null && $target->getId() === $source->getId() && in_array($targetParent, self::subtree($id, $byId), true)) {
			throw new ConflictException('Invalid move: would create a cycle');
		}
		if ($this->folders->findSibling((int)$target->getId(), $targetParent, $folder->getName(), $id) !== null) {
			throw new ConflictException('A folder with this name already exists in the destination');
		}
		$same = $folder->getParentId() === $targetParent && $target->getId() === $source->getId();
		if (!$same) {
			$this->tx->run(function () use ($folder, $targetParent, $target, $source, $byId, $id) {
				$now = $this->time->getTime();
				$folder->setParentId($targetParent);
				$folder->setDisplayOrder(0);
				$folder->setUpdatedAt($now);
				$this->folders->update($folder);
				if ($target->getId() !== $source->getId()) {
					$ids = self::subtree($id, $byId);
					$this->folders->moveToWorkspace($ids, (int)$target->getId(), $now);
					$this->notes->moveFolderNotesToWorkspace($ids, (int)$target->getId());
				}
			});
		}
		$after = $this->byId((int)$target->getId());
		return ['message' => 'Folder moved successfully', 'folder' => [
			'id' => $id,
			'name' => $folder->getName(),
			'workspace' => $this->workspaces->keyFor($uid, (int)$target->getId()),
			'parent_id' => $targetParent,
			'display_order' => (int)$folder->getDisplayOrder(),
			'path' => self::pathOf($id, $after),
		]];
	}

	/** @return array<string, mixed> */
	public function reorder(string $uid, array $data): array {
		$folderId = (int)($data['folder_id'] ?? 0);
		$targetId = (int)($data['target_folder_id'] ?? 0);
		$position = strtolower(trim((string)($data['position'] ?? '')));
		if ($folderId <= 0 || $targetId <= 0) {
			throw new BadRequestException('folder_id and target_folder_id are required');
		}
		if (!in_array($position, ['before', 'after'], true)) {
			throw new BadRequestException('position must be before or after');
		}
		if ($folderId === $targetId) {
			throw new BadRequestException('Folder cannot be reordered relative to itself');
		}
		$folder = $this->access->requireFolder($uid, $folderId, 'manage');
		$target = $this->access->requireFolder($uid, $targetId, 'write');
		if ($folder->getWorkspaceId() !== $target->getWorkspaceId()) {
			throw new BadRequestException('Folders must be in the same workspace');
		}
		$ws = $this->workspaces->resolveOptional($uid, $data['workspace'] ?? null);
		if ($ws !== null && $ws->getId() !== $target->getWorkspaceId()) {
			throw new BadRequestException('Workspace does not match target folder');
		}
		$wsId = (int)$target->getWorkspaceId();
		$parent = $target->getParentId();
		$byId = $this->byId($wsId);
		if ($parent !== null && in_array($parent, self::subtree($folderId, $byId), true)) {
			throw new ConflictException('Invalid move: would create a cycle');
		}
		if ($this->folders->findSibling($wsId, $parent, $folder->getName(), $folderId) !== null) {
			throw new ConflictException('A folder with this name already exists in the destination');
		}
		$mode = $this->settings->noteListSort($uid);
		$siblings = array_filter($byId, static fn (Folder $f) => $f->getParentId() === $parent && (int)$f->getId() !== $folderId);
		uasort($siblings, static fn (Folder $a, Folder $b) => self::compare($mode, $a, $b));
		$order = array_keys($siblings);
		$index = array_search($targetId, $order, true);
		$insert = $position === 'before' ? $index : $index + 1;
		array_splice($order, $insert, 0, [$folderId]);
		$this->tx->run(function () use ($order, $byId, $folder, $folderId, $parent) {
			foreach ($order as $i => $fid) {
				$f = $fid === $folderId ? $folder : $byId[$fid];
				$f->setDisplayOrder($i + 1);
				if ($fid === $folderId) {
					$f->setParentId($parent);
				}
				$this->folders->update($f);
			}
		});
		$this->settings->set($uid, 'note_list_sort', 'manual');
		return ['message' => 'Folder reordered successfully', 'folder' => [
			'id' => $folderId,
			'name' => $folder->getName(),
			'workspace' => $this->workspaces->keyFor($uid, $wsId),
			'parent_id' => $parent,
			'position' => $position,
			'target_folder_id' => $targetId,
		]];
	}

	public function empty(string $uid, int $id, mixed $wsKey): int {
		$folder = $this->access->requireFolder($uid, $id, 'write');
		$this->checkWorkspaceParam($uid, $folder, $wsKey);
		$now = $this->time->getTime();
		$count = 0;
		foreach ($this->notes->findList([(int)$folder->getWorkspaceId()], ['folder_id' => $id]) as $note) {
			$note->setTrashedAt($now);
			$this->notes->update($note);
			$count++;
		}
		return $count;
	}

	/** @return array{0: ?string, 1: ?string} */
	public function setIcon(string $uid, int $id, mixed $icon, mixed $iconColor): array {
		$folder = $this->access->requireFolder($uid, $id, 'write');
		$folder->setIcon($this->validator->icon($icon));
		$folder->setIconColor($this->validator->iconColor($iconColor));
		$this->folders->update($folder);
		return [$folder->getIcon(), $folder->getIconColor()];
	}

	public function setColor(string $uid, int $id, mixed $color): ?string {
		$value = $this->validator->color($color);
		$folder = $this->access->requireFolder($uid, $id, 'write');
		$folder->setColor($value);
		$this->folders->update($folder);
		return $value;
	}

	public function setFlag(string $uid, int $id, string $field, bool $value): void {
		$folder = $this->access->requireFolder($uid, $id, 'write');
		$field === 'pinned' ? $folder->setPinned($value ? 1 : 0) : $folder->setFavorite($value ? 1 : 0);
		$this->folders->update($folder);
	}

	/** @return array<string, int> */
	public function noteCount(string $uid, int $id, mixed $wsKey): array {
		$folder = $this->access->requireFolder($uid, $id, 'read');
		$this->checkWorkspaceParam($uid, $folder, $wsKey);
		$wsId = (int)$folder->getWorkspaceId();
		$ids = self::subtree($id, $this->byId($wsId));
		return [
			'count' => count($this->notes->findList([$wsId], ['folder_ids' => $ids])),
			'direct_count' => count($this->notes->findList([$wsId], ['folder_id' => $id])),
			'subfolder_count' => count($ids) - 1,
		];
	}

	/** @return array{path: string, depth: int} */
	public function path(string $uid, int $id, mixed $wsKey): array {
		$folder = $this->access->requireFolder($uid, $id, 'read');
		$this->checkWorkspaceParam($uid, $folder, $wsKey);
		$byId = $this->byId((int)$folder->getWorkspaceId());
		$parts = [];
		for ($cur = $id, $depth = 0; $cur !== null && isset($byId[$cur]) && $depth < 10; $depth++) {
			array_unshift($parts, $byId[$cur]->getName());
			$cur = $byId[$cur]->getParentId();
		}
		return ['path' => implode('/', $parts), 'depth' => $depth];
	}

	/** @return array<int|string, int> */
	public function counts(string $uid, mixed $wsKey): array {
		$ws = $this->workspaces->resolveOptional($uid, $wsKey);
		$wsIds = $ws !== null ? [(int)$ws->getId()] : $this->access->visibleWorkspaceIds($uid);
		$notes = $this->notes->findList($wsIds);
		$byFolder = [];
		$uncategorized = 0;
		$favorites = 0;
		foreach ($notes as $n) {
			if ($n->getFolderId() === null) {
				$uncategorized++;
			} else {
				$byFolder[(int)$n->getFolderId()] = ($byFolder[(int)$n->getFolderId()] ?? 0) + 1;
			}
			if ((int)$n->getFavorite() === 1) {
				$favorites++;
			}
		}
		$counts = [];
		foreach ($wsIds as $wsId) {
			$byId = $this->byId($wsId);
			foreach (array_keys($byId) as $fid) {
				$counts[$fid] = array_sum(array_map(static fn (int $i) => $byFolder[$i] ?? 0, self::subtree($fid, $byId)));
			}
		}
		$counts['uncategorized'] = $uncategorized;
		$counts['Favorites'] = $favorites;
		return $counts;
	}

	/** @return list<array{id: int, name: string}> */
	public function suggested(string $uid, mixed $wsKey): array {
		$ws = $this->workspaces->resolveOptional($uid, $wsKey);
		$wsIds = $ws !== null ? [(int)$ws->getId()] : $this->access->visibleWorkspaceIds($uid);
		$last = [];
		$count = [];
		foreach ($this->notes->findList($wsIds) as $n) {
			$fid = $n->getFolderId();
			if ($fid === null) {
				continue;
			}
			$last[$fid] = max($last[$fid] ?? 0, (int)$n->getUpdatedAt());
			$count[$fid] = ($count[$fid] ?? 0) + 1;
		}
		arsort($last);
		arsort($count);
		$ids = array_slice(array_keys($last), 0, 5);
		foreach (array_keys($count) as $fid) {
			if (count($ids) >= 5) {
				break;
			}
			if (!in_array($fid, $ids, true)) {
				$ids[] = $fid;
			}
		}
		$serializerName = function (int $fid): string {
			try {
				return $this->folders->find($fid)->getName();
			} catch (\OCP\AppFramework\Db\DoesNotExistException) {
				return 'Unknown';
			}
		};
		return array_map(static fn (int $fid) => ['id' => $fid, 'name' => $serializerName($fid)], $ids);
	}

	/** @return array<string, mixed> */
	public function moveFiles(string $uid, array $data): array {
		$sourceId = (int)($data['source_folder_id'] ?? 0);
		if (!isset($data['target_folder_id'])) {
			throw new BadRequestException('Target folder ID is required');
		}
		$targetId = (int)$data['target_folder_id'];
		if ($sourceId === 0) {
			throw new BadRequestException('Source folder ID is required');
		}
		if ($sourceId === $targetId) {
			throw new BadRequestException('Source and target folders cannot be the same');
		}
		$source = $this->access->requireFolder($uid, $sourceId, 'write');
		$sourceWs = $this->access->requireWorkspace($uid, (int)$source->getWorkspaceId(), 'write');
		$given = $this->workspaces->resolveOptional($uid, $data['workspace'] ?? null, 'write');
		if ($given !== null && $given->getId() !== $sourceWs->getId()) {
			throw new NotFoundException('Source folder not found');
		}
		$targetWs = $this->workspaces->resolveOptional($uid, $data['target_workspace'] ?? null, 'write') ?? $sourceWs;
		$target = null;
		if ($targetId !== 0) {
			$target = $this->access->requireFolder($uid, $targetId, 'write');
			if ($target->getWorkspaceId() !== $targetWs->getId()) {
				throw new BadRequestException('Target folder belongs to a different workspace');
			}
		}
		if ($targetWs->getId() !== $sourceWs->getId()) {
			$this->access->requireWorkspace($uid, $sourceWs, 'manage');
		}
		$notes = $this->notes->findList([(int)$sourceWs->getId()], ['folder_id' => $sourceId]);
		if ($notes === []) {
			throw new BadRequestException('No files found in source folder');
		}
		$this->tx->run(function () use ($notes, $target, $targetWs, $uid) {
			$now = $this->time->getTime();
			foreach ($notes as $note) {
				$note->setFolderId($target?->getId());
				$note->setWorkspaceId((int)$targetWs->getId());
				$note->setUpdatedAt($now);
				$note->setUpdatedBy($uid);
				$this->notes->update($note);
			}
		});
		return [
			'message' => 'Moved ' . count($notes) . ' files successfully',
			'moved_count' => count($notes),
			'share_delta' => 0,
			'target_workspace' => $this->workspaces->keyFor($uid, (int)$targetWs->getId()),
		];
	}

	/** @return array<string, mixed> */
	public function changeTags(string $uid, int $id, array $data, bool $add): array {
		$raw = $data['tags'] ?? null;
		$tags = $this->validator->noteTags(is_array($raw) ? $raw : preg_split('/[,\s]+/', (string)($raw ?? '')));
		if ($tags === []) {
			throw new BadRequestException('At least one tag is required');
		}
		$folder = $this->access->requireFolder($uid, $id, 'write');
		$ws = $this->workspaces->resolveOptional($uid, $data['workspace'] ?? null);
		if ($ws !== null && $ws->getId() !== $folder->getWorkspaceId()) {
			throw new BadRequestException('Folder belongs to a different workspace');
		}
		$include = !empty($data['include_subfolders']) && filter_var($data['include_subfolders'], FILTER_VALIDATE_BOOLEAN);
		$wsId = (int)$folder->getWorkspaceId();
		$ids = $include ? self::subtree($id, $this->byId($wsId)) : [$id];
		$notes = $this->notes->findList([$wsId], ['folder_ids' => $ids]);
		$noteIds = array_map(static fn (Note $n) => (int)$n->getId(), $notes);
		$lower = array_map('mb_strtolower', $tags);
		$updated = $this->tx->run(function () use ($noteIds, $tags, $lower, $add, $uid) {
			$current = $this->tags->tagsFor($noteIds);
			$changed = [];
			foreach ($noteIds as $nid) {
				$have = $current[$nid] ?? [];
				if ($add) {
					$new = array_values(array_diff($tags, $have));
					foreach ($new as $t) {
						$this->tags->add($nid, $t);
					}
				} else {
					$new = array_values(array_filter($have, static fn ($t) => in_array(mb_strtolower($t), $lower, true)));
					foreach ($new as $t) {
						$this->tags->remove($nid, $t);
					}
				}
				if ($new !== []) {
					$changed[] = $nid;
				}
			}
			if ($changed !== []) {
				$this->notes->touch($changed, $uid, $this->time->getTime());
			}
			return count($changed);
		});
		return [
			'message' => ($add ? 'Tagged ' : 'Untagged ') . $updated . ' notes',
			'tags' => $add ? $tags : $lower,
			'notes_count' => count($noteIds),
			'note_ids' => $noteIds,
			'updated_count' => $updated,
			'include_subfolders' => $include,
		];
	}

	// Archivieren: Ordnerzweig nach „Archives“ desselben Besitzers, gleichnamige Ordner werden zusammengeführt
	/** @return array{0: array<string, mixed>, 1: int} */
	public function archive(string $uid, int $id): array {
		$folder = $this->access->requireFolder($uid, $id, 'manage');
		$source = $this->access->requireWorkspace($uid, (int)$folder->getWorkspaceId(), 'manage');
		$sourceKey = $this->workspaces->keyFor($uid, (int)$source->getId());
		if ($this->workspaces->isOwnArchive($source)) {
			return [['already_archived' => true, 'workspace' => $sourceKey, 'message' => 'Folder is already archived'], 200];
		}
		$byId = $this->byId((int)$source->getId());
		$branch = self::subtree($id, $byId);
		$noteIds = array_map(static fn (Note $n) => (int)$n->getId(), $this->notes->findList([(int)$source->getId()], ['folder_ids' => $branch]));
		$parentPath = $this->ancestry($folder->getParentId(), $byId);
		return $this->tx->run(function () use ($uid, $folder, $id, $source, $sourceKey, $byId, $parentPath, $noteIds) {
			[$archive, $createdWs] = $this->workspaces->archiveFor($source);
			$created = [];
			$parentId = $this->mirrorPath($archive, $parentPath, $created);
			$conflicts = [];
			$emptied = [];
			$targetId = $this->mergeInto($id, $parentId, $archive, $byId, $conflicts, $emptied);
			if ($conflicts !== []) {
				throw new ConflictException('Some notes have the same title as a note already archived in the same folder', ['conflicts' => $conflicts]);
			}
			$this->folders->deleteByIds($emptied);
			$names = array_map(static fn (Folder $f) => $f->getName(), $parentPath);
			$names[] = $folder->getName();
			return [[
				'message' => 'Folder archived successfully',
				'workspace' => $this->workspaces->keyFor($uid, (int)$archive->getId()) ?? WorkspaceService::ARCHIVE_NAME,
				'workspace_created' => $createdWs,
				'old_workspace' => $sourceKey,
				'old_folder_id' => $id,
				'folder_id' => $targetId,
				'folder_path' => implode('/', $names),
				'merged' => $targetId !== $id,
				'created_folders' => $created,
				'note_ids' => $noteIds,
			], 200];
		});
	}

	/**
	 * Vorfahren von außen nach innen
	 * @param array<int, Folder> $byId
	 * @return Folder[]
	 */
	public function ancestry(?int $folderId, array $byId): array {
		$path = [];
		for ($cur = $folderId, $d = 0; $cur !== null && isset($byId[$cur]) && $d < 50; $d++) {
			array_unshift($path, $byId[$cur]);
			$cur = $byId[$cur]->getParentId();
		}
		return $path;
	}

	/**
	 * Pfad im Archiv nachbilden
	 * @param Folder[] $path
	 * @param list<array{id: int, name: string}> $created
	 */
	public function mirrorPath(Workspace $archive, array $path, array &$created): ?int {
		$parentId = null;
		foreach ($path as $seg) {
			$found = $this->folders->findSibling((int)$archive->getId(), $parentId, $seg->getName());
			if ($found !== null) {
				$parentId = (int)$found->getId();
				continue;
			}
			$new = $this->insertFolder((int)$archive->getId(), $parentId, $seg->getName(), [
				'icon' => $seg->getIcon(), 'icon_color' => $seg->getIconColor(), 'color' => $seg->getColor(),
			]);
			$parentId = (int)$new->getId();
			$created[] = ['id' => $parentId, 'name' => $seg->getName()];
		}
		return $parentId;
	}

	/**
	 * @param array<int, Folder> $byId
	 * @param string[] $conflicts
	 * @param int[] $emptied
	 */
	private function mergeInto(int $sourceId, ?int $archiveParent, Workspace $archive, array $byId, array &$conflicts, array &$emptied): int {
		$archiveId = (int)$archive->getId();
		$now = $this->time->getTime();
		$folder = $byId[$sourceId];
		$existing = $this->folders->findSibling($archiveId, $archiveParent, $folder->getName());
		if ($existing === null) {
			$branch = self::subtree($sourceId, $byId);
			$folder->setParentId($archiveParent);
			$folder->setDisplayOrder(0);
			$this->folders->update($folder);
			$this->folders->moveToWorkspace($branch, $archiveId, $now);
			$this->notes->moveFolderNotesToWorkspace($branch, $archiveId);
			return $sourceId;
		}
		$targetId = (int)$existing->getId();
		foreach ($this->notes->findList([(int)$folder->getWorkspaceId()], ['folder_id' => $sourceId, 'trashed' => null]) as $note) {
			if ($note->getTrashedAt() === null && $this->notes->headingExists($archiveId, $targetId, (string)$note->getHeading())) {
				$conflicts[] = (string)$note->getHeading();
			}
			$note->setFolderId($targetId);
			$note->setWorkspaceId($archiveId);
			$this->notes->update($note);
		}
		foreach ($byId as $cid => $child) {
			if ($child->getParentId() === $sourceId) {
				$this->mergeInto($cid, $targetId, $archive, $byId, $conflicts, $emptied);
			}
		}
		$emptied[] = $sourceId;
		return $targetId;
	}
}
