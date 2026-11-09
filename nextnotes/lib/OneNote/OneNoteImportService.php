<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\OneNote;

use OCA\NextNotes\AppInfo\Application;
use OCA\NextNotes\Db\Folder;
use OCA\NextNotes\Db\FolderMapper;
use OCA\NextNotes\Db\NoteMapper;
use OCA\NextNotes\Db\OneNoteImport;
use OCA\NextNotes\Db\OneNoteImportMapper;
use OCA\NextNotes\Db\OneNoteItem;
use OCA\NextNotes\Db\OneNoteItemMapper;
use OCA\NextNotes\Db\Workspace;
use OCA\NextNotes\Db\WorkspaceMapper;
use OCA\NextNotes\OneNote\Convert\PageConverter;
use OCA\NextNotes\Service\Exception\BadRequestException;
use OCA\NextNotes\Service\Exception\ConflictException;
use OCA\NextNotes\Service\Exception\ForbiddenException;
use OCA\NextNotes\Service\Exception\NotFoundException;
use OCA\NextNotes\Service\Exception\TooLargeException;
use OCA\NextNotes\Service\Exception\TooManyRequestsException;
use OCA\NextNotes\Service\FolderService;
use OCA\NextNotes\Service\NoteService;
use OCA\NextNotes\Service\Transaction;
use OCA\NextNotes\Service\WorkspaceService;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbException;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * OneNote-Import (Plan M9 §2 ImportService, §3, §4.3–4.8): Auswahl (tree/start) und Job-Schritte (Struktur, Seitenliste, Seite).
 * Graph- und Token-Anfragen nie in einer Transaktion; jede Transaktion beginnt mit dem bedingten Lease-Check, jeder
 * Schreibvorgang des Jobs ist an id, uid, lease_token und status=running gebunden. Anlagen mit expliziter uid, ohne Sitzung.
 */
class OneNoteImportService {
	public const RUN_SECONDS = 120;
	public const RUN_REQUESTS = 50;
	public const MAX_SELECTION = 200;
	public const NOT_FOUND_LIMIT = 20;
	public const TEAMS_PER_CALL = 20;
	private const DUE_BATCH = 20;
	private const SELECT_TTL = 86400;
	private const REAUTH_TTL = 7 * 86400;
	private const STALL_TTL = 7 * 86400;
	private const PAGE_LIMIT = 16 * 1024 * 1024;
	private const LIST_FOLLOW_MAX = 20;
	private const MAX_DEPTH = 20;
	private const FINISHED = ['done', 'failed', 'cancelled', 'expired'];
	private const GRAPH_TOO_BIG = ['10008', '10013'];

	public function __construct(
		private OneNoteConfig $config,
		private OneNoteImportMapper $imports,
		private OneNoteItemMapper $items,
		private ConnectService $connect,
		private GraphClient $graph,
		private PageConverter $converter,
		private WorkspaceService $workspaces,
		private FolderService $folders,
		private NoteService $notes,
		private WorkspaceMapper $workspaceRows,
		private FolderMapper $folderRows,
		private NoteMapper $noteRows,
		private Transaction $tx,
		private ISecureRandom $random,
		private ITimeFactory $time,
		private IUserManager $users,
		private IAppManager $apps,
		private IFactory $l10n,
		private OneNoteNotifications $notify,
		private LoggerInterface $logger,
	) {
	}

	// ---------- Auswahl (Routen) ----------

	/**
	 * Notizbücher mit erster Ebene (§4.3); Teams nur, wenn erlaubt, je Aufruf höchstens 20 (ab $teamOffset)
	 * @return array<string, mixed>
	 */
	public function tree(string $uid, int $id, int $teamOffset = 0): array {
		$import = $this->selectable($uid, $id);
		$run = new ImportRun($import, null, $this->l($this->users->get($uid)));
		$teamsAllowed = (int)$import->getTeamScopes() === 1 && $this->config->teamsEffective();
		$teamOffset = max(0, $teamOffset);
		try {
			$notebooks = $teamOffset === 0 ? $this->notebookList($run, 'me', null) : [];
			$more = false;
			if ($teamsAllowed) {
				$teams = $this->joinedTeams($run);
				foreach (array_slice($teams, $teamOffset, self::TEAMS_PER_CALL) as $team) {
					try {
						array_push($notebooks, ...$this->notebookList($run, 'group:' . $team['id'], $team['name']));
					} catch (GraphException $e) {
						// Team verlassen, kein Notizbuch oder keine Zustimmung: still überspringen
						if (!in_array($e->reason, ['forbidden', 'not_found'], true)) {
							throw $e;
						}
					}
				}
				$more = count($teams) > $teamOffset + self::TEAMS_PER_CALL;
			}
		} catch (TokenException $e) {
			throw new ConflictException('Microsoft sign-in is no longer valid', ['code' => $e->reason === 'failed' ? 'invalid_client' : 'reconnect']);
		} catch (GraphException $e) {
			if ($e->reason === 'budget' || $e->isTransient()) {
				throw new TooManyRequestsException('Microsoft limit reached, try again later', ['code' => 'graph_busy', 'retry_at' => $e->retryAt]);
			}
			throw new ConflictException('Microsoft Graph request failed', ['code' => 'graph_error']);
		}
		return ['notebooks' => $notebooks, 'teams' => $teamsAllowed, 'teams_more' => $more, 'teams_next' => $teamOffset + self::TEAMS_PER_CALL];
	}

