<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service\View;

use OCA\NextNotes\Service\NotePalette;
use OCA\NextNotes\Db\Note;
use OCA\NextNotes\Db\NoteMapper;
use OCA\NextNotes\Db\NoteTagMapper;
use OCA\NextNotes\Service\ContentUrlRewriter;
use OCA\NextNotes\Service\DiaryDates;
use OCA\NextNotes\Service\DiaryService;
use OCA\NextNotes\Service\InputValidator;
use OCA\NextNotes\Service\Pcre;
use OCA\NextNotes\Service\SearchTextNormalizer;
use OCA\NextNotes\Service\TagService;
use OCA\NextNotes\Service\TaskService;
use OCP\IDateTimeZone;

/** Daten der Tagebuch-Seite (Upstream diary.php): Tagebücher, Einträge als Karten, Eintrag für heute, ?date= */
class DiaryViewService {
	private const EXCERPT = 220;
	// Suchtext je Karte begrenzt, damit die Seite bei vielen Einträgen klein bleibt
	private const SEARCH_CHARS = 1000;
	// Karten-Vorschau und Suchtext nur aus dem Anfang der Notiz (wie Kanban, Plan M4b)
	private const PREVIEW_BYTES = 4096;
	// Aufgabenlisten werden für die Karte nur bis zu dieser Größe ganz gelesen (JSON lässt sich nicht kürzen)
	private const TASKLIST_FULL_MAX = 65536;

	public function __construct(
		private DiaryService $diary,
		private DiaryDates $dates,
		private NoteMapper $notes,
		private NoteTagMapper $tags,
		private SearchTextNormalizer $text,
		private ContentUrlRewriter $urls,
		private PageTranslator $tr,
		private IDateTimeZone $timeZone,
		private InputValidator $validator,
		private NotePalette $palette,
	) {
	}

