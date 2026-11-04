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
use OCA\NextNotes\Service\Exception\ConflictException;
use OCA\NextNotes\Service\Exception\NotFoundException;
use OCA\NextNotes\Service\Exception\TooLargeException;
use OCA\NextNotes\Service\View\PageTranslator;
use OCA\NextNotes\Service\View\TrashViewService;
use OCP\IDateTimeZone;
use OCP\IL10N;

/**
 * Tagebuch und Kalender (Upstream lib/diary.php, api/v1/calendar/*, api/v1/diary/*, NotesController-Automatik).
 * Tagebuch = Wurzelordner mit is_diary; Einträge = Notizen im Teilbaum mit Datumstitel. Lesen ohne Schreiben (B61).
 */
class DiaryService {
	public const MAX_IDS = 50;
	// Upstream getDiaryRootFolderName: Standardnamen aller Sprachen (diary.folder_name)
	private const ROOT_NAMES = ['Diary', 'Journal', 'Tagebuch', 'Diario', 'Diário', 'Дневник', '日记', '일기', '日記', 'Diari'];

	public function __construct(
		private FolderMapper $folders,
		private NoteMapper $notes,
		private AccessService $access,
		private WorkspaceService $workspaces,
		private FolderService $folderService,
		private SettingsService $settings,
		private InputValidator $validator,
		private DiaryDates $dates,
		private ConversionService $conversion,
		private ContentUrlRewriter $urls,
		private PageTranslator $tr,
		private IDateTimeZone $timeZone,
		private IL10N $l,
		private Transaction $tx,
	) {
	}

	// ---------- Ordner ----------

	/** @return array<int, Folder> Ordner des Workspace ohne Papierkorb */
	public function folderMap(int $wsId): array {
		$out = [];
		foreach ($this->folders->findInWorkspace($wsId) as $f) {
			if ($f->getTrashedAt() === null) {
				$out[(int)$f->getId()] = $f;
			}
		}
		return $out;
	}

	/**
	 * Tagebücher des Workspace in Seitenleisten-Reihenfolge; ohne markierten Ordner der Standardname als Tagebuch (ohne Schreiben)
	 * @param array<int, Folder> $byId
	 * @return list<array{id: int, name: string}>
	 */
	public function roots(string $uid, array $byId): array {
		$roots = array_values(array_filter($byId, static fn (Folder $f) => $f->getParentId() === null && (int)$f->getIsDiary() === 1));
		if ($roots === []) {
			$legacy = $this->legacyRoot($uid, $byId);
			return $legacy === null ? [] : [['id' => (int)$legacy->getId(), 'name' => (string)$legacy->getName()]];
		}
		usort($roots, static function (Folder $a, Folder $b): int {
			$oa = (int)$a->getDisplayOrder() > 0 ? (int)$a->getDisplayOrder() : 999999;
			$ob = (int)$b->getDisplayOrder() > 0 ? (int)$b->getDisplayOrder() : 999999;
			return $oa <=> $ob ?: strcasecmp((string)$a->getName(), (string)$b->getName()) ?: (int)$a->getId() <=> (int)$b->getId();
		});
		return array_map(static fn (Folder $f) => ['id' => (int)$f->getId(), 'name' => (string)$f->getName()], $roots);
	}

	/** @param array<int, Folder> $byId */
	private function legacyRoot(string $uid, array $byId): ?Folder {
		$byName = [];
		foreach ($byId as $f) {
			if ($f->getParentId() === null) {
				$byName[(string)$f->getName()] ??= $f;
			}
		}
		foreach ($this->rootNameCandidates($uid) as $name) {
			if (isset($byName[$name])) {
				return $byName[$name];
			}
		}
		return null;
	}

	/** @return list<string> eingestellter Name, sonst lokalisierter Standard und die Standardnamen aller Sprachen */
	private function rootNameCandidates(string $uid): array {
		$setting = trim($this->settings->get($uid, 'diary_folder', ''));
		if ($setting !== '') {
			return [$setting];
		}
		return array_values(array_unique([$this->localizedRootName($uid), ...self::ROOT_NAMES]));
	}