	/**
	 * Auswahl speichern (§4.3): Positivliste, IDs geprüft, höchstens 200 Einträge; danach queued
	 * @return array<string, mixed>
	 */
	public function start(string $uid, int $id, mixed $selection): array {
		$import = $this->selectable($uid, $id);
		$teams = (int)$import->getTeamScopes() === 1 && $this->config->teamsEffective();
		$normalized = self::normalizeSelection($selection, $teams);
		$now = $this->time->getTime();
		$n = $this->imports->updateWhere($id, $uid, [
			'status' => 'queued',
			'selection' => (string)json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
			'cursor' => null,
			'next_run_at' => $now,
			'backoff_level' => 0,
			'not_found_streak' => 0,
			'updated_at' => $now,
		], ['status' => 'select']);
		if ($n !== 1) {
			throw new ConflictException('Import changed, reload', ['code' => 'changed']);
		}
		return ['import' => ConnectService::view($this->imports->findOwn($id, $uid))];
	}

	private function selectable(string $uid, int $id): OneNoteImport {
		if (!$this->config->enabled()) {
			throw new ForbiddenException('OneNote import is not configured', ['code' => 'not_configured']);
		}
		$this->connect->reconcile($uid);
		try {
			$import = $this->imports->findOwn($id, $uid);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Import not found');
		}
		if ($import->getStatus() !== 'select') {
			throw new ConflictException('Import is not waiting for a selection', ['code' => 'not_select']);
		}
		return $import;
	}

	/**
	 * @return list<array{root: string, notebook: string, name: string, items: list<array{kind: string, id: string}>|null}>
	 */
	public static function normalizeSelection(mixed $selection, bool $teams): array {
		if (!is_array($selection) || !array_is_list($selection) || $selection === []) {
			throw new BadRequestException('selection must be a non-empty list');
		}
		$out = [];
		$seen = [];
		$count = 0;
		foreach ($selection as $entry) {
			$root = is_array($entry) ? ($entry['root'] ?? null) : null;
			$notebook = is_array($entry) ? ($entry['notebook'] ?? null) : null;
			if (!is_string($root) || !is_string($notebook) || GraphClient::rootPath($root) === null || !GraphClient::validId($notebook)) {
				throw new BadRequestException('Invalid notebook in selection');
			}
			if ($root !== 'me' && !$teams) {
				throw new BadRequestException('Team notebooks are not allowed');
			}
			$key = $root . '|' . $notebook;
			if (isset($seen[$key])) {
				throw new BadRequestException('Notebook selected twice');
			}
			$seen[$key] = true;
			$count++;
			$items = $entry['items'] ?? null;
			if ($items !== null) {
				if (!is_array($items) || !array_is_list($items) || $items === []) {
					throw new BadRequestException('items must be null or a non-empty list');
				}
				$list = [];
				foreach ($items as $item) {
					$kind = is_array($item) ? ($item['kind'] ?? null) : null;
					$itemId = is_array($item) ? ($item['id'] ?? null) : null;
					if (!in_array($kind, ['section', 'section_group'], true) || !is_string($itemId) || !GraphClient::validId($itemId)) {
						throw new BadRequestException('Invalid item in selection');
					}
					$list[$kind . ':' . $itemId] = ['kind' => $kind, 'id' => $itemId];
				}
				$items = array_values($list);
				$count += count($items);
			}
			if ($count > self::MAX_SELECTION) {
				throw new BadRequestException('Too many entries in selection (max ' . self::MAX_SELECTION . ')');
			}
			$name = is_string($entry['name'] ?? null) ? mb_substr(trim($entry['name']), 0, 255) : '';
			$out[] = ['root' => $root, 'notebook' => $notebook, 'name' => $name, 'items' => $items];
		}
		return $out;
	}

