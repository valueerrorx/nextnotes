<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service\View;

use OCA\NextNotes\Db\Note;
use OCA\NextNotes\Db\NoteMapper;
use OCA\NextNotes\Service\ContentUrlRewriter;
use OCA\NextNotes\Service\ConversionService;
use OCA\NextNotes\Service\Exception\BadRequestException;
use OCA\NextNotes\Service\Exception\TooLargeException;
use OCA\NextNotes\Service\HtmlSanitizer;
use OCA\NextNotes\Service\Pcre;
use OCA\NextNotes\Service\SearchService;
use OCA\NextNotes\Service\SearchTextNormalizer;
use OCA\NextNotes\Service\SettingsService;
use OCP\IDateTimeFormatter;

/** Daten der Papierkorb-Seite (Upstream trash.php): Anzahl, je 50 mit Vorschau („Mehr laden“ per Cursor), Suche, Sortierung */
class TrashViewService {
	public const LIMIT = 50;
	// Vorschau: Inhaltsanfang je Notiz
	public const PREVIEW_CHARS = 16384;
	// Render-Budget je Request (Plan M4 §1.2): Summe der Vorschau-HTML und der ganz geladenen Aufgabenlisten
	public const RENDER_BUDGET = 1048576;
	// Aufgabenlisten werden nur bis zu dieser Größe ganz geladen (JSON lässt sich nicht kürzen)
	private const TASKLIST_FULL_MAX = 262144;
	// Aufgabenlisten-Vorschau: höchstens so viele Zeichen Aufgabentext je Eintrag
	private const TASK_TEXT_CHARS = 500;
	public const SORTS = ['trashed', 'created'];

	public function __construct(
		private NoteMapper $notes,
		private SettingsService $settings,
		private SearchTextNormalizer $normalizer,
		private ConversionService $conversion,
		private HtmlSanitizer $sanitizer,
		private ContentUrlRewriter $urls,
		private PageTranslator $tr,
		private IDateTimeFormatter $dates,
	) {
	}

	/**
	 * @param array{wsId: int} $frame
	 * @param mixed $rawAfter Cursor „Zeitstempel:id“ der letzten gezeigten Notiz („Mehr laden“), sonst erste Seite
	 * @return array{sort: string, search: string, total: int, notes: list<array<string, mixed>>, remaining: int, limited: bool, cursor: string}
	 */
	public function build(string $uid, array $frame, mixed $rawSort, mixed $rawSearch, mixed $rawAfter = null): array {
		// ?sort= gilt für diesen Aufruf; gespeichert wird nichts (GET ohne Seiteneffekt, B58)
		$sort = is_string($rawSort) && in_array($rawSort, self::SORTS, true) ? $rawSort : $this->settings->get($uid, 'trash_sort', 'trashed');
		$sort = in_array($sort, self::SORTS, true) ? $sort : 'trashed';
		$byCreated = $sort === 'created';
		$search = is_string($rawSearch) ? trim($rawSearch) : '';
		$terms = $search === '' ? [] : array_slice(array_values(array_filter(array_map(fn (string $t) => $this->normalizer->fold($t),
			preg_split('/\s+/u', $search) ?: []), static fn (string $t) => $t !== '')), 0, SearchService::MAX_TERMS);
		$after = is_string($rawAfter) && preg_match('/^(\d{1,15}):(\d{1,15})$/', $rawAfter, $m) === 1 ? [(int)$m[1], (int)$m[2]] : null;

		$wsId = $frame['wsId'];
		// erste Seite: Gesamtzahl; danach: Anzahl ab dem Cursor (für „noch N“)
		$total = $this->notes->countTrashed($wsId, $terms, $byCreated, $after);
		$rows = $total === 0 ? [] : $this->notes->findTrashedPage($wsId, $terms, $byCreated, self::LIMIT, $after);
		$ids = array_map(static fn (Note $n) => (int)$n->getId(), $rows);
		$prefixes = $ids === [] ? [] : $this->notes->contentPrefixes($ids, self::PREVIEW_CHARS + 1);
		$budget = self::RENDER_BUDGET;
		// gekürzte Aufgabenlisten (bis 256 KiB) in einer Abfrage ganz nachladen, zusammen höchstens im Budget
		$full = array_values(array_filter($rows, static fn (Note $n) => $n->getType() === 'tasklist'
			&& mb_strlen($prefixes[(int)$n->getId()] ?? '') > self::PREVIEW_CHARS));
		if ($full !== []) {
			$small = [];
			$loaded = 0;
			foreach ($this->notes->contentBytes(array_map(static fn (Note $n) => (int)$n->getId(), $full)) as $id => $bytes) {
				if ($bytes <= self::TASKLIST_FULL_MAX && $loaded + $bytes <= $budget) {
					$small[] = $id;
					$loaded += $bytes;
				}
			}
			if ($small !== []) {
				$prefixes = $this->notes->contentByIds($small) + $prefixes;
			}
		}

		$notes = [];
		$cursor = '';
		foreach ($rows as $n) {
			$id = (int)$n->getId();
			$html = $this->preview($uid, (string)$n->getType(), $prefixes[$id] ?? '');
			unset($prefixes[$id]);
			// Vorschau, die das Budget sprengen würde, bleibt leer (Titel und Aktionen bleiben)
			if (strlen($html) > $budget) {
				$html = '';
			}
			$budget -= strlen($html);
			$notes[] = [
				'id' => $id,
				'heading' => (string)($n->getHeading() ?? ''),
				'dateLabel' => $byCreated
					? $this->tr->t('trash.note.created_on', ['date' => $this->date((int)$n->getCreatedAt())], 'Created on {{date}}')
					: $this->tr->t('trash.note.deleted_on', ['date' => $this->date((int)$n->getTrashedAt())], 'Deleted on {{date}}'),
				'html' => $html,
			];
			$cursor = ($byCreated ? (int)$n->getCreatedAt() : (int)$n->getTrashedAt()) . ':' . $id;
		}
		return ['sort' => $sort, 'search' => $search, 'total' => $total, 'notes' => $notes,
			'remaining' => max(0, $total - count($notes)), 'limited' => $total > count($notes), 'cursor' => $cursor];
	}