	private function localizedRootName(string $uid): string {
		$this->tr->setLanguage($this->tr->languageFor($this->settings->read($uid, 'language')));
		$name = trim($this->tr->t('diary.folder_name', [], 'Diary'));
		return $name !== '' ? $name : 'Diary';
	}

	/** Upstream getDiaryRootFolderName: Name des Standard-Tagebuchs (vorhandener Altname bleibt) */
	public function defaultRootName(string $uid, array $byId): string {
		return $this->legacyRoot($uid, $byId)?->getName() ?? $this->rootNameCandidates($uid)[0];
	}

	/**
	 * Ordner-IDs der Teilbäume (alle Tagebücher oder eines)
	 * @param array<int, Folder> $byId
	 * @param list<array{id: int, name: string}> $roots
	 * @return int[]
	 */
	public static function subtreeIds(array $byId, array $roots, ?int $rootId = null): array {
		$children = [];
		foreach ($byId as $id => $f) {
			$children[(int)$f->getParentId()][] = $id;
		}
		$queue = [];
		foreach ($roots as $root) {
			if ($rootId === null || $root['id'] === $rootId) {
				$queue[] = $root['id'];
			}
		}
		$out = [];
		while ($queue !== []) {
			$id = array_shift($queue);
			if (isset($out[$id])) {
				continue;
			}
			$out[$id] = true;
			array_push($queue, ...($children[$id] ?? []));
		}
		return array_keys($out);
	}

	/** @param array<int, Folder> $byId */
	private static function rootFolderOf(?int $folderId, array $byId): ?Folder {
		for ($depth = 0; $folderId !== null && isset($byId[$folderId]) && $depth < 100; $depth++) {
			$f = $byId[$folderId];
			if ($f->getParentId() === null) {
				return $f;
			}
			$folderId = (int)$f->getParentId();
		}
		return null;
	}

	/**
	 * Einträge eines Tagebuchs (ohne Inhalt)
	 * @param int[] $folderIds
	 * @return Note[]
	 */
	public function entryNotes(int $wsId, array $folderIds): array {
		return $folderIds === [] ? [] : $this->notes->findList([$wsId], ['folder_ids' => $folderIds]);
	}

	/** @param Note[] $notes erste Notiz (kleinste ID) mit Datumstitel = $iso */
	private function entryForDate(string $uid, array $notes, string $iso): ?int {
		foreach ($notes as $n) {
			if ($this->dates->parseTitle($uid, (string)$n->getHeading()) === $iso) {
				return (int)$n->getId();
			}
		}
		return null;
	}

	public function today(string $uid): \DateTimeImmutable {
		return new \DateTimeImmutable('now', $this->timeZone->getTimeZone(false, $uid));
	}

	// ---------- api/v1/calendar ----------

