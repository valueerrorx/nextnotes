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
use OCA\NextNotes\Db\Note;
use OCA\NextNotes\Db\NoteMapper;
use OCA\NextNotes\Db\NoteTagMapper;
use OCA\NextNotes\Db\LockMapper;
use OCA\NextNotes\Db\MentionHandledMapper;
use OCA\NextNotes\Db\ReminderMapper;
use OCA\NextNotes\Db\SnapshotMapper;
use OCA\NextNotes\Db\Workspace;
use OCA\NextNotes\Service\Exception\BadRequestException;
use OCA\NextNotes\Service\Exception\ConflictException;
use OCA\NextNotes\Service\Exception\ForbiddenException;
use OCA\NextNotes\Service\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDateTimeZone;
use OCP\IL10N;
use OCP\Notification\IManager as INotificationManager;

// Notizen (Upstream NotesController + notizbezogene Routen aus FoldersController)
class NoteService {
	public const MAX_PAGE = 1000;
	private const OPENED_KEY = SettingsService::OPENED_KEY;
	private const OPENED_LIMIT = 50;
	private const TEMPLATE_NAMES = ['templates', 'template', 'modèles', 'modèle', 'modeles', 'modele', 'vorlagen', 'vorlage',
		'plantillas', 'plantilla', 'modelos', 'modelo', 'шаблоны', 'шаблон', '模板', '템플릿', 'テンプレート', 'plantilles'];

	public function __construct(
		private NoteMapper $notes,
		private FolderMapper $folders,
		private NoteTagMapper $tagRows,
		private AccessService $access,
		private WorkspaceService $workspaces,
		private FolderService $folderService,
		private TagService $tags,
		private SettingsService $settings,
		private NoteSerializer $serializer,
		private HtmlSanitizer $sanitizer,
		private SearchTextNormalizer $search,
		private ContentUrlRewriter $urls,
		private InputValidator $validator,
		private Transaction $tx,
		private ITimeFactory $time,
		private IDateTimeZone $timeZone,
		private IL10N $l,
		private AttachmentService $attachments,
		private SnapshotService $snapshots,
		private SnapshotMapper $snapshotRows,
		private LockService $locks,
		private LockMapper $lockRows,
		private ReminderMapper $reminders,
		private MentionHandledMapper $mentions,
		private INotificationManager $notifications,
		private EmbeddedImageService $images,
		private MentionService $mentionService,
		private QuotaService $quota,
	) {
	}

	// ---------- Hilfen ----------

	private function now(): int {
		return $this->time->getTime();
	}

