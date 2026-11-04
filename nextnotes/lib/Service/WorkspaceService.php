<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service;

use OCA\NextNotes\AppInfo\Application;
use OCA\NextNotes\Db\Folder;
use OCA\NextNotes\Db\FolderMapper;
use OCA\NextNotes\Db\MentionHandledMapper;
use OCA\NextNotes\Db\NoteMapper;
use OCA\NextNotes\Db\ReminderMapper;
use OCA\NextNotes\Db\Workspace;
use OCA\NextNotes\Db\WorkspaceMapper;
use OCA\NextNotes\Db\WsShareMapper;
use OCA\NextNotes\Service\Exception\BadRequestException;
use OCA\NextNotes\Service\Exception\ConflictException;
use OCA\NextNotes\Service\Exception\NotFoundException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use OCP\L10N\IFactory;

// Workspaces, Schlüssel (SPEC §4.4), Standard-Workspace, Freigaben (Service-Weg)
class WorkspaceService {
	public const ARCHIVE_NAME = 'Archives';

	/** @var array<string, array<int, string>> uid → [wsId → Schlüssel] */
	private array $keys = [];
	/** @var array<string, array<int, Workspace>> uid → sichtbare Workspaces */
	private array $visible = [];

	public function __construct(
		private WorkspaceMapper $workspaces,
		private WsShareMapper $shares,
		private FolderMapper $folders,
		private NoteMapper $notes,
		private AccessService $access,
		private InputValidator $validator,
		private Transaction $tx,
		private ITimeFactory $time,
		private IUserManager $userManager,
		private IFactory $l10nFactory,
		private ReminderMapper $reminders,
		private MentionHandledMapper $mentions,
		private BackgroundFiles $backgrounds,
		private SettingsService $settings,
	) {
	}

	public function reset(): void {
		$this->keys = [];
		$this->visible = [];
		$this->access->resetCache();
	}

	// Erster Zugriff ohne eigenen Workspace: „Notizen“ in Benutzersprache (Thomas, O5)
	public function ensureDefault(string $uid): void {
		if ($this->workspaces->findOwned($uid) !== []) {
			return;
		}
		$name = $this->defaultName($uid);
		$mine = $this->insert($uid, $name, 'normal', [], null);
		// Wettlauf paralleler Erstzugriffe: kleinste ID gewinnt
		$all = $this->workspaces->findOwned($uid, 'normal', $name);
		$ids = array_map(static fn (Workspace $w) => (int)$w->getId(), $all);
		if (count($ids) > 1 && min($ids) !== (int)$mine->getId()) {
			$this->workspaces->deleteById((int)$mine->getId());
		}
		$this->reset();
	}

	// Name des Standard-Workspace in der Benutzersprache
	private function defaultName(string $uid): string {
		$l = $this->l10nFactory->get(Application::APP_ID, $this->l10nFactory->getUserLanguage($this->userManager->get($uid)));
		return $l->t('Notes');
	}

	private function insert(string $uid, string $name, string $kind, array $tags, ?string $color): Workspace {
		$now = $this->time->getTime();
		$ws = new Workspace();
		$ws->setOwnerType('user');
		$ws->setOwnerId($uid);
		$ws->setKind($kind);
		$ws->setName($name);
		$ws->setTags($tags === [] ? null : implode(',', $tags));
		$ws->setColor($color);
		$ws->setDisplayOrder(0);
		$ws->setCreatedBy($uid);
		$ws->setCreatedAt($now);
		$ws->setUpdatedAt($now);
		return $this->workspaces->insert($ws);
	}

	/** @return Workspace[] sichtbare Workspaces: eigene (Reihenfolge), dann geteilte nach Name */
	public function listVisible(string $uid): array {
		if (!isset($this->visible[$uid])) {
			$own = $this->workspaces->findOwned($uid);
			$shared = $this->workspaces->findByIds(array_keys($this->workspaces->findSharedWith($uid)));
			usort($shared, static fn (Workspace $a, Workspace $b) => strnatcasecmp($a->getName(), $b->getName()) ?: $a->getId() <=> $b->getId());
			$list = [];
			foreach (array_merge($own, $shared) as $ws) {
				$list[(int)$ws->getId()] = $ws;
			}
			$this->visible[$uid] = $list;
		}
		return array_values($this->visible[$uid]);
	}