	/** @return array<string, mixed> GET calendar/diary-entry.php */
	public function lookup(string $uid, mixed $rawDate, mixed $wsKey, mixed $rawDiary): array {
		$date = $this->validator->date($rawDate, 'date');
		$ws = $this->workspace($uid, $wsKey);
		$wsId = (int)$ws->getId();
		$key = (string)$this->workspaces->keyFor($uid, $wsId);
		$byId = $this->folderMap($wsId);
		$roots = $this->roots($uid, $byId);
		$type = $this->dates->defaultNoteType($uid);
		$title = $this->dates->formatTitle($uid, $date);
		$folder = '/' . substr($date, 0, 4) . '/' . substr($date, 5, 2);
		$notes = $this->entryNotes($wsId, self::subtreeIds($byId, $roots));
		$byFolder = [];
		foreach ($notes as $n) {
			$byFolder[(int)$n->getFolderId()][] = $n;
		}
		$diaries = [];
		foreach ($roots as $root) {
			$inRoot = [];
			foreach (self::subtreeIds($byId, $roots, $root['id']) as $fid) {
				array_push($inRoot, ...($byFolder[$fid] ?? []));
			}
			usort($inRoot, static fn (Note $a, Note $b) => (int)$a->getId() <=> (int)$b->getId());
			$entryId = $this->entryForDate($uid, $inRoot, $date);
			$diaries[] = ['id' => $root['id'], 'name' => $root['name'], 'exists' => $entryId !== null, 'noteId' => $entryId,
				'folder' => $root['name'] . $folder, 'workspace' => $key, 'noteType' => $type, 'title' => $title];
		}
		if ($diaries === []) {
			$name = $this->defaultRootName($uid, $byId);
			$diaries[] = ['id' => null, 'name' => $name, 'exists' => false, 'noteId' => null,
				'folder' => $name . $folder, 'workspace' => $key, 'noteType' => $type, 'title' => $title];
		}
		$selected = $diaries[0];
		$wanted = is_scalar($rawDiary) ? (int)$rawDiary : 0;
		if ($wanted > 0) {
			// Unbekanntes oder fremdes Tagebuch → 404 (Upstream fiel still auf das erste zurück)
			$hit = array_values(array_filter($diaries, static fn (array $d) => $d['id'] === $wanted));
			$selected = $hit[0] ?? throw new NotFoundException('Diary not found');
		}
		return ['exists' => $selected['exists'], 'id' => $selected['noteId'], 'folder' => $selected['folder'], 'workspace' => $key,
			'noteType' => $type, 'title' => $title, 'diaries' => $diaries];
	}

	/**
	 * GET calendar/notes-by-date.php: Anzahl je Tag (Zeitzone des Benutzers); mode=both (P25) liefert beide in einer Antwort
	 * @return array<string, mixed>
	 */
	public function countsByDate(string $uid, mixed $wsKey, mixed $mode): array {
		$tz = $this->timeZone->getTimeZone(false, $uid);
		$created = [];
		$modified = [];
		foreach ($this->notes->calendarStamps($this->scope($uid, $wsKey)) as [$c, $u]) {
			$day = (new \DateTimeImmutable('@' . $c))->setTimezone($tz)->format('Y-m-d');
			$created[$day] = ($created[$day] ?? 0) + 1;
			// gleiche Bedingung wie notes-on-date?mode=modified (updated_at)
			$day = (new \DateTimeImmutable('@' . $u))->setTimezone($tz)->format('Y-m-d');
			$modified[$day] = ($modified[$day] ?? 0) + 1;
		}
		return match ($mode) {
			'both' => ['created' => (object)$created, 'modified' => (object)$modified],
			'modified' => $modified,
			default => $created,
		};
	}

	/** @return list<array{id: int, title: string}> GET calendar/notes-on-date.php */
	public function notesOnDate(string $uid, mixed $rawDate, mixed $wsKey, mixed $mode): array {
		$date = $this->validator->date($rawDate, 'date');
		$tz = $this->timeZone->getTimeZone(false, $uid);
		$from = (new \DateTimeImmutable($date . ' 00:00:00', $tz))->getTimestamp();
		$to = (new \DateTimeImmutable($date . ' 00:00:00', $tz))->modify('+1 day')->getTimestamp() - 1;
		$byUpdated = $mode === 'modified';
		$notes = $this->notes->findList($this->scope($uid, $wsKey), $byUpdated
			? ['updated_from' => $from, 'updated_to' => $to] : ['created_from' => $from, 'created_to' => $to]);
		$ts = static fn (Note $n): int => $byUpdated ? (int)$n->getUpdatedAt() : (int)$n->getCreatedAt();
		usort($notes, static fn (Note $a, Note $b) => $ts($a) <=> $ts($b) ?: (int)$a->getId() <=> (int)$b->getId());
		return array_map(static fn (Note $n) => ['id' => (int)$n->getId(), 'title' => (string)$n->getHeading() !== '' ? (string)$n->getHeading() : 'Untitled'], $notes);
	}

	/** @return int[] angefragter Workspace (Leserecht) oder alle sichtbaren */
	private function scope(string $uid, mixed $wsKey): array {
		$ws = $this->workspaces->resolveOptional($uid, $wsKey);
		return $ws !== null ? [(int)$ws->getId()] : $this->access->visibleWorkspaceIds($uid);
	}