	// Inhalt prüfen (Größe, UTF-8), säubern, URLs kanonisch, search_text neu
	private function applyContent(Note $note, string $content): void {
		$content = $this->validator->content($content);
		$type = (string)$note->getType();
		// Editor-Hülle: Inhalt bis zum ersten </div> (wie Upstream-Regex, per strpos linear, B37)
		$open = $type === 'markdown' ? strpos($content, '<div class="markdown-editor"') : false;
		$inner = $open !== false ? strpos($content, '>', $open) : false;
		$close = $inner !== false ? strpos($content, '</div>', $inner) : false;
		if ($close !== false) {
			$content = html_entity_decode(strip_tags(substr($content, $inner + 1, $close - $inner - 1)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		}
		$content = $this->sanitizer->sanitizeForType($type, $this->urls->toServer($content));
		$this->validator->storable($content);
		$note->setContent($content);
		$this->refreshSearch($note);
	}

	private function refreshSearch(Note $note): void {
		$note->setSearchText($this->search->build($note->getHeading(), $note->getContent(), (string)$note->getType()));
	}

	// Upstream generateUniqueTitle: „Titel (n)“
	private function uniqueHeading(int $wsId, ?int $folderId, string $heading, ?int $excludeId = null): string {
		$heading = $heading === '' ? $this->l->t('New note') : $heading;
		if (!$this->notes->headingExists($wsId, $folderId, $heading, $excludeId)) {
			return $heading;
		}
		for ($i = 1; ; $i++) {
			$candidate = $heading . ' (' . $i . ')';
			if (!$this->notes->headingExists($wsId, $folderId, $candidate, $excludeId)) {
				return $candidate;
			}
		}
	}

	private function dateFilter(string $raw, bool $end): ?int {
		$raw = trim($raw);
		if ($raw === '') {
			return null;
		}
		if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
			throw new BadRequestException(($end ? 'created_to' : 'created_from') . ' must use YYYY-MM-DD format');
		}
		$d = new \DateTime($raw . ($end ? ' 23:59:59' : ' 00:00:00'), $this->timeZone->getTimeZone());
		return $d->getTimestamp();
	}

	// ---------- Listen ----------

	/** @return array<string, mixed> GET /notes */
	public function index(string $uid, array $q): array {
		$limit = null;
		$limitRaw = trim((string)($q['limit'] ?? ''));
		if ($limitRaw !== '') {
			if (!ctype_digit($limitRaw) || (int)$limitRaw < 1 || (int)$limitRaw > self::MAX_PAGE) {
				throw new BadRequestException('limit must be an integer between 1 and ' . self::MAX_PAGE);
			}
			$limit = (int)$limitRaw;
		}
		$offsetRaw = trim((string)($q['offset'] ?? ''));
		if ($offsetRaw !== '' && !ctype_digit($offsetRaw)) {
			throw new BadRequestException('offset must be a non-negative integer');
		}
		$offset = (int)$offsetRaw;
		$from = $this->dateFilter((string)($q['created_from'] ?? ''), false);
		$to = $this->dateFilter((string)($q['created_to'] ?? ''), true);
		if ($from !== null && $to !== null && $from > $to) {
			throw new BadRequestException('created_from must be before or equal to created_to');
		}
		$ws = $this->workspaces->resolveOptional($uid, $q['workspace'] ?? null);
		$wsIds = $ws !== null ? [(int)$ws->getId()] : $this->access->visibleWorkspaceIds($uid);

		if (!empty($q['get_folders'])) {
			return ['folders' => $this->folderPaths($wsIds)];
		}

		$filter = [];
		if (($q['folder_id'] ?? '') !== '' && $q['folder_id'] !== null) {
			$filter['folder_id'] = (int)$q['folder_id'];
		}
		if (isset($q['folder']) && $q['folder'] !== '') {
			$byName = array_filter($this->folders->findInWorkspaces($wsIds), static fn (Folder $f) => $f->getName() === (string)$q['folder']);
			$filter['folder_ids'] = array_map(static fn (Folder $f) => (int)$f->getId(), $byName);
		}
		if (isset($q['favorite']) && $q['favorite'] !== '') {
			$filter['favorite'] = (int)$q['favorite'];
		}
		if (isset($q['search']) && $q['search'] !== '') {
			$filter['search'] = $this->search->fold((string)$q['search']);
		}
		if ($from !== null) {
			$filter['created_from'] = $from;
		}
		if ($to !== null) {
			$filter['created_to'] = $to;
		}
		$notes = $this->notes->findList($wsIds, $filter);
		$sort = (string)($q['sort'] ?? '');
		if (!in_array($sort, ['updated_desc', 'created_desc', 'heading_asc', 'type_asc', 'manual'], true)) {
			$sort = $this->settings->noteListSort($uid);
		}
		$after = !in_array($this->settings->get($uid, 'notes_without_folders_after_folders', '1'), ['0', 'false'], true);
		$this->sortList($notes, $sort, $after);
		$total = count($notes);
		$page = array_slice($notes, $offset, $limit);
		return [
			'notes' => $this->serializer->listItems($uid, $page),
			'count' => count($page),
			'total' => $total,
			'offset' => $offset,
			'limit' => $limit,
			'has_more' => ($offset + count($page)) < $total,
		];
	}

	/**
	 * Upstream ORDER BY von GET /notes in PHP
	 * @param Note[] $notes
	 */
	private function sortList(array &$notes, string $sort, bool $rootAfter): void {
		$names = [];
		$folderKey = function (Note $n) use (&$names): array {
			$fid = $n->getFolderId();
			if ($fid === null) {
				return [0, ''];
			}
			$names[$fid] ??= (string)$this->serializer->folderName($fid);
			return [1, $names[$fid]];
		};
		usort($notes, function (Note $a, Note $b) use ($sort, $rootAfter, $folderKey) {
			[$fa, $na] = $folderKey($a);
			[$fb, $nb] = $folderKey($b);
			$caseA = $fa === 0 ? ($rootAfter ? 1 : 0) : ($rootAfter ? 0 : 1);
			$caseB = $fb === 0 ? ($rootAfter ? 1 : 0) : ($rootAfter ? 0 : 1);
			$nocase = static fn (?string $x, ?string $y) => strcmp(mb_strtolower((string)$x), mb_strtolower((string)$y));
			$byCase = $sort === 'heading_asc' ? 0 : ($caseA <=> $caseB);
			$byFolder = ($fa <=> $fb) ?: strcmp($na, $nb);
			$rest = match ($sort) {
				'created_desc' => (int)$b->getCreatedAt() <=> (int)$a->getCreatedAt(),
				'heading_asc' => $nocase($a->getHeading(), $b->getHeading()),
				'type_asc' => $nocase($a->getType(), $b->getType()) ?: $nocase($a->getHeading(), $b->getHeading()),
				'manual' => (((int)$a->getDisplayOrder() > 0 ? 1 : 0) <=> ((int)$b->getDisplayOrder() > 0 ? 1 : 0))
					?: ((int)$a->getDisplayOrder() <=> (int)$b->getDisplayOrder())
					?: ((int)$b->getUpdatedAt() <=> (int)$a->getUpdatedAt()),
				default => (int)$b->getUpdatedAt() <=> (int)$a->getUpdatedAt(),
			};
			return $byCase ?: $byFolder ?: $rest ?: ((int)$a->getId() <=> (int)$b->getId());
		});
	}

	/**
	 * get_folders: {id: {id, name, path}}
	 * @param int[] $wsIds
	 */
	private function folderPaths(array $wsIds): array|\stdClass {
		$out = [];
		foreach ($wsIds as $wsId) {
			$byId = $this->folderService->byId($wsId);
			foreach ($byId as $id => $f) {
				$out[$id] = ['id' => $id, 'name' => $f->getName(), 'path' => FolderService::pathOf($id, $byId)];
			}
		}
		return $out === [] ? new \stdClass() : $out;
	}

	/** @return array{notes: list<array<string, mixed>>, count: int} GET /notes/templates */
	public function templates(string $uid, mixed $wsKey): array {
		$visible = $this->workspaces->listVisible($uid);
		$ws = $this->workspaces->resolveOptional($uid, $wsKey);
		$folderIds = [];
		foreach ($ws !== null ? [$ws] : $visible as $w) {
			$byId = $this->folderService->byId((int)$w->getId());
			foreach ($byId as $id => $f) {
				if (in_array(mb_strtolower(trim($f->getName())), self::TEMPLATE_NAMES, true)) {
					array_push($folderIds, ...FolderService::subtree($id, $byId));
				}
			}
		}
		$templateWs = array_map(static fn (Workspace $w) => (int)$w->getId(), array_filter($visible, static fn (Workspace $w) => in_array(mb_strtolower(trim($w->getName())), self::TEMPLATE_NAMES, true)));
		$candidates = [];
		if ($folderIds !== []) {
			$scope = $ws !== null ? [(int)$ws->getId()] : array_map(static fn (Workspace $w) => (int)$w->getId(), $visible);
			$candidates = $this->notes->findList($scope, ['folder_ids' => $folderIds]);
		}
		if ($templateWs !== []) {
			$candidates = array_merge($candidates, $this->notes->findList($templateWs));
		}
		$seen = [];
		$out = [];
		foreach ($candidates as $n) {
			$type = (string)$n->getType();
			if (isset($seen[(int)$n->getId()]) || !in_array($type, ['', 'note', 'markdown'], true)) {
				continue;
			}
			$seen[(int)$n->getId()] = true;
			$out[] = [
				'id' => (int)$n->getId(),
				'heading' => (string)($n->getHeading() ?? ''),
				'type' => $type === 'markdown' ? 'markdown' : 'note',
				'workspace' => $this->workspaces->keyFor($uid, (int)$n->getWorkspaceId()),
				'folder_id' => $n->getFolderId(),
				'folder' => $this->serializer->folderName($n->getFolderId()),
				'icon' => $n->getIcon() ?: NoteSerializer::DEFAULT_ICON,
				'icon_color' => $n->getIconColor(),
			];
		}
		usort($out, static fn ($a, $b) => strcmp(mb_strtolower($a['heading']), mb_strtolower($b['heading'])) ?: $a['id'] <=> $b['id']);
		return ['notes' => $out, 'count' => count($out)];
	}

	// ---------- Einzelne Notiz ----------

	// Upstream findNoteIdByTitle (für /notes/resolve, M2b): exakter Titel (Groß/Klein, Akzente egal), sonst enthält
	/** @param int[] $wsIds */
	public function findIdByTitle(array $wsIds, string $title, bool $partial): ?int {
		$title = trim($title);
		if ($title === '') {
			return null;
		}
		$folded = $this->search->fold($title);
		$exact = [];
		$part = [];
		foreach ($this->notes->findList($wsIds) as $n) {
			$h = trim((string)$n->getHeading());
			$fh = $this->search->fold($h);
			if ($fh === $folded) {
				$exact[] = [$h === $title ? 1 : 0, (int)$n->getUpdatedAt(), (int)$n->getId()];
			} elseif ($partial && str_contains($fh, $folded)) {
				$part[] = [0, (int)$n->getUpdatedAt(), (int)$n->getId()];
			}
		}
		$pick = $exact !== [] ? $exact : $part;
		if ($pick === []) {
			return null;
		}
		rsort($pick);
		return $pick[0][2];
	}

	/** @return array{0: array<string, mixed>, 1: string} Notiz, Version */
	public function show(string $uid, int $id, mixed $wsKey): array {
		$ws = $this->workspaces->resolveOptional($uid, $wsKey);
		$note = $this->access->requireNote($uid, $id, 'read');
		if ($ws !== null && $note->getWorkspaceId() !== $ws->getId()) {
			throw new NotFoundException('Note not found or has been deleted');
		}
		$version = NoteSerializer::version($note);
		return [$this->serializer->full($uid, $note, $version), $version];
	}

	/** @return array<string, mixed> POST /notes (201); $diary: Tagebuch-Vorlage für leere Einträge (M4f) */
	public function create(string $uid, array $in, ?DiaryService $diary = null): array {
		$type = $this->validator->noteType($in['type'] ?? null);
		$headingIn = $this->validator->heading(is_scalar($in['heading'] ?? null) ? (string)$in['heading'] : '');
		$tagList = isset($in['tags']) ? $this->validator->noteTags($in['tags']) : [];
		$content = $in['content'] ?? $in['entry'] ?? '';
		$content = is_scalar($content) ? (string)$content : '';
		$folderPath = $in['folder'] ?? $in['folder_name'] ?? null;
		$folderPath = is_string($folderPath) ? trim($folderPath) : '';
		$folderIdIn = isset($in['folder_id']) ? (int)$in['folder_id'] : 0;
		$hasWs = is_string($in['workspace'] ?? null) && trim($in['workspace']) !== '';
		// speed-dial setzen nur die Speed-Dial-Aktionen (PUT notes/{id}/tags, Ordner-Tags), nicht die Tag-Eingabe
		$tagList = array_values(array_filter($tagList, static fn (string $t) => !TagService::isInternal($t)));
		$this->pendingMention = null;

		$out = $this->tx->run(function () use ($uid, $in, $type, $headingIn, $tagList, $content, $folderPath, $folderIdIn, $hasWs, $diary) {
			$folder = $folderIdIn > 0 ? $this->access->requireFolder($uid, $folderIdIn, 'write') : null;
			if ($hasWs) {
				$ws = $this->workspaces->requireByKey($uid, trim($in['workspace']), 'write');
			} elseif ($folder !== null) {
				$ws = $this->access->requireWorkspace($uid, (int)$folder->getWorkspaceId(), 'write');
			} else {
				$ws = $this->workspaces->defaultWorkspace($uid);
			}
			$wsId = (int)$ws->getId();
			if ($folder !== null && $folder->getWorkspaceId() !== $wsId) {
				throw new BadRequestException('folder_id must be a folder of the target workspace');
			}
			$folderId = $folder?->getId();
			if ($folderId === null && $folderPath !== '') {
				$folderId = $this->folderService->resolvePath($uid, $ws, $folderPath, true);
			}
			$linked = null;
			if (isset($in['linked_note_id']) && (int)$in['linked_note_id'] > 0) {
				try {
					$target = $this->access->requireNote($uid, (int)$in['linked_note_id'], 'read');
				} catch (NotFoundException) {
					throw new NotFoundException('Shortcut not found');
				}
				if ($this->notes->findList($this->access->visibleWorkspaceIds($uid), ['linked_note_id' => (int)$target->getId()]) !== []) {
					throw new BadRequestException('A shortcut already exists for this note');
				}
				$linked = (int)$target->getId();
			}
			$heading = $type === 'linked' && $headingIn !== '' ? $headingIn : $this->uniqueHeading($wsId, $folderId, $headingIn);
			if ($diary !== null && trim($content) === '') {
				$content = $diary->initialContent($uid, $ws, $folderId, $headingIn, $type) ?? $content;
			}
			$now = $this->now();
			$created = $now;
			if (isset($in['created_date']) && is_string($in['created_date'])
				&& preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($in['created_date']), $cd) && checkdate((int)$cd[2], (int)$cd[3], (int)$cd[1])) {
				$created = (new \DateTime(trim($in['created_date']) . ' 12:00:00', $this->timeZone->getTimeZone()))->getTimestamp();
			}
			$note = new Note();
			$note->setWorkspaceId($wsId);
			$note->setFolderId($folderId);
			$note->setHeading($heading);
			$note->setType($type);
			$note->setRole('note');
			$note->setLinkedNoteId($linked);
			foreach (['Favorite', 'Pinned', 'DisplayOrder', 'DashboardOrder', 'SpeedDialOrder', 'KanbanOrder'] as $f) {
				$note->{'set' . $f}(0);
			}
			$note->setCreatedBy($uid);
			$note->setUpdatedBy($uid);
			$note->setCreatedAt($created);
			$note->setUpdatedAt($now);
			$this->applyContent($note, $content);
			$note = $this->notes->insert($note);
			$this->tags->replace((int)$note->getId(), $tagList);
			$this->pendingMention = [$note, ''];
			return [
				'note' => [
					'id' => (int)$note->getId(),
					'heading' => $heading,
					'workspace' => $this->workspaces->keyFor($uid, $wsId),
					'type' => $type,
					'folder_id' => $folderId,
					'created' => NoteSerializer::date($created),
				],
				'share_delta' => 0,
			];
		});
		$this->notifyMentions($uid);
		return $out;
	}

