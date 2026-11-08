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
use OCA\NextNotes\Db\Workspace;
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
	private function cloneNote(Note $src, ?int $wsId, ?int $folderId, string $uid, array &$created, ?int $assignmentId = null, ?int $submissionId = null): Note {
		$now = $this->time->getTime();
		$n = new Note();
		$n->setWorkspaceId($wsId);
		$n->setFolderId($folderId);
		$heading = (string)$src->getHeading();
		$n->setHeading($wsId === null ? ($heading === '' ? $this->l->t('New note') : $heading) : $this->uniqueHeading($wsId, $folderId, $heading));
		$n->setContent($src->getContent());
		$n->setType($src->getType() ?: 'note');
		// Vorlage (ohne Workspace) bzw. Kopie eines Schülers: tragen assignment_id (Quote beim Team, Plan M6 §2.3a)
		$n->setRole($assignmentId === null ? 'note' : ($submissionId === null ? 'template' : 'submission'));
		$n->setAssignmentId($assignmentId);
		$n->setSubmissionId($submissionId);
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
			$content = Pcre::replaceCallback(
				'#/api/v1/notes/' . $srcId . '/attachments/(\d++)(?![0-9])#',
				static fn ($m) => isset($map[(int)$m[1]]) ? '/api/v1/notes/' . $newId . '/attachments/' . $map[(int)$m[1]] : $m[0],
				(string)$n->getContent(),
			);
			// längere IDs können die Grenze sprengen: 413, Transaktion rollt zurück (B37)
			$this->validator->storable($content);
			$n->setContent($content);
			$this->notes->update($n);
		}
		return $n;
	}

	/**
	 * Vorlage einer Aufgabe (Plan M6 §2.3): eingefrorene Kopie ohne Workspace, Anhänge physisch kopiert, Inhalts-URLs umgeschrieben,
	 * Verweise auf andere Notizen bleiben (Q2). Läuft in der Transaktion des Aufrufers; Rechte und Quote prüft der Aufrufer
	 * @param int[] $created neue Notiz-IDs (Dateien bei Rollback über discardFiles entfernen)
	 */
	public function copyAsTemplate(Note $src, int $assignmentId, string $uid, array &$created): Note {
		return $this->cloneNote($src, null, null, $uid, $created, $assignmentId);
	}

	/**
	 * Kopie der Vorlage für einen Schüler (SPEC §9.1): im Aufgaben-Ordner seines Schüler-Workspace, Anhänge physisch kopiert.
	 * Läuft in der Transaktion des Austeilens nach enforce; Dateien bei Rollback über discardFiles der $created entfernen (Review M6-Plan r2 Hinweis 3)
	 * @param int[] $created
	 */
	public function copyAsSubmission(Note $template, int $wsId, int $folderId, int $assignmentId, int $submissionId, string $uid, array &$created): Note {
		return $this->cloneNote($template, $wsId, $folderId, $uid, $created, $assignmentId, $submissionId);
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
		// Q1: Material ohne Schreibrecht → Kopie in den eigenen privaten Workspace; ausdrückliches Ziel (folder_id) prüft der normale Weg
		$explicit = isset($in['folder_id']) && $in['folder_id'] !== '' && (int)$in['folder_id'] !== 0;
		if (!$explicit && $this->access->isMaterialReadOnly($uid, $src)) {
			return $this->copyMaterialToPrivate($uid, $src, $in);
		}
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
		$target = $this->access->requireWorkspace($uid, $wsId, 'write');
		// Kopie in einen anderen Workspace = Inhalt verlässt die Quelle (B17, Plan M5 §2.2); Vorlagen/Abgaben nie kopieren
		$source = $wsId === $src->getWorkspaceId() ? $target : $this->access->requireWorkspace($uid, (int)$src->getWorkspaceId(), 'read');
		$this->access->requireLeave($uid, new LeaveRequest($source, $target, LeaveRequest::COPY, [$id]));
		$quota = $this->attachments->quotaFor($wsId, $this->attachments->bytesOf([$id]));
		$created = [];
		$copy = $this->run(function () use ($src, $wsId, $folderId, $uid, $quota, &$created) {
			$this->attachments->enforceQuota($quota);
			return $this->cloneNote($src, $wsId, $folderId, $uid, $created);
		}, $created);
		return ['id' => (int)$copy->getId(), 'heading' => $copy->getHeading(), 'message' => 'Note duplicated successfully', 'share_delta' => 0];
	}

	/**
	 * Q1 (Thomas 2026-11-07): Kopie einer Material-Notiz in den privaten Workspace des Schülers (Wurzel, gehört ihm, Schüler-Quote).
	 * Kopie, keine Verschiebung: die Verlassen-Regel bleibt für alle anderen Wege unverändert.
	 * @return array<string, mixed>
	 */
	private function copyMaterialToPrivate(string $uid, Note $src, array $in): array {
		// Verknüpfung → Ziel; das Ziel muss selbst Material sein oder die Verlassen-Regel erfüllen (Review M6-Plan r2 Hinweis 2)
		$note = $this->access->resolveLinked($uid, $src);
		$target = $this->privateTarget($uid, $in['workspace'] ?? null);
		if ((int)$note->getId() !== (int)$src->getId() && !$this->access->isMaterialReadOnly($uid, $note)) {
			$this->access->requireLeave($uid, new LeaveRequest(
				$this->access->requireWorkspace($uid, (int)$note->getWorkspaceId(), 'read'), $target, LeaveRequest::COPY, [(int)$note->getId()]));
		}
		$wsId = (int)$target->getId();
		$quota = $this->attachments->quotaFor($wsId, $this->attachments->bytesOf([(int)$note->getId()]));
		$created = [];
		$copy = $this->run(function () use ($note, $wsId, $uid, $quota, &$created) {
			$this->attachments->enforceQuota($quota);
			return $this->cloneNote($note, $wsId, null, $uid, $created);
		}, $created);
		return [
			'id' => (int)$copy->getId(),
			'heading' => $copy->getHeading(),
			'message' => 'Note duplicated successfully',
			'share_delta' => 0,
			'workspace' => $this->workspaces->keyFor($uid, $wsId),
		];
	}

	// Eigener privater Workspace (owner_type=user, kind=normal) aus dem Parameter, sonst der Standard-Workspace; nie Team/geteilt/Aufgaben
	private function privateTarget(string $uid, mixed $key): Workspace {
		try {
			$ws = $this->workspaces->resolveOptional($uid, $key);
		} catch (NotFoundException) {
			$ws = null;
		}
		if ($ws !== null && $ws->getOwnerType() === 'user' && $ws->getOwnerId() === $uid && $ws->getKind() === 'normal') {
			return $ws;
		}
		return $this->workspaces->defaultWorkspace($uid);
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
		// Kopie im selben Workspace: nur Prüfung gegen systemverwaltete Inhalte (M6); Zweig mit Material nur Lehrkraft (kein Sammel-Kopieren)
		$wsEntity = $this->access->requireWorkspace($uid, $wsId, 'write');
		$this->access->requireNoMaterial($uid, $wsEntity, $tree);
		$this->access->requireLeave($uid, new LeaveRequest($wsEntity, $wsEntity, LeaveRequest::COPY, [], array_map(static fn (int $fid) => $byId[$fid], $tree)));
		$sourceNotes = $this->notes->findList([$wsId], ['folder_ids' => $tree], true);
		$byFolder = [];
		foreach ($sourceNotes as $n) {
			$byFolder[(int)$n->getFolderId()][] = $n;
		}
		$parentId = $folder->getParentId();
		$name = $this->validator->folderName($this->uniqueFolderName($wsId, $parentId, (string)$folder->getName()));
		$stats = ['notes' => 0, 'folders' => 0, 'pairs' => []];
		$quota = $this->attachments->quotaFor($wsId, $this->attachments->bytesOf(array_map(static fn (Note $n) => (int)$n->getId(), $sourceNotes)));
		$created = [];
		$newId = $this->run(function () use ($folder, $name, $parentId, $wsId, $byId, $byFolder, $uid, $quota, &$stats, &$created) {
			$this->attachments->enforceQuota($quota);
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
		// Lehrkraft kopiert Material im selben Team-Workspace: Kennzeichen bleibt
		$f->setMaterial((int)$src->getMaterial());
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
			$new = Pcre::replaceCallback('/([?&](?:amp;)?note=)(\d++)(?![0-9])|(data-note-id=")(\d++)(")/',
				static function ($m) use ($map) {
					if (($m[1] ?? '') !== '') {
						return $m[1] . ($map[(int)$m[2]] ?? $m[2]);
					}
					return $m[3] . ($map[(int)$m[4]] ?? $m[4]) . $m[5];
				}, $content);
			if ($new !== $content) {
				$this->validator->storable($new);
				$copy->setContent($new);
				$this->notes->update($copy);
			}
		}
	}
}