	/** @return list<array<string, mixed>> */
	private function notebookList(ImportRun $run, string $root, ?string $team): array {
		$url = GraphClient::PREFIX . GraphClient::rootPath($root) . '/onenote/notebooks?$select=id,displayName,lastModifiedDateTime'
			. '&$expand=sections($select=id,displayName),sectionGroups($select=id,displayName)';
		$out = [];
		foreach ($this->listAll($run, $url) as $nb) {
			$id = is_string($nb['id'] ?? null) ? $nb['id'] : '';
			if (!GraphClient::validId($id)) {
				continue;
			}
			$items = [];
			foreach (['sections' => 'section', 'sectionGroups' => 'section_group'] as $prop => $kind) {
				foreach (is_array($nb[$prop] ?? null) ? $nb[$prop] : [] as $el) {
					$elId = is_array($el) && is_string($el['id'] ?? null) ? $el['id'] : '';
					if (GraphClient::validId($elId)) {
						$items[] = ['kind' => $kind, 'id' => $elId, 'name' => self::name($el)];
					}
				}
			}
			$out[] = ['root' => $root, 'id' => $id, 'name' => self::name($nb), 'team' => $team,
				'preselected' => $root === 'me', 'items' => $items];
		}
		return $out;
	}

	/** @return list<array{id: string, name: string}> */
	private function joinedTeams(ImportRun $run): array {
		$out = [];
		foreach ($this->listAll($run, GraphClient::PREFIX . 'me/joinedTeams?$select=id,displayName') as $t) {
			$id = is_string($t['id'] ?? null) ? $t['id'] : '';
			if (GraphClient::validId($id)) {
				$out[] = ['id' => $id, 'name' => self::name($t)];
			}
		}
		return $out;
	}

	/** @return list<array<string, mixed>> value-Listen mit nextLink (nur Graph-Präfix) */
	private function listAll(ImportRun $run, string $url): array {
		$out = [];
		for ($i = 0; $i < self::LIST_FOLLOW_MAX && $url !== null; $i++) {
			$data = $this->fetch($run, $url)->json() ?? [];
			foreach (is_array($data['value'] ?? null) ? $data['value'] : [] as $v) {
				if (is_array($v)) {
					$out[] = $v;
				}
			}
			$url = self::nextLink($data);
		}
		return $out;
	}

	// ---------- Job ----------

	/** Ein Lauf (OneNoteImportJob): Aufräumen immer, Importe nur bei eingeschalteter Funktion */
	public function runDue(float $budgetSeconds = self::RUN_SECONDS): void {
		$started = microtime(true);
		$this->cleanup();
		if (!$this->config->enabled()) {
			return;
		}
		$this->connect->reconcile(null);
		foreach ($this->imports->findDue($this->time->getTime(), self::DUE_BATCH) as $row) {
			if (microtime(true) - $started >= $budgetSeconds) {
				return;
			}
			$lease = $this->random->generate(32, ISecureRandom::CHAR_ALPHANUMERIC);
			$now = $this->time->getTime();
			if ($this->imports->claim((int)$row->getId(), (string)$row->getUid(), $lease, $now, $now + GraphClient::LEASE_SECONDS) !== 1) {
				continue;
			}
			$this->runOne((int)$row->getId(), (string)$row->getUid(), $lease, $started, $budgetSeconds);
		}
	}

	private function runOne(int $id, string $uid, string $lease, float $started, float $budget): void {
		try {
			$import = $this->imports->findOwn($id, $uid);
		} catch (DoesNotExistException) {
			return;
		}
		$run = new ImportRun($import, $lease, $this->l($this->users->get($uid)));
		try {
			while ($this->preflight($run)) {
				// weiches Laufbudget: neue Schritte nur unter 50 Anfragen und 120 s (§4.5)
				if ($run->requests >= self::RUN_REQUESTS || microtime(true) - $started >= $budget) {
					$this->release($run, $this->time->getTime());
					return;
				}
				if (!$this->step($run)) {
					return;
				}
			}
		} catch (GraphException $e) {
			$this->onGraphError($run, $e);
		} catch (TokenException $e) {
			// reauth/failed hat ConnectService gesetzt, stale = fremder Schreibvorgang
			if ($e->reason === 'transient') {
				$this->backoff($run, 0);
			}
		} catch (\Throwable $e) {
			// ohne Meldungstext: kann Titel oder SQL-Parameter enthalten
			$this->logger->error('nextnotes: OneNote import step failed', ['import' => $id, 'error' => get_class($e), 'code' => $e->getCode()]);
			$this->backoff($run, 0);
		}
	}