	/**
	 * OneNote-Import (Plan M9 §5): Richtext-Notiz mit Daten aus Graph; ohne Sitzung, ohne Erwähnungen, in der Transaktion des Aufrufers.
	 * Ziel prüft der Aufrufer (eigener Workspace, gesperrt).
	 * @throws \OCA\NextNotes\Service\Exception\TooLargeException Inhalt zu groß (vor dem Einfügen)
	 */
	public function createImported(string $uid, int $workspaceId, ?int $folderId, string $heading, string $html, int $createdAt, int $updatedAt, int $order): Note {
		$heading = trim(Pcre::replace('/[\x00-\x1F\x7F]/u', ' ', $heading));
		$heading = $this->uniqueHeading($workspaceId, $folderId, mb_substr($heading, 0, 1000));
		$note = new Note();
		$note->setWorkspaceId($workspaceId);
		$note->setFolderId($folderId);
		$note->setHeading($heading);
		$note->setType('note');
		$note->setRole('note');
		foreach (['Favorite', 'Pinned', 'DashboardOrder', 'SpeedDialOrder', 'KanbanOrder'] as $f) {
			$note->{'set' . $f}(0);
		}
		$note->setDisplayOrder(max(0, $order));
		$note->setCreatedBy($uid);
		$note->setUpdatedBy($uid);
		$note->setCreatedAt($createdAt);
		$note->setUpdatedAt($updatedAt);
		$this->applyContent($note, $html);
		return $this->notes->insert($note);
	}

	/** Inhalt einer gerade importierten Notiz setzen (OneNote-Ressourcen, M9d); nur Job, in dessen Transaktion */
	public function setImportedContent(Note $note, string $html): Note {
		$this->applyContent($note, $html);
		return $this->notes->update($note);
	}

	/** @var ?array{0: Note, 1: string} gespeicherte Notiz und Inhalt davor, bis nach dem Commit */
	private ?array $pendingMention = null;

	// Neu erwähnte Mitglieder benachrichtigen (§6); erst nach dem Commit, Fehler brechen das Speichern nicht ab
	private function notifyMentions(string $uid): void {
		$pending = $this->pendingMention;
		$this->pendingMention = null;
		if ($pending !== null) {
			$this->mentionService->notifyNew($uid, $pending[0], $pending[1]);
		}
	}

	/** @return array<string, mixed> PATCH /notes/{id}; $diary: Datumstitel verschiebt in den Datumsordner (M4f) */
	public function update(string $uid, int $id, array $in, string $ifMatch, string $session, ?DiaryService $diary = null): array {
		$writable = $this->access->requireNote($uid, $id, 'write');
		// Vorlagen/Abgaben ohne eigenen Ort: kein Umzug, kein Ordner, kein Tagebuch (Review M6-Plan r2 Hinweis 8)
		$system = $writable->getRole() !== 'note';
		if ($system) {
			// Upstream-Speichern (notes.js) schickt folder_id immer mit: unveränderter Ordner ist kein Umzug (M6g2)
			if (isset($in['folder_id']) && is_scalar($in['folder_id']) && (int)$in['folder_id'] === (int)$writable->getFolderId()) {
				unset($in['folder_id']);
			}
			foreach (['workspace', 'folder_id', 'folder', 'folder_name'] as $key) {
				if (($in[$key] ?? null) !== null) {
					throw new BadRequestException('This note cannot be moved');
				}
			}
			$diary = null;
		}
		// ADR 0005: gültige Sperre einer anderen Sitzung → 423, danach Versionsprüfung → 409
		$this->locks->assertCanSave($uid, $id, $session);
		$expected = trim((string)($in['if_version'] ?? ''));
		if ($expected === '' && $ifMatch !== '') {
			$expected = trim(str_starts_with($ifMatch, 'W/') ? substr($ifMatch, 2) : $ifMatch, '" ');
			$expected = explode('-', $expected, 2)[0];
		}
		$newHeading = array_key_exists('heading', $in) && is_scalar($in['heading']) ? $this->validator->heading((string)$in['heading']) : null;
		$content = $in['content'] ?? $in['entry'] ?? null;
		$tagList = array_key_exists('tags', $in) ? $this->validator->noteTags($in['tags']) : null;
		$stateHash = (isset($in['state_hash']) && is_string($in['state_hash']) && preg_match('/^[0-9a-f-]{1,80}$/', $in['state_hash'])) ? $in['state_hash'] : null;
		// Base64-Bilder einer HTML-Notiz → Anhänge (Upstream #1471, M4b); Grenzen vor der Transaktion, nichts geändert bei 413
		// Quote gegen den Ziel-Workspace, falls die Notiz mit dem Speichern umzieht (M4b-r1 MINOR 2)
		$target = is_string($in['workspace'] ?? null) && trim($in['workspace']) !== '' ? $this->workspaces->requireByKey($uid, trim($in['workspace']), 'write') : null;
		$targetWsId = $target !== null ? (int)$target->getId() : (int)$writable->getWorkspaceId();
		$images = null;
		if (is_scalar($content) && $writable->getType() === 'note') {
			$images = $this->images->prepare((string)$content, $writable, $target !== null ? $targetWsId : null);
		}
		// Besitzerwechsel: vorhandene Anhänge zählen beim Ziel-Besitzer, zusammen mit neuen Bildern (H1 M5a)
		$moveQuota = $target !== null && $targetWsId !== (int)$writable->getWorkspaceId()
			? $this->quota->forLeave(new LeaveRequest($this->access->requireWorkspace($uid, (int)$writable->getWorkspaceId(), 'write'), $target, LeaveRequest::NOTES, [$id]))
			: null;
		$quota = $this->quota->combine($images['quota'] ?? null, $moveQuota);
		$lockWs = self::mayCreateFolder($uid, $in, $newHeading, $writable, $diary) ? $targetWsId : null;
		$this->pendingMention = null;
		$out = $this->tx->run(function () use ($uid, $id, $in, $expected, $newHeading, $content, $tagList, $stateHash, $images, $diary, $lockWs, $quota) {
			$this->quota->enforce($quota);
			// B64: Workspace vor der Notiz sperren (Reihenfolge wie Löschen/Verschieben), falls ein Ordner entstehen kann
			if ($lockWs !== null) {
				$this->folderService->lockWorkspace($lockWs);
			}
			return $this->updateLocked($uid, $id, $in, $expected, $newHeading, $content, $tagList, $stateHash, $images, $diary);
		});
		$this->notifyMentions($uid);
		return $out;
	}