	private function date(int $ts): string {
		return $this->dates->formatDateTime($ts, 'medium', 'short');
	}

	// Vorschau als bereinigtes HTML in Client-Form; gekürzter Inhalt wird neu bereinigt (schließt offene Tags)
	private function preview(string $uid, string $type, string $content): string {
		if ($content === '') {
			return '';
		}
		$cut = mb_strlen($content) > self::PREVIEW_CHARS;
		try {
			if ($type === 'tasklist') {
				// ganz geladen → gültiges JSON; nur angeschnitten → ungültig, keine Vorschau
				$tasks = json_decode($content, true);
				return is_array($tasks) ? $this->tasklist($tasks) : '';
			}
			if ($cut) {
				$content = mb_substr($content, 0, self::PREVIEW_CHARS);
			}
			if ($type === 'markdown') {
				return $this->conversion->renderSnapshot($uid, $content);
			}
			if ($type === 'linked') {
				return '';
			}
			return $this->urls->toClient($cut ? $this->sanitizer->sanitizeHtml($content) : $content);
		} catch (TooLargeException|BadRequestException) {
			return '';
		}
	}

	/** Upstream renderTasklistHtml/renderTasklistSubtasksHtml: nur Lesen, Text escaped, Links klickbar */
	private function tasklist(array $tasks): string {
		$html = '<div class="task-list-container"><div class="tasks-list">';
		foreach (array_slice($tasks, 0, 200) as $task) {
			// gekürzt wie Notizen: Aufgaben bis etwa PREVIEW_CHARS Ausgabe
			if (strlen($html) >= self::PREVIEW_CHARS) {
				break;
			}
			if (!is_array($task)) {
				continue;
			}
			$done = !empty($task['completed']);
			$subs = '';
			foreach (is_array($task['subtasks'] ?? null) ? array_slice($task['subtasks'], 0, 200) : [] as $sub) {
				if (strlen($html) + strlen($subs) >= self::PREVIEW_CHARS) {
					break;
				}
				if (!is_array($sub) || !is_scalar($sub['text'] ?? null)) {
					continue;
				}
				$subDone = !empty($sub['completed']);
				$subs .= '<div class="task-subitem' . ($subDone ? ' completed' : '') . '"><input type="checkbox" class="task-subitem-checkbox"'
					. ($subDone ? ' checked' : '') . ' disabled /> <span class="task-subitem-text">' . $this->linkify((string)$sub['text']) . '</span></div>';
			}
			if ($subs !== '') {
				$subs = '<div class="task-subtasks">' . $subs . '</div>';
			}
			$html .= '<div class="task-item' . ($done ? ' completed' : '') . ($subs !== '' ? ' has-subtasks' : '') . '">'
				. '<input type="checkbox" disabled' . ($done ? ' checked' : '') . ' /> '
				. '<span class="task-text">' . $this->linkify(is_scalar($task['text'] ?? null) ? (string)$task['text'] : '') . '</span>' . $subs . '</div>';
		}
		return $html . '</div></div>';
	}

	// Upstream linkify_html: erst escapen, dann http(s)/www-Adressen als Link
	private function linkify(string $text): string {
		$escaped = htmlspecialchars(mb_substr($text, 0, self::TASK_TEXT_CHARS), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		return Pcre::replaceCallback('#\b(?:https?://|www\.)[^\s"\'<>]+#i', static function (array $m): string {
			$href = preg_match('/^https?:/i', $m[0]) === 1 ? $m[0] : 'http://' . $m[0];
			return '<a href="' . $href . '" target="_blank" rel="noopener noreferrer">' . $m[0] . '</a>';
		}, $escaped);
	}
}