	// Vor jedem Schritt (§4.4.1, §4.7, §4.8): Zeile neu lesen, eigene Lease, Konto, App-Zugriff, Konfiguration
	private function preflight(ImportRun $run): bool {
		try {
			$fresh = $this->imports->findOwn($run->id(), $run->uid());
		} catch (DoesNotExistException) {
			return false;
		}
		if ($fresh->getStatus() !== 'running' || $fresh->getLeaseToken() !== $run->lease) {
			return false;
		}
		$run->load($fresh);
		$user = $this->users->get($run->uid());
		if ($user === null) {
			// Konto gelöscht: keine Wirkung außer dem Entfernen verbliebener Zeilen
			$this->connect->deleteForUser($run->uid());
			return false;
		}
		if (!$user->isEnabled()) {
			$this->connect->toReauth($fresh, 'user_disabled', $run->lease);
			return false;
		}
		if (!$this->apps->isEnabledForUser(Application::APP_ID, $user)) {
			$this->connect->toReauth($fresh, 'app_disabled', $run->lease);
			return false;
		}
		if (!$this->config->enabled()) {
			$this->release($run, $this->time->getTime());
			return false;
		}
		return $this->connect->reconcile($run->uid()) === 0;
	}

	// nächster Schritt aus dem Cursor; false = Lauf endet
	private function step(ImportRun $run): bool {
		$cur = $run->cursor['cur'];
		if ($cur !== null && empty($cur['listed'])) {
			$this->listStep($run);
			return true;
		}
		if ($cur !== null && ($cur['pages'] ?? []) !== []) {
			$this->pageStep($run);
			return true;
		}
		if ($run->cursor['queue'] !== []) {
			$next = array_shift($run->cursor['queue']);
			$run->cursor['cur'] = $next + ['listed' => false, 'next' => null, 'pages' => []];
			$this->listStep($run);
			return true;
		}
		$run->cursor['cur'] = null;
		if ($run->cursor['nb'] < count($run->selection)) {
			$this->structureStep($run);
			return true;
		}
		$this->finish($run);
		return false;
	}

	// Struktur je Notizbuch (§4.4.2): Abruf ohne Transaktion, dann Workspace/Ordner/Items/Warteschlange in einer Transaktion
	private function structureStep(ImportRun $run): void {
		$index = $run->cursor['nb'];
		$sel = $run->selection[$index];
		$url = GraphClient::PREFIX . GraphClient::rootPath((string)$sel['root']) . '/onenote/notebooks/' . rawurlencode((string)$sel['notebook'])
			. '?$expand=sections,sectionGroups(levels=max;$expand=sections)';
		try {
			$nb = $this->fetch($run, $url)->json() ?? [];
		} catch (GraphException $e) {
			$this->skipOnError($run, $e, (string)$sel['name']);
			$run->cursor['nb'] = $index + 1;
			$this->saveOrFail($run);
			return;
		}
		$name = self::name($nb) !== '' ? self::name($nb) : (string)$sel['name'];
		$top = self::children($nb);
		if ($sel['items'] !== null) {
			$wanted = [];
			foreach ($sel['items'] as $item) {
				$wanted[$item['kind'] . ':' . $item['id']] = true;
			}
			$top = array_values(array_filter($top, static fn (array $el): bool => isset($wanted[$el['kind'] . ':' . $el['id']])));
			// gewählt, aber nicht mehr vorhanden
			$missing = count($wanted) - count($top);
			for ($k = 0; $k < $missing; $k++) {
				$run->report($name, 'not_found');
			}
		}
		try {
			$this->inTx($run, function () use ($run, $sel, $nb, $name, $top, $index): void {
				$ws = $this->targetWorkspace($run, (string)$sel['notebook'], $name, PageConverter::timestamp(is_string($nb['lastModifiedDateTime'] ?? null) ? $nb['lastModifiedDateTime'] : null));
				$wsId = (int)$ws->getId();
				$this->folders->lockWorkspace($wsId);
				$queue = [];
				foreach ($top as $order => $el) {
					$this->buildFolders($run, (string)$sel['root'], $index, $wsId, null, $el, $order, $queue, 0);
				}
				$run->cursor['nb'] = $index + 1;
				$run->cursor['queue'] = array_merge($run->cursor['queue'], $queue);
				// abgeschlossener Strukturschritt setzt das Backoff zurück (§4.5)
				$run->backoffLevel = 0;
				$this->save($run);
			});
		} catch (RestructureException) {
			// Item parallel geändert: zurückgerollt, nächster Schritt liest neu
		}
	}

	/**
	 * Ordner für Abschnitt bzw. Abschnittsgruppe (rekursiv); Abschnitte kommen in die Warteschlange
	 * @param array{kind: string, id: string, name: string, node: array<string, mixed>} $el
	 * @param list<array<string, mixed>> $queue
	 */
	private function buildFolders(ImportRun $run, string $root, int $index, int $wsId, ?int $parentId, array $el, int $order, array &$queue, int $depth): void {
		if ($depth > self::MAX_DEPTH) {
			$run->report($el['name'], 'error');
			return;
		}
		$folder = $this->targetFolder($run, $el['kind'], $el['id'], $wsId, $parentId, $el['name'], $order);
		if ($el['kind'] === 'section') {
			$queue[] = ['i' => $index, 'r' => $root, 's' => $el['id'], 'w' => $wsId, 'f' => (int)$folder->getId(), 'n' => mb_substr($el['name'], 0, 80)];
			return;
		}
		foreach (self::children($el['node']) as $childOrder => $child) {
			$this->buildFolders($run, $root, $index, $wsId, (int)$folder->getId(), $child, $childOrder, $queue, $depth + 1);
		}
	}