	// Kann PATCH einen Ordner anlegen (Pfad oder Tagebuch-Datumsordner)? Gleiche Bedingungen wie updateLocked
	private static function mayCreateFolder(string $uid, array $in, ?string $newHeading, Note $note, ?DiaryService $diary): bool {
		$path = $in['folder'] ?? $in['folder_name'] ?? null;
		if (!(array_key_exists('folder_id', $in) && $in['folder_id'] !== null) && is_string($path)) {
			return trim($path) !== '';
		}
		return $diary !== null && $newHeading !== null && $newHeading !== (string)$note->getHeading() && $note->getFolderId() !== null
			&& $diary->isDateTitle($uid, $newHeading);
	}

	/** @return array<string, mixed> */
	private function updateLocked(string $uid, int $id, array $in, string $expected, ?string $newHeading, mixed $content, ?array $tagList, ?string $stateHash, ?array $images, ?DiaryService $diary = null): array {
		// Versionsprüfung und Schreiben unter Zeilensperre (wie Aufgaben): keine verlorene Änderung
		$this->notes->lockRow($id);
		$note = $this->notes->find($id);
		// Vorlage/Abgabe: Status unter der Notizsperre neu prüfen (Review M6-Plan r2 Hinweis 1)
		$this->access->recheckLocked($uid, $note);
		$current = NoteSerializer::version($note);
		if ($expected !== '' && $expected !== '*' && !hash_equals($current, $expected)) {
			throw new ConflictException('Version conflict: the note was modified since the version you provided', [
				'code' => 'version_conflict',
				'current' => [
					'version' => $current,
					'updated' => NoteSerializer::date($note->getUpdatedAt()),
					'heading' => $note->getHeading(),
					'content' => $this->urls->toClient((string)$note->getContent()),
				],
			]);
		}
		$oldHeading = (string)$note->getHeading();
		$heading = $newHeading ?? $oldHeading;
		if ($content !== null || $heading !== $oldHeading || $tagList !== null) {
			$this->snapshots->autoBefore($note, $uid);
		}
		$sourceWs = (int)$note->getWorkspaceId();
		$wsId = $sourceWs;
		$ws = null;
		if (is_string($in['workspace'] ?? null) && trim($in['workspace']) !== '') {
			$ws = $this->workspaces->requireByKey($uid, trim($in['workspace']), 'write');
			$wsId = (int)$ws->getId();
			if ($wsId !== $sourceWs) {
				$this->access->requireLeave($uid, new LeaveRequest($this->access->requireWorkspace($uid, $sourceWs, 'write'), $ws, LeaveRequest::NOTES, [$id]));
			}
		}
		$folderId = $note->getFolderId();
		$folderGiven = array_key_exists('folder_id', $in) && $in['folder_id'] !== null;
		if ($folderGiven) {
			$folderId = (int)$in['folder_id'] ?: null;
			if ($folderId !== null) {
				$f = $this->access->requireFolder($uid, $folderId, 'write');
				if ($f->getWorkspaceId() !== $wsId) {
					throw new BadRequestException('folder_id must be a folder of the target workspace');
				}
			}
		}
		$path = $in['folder'] ?? $in['folder_name'] ?? null;
		$pathApplied = false;
		if (!$folderGiven && is_string($path)) {
			$pathApplied = true;
			$path = trim($path);
			if ($path === '') {
				$folderId = null;
			} else {
				$folderId = $this->folderService->resolvePath($uid, $ws ?? $this->access->requireWorkspace($uid, $wsId, 'write'), $path, true);
				if ($folderId === null) {
					throw new NotFoundException('Folder not found');
				}
			}
		}
		if ($wsId !== $sourceWs && !$folderGiven && !$pathApplied) {
			$folderId = null;
		}
		// Tagebuch: neuer Datumstitel → Tagebuch/JJJJ/MM, nur ohne Ordnerwechsel in dieser Anfrage und im selben Workspace
		if ($diary !== null && $heading !== $oldHeading && !$pathApplied && $wsId === $sourceWs && $folderId === $note->getFolderId()) {
			$folderId = $diary->dateFolder($uid, $ws ?? $this->access->requireWorkspace($uid, $wsId, 'write'), $folderId, $heading) ?? $folderId;
		}
		if ($note->getWorkspaceId() !== null && ($heading !== $oldHeading || $folderId !== $note->getFolderId() || $wsId !== $sourceWs)) {
			$heading = $this->notes->headingExists($wsId, $folderId, $heading, (int)$note->getId())
				? $this->uniqueHeading($wsId, $folderId, $heading, (int)$note->getId())
				: $heading;
		}
		$now = $this->now();
		$note->setHeading($heading);
		if ($note->getWorkspaceId() !== null) {
			$note->setWorkspaceId($wsId);
			$note->setFolderId($folderId);
		}
		$note->setUpdatedAt($now);
		$note->setUpdatedBy($uid);
		$adopted = [];
		$oldContent = (string)($note->getContent() ?? '');
		if ($content !== null) {
			$content = is_scalar($content) ? (string)$content : '';
			if ($images !== null && $note->getType() === 'note') {
				['content' => $content, 'adopted' => $adopted] = $this->images->store($images, $id, $content, $uid);
			}
			$this->applyContent($note, $content);
		} else {
			$this->refreshSearch($note);
		}
		$version = NoteSerializer::version($note);
		$note->setClientStateHash($stateHash);
		$note->setClientStateVersion($stateHash !== null ? $version : null);
		$this->notes->update($note);
		if ($content !== null) {
			$this->pendingMention = [$note, $oldContent];
		}
		if ($tagList !== null) {
			// speed-dial bleibt, wenn vorhanden; neu setzen nur die Speed-Dial-Aktionen
			$internal = array_filter($tagList, static fn (string $t) => TagService::isInternal($t));
			if ($internal !== [] && array_filter($this->tags->tagsOf((int)$note->getId()), static fn (string $t) => TagService::isInternal($t)) === []) {
				$tagList = array_values(array_filter($tagList, static fn (string $t) => !TagService::isInternal($t)));
			}
			$this->tags->replace((int)$note->getId(), $tagList);
		}
		$linked = [];
		if ($heading !== $oldHeading) {
			// Verknüpfungen im Material-Zweig ohne Schreibrecht bleiben unverändert (M6a)
			$writable = $this->access->writableWorkspaceIds($uid);
			foreach ($this->access->filterWritable($uid, $this->notes->findList($writable, ['linked_note_id' => (int)$note->getId()], true)) as $link) {
				$link->setHeading($heading);
				$link->setUpdatedAt($now);
				$link->setUpdatedBy($uid);
				$this->refreshSearch($link);
				$this->notes->update($link);
				$linked[] = (int)$link->getId();
			}
		}
		$display = (new \DateTime('@' . $now))->setTimezone($this->timeZone->getTimeZone())->format('d/m/Y H:i');
		$out = ['note' => [
			'id' => (int)$note->getId(),
			'heading' => $heading,
			'updated' => NoteSerializer::date($now),
			'updated_display' => $display,
			'version' => $version,
		]];
		if ($linked !== []) {
			$out['updated_linked_notes'] = $linked;
		}
		// Editor tauscht data:-Adressen gegen die Anhänge (Upstream adopted_attachments)
		if ($adopted !== []) {
			$out['adopted_attachments'] = array_map(fn (array $a) => $a + ['new_url' => $this->urls->toClient('/api/v1/notes/' . $id . '/attachments/' . $a['id'])], $adopted);
		}
		return $out;
	}

