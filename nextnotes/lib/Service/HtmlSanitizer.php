<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-FileCopyrightText: Timothé Poznanski (Poznote, MIT)
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service;

use DOMDocument;
use DOMElement;
use DOMXPath;

// Port von src/lib/html-sanitize.php (Poznote, MIT): sanitizeHtml, sanitizeMarkdownContent, Iframe-Regeln
class HtmlSanitizer {
	// Upstream ALLOWED_IFRAME_DOMAINS; Admin-Liste folgt mit ISettings (M4)
	public const IFRAME_DOMAINS = [
		'youtube.com', 'www.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com',
		'player.bilibili.com', 'www.bilibili.com', 'bilibili.com',
	];

	private const ALLOWED_TAGS = [
		'p', 'br', 'div', 'span', 'a', 'strong', 'b', 'em', 'i', 'u', 's', 'strike',
		'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
		'ul', 'ol', 'li', 'dl', 'dt', 'dd',
		'table', 'caption', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td',
		'blockquote', 'pre', 'code', 'hr',
		'img', 'figure', 'figcaption',
		'details', 'summary',
		'mark', 'small', 'sub', 'sup',
		'abbr', 'cite', 'q', 'time',
		'input', 'label',
		'iframe', 'video', 'audio',
		'button',
		'aside',
		'svg', 'path', 'rect', 'polyline',
	];

