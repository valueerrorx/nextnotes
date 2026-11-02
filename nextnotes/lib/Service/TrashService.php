<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service;

use OCA\NextNotes\Db\Note;
use OCA\NextNotes\Db\NoteMapper;
use OCA\NextNotes\Db\NoteTagMapper;

// Papierkorb (Upstream TrashController)
class TrashService {
	public function __construct(
		private NoteMapper $notes,
		private NoteTagMapper $tags,
		private AccessService $access,
		private WorkspaceService $workspaces,
		private NoteService $noteService,
		private NoteSerializer $serializer,
		private SearchTextNormalizer $search,
	) {
	}

	/** @return array<string, mixed> */
	public function index(string $uid, mixed $wsKey, mixed $search): array {
		$ws = $this->workspaces->resolveOptional($uid, $wsKey);
		$wsIds = $ws !== null ? [(int)$ws->getId()] : $this->access->visibleWorkspaceIds($uid);
		$all = $this->notes->findList($wsIds, ['trashed' => true]);
		usort($all, static fn (Note $a, Note $b) => ((int)$b->getUpdatedAt() <=> (int)$a->getUpdatedAt()) ?: ((int)$b->getId() <=> (int)$a->getId()));
		$tagMap = $this->tags->tagsFor(array_map(static fn (Note $n) => (int)$n->getId(), $all));
		$term = is_scalar($search) ? $this->search->fold((string)$search) : '';
		$out = [];
		foreach ($all as $n) {
			$tags = $tagMap[(int)$n->getId()] ?? [];
			if ($term !== '' && !str_contains($this->search->fold((string)$n->getHeading()), $term)
				&& !str_contains($this->search->fold(implode(',', $tags)), $term)) {
				continue;
			}
			$out[] = [
				'id' => (int)$n->getId(),
				'heading' => $n->getHeading(),
				'tags' => $this->serializer->tagString($tags),
				'folder' => $this->serializer->folderName($n->getFolderId()),
				'folder_id' => $n->getFolderId(),
				'workspace' => $this->workspaces->keyFor($uid, (int)$n->getWorkspaceId()),
				'type' => $n->getType(),
				'updated' => NoteSerializer::date($n->getUpdatedAt()),
				'created' => NoteSerializer::date($n->getCreatedAt()),
			];
		}
		return [
			'total' => count($all),
			'count' => count($out),
			'notes' => $out,
			'filters' => ['workspace' => is_string($wsKey) && $wsKey !== '' ? $wsKey : null, 'search' => is_string($search) && $search !== '' ? $search : null],
		];
	}

	// Leeren wirkt nur in Workspaces mit Schreibrecht
	public function empty(string $uid, mixed $wsKey): int {
		$ws = $this->workspaces->resolveOptional($uid, $wsKey, 'write');
		$wsIds = $ws !== null ? [(int)$ws->getId()] : $this->access->writableWorkspaceIds($uid);
		$ids = array_map(static fn (Note $n) => (int)$n->getId(), $this->notes->findList($wsIds, ['trashed' => true]));
		$this->noteService->purge($ids);
		return count($ids);
	}

	public function destroy(string $uid, int $id, mixed $wsKey): void {
		$note = $this->access->requireNote($uid, $id, 'write', true);
		$ws = $this->workspaces->resolveOptional($uid, $wsKey);
		if ($ws !== null && $ws->getId() !== $note->getWorkspaceId()) {
			throw new Exception\NotFoundException('Note not found');
		}
		$this->noteService->purge([$id]);
	}
}