	// sendBeacon beim Verlassen der Seite (FormData)
	public function beacon(string $uid, int $id, mixed $content, string $session): void {
		if (!is_scalar($content) || (string)$content === '') {
			throw new BadRequestException('Content is required');
		}
		$note = $this->access->requireNote($uid, $id, 'write');
		$this->locks->assertCanSave($uid, $id, $session);
		$this->tx->run(function () use ($note, $content, $uid) {
			$note = $this->access->lockForWrite($uid, $note);
			$this->snapshots->autoBefore($note, $uid);
			$this->applyContent($note, (string)$content);
			$note->setUpdatedAt($this->now());
			$note->setUpdatedBy($uid);
			$this->notes->update($note);
		});
	}

	/** @return array<string, mixed> POST /notes/{id}/snapshot/restore */
	public function restoreSnapshot(string $uid, int $id, string $key, ?string $date, bool $meta): array {
		$note = $this->access->requireNote($uid, $id, 'write');
		$snapshot = $this->snapshots->require($note, $key, $date);
		return $this->tx->run(function () use ($uid, $note, $snapshot, $meta) {
			$note = $this->access->lockForWrite($uid, $note);
			$now = $this->now();
			$changedHeading = null;
			$heading = trim((string)$snapshot->getHeading());
			if ($meta && $heading !== '' && $heading !== (string)$note->getHeading()) {
				$changedHeading = $this->uniqueHeading((int)$note->getWorkspaceId(), $note->getFolderId(), $heading, (int)$note->getId());
				$note->setHeading($changedHeading);
				foreach ($this->access->filterWritable($uid, $this->notes->findList($this->access->writableWorkspaceIds($uid), ['linked_note_id' => (int)$note->getId()], true)) as $link) {
					$link->setHeading($changedHeading);
					$link->setUpdatedAt($now);
					$link->setUpdatedBy($uid);
					$this->refreshSearch($link);
					$this->notes->update($link);
				}
			}
			$this->applyContent($note, (string)$snapshot->getContent());
			$note->setUpdatedAt($now);
			$note->setUpdatedBy($uid);
			$this->notes->update($note);
			return ['message' => 'Note restored to snapshot state', 'heading' => $changedHeading, 'tags' => null];
		});
	}

	/** @return array<string, mixed> DELETE /notes/{id} */
	public function delete(string $uid, int $id, bool $permanent, mixed $wsKey): array {
		$note = $this->access->requireNote($uid, $id, 'manage', null);
		$ws = $this->workspaces->resolveOptional($uid, $wsKey);
		if ($ws !== null && $ws->getId() !== $note->getWorkspaceId()) {
			throw new NotFoundException('Note not found');
		}
		$links = $this->access->filterWritable($uid, $this->notes->findList($this->access->writableWorkspaceIds($uid), ['linked_note_id' => $id, 'trashed' => null]));
		if ($permanent) {
			$this->purge(array_merge([$id], array_map(static fn (Note $n) => (int)$n->getId(), $links)));
			return ['message' => 'Note permanently deleted', 'note' => [
				'id' => $id,
				'heading' => $note->getHeading(),
				'file_deleted' => true,
				'png_file_deleted' => false,
				'attachments_deleted' => [],
				'linked_notes_deleted' => count($links),
			]];
		}
		if ($note->getTrashedAt() !== null) {
			throw new BadRequestException('Note is already in trash');
		}
		$this->tx->run(function () use ($note, $links, $uid) {
			$now = $this->now();
			foreach (array_merge($links, [$note]) as $n) {
				$n->setTrashedAt($now);
				$n->setUpdatedAt($now);
				$n->setUpdatedBy($uid);
				$this->notes->update($n);
			}
		});
		return ['message' => 'Note moved to trash', 'note' => [
			'id' => $id,
			'heading' => $note->getHeading(),
			'action' => 'moved_to_trash',
			'deleted_linked_count' => count($links),
		]];
	}

	/** @return array<string, mixed> */
	public function restore(string $uid, int $id, mixed $wsKey): array {
		$note = $this->access->requireNote($uid, $id, 'manage', true);
		$ws = $this->workspaces->resolveOptional($uid, $wsKey);
		if ($ws !== null && $ws->getId() !== $note->getWorkspaceId()) {
			throw new NotFoundException('Note not found');
		}
		$note->setTrashedAt(null);
		// Wiederherstellen (nur mit Schreibrecht, bei Material Lehrkraft) hebt die Papierkorb-Markierung auf
		$note->setTrashMaterial(0);
		// Ordner inzwischen gelöscht → Wurzel (Upstream lässt die Notiz unsichtbar)
		if ($note->getFolderId() !== null) {
			try {
				if ($this->folders->find((int)$note->getFolderId())->getWorkspaceId() !== $note->getWorkspaceId()) {
					$note->setFolderId(null);
				}
			} catch (DoesNotExistException) {
				$note->setFolderId(null);
			}
		}
		$this->notes->update($note);
		return ['message' => 'Note restored successfully', 'note' => ['id' => $id, 'heading' => $note->getHeading()]];
	}

	// Endgültig löschen: Tags, Anhänge (Dateien nach dem Commit), Snapshots, Sperren, Erinnerungen, Mention-Status, Benachrichtigungen
	/** @param int[] $ids */
	public function purge(array $ids): void {
		$ids = array_values(array_unique(array_map('intval', $ids)));
		if ($ids === []) {
			return;
		}
		$this->tx->run(function () use ($ids) {
			$this->tagRows->deleteForNotes($ids);
			$this->attachments->purge($ids);
			$this->snapshotRows->deleteForNotes($ids);
			$this->lockRows->deleteForNotes($ids);
			$this->reminders->deleteForNotes($ids);
			$this->mentions->deleteForNotes($ids);
			$this->notes->deleteByIds($ids);
		});
		foreach ($ids as $id) {
			$n = $this->notifications->createNotification();
			$n->setApp(Application::APP_ID)->setObject('note', (string)$id);
			$this->notifications->markProcessed($n);
		}
	}

	// Umwandlung HTML ↔ Markdown (ConversionService): in dessen Transaktion unter Zeilensperre; Rechte/Sperre prüft der Aufrufer
	public function storeConverted(Note $note, string $type, string $content, string $uid): void {
		$this->snapshots->beforeConversion($note, $uid);
		$note->setType($type);
		$this->applyContent($note, $content);
		$note->setUpdatedAt($this->now());
		$note->setUpdatedBy($uid);
		$this->notes->update($note);
	}