	/**
	 * @param array{wsId: int, workspaceKey: string, replaceWorkspace: ?string} $frame
	 * @return array{redirectNote: ?int, data: array<string, mixed>, palette?: list<array<string, string>>, roots: list<array{id: int, name: string}>, selectedId: ?int, todayExists: bool, emptyFolder: string, isEmpty: bool}
	 */
	public function build(string $uid, array $frame, mixed $rawDiary, mixed $rawDate): array {
		$wsId = $frame['wsId'];
		$key = $frame['workspaceKey'];
		$byId = $this->diary->folderMap($wsId);
		$roots = $this->diary->roots($uid, $byId);
		$wanted = is_scalar($rawDiary) ? (int)$rawDiary : 0;
		$selected = $roots[0] ?? null;
		foreach ($roots as $root) {
			if ($root['id'] === $wanted) {
				$selected = $root;
			}
		}
		$rootName = $selected['name'] ?? $this->diary->defaultRootName($uid, $byId);
		$notes = $selected === null ? [] : $this->diary->entryNotes($wsId, DiaryService::subtreeIds($byId, $roots, $selected['id']));

		// ?date= (Slash-Menü „Link zum Tagebuch-Eintrag“): vorhandenen Eintrag öffnen, sonst legt diary-page.js ihn an (GET schreibt nicht)
		$openDate = null;
		if (is_string($rawDate) && $rawDate !== '') {
			try {
				$openDate = $this->validator->date($rawDate, 'date');
			} catch (\Throwable) {
				$openDate = null;
			}
		}
		$parsed = [];
		foreach ($notes as $n) {
			$parsed[(int)$n->getId()] = $this->dates->parseTitle($uid, (string)$n->getHeading());
		}
		if ($openDate !== null) {
			foreach ($notes as $n) {
				if ($parsed[(int)$n->getId()] === $openDate) {
					return ['redirectNote' => (int)$n->getId(), 'data' => [], 'roots' => $roots, 'selectedId' => null, 'todayExists' => false, 'emptyFolder' => '', 'isEmpty' => true];
				}
			}
		}

		$tz = $this->timeZone->getTimeZone(false, $uid);
		$now = $this->diary->today($uid);
		$todayIso = $now->format('Y-m-d');
		$todayId = null;
		foreach ($notes as $n) {
			if ($parsed[(int)$n->getId()] === $todayIso) {
				$todayId = (int)$n->getId();
				break;
			}
		}
		$ids = array_map(static fn (Note $n) => (int)$n->getId(), $notes);
		$prefixes = $ids === [] ? [] : $this->notes->contentPrefixes($ids, self::PREVIEW_BYTES);
		$taskIds = array_values(array_filter($ids, fn (int $id) => strlen($prefixes[$id] ?? '') >= self::PREVIEW_BYTES && $this->typeOf($notes, $id) === 'tasklist'));
		if ($taskIds !== []) {
			$small = array_keys(array_filter($this->notes->contentBytes($taskIds), static fn (int $b) => $b <= self::TASKLIST_FULL_MAX));
			$prefixes = ($small === [] ? [] : $this->notes->contentByIds($small)) + $prefixes;
		}
		$tagMap = $ids === [] ? [] : $this->tags->tagsFor($ids);
		$untitled = $this->tr->t('common.untitled', [], 'Untitled');
		$query = '&workspace=' . rawurlencode($key);
		$cards = [];
		foreach ($notes as $n) {
			$id = (int)$n->getId();
			$type = (string)($n->getType() ?: 'note');
			$heading = trim((string)$n->getHeading());
			$tags = array_values(array_filter($tagMap[$id] ?? [], static fn ($t) => !TagService::isInternal((string)$t)));
			$preview = $this->preview($type, $prefixes[$id] ?? '');
			$created = (new \DateTimeImmutable('@' . (int)$n->getCreatedAt()))->setTimezone($tz)->format('Y-m-d');
			$color = (string)($n->getColor() ?? '');
			$cards[] = [
				'id' => $id,
				'heading' => $heading !== '' ? $heading : $untitled,
				'type' => $type,
				'entryDate' => $parsed[$id] ?? $created,
				'dated' => $parsed[$id] !== null,
				'url' => 'index.php?note=' . $id . '&newtab=1' . $query,
				'text' => $preview['text'],
				'headings' => null,
				'tasks' => $preview['tasks'],
				'image' => $preview['image'],
				'tags' => $tags,
				'search' => trim($heading . ' ' . implode(' ', $tags) . ' ' . $preview['search']),
				'created' => $created,
				'updated' => (new \DateTimeImmutable('@' . (int)$n->getUpdatedAt()))->setTimezone($tz)->format('Y-m-d'),
				'icon' => (string)($n->getIcon() ?? '') !== '' ? IndexViewService::iconClass((string)$n->getIcon(), 'lucide-file') : '',
				'iconColor' => IndexViewService::iconColor((string)($n->getIconColor() ?? '')),
				'color' => $color,
				'colorHex' => $this->palette->hex($color, NotePalette::ownerOf($frame['workspace'])),
				'_created' => (int)$n->getCreatedAt(),
			];
		}
		// Reihenfolge nach Eintragsdatum (Titel), dann neueste zuerst wie Upstream
		usort($cards, static fn (array $a, array $b) => strcmp($b['entryDate'], $a['entryDate']) ?: ($b['_created'] <=> $a['_created']) ?: ($b['id'] <=> $a['id']));
		foreach ($cards as &$card) {
			unset($card['_created']);
		}
		unset($card);

		$folderPath = $rootName . '/' . $now->format('Y') . '/' . $now->format('m');
		$t = fn (string $k, string $d) => $this->tr->t($k, [], $d);
		$palette = $this->palette->entries(NotePalette::ownerOf($frame['workspace']));
		$data = [
			'notes' => $cards,
			'todayNoteId' => $todayId,
			'todayTitle' => $this->dates->formatTitle($uid, $todayIso),
			'todayIso' => $todayIso,
			'openDate' => $openDate === null ? null : [
				'iso' => $openDate,
				'title' => $this->dates->formatTitle($uid, $openDate),
				'folder' => $rootName . '/' . substr($openDate, 0, 4) . '/' . substr($openDate, 5, 2),
			],
			'folderPath' => $folderPath,
			'workspace' => $key,
			'noteType' => $this->dates->defaultNoteType($uid),
			'pageWorkspace' => $key,
			'diaryId' => $selected['id'] ?? null,
			'diaries' => $roots,
			'lang' => $this->tr->language(),
			'txt' => [
				'createError' => $t('diary.create_error', 'Could not create the diary entry.'),
				'today' => $t('diary.today_badge', 'Today'),
				'todayCreate' => $t('diary.today_button_create', "Create today's entry"),
				'newDiaryTitle' => $t('diary.new_modal_title', 'Create a new diary'),
				'newDiaryPlaceholder' => $t('diary.new_name_placeholder', 'Diary name'),
				'newDiaryError' => $t('diary.new_create_error', 'Could not create the diary.'),
				'deleteDiaryTitle' => $t('diary.delete_title', 'Delete diary'),
				'deleteDiaryConfirm' => $t('diary.delete_confirm', 'Delete the diary "{{name}}"? Its folders are removed and all its entries are moved to the trash.'),
				'deleteDiaryError' => $t('diary.delete_error', 'Could not delete the diary.'),
				'deleteLabel' => $t('common.delete', 'Delete'),
				'renameLabel' => $t('common.rename', 'Rename'),
				'renameDiaryTitle' => $t('diary.rename_title', 'Rename diary'),
				'renameDiaryError' => $t('diary.rename_error', 'Could not rename the diary.'),
				'journalEdit' => $t('diary.journal_edit', 'Edit here'),
				'journalEditDone' => $t('diary.journal_edit_done', 'Done editing'),
				'journalEditPlaceholder' => $t('diary.journal_edit_placeholder', 'Write here...'),
				'journalSaving' => $t('diary.journal_saving', 'Saving...'),
				'journalSaved' => $t('diary.journal_saved', 'Saved'),
				'journalSaveError' => $t('diary.journal_save_error', 'Could not save this entry.'),
				'journalConflict' => $t('diary.journal_conflict', 'This entry was changed elsewhere. Your latest changes here were not saved: reload the page to see the current version.'),
				'journalTagsEdit' => $t('diary.journal_tags_edit', 'Edit tags'),
				'journalTagPlaceholder' => $t('diary.journal_tag_placeholder', 'Add a tag...'),
				'journalTagRemove' => $t('diary.journal_tag_remove', 'Remove tag'),
				'journalTagsSaveError' => $t('diary.journal_tags_save_error', 'Could not save the tags.'),
				'journalEmptyEntry' => $t('diary.journal_empty_entry', 'This entry is empty.'),
				'colorFilterAll' => $t('note_color.filter_all', 'All notes'),
				'colorFilterAny' => $t('note_color.filter_any', 'Any color'),
				'colorFilterNone' => $t('note_color.filter_none', 'No color'),
				'colorApplyError' => $t('note_color.apply_error', 'Could not update the note color.'),
				'journalLoadError' => $t('diary.journal_load_error', 'Could not load this entry.'),
				'create' => $t('common.create', 'Create'),
				'cancel' => $t('common.cancel', 'Cancel'),
			],
		];
		return [
			'redirectNote' => null,
			'data' => $data,
			'palette' => $palette,
			'roots' => $roots,
			'selectedId' => $selected['id'] ?? null,
			'todayExists' => $todayId !== null,
			'emptyFolder' => $folderPath,
			'isEmpty' => $cards === [],
		];
	}

