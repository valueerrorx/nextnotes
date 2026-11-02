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
	) {
	}

	// ---------- Hilfen ----------

	private function now(): int {
		return $this->time->getTime();
	}

	// Inhalt säubern, URLs kanonisch, search_text neu
	private function applyContent(Note $note, string $content): void {
		$type = (string)$note->getType();
		if ($type === 'markdown' && str_contains($content, '<div class="markdown-editor"')
			&& preg_match('/<div class="markdown-editor"[^>]*>(.*?)<\/div>/s', $content, $m)) {
			$content = html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		}
		$content = $this->sanitizer->sanitizeForType($type, $this->urls->toServer($content));
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

	/** @return array<string, mixed> POST /notes (201) */
	public function create(string $uid, array $in): array {
		$type = $this->validator->noteType($in['type'] ?? null);
		$headingIn = $this->validator->heading(is_scalar($in['heading'] ?? null) ? (string)$in['heading'] : '');
		$tagList = isset($in['tags']) ? $this->validator->noteTags($in['tags']) : [];
		$content = $in['content'] ?? $in['entry'] ?? '';
		$content = is_scalar($content) ? (string)$content : '';
		$folderPath = $in['folder'] ?? $in['folder_name'] ?? null;
		$folderPath = is_string($folderPath) ? trim($folderPath) : '';
		$folderIdIn = isset($in['folder_id']) ? (int)$in['folder_id'] : 0;
		$hasWs = is_string($in['workspace'] ?? null) && trim($in['workspace']) !== '';

		return $this->tx->run(function () use ($uid, $in, $type, $headingIn, $tagList, $content, $folderPath, $folderIdIn, $hasWs) {
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
				$folderId = $this->folderService->resolvePath($ws, $folderPath, true);
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
	}

	/** @return array<string, mixed> PATCH /notes/{id} */
	public function update(string $uid, int $id, array $in, string $ifMatch, string $session): array {
		$this->access->requireNote($uid, $id, 'write');
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

		return $this->tx->run(function () use ($uid, $id, $in, $expected, $newHeading, $content, $tagList, $stateHash) {
			// Versionsprüfung und Schreiben unter Zeilensperre (wie Aufgaben): keine verlorene Änderung
			$this->notes->lockRow($id);
			$note = $this->notes->find($id);
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
					$this->access->requireWorkspace($uid, $sourceWs, 'manage');
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
					$folderId = $this->folderService->resolvePath($ws ?? $this->access->requireWorkspace($uid, $wsId, 'write'), $path, true);
					if ($folderId === null) {
						throw new NotFoundException('Folder not found');
					}
				}
			}
			if ($wsId !== $sourceWs && !$folderGiven && !$pathApplied) {
				$folderId = null;
			}
			if ($heading !== $oldHeading || $folderId !== $note->getFolderId() || $wsId !== $sourceWs) {
				$heading = $this->notes->headingExists($wsId, $folderId, $heading, (int)$note->getId())
					? $this->uniqueHeading($wsId, $folderId, $heading, (int)$note->getId())
					: $heading;
			}
			$now = $this->now();
			$note->setHeading($heading);
			$note->setWorkspaceId($wsId);
			$note->setFolderId($folderId);
			$note->setUpdatedAt($now);
			$note->setUpdatedBy($uid);
			if ($content !== null) {
				$this->applyContent($note, is_scalar($content) ? (string)$content : '');
			} else {
				$this->refreshSearch($note);
			}
			$version = NoteSerializer::version($note);
			$note->setClientStateHash($stateHash);
			$note->setClientStateVersion($stateHash !== null ? $version : null);
			$this->notes->update($note);
			if ($tagList !== null) {
				$this->tags->replace((int)$note->getId(), $tagList);
			}
			$linked = [];
			if ($heading !== $oldHeading) {
				$writable = $this->access->writableWorkspaceIds($uid);
				foreach ($this->notes->findList($writable, ['linked_note_id' => (int)$note->getId()], true) as $link) {
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
			return $out;
		});
	}

	// sendBeacon beim Verlassen der Seite (FormData)
	public function beacon(string $uid, int $id, mixed $content, string $session): void {
		if (!is_scalar($content) || (string)$content === '') {
			throw new BadRequestException('Content is required');
		}
		$note = $this->access->requireNote($uid, $id, 'write');
		$this->locks->assertCanSave($uid, $id, $session);
		$this->tx->run(function () use ($note, $content, $uid) {
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
			$now = $this->now();
			$changedHeading = null;
			$heading = trim((string)$snapshot->getHeading());
			if ($meta && $heading !== '' && $heading !== (string)$note->getHeading()) {
				$changedHeading = $this->uniqueHeading((int)$note->getWorkspaceId(), $note->getFolderId(), $heading, (int)$note->getId());
				$note->setHeading($changedHeading);
				foreach ($this->notes->findList($this->access->writableWorkspaceIds($uid), ['linked_note_id' => (int)$note->getId()], true) as $link) {
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
		$note = $this->access->requireNote($uid, $id, 'write', null);
		$ws = $this->workspaces->resolveOptional($uid, $wsKey);
		if ($ws !== null && $ws->getId() !== $note->getWorkspaceId()) {
			throw new NotFoundException('Note not found');
		}
		$links = $this->notes->findList($this->access->writableWorkspaceIds($uid), ['linked_note_id' => $id, 'trashed' => null]);
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
		$note = $this->access->requireNote($uid, $id, 'write', true);
		$ws = $this->workspaces->resolveOptional($uid, $wsKey);
		if ($ws !== null && $ws->getId() !== $note->getWorkspaceId()) {
			throw new NotFoundException('Note not found');
		}
		$note->setTrashedAt(null);
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
	public function setTags(string $uid, int $id, array $in): string {
		if (!array_key_exists('tags', $in) || $in['tags'] === null) {
			throw new BadRequestException('tags field is required');
		}
		$note = $this->access->requireNote($uid, $id, 'write', null);
		$ws = $this->workspaces->resolveOptional($uid, $in['workspace'] ?? null);
		if ($ws !== null && $ws->getId() !== $note->getWorkspaceId()) {
			throw new NotFoundException('Note not found');
		}
		$tags = $this->validator->noteTags($in['tags']);
		$this->tx->run(function () use ($note, $tags, $uid) {
			$this->snapshots->autoBefore($note, $uid);
			$this->tags->replace((int)$note->getId(), $tags);
			$note->setUpdatedAt($this->now());
			$note->setUpdatedBy($uid);
			$this->notes->update($note);
		});
		return $this->serializer->tagString($tags);
	}

	/** @return array{0: string, 1: ?string} */
	public function setIcon(string $uid, int $id, mixed $icon, mixed $iconColor): array {
		$iconValue = $this->validator->icon($icon);
		$colorValue = $this->validator->iconColor($iconColor);
		$note = $this->access->requireNote($uid, $id, 'write');
		$note->setIcon($iconValue);
		$note->setIconColor($colorValue);
		$this->notes->update($note);
		return [$iconValue ?? NoteSerializer::DEFAULT_ICON, $colorValue];
	}

	public function setColor(string $uid, int $id, mixed $color): ?string {
		$value = $this->validator->color($color);
		$note = $this->access->requireNote($uid, $id, 'write');
		$note->setColor($value);
		$this->notes->update($note);
		return $value;
	}

	public function setPinned(string $uid, int $id, bool $pinned): void {
		$note = $this->access->requireNote($uid, $id, 'write');
		$note->setPinned($pinned ? 1 : 0);
		$this->notes->update($note);
	}

	public function setContentWidth(string $uid, int $id, ?int $width): void {
		$note = $this->access->requireNote($uid, $id, 'write');
		$note->setContentWidth($width);
		$this->notes->update($note);
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
		$note = $this->access->requireNote($uid, $id, 'write');
		if ($hasColor) {
			$note->setTitleColor($color);
		}
		if ($hasSize) {
			$note->setTitleSize($size);
		}
		$this->notes->update($note);
		return ['title_color' => $note->getTitleColor(), 'title_size' => $note->getTitleSize()];
	}

	public function toggleFavorite(string $uid, int $id, mixed $wsKey): int {
		$note = $this->access->requireNote($uid, $id, 'write', null);
		$ws = $this->workspaces->resolveOptional($uid, $wsKey);
		if ($ws !== null && $ws->getId() !== $note->getWorkspaceId()) {
			throw new NotFoundException('Note not found');
		}
		$value = (int)$note->getFavorite() === 1 ? 0 : 1;
		$note->setFavorite($value);
		$this->notes->update($note);
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
		return $this->tx->run(fn () => [
			'notes' => $this->notes->clearFavorites((int)$ws->getId()),
			'folders' => $this->folders->clearFavorites((int)$ws->getId()),
		]);
	}

	// ---------- Notiz ↔ Ordner (Upstream FoldersController) ----------

	/** @return array<string, mixed> POST /notes/{id}/folder */
	public function moveToFolder(string $uid, int $id, array $in): array {
		$note = $this->access->requireNote($uid, $id, 'write', null);
		$sourceWs = (int)$note->getWorkspaceId();
		$ws = $this->workspaces->resolveOptional($uid, $in['workspace'] ?? null, 'write');
		$targetWs = $ws !== null ? (int)$ws->getId() : $sourceWs;
		$raw = $in['folder_id'] ?? $in['parent_id'] ?? null;
		$targetId = ($raw === null || $raw === '') ? null : (int)$raw;
		$name = $in['folder'] ?? $in['target_folder'] ?? null;
		$folderName = null;
		return $this->tx->run(function () use ($uid, $note, $id, $sourceWs, $targetWs, $targetId, $name, &$folderName) {
			if ($targetId !== null && $targetId > 0) {
				$f = $this->access->requireFolder($uid, $targetId, 'write');
				if ($f->getWorkspaceId() !== $targetWs) {
					throw new NotFoundException('Folder not found');
				}
				$folderName = $f->getName();
			} elseif (($targetId === null || $targetId === 0) && is_string($name) && $name !== '' && $targetId === null) {
				$targetWsEntity = $this->access->requireWorkspace($uid, $targetWs, 'write');
				$found = array_values(array_filter($this->folders->findInWorkspace($targetWs), static fn (Folder $f) => $f->getName() === $name));
				$targetId = $found !== [] ? (int)$found[0]->getId() : $this->folderService->resolvePath($targetWsEntity, $this->validator->folderName($name), true);
				$folderName = $name;
			} else {
				$targetId = null;
			}
			if ($targetWs !== $sourceWs) {
				$this->access->requireWorkspace($uid, $sourceWs, 'manage');
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
		$note = $this->access->requireNote($uid, $id, 'write', null);
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
		$note = $this->access->requireNote($uid, $id, 'write');
		$source = $this->access->requireWorkspace($uid, (int)$note->getWorkspaceId(), 'manage');
		$sourceKey = $this->workspaces->keyFor($uid, (int)$source->getId());
		if ($this->workspaces->isOwnArchive($source)) {
			return ['already_archived' => true, 'workspace' => $sourceKey, 'message' => 'Note is already archived'];
		}
		$byId = $this->folderService->byId((int)$source->getId());
		$path = $this->folderService->ancestry($note->getFolderId(), $byId);
		return $this->tx->run(function () use ($uid, $note, $id, $source, $sourceKey, $path) {
			[$archive, $createdWs] = $this->workspaces->archiveFor($source);
			$created = [];
			$targetId = $this->folderService->mirrorPath($archive, $path, $created);
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
		$note = $this->access->requireNote($uid, $id, 'write');
		$note->setKanbanCompleted($completed ? $this->now() : null);
		$this->notes->update($note);
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
		if (!in_array($scope, ['sidebar', 'dashboard', 'kanban', 'speed_dial'], true)) {
			throw new BadRequestException('scope must be sidebar, dashboard, kanban or speed_dial');
		}
		$note = $this->access->requireNote($uid, $noteId, 'write');
		$target = $this->access->requireNote($uid, $targetId, 'write');
		$wsId = (int)$target->getWorkspaceId();
		if ($note->getWorkspaceId() !== $wsId) {
			throw new BadRequestException('Notes must be in the same workspace');
		}
		$ws = $this->workspaces->resolveOptional($uid, $in['workspace'] ?? null);
		if ($ws !== null && $ws->getId() !== $wsId) {
			throw new BadRequestException('Workspace does not match target note');
		}
		$key = $this->workspaces->keyFor($uid, $wsId);
		$folderId = $target->getFolderId();
		$changes = $note->getFolderId() !== $folderId;
		if ($scope !== 'sidebar') {
			$column = ['kanban' => 'KanbanOrder', 'dashboard' => 'DashboardOrder', 'speed_dial' => 'SpeedDialOrder'][$scope];
			if ($scope === 'speed_dial') {
				$tags = $this->tagRows->tagsFor([$noteId, $targetId]);
				$has = static fn (int $nid) => in_array('speed-dial', array_map('mb_strtolower', $tags[$nid] ?? []), true);
				if (!$has($noteId) || !$has($targetId)) {
					throw new BadRequestException('Speed dial reordering needs two notes of the speed dial');
				}
				$ids = $this->tagRows->findInWorkspaces([$wsId], 'speed-dial');
				$siblings = array_values(array_filter($this->notes->findByIds($ids), static fn (Note $n) => (int)$n->getId() !== $noteId && $n->getTrashedAt() === null));
			} else {
				if ($changes) {
					throw new BadRequestException(ucfirst($scope) . ' reordering keeps the note in its folder: target_note_id must be a sibling');
				}
				$siblings = $this->notes->findList([$wsId], ['folder_id' => $folderId, 'exclude_id' => $noteId]);
			}
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
			$index = $this->insertAt($order, $targetId, $noteId, $position);
			$this->tx->run(function () use ($order, $byId, $column) {
				foreach ($order as $i => $nid) {
					$byId[$nid]->{'set' . $column}($i + 1);
					$this->notes->update($byId[$nid]);
				}
			});
			return ['message' => 'Note reordered successfully', 'note' => [
				'id' => $noteId, 'workspace' => $key, 'folder_id' => $folderId,
				'folder' => $this->serializer->folderName($note->getFolderId()), 'position' => $position,
				'target_note_id' => $targetId, 'scope' => $scope,
				['kanban' => 'kanban_order', 'dashboard' => 'dashboard_order', 'speed_dial' => 'speed_dial_order'][$scope] => $index + 1,
			], 'share_delta' => 0];
		}
		if ($changes && $this->notes->headingExists($wsId, $folderId, (string)$note->getHeading(), $noteId)) {
			throw new ConflictException('A note with the same title already exists in the destination folder');
		}
		$siblings = $this->notes->findList([$wsId], ['folder_id' => $folderId, 'exclude_id' => $noteId]);
		$mode = $this->settings->noteListSort($uid);
		usort($siblings, fn (Note $a, Note $b) => $this->compareNotes($mode, $a, $b));
		$order = array_map(static fn (Note $n) => (int)$n->getId(), $siblings);
		$byId = [$noteId => $note];
		foreach ($siblings as $s) {
			$byId[(int)$s->getId()] = $s;
		}
		$index = $this->insertAt($order, $targetId, $noteId, $position);
		$this->tx->run(function () use ($order, $byId, $noteId, $folderId, $changes, $uid) {
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
	private static function comparePlaced(int $oa, int $ob, Note $a, Note $b): int {
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
	private function compareNotes(string $mode, Note $a, Note $b): int {
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