	// Inhalt aus der Aufgaben-API bzw. dem Erinnerungsjob speichern; Rechte prüft der Aufrufer
	public function saveContent(Note $note, string $content, ?string $uid): void {
		$this->tx->run(function () use ($note, $content, $uid) {
			if ($uid !== null) {
				$this->snapshots->autoBefore($note, $uid);
				$note->setUpdatedBy($uid);
			}
			$this->applyContent($note, $content);
			$note->setUpdatedAt($this->now());
			$this->notes->update($note);
		});
	}

	/** @return string Tags als Text */
	/** @return array{tags: string, version: string} neue Version, damit der offene Editor ohne 409 weiterspeichert (B52) */
	public function setTags(string $uid, int $id, array $in): array {
		if (!array_key_exists('tags', $in) || $in['tags'] === null) {
			throw new BadRequestException('tags field is required');
		}
		$note = $this->access->requireNote($uid, $id, 'write', null);
		$ws = $this->workspaces->resolveOptional($uid, $in['workspace'] ?? null);
		if ($ws !== null && $ws->getId() !== $note->getWorkspaceId()) {
			throw new NotFoundException('Note not found');
		}
		$tags = $this->validator->noteTags($in['tags']);
		$this->tx->run(function () use (&$note, $tags, $uid) {
			$note = $this->access->lockForWrite($uid, $note);
			$this->snapshots->autoBefore($note, $uid);
			$this->tags->replace((int)$note->getId(), $tags);
			$note->setUpdatedAt($this->now());
			$note->setUpdatedBy($uid);
			$this->notes->update($note);
		});
		return ['tags' => $this->serializer->tagString($tags), 'version' => NoteSerializer::version($note)];
	}

	/**
	 * Einzelne Felder schreiben; Vorlage/Abgabe unter Notiz-Zeilensperre neu geprüft (lockForWrite)
	 * @param callable(Note): mixed $change
	 */
	private function writeFields(string $uid, Note $note, callable $change): Note {
		if ($note->getRole() === 'note') {
			$change($note);
			return $this->notes->update($note);
		}
		return $this->tx->run(function () use ($uid, $note, $change): Note {
			$note = $this->access->lockForWrite($uid, $note);
			$change($note);
			return $this->notes->update($note);
		});
	}

	/** @return array{0: string, 1: ?string} */
	public function setIcon(string $uid, int $id, mixed $icon, mixed $iconColor): array {
		$iconValue = $this->validator->icon($icon);
		$colorValue = $this->validator->iconColor($iconColor);
		$this->writeFields($uid, $this->access->requireNote($uid, $id, 'write'), static function (Note $note) use ($iconValue, $colorValue): void {
			$note->setIcon($iconValue);
			$note->setIconColor($colorValue);
		});
		return [$iconValue ?? NoteSerializer::DEFAULT_ICON, $colorValue];
	}

	/** @return array{0: ?string, 1: ?string} gespeicherte Farbe, Hex nach Besitzer-Palette (R16) */
	public function setColor(string $uid, int $id, mixed $color): array {
		$value = $this->validator->color($color);
		$note = $this->writeFields($uid, $this->access->requireNote($uid, $id, 'write'), static fn (Note $note) => $note->setColor($value));
		return [$value, $this->validator->colorHex($value, $this->workspaces->paletteOwner($uid, (int)$note->getWorkspaceId()))];
	}

	public function setPinned(string $uid, int $id, bool $pinned): void {
		$this->writeFields($uid, $this->access->requireNote($uid, $id, 'write'), static fn (Note $note) => $note->setPinned($pinned ? 1 : 0));
	}

	public function setContentWidth(string $uid, int $id, ?int $width): void {
		$this->writeFields($uid, $this->access->requireNote($uid, $id, 'write'), static fn (Note $note) => $note->setContentWidth($width));
	}