	// Leerer Schlüssel → Standard-Workspace (Upstream getFirstWorkspaceName), fremd/unbekannt → 404
	private function workspace(string $uid, mixed $wsKey, string $perm = 'read'): Workspace {
		return $this->workspaces->resolveOptional($uid, $wsKey, $perm) ?? $this->workspaces->defaultWorkspace($uid);
	}

	// ---------- api/v1/diary ----------

	/** @return array{diaries: list<array{id: int, name: string}>} GET diary/diaries.php */
	public function diaries(string $uid, mixed $wsKey): array {
		$ws = $this->workspace($uid, $wsKey);
		return ['diaries' => $this->roots($uid, $this->folderMap((int)$ws->getId()))];
	}

	/** @return array<string, mixed> POST diary/diaries.php: Wurzelordner anlegen oder vorhandenen markieren */
	public function createDiary(string $uid, array $in): array {
		$ws = $this->workspace($uid, $in['workspace'] ?? null, 'write');
		$wsId = (int)$ws->getId();
		$key = (string)$this->workspaces->keyFor($uid, $wsId);
		$name = $this->validator->folderName(is_scalar($in['name'] ?? null) ? $in['name'] : '');
		return $this->tx->run(function () use ($uid, $wsId, $key, $name): array {
			$byId = $this->folderMap($wsId);
			$existing = $this->folders->findSibling($wsId, null, $name);
			if ($existing !== null && (int)$existing->getIsDiary() === 1) {
				throw new ConflictException('A diary with this name already exists', ['code' => 'diary_exists']);
			}
			// Altes Standard-Tagebuch erst markieren, sonst gälte es neben dem neuen nicht mehr als Tagebuch (wie Upstream)
			$legacy = array_filter($byId, static fn (Folder $f) => (int)$f->getIsDiary() === 1) === [] ? $this->legacyRoot($uid, $byId) : null;
			if ($legacy !== null && $legacy->getId() !== $existing?->getId()) {
				$this->markDiary($legacy);
			}
			[$result] = $this->folderService->create($uid, ['folder_name' => $name, 'workspace' => $key, 'is_diary' => true]);
			return ['success' => true, 'converted' => !empty($result['converted']),
				'diary' => ['id' => (int)$result['folder_id'], 'name' => $name], 'workspace' => $key];
		});
	}