	/** @param Note[] $notes */
	private function typeOf(array $notes, int $id): string {
		foreach ($notes as $n) {
			if ((int)$n->getId() === $id) {
				return (string)$n->getType();
			}
		}
		return '';
	}

	/**
	 * Upstream buildNoteCardPreview (ohne Zeilenstruktur): Auszug, Aufgaben, erstes Bild, Suchtext
	 * @return array{text: string, tasks: ?list<array{text: string, done: bool}>, image: ?string, search: string}
	 */
	private function preview(string $type, string $content): array {
		if ($content === '' || $type === 'linked') {
			return ['text' => '', 'tasks' => null, 'image' => null, 'search' => ''];
		}
		if ($type === 'tasklist') {
			$tasks = [];
			$search = [];
			foreach (TaskService::cleanTasks($content) as $task) {
				$label = trim($task['text']);
				if ($label === '') {
					continue;
				}
				$search[] = $label;
				if (count($tasks) < 10) {
					$tasks[] = ['text' => $label, 'done' => $task['completed']];
				}
			}
			return ['text' => '', 'tasks' => $tasks, 'image' => null, 'search' => implode(' ', $search)];
		}
		$image = null;
		if ($type === 'markdown' ? Pcre::match('/!\[[^\]]*+\]\(\s*([^)\s]++)/', $content, $m) : Pcre::match('/<img\b[^>]*?\bsrc=["\']([^"\']++)["\']/i', $content, $m)) {
			$src = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
			// nur Anhänge der App (Rechte prüft der Abruf); fremde Bilder blockiert die CSP
			if (Pcre::match('#^/?api/v1/notes/\d+/attachments/\d+(?:\?[^"\'\s]*)?$#', $src)) {
				$image = $this->urls->toClient('/' . ltrim($src, '/'));
			}
		}
		$plain = $this->text->plainText($content, $type);
		$text = mb_strlen($plain) > self::EXCERPT ? rtrim(mb_substr($plain, 0, self::EXCERPT)) . '…' : $plain;
		return ['text' => $text, 'tasks' => null, 'image' => $image, 'search' => mb_substr($plain, 0, self::SEARCH_CHARS)];
	}
}
