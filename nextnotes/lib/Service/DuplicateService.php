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
use OCA\NextNotes\Service\Exception\BadRequestException;
use OCA\NextNotes\Service\Exception\NotFoundException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;

// Duplizieren von Notizen und Ordnern inkl. physischer Anhangskopie (Upstream poznoteCloneNoteRecord, Folders::duplicate)
class DuplicateService {
	private const MAX_DEPTH = 50;

	public function __construct(
		private NoteMapper $notes,
		private FolderMapper $folders,
		private AccessService $access,
		private WorkspaceService $workspaces,
		private FolderService $folderService,
		private TagService $tags,
		private AttachmentService $attachments,
		private SearchTextNormalizer $search,
		private InputValidator $validator,
		private Transaction $tx,
		private ITimeFactory $time,
		private IL10N $l,
	) {
	}

	private function uniqueHeading(int $wsId, ?int $folderId, string $heading): string {
		$heading = $heading === '' ? $this->l->t('New note') : $heading;
		if (!$this->notes->headingExists($wsId, $folderId, $heading)) {
			return $heading;
		}
		for ($i = 1; ; $i++) {
			$candidate = $heading . ' (' . $i . ')';
			if (!$this->notes->headingExists($wsId, $folderId, $candidate)) {
				return $candidate;
			}
		}
	}

	private function uniqueFolderName(int $wsId, ?int $parentId, string $name): string {
		$candidate = $name;
		for ($i = 1; $this->folders->findSibling($wsId, $parentId, $candidate) !== null; $i++) {
			$candidate = $name . ' (' . $i . ')';
		}
		return $candidate;
	}

	/**
	 * Eine Notiz kopieren (läuft in einer Transaktion des Aufrufers)
	 * @param int[] $created neue Notiz-IDs (Aufräumen bei Rollback)
	 */
	private function cloneNote(Note $src, int $wsId, ?int $folderId, string $uid, array &$created): Note {
		$now = $this->time->getTime();
		$n = new Note();
		$n->setWorkspaceId($wsId);
		$n->setFolderId($folderId);
		$n->setHeading($this->uniqueHeading($wsId, $folderId, (string)$src->getHeading()));
		$n->setContent($src->getContent());
		$n->setType($src->getType() ?: 'note');
		$n->setRole('note');
		foreach (['Icon', 'IconColor', 'Color', 'ContentWidth', 'TitleColor', 'TitleSize'] as $f) {
			$n->{'set' . $f}($src->{'get' . $f}());
		}
		foreach (['Favorite', 'Pinned', 'DisplayOrder', 'DashboardOrder', 'SpeedDialOrder', 'KanbanOrder'] as $f) {
			$n->{'set' . $f}(0);
		}
		$n->setCreatedBy($uid);
		$n->setUpdatedBy($uid);
		$n->setCreatedAt($now);
		$n->setUpdatedAt($now);
		$n->setSearchText($this->search->build($n->getHeading(), $n->getContent(), (string)$n->getType()));
		$n = $this->notes->insert($n);
		$newId = (int)$n->getId();
		$created[] = $newId;
		$this->tags->replace($newId, $this->tags->tagsOf((int)$src->getId()));
		$map = $this->attachments->copyAll((int)$src->getId(), $newId, $uid);
		if ($map !== []) {
			$srcId = (int)$src->getId();
			$content = preg_replace_callback(
				'#/api/v1/notes/' . $srcId . '/attachments/(\d+)(?![0-9])#',
				static fn ($m) => isset($map[(int)$m[1]]) ? '/api/v1/notes/' . $newId . '/attachments/' . $map[(int)$m[1]] : $m[0],
				(string)$n->getContent(),
			) ?? (string)$n->getContent();
			$n->setContent($content);
			$this->notes->update($n);
		}
		return $n;
	}

	/** @param int[] $created */
	private function run(callable $fn, array &$created): mixed {
		try {
			return $this->tx->run($fn);
		} catch (\Throwable $e) {
			foreach ($created as $id) {
				$this->attachments->discardFiles($id);
			}
			throw $e;
		}
	}

	/** @return array<string, mixed> POST /notes/{id}/duplicate (200 wie Upstream) */
	public function duplicateNote(string $uid, int $id, array $in): array {
		$src = $this->access->requireNote($uid, $id, 'read');
		$wsId = (int)$src->getWorkspaceId();
		$folderId = $src->getFolderId();
		if (array_key_exists('folder_id', $in)) {
			$raw = $in['folder_id'];
			$folderId = ($raw === null || $raw === '' || (int)$raw === 0) ? null : (int)$raw;
			if ($folderId !== null) {
				try {
					$folder = $this->access->requireFolder($uid, $folderId, 'write');
				} catch (NotFoundException) {
					throw new NotFoundException('Target folder not found');
				}
				$ws = $this->workspaces->resolveOptional($uid, $in['workspace'] ?? null);
				if ($ws !== null && $ws->getId() !== $folder->getWorkspaceId()) {
					throw new NotFoundException('Target folder not found');
				}
				$wsId = (int)$folder->getWorkspaceId();
			}
		}
		$this->access->requireWorkspace($uid, $wsId, 'write');
		// Kopie in einen anderen Workspace = Inhalt verlässt die Quelle (B17)
		if ($wsId !== $src->getWorkspaceId()) {
			$this->access->requireWorkspace($uid, (int)$src->getWorkspaceId(), 'manage');
		}
		$created = [];
		$copy = $this->run(function () use ($src, $id, $wsId, $folderId, $uid, &$created) {
			$this->attachments->assertQuota($wsId, $this->attachments->bytesOf([$id]));
			return $this->cloneNote($src, $wsId, $folderId, $uid, $created);
		}, $created);
		return ['id' => (int)$copy->getId(), 'heading' => $copy->getHeading(), 'message' => 'Note duplicated successfully', 'share_delta' => 0];
	}