	/**
	 * GET diary/entries.php: gerenderte Einträge (nur Tagebuch-Teilbaum des Workspace), Budget je Request wie der Papierkorb.
	 * Über dem Budget: ID in truncated (Frontend fragt nach, P26); einzeln über MAX_RENDER_INPUT: Hinweis mit Link.
	 * @return array{entries: object, truncated: list<int>}
	 */
	public function entries(string $uid, mixed $rawIds, mixed $wsKey): array {
		$ids = [];
		foreach (explode(',', is_scalar($rawIds) ? (string)$rawIds : '') as $raw) {
			$id = (int)trim($raw);
			if ($id > 0) {
				$ids[$id] = $id;
			}
		}
		if ($ids === [] || count($ids) > self::MAX_IDS) {
			throw new BadRequestException('Pass between 1 and ' . self::MAX_IDS . ' note ids');
		}
		$ws = $this->workspace($uid, $wsKey);
		$wsId = (int)$ws->getId();
		$byId = $this->folderMap($wsId);
		$inDiary = array_flip(self::subtreeIds($byId, $this->roots($uid, $byId)));
		$found = [];
		foreach ($this->notes->findListByIds(array_values($ids)) as $n) {
			if ((int)$n->getWorkspaceId() === $wsId && $n->getRole() === 'note' && $n->getTrashedAt() === null && isset($inDiary[(int)$n->getFolderId()])) {
				$found[(int)$n->getId()] = $n;
			}
		}
		// Ziele verknüpfter Einträge: nur lesbar und nicht selbst verknüpft
		$linkIds = array_values(array_filter(array_map(static fn (Note $n) => $n->getType() === 'linked' ? (int)$n->getLinkedNoteId() : 0, $found)));
		$targets = [];
		foreach ($this->access->filterReadable($uid, $linkIds === [] ? [] : $this->notes->findListByIds($linkIds)) as $t) {
			if ($t->getTrashedAt() === null && $t->getType() !== 'linked') {
				$targets[(int)$t->getId()] = $t;
			}
		}
		$source = [];
		foreach ($found as $id => $n) {
			$source[$id] = $n->getType() === 'linked' ? ($targets[(int)$n->getLinkedNoteId()] ?? null) : $n;
		}
		$bytes = $this->notes->contentBytes(array_map(static fn (Note $s) => (int)$s->getId(), array_filter($source)));
		$entries = [];
		$truncated = [];
		$render = [];
		$budget = TrashViewService::RENDER_BUDGET;
		foreach ($ids as $id) {
			if (!isset($found[$id])) {
				continue;
			}
			$s = $source[$id];
			if ($s === null) {
				$entries[(string)$id] = '';
				continue;
			}
			$size = $bytes[(int)$s->getId()] ?? 0;
			if ($size > ConversionService::MAX_RENDER_INPUT) {
				$entries[(string)$id] = $this->tooLarge($uid, $id, $ws);
				continue;
			}
			if ($size > $budget) {
				$truncated[] = $id;
				continue;
			}
			$budget -= $size;
			$render[$id] = $s;
		}
		$contents = $this->notes->contentByIds(array_map(static fn (Note $s) => (int)$s->getId(), array_values($render)));
		foreach ($render as $id => $s) {
			try {
				$entries[(string)$id] = $this->renderEntry($uid, (string)$s->getType(), $contents[(int)$s->getId()] ?? '');
			} catch (TooLargeException|BadRequestException) {
				$entries[(string)$id] = $this->tooLarge($uid, $id, $ws);
			}
		}
		// Reihenfolge der Anfrage
		$ordered = [];
		foreach ($ids as $id) {
			if (array_key_exists((string)$id, $entries)) {
				$ordered[(string)$id] = $entries[(string)$id];
			}
		}
		return ['entries' => (object)$ordered, 'truncated' => $truncated];
	}