	/** @return list<array{kind: string, id: string, name: string, node: array<string, mixed>}> Abschnitte, dann Gruppen; ungültige IDs entfallen */
	private static function children(array $node): array {
		$out = [];
		foreach (['sections' => 'section', 'sectionGroups' => 'section_group'] as $prop => $kind) {
			foreach (is_array($node[$prop] ?? null) ? $node[$prop] : [] as $el) {
				$id = is_array($el) && is_string($el['id'] ?? null) ? $el['id'] : '';
				if (GraphClient::validId($id)) {
					$out[] = ['kind' => $kind, 'id' => $id, 'name' => self::name($el), 'node' => $el];
				}
			}
		}
		return $out;
	}

	// Seitenliste je Abschnitt (§4.4.3): $top=100, nextLink folgen, am Ende nach order sortieren
	private function listStep(ImportRun $run): void {
		$cur = $run->cursor['cur'];
		$url = is_string($cur['next'] ?? null) ? $cur['next']
			: GraphClient::PREFIX . GraphClient::rootPath((string)$cur['r']) . '/onenote/sections/' . rawurlencode((string)$cur['s'])
				. '/pages?pagelevel=true&$select=id,title,createdDateTime,lastModifiedDateTime,level,order&$top=100';
		try {
			$data = $this->fetch($run, $url)->json() ?? [];
		} catch (GraphException $e) {
			$this->skipOnError($run, $e, (string)$cur['n']);
			$run->cursor['cur'] = null;
			$this->saveOrFail($run);
			return;
		}
		$pages = is_array($cur['pages'] ?? null) ? $cur['pages'] : [];
		foreach (is_array($data['value'] ?? null) ? $data['value'] : [] as $p) {
			$pid = is_array($p) && is_string($p['id'] ?? null) ? $p['id'] : '';
			$title = is_array($p) && is_string($p['title'] ?? null) ? $p['title'] : '';
			if (!GraphClient::validId($pid)) {
				$run->report($title, 'invalid_id');
				continue;
			}
			$pages[] = ['id' => $pid, 't' => mb_substr($title, 0, 1000), 'c' => (string)($p['createdDateTime'] ?? ''), 'm' => (string)($p['lastModifiedDateTime'] ?? ''),
				'l' => max(0, min(2, (int)($p['level'] ?? 0))), 'o' => (int)($p['order'] ?? 0)];
		}
		$next = self::nextLink($data);
		$cur['pages'] = $pages;
		$cur['next'] = $next;
		if ($next === null) {
			// stabil nach order (usort ist seit PHP 8 stabil)
			usort($pages, static fn (array $a, array $b): int => $a['o'] <=> $b['o']);
			$cur['pages'] = $pages;
			$cur['listed'] = true;
			$run->pagesTotal += count($pages);
		}
		$run->cursor['cur'] = $pages === [] && $next === null ? null : $cur;
		$this->save($run);
	}