	/** @return array<string, mixed> POST /folders/{id}/duplicate (201) */
	public function duplicateFolder(string $uid, int $id, mixed $wsKey): array {
		$folder = $this->access->requireFolder($uid, $id, 'write');
		$wsId = (int)$folder->getWorkspaceId();
		$ws = $this->workspaces->resolveOptional($uid, $wsKey);
		if ($ws !== null && $ws->getId() !== $wsId) {
			throw new NotFoundException('Folder not found');
		}
		$byId = $this->folderService->byId($wsId);
		$tree = FolderService::subtree($id, $byId);
		$sourceNotes = $this->notes->findList([$wsId], ['folder_ids' => $tree], true);
		$byFolder = [];
		foreach ($sourceNotes as $n) {
			$byFolder[(int)$n->getFolderId()][] = $n;
		}
		$parentId = $folder->getParentId();
		$name = $this->validator->folderName($this->uniqueFolderName($wsId, $parentId, (string)$folder->getName()));
		$stats = ['notes' => 0, 'folders' => 0, 'pairs' => []];
		$created = [];
		$newId = $this->run(function () use ($folder, $name, $parentId, $wsId, $byId, $byFolder, $sourceNotes, $uid, &$stats, &$created) {
			$this->attachments->assertQuota($wsId, $this->attachments->bytesOf(array_map(static fn (Note $n) => (int)$n->getId(), $sourceNotes)));
			$newId = $this->copyTree($folder, $name, $parentId, $wsId, $byId, $byFolder, $uid, $stats, $created, 0);
			$this->rewriteLinks($stats['pairs']);
			return $newId;
		}, $created);
		return [
			'message' => 'Folder duplicated successfully',
			'folder' => ['id' => $newId, 'name' => $name, 'workspace' => $this->workspaces->keyFor($uid, $wsId), 'parent_id' => $parentId],
			'folder_id' => $newId,
			'folder_name' => $name,
			'source_folder_id' => $id,
			'notes_count' => $stats['notes'],
			'subfolders_count' => $stats['folders'] - 1,
		];
	}

	/**
	 * @param array<int, Folder> $byId
	 * @param array<int, Note[]> $byFolder
	 * @param int[] $created
	 */
	private function copyTree(Folder $src, string $name, ?int $parentId, int $wsId, array $byId, array $byFolder, string $uid, array &$stats, array &$created, int $depth): int {
		if ($depth > self::MAX_DEPTH) {
			throw new BadRequestException('Folder hierarchy too deep');
		}
		$now = $this->time->getTime();
		$f = new Folder();
		$f->setWorkspaceId($wsId);
		$f->setParentId($parentId);
		$f->setName($name);
		foreach (['Icon', 'IconColor', 'Color', 'SortSetting'] as $field) {
			$f->{'set' . $field}($src->{'get' . $field}());
		}
		$f->setKanbanEnabled((int)$src->getKanbanEnabled());
		$f->setDisplayOrder((int)$src->getDisplayOrder());
		$f->setPinned(0);
		$f->setFavorite(0);
		$f->setIsDiary(0);
		$f->setCreatedAt($now);
		$f->setUpdatedAt($now);
		$f = $this->folders->insert($f);
		$newId = (int)$f->getId();
		$stats['folders']++;
		foreach ($byFolder[(int)$src->getId()] ?? [] as $note) {
			$copy = $this->cloneNote($note, $wsId, $newId, $uid, $created);
			$stats['notes']++;
			$stats['pairs'][(int)$note->getId()] = $copy;
		}
		foreach ($byId as $sub) {
			if ($sub->getParentId() === (int)$src->getId()) {
				$this->copyTree($sub, (string)$sub->getName(), $newId, $wsId, $byId, $byFolder, $uid, $stats, $created, $depth + 1);
			}
		}
		return $newId;
	}

	/**
	 * Interne Links der Kopien auf die Kopien umbiegen (?note=<id>, data-note-id="<id>")
	 * @param array<int, Note> $pairs alte ID → Kopie
	 */
	private function rewriteLinks(array $pairs): void {
		if ($pairs === []) {
			return;
		}
		$map = array_map(static fn (Note $n) => (int)$n->getId(), $pairs);
		foreach ($pairs as $copy) {
			$content = (string)$copy->getContent();
			$new = preg_replace_callback('/([?&](?:amp;)?note=)(\d+)(?![0-9])|(data-note-id=")(\d+)(")/',
				static function ($m) use ($map) {
					if (($m[1] ?? '') !== '') {
						return $m[1] . ($map[(int)$m[2]] ?? $m[2]);
					}
					return $m[3] . ($map[(int)$m[4]] ?? $m[4]) . $m[5];
				}, $content) ?? $content;
			if ($new !== $content) {
				$copy->setContent($new);
				$this->notes->update($copy);
			}
		}
	}
}