	private const ALLOWED_ATTRS = [
		'a' => ['href', 'title', 'target', 'rel', 'download'],
		'img' => ['src', 'alt', 'title', 'width', 'height', 'data-is-excalidraw', 'data-excalidraw-note-id'],
		'td' => ['colspan', 'rowspan', 'data-formula'],
		'th' => ['colspan', 'rowspan', 'scope'],
		'ol' => ['type', 'start'],
		'div' => ['class', 'data-tasklist-json', 'data-markdown-content', 'data-excalidraw', 'data-diagram-id', 'data-task-embed', 'contenteditable'],
		'span' => ['class'],
		'input' => ['type', 'checked', 'disabled'],
		'time' => ['datetime'],
		'blockquote' => ['cite'],
		'q' => ['cite'],
		'pre' => ['data-language', 'data-line-numbers', 'data-auto-language'],
		'code' => ['data-language', 'data-auto-language'],
		'iframe' => ['src', 'width', 'height', 'frameborder', 'allow', 'allowfullscreen', 'allowtransparency', 'title', 'sandbox', 'loading', 'referrerpolicy', 'style', 'class', 'scrolling', 'contenteditable', 'data-is-audio', 'data-audio-src', 'data-converted-from-audio'],
		'video' => ['src', 'width', 'height', 'preload', 'poster', 'class', 'style', 'controls', 'muted', 'playsinline', 'loop', 'autoplay'],
		'audio' => ['src', 'preload', 'class', 'style', 'controls', 'muted', 'loop', 'autoplay'],
		// ohne data-action: kein Upstream-Code schreibt Aktionsknöpfe in Inhalte; sonst UI-Aktionen im Recht des Lesers (B26)
		'button' => ['class'],
		'svg' => ['viewBox', 'width', 'height', 'aria-hidden', 'fill', 'xmlns', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin'],
		'path' => ['d', 'fill', 'fill-rule', 'clip-rule', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin'],
		'rect' => ['x', 'y', 'width', 'height', 'rx', 'ry', 'fill', 'stroke', 'stroke-width'],
		'polyline' => ['points', 'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin'],
	];

	private const GLOBAL_ATTRS = ['id', 'class', 'style'];

	private const STRIPPED_TAGS = [
		'script', 'style', 'noscript', 'template',
		'object', 'embed', 'applet', 'param', 'canvas', 'map', 'area',
		'frame', 'frameset', 'noframes',
		'head', 'title', 'link', 'meta', 'base',
		'select', 'option', 'optgroup', 'datalist', 'textarea',
	];

	private const BLOCK_LEVEL_TAGS = [
		'section', 'article', 'main', 'header', 'footer', 'nav',
		'address', 'hgroup', 'form', 'fieldset', 'legend',
		'center', 'dir', 'menu',
	];

	private const DANGEROUS_PATTERNS = ['/javascript:/i', '/data:(?!image\/)/i', '/vbscript:/i'];

	// Inhalt nach Notiztyp wie NotesController::sanitizeContentForType
	public function sanitizeForType(string $type, string $content): string {
		if ($content === '') {
			return $content;
		}
		if ($type === 'markdown') {
			return $this->sanitizeMarkdown($content);
		}
		if ($type === 'tasklist' && self::isStructuredJson($content)) {
			return $content;
		}
		return $this->sanitizeHtml($content);
	}

	public static function isStructuredJson(string $content): bool {
		// nur JSON-Leerraum (RFC 8259) erlaubt; trim() entfernte auch \x0B/\0 → Rohspeicherung von Nicht-JSON (M3b1-Test)
		$trimmed = trim($content, " \t\n\r");
		if ($trimmed === '' || ($trimmed[0] !== '[' && $trimmed[0] !== '{')) {
			return false;
		}
		$decoded = json_decode($content, true);
		return json_last_error() === JSON_ERROR_NONE && is_array($decoded);
	}

	public function iframeSrcIsTrusted(string $src): bool {
		$src = trim($src);
		if ($src === '') {
			return false;
		}
		if (preg_match('#^([a-z][a-z0-9+.\-]*):#i', $src, $m) && !in_array(strtolower($m[1]), ['http', 'https'], true)) {
			return false;
		}
		$host = parse_url($src, PHP_URL_HOST);
		if ($host === null || $host === false || $host === '') {
			if (str_starts_with($src, '//')) {
				return false;
			}
			return $src[0] === '/'
				|| str_starts_with($src, './')
				|| preg_match('~^audio_player\.php(?:[?#]|$)~i', $src) === 1;
		}
		$host = strtolower($host);
		foreach (self::IFRAME_DOMAINS as $domain) {
			if ($host === $domain || str_ends_with($host, '.' . $domain)) {
				return true;
			}
		}
		return false;
	}

	public function sanitizeHtml(string $html): string {
		if ($html === '') {
			return $html;
		}
		$previous = libxml_use_internal_errors(true);
		$dom = new DOMDocument();
		$dom->encoding = 'UTF-8';
		$wrapped = '<html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body>' . $html . '</body></html>';
		@$dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
		$xpath = new DOMXPath($dom);

		// Laufzeit-UI des Editors gehört nicht in gespeicherten Inhalt
		foreach (['//a[contains(concat(" ", normalize-space(@class), " "), " heading-anchor ") or @data-heading-anchor="true"]',
			'//*[contains(concat(" ", normalize-space(@class), " "), " tasklist-embed-widget ")]'] as $query) {
			foreach (iterator_to_array($xpath->query($query) ?: []) as $node) {
				$node->parentNode?->removeChild($node);
			}
		}

		$toRemove = [];
		$toUnwrap = [];
		foreach (iterator_to_array($xpath->query('//body//*') ?: []) as $element) {
			if (!$element instanceof DOMElement || $element->parentNode === null) {
				continue;
			}
			$tag = strtolower($element->tagName);
			$inCode = false;
			$inSvg = false;
			for ($p = $element->parentNode; $p instanceof DOMElement; $p = $p->parentNode) {
				$pt = strtolower($p->tagName);
				if ($pt === 'code' || $pt === 'pre') {
					$inCode = true;
					break;
				}
				if ($pt === 'svg') {
					$inSvg = true;
				}
			}
			// Gefährliche Tags in Codeblöcken werden zu Text
			if ($inCode && in_array($tag, ['script', 'iframe', 'object', 'embed', 'applet', 'form', 'style'], true)) {
				$text = $dom->createTextNode(htmlspecialchars((string)$dom->saveHTML($element), ENT_QUOTES, 'UTF-8'));
				$element->parentNode->replaceChild($text, $element);
				continue;
			}
			if (!in_array($tag, self::ALLOWED_TAGS, true)) {
				if ($inSvg || in_array($tag, self::STRIPPED_TAGS, true)) {
					$toRemove[] = $element;
				} else {
					$toUnwrap[] = $element;
				}
				continue;
			}
			$this->cleanAttributes($element, $tag, $toRemove);
		}

		foreach ($toRemove as $element) {
			$element->parentNode?->removeChild($element);
		}
		foreach ($toUnwrap as $element) {
			$parent = $element->parentNode;
			if ($parent === null) {
				continue;
			}
			if (!$element->hasChildNodes()) {
				$parent->removeChild($element);
				continue;
			}
			$replacement = in_array(strtolower($element->tagName), self::BLOCK_LEVEL_TAGS, true)
				? $dom->createElement('div')
				: $dom->createDocumentFragment();
			while ($element->firstChild !== null) {
				$replacement->appendChild($element->firstChild);
			}
			$parent->replaceChild($replacement, $element);
		}

		$body = $dom->getElementsByTagName('body')->item(0);
		$out = '';
		if ($body !== null) {
			foreach ($body->childNodes as $child) {
				$out .= $dom->saveHTML($child);
			}
		}
		$out = trim($out);
		$out = str_replace(['&lt;script', '&lt;/script'], '', $out);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		return $out;
	}

	/** @param DOMElement[] $toRemove */
	private function cleanAttributes(DOMElement $element, string $tag, array &$toRemove): void {
		$remove = [];
		$allowed = self::ALLOWED_ATTRS[$tag] ?? [];
		foreach (iterator_to_array($element->attributes) as $attr) {
			$name = strtolower($attr->name);
			$value = $attr->value;
			if (!in_array($name, $allowed, true) && !in_array($name, self::GLOBAL_ATTRS, true)) {
				$remove[] = $attr->name;
				continue;
			}
			// href/src: Whitespace/Steuerzeichen (nach Entity-Decoding durch DOM) vor der Schema-Prüfung entfernen (B19)
			$probe = ($name === 'href' || $name === 'src') ? (preg_replace('/[\x00-\x20\x7F\p{Z}]+/u', '', $value) ?? '') : $value;
			foreach (self::DANGEROUS_PATTERNS as $pattern) {
				if (preg_match($pattern, $value) || preg_match($pattern, $probe)) {
					$remove[] = $attr->name;
					continue 2;
				}
			}
			if ($name === 'href' || $name === 'src') {
				if ($tag === 'iframe' && $name === 'src') {
					if (!$this->iframeSrcIsTrusted($value)) {
						$toRemove[] = $element;
						return;
					}
					continue;
				}
				if ($name === 'src' && $tag === 'img' && str_starts_with($value, 'data:image/')) {
					continue;
				}
				if (!preg_match('/^(https?:\/\/|mailto:|\/|#|\.\/|\.\.\/)/i', $value)
					&& !str_starts_with($value, 'data:') && preg_match('/[<>"\']/', $value)) {
					$remove[] = $attr->name;
				}
			}
			if ($name === 'type' && $tag === 'ol' && !preg_match('/^[1aAiI]$/', $value)) {
				$remove[] = $attr->name;
			}
			if ($name === 'start' && $tag === 'ol' && !preg_match('/^-?\d{1,6}$/', $value)) {
				$remove[] = $attr->name;
			}
		}
		foreach ($remove as $name) {
			$element->removeAttribute($name);
		}
		if ($tag === 'ol' && ($element->hasAttribute('start') || stripos($element->getAttribute('style'), 'counter-reset') !== false)) {
			$start = $element->hasAttribute('start') ? (int)$element->getAttribute('start') : null;
			if ($start === 1) {
				$element->removeAttribute('start');
			}
			$style = self::listStartStyle($element->getAttribute('style'), $start);
			if ($style !== '') {
				$element->setAttribute('style', $style);
			} else {
				$element->removeAttribute('style');
			}
		}
	}

	private static function listStartStyle(string $style, ?int $start): string {
		$style = preg_replace('/(^|;)\s*counter-reset\s*:[^;]*/i', '$1', $style) ?? $style;
		$style = trim(preg_replace('/;(\s*;)+/', ';', $style) ?? $style, "; \t\n\r");
		if ($start !== null && $start !== 1) {
			$style = ($style !== '' ? $style . '; ' : '') . 'counter-reset: item ' . ($start - 1);
		}
		return $style !== '' ? $style . ';' : '';
	}

	public function sanitizeMarkdown(string $markdown): string {
		if ($markdown === '') {
			return $markdown;
		}
		$markdown = str_replace("\x00", '', $markdown);
		$segments = [];
		$markdown = self::maskCode($markdown, $segments);
		$content = '(?:[^\n]|\n(?![ \t]*\n))*?';
		$markdown = preg_replace('/<script\b[^<>]*>' . $content . '<\/script\s*>/i', '', $markdown) ?? '';
		$markdown = preg_replace('/<style\b[^<>]*>' . $content . '<\/style\s*>/i', '', $markdown) ?? '';
		$markdown = preg_replace('/<(object|embed|applet)\b[^<>]*>' . $content . '<\/\1\s*>/i', '', $markdown) ?? '';
		$markdown = preg_replace('/<(object|embed|applet)\b[^<>]*\/?>/i', '', $markdown) ?? '';
		$markdown = preg_replace('/<form\b[^<>]*>' . $content . '<\/form\s*>/i', '', $markdown) ?? '';
		$markdown = preg_replace('/(<[a-zA-Z][^<>]*?)[\s\/]+on\w+\s*=\s*(["\']).*?\2/i', '$1', $markdown) ?? '';
		$markdown = preg_replace('/(<[a-zA-Z][^<>]*?)[\s\/]+on\w+\s*=\s*[^\s<>]*/i', '$1', $markdown) ?? '';
		$markdown = preg_replace('/(href|src)\s*=\s*(["\'])\s*javascript:/i', '$1=$2', $markdown) ?? '';
		$markdown = preg_replace('/(href|src)\s*=\s*(["\'])\s*vbscript:/i', '$1=$2', $markdown) ?? '';
		return self::restoreCode($markdown, $segments);
	}

	/** @param array<int, string> $segments */
	public static function maskCode(string $markdown, array &$segments): string {
		$segments = [];
		$store = static function (string $code) use (&$segments): string {
			$i = count($segments);
			$segments[$i] = $code;
			return "\x00MDCODE" . $i . "\x00";
		};
		$maskInline = static fn (string $line): string => preg_replace_callback('/(?<!\\\\)`([^`\n]+?)(?<!\\\\)`/', static fn ($m) => $store($m[0]), $line) ?? $line;
		$output = [];
		$pending = [];
		$inFence = false;
		foreach (explode("\n", $markdown) as $line) {
			if (!$inFence) {
				if (preg_match('/^[ \t]*```/', $line)) {
					$inFence = true;
					$pending = [$line];
				} else {
					$output[] = $maskInline($line);
				}
				continue;
			}
			$pending[] = $line;
			if (preg_match('/^[ \t]*```[ \t\r]*$/', $line)) {
				$output[] = $store(implode("\n", $pending));
				$inFence = false;
				$pending = [];
			}
		}
		foreach ($pending as $line) {
			$output[] = $maskInline($line);
		}
		return implode("\n", $output);
	}

	/** @param array<int, string> $segments */
	public static function restoreCode(string $markdown, array $segments): string {
		if ($segments === []) {
			return $markdown;
		}
		return preg_replace_callback('/\x00MDCODE(\d+)\x00/', static fn ($m) => $segments[(int)$m[1]] ?? '', $markdown) ?? $markdown;
	}
}