	/** @return array{title_color: ?string, title_size: ?int} */
	public function setTitleStyle(string $uid, int $id, array $in): array {
		$hasColor = array_key_exists('title_color', $in);
		$hasSize = array_key_exists('title_size', $in);
		if (!$hasColor && !$hasSize) {
			throw new BadRequestException('Invalid JSON in request body: expected "title_color" and/or "title_size"');
		}
		$color = $hasColor ? $this->validator->titleColor($in['title_color']) : null;
		$size = null;
		if ($hasSize && $in['title_size'] !== null) {
			$size = filter_var($in['title_size'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 12, 'max_range' => 64]]);
			if ($size === false) {
				throw new BadRequestException('Invalid value for "title_size": expected a size in px between 12 and 64, or null');
			}
		}
		$note = $this->writeFields($uid, $this->access->requireNote($uid, $id, 'write'), static function (Note $note) use ($hasColor, $color, $hasSize, $size): void {
			if ($hasColor) {
				$note->setTitleColor($color);
			}
			if ($hasSize) {
				$note->setTitleSize($size);
			}
		});
		return ['title_color' => $note->getTitleColor(), 'title_size' => $note->getTitleSize()];
	}

	public function toggleFavorite(string $uid, int $id, mixed $wsKey): int {
		$note = $this->access->requireNote($uid, $id, 'write', null);
		$ws = $this->workspaces->resolveOptional($uid, $wsKey);
		if ($ws !== null && $ws->getId() !== $note->getWorkspaceId()) {
			throw new NotFoundException('Note not found');
		}
		$value = (int)$note->getFavorite() === 1 ? 0 : 1;
		$this->writeFields($uid, $note, static fn (Note $note) => $note->setFavorite($value));
		return $value;
	}

	// Zuletzt geöffnet in IUserConfig; Ausgabe gefiltert über AccessService
	public function markOpened(string $uid, int $id): void {
		$this->access->requireNote($uid, $id, 'read');
		$ids = array_values(array_filter($this->openedRaw($uid), static fn (int $n) => $n !== $id));
		array_unshift($ids, $id);
		$this->settings->set($uid, self::OPENED_KEY, (string)json_encode(array_slice($ids, 0, self::OPENED_LIMIT)));
	}

	/** @return int[] */
	private function openedRaw(string $uid): array {
		$list = json_decode($this->settings->get($uid, self::OPENED_KEY, '[]'), true);
		return is_array($list) ? array_values(array_filter(array_map(static fn ($v) => is_array($v) ? (int)($v[0] ?? 0) : (int)$v, $list))) : [];
	}

	/** @return int[] zuletzt geöffnete, nur mit Leserecht und nicht im Papierkorb */
	public function openedNoteIds(string $uid): array {
		$out = [];
		foreach ($this->openedRaw($uid) as $id) {
			try {
				$this->access->requireNote($uid, $id, 'read');
				$out[] = $id;
			} catch (NotFoundException) {
			}
		}
		return $out;
	}

	// Wert der Einstellung recently_opened_notes wie Upstream: [[id, Workspace-Schlüssel], …], gefiltert
	public function openedSetting(string $uid): string {
		$pairs = [];
		foreach ($this->notes->findByIds($this->openedNoteIds($uid)) as $n) {
			$pairs[(int)$n->getId()] = [(int)$n->getId(), (string)$this->workspaces->keyFor($uid, (int)$n->getWorkspaceId())];
		}
		$out = [];
		foreach ($this->openedNoteIds($uid) as $id) {
			if (isset($pairs[$id])) {
				$out[] = $pairs[$id];
			}
		}
		return (string)json_encode($out, JSON_UNESCAPED_UNICODE);
	}

	/** @return array{notes: int, folders: int} */
	public function clearFavorites(string $uid, mixed $wsKey): array {
		$ws = $this->workspaces->resolveOptional($uid, $wsKey, 'write');
		if ($ws === null) {
			throw new BadRequestException('workspace is required');
		}
		// Material ohne Schreibrecht behält Favoriten (M6a)
		$exclude = $this->access->materialRestricted($uid, $ws) ? array_keys($this->access->materialFolderIds($ws)) : null;
		return $this->tx->run(fn () => [
			'notes' => $this->notes->clearFavorites((int)$ws->getId(), $exclude),
			'folders' => $this->folders->clearFavorites((int)$ws->getId(), $exclude ?? []),
		]);
	}

	// ---------- Notiz ↔ Ordner (Upstream FoldersController) ----------

	/** @return array<string, mixed> POST /notes/{id}/folder */
	public function moveToFolder(string $uid, int $id, array $in): array {
		$note = $this->access->requireNote($uid, $id, 'manage', null);
		$sourceWs = (int)$note->getWorkspaceId();
		$ws = $this->workspaces->resolveOptional($uid, $in['workspace'] ?? null, 'write');
		$targetWs = $ws !== null ? (int)$ws->getId() : $sourceWs;
		$raw = $in['folder_id'] ?? $in['parent_id'] ?? null;
		$targetId = ($raw === null || $raw === '') ? null : (int)$raw;
		$name = $in['folder'] ?? $in['target_folder'] ?? null;
		$folderName = null;
		$quota = $ws !== null && $targetWs !== $sourceWs
			? $this->quota->forLeave(new LeaveRequest($this->access->requireWorkspace($uid, $sourceWs, 'write'), $ws, LeaveRequest::NOTES, [$id]))
			: null;
		return $this->tx->run(function () use ($uid, $note, $id, $sourceWs, $targetWs, $targetId, $name, &$folderName, $quota) {
			$this->quota->enforce($quota);
			if ($targetId !== null && $targetId > 0) {
				$f = $this->access->requireFolder($uid, $targetId, 'write');
				if ($f->getWorkspaceId() !== $targetWs) {
					throw new NotFoundException('Folder not found');
				}
				$folderName = $f->getName();
			} elseif (($targetId === null || $targetId === 0) && is_string($name) && $name !== '' && $targetId === null) {
				$targetWsEntity = $this->access->requireWorkspace($uid, $targetWs, 'write');
				$found = array_values(array_filter($this->folders->findInWorkspace($targetWs), static fn (Folder $f) => $f->getName() === $name));
				// Ordner per Name: gleiches Schreibrecht wie per ID (Material, M6a)
				$targetId = $found !== [] ? (int)$this->access->requireFolder($uid, (int)$found[0]->getId(), 'write')->getId()
					: $this->folderService->resolvePath($uid, $targetWsEntity, $this->validator->folderName($name), true);
				$folderName = $name;
			} else {
				$targetId = null;
			}
			if ($targetWs !== $sourceWs) {
				$this->access->requireLeave($uid, new LeaveRequest(
					$this->access->requireWorkspace($uid, $sourceWs, 'write'),
					$this->access->requireWorkspace($uid, $targetWs, 'write'),
					LeaveRequest::NOTES,
					[$id],
				));
			}
			if ($this->notes->headingExists($targetWs, $targetId, (string)$note->getHeading(), $id)) {
				throw new ConflictException('A note with the same title already exists in the destination folder');
			}
			$oldFolder = $note->getFolderId();
			$oldName = $this->serializer->folderName($oldFolder);
			$note->setFolderId($targetId);
			$note->setWorkspaceId($targetWs);
			$note->setDisplayOrder(0);
			$note->setKanbanOrder(0);
			$note->setUpdatedAt($this->now());
			$note->setUpdatedBy($uid);
			$this->notes->update($note);
			return [
				'message' => 'Note moved successfully',
				'old_folder' => $oldName,
				'old_folder_id' => $oldFolder,
				'new_folder' => $folderName,
				'new_folder_id' => $targetId,
				'old_workspace' => $this->workspaces->keyFor($uid, $sourceWs),
				'new_workspace' => $targetWs !== $sourceWs ? $this->workspaces->keyFor($uid, $targetWs) : null,
				'share_delta' => 0,
			];
		});
	}

	public function removeFromFolder(string $uid, int $id, mixed $wsKey): void {
		$note = $this->access->requireNote($uid, $id, 'manage', null);
		$ws = $this->workspaces->resolveOptional($uid, $wsKey);
		if ($ws !== null && $ws->getId() !== $note->getWorkspaceId()) {
			throw new NotFoundException('Note not found');
		}
		if ($this->notes->headingExists((int)$note->getWorkspaceId(), null, (string)$note->getHeading(), $id)) {
			throw new ConflictException('A note with the same title already exists at the root level');
		}
		$note->setFolderId(null);
		$note->setDisplayOrder(0);
		$note->setKanbanOrder(0);
		$note->setUpdatedAt($this->now());
		$note->setUpdatedBy($uid);
		$this->notes->update($note);
	}

	/** @return array<string, mixed> POST /notes/{id}/archive */
	public function archive(string $uid, int $id): array {
		$note = $this->access->requireNote($uid, $id, 'manage');
		// Benutzer-Quelle: verwalten (B17); Team-Quelle: Archiv desselben Teams über requireLeave
		$source = $this->access->requireWorkspace($uid, (int)$note->getWorkspaceId(), 'write');
		$sourceKey = $this->workspaces->keyFor($uid, (int)$source->getId());
		if ($this->workspaces->isOwnArchive($source)) {
			return ['already_archived' => true, 'workspace' => $sourceKey, 'message' => 'Note is already archived'];
		}
		$byId = $this->folderService->byId((int)$source->getId());
		$path = $this->folderService->ancestry($note->getFolderId(), $byId);
		return $this->tx->run(function () use ($uid, $note, $id, $source, $sourceKey, $path) {
			[$archive, $createdWs] = $this->workspaces->archiveFor($source, $uid);
			$this->access->requireLeave($uid, new LeaveRequest($source, $archive, LeaveRequest::NOTES, [$id]));
			$created = [];
			$targetId = $this->folderService->mirrorPath($archive, $path, $created);
			// Archiv-Ordner im Material-Zweig: nur Lehrkraft (M6a)
			if ($targetId !== null) {
				$this->access->resetMaterialCache((int)$archive->getId());
				$this->access->requireNoMaterial($uid, $archive, [$targetId]);
			}
			if ($this->notes->headingExists((int)$archive->getId(), $targetId, (string)$note->getHeading(), $id)) {
				throw new ConflictException('A note with the same title is already archived in this folder');
			}
			$oldFolder = $note->getFolderId();
			$oldName = $this->serializer->folderName($oldFolder);
			$note->setFolderId($targetId);
			$note->setWorkspaceId((int)$archive->getId());
			$this->notes->update($note);
			$targetName = $path !== [] ? $path[count($path) - 1]->getName() : null;
			return [
				'message' => 'Note archived successfully',
				'note_id' => $id,
				'workspace' => $this->workspaces->keyFor($uid, (int)$archive->getId()) ?? WorkspaceService::ARCHIVE_NAME,
				'workspace_created' => $createdWs,
				'old_workspace' => $sourceKey,
				'old_folder' => $oldName,
				'old_folder_id' => $oldFolder,
				'folder' => $targetName,
				'folder_id' => $targetId,
				'folder_path' => implode('/', array_map(static fn (Folder $f) => $f->getName(), $path)),
				'created_folders' => $created,
			];
		});
	}

	public function setKanbanCompleted(string $uid, int $id, bool $completed): void {
		$stamp = $completed ? $this->now() : null;
		$this->writeFields($uid, $this->access->requireNote($uid, $id, 'write'), static fn (Note $note) => $note->setKanbanCompleted($stamp));
	}

	/** @return array<string, mixed> POST /notes/reorder */
	public function reorder(string $uid, array $in): array {
		$noteId = (int)($in['note_id'] ?? 0);
		$targetId = (int)($in['target_note_id'] ?? 0);
		$position = strtolower(trim((string)($in['position'] ?? '')));
		$scope = strtolower(trim((string)($in['scope'] ?? 'sidebar')));
		if ($noteId <= 0 || $targetId <= 0) {
			throw new BadRequestException('note_id and target_note_id are required');
		}
		if (!in_array($position, ['before', 'after'], true)) {
			throw new BadRequestException('position must be before or after');
		}
		if ($noteId === $targetId) {
			throw new BadRequestException('Note cannot be reordered relative to itself');
		}
		// speed_dial entfällt mit dem Speed Dial (M6z): wie jeder unbekannte Bereich 400
		if (!in_array($scope, ['sidebar', 'dashboard', 'kanban'], true)) {
			throw new BadRequestException('scope must be sidebar, dashboard or kanban');
		}
		$note = $this->access->requireNote($uid, $noteId, 'write');
		$target = $this->access->requireNote($uid, $targetId, 'write');
		if ($note->getWorkspaceId() === null || $note->getWorkspaceId() !== $target->getWorkspaceId()) {
			throw new BadRequestException('Notes must be in the same workspace');
		}
		$wsId = (int)$target->getWorkspaceId();
		$ws = $this->workspaces->resolveOptional($uid, $in['workspace'] ?? null);
		if ($ws !== null && $ws->getId() !== $wsId) {
			throw new BadRequestException('Workspace does not match target note');
		}
		$key = $this->workspaces->keyFor($uid, $wsId);
		$folderId = $target->getFolderId();
		$changes = $note->getFolderId() !== $folderId;
		if ($scope !== 'sidebar') {
			$column = ['kanban' => 'KanbanOrder', 'dashboard' => 'DashboardOrder'][$scope];
			if ($changes) {
				throw new BadRequestException(ucfirst($scope) . ' reordering keeps the note in its folder: target_note_id must be a sibling');
			}
			$siblings = $this->notes->findList([$wsId], ['folder_id' => $folderId, 'exclude_id' => $noteId]);
			$getter = 'get' . $column;
			usort($siblings, static function (Note $a, Note $b) use ($getter, $scope) {
				if ($scope === 'dashboard' && ($pin = (int)$b->getPinned() <=> (int)$a->getPinned()) !== 0) {
					return $pin;
				}
				return self::comparePlaced((int)$a->$getter(), (int)$b->$getter(), $a, $b);
			});
			$order = array_map(static fn (Note $n) => (int)$n->getId(), $siblings);
			if ($scope === 'kanban' && is_array($in['visible_order'] ?? null)) {
				$visible = array_values(array_unique(array_map('intval', $in['visible_order'])));
				$shown = array_values(array_intersect($visible, $order));
				$order = array_merge($shown, array_values(array_diff($order, $shown)));
			}
			$byId = [$noteId => $note];
			foreach ($siblings as $s) {
				$byId[(int)$s->getId()] = $s;
			}
			// Material ohne Schreibrecht im Ordner behält seine Position (M6a)
			$writable = array_flip(array_map(static fn (Note $n) => (int)$n->getId(), $this->access->filterWritable($uid, $siblings)));
			$fixed = array_diff_key(array_flip($order), $writable);
			if ($fixed === []) {
				$index = $this->insertAt($order, $targetId, $noteId, $position);
			} else {
				$order = FolderService::placeAround($order, $fixed, $noteId, $targetId, $position);
				$index = (int)array_search($noteId, $order, true);
			}
			$this->tx->run(function () use ($uid, $order, $byId, $column, $fixed, $noteId, $note) {
				$byId[$noteId] = $this->access->lockForWrite($uid, $note);
				foreach ($order as $i => $nid) {
					if (isset($fixed[$nid])) {
						continue;
					}
					$byId[$nid]->{'set' . $column}($i + 1);
					$this->notes->update($byId[$nid]);
				}
			});
			return ['message' => 'Note reordered successfully', 'note' => [
				'id' => $noteId, 'workspace' => $key, 'folder_id' => $folderId,
				'folder' => $this->serializer->folderName($note->getFolderId()), 'position' => $position,
				'target_note_id' => $targetId, 'scope' => $scope,
				['kanban' => 'kanban_order', 'dashboard' => 'dashboard_order'][$scope] => $index + 1,
			], 'share_delta' => 0];
		}
		// Seitenleiste mit Ordnerwechsel – Vorlage/Abgabe: nie in einen anderen Ordner (SPEC §9.3); Zielordner wie beim Verschieben prüfen (System-/Material-Ordner)
		if ($changes && $note->getRole() !== 'note') {
			throw new ForbiddenException('This content is managed by the system');
		}
		if ($changes && $folderId !== null) {
			$this->access->requireFolder($uid, (int)$folderId, 'write');
		}
		if ($changes && $this->notes->headingExists($wsId, $folderId, (string)$note->getHeading(), $noteId)) {
			throw new ConflictException('A note with the same title already exists in the destination folder');
		}
		$siblings = $this->notes->findList([$wsId], ['folder_id' => $folderId, 'exclude_id' => $noteId]);
		$mode = $this->settings->noteListSort($uid);
		usort($siblings, fn (Note $a, Note $b) => self::compareNotes($mode, $a, $b));
		$order = array_map(static fn (Note $n) => (int)$n->getId(), $siblings);
		$byId = [$noteId => $note];
		foreach ($siblings as $s) {
			$byId[(int)$s->getId()] = $s;
		}
		$index = $this->insertAt($order, $targetId, $noteId, $position);
		$this->tx->run(function () use ($order, $byId, $noteId, $folderId, $changes, $uid, $note) {
			$byId[$noteId] = $this->access->lockForWrite($uid, $note);
			foreach ($order as $i => $nid) {
				$n = $byId[$nid];
				$n->setDisplayOrder($i + 1);
				if ($nid === $noteId && $changes) {
					$n->setFolderId($folderId);
					$n->setKanbanOrder(0);
					$n->setUpdatedAt($this->now());
					$n->setUpdatedBy($uid);
				}
				$this->notes->update($n);
			}
		});
		$this->settings->set($uid, 'note_list_sort', 'manual');
		return ['message' => 'Note reordered successfully', 'note' => [
			'id' => $noteId, 'workspace' => $key, 'folder_id' => $folderId,
			'folder' => $this->serializer->folderName($folderId), 'position' => $position,
			'target_note_id' => $targetId, 'display_order' => $index + 1,
		], 'share_delta' => 0];
	}

	/** @param int[] $order */
	private function insertAt(array &$order, int $targetId, int $noteId, string $position): int {
		$index = array_search($targetId, $order, true);
		if ($index === false) {
			throw new NotFoundException('Target note is not available in the destination order');
		}
		$insert = $position === 'before' ? $index : $index + 1;
		array_splice($order, $insert, 0, [$noteId]);
		return $insert;
	}

	// Upstream poznoteComparePlacedOrder
	public static function comparePlaced(int $oa, int $ob, Note $a, Note $b): int {
		if ($oa > 0 && $ob > 0) {
			return ($oa <=> $ob) ?: ((int)$a->getId() <=> (int)$b->getId());
		}
		if ($oa > 0) {
			return 1;
		}
		if ($ob > 0) {
			return -1;
		}
		return ((int)$b->getUpdatedAt() <=> (int)$a->getUpdatedAt()) ?: ((int)$b->getId() <=> (int)$a->getId());
	}

	// Upstream poznoteCompareNotes
	public static function compareNotes(string $mode, Note $a, Note $b): int {
		$byHeading = static fn () => strnatcasecmp(mb_strtolower((string)$a->getHeading()), mb_strtolower((string)$b->getHeading())) ?: ((int)$a->getId() <=> (int)$b->getId());
		$rank = static fn (?string $t) => ['note' => 0, 'markdown' => 1, 'tasklist' => 2, 'excalidraw' => 3, 'linked' => 4][$t ?: 'note'] ?? 5;
		return match ($mode) {
			'heading_asc' => $byHeading(),
			'created_desc' => ((int)$b->getCreatedAt() <=> (int)$a->getCreatedAt()) ?: ((int)$a->getId() <=> (int)$b->getId()),
			'type_asc' => ($rank($a->getType()) <=> $rank($b->getType())) ?: strcmp((string)$a->getType(), (string)$b->getType()) ?: $byHeading(),
			'manual' => self::comparePlaced((int)$a->getDisplayOrder(), (int)$b->getDisplayOrder(), $a, $b),
			default => ((int)$b->getUpdatedAt() <=> (int)$a->getUpdatedAt()) ?: ((int)$a->getId() <=> (int)$b->getId()),
		};
	}
}
