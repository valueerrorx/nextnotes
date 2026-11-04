<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service;

/**
 * Formatierung exportierter Notizen ohne Datenbank (Port von src/public/api_export_note.php, src/export_helpers.php,
 * src/lib/tasklists.php): Dateinamen, Front Matter, Aufgabenlisten, HTML-Dokument, Anhang-Verweise, Inhalt bereinigen.
 * Regex nur über Pcre (B46); Verweise auf Fremdes entscheidet der Aufrufer (B47).
 */
class ExportFormatter {
	private const JSON = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
	private const UI_BUTTONS = ['code-block-copy-btn', 'code-block-delete-btn', 'code-block-lang-btn', 'code-block-line-numbers-btn'];

	// Upstream sanitizeDownloadFilename
	public static function fileName(string $title): string {
		$name = trim(Pcre::replace('/[^a-zA-Z0-9-_ ]/', '', $title));
		return $name !== '' ? $name : 'poznote-export';
	}

	/**
	 * YAML Front Matter (Upstream addFrontMatterToMarkdown, mit type: tasklist für den Re-Import)
	 * @param array{title: string, tags: list<string>, folder: ?string, tasklist: bool, favorite: bool, created: string, updated: string} $m
	 */
	public static function frontMatter(array $m): string {
		$out = "---\ntitle: " . json_encode($m['title'], self::JSON) . "\n";
		if ($m['tags'] !== []) {
			$out .= "tags:\n";
			foreach ($m['tags'] as $tag) {
				$out .= '  - ' . json_encode($tag, self::JSON) . "\n";
			}
		}
		if ($m['folder'] !== null && $m['folder'] !== '') {
			$out .= 'folder: ' . json_encode($m['folder'], self::JSON) . "\n";
		}
		if ($m['tasklist']) {
			$out .= "type: tasklist\n";
		}
		$out .= 'favorite: ' . ($m['favorite'] ? 'true' : 'false') . "\n";
		foreach (['created', 'updated'] as $k) {
			if ($m[$k] !== '') {
				$out .= $k . ': ' . json_encode($m[$k], JSON_UNESCAPED_UNICODE) . "\n";
			}
		}
		return $out . "---\n\n";
	}

	/** @return list<array<string, mixed>>|null Aufgaben einer Aufgabenliste, null = kein gültiges JSON-Array */
	public static function tasks(string $json): ?array {
		$tasks = json_decode(Pcre::replace('/^\xEF\xBB\xBF/', '', $json), true);
		return is_array($tasks) && array_is_list($tasks) ? $tasks : null;
	}

	/** @return list<array{text: string, completed: bool}> Upstream getTasklistSubtasks (ohne leere Einträge) */
	private static function subtasks(mixed $task): array {
		$out = [];
		foreach (is_array($task) && is_array($task['subtasks'] ?? null) ? $task['subtasks'] : [] as $s) {
			$text = is_array($s) && is_scalar($s['text'] ?? null) ? trim((string)$s['text']) : '';
			if ($text !== '') {
				$out[] = ['text' => $text, 'completed' => !empty($s['completed'])];
			}
		}
		return $out;
	}

	// Upstream convertTasklistToMarkdown
	public static function tasklistToMarkdown(string $json): string {
		$md = '';
		foreach (self::tasks($json) ?? [] as $task) {
			$text = is_array($task) && is_scalar($task['text'] ?? null) ? trim((string)$task['text']) : '';
			if ($text === '') {
				continue;
			}
			$box = !empty($task['completed']) ? '[x]' : '[ ]';
			$md .= !empty($task['important']) ? '- ' . $box . ' **' . $text . "** ⭐\n" : '- ' . $box . ' ' . $text . "\n";
			foreach (self::subtasks($task) as $s) {
				$md .= '    - ' . ($s['completed'] ? '[x]' : '[ ]') . ' ' . $s['text'] . "\n";
			}
		}
		return $md;
	}