	/** @return array<int, string> wsId → Schlüssel (deterministisch, eindeutig) */
	public function keys(string $uid): array {
		if (isset($this->keys[$uid])) {
			return $this->keys[$uid];
		}
		$list = $this->listVisible($uid);
		$byName = [];
		foreach ($list as $ws) {
			$byName[$ws->getName()][] = $ws;
		}
		$keys = [];
		$long = [];
		foreach ($list as $ws) {
			$id = (int)$ws->getId();
			$own = $ws->getOwnerType() === 'user' && $ws->getOwnerId() === $uid;
			if ($own || count($byName[$ws->getName()]) === 1) {
				$keys[$id] = $ws->getName();
				continue;
			}
			$keys[$id] = $ws->getName() . ' (' . $this->ownerName($ws) . ')';
			$long[$keys[$id]][] = $id;
		}
		foreach ($long as $key => $ids) {
			if (count($ids) > 1) {
				foreach ($ids as $id) {
					$keys[$id] = substr($key, 0, -1) . ', #' . $id . ')';
				}
			}
		}
		return $this->keys[$uid] = $keys;
	}

	private function ownerName(Workspace $ws): string {
		return $this->userManager->getDisplayName((string)$ws->getOwnerId()) ?? (string)$ws->getOwnerId();
	}

	public function keyFor(string $uid, int $workspaceId): ?string {
		return $this->keys($uid)[$workspaceId] ?? null;
	}

	// Schlüssel → Workspace; Langform „Name (…, #id)“ immer akzeptiert, unbekannt → 404
	public function resolveKey(string $uid, string $key): Workspace {
		$keys = $this->keys($uid);
		$id = array_search($key, $keys, true);
		if ($id === false && preg_match('/^(.*) \(.*, #(\d+)\)$/u', $key, $m)) {
			$candidate = (int)$m[2];
			if (isset($keys[$candidate]) && $this->visible[$uid][$candidate]->getName() === $m[1]) {
				$id = $candidate;
			}
		}
		if ($id === false) {
			throw new NotFoundException('Workspace not found');
		}
		return $this->visible[$uid][(int)$id];
	}

	public function requireByKey(string $uid, string $key, string $perm): Workspace {
		return $this->access->requireWorkspace($uid, $this->resolveKey($uid, $key), $perm);
	}

	// Leerer Schlüssel → null (alle sichtbaren)
	public function resolveOptional(string $uid, mixed $key, string $perm = 'read'): ?Workspace {
		if (!is_string($key) || trim($key) === '') {
			return null;
		}
		return $this->requireByKey($uid, trim($key), $perm);
	}

	// Upstream getFirstWorkspaceName: erster eigener normaler Workspace
	public function defaultWorkspace(string $uid): Workspace {
		$this->ensureDefault($uid);
		$own = $this->workspaces->findOwned($uid, 'normal');
		if ($own === []) {
			throw new NotFoundException('Workspace not found');
		}
		return $own[0];
	}

	// Schlüssel gegeben → Schreibrecht nötig; sonst Standard-Workspace
	public function targetForWrite(string $uid, mixed $key): Workspace {
		return $this->resolveOptional($uid, $key, 'write') ?? $this->defaultWorkspace($uid);
	}

	/** @return list<array<string, mixed>> Upstream-Format GET /workspaces */
	public function index(string $uid): array {
		$keys = $this->keys($uid);
		$out = [];
		foreach ($this->listVisible($uid) as $ws) {
			$color = ($ws->getColor() ?? '') !== '' ? $ws->getColor() : null;
			$out[] = [
				'name' => $keys[(int)$ws->getId()],
				'created' => gmdate('Y-m-d H:i:s', (int)$ws->getCreatedAt()),
				'tags' => $this->validator->workspaceTags((string)($ws->getTags() ?? '')),
				'color' => $color,
				'color_hex' => $this->validator->colorHex($color),
			];
		}
		return $out;
	}

	/** @return array{0: Workspace, 1: string[]} */
	public function create(string $uid, mixed $name, mixed $tags, bool $hasColor, mixed $color): array {
		$name = $this->validator->workspaceName($name);
		$tagList = $this->validator->workspaceTags($tags ?? []);
		$color = $hasColor ? $this->validator->color($color) : null;
		$ws = $this->tx->run(function () use ($uid, $name, $tagList, $color) {
			if ($this->workspaces->findOwned($uid, 'normal', $name) !== []) {
				throw new ConflictException('Workspace already exists');
			}
			return $this->insert($uid, $name, 'normal', $tagList, $color);
		});
		$this->reset();
		return [$ws, $tagList];
	}