	// Je Seite (§4.4.4): vorhandenes Ziel → überspringen; sonst Inhalt holen, konvertieren und in einer Transaktion anlegen
	private function pageStep(ImportRun $run): void {
		$cur = $run->cursor['cur'];
		$page = $cur['pages'][0];
		$hash = OneNoteItemMapper::hash('page', (string)$page['id']);
		$item = $this->items->findBySource($run->uid(), $hash);
		if ($item !== null && $this->noteExists((int)$item->getTargetId())) {
			$this->popPage($run);
			$run->pagesSkipped++;
			$run->notFoundStreak = 0;
			$this->save($run);
			return;
		}
		$title = PageConverter::title((string)$page['t'], (int)$page['l'], $run->l);
		$url = GraphClient::PREFIX . GraphClient::rootPath((string)$cur['r']) . '/onenote/pages/' . rawurlencode((string)$page['id']) . '/content';
		$tooLarge = false;
		try {
			$html = $this->fetch($run, $url, self::PAGE_LIMIT)->body;
		} catch (GraphException $e) {
			if ($e->reason !== 'too_large') {
				$this->skipOnError($run, $e, $title);
				$this->popPage($run);
				$this->saveOrFail($run);
				return;
			}
			$html = '';
			$tooLarge = true;
		}
		$converted = $tooLarge ? null : $this->converter->convert($html, $run->l);
		$content = $converted === null || $converted->tooLarge ? self::tooLargeHint($run->l) : $this->converter->applyResources($converted->html, [], $run->l);
		// M9b: Ressourcen werden noch nicht geholt (M9d) und bleiben Platzhalter
		$placeholders = $converted === null ? 0 : count($converted->resources) + $converted->skippedResources;
		$created = PageConverter::timestamp((string)$page['c']) ?? $this->time->getTime();
		$modified = PageConverter::timestamp((string)$page['m']) ?? $created;
		try {
			$this->inTx($run, function () use ($run, $cur, $item, $hash, $title, $content, $created, $modified, $page, $placeholders, $converted, $tooLarge): void {
				$wsId = (int)$cur['w'];
				if (!$this->ownWorkspace($run->uid(), $wsId)) {
					throw new RestructureException();
				}
				$this->folders->lockWorkspace($wsId);
				$folderId = (int)$cur['f'];
				if (!$this->folderIn($folderId, $wsId)) {
					throw new RestructureException();
				}
				$hint = $tooLarge || ($converted?->tooLarge ?? false);
				try {
					$note = $this->notes->createImported($run->uid(), $wsId, $folderId, $title, $content, $created, $modified, (int)$page['o']);
				} catch (TooLargeException) {
					$note = $this->notes->createImported($run->uid(), $wsId, $folderId, $title, self::tooLargeHint($run->l), $created, $modified, (int)$page['o']);
					$hint = true;
				}
				$this->link($run, $item, $hash, 'page', (int)$note->getId(), $modified);
				if ($hint) {
					$run->report($title, 'too_large');
				}
				$this->popPage($run);
				$run->pagesDone++;
				$run->resourcesFailed += $placeholders;
				$run->notFoundStreak = 0;
				// erst eine angelegte Seite setzt das Backoff zurück (§4.5)
				$run->backoffLevel = 0;
				$this->save($run);
			});
		} catch (RestructureException) {
			// Ziel-Workspace oder -Ordner weg bzw. nicht mehr eigen: Notizbuch neu aufbauen, vorhandene Seiten werden übersprungen
			$this->reload($run);
			$run->cursor = ['nb' => (int)$cur['i'], 'queue' => [], 'cur' => null];
			$this->save($run);
		} catch (DbException $e) {
			if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
			// paralleles Item (UNIQUE): Seite gilt als übersprungen
			$this->reload($run);
			$this->popPage($run);
			$run->pagesSkipped++;
			$this->save($run);
		}
	}

	private function popPage(ImportRun $run): void {
		$cur = $run->cursor['cur'];
		array_shift($cur['pages']);
		$run->cursor['cur'] = $cur['pages'] === [] ? null : $cur;
	}

	// 404/403/sonstige Fehler eines Elements: überspringen mit Bericht; nur 404 zählt zur Serie (§4.4.4); Rest wirft weiter
	private function skipOnError(ImportRun $run, GraphException $e, string $title): void {
		if (in_array($e->graphCode, self::GRAPH_TOO_BIG, true)) {
			$this->save($run);
			$this->connect->fail($run->import, 'graph_10008', $run->lease);
			throw new GraphException('lease');
		}
		switch ($e->reason) {
			case 'not_found':
				$run->report($title, 'not_found');
				$run->notFoundStreak++;
				return;
			case 'forbidden':
				$run->report($title, 'forbidden');
				return;
			case 'http':
			case 'redirect':
			case 'host':
			case 'too_large':
				$run->report($title, 'error');
				return;
		}
		throw $e;
	}

	// speichern; ab 20 Graph-404 in Folge failed/graph_not_found
	private function saveOrFail(ImportRun $run): void {
		$this->save($run);
		if ($run->notFoundStreak >= self::NOT_FOUND_LIMIT) {
			$this->connect->fail($run->import, 'graph_not_found', $run->lease);
			throw new GraphException('lease');
		}
	}

	private function finish(ImportRun $run): void {
		$now = $this->time->getTime();
		$n = $this->imports->updateWhere($run->id(), $run->uid(), ['status' => 'done', 'cursor' => null, 'next_run_at' => null, 'finished_at' => $now,
			'updated_at' => $now] + ConnectService::cleared() + $run->state(), $this->guard($run));
		if ($n === 1) {
			$this->notify->send($run->uid(), $run->id(), OneNoteNotifications::DONE, '',
				['done' => $run->pagesDone, 'skipped' => $run->pagesSkipped, 'reported' => $run->reported()]);
		}
	}

	// Lauf beenden, Lease freigeben; nächster Lauf ab $at
	private function release(ImportRun $run, int $at): void {
		$this->imports->updateWhere($run->id(), $run->uid(), ['status' => 'queued', 'lease_token' => null, 'lease_until' => null, 'next_run_at' => $at],
			$this->guard($run));
	}