	// Upstream renderDiaryJournalContent: nur Lesen; HTML ist beim Speichern bereinigt, Markdown über den Renderer (Sanitizer)
	private function renderEntry(string $uid, string $type, string $content): string {
		if (trim($content) === '') {
			return '';
		}
		if ($type === 'tasklist') {
			$html = '';
			foreach (TaskService::cleanTasks($content) as $task) {
				$text = trim($task['text']);
				if ($text === '') {
					continue;
				}
				$classes = trim(($task['completed'] ? 'completed' : '') . ($task['important'] ? ' important' : ''));
				$html .= '<li' . ($classes !== '' ? ' class="' . $classes . '"' : '') . '><input type="checkbox" disabled' . ($task['completed'] ? ' checked' : '') . '> '
					. '<span>' . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
				$subs = '';
				foreach ($task['subtasks'] ?? [] as $sub) {
					$subs .= '<li' . ($sub['completed'] ? ' class="completed"' : '') . '><input type="checkbox" disabled' . ($sub['completed'] ? ' checked' : '') . '> '
						. '<span>' . htmlspecialchars((string)$sub['text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span></li>';
				}
				$html .= ($subs !== '' ? '<ul class="diary-journal-subtasks">' . $subs . '</ul>' : '') . '</li>';
			}
			return $html !== '' ? '<ul class="diary-journal-tasks">' . $html . '</ul>' : '';
		}
		if ($type === 'markdown') {
			return $this->conversion->renderSnapshot($uid, $content);
		}
		return $this->urls->toClient($content);
	}

	// Link über den Workspace-Schlüssel: Namen können in geteilten Workspaces doppelt sein (§4.4)
	private function tooLarge(string $uid, int $id, Workspace $ws): string {
		$url = 'index.php?' . http_build_query(['note' => $id, 'newtab' => 1, 'workspace' => (string)$this->workspaces->keyFor($uid, (int)$ws->getId())]);
		return '<p class="diary-journal-too-large">' . htmlspecialchars($this->l->t('This entry is too large for the journal view.'), ENT_QUOTES, 'UTF-8')
			. ' <a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($this->l->t('Open note'), ENT_QUOTES, 'UTF-8') . '</a></p>';
	}

	// ---------- Automatik (POST/PATCH /notes, Upstream NotesController:1075, :1357) ----------

	/**
	 * Neue leere Notiz mit Datumstitel in einem Tagebuch → Inhalt der Vorlage (diary_templates); markiert das Standard-Tagebuch beim ersten Eintrag (B61)
	 */
	public function initialContent(string $uid, Workspace $ws, ?int $folderId, string $heading, string $type): ?string {
		if ($folderId === null || $this->dates->parseTitle($uid, $heading) === null) {
			return null;
		}
		$wsId = (int)$ws->getId();
		$byId = $this->folderMap($wsId);
		$root = self::rootFolderOf($folderId, $byId);
		if ($root === null) {
			return null;
		}
		// Standard-Tagebuch ohne Markierung (per Pfad angelegt oder Altbestand): beim ersten Eintrag markieren (B61)
		if ((int)$root->getIsDiary() !== 1) {
			if (($this->roots($uid, $byId)[0]['id'] ?? null) !== (int)$root->getId()) {
				return null;
			}
			$this->markDiary($root);
		}
		return $this->templateContent($uid, $ws, $type);
	}

	// Schreibrecht hat der Aufrufer geprüft (Workspace write)
	private function markDiary(Folder $folder): void {
		$folder->setIsDiary(1);
		$this->folders->update($folder);
	}

	// Upstream getDiaryTemplateContent: Vorlage nur aus demselben Workspace, nur HTML/Markdown, Typ bei Bedarf umgewandelt
	private function templateContent(string $uid, Workspace $ws, string $type): ?string {
		if (!in_array($type, ['note', 'markdown'], true)) {
			return null;
		}
		$templates = json_decode($this->settings->get($uid, 'diary_templates', ''), true);
		$templateId = is_array($templates) ? ($templates[(string)$ws->getId()] ?? null) : null;
		if (!is_scalar($templateId) || !ctype_digit((string)$templateId) || (int)$templateId <= 0) {
			return null;
		}
		$found = $this->notes->findByIds([(int)$templateId]);
		$tpl = $found[0] ?? null;
		if ($tpl === null || (int)$tpl->getWorkspaceId() !== (int)$ws->getId() || $tpl->getRole() !== 'note' || $tpl->getTrashedAt() !== null) {
			return null;
		}
		$tplType = (string)($tpl->getType() ?: 'note');
		$content = (string)$tpl->getContent();
		if (!in_array($tplType, ['note', 'markdown'], true) || trim($content) === '') {
			return null;
		}
		// Wie der Umwandlungszweig im Client-Format: create() speichert über applyContent (toServer)
		$content = $this->urls->toClient($content);
		if ($tplType === $type) {
			return $content;
		}
		return $type === 'markdown' ? $this->conversion->htmlToMarkdown($uid, $content) : $this->conversion->markdownToHtml($uid, $content);
	}

	/**
	 * Titeländerung auf ein Datum → Ordner Tagebuch/JJJJ/MM desselben Tagebuchs (bei Bedarf angelegt); null = bleibt
	 */
	public function dateFolder(string $uid, Workspace $ws, ?int $currentFolderId, string $newHeading): ?int {
		$date = $this->dates->parseTitle($uid, $newHeading);
		if ($date === null || $currentFolderId === null) {
			return null;
		}
		$byId = $this->folderMap((int)$ws->getId());
		$root = self::rootFolderOf($currentFolderId, $byId);
		if ($root === null || !in_array((int)$root->getId(), array_column($this->roots($uid, $byId), 'id'), true)) {
			return null;
		}
		$target = $this->folderService->resolvePath($ws, $root->getName() . '/' . substr($date, 0, 4) . '/' . substr($date, 5, 2), true);
		return $target !== null && $target !== $currentFolderId ? $target : null;
	}
}