	/**
	 * Umbenennen und/oder Tags/Farbe; nur Besitzer
	 * @return array<string, mixed>
	 */
	public function update(string $uid, string $key, array $input): array {
		$ws = $this->requireByKey($uid, $key, 'manage');
		$newName = trim((string)($input['new_name'] ?? ''));
		$hasTags = array_key_exists('tags', $input);
		$hasColor = array_key_exists('color', $input);
		$tags = $hasTags ? $this->validator->workspaceTags($input['tags']) : null;
		$color = $hasColor ? $this->validator->color($input['color']) : null;
		if ($newName === '' && !$hasTags && !$hasColor) {
			throw new BadRequestException('new_name, tags or color is required');
		}
		if ($newName !== '') {
			$newName = $this->validator->workspaceName($newName);
		}
		$oldName = $ws->getName();
		$oldKeys = $this->keys($uid);
		$this->tx->run(function () use ($uid, $ws, $newName, $oldName, $hasTags, $tags, $hasColor, $color) {
			if ($newName !== '' && $newName !== $oldName) {
				if ($this->workspaces->findOwned($uid, 'normal', $newName) !== []) {
					throw new ConflictException('Target workspace name already exists');
				}
				$ws->setName($newName);
			}
			if ($hasTags) {
				$ws->setTags($tags === [] ? null : implode(',', $tags));
			}
			if ($hasColor) {
				$ws->setColor($color);
			}
			$ws->setUpdatedAt($this->time->getTime());
			$this->workspaces->update($ws);
		});
		$this->reset();
		// Wie Upstream workspaces.php rename: Standard-/zuletzt geöffneter Workspace folgen ihrem Schlüssel (auch gleichnamige fremde, deren Schlüssel sich verschiebt)
		if ($ws->getName() !== $oldName) {
			$newKeys = $this->keys($uid);
			foreach (['default_workspace', 'last_opened_workspace'] as $setting) {
				$id = array_search($this->settings->get($uid, $setting), $oldKeys, true);
				if ($id !== false && isset($newKeys[$id]) && $newKeys[$id] !== $oldKeys[$id]) {
					$this->settings->set($uid, $setting, $newKeys[$id]);
				}
			}
		}
		return [
			'old_name' => $oldName,
			'new_name' => $ws->getName(),
			'tags' => $hasTags ? $tags : null,
			'color' => $hasColor ? $color : null,
		];
	}

	// Inhalte wandern nur in einen eigenen, nicht geteilten Workspace (Thomas, SPEC §11.2 B18)
	public function delete(string $uid, string $key): void {
		$ws = $this->requireByKey($uid, $key, 'manage');
		$others = array_values(array_filter(
			$this->workspaces->findOwned($uid, 'normal'),
			static fn (Workspace $w) => $w->getId() !== $ws->getId(),
		));
		if ($others === []) {
			throw new BadRequestException('Cannot delete the last workspace');
		}
		$default = $this->defaultName($uid);
		$this->tx->run(function () use ($uid, $ws, $default) {
			// Ziel und Freigabestand in derselben Transaktion lesen (Review r2 N1)
			$others = array_values(array_filter(
				$this->workspaces->findOwned($uid, 'normal'),
				static fn (Workspace $w) => $w->getId() !== $ws->getId(),
			));
			$shared = $this->shares->sharedWorkspaceIds(array_map(static fn (Workspace $w) => (int)$w->getId(), $others));
			$candidates = array_values(array_filter($others, static fn (Workspace $w) => $w->getName() !== self::ARCHIVE_NAME && !in_array((int)$w->getId(), $shared, true)));
			usort($candidates, static fn (Workspace $a, Workspace $b) => $a->getId() <=> $b->getId());
			$preferred = array_values(array_filter($candidates, static fn (Workspace $w) => $w->getName() === $default));
			$target = $preferred[0] ?? $candidates[0] ?? null;
			if ($target === null) {
				// Kein geeigneter Workspace: neuen Standard-Workspace anlegen (Name frei wählen)
				$name = $default;
				for ($i = 2; $this->workspaces->findOwned($uid, 'normal', $name) !== []; $i++) {
					$name = $default . ' ' . $i;
				}
				$target = $this->insert($uid, $name, 'normal', [], null);
			}
			// Quelle und Ziel in einer Anweisung sperren: kein paralleles Löschen/Verschieben dazwischen (fehlt eine → 404)
			if ($this->workspaces->lockRows([(int)$ws->getId(), (int)$target->getId()]) < 2) {
				throw new NotFoundException('Workspace not found');
			}
			$this->moveContents($ws, $target);
			$this->shares->deleteForWorkspace((int)$ws->getId());
			$this->workspaces->deleteById((int)$ws->getId());
			$this->backgrounds->queueWorkspace((int)$ws->getId());
		});
		$this->reset();
	}

