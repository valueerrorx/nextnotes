<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service;

use OCA\NextNotes\Db\NoteMapper;
use OCA\NextNotes\Db\NoteTagMapper;
use OCA\NextNotes\Service\Exception\BadRequestException;
use OCA\NextNotes\Service\Exception\NotFoundException;
use OCP\AppFramework\Utility\ITimeFactory;

// Tags (nextnotes_note_tags); API liefert weiter kommagetrennten Text
class TagService {
	// Interner Tag der Speed-Dial-Ansicht (Upstream POZNOTE_SPEED_DIAL_TAG); in Tag-Listen und -Anzeigen ausgeblendet (B51)
	public const SPEED_DIAL = 'speed-dial';

	public static function isInternal(string $tag): bool {
		return mb_strtolower(trim($tag)) === self::SPEED_DIAL;
	}

	public function __construct(
		private NoteTagMapper $tags,
		private NoteMapper $notes,
		private AccessService $access,
		private WorkspaceService $workspaces,
		private InputValidator $validator,
		private Transaction $tx,
		private ITimeFactory $time,
	) {
	}

	/** @return string[] */
	public function tagsOf(int $noteId): array {
		return $this->tags->tagsFor([$noteId])[$noteId] ?? [];
	}

	/** @param string[] $tags bereits geprüft */
	public function replace(int $noteId, array $tags): void {
		$this->tags->deleteForNotes([$noteId]);
		foreach ($tags as $tag) {
			$this->tags->add($noteId, $tag);
		}
	}

	/** @param string[] $tags */
	public function add(int $noteId, array $tags): void {
		foreach ($tags as $tag) {
			$this->tags->add($noteId, $tag);
		}
	}

	/** @return string[] GET /tags: sichtbare Workspaces oder einer */
	public function index(string $uid, mixed $workspaceKey): array {
		$ws = $this->workspaces->resolveOptional($uid, $workspaceKey);
		$ids = $ws !== null ? [(int)$ws->getId()] : $this->access->visibleWorkspaceIds($uid);
		$list = $ids === [] ? [] : array_filter($this->tags->findInWorkspaces($ids), static fn ($t) => !self::isInternal((string)$t));
		sort($list, SORT_NATURAL | SORT_FLAG_CASE);
		return array_values($list);
	}

	// Wirkt auf Notizen in Workspaces mit Schreibrecht (Plan O13)
	/** @return int[] */
	private function scope(string $uid, mixed $workspaceKey): array {
		$ws = $this->workspaces->resolveOptional($uid, $workspaceKey, 'write');
		return $ws !== null ? [(int)$ws->getId()] : $this->access->writableWorkspaceIds($uid);
	}

	public function rename(string $uid, string $tag, mixed $newName, mixed $workspaceKey): int {
		if (!is_scalar($newName) || trim((string)$newName) === '') {
			throw new BadRequestException('new_name is required');
		}
		$newName = $this->validator->tagName((string)$newName);
		$ids = $this->scope($uid, $workspaceKey);
		$noteIds = $ids === [] ? [] : $this->tags->findInWorkspaces($ids, $tag);
		if ($noteIds === []) {
			throw new NotFoundException('Tag not found: ' . $tag);
		}
		if ($newName === $tag) {
			return 0;
		}
		$this->tx->run(function () use ($noteIds, $tag, $newName, $uid) {
			$current = $this->tags->tagsFor($noteIds);
			foreach ($noteIds as $id) {
				$list = array_map(static fn (string $t) => $t === $tag ? $newName : $t, $current[$id] ?? []);
				$this->replace($id, array_values(array_unique($list)));
			}
			$this->notes->touch($noteIds, $uid, $this->time->getTime());
		});
		return count($noteIds);
	}

	public function delete(string $uid, string $tag, mixed $workspaceKey): int {
		$ids = $this->scope($uid, $workspaceKey);
		$noteIds = $ids === [] ? [] : $this->tags->findInWorkspaces($ids, $tag);
		if ($noteIds === []) {
			throw new NotFoundException('Tag not found: ' . $tag);
		}
		$this->tx->run(function () use ($noteIds, $tag, $uid) {
			foreach ($noteIds as $id) {
				$this->tags->remove($id, $tag);
			}
			$this->notes->touch($noteIds, $uid, $this->time->getTime());
		});
		return count($noteIds);
	}
}
