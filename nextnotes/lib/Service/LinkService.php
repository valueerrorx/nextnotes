<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service;

use OCA\NextNotes\Db\FolderMapper;
use OCA\NextNotes\Db\Note;
use OCA\NextNotes\Db\NoteMapper;
use OCA\NextNotes\Db\NoteTagMapper;
use OCA\NextNotes\Service\Exception\NotFoundException;

// Indirekte Lesepfade: Backlinks und Graph (Upstream BacklinksController, GraphController), nur sichtbare Workspaces
class LinkService {
	private const TYPES = ['note', 'markdown', 'tasklist'];

	public function __construct(
		private NoteMapper $notes,
		private FolderMapper $folders,
		private NoteTagMapper $tags,
		private AccessService $access,
		private WorkspaceService $workspaces,
	) {
	}

	/** @return array{backlinks: list<array<string, mixed>>, count: int} GET /notes/{id}/backlinks */
	public function backlinks(string $uid, int $id, mixed $wsKey, bool $allWorkspaces): array {
		$target = $this->access->requireNote($uid, $id, 'read');
		// Vorlage/Abgabe: nie verlinkbar (nur Direktzugriff), Editor fragt mit Seiten-Workspace (M6g2)
		if ($target->getRole() !== 'note') {
			return ['backlinks' => [], 'count' => 0];
		}
		$ws = $this->workspaces->resolveOptional($uid, $wsKey);
		if ($ws !== null && $ws->getId() !== $target->getWorkspaceId()) {
			throw new NotFoundException('Note not found');
		}
		$wsIds = ($ws !== null && !$allWorkspaces) ? [(int)$ws->getId()] : $this->access->visibleWorkspaceIds($uid);
		$heading = (string)$target->getHeading();
		$needles = ['data-note-id="' . $id . '"', 'note=' . $id];
		if ($heading !== '') {
			$needles[] = '[[' . $heading . ']]';
		}
		$candidates = $this->notes->findList($wsIds, ['types' => self::TYPES, 'exclude_id' => $id, 'content_any' => $needles], true);
		$out = [];
		foreach ($candidates as $n) {
			$content = (string)$n->getContent();
			$found = str_contains($content, 'data-note-id="' . $id . '"')
				|| preg_match('/[?&]note=' . $id . '(?:[^0-9]|$)/', $content)
				|| ($heading !== '' && $n->getWorkspaceId() === $target->getWorkspaceId() && str_contains($content, '[[' . $heading . ']]'));
			if ($found) {
				$out[] = [
					'id' => (int)$n->getId(),
					'heading' => (string)$n->getHeading() !== '' ? (string)$n->getHeading() : 'Untitled',
					'workspace' => (string)$this->workspaces->keyFor($uid, (int)$n->getWorkspaceId()),
				];
			}
		}
		usort($out, static fn ($a, $b) => strcasecmp($a['heading'], $b['heading']));
		return ['backlinks' => $out, 'count' => count($out)];
	}