	// Ordner und Notizen in einen anderen Workspace; namensgleiche Wurzelordner im Ziel bekommen einen Zusatz
	private function moveContents(Workspace $ws, Workspace $target): int {
		$folders = $this->folders->findInWorkspace((int)$ws->getId());
		foreach ($folders as $folder) {
			if ($folder->getParentId() !== null) {
				continue;
			}
			$name = $folder->getName();
			for ($i = 2; $this->folders->findSibling((int)$target->getId(), null, $name) !== null; $i++) {
				$name = $folder->getName() . ' (' . $i . ')';
			}
			if ($name !== $folder->getName()) {
				$folder->setName($name);
				$this->folders->update($folder);
			}
		}
		$this->folders->moveToWorkspace(array_map(static fn (Folder $f) => (int)$f->getId(), $folders), (int)$target->getId(), $this->time->getTime());
		return $this->notes->moveWorkspaceNotes((int)$ws->getId(), (int)$target->getId());
	}

	// workspaces.php move_notes: nur zwischen eigenen Workspaces (Quota beim Besitzer, B17); Quelle bleibt bestehen
	public function moveNotes(string $uid, string $key, string $targetKey): int {
		$ws = $this->requireByKey($uid, $key, 'manage');
		$target = $this->requireByKey($uid, $targetKey, 'manage');
		if ($ws->getId() === $target->getId()) {
			throw new BadRequestException('Source and target workspaces must differ');
		}
		$moved = $this->tx->run(function () use ($uid, $ws, $target) {
			// beide sperren und neu lesen: ein paralleles delete darf das Ziel nicht zwischen Prüfung und Verschieben entfernen
			if ($this->workspaces->lockRows([(int)$ws->getId(), (int)$target->getId()]) !== 2) {
				throw new NotFoundException('Workspace not found');
			}
			return $this->moveContents(
				$this->access->requireWorkspace($uid, (int)$ws->getId(), 'manage'),
				$this->access->requireWorkspace($uid, (int)$target->getId(), 'manage'),
			);
		});
		$this->reset();
		return $moved;
	}

	/**
	 * workspaces.php reorder: Positionen 1..n; jeder Name braucht Verwaltungsrecht (fremde/geteilte → 404/403)
	 * @param string[] $keys
	 */
	public function reorder(string $uid, array $keys): void {
		$list = [];
		foreach ($keys as $key) {
			$list[] = $this->requireByKey($uid, $key, 'manage');
		}
		$this->tx->run(function () use ($list) {
			foreach ($list as $i => $ws) {
				$ws->setDisplayOrder($i + 1);
				$this->workspaces->update($ws);
			}
		});
		$this->reset();
	}

	// Freigabe nur über Service (UI-Weg M3/M4); nur Besitzer
	public function share(string $ownerUid, int $workspaceId, string $targetUid, string $permission = 'write'): void {
		$ws = $this->access->requireWorkspace($ownerUid, $workspaceId, 'manage');
		if ($targetUid === $ownerUid || !$this->userManager->userExists($targetUid)) {
			throw new BadRequestException('Invalid share target');
		}
		$this->shares->insertIgnore((int)$ws->getId(), $targetUid, $permission === 'read' ? 'read' : 'write', $ownerUid, $this->time->getTime());
		$this->reset();
	}

	// Entzug löscht Erinnerungen, Mention-Status und Hintergrundbilder des Empfängers in diesem Workspace (SPEC §6, §8)
	public function unshare(string $ownerUid, int $workspaceId, string $targetUid): void {
		$ws = $this->access->requireWorkspace($ownerUid, $workspaceId, 'manage');
		$this->tx->run(function () use ($ws, $targetUid) {
			$this->shares->deleteUserShare((int)$ws->getId(), $targetUid);
			$noteIds = $this->notes->idsInWorkspace((int)$ws->getId());
			$this->reminders->deleteForNotes($noteIds, $targetUid);
			$this->mentions->deleteForNotes($noteIds, $targetUid);
			$this->backgrounds->queueUser([(int)$ws->getId()], $targetUid);
		});
		$this->reset();
	}

	// „Archives“ beim Besitzer der Quelle, bei Bedarf angelegt (Plan O6)
	/** @return array{0: Workspace, 1: bool} Workspace, neu angelegt */
	public function archiveFor(Workspace $source): array {
		if ($source->getOwnerType() !== 'user') {
			throw new BadRequestException('Archiving is not available for this workspace');
		}
		$owner = (string)$source->getOwnerId();
		$existing = $this->workspaces->findOwned($owner, 'normal', self::ARCHIVE_NAME);
		if ($existing !== []) {
			return [$existing[0], false];
		}
		$ws = $this->insert($owner, self::ARCHIVE_NAME, 'normal', [], null);
		$this->reset();
		return [$ws, true];
	}

	public function isOwnArchive(Workspace $ws): bool {
		return $ws->getName() === self::ARCHIVE_NAME;
	}
}
