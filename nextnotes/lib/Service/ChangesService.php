<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service;

use OCA\NextNotes\Db\AttachmentMapper;
use OCA\NextNotes\Db\FolderMapper;
use OCA\NextNotes\Db\NoteMapper;
use OCA\NextNotes\Db\NoteTagMapper;
use OCA\NextNotes\Service\Exception\NotFoundException;

// Änderungs-Polling (Upstream Notes::changes); Notizen ohne Leserecht gelten als „missing“
class ChangesService {
	private const MAX_NOTES = 20;

	public function __construct(
		private NoteMapper $notes,
		private FolderMapper $folders,
		private NoteTagMapper $tags,
		private AttachmentMapper $attachments,
		private AccessService $access,
		private WorkspaceService $workspaces,
	) {
	}

	/** @return array<string, mixed> GET /changes */
	public function changes(string $uid, mixed $wsKey, mixed $noteIdsRaw): array {
		$ws = $this->workspaces->resolveOptional($uid, $wsKey) ?? $this->workspaces->defaultWorkspace($uid);
		$ids = [];
		foreach (explode(',', is_scalar($noteIdsRaw) ? (string)$noteIdsRaw : '') as $raw) {
			$raw = trim($raw);
			if ($raw !== '' && ctype_digit($raw)) {
				$ids[(int)$raw] = true;
			}
		}
		$ids = array_slice(array_keys($ids), 0, self::MAX_NOTES);
		$readable = [];
		foreach ($ids as $id) {
			try {
				$readable[$id] = $this->access->requireNote($uid, $id, 'read', null);
			} catch (NotFoundException) {
			}
		}
		$tags = $this->tags->tagsFor(array_keys($readable));
		$atts = $this->attachments->findForNotes(array_keys($readable));
		$notes = [];
		foreach ($ids as $id) {
			if (!isset($readable[$id])) {
				$notes[(string)$id] = ['version' => 'missing', 'content_version' => null, 'exists' => false, 'trash' => false];
				continue;
			}
			$n = $readable[$id];
			$contentVersion = NoteSerializer::version($n);
			$meta = implode("\x1f", [
				(string)$n->getType(), implode(',', $tags[$id] ?? []), (string)$n->getFolderId(), (string)$n->getWorkspaceId(),
				implode(',', array_map(static fn ($a) => (string)$a->getId(), $atts[$id] ?? [])),
				(string)$n->getIcon(), (string)$n->getIconColor(), (string)$n->getColor(), (string)$n->getFavorite(),
				(string)$n->getPinned(), (string)$n->getContentWidth(), (string)$n->getTitleColor(), (string)$n->getTitleSize(),
				(string)$n->getLinkedNoteId(), $n->getTrashedAt() !== null ? '1' : '0',
			]);
			$notes[(string)$id] = [
				'version' => md5($contentVersion . '|' . md5($meta)),
				'content_version' => $contentVersion,
				'exists' => true,
				'trash' => $n->getTrashedAt() !== null,
			];
		}
		return [
			'workspace' => $this->workspaces->keyFor($uid, (int)$ws->getId()),
			'tree_version' => $this->treeVersion($uid, (int)$ws->getId()),
			'notes' => (object)$notes,
		];
	}

	// Hash über alles, was die Seitenleiste zeigt (Workspace-Liste, Ordner, Notizzeilen ohne Inhalt)
	private function treeVersion(string $uid, int $wsId): string {
		$hash = hash_init('md5');
		$feed = static function (array $row) use ($hash): void {
			hash_update($hash, implode("\x1f", array_map(static fn ($v) => (string)$v, $row)) . "\x1e");
		};
		$keys = $this->workspaces->keys($uid);
		ksort($keys);
		foreach ($keys as $id => $key) {
			$feed([$id, $key]);
		}
		foreach ($this->folders->findInWorkspace($wsId) as $f) {
			$feed([$f->getId(), $f->getName(), $f->getParentId(), $f->getDisplayOrder(), $f->getPinned(), $f->getFavorite(),
				$f->getIcon(), $f->getIconColor(), $f->getColor(), $f->getKanbanEnabled(), $f->getSortSetting(), $f->getIsDiary()]);
		}
		$notes = $this->notes->findList([$wsId], ['trashed' => null]);
		$tags = $this->tags->tagsFor(array_map(static fn ($n) => (int)$n->getId(), $notes));
		foreach ($notes as $n) {
			$feed([$n->getId(), $n->getHeading(), $n->getFolderId(), $n->getTrashedAt() !== null ? 1 : 0, $n->getFavorite(),
				$n->getPinned(), $n->getDisplayOrder(), $n->getIcon(), $n->getIconColor(), $n->getColor(), $n->getType(),
				$n->getLinkedNoteId(), implode(',', $tags[(int)$n->getId()] ?? []), $n->getUpdatedAt()]);
		}
		return hash_final($hash);
	}
}
