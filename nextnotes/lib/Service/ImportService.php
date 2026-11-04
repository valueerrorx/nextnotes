<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service;

use OCA\NextNotes\Service\Exception\BadRequestException;
use OCA\NextNotes\Service\Exception\TooLargeException;
use OCA\NextNotes\Service\View\PageTranslator;

/**
 * Drag&Drop-Import einzelner Dateien in die Seitenleiste (Upstream api_import_dropped_notes.php + import_helpers.php
 * importIndividualNotes/importSingleNoteFile, M4b). Anlage über NoteService (Sanitizer, Titel, Rechte); kein ZIP (ADR 0009).
 * Regex nur über Pcre (Plan M4b.4).
 */
class ImportService {
	public const MAX_FILES = 50;
	public const MAX_FILE_BYTES = 16 * 1048576;
	public const MAX_TOTAL_BYTES = 64 * 1048576;
	private const EXTENSIONS = ['md', 'markdown', 'txt', 'html', 'htm', 'json'];

	public function __construct(
		private NoteService $notes,
		private WorkspaceService $workspaces,
		private AccessService $access,
		private SettingsService $settings,
		private PageTranslator $tr,
		private Transaction $tx,
	) {
	}

	/**
	 * @param array<string, mixed>|null $upload Eintrag files[] aus IRequest::getUploadedFile (Mehrfach-Form)
	 * @return array{message: string, error: string, imported: int}
	 */
	public function importDropped(string $uid, ?array $upload, mixed $wsKey, mixed $folderId, mixed $fileCount = null): array {
		$this->tr->setLanguage($this->tr->languageFor($this->settings->read($uid, 'language')));
		// B44: Ziel-Workspace Pflicht (Upstream: sonst alphabetisch erster), Schreibrecht
		if (!is_string($wsKey) || trim($wsKey) === '') {
			throw new BadRequestException('Missing workspace');
		}
		$ws = $this->workspaces->requireByKey($uid, trim($wsKey), 'write');
		$folder = null;
		if (is_scalar($folderId) && (int)$folderId > 0) {
			$folder = $this->access->requireFolder($uid, (int)$folderId, 'write');
			if ($folder->getWorkspaceId() !== $ws->getId()) {
				throw new BadRequestException('folder_id must be a folder of the target workspace');
			}
		}
		$files = self::fileList($upload);
		if ($files === []) {
			throw new BadRequestException($this->tr->t('restore_import.errors.no_notes_selected_or_upload', [], 'No files were received.'));
		}
		// PHP verwirft Dateien über max_file_uploads still; das Frontend sendet die Anzahl mit (P20)
		$sent = is_scalar($fileCount) && (int)$fileCount > 0 ? (int)$fileCount : count($files);
		$max = min(self::MAX_FILES, max(1, (int)ini_get('max_file_uploads')));
		if ($sent > $max || $sent > count($files)) {
			throw new BadRequestException($this->tr->t('restore_import.individual_notes.errors.too_many_files', ['max' => $max, 'count' => $sent], 'Too many files'));
		}
		if (count($files) === 1 && str_ends_with(strtolower($files[0]['name']), '.zip')) {
			throw new BadRequestException('ZIP import is not supported');
		}
		// Grenzen vor der Verarbeitung: nichts angelegt
		$total = 0;
		foreach ($files as $f) {
			$size = $f['error'] === UPLOAD_ERR_OK && is_uploaded_file($f['tmp']) ? (int)filesize($f['tmp']) : 0;
			if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE || $size > self::MAX_FILE_BYTES) {
				throw new TooLargeException('File too large (max ' . intdiv(self::MAX_FILE_BYTES, 1048576) . 'MB): ' . $f['name']);
			}
			$total += $size;
		}
		if ($total > self::MAX_TOTAL_BYTES) {
			throw new TooLargeException('Import too large (max ' . intdiv(self::MAX_TOTAL_BYTES, 1048576) . 'MB per request)');
		}
		$wsKeyOut = (string)$this->workspaces->keyFor($uid, (int)$ws->getId());
		$imported = 0;
		$errors = [];
		foreach ($files as $f) {
			try {
				$this->importOne($uid, $f, $wsKeyOut, $folder?->getId());
				$imported++;
			} catch (BadRequestException|TooLargeException $e) {
				$errors[] = $f['name'] . ': ' . $e->getMessage();
			}
		}
		$folderName = $folder !== null ? (string)$folder->getName() : $this->tr->t('restore_import.sections.individual_notes.no_folder', [], 'No folder (root level)');
		$message = $this->tr->t('restore_import.messages.notes_imported', ['count' => $imported, 'workspace' => (string)$ws->getName(), 'folder' => $folderName],
			'Imported {{count}} note(s) into workspace "{{workspace}}", folder "{{folder}}".');
		if ($errors !== []) {
			$message .= ' ' . count($errors) . ' error(s): ' . implode('; ', $errors);
		}
		if ($imported === 0) {
			throw new BadRequestException($errors !== [] ? implode('; ', $errors) : 'Import failed');
		}
		return ['message' => $message, 'error' => implode('; ', $errors), 'imported' => $imported];
	}

	/** @return list<array{name: string, tmp: string, error: int}> */
	private static function fileList(?array $upload): array {
		if ($upload === null || !isset($upload['name'])) {
			return [];
		}
		$names = (array)$upload['name'];
		$out = [];
		foreach ($names as $i => $name) {
			if (!is_string($name) || $name === '') {
				continue;
			}
			$out[] = ['name' => $name, 'tmp' => (string)(((array)($upload['tmp_name'] ?? []))[$i] ?? ''), 'error' => (int)(((array)($upload['error'] ?? []))[$i] ?? UPLOAD_ERR_NO_FILE)];
		}
		return $out;
	}

	/** @param array{name: string, tmp: string, error: int} $f */
	private function importOne(string $uid, array $f, string $wsKey, ?int $folderId): void {
		if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp'])) {
			throw new BadRequestException($this->tr->t('restore_import.individual_notes.errors.upload_error', [], 'Upload error'));
		}
		$ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
		if (!in_array($ext, self::EXTENSIONS, true)) {
			throw new BadRequestException($this->tr->t('restore_import.individual_notes.errors.invalid_file_type', ['allowed' => '.html, .md, .markdown, .txt, .json'], 'Invalid file type'));
		}
		$content = (string)file_get_contents($f['tmp']);
		if (!mb_check_encoding($content, 'UTF-8')) {
			throw new BadRequestException('Invalid UTF-8 in content');
		}
		$note = $this->noteFromFile($content, $f['name'], $ext === 'htm' ? 'html' : $ext);
		$in = ['type' => $note['type'], 'heading' => $note['title'], 'tags' => $note['tags'], 'workspace' => $wsKey, 'folder_id' => $folderId];
		if ($note['created'] !== null) {
			$in['created_date'] = $note['created'];
		}
		// Base64-Bilder: leer anlegen, Inhalt per update (EmbeddedImageService, Quote, Rollback); alles in einer Transaktion
		$images = $note['type'] === 'note' && stripos($note['content'], 'data:image/') !== false;
		$this->tx->run(function () use ($uid, $in, $note, $images, $wsKey): void {
			$created = $this->notes->create($uid, $in + ['content' => $images ? '' : $note['content']]);
			$id = (int)$created['note']['id'];
			if ($images) {
				$this->notes->update($uid, $id, ['content' => $note['content']], '', '');
			}
			if ($note['favorite']) {
				$this->notes->toggleFavorite($uid, $id, $wsKey);
			}
		});
	}

	/**
	 * Upstream importSingleNoteFile ohne Datenbank: Typ, Titel, Tags, Favorit, Datum, Inhalt
	 * @return array{type: string, title: string, tags: list<string>, favorite: bool, created: ?string, content: string}
	 */
	public function noteFromFile(string $content, string $fileName, string $ext): array {
		$type = in_array($ext, ['md', 'markdown'], true) ? 'markdown' : 'note';
		$content = Pcre::replace('/^\xEF\xBB\xBF/', '', $content);
		$front = [];
		$tags = [];
		if ($type === 'note') {
			$tasks = self::tasklistJson($content, $ext);
			if ($tasks !== null) {
				$type = 'tasklist';
				$content = $tasks;
			} elseif ($ext === 'json') {
				$content = '<pre>' . htmlspecialchars($content, ENT_QUOTES, 'UTF-8') . '</pre>';
			} elseif ($ext === 'html') {
				$content = self::removeStyleTags($content);
			} elseif ($ext === 'txt') {
				$content = nl2br(htmlspecialchars($content, ENT_QUOTES, 'UTF-8'), true);
			}
		} else {
			[$front, $content] = self::parseFrontMatter($content);
			$tasks = ($front['type'] ?? null) === 'tasklist' ? self::checkboxListToTasks($content) : null;
			if ($tasks !== null) {
				$type = 'tasklist';
				$content = $tasks;
			} else {
				[$tags, $content] = self::obsidianTags($content);
			}
		}
		$title = isset($front['title']) ? (is_array($front['title']) ? implode(' ', $front['title']) : (string)$front['title']) : pathinfo($fileName, PATHINFO_FILENAME);
		$all = [];
		if (isset($front['tags'])) {
			$all = is_array($front['tags']) ? $front['tags'] : [(string)$front['tags']];
		}
		$all = array_merge($all, $tags);
		// Upstream sanitizeTagsString: Leerzeichen trennen, leere weg, doppelte weg
		$clean = [];
		foreach ($all as $t) {
			foreach (explode(',', str_replace(' ', ',', (string)$t)) as $part) {
				$part = trim($part);
				// Tags mit Markup-Zeichen (B20) fallen weg statt den Import abzubrechen
				if ($part !== '' && mb_strlen($part) <= 255 && !Pcre::match('/[<>"`\x00-\x1F\x7F]/', $part) && !in_array($part, $clean, true)) {
					$clean[] = $part;
				}
			}
		}
		$created = isset($front['created']) && is_string($front['created']) && Pcre::match('/^\d{4}-\d{2}-\d{2}/', $front['created']) ? substr($front['created'], 0, 10) : null;
		return [
			'type' => $type,
			'title' => trim($title) !== '' ? trim($title) : $this->tr->t('restore_import.individual_notes.default_title_with_date', ['date' => date('Y-m-d H:i:s')], 'Imported note'),
			'tags' => $clean,
			'favorite' => ($front['favorite'] ?? null) === true,
			'created' => $created,
			'content' => $content,
		];
	}

	/**
	 * Upstream parseFrontMatter (YAML-Teilmenge: key: value, [a, b], Listen mit -)
	 * @return array{0: array<string, mixed>, 1: string}
	 */
	public static function parseFrontMatter(string $content): array {
		$content = str_replace(["\r\n", "\r"], "\n", $content);
		if (!str_starts_with($content, '---')) {
			return [[], $content];
		}
		// erste Zeile ---, Ende an der nächsten Zeile, die nur --- (plus Leerraum) enthält
		$lines = explode("\n", $content);
		if (trim($lines[0]) !== '---') {
			return [[], $content];
		}
		$end = null;
		for ($i = 1, $n = count($lines); $i < $n; $i++) {
			if (Pcre::match('/^---\s*$/', $lines[$i])) {
				$end = $i;
				break;
			}
		}
		if ($end === null || $end === $n - 1 && !str_ends_with($content, "\n")) {
			return [[], $content];
		}
		$meta = [];
		$current = null;
		$yaml = array_slice($lines, 1, $end - 1);
		foreach ($yaml as $j => $line) {
			if (trim($line) === '') {
				continue;
			}
			if (Pcre::match('/^\s*-\s+(.+)$/', $line, $m)) {
				if ($current !== null) {
					$meta[$current][] = trim(trim($m[1]), '"\'');
				}
				continue;
			}
			if (!Pcre::match('/^([a-zA-Z_][a-zA-Z0-9_]*)\s*:\s*(.*)$/', trim($line), $m)) {
				continue;
			}
			$key = $m[1];
			$value = trim($m[2]);
			$nextIsList = isset($yaml[$j + 1]) && Pcre::match('/^-\s+/', trim($yaml[$j + 1]));
			if ($value === '' || $nextIsList) {
				$current = $key;
				$meta[$key] = [];
				continue;
			}
			$current = null;
			if (Pcre::match('/^\[(.*)\]$/', $value, $m)) {
				$meta[$key] = array_map(static fn (string $x) => trim(trim($x), '"\''), explode(',', $m[1]));
				continue;
			}
			$value = trim($value, '"\'');
			$meta[$key] = $value === 'true' ? true : ($value === 'false' ? false : $value);
		}
		return [$meta, implode("\n", array_slice($lines, $end + 1))];
	}

	/** @return array{0: list<string>, 1: string} Upstream extractObsidianTags: Zeilen nur aus #tags am Anfang */
	private static function obsidianTags(string $content): array {
		$lines = explode("\n", $content);
		$tags = [];
		$remove = 0;
		foreach ($lines as $i => $line) {
			$t = trim($line);
			if ($t === '') {
				$remove = $i + 1;
				continue;
			}
			if (!Pcre::match('/^#[^\s#]/', $t)) {
				break;
			}
			Pcre::matchAll('/#([^\s#]++)/', $t, $m);
			array_push($tags, ...$m[1]);
			$remove = $i + 1;
		}
		if ($remove > 0 && $tags !== []) {
			$content = ltrim(implode("\n", array_slice($lines, $remove)), "\n\r");
		}
		return [$tags, $content];
	}

	// Upstream removeStyleTags; nur bis zum letzten </style> (linear, vgl. B46)
	private static function removeStyleTags(string $html): string {
		$cut = strripos($html, '</style>');
		if ($cut === false) {
			return $html;
		}
		$cut += 8;
		return Pcre::replace('/<style[^>]*+>(?:[^<]++|<(?!\/style>))*+<\/style>/i', '', substr($html, 0, $cut)) . substr($html, $cut);
	}

	// Upstream convertMarkdownCheckboxListToTasklistJson: - [ ] / - [x], eingerückte = Unteraufgaben
	private static function checkboxListToTasks(string $markdown): ?string {
		$tasks = [];
		$parentIndent = null;
		foreach (explode("\n", $markdown) as $line) {
			if (!Pcre::match('/^(\s*)[-*+]\s+\[([ xX])\]\s+(.*)$/u', $line, $m)) {
				continue;
			}
			$indent = strlen(str_replace("\t", '    ', $m[1]));
			$text = trim($m[3]);
			$done = strtolower($m[2]) === 'x';
			if ($parentIndent !== null && $indent > $parentIndent && $tasks !== []) {
				if ($text !== '') {
					$tasks[count($tasks) - 1]['subtasks'][] = ['id' => self::taskId(), 'text' => $text, 'completed' => $done];
				}
				continue;
			}
			$important = false;
			if (Pcre::match('/\s+⭐\s*$/u', $text)) {
				$important = true;
				$text = Pcre::replace('/^\*\*(.*)\*\*$/us', '$1', trim(Pcre::replace('/\s+⭐\s*$/u', '', $text)));
			}
			$text = trim($text);
			if ($text === '') {
				continue;
			}
			$parentIndent = $indent;
			$tasks[] = ['id' => self::taskId(), 'text' => $text, 'completed' => $done, 'noteId' => '', 'important' => $important];
		}
		return $tasks === [] ? null : json_encode($tasks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}

	// Upstream detectImportedTasklistContent: Poznote-JSON (Liste oder {"tasks": […]}) oder exportiertes Tasklist-HTML; IDs neu
	private static function tasklistJson(string $content, string $ext): ?string {
		if (str_contains($content, 'excalidraw-container')) {
			return null;
		}
		$decoded = json_decode(trim($content), true);
		if (is_array($decoded) && isset($decoded['tasks']) && is_array($decoded['tasks']) && count($decoded) === 1) {
			$decoded = $decoded['tasks'];
		}
		$tasks = null;
		if (is_array($decoded) && array_is_list($decoded)) {
			$meta = $decoded === [];
			foreach ($decoded as $t) {
				if (!is_array($t) || !array_key_exists('text', $t)) {
					$meta = false;
					break;
				}
				$meta = $meta || array_intersect(['completed', 'important', 'noteId', 'id'], array_keys($t)) !== [];
			}
			$tasks = $meta ? $decoded : null;
		} elseif ($ext === 'html' && stripos($content, 'task-item') !== false) {
			$tasks = self::tasksFromHtml($content);
		}
		if ($tasks === null) {
			return null;
		}
		foreach ($tasks as &$t) {
			$t['id'] = self::taskId();
			$t['noteId'] = '';
			foreach ($t['subtasks'] ?? [] as $k => $s) {
				if (is_array($s)) {
					$t['subtasks'][$k]['id'] = self::taskId();
				}
			}
		}
		unset($t);
		return json_encode($tasks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}

	/** @return list<array<string, mixed>>|null Upstream extractTaskListFromHTML */
	private static function tasksFromHtml(string $html): ?array {
		$dom = new \DOMDocument();
		$prev = libxml_use_internal_errors(true);
		$ok = $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
		libxml_clear_errors();
		libxml_use_internal_errors($prev);
		if (!$ok) {
			return null;
		}
		$xpath = new \DOMXPath($dom);
		$tasks = [];
		foreach ($xpath->query('//div[contains(@class, "task-item")]') ?: [] as $item) {
			if (!$item instanceof \DOMElement || $item->getAttribute('data-task-id') === '') {
				continue;
			}
			$classes = $item->getAttribute('class');
			$text = $xpath->query('.//span[contains(@class, "task-text")]', $item)?->item(0);
			$task = ['id' => 0, 'text' => $text !== null ? trim($text->textContent) : '', 'completed' => str_contains($classes, 'completed'), 'important' => str_contains($classes, 'important')];
			$subs = [];
			foreach ($xpath->query('.//div[contains(@class, "task-subitem")]', $item) ?: [] as $sub) {
				$st = $xpath->query('.//span[contains(@class, "task-subitem-text")]', $sub)?->item(0);
				$subText = $st !== null ? trim($st->textContent) : '';
				if ($subText !== '' && $sub instanceof \DOMElement) {
					$subs[] = ['id' => 0, 'text' => $subText, 'completed' => str_contains($sub->getAttribute('class'), 'completed')];
				}
			}
			if ($subs !== []) {
				$task['subtasks'] = $subs;
			}
			$tasks[] = $task;
		}
		return $tasks === [] ? null : $tasks;
	}

	// Upstream generateImportedTaskId: Zeitstempel, je Request nie doppelt
	private static function taskId(): int {
		static $last = 0;
		$id = (int)(microtime(true) * 10000);
		$last = $id <= $last ? $last + 1 : $id;
		return $last;
	}
}
