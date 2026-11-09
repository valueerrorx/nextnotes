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
use OCA\NextNotes\Service\Exception\ForbiddenException;
use OCA\NextNotes\Service\Exception\NotFoundException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use OCP\L10N\IFactory;

// Workspaces, Schlüssel (SPEC §4.4), Standard-Workspace, Freigaben (Service-Weg)
class WorkspaceService {
	public const ARCHIVE_NAME = 'Archives';
	// Reihenfolge fremder Workspaces je Benutzer (IUserConfig, über die Settings-API gesperrt)
	public const FOREIGN_ORDER_KEY = 'foreign_workspace_order';
	private const TEAM_MANAGE_LEVEL = 4;

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
		private TeamService $teams,
		private TeamStateService $teamState,
		private QuotaService $quota,
		private ShareTargetPolicy $sharePolicy,
	) {
	}

	public function reset(): void {
		$this->keys = [];
		$this->visible = [];
		$this->access->resetCache();
	}

	// Erster Zugriff ohne eigenen Workspace: „Notizen“ in Benutzersprache (Thomas, O5)
	public function ensureDefault(string $uid): void {
		// Schüler-Workspace (kind=assignments) zählt nicht als eigener Workspace
		if ($this->workspaces->findOwned($uid, 'normal') !== []) {
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

	private function insert(string $uid, string $name, string $kind, array $tags, ?string $color, ?string $teamId = null): Workspace {
		$now = $this->time->getTime();
		$ws = new Workspace();
		$ws->setOwnerType($teamId === null ? 'user' : 'team');
		$ws->setOwnerId($teamId ?? $uid);
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

	/** @return Workspace[] sichtbare Workspaces: eigene (Reihenfolge), dann geteilte und Team-Workspaces (eigene Reihenfolge des Benutzers, sonst Name) */
	public function listVisible(string $uid): array {
		if (!isset($this->visible[$uid])) {
			// eigene nur, wenn erreichbar (Schüler-Workspace nach Austritt nicht, M7h)
			$visibleIds = $this->access->visibleWorkspaceIds($uid);
			$reachable = array_flip($visibleIds);
			$own = array_values(array_filter($this->workspaces->findOwned($uid), static fn (Workspace $w) => isset($reachable[(int)$w->getId()])));
			$ownIds = array_map(static fn (Workspace $w) => (int)$w->getId(), $own);
			$shared = $this->workspaces->findByIds(array_values(array_diff($visibleIds, $ownIds)));
			$order = array_flip($this->foreignOrder($uid));
			usort($shared, static fn (Workspace $a, Workspace $b) => ($order[(int)$a->getId()] ?? PHP_INT_MAX) <=> ($order[(int)$b->getId()] ?? PHP_INT_MAX)
				?: strnatcasecmp($a->getName(), $b->getName()) ?: $a->getId() <=> $b->getId());
			$list = [];
			foreach (array_merge($own, $shared) as $ws) {
				$list[(int)$ws->getId()] = $ws;
			}
			$this->visible[$uid] = $list;
		}
		return array_values($this->visible[$uid]);
	}

	/** @return Workspace[] Workspace-Menü und Ziele: ohne Schüler-Workspaces (Q3, nur über „Meine Aufgaben“; Schlüssel bleiben auflösbar) */
	public function listMenu(string $uid): array {
		return array_values(array_filter($this->listVisible($uid), static fn (Workspace $ws) => $ws->getKind() === 'normal'));
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
			$keys[$id] = $ws->getName() . ' (' . $this->ownerName($uid, $ws) . ')';
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

	// Teamname aus der Mitgliedschaft (Request), sonst Snapshot; GET schreibt nie (SPEC §4.5)
	private function ownerName(string $uid, Workspace $ws): string {
		$id = (string)$ws->getOwnerId();
		if ($ws->getOwnerType() === 'team') {
			return $this->teams->memberTeams($uid)[$id] ?? $this->teamState->snapshotName($id) ?? $id;
		}
		return $this->userManager->getDisplayName($id) ?? $id;
	}

	/** @return int[] eigene Reihenfolge fremder Workspaces */
	private function foreignOrder(string $uid): array {
		$list = json_decode($this->settings->get($uid, self::FOREIGN_ORDER_KEY, '[]'), true);
		return is_array($list) ? array_values(array_map('intval', array_filter($list, 'is_int'))) : [];
	}

	public function keyFor(string $uid, int $workspaceId): ?string {
		return $this->keys($uid)[$workspaceId] ?? null;
	}

	/**
	 * Lesbare Team-Workspaces (kind=normal) je Team, aufsteigend nach ID wie WorkspaceResourceProvider; der erste ist die Heimat der Aufgaben-Seite (M6i)
	 * @return array<string, list<string>> Team-ID → Schlüssel
	 */
	public function teamWorkspaceKeys(string $uid): array {
		$keys = $this->keys($uid);
		$byTeam = [];
		foreach ($this->listVisible($uid) as $ws) {
			if ($ws->getOwnerType() === 'team' && $ws->getKind() === 'normal' && isset($keys[(int)$ws->getId()])) {
				$byTeam[(string)$ws->getOwnerId()][(int)$ws->getId()] = $keys[(int)$ws->getId()];
			}
		}
		return array_map(static function (array $list): array {
			ksort($list);
			return array_values($list);
		}, $byTeam);
	}

	// Ziel-Workspace für Links auf assignments.php (M6i): erster Team-Workspace des Teams, sonst erster eigener (Aufgaben ohne Team-Workspace); nie schreibend
	public function assignmentsKey(string $uid, string $teamId): ?string {
		$team = $this->teamWorkspaceKeys($uid)[$teamId][0] ?? null;
		if ($team !== null) {
			return $team;
		}
		foreach ($this->listVisible($uid) as $ws) {
			if ($ws->getOwnerType() === 'user' && $ws->getOwnerId() === $uid && $ws->getKind() === 'normal') {
				return $this->keyFor($uid, (int)$ws->getId());
			}
		}
		return null;
	}

	// R16: Paletten-Besitzer eines sichtbaren Workspace (nicht sichtbar → Aufrufer)
	public function paletteOwner(string $uid, int $workspaceId): string {
		$this->listVisible($uid);
		$ws = $this->visible[$uid][$workspaceId] ?? null;
		return $ws === null ? $uid : NotePalette::ownerOf($ws);
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
		foreach ($this->listMenu($uid) as $ws) {
			$color = ($ws->getColor() ?? '') !== '' ? $ws->getColor() : null;
			$out[] = [
				'name' => $keys[(int)$ws->getId()],
				'created' => gmdate('Y-m-d H:i:s', (int)$ws->getCreatedAt()),
				'tags' => $this->validator->workspaceTags((string)($ws->getTags() ?? '')),
				'color' => $color,
				'color_hex' => $this->validator->colorHex($color, NotePalette::ownerOf($ws)),
				// Team-Kennzeichen im Workspace-Menü (M5d); null = kein Team-Workspace
				'team' => $this->teamName($uid, $ws),
			];
		}
		return $out;
	}

	// Anzeigename des Teams eines Team-Workspace (Mitgliedschaft, sonst Snapshot), sonst null
	public function teamName(string $uid, Workspace $ws): ?string {
		return $ws->getOwnerType() === 'team' ? $this->ownerName($uid, $ws) : null;
	}

	/**
	 * Optional Team-Workspace (team = singleId): nur aktives Team, Level ≥ 4, Team passiert die Probe-Regel (Plan M5 §2.3)
	 * @return array{0: Workspace, 1: string[]}
	 */
	public function create(string $uid, mixed $name, mixed $tags, bool $hasColor, mixed $color, mixed $team = null): array {
		$name = $this->validator->workspaceName($name);
		$tagList = $this->validator->workspaceTags($tags ?? []);
		$color = $hasColor ? $this->validator->color($color) : null;
		$teamId = null;
		if ($team !== null && $team !== '') {
			$teamId = $this->requireTeamForCreate($uid, $team);
			$this->teamState->ensure($teamId, $this->teams->memberTeams($uid)[$teamId] ?? null);
		}
		$ws = $this->tx->run(function () use ($uid, $name, $tagList, $color, $teamId) {
			if ($teamId !== null) {
				$this->workspaces->lockOwner('team', $teamId);
			}
			if ($this->workspaces->findByOwner($teamId === null ? 'user' : 'team', $teamId ?? $uid, 'normal', $name) !== []) {
				throw new ConflictException('Workspace already exists');
			}
			return $this->insert($uid, $name, 'normal', $tagList, $color, $teamId);
		});
		$this->reset();
		return [$ws, $tagList];
	}

	// OneNote-Import (Plan M9 §5): eigener Workspace kind=normal mit freiem Namen; ohne Sitzung, in der Transaktion des Aufrufers
	public function createImported(string $uid, string $name): Workspace {
		$base = trim(mb_substr(trim(Pcre::replace('/\s+/u', ' ', Pcre::replace('/[^\p{L}0-9 _-]+/u', ' ', $name))), 0, 240));
		$base = $base === '' ? 'OneNote' : $base;
		$candidate = $base;
		for ($i = 1; $this->workspaces->findOwned($uid, 'normal', $candidate) !== []; $i++) {
			$candidate = $base . ' OneNote' . ($i > 1 ? ' ' . $i : '');
		}
		$ws = $this->insert($uid, $candidate, 'normal', [], null);
		$this->reset();
		return $ws;
	}

	// Nicht Mitglied → 404; Team zählt nicht (versteckt, Backend …), nicht aktiv oder Level < 4 → 403
	public function requireTeamForCreate(string $uid, mixed $team): string {
		if (!is_string($team) || strlen($team) > 64 || !$this->teams->isAvailable()) {
			throw new NotFoundException('Team not found');
		}
		$m = $this->teams->membership($team, $uid);
		if ($m['level'] < 1) {
			throw new NotFoundException('Team not found');
		}
		if (!$m['counts'] || $m['level'] < self::TEAM_MANAGE_LEVEL || $this->teamState->stateOf($team) !== TeamStateService::ACTIVE) {
			throw new ForbiddenException('Permission denied');
		}
		return $team;
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
				if ($this->workspaces->findByOwner((string)$ws->getOwnerType(), (string)$ws->getOwnerId(), 'normal', $newName) !== []) {
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
		// Team-Name-Snapshot beim Umbenennen auffrischen (SPEC §2.1)
		if ($ws->getOwnerType() === 'team' && $ws->getName() !== $oldName) {
			$this->teamState->ensure((string)$ws->getOwnerId(), $this->teams->memberTeams($uid)[(string)$ws->getOwnerId()] ?? null);
		}
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

	// Inhalte wandern nur in einen eigenen, nicht geteilten Workspace (Thomas, SPEC §11.2 B18); Team-Workspaces: WorkspaceDeletion
	public function delete(string $uid, string $key): void {
		$ws = $this->requireByKey($uid, $key, 'manage');
		if ($ws->getOwnerType() !== 'user') {
			throw new BadRequestException('Use the team workspace deletion');
		}
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
			$this->access->requireLeave($uid, new LeaveRequest($ws, $target, LeaveRequest::WORKSPACE));
			$this->moveContents($ws, $target);
			$this->shares->deleteForWorkspace((int)$ws->getId());
			$this->workspaces->deleteById((int)$ws->getId());
			$this->backgrounds->queueWorkspace((int)$ws->getId());
		});
		$this->reset();
	}

	// Ordner und Notizen in einen anderen Workspace; namensgleiche Wurzelordner im Ziel bekommen einen Zusatz (Aufrufer sperrt beide und prüft requireLeave)
	public function moveContents(Workspace $ws, Workspace $target): int {
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
		$ids = array_map(static fn (Folder $f) => (int)$f->getId(), $folders);
		$this->folders->moveToWorkspace($ids, (int)$target->getId(), $this->time->getTime());
		// Material wirkt nur in Team-Workspaces: beim Verlassen entfernen (M6a)
		if ($target->getOwnerType() !== 'team' || $target->getKind() !== 'normal') {
			$this->folders->clearMaterial((int)$target->getId(), $ids);
		}
		$this->access->resetMaterialCache();
		$moved = $this->notes->moveWorkspaceNotes((int)$ws->getId(), (int)$target->getId());
		if ($target->getOwnerType() !== 'team' || $target->getKind() !== 'normal') {
			$this->notes->clearTrashMaterial((int)$target->getId());
		}
		return $moved;
	}

	// workspaces.php move_notes: Quelle verwalten, Ziel schreiben, Verlassen-Regel (B17, Plan M5 §2.3); Quote beim Ziel-Besitzer; Quelle bleibt bestehen
	public function moveNotes(string $uid, string $key, string $targetKey): int {
		$ws = $this->requireByKey($uid, $key, 'manage');
		$target = $this->requireByKey($uid, $targetKey, 'write');
		if ($ws->getId() === $target->getId()) {
			throw new BadRequestException('Source and target workspaces must differ');
		}
		$this->access->requireLeave($uid, new LeaveRequest($ws, $target, LeaveRequest::WORKSPACE));
		$quota = $this->quota->forLeave(new LeaveRequest($ws, $target, LeaveRequest::WORKSPACE));
		$moved = $this->tx->run(function () use ($uid, $ws, $target, $quota) {
			$this->quota->enforce($quota);
			// beide sperren und neu lesen: ein paralleles delete darf das Ziel nicht zwischen Prüfung und Verschieben entfernen
			if ($this->workspaces->lockRows([(int)$ws->getId(), (int)$target->getId()]) !== 2) {
				throw new NotFoundException('Workspace not found');
			}
			$source = $this->access->requireWorkspace($uid, (int)$ws->getId(), 'manage');
			$dest = $this->access->requireWorkspace($uid, (int)$target->getId(), 'write');
			return $this->access->moveLeaving($uid, new LeaveRequest($source, $dest, LeaveRequest::WORKSPACE), fn () => $this->moveContents($source, $dest));
		});
		$this->reset();
		return $moved;
	}

	/**
	 * workspaces.php reorder: eigene über display_order (1..n), fremde (geteilt/Team) nur in der eigenen Liste (IUserConfig); nie die Reihenfolge anderer (R8)
	 * @param string[] $keys
	 */
	public function reorder(string $uid, array $keys): void {
		$own = [];
		$foreign = [];
		foreach ($keys as $key) {
			$ws = $this->requireByKey($uid, $key, 'read');
			if ($ws->getOwnerType() === 'user' && $ws->getOwnerId() === $uid) {
				$own[] = $ws;
			} else {
				$foreign[] = (int)$ws->getId();
			}
		}
		$this->tx->run(function () use ($own) {
			foreach ($own as $i => $ws) {
				$ws->setDisplayOrder($i + 1);
				$this->workspaces->update($ws);
			}
		});
		if ($foreign !== []) {
			$this->settings->set($uid, self::FOREIGN_ORDER_KEY, json_encode(array_values(array_unique($foreign))));
		}
		$this->reset();
	}

	// Freigabe nur über Service (UI-Weg M3/M4); nur Besitzer
	// Neuer Empfänger nur nach NC-Freigaberegeln; bestehender: Rechtewechsel per Update, nie an der Richtlinie gescheitert (Plan M5 §2.7)
	public function share(string $ownerUid, int $workspaceId, string $targetUid, string $permission = 'write'): void {
		$ws = $this->requireShareable($ownerUid, $workspaceId);
		$current = $this->shares->permissionsFor([(int)$ws->getId()])[(int)$ws->getId()] ?? [];
		$wanted = $current;
		$wanted[$targetUid] = $permission;
		$this->applyShares($ws, $ownerUid, $wanted, $current);
	}

	// Entzug löscht Erinnerungen, Mention-Status und Hintergrundbilder des Empfängers in diesem Workspace (SPEC §6, §8)
	public function unshare(string $ownerUid, int $workspaceId, string $targetUid): void {
		$ws = $this->access->requireWorkspace($ownerUid, $workspaceId, 'manage');
		$this->tx->run(fn () => $this->removeRecipient($ws, $targetUid));
		$this->reset();
	}

	/**
	 * Freigabe-Dialog: Soll-Liste uid → read|write; neue nach Richtlinie, bestehende per Update, fehlende entzogen; alles oder nichts
	 * @param array<string, string> $wanted
	 * @return array{shared: bool, allowed_users: list<array{uid: string, displayName: string, permission: string}>, shared_with: list<string>}
	 */
	public function setShares(string $ownerUid, int $workspaceId, array $wanted): array {
		$ws = $this->requireShareable($ownerUid, $workspaceId);
		$id = (int)$ws->getId();
		$this->applyShares($ws, $ownerUid, $wanted, $this->shares->permissionsFor([$id])[$id] ?? []);
		return $this->shareStates([$id])[$id];
	}

	/**
	 * Freigabezustand je Workspace (Einstellungen, Antwort des Dialogs); Anzeigenamen nur der eigenen Empfänger
	 * @param int[] $workspaceIds
	 * @return array<int, array{shared: bool, allowed_users: list<array{uid: string, displayName: string, permission: string}>, shared_with: list<string>}>
	 */
	public function shareStates(array $workspaceIds): array {
		$all = $workspaceIds === [] ? [] : $this->shares->permissionsFor($workspaceIds);
		$out = [];
		foreach ($workspaceIds as $id) {
			$users = [];
			foreach ($all[$id] ?? [] as $uid => $permission) {
				$users[] = ['uid' => (string)$uid, 'displayName' => $this->userManager->getDisplayName((string)$uid) ?? (string)$uid, 'permission' => $permission === 'read' ? 'read' : 'write'];
			}
			$out[$id] = ['shared' => $users !== [], 'allowed_users' => $users, 'shared_with' => array_column($users, 'displayName')];
		}
		return $out;
	}

	// Teilen nur für eigene Benutzer-Workspaces (ADR 0014, Plan M5 §2.2)
	private function requireShareable(string $ownerUid, int $workspaceId): Workspace {
		$ws = $this->access->requireWorkspace($ownerUid, $workspaceId, 'manage');
		if ($ws->getOwnerType() !== 'user') {
			throw new ForbiddenException('Team workspaces cannot be shared');
		}
		return $ws;
	}

	/**
	 * @param array<string, string> $wanted uid → read|write
	 * @param array<string, string> $current bestehende Empfänger
	 */
	private function applyShares(Workspace $ws, string $ownerUid, array $wanted, array $current): void {
		$wanted = array_map(static fn ($p) => $p === 'read' ? 'read' : 'write', $wanted);
		// Einheitliche Antwort für unbekannt, deaktiviert, sich selbst und nicht erlaubt; geprüft vor jeder Änderung
		foreach (array_keys($wanted) as $uid) {
			if (!isset($current[(string)$uid]) && !$this->sharePolicy->allowed($ownerUid, (string)$uid)) {
				throw new BadRequestException('Invalid share target');
			}
		}
		$id = (int)$ws->getId();
		$this->tx->run(function () use ($ws, $id, $ownerUid, $wanted, $current) {
			foreach ($wanted as $uid => $permission) {
				$uid = (string)$uid;
				if (!isset($current[$uid])) {
					$this->shares->insertIgnore($id, $uid, $permission, $ownerUid, $this->time->getTime());
				} elseif ($current[$uid] !== $permission) {
					$this->shares->updatePermission($id, $uid, $permission);
				}
			}
			foreach (array_keys(array_diff_key($current, $wanted)) as $uid) {
				$this->removeRecipient($ws, (string)$uid);
			}
		});
		$this->reset();
	}

	private function removeRecipient(Workspace $ws, string $targetUid): void {
		$this->shares->deleteUserShare((int)$ws->getId(), $targetUid);
		$noteIds = $this->notes->idsInWorkspace((int)$ws->getId());
		$this->reminders->deleteForNotes($noteIds, $targetUid);
		$this->mentions->deleteForNotes($noteIds, $targetUid);
		$this->backgrounds->queueUser([(int)$ws->getId()], $targetUid);
	}

	// „Archives“ beim Besitzer der Quelle, bei Bedarf angelegt (Plan O6); Team-Quelle: Archiv desselben Teams, anlegen nur mit verwalten (Plan M5 §2.2)
	/** @return array{0: Workspace, 1: bool} Workspace, neu angelegt */
	public function archiveFor(Workspace $source, string $uid): array {
		$type = (string)$source->getOwnerType();
		if ($type !== 'user' && $type !== 'team') {
			throw new BadRequestException('Archiving is not available for this workspace');
		}
		$owner = (string)$source->getOwnerId();
		$existing = $this->workspaces->findByOwner($type, $owner, 'normal', self::ARCHIVE_NAME);
		if ($existing !== []) {
			return [$existing[0], false];
		}
		if ($type === 'team') {
			$this->access->requireWorkspace($uid, $source, 'manage');
		}
		$ws = $this->insert($type === 'team' ? $uid : $owner, self::ARCHIVE_NAME, 'normal', [], null, $type === 'team' ? $owner : null);
		$this->reset();
		return [$ws, true];
	}

	public function isOwnArchive(Workspace $ws): bool {
		return $ws->getName() === self::ARCHIVE_NAME;
	}
}