	/** @return array<string, mixed> GET /graph */
	public function graph(string $uid, mixed $wsKey, mixed $limitRaw): array {
		$ws = $this->workspaces->resolveOptional($uid, $wsKey);
		$wsIds = $ws !== null ? [(int)$ws->getId()] : $this->access->visibleWorkspaceIds($uid);
		$limit = is_scalar($limitRaw) ? max(0, (int)$limitRaw) : 0;
		$rows = $this->notes->findList($wsIds, ['types' => self::TYPES], true);
		$folders = [];
		foreach ($this->folders->findInWorkspaces($wsIds) as $f) {
			$folders[(int)$f->getId()] = [
				'id' => (int)$f->getId(),
				'name' => (string)$f->getName(),
				'parent_id' => $f->getParentId(),
			] + self::icon($f->getIcon(), $f->getIconColor(), 'lucide-folder');
		}
		$headingMap = [];
		foreach ($rows as $n) {
			$key = mb_strtolower((string)$n->getHeading());
			if ($key !== '' && !isset($headingMap[$key])) {
				$headingMap[$key] = (int)$n->getId();
			}
		}
		$total = count($rows);
		if ($limit > 0 && $total > $limit) {
			$byDate = $rows;
			usort($byDate, static fn (Note $a, Note $b) => ((int)$b->getUpdatedAt() <=> (int)$a->getUpdatedAt()) ?: ((int)$b->getId() <=> (int)$a->getId()));
			$kept = array_flip(array_map(static fn (Note $n) => (int)$n->getId(), array_slice($byDate, 0, $limit)));
			$rows = array_values(array_filter($rows, static fn (Note $n) => isset($kept[(int)$n->getId()])));
		}
		$tags = $this->tags->tagsFor(array_map(static fn (Note $n) => (int)$n->getId(), $rows));
		$nodes = [];
		$idSet = [];
		foreach ($rows as $n) {
			$id = (int)$n->getId();
			$fid = $n->getFolderId();
			$fid = ($fid !== null && isset($folders[$fid])) ? $fid : null;
			$type = $n->getType() ?: 'note';
			$nodes[] = [
				'id' => $id,
				'title' => (string)$n->getHeading() !== '' ? (string)$n->getHeading() : 'Untitled',
				'folder' => $fid !== null ? $folders[$fid]['name'] : '',
				'folder_id' => $fid,
				'type' => $type,
				'favorite' => (int)$n->getFavorite() === 1,
				'tags' => array_values(array_unique($tags[$id] ?? [])),
			] + self::icon($n->getIcon(), $n->getIconColor(), match ($type) {
				'tasklist' => 'lucide-list-todo',
				'markdown' => 'lucide-file-code',
				default => 'lucide-file-text',
			});
			$idSet[$id] = true;
		}
		$edges = [];
		$seen = [];
		foreach ($rows as $n) {
			$source = (int)$n->getId();
			$content = (string)$n->getContent();
			if ($content === '') {
				continue;
			}
			$targets = [];
			if (preg_match_all('/data-note-id="(\d+)"/', $content, $m)) {
				foreach ($m[1] as $t) {
					$targets[(int)$t] = true;
				}
			}
			if (preg_match_all('/[?&]note=(\d+)/', $content, $m)) {
				foreach ($m[1] as $t) {
					$targets[(int)$t] = true;
				}
			}
			if (preg_match_all('/\[\[([^\[\]]+)\]\]/', $content, $m)) {
				foreach ($m[1] as $title) {
					$key = mb_strtolower(trim($title));
					if ($key !== '' && isset($headingMap[$key])) {
						$targets[$headingMap[$key]] = true;
					}
				}
			}
			// Nur Kanten zwischen sichtbaren Knoten
			foreach (array_keys($targets) as $target) {
				if ($target === $source || !isset($idSet[$target]) || isset($seen[$source . '>' . $target])) {
					continue;
				}
				$seen[$source . '>' . $target] = true;
				$edges[] = ['source' => $source, 'target' => $target];
			}
		}
		return ['nodes' => $nodes, 'edges' => $edges, 'folders' => self::foldersHoldingNotes($folders, $nodes), 'total' => $total];
	}

	/** @return array{icon: ?string, icon_color: ?string} */
	private static function icon(?string $icon, ?string $color, string $default): array {
		$icon = preg_match('/lucide-[a-z0-9-]+/', (string)$icon, $m) ? $m[0] : null;
		$color = trim((string)$color) !== '' ? trim((string)$color) : null;
		if ($icon === null && $color === null) {
			return ['icon' => null, 'icon_color' => null];
		}
		return ['icon' => $icon ?? $default, 'icon_color' => $color];
	}

	private static function foldersHoldingNotes(array $folders, array $nodes): array {
		$kept = [];
		foreach ($nodes as $node) {
			$fid = $node['folder_id'];
			while ($fid !== null && isset($folders[$fid]) && !isset($kept[$fid])) {
				$kept[$fid] = true;
				$fid = $folders[$fid]['parent_id'];
			}
		}
		$out = [];
		foreach ($folders as $id => $f) {
			if (!isset($kept[$id])) {
				continue;
			}
			if ($f['parent_id'] !== null && !isset($kept[$f['parent_id']])) {
				$f['parent_id'] = null;
			}
			$out[] = $f;
		}
		return $out;
	}
}