	// 429/5xx/Netz (§4.5): Retry-After bzw. 5 min × 2^level, höchstens 60 min; ab Stufe 10 failed
	private function backoff(ImportRun $run, int $retryAt): void {
		$now = $this->time->getTime();
		$level = $run->backoffLevel + 1;
		if ($level >= RateBudget::BACKOFF_FAIL_LEVEL) {
			$this->connect->fail($run->import, 'graph_unavailable', $run->lease);
			return;
		}
		$at = $retryAt > $now ? $retryAt : $now + RateBudget::backoffSeconds($run->backoffLevel);
		$this->imports->updateWhere($run->id(), $run->uid(), ['status' => 'queued', 'lease_token' => null, 'lease_until' => null,
			'next_run_at' => $at, 'backoff_level' => $level], $this->guard($run));
	}

	private function onGraphError(ImportRun $run, GraphException $e): void {
		if ($e->reason === 'lease') {
			return;
		}
		if ($e->reason === 'budget') {
			$this->release($run, max($e->retryAt, $this->time->getTime()));
			return;
		}
		$this->backoff($run, $e->isTransient() ? $e->retryAt : 0);
	}

	/**
	 * Graph-GET mit Token-Erneuerung (§4.6): vor der Anfrage bei Ablauf < 5 min, nach 401 einmal Refresh und Wiederholung, dann reauth
	 * @throws GraphException|TokenException
	 */
	private function fetch(ImportRun $run, string $url, int $limit = HttpGate::JSON_LIMIT): HttpResult {
		$run->requests++;
		$token = $this->connect->accessToken($run->import, $run->lease);
		try {
			return $this->graph->get($run->import, $token, $url, $run->lease, $limit);
		} catch (GraphException $e) {
			if ($e->reason !== 'unauthorized') {
				throw $e;
			}
		}
		$token = $this->connect->refresh($run->import, $run->lease);
		$run->requests++;
		try {
			return $this->graph->get($run->import, $token, $url, $run->lease, $limit);
		} catch (GraphException $e) {
			if ($e->reason === 'unauthorized') {
				$this->connect->toReauth($run->import, 'invalid_grant', $run->lease);
				throw new TokenException('reauth');
			}
			throw $e;
		}
	}

	// ---------- Schreiben (an die Lease gebunden) ----------

	/** @return array<string, string> */
	private function guard(ImportRun $run): array {
		return ['lease_token' => (string)$run->lease, 'status' => 'running'];
	}

	// Fortschritt als bedingtes UPDATE (Autocommit oder innerhalb inTx); 0 Zeilen → Lauf endet ohne Wirkung
	private function save(ImportRun $run): void {
		$n = $this->imports->updateWhere($run->id(), $run->uid(), $run->state() + ['updated_at' => $this->time->getTime()], $this->guard($run));
		if ($n !== 1) {
			throw new GraphException('lease');
		}
	}

	// Transaktion nur für die abschließenden Schreibvorgänge, erste Anweisung = Lease-Check (§2 Job)
	private function inTx(ImportRun $run, callable $fn): void {
		$this->tx->run(function () use ($run, $fn): void {
			if ($this->imports->updateWhere($run->id(), $run->uid(), ['updated_at' => $this->time->getTime()], $this->guard($run)) !== 1) {
				throw new GraphException('lease');
			}
			$fn();
		});
	}

	private function reload(ImportRun $run): void {
		try {
			$fresh = $this->imports->findOwn($run->id(), $run->uid());
		} catch (DoesNotExistException) {
			throw new GraphException('lease');
		}
		if ($fresh->getStatus() !== 'running' || $fresh->getLeaseToken() !== $run->lease) {
			throw new GraphException('lease');
		}
		$run->load($fresh);
	}

	// Item anlegen bzw. Ziel bedingt ersetzen (§3); in der Transaktion des Ziels
	private function link(ImportRun $run, ?OneNoteItem $item, string $hash, string $kind, int $target, ?int $modified): void {
		if ($item === null) {
			$this->items->add($run->uid(), $hash, $kind, $target, $modified, $run->id(), $this->time->getTime());
			return;
		}
		if ($this->items->replaceTarget($run->uid(), $hash, (int)$item->getTargetId(), $target, $run->id()) !== 1) {
			throw new RestructureException();
		}
	}

	// Notizbuch → eigener Workspace kind=normal (§3): aus dem Item, sonst neu; Team-Workspaces nie
	private function targetWorkspace(ImportRun $run, string $graphId, string $name, ?int $modified): Workspace {
		$hash = OneNoteItemMapper::hash('notebook', $graphId);
		$item = $this->items->findBySource($run->uid(), $hash);
		if ($item !== null && $this->ownWorkspace($run->uid(), (int)$item->getTargetId())) {
			return $this->workspaceRows->find((int)$item->getTargetId());
		}
		$ws = $this->workspaces->createImported($run->uid(), $name !== '' ? $name : 'OneNote');
		$this->link($run, $item, $hash, 'notebook', (int)$ws->getId(), $modified);
		return $ws;
	}