	// Upstream api_export_note.php (Aufgabenliste als HTML) mit renderTasklistSubtasksHtml; ungültig → <pre>
	public static function tasklistToHtml(string $json): string {
		$tasks = self::tasks($json);
		if ($tasks === null) {
			return '<pre>' . self::h($json) . '</pre>';
		}
		$html = '<div class="task-list-container">' . "\n" . '<div class="tasks-list">' . "\n";
		foreach ($tasks as $task) {
			$task = is_array($task) ? $task : [];
			$text = is_scalar($task['text'] ?? null) ? (string)$task['text'] : '';
			$html .= '<div class="task-item' . (!empty($task['completed']) ? ' completed' : '') . (!empty($task['important']) ? ' important' : '') . '">';
			$html .= '<input type="checkbox" disabled' . (!empty($task['completed']) ? ' checked' : '') . ' /> ';
			$html .= '<span class="task-text">' . self::h($text) . '</span>';
			$subtasks = self::subtasks($task);
			if ($subtasks !== []) {
				$html .= '<div class="task-subtasks">';
				foreach ($subtasks as $s) {
					$html .= '<div class="task-subitem' . ($s['completed'] ? ' completed' : '') . '">';
					$html .= '<input type="checkbox" class="task-subitem-checkbox"' . ($s['completed'] ? ' checked' : '') . ' disabled /> ';
					$html .= '<span class="task-subitem-text">' . self::h($s['text']) . '</span></div>';
				}
				$html .= '</div>';
			}
			$html .= '</div>' . "\n";
		}
		return $html . '</div>' . "\n" . '</div>' . "\n";
	}

	/**
	 * Eigenständiges HTML-Dokument (Upstream generateStyledHtml) ohne Skript; $content ist bereinigt und sanitisiert
	 * @param list<string> $tags
	 */
	public static function styledHtml(string $content, string $title, array $tags, string $attachmentLinks = ''): string {
		static $css = null;
		$css ??= (string)file_get_contents(dirname(__DIR__, 2) . '/templates/export/note-file.css');
		$html = "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"UTF-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n"
			. '<title>' . self::h($title) . "</title>\n<style>\n" . $css . "</style>\n</head>\n<body>\n<div class=\"note-metadata\">\n"
			. '<h1 class="note-title">' . self::h($title) . '</h1>';
		if ($tags !== []) {
			$html .= '<div class="note-tags">';
			foreach ($tags as $tag) {
				$html .= '<span class="note-tag">' . self::h($tag) . '</span>';
			}
			$html .= '</div>';
		}
		return $html . $attachmentLinks . "\n</div>\n<div class=\"note-content\">\n" . $content . "\n</div>\n</body>\n</html>";
	}

	/**
	 * Kopfzeile mit Anhängen des ZIP (Upstream buildExportAttachmentLinks); im Inhalt sichtbare Bilder fehlen
	 * @param list<array{id: int, name: string, mime: string, file: string}> $attachments file = Name im ZIP (attachments/…)
	 */
	public static function attachmentLinks(array $attachments, string $content): string {
		$links = [];
		foreach ($attachments as $a) {
			if (self::isImage($a) && str_contains($content, 'attachments/' . $a['id'])) {
				continue;
			}
			$links[] = '<a href="' . self::h('attachments/' . rawurlencode($a['file'])) . '" download="' . self::h($a['name']) . '">' . self::h($a['name']) . '</a>';
		}
		return $links === [] ? '' : '<div class="note-attachments">' . implode(' ', $links) . '</div>';
	}

	/**
	 * Abschnitt „Attachments“ am Ende des Markdown-Exports (Upstream buildExportAttachmentMarkdown)
	 * @param list<array{id: int, name: string, mime: string, file: string}> $attachments
	 */
	public static function attachmentMarkdown(array $attachments, string $content): string {
		$lines = [];
		foreach ($attachments as $a) {
			if (str_contains($content, 'attachments/' . $a['file'])) {
				continue;
			}
			$lines[] = '- [' . str_replace([']', '['], ['\\]', '\\['], $a['name']) . '](attachments/' . rawurlencode($a['file']) . ')';
		}
		return $lines === [] ? '' : "\n\n## Attachments\n\n" . implode("\n", $lines) . "\n";
	}

