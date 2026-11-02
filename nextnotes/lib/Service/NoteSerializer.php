<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service;

use OCA\NextNotes\Db\FolderMapper;
use OCA\NextNotes\Db\Note;
use OCA\NextNotes\Db\NoteTagMapper;
use OCP\AppFramework\Db\DoesNotExistException;

// Notizen im Upstream-JSON (NotesController::index/show)
class NoteSerializer {
	public const DEFAULT_ICON = 'lucide-file-text';

	/** @var array<int, ?string> Ordnername je ID */
	private array $folderNames = [];

	public function __construct(
		private WorkspaceService $workspaces,
		private FolderMapper $folders,
		private NoteTagMapper $tags,
		private InputValidator $validator,
		private ContentUrlRewriter $urls,
	) {
	}

	public static function date(?int $ts): ?string {
		return $ts === null ? null : gmdate('Y-m-d H:i:s', $ts);
	}

	// Upstream poznoteNoteVersion: md5(updated|heading|md5(content))
	public static function version(Note $note): string {
		return md5((string)self::date($note->getUpdatedAt()) . '|' . (string)($note->getHeading() ?? '') . '|' . md5((string)($note->getContent() ?? '')));
	}

	public function tagString(array $tags): string {
		return implode(', ', $tags);
	}

	public function folderName(?int $folderId): ?string {
		if ($folderId === null) {
			return null;
		}
		if (!array_key_exists($folderId, $this->folderNames)) {
			try {
				$this->folderNames[$folderId] = $this->folders->find($folderId)->getName();
			} catch (DoesNotExistException) {
				$this->folderNames[$folderId] = null;
			}
		}
		return $this->folderNames[$folderId];
	}

	/**
	 * Listeneintrag wie GET /notes
	 * @param Note[] $notes
	 * @return list<array<string, mixed>>
	 */
	public function listItems(string $uid, array $notes): array {
		$tags = $this->tags->tagsFor(array_map(static fn (Note $n) => (int)$n->getId(), $notes));
		$out = [];
		foreach ($notes as $n) {
			$id = (int)$n->getId();
			$out[] = [
				'id' => $id,
				'heading' => $n->getHeading() ?? '',
				'type' => $n->getType() ?: 'note',
				'tags' => $this->tagString($tags[$id] ?? []),
				'folder' => $this->folderName($n->getFolderId()),
				'folder_id' => $n->getFolderId(),
				'workspace' => $this->workspaces->keyFor($uid, (int)$n->getWorkspaceId()),
				'updated' => self::date($n->getUpdatedAt()),
				'created' => self::date($n->getCreatedAt()),
				'favorite' => (int)$n->getFavorite(),
				'icon' => $n->getIcon() ?: self::DEFAULT_ICON,
				'icon_color' => $n->getIconColor(),
				'color' => $n->getColor(),
				'content_width' => $n->getContentWidth(),
				'title_color' => $n->getTitleColor(),
				'title_size' => $n->getTitleSize(),
				'display_order' => (int)$n->getDisplayOrder(),
				'dashboard_order' => (int)$n->getDashboardOrder(),
				'color_hex' => $this->validator->colorHex($n->getColor()),
			];
		}
		return $out;
	}

	/** @return array<string, mixed> wie GET /notes/{id} */
	public function full(string $uid, Note $n, string $version): array {
		$id = (int)$n->getId();
		return [
			'id' => $id,
			'heading' => $n->getHeading() ?? '',
			'workspace' => $this->workspaces->keyFor($uid, (int)$n->getWorkspaceId()),
			'type' => $n->getType() ?: 'note',
			'tags' => $this->tagString($this->tags->tagsFor([$id])[$id] ?? []),
			'folder' => $this->folderName($n->getFolderId()),
			'folder_id' => $n->getFolderId(),
			'favorite' => (int)$n->getFavorite(),
			'linked_note_id' => $n->getLinkedNoteId(),
			'icon' => $n->getIcon() ?: self::DEFAULT_ICON,
			'icon_color' => $n->getIconColor(),
			'color' => $n->getColor(),
			'color_hex' => $this->validator->colorHex($n->getColor()),
			'created' => self::date($n->getCreatedAt()),
			'updated' => self::date($n->getUpdatedAt()),
			'version' => $version,
			// Erinnerungen ab M2c (nextnotes_reminders)
			'reminder_at' => null,
			'content_width' => $n->getContentWidth(),
			'title_color' => $n->getTitleColor(),
			'title_size' => $n->getTitleSize(),
			'display_order' => (int)$n->getDisplayOrder(),
			'dashboard_order' => (int)$n->getDashboardOrder(),
			'state_hash' => ($n->getClientStateHash() !== null && hash_equals((string)$n->getClientStateVersion(), $version)) ? $n->getClientStateHash() : null,
			'content' => $this->urls->toClient((string)($n->getContent() ?? '')),
		];
	}
}