	private function targetFolder(ImportRun $run, string $kind, string $graphId, int $wsId, ?int $parentId, string $name, int $order): Folder {
		$hash = OneNoteItemMapper::hash($kind, $graphId);
		$item = $this->items->findBySource($run->uid(), $hash);
		if ($item !== null && $this->folderIn((int)$item->getTargetId(), $wsId)) {
			return $this->folderRows->find((int)$item->getTargetId());
		}
		$folder = $this->folders->createImported($wsId, $parentId, $name !== '' ? $name : 'OneNote', $order);
		$this->link($run, $item, $hash, $kind, (int)$folder->getId(), null);
		return $folder;
	}

	private function ownWorkspace(string $uid, int $wsId): bool {
		try {
			$ws = $this->workspaceRows->find($wsId);
		} catch (DoesNotExistException) {
			return false;
		}
		return $ws->getOwnerType() === 'user' && $ws->getOwnerId() === $uid && $ws->getKind() === 'normal';
	}

	private function folderIn(int $folderId, int $wsId): bool {
		try {
			$f = $this->folderRows->find($folderId);
		} catch (DoesNotExistException) {
			return false;
		}
		return (int)$f->getWorkspaceId() === $wsId && $f->getTrashedAt() === null;
	}

	// Zielnotiz vorhanden, auch im Papierkorb (§3)
	private function noteExists(int $noteId): bool {
		try {
			$this->noteRows->find($noteId);
			return true;
		} catch (DoesNotExistException) {
			return false;
		}
	}

	// ---------- Aufräumen (§3 Lebenszyklus), auch bei abgeschalteter Funktion ----------

	public function cleanup(): void {
		$now = $this->time->getTime();
		foreach ($this->imports->findStale(['auth'], 'auth_expires_at', $now) as $i) {
			$this->imports->deleteWhere((int)$i->getId(), (string)$i->getUid(), ['status' => 'auth', 'updated_at' => (int)$i->getUpdatedAt()]);
		}
		$expire = ['select' => self::SELECT_TTL, 'reauth' => self::REAUTH_TTL];
		foreach ($expire as $status => $ttl) {
			foreach ($this->imports->findStale([$status], 'updated_at', $now - $ttl) as $i) {
				$this->imports->updateWhere((int)$i->getId(), (string)$i->getUid(), ConnectService::cleared()
					+ ['status' => 'expired', 'finished_at' => $now, 'updated_at' => $now], ['status' => $status, 'updated_at' => (int)$i->getUpdatedAt()]);
			}
		}
		foreach ($this->imports->findStale(['queued', 'running'], 'updated_at', $now - self::STALL_TTL) as $i) {
			if ((int)$i->getLeaseUntil() > $now) {
				continue;
			}
			$n = $this->imports->updateWhere((int)$i->getId(), (string)$i->getUid(), ConnectService::cleared()
				+ ['status' => 'failed', 'error_code' => 'stalled', 'finished_at' => $now, 'updated_at' => $now],
				['status' => (string)$i->getStatus(), 'updated_at' => (int)$i->getUpdatedAt()]);
			if ($n === 1) {
				$this->notify->send((string)$i->getUid(), (int)$i->getId(), OneNoteNotifications::FAILED, 'stalled');
			}
		}
		foreach ($this->imports->findStale(self::FINISHED, 'finished_at', $now - ConnectService::KEEP_DAYS * 86400) as $i) {
			$this->imports->deleteWhere((int)$i->getId(), (string)$i->getUid(), ['status' => (string)$i->getStatus()]);
		}
	}

	// ---------- Hilfen ----------

	private function l(?IUser $user): IL10N {
		return $this->l10n->get(Application::APP_ID, $user !== null ? $this->l10n->getUserLanguage($user) : null);
	}

	/** @param array<string, mixed> $data */
	private static function nextLink(array $data): ?string {
		$next = $data['@odata.nextLink'] ?? null;
		return is_string($next) && GraphClient::nextLinkAllowed($next) ? $next : null;
	}

	private static function name(mixed $el): string {
		$n = is_array($el) && is_string($el['displayName'] ?? null) ? trim($el['displayName']) : '';
		return mb_substr($n, 0, 255);
	}

	// Seiten-HTML über 16 MiB (Abbruch beim Lesen): gleicher Hinweis wie der Konverter
	private static function tooLargeHint(IL10N $l): string {
		return '<p>' . htmlspecialchars($l->t('This OneNote page was too large or too complex to import.'), ENT_QUOTES, 'UTF-8') . '</p>';
	}
}