	/** @param array{name: string, mime: string} $a */
	private static function isImage(array $a): bool {
		return str_starts_with(strtolower($a['mime']), 'image/')
			|| in_array(strtolower(pathinfo($a['name'], PATHINFO_EXTENSION)), ['png', 'jpg', 'jpeg', 'gif', 'svg', 'webp'], true);
	}

	/**
	 * Bedienelemente entfernen und [[…]] außerhalb von Code verlinken (Upstream cleanExportContent/linkExportNoteReferences).
	 * Ergebnis vor der Ausgabe erneut durch den HtmlSanitizer (DOM-Serialisierung ist nicht die des Browsers).
	 * @param null|callable(list<string>): array<string, array{href: ?string, text: string}> $resolve alle Verweise auf einmal (B47)
	 */
	public static function cleanContent(string $html, ?callable $resolve): string {
		if (trim($html) === '') {
			return $html;
		}
		$dom = new \DOMDocument();
		// html/body implizit: unausgeglichenes Markup verliert keine Knoten; Fehler nicht sammeln (B39)
		HtmlSanitizer::loadHtmlQuiet($dom, '<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NODEFDTD | LIBXML_COMPACT | LIBXML_PARSEHUGE);
		$xpath = new \DOMXPath($dom);
		$classTests = implode(' or ', array_map(static fn (string $c) => "contains(concat(' ', normalize-space(@class), ' '), ' $c ')", self::UI_BUTTONS));
		foreach (iterator_to_array($xpath->query('//*[' . $classTests . ']') ?: []) as $node) {
			$node->parentNode?->removeChild($node);
		}
		if ($resolve !== null && str_contains($html, '[[')) {
			self::linkReferences($dom, $xpath, $resolve);
		}
		$out = '';
		foreach ($xpath->query('/html/body') ?: [] as $body) {
			foreach ($body->childNodes as $child) {
				$out .= $dom->saveHTML($child);
			}
		}
		return $out;
	}

	/** @param callable(list<string>): array<string, array{href: ?string, text: string}> $resolve */
	private static function linkReferences(\DOMDocument $dom, \DOMXPath $xpath, callable $resolve): void {
		$nodes = [];
		$refs = [];
		foreach ($xpath->query("//text()[contains(., '[[') and not(ancestor::code) and not(ancestor::pre)]") ?: [] as $node) {
			$parts = Pcre::split('/\[\[([^\]]+)\]\]/', (string)$node->nodeValue, PREG_SPLIT_DELIM_CAPTURE);
			if (count($parts) < 3) {
				continue;
			}
			$nodes[] = [$node, $parts];
			for ($i = 1; $i < count($parts); $i += 2) {
				$refs[$parts[$i]] = true;
			}
		}
		if ($nodes === []) {
			return;
		}
		$targets = $resolve(array_map('strval', array_keys($refs)));
		foreach ($nodes as [$node, $parts]) {
			$fragment = $dom->createDocumentFragment();
			foreach ($parts as $i => $part) {
				if ($i % 2 === 0) {
					if ($part !== '') {
						$fragment->appendChild($dom->createTextNode($part));
					}
					continue;
				}
				$t = $targets[$part] ?? ['href' => null, 'text' => $part];
				if ($t['href'] !== null) {
					$el = $dom->createElement('a');
					$el->setAttribute('href', $t['href']);
					$el->setAttribute('class', 'note-internal-link');
				} else {
					$el = $dom->createElement('span');
					$el->setAttribute('class', 'note-internal-link note-link-broken');
				}
				$el->appendChild($dom->createTextNode($t['text']));
				$fragment->appendChild($el);
			}
			$node->parentNode?->replaceChild($fragment, $node);
		}
	}

	public static function h(string $s): string {
		return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
}
