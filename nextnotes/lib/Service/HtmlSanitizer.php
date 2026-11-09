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
use OCA\NextNotes\Service\Exception\TooLargeException;

// Port von src/lib/html-sanitize.php (Poznote, MIT): sanitizeHtml, sanitizeMarkdownContent, Iframe-Regeln
class HtmlSanitizer {
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
		'div' => ['class', 'data-tasklist-json', 'data-markdown-content', 'data-excalidraw', 'data-diagram-id', 'data-task-embed', 'contenteditable', 'data-math', 'data-nn-sheet'],
		'span' => ['class', 'data-math'],
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

	// libxml braucht ~190 Byte je Knoten; Obergrenze hält 16 MiB aus Kurz-Tags unter ~256 MB (B39)
	public const MAX_TAGS = 500000;
	// Obergrenze Knotenverschiebungen beim Auspacken (verschachtelte unbekannte Tags wären sonst quadratisch)
	private const MAX_UNWRAP_MOVES = 20000000;
	// libxml 2.9 prüft doppelte Attribute je Tag quadratisch (nur HTML); Markdown-Scanner kostet linear je Attribut (B39)
	public const MAX_ATTRS_PER_TAG = 256;
	public const MAX_ATTRS = 1000000;

	private const DANGEROUS_PATTERNS = ['/javascript:/i', '/data:(?!image\/)/i', '/vbscript:/i'];

	// Start-/End-Tag-Name, Attributliste, ein Attribut (Trenner, Name, Wert; offenes Anführungszeichen läuft wie im Browser bis zum Ende)
	private const MD_TAG_AT = '/\G<(\/?+)([a-zA-Z][^\s\/>]*+)/';
	private const MD_TAG_FULL_AT = '/\G<(\/?+)([a-zA-Z][^\s\/>]*+)((?:[\s\/]*+[^\s\/>][^\s\/>=]*+(?:\s*+=\s*+(?:"[^"]*+"?|\'[^\']*+\'?|[^\s>]*+))?)*+)/';
	private const MD_ATTR_AT = '/\G([\s\/]*+)([^\s\/>][^\s\/>=]*+)(?:\s*+=\s*+("[^"]*+"?|\'[^\']*+\'?|[^\s>]*+))?/';
	private const MD_RAWTEXT_TAGS = ['script', 'style', 'textarea', 'title', 'noscript', 'xmp', 'iframe', 'noembed', 'noframes', 'plaintext'];
	private const MD_MAX_ROUNDS = 4;
	private const VIEW_MARKUP = 0;
	private const VIEW_TEXT = 1;
	private const VIEW_FOREIGN = 2;

	public function __construct(
		private EmbedDomainService $embedDomains,
	) {
	}

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
		if (Pcre::match('#^([a-z][a-z0-9+.\-]*):#i', $src, $m) && !in_array(strtolower($m[1]), ['http', 'https'], true)) {
			return false;
		}
		$host = parse_url($src, PHP_URL_HOST);
		if ($host === null || $host === false || $host === '') {
			if (str_starts_with($src, '//')) {
				return false;
			}
			return $src[0] === '/'
				|| str_starts_with($src, './')
				|| Pcre::match('~^audio_player\.php(?:[?#]|$)~i', $src);
		}
		// exakter Host aus der Admin-Liste, keine Subdomains (B41)
		return $this->embedDomains->isAllowedHost($host);
	}

	public function sanitizeHtml(string $html): string {
		if ($html === '') {
			return $html;
		}
		// Start-Tags und Kommentare grob zählen ('<' minus '</'), bevor libxml Knoten anlegt
		if (substr_count($html, '<') - substr_count($html, '</') > self::MAX_TAGS) {
			throw new TooLargeException('Content has too many elements (max ' . self::MAX_TAGS . ')');
		}
		self::assertAttributeBudget($html, self::MAX_ATTRS_PER_TAG);
		// libxml-Fehler nicht sammeln: ein Eintrag je offenem Tag sprengte den Speicher (B39)
		libxml_clear_errors();
		$previous = libxml_use_internal_errors(false);
		try {
			return $this->sanitizeDom($html);
		} finally {
			libxml_use_internal_errors($previous);
			libxml_clear_errors();
		}
	}

	/** Einzige loadHTML-Stelle der App (check-upstream h): Meldungen aus, nichts gesammelt (B39); letzter Fehler bleibt abfragbar */
	public static function loadHtmlQuiet(DOMDocument $dom, string $html, int $flags): bool {
		$previous = libxml_use_internal_errors(false);
		set_error_handler(static fn (): bool => true);
		try {
			return $dom->loadHTML($html, $flags | LIBXML_NOERROR | LIBXML_NOWARNING);
		} finally {
			restore_error_handler();
			libxml_use_internal_errors($previous);
		}
	}

	private function sanitizeDom(string $html): string {
		$dom = new DOMDocument();
		$dom->encoding = 'UTF-8';
		// ohne schließendes </body>: sonst je offenem Tag ein Fehler; PARSEHUGE: sonst fällt ein Attribut > 10 MB weg (B37)
		$wrapped = '<html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body>' . $html;
		self::loadHtmlQuiet($dom, $wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_PARSEHUGE);
		// Fataler Fehler hält den Parser an und ist dann der letzte; Rest wäre abgeschnitten → ablehnen (B37)
		$error = libxml_get_last_error();
		if ($error !== false && $error->level === LIBXML_ERR_FATAL) {
			throw new TooLargeException('Content too complex to process (' . trim($error->message) . ')');
		}
		$body = $dom->getElementsByTagName('body')->item(0);
		if ($body === null) {
			return '';
		}
		// Vorordnung per Zeiger statt Knotenliste: Speicher O(Tiefe) statt O(Knoten) (B39)
		// $ctx: Bit 1 = in code/pre, Bit 2 = in svg, ab Bit 3 = Zahl unbekannter Vorfahren; $ctxStack: Kontext der Vorfahren
		$ctx = 0;
		$ctxStack = [];
		$unwrap = false;
		$moves = 0;
		$node = $body->firstChild;
		while ($node !== null) {
			// Auspacken verschiebt jeden Knoten einmal je unbekanntem Vorfahren (PHP gleicht dabei den Teilbaum ab)
			$moves += $ctx >> 2;
			if ($moves > self::MAX_UNWRAP_MOVES) {
				throw new TooLargeException('Content too complex to process (nested unknown elements)');
			}
			if (!$node instanceof DOMElement) {
				$node = self::skip($node, $body, $ctx, $ctxStack);
				continue;
			}
			$element = $node;
			$tag = strtolower($element->tagName);
			if (self::isEditorUi($element, $tag)) {
				$node = self::skip($element, $body, $ctx, $ctxStack);
				$element->parentNode->removeChild($element);
				continue;
			}
			// Gefährliche Tags in Codeblöcken werden zu Text
			if (($ctx & 1) && in_array($tag, ['script', 'iframe', 'object', 'embed', 'applet', 'form', 'style'], true)) {
				self::removeEditorUiBelow($element);
				$node = $dom->createTextNode(htmlspecialchars((string)$dom->saveHTML($element), ENT_QUOTES, 'UTF-8'));
				$element->parentNode->replaceChild($node, $element);
				continue;
			}
			if (!isset(self::allowedTags()[$tag])) {
				if (($ctx & 2) || in_array($tag, self::STRIPPED_TAGS, true)) {
					$node = self::skip($element, $body, $ctx, $ctxStack);
					$element->parentNode->removeChild($element);
					continue;
				}
				// Auspacken erst im zweiten Durchlauf (wie bisher nach allen Entfernungen); Kinder im selben Kontext
				$unwrap = true;
				if ($element->firstChild === null) {
					$node = self::skip($element, $body, $ctx, $ctxStack);
					continue;
				}
				$ctxStack[] = $ctx;
				$ctx += 4;
				$node = $element->firstChild;
				continue;
			}
			if ($element->hasAttributes() && !$this->cleanAttributes($element, $tag)) {
				$node = self::skip($element, $body, $ctx, $ctxStack);
				$element->parentNode->removeChild($element);
				continue;
			}
			if ($element->firstChild === null) {
				$node = self::skip($element, $body, $ctx, $ctxStack);
				continue;
			}
			// wie bisher: svg zählt nur unterhalb des nächsten code/pre
			$ctxStack[] = $ctx;
			$ctx = ($tag === 'code' || $tag === 'pre') ? ($ctx & ~3) | 1 : ($ctx | ($tag === 'svg' ? 2 : 0));
			$node = $element->firstChild;
		}

		if ($unwrap) {
			self::unwrapUnknown($dom, $body);
		}
		// Block-Hosts (Formel, Tabelle, ab M8c Rechnen) nach Vertrag (SPEC §4.7)
		BlockMarkup::normalize($body);
		$out = '';
		for ($child = $body->firstChild; $child !== null; $child = $child->nextSibling) {
			$out .= $dom->saveHTML($child);
		}
		$out = trim($out);
		return str_replace(['&lt;script', '&lt;/script'], '', $out);
	}

	// Unbekannte Elemente in Vorordnung auspacken (Block → div), leere entfernen
	private static function unwrapUnknown(DOMDocument $dom, DOMElement $body): void {
		$ctx = 0;
		$stack = [];
		$node = $body->firstChild;
		while ($node !== null) {
			if ($node instanceof DOMElement && !isset(self::allowedTags()[$tag = strtolower($node->tagName)])) {
				if ($node->firstChild === null) {
					$next = self::skip($node, $body, $ctx, $stack);
					$node->parentNode->removeChild($node);
					$node = $next;
					continue;
				}
				$block = in_array($tag, self::BLOCK_LEVEL_TAGS, true);
				$replacement = $block ? $dom->createElement('div') : $dom->createDocumentFragment();
				$first = $node->firstChild;
				while ($node->firstChild !== null) {
					$replacement->appendChild($node->firstChild);
				}
				$node->parentNode->replaceChild($replacement, $node);
				$node = $block ? $replacement : $first;
				continue;
			}
			if ($node->firstChild !== null) {
				$stack[] = 0;
				$node = $node->firstChild;
				continue;
			}
			$node = self::skip($node, $body, $ctx, $stack);
		}
	}

	// Nächster Knoten in Vorordnung ohne den Teilbaum von $node; steigt bis $root auf
	private static function skip(\DOMNode $node, \DOMNode $root, int &$ctx, array &$ctxStack): ?\DOMNode {
		while ($node->nextSibling === null) {
			$node = $node->parentNode;
			if ($node === null || $node->isSameNode($root)) {
				return null;
			}
			$ctx = array_pop($ctxStack);
		}
		return $node->nextSibling;
	}

	// Teilbaum, der gleich zu Text wird, ohne Editor-UI (wie früher der Vorab-Durchlauf)
	private static function removeEditorUiBelow(DOMElement $root): void {
		$stack = [$root];
		while ($stack !== []) {
			$element = array_pop($stack);
			for ($c = $element->firstChild; $c !== null; $c = $next) {
				$next = $c->nextSibling;
				if (!$c instanceof DOMElement) {
					continue;
				}
				if (self::isEditorUi($c, strtolower($c->tagName))) {
					$element->removeChild($c);
				} else {
					$stack[] = $c;
				}
			}
		}
	}

	// DANGEROUS_PATTERNS; stripos-Vorfilter spart den Regex für fast alle Werte
	private static function isDangerous(string $value): bool {
		if (stripos($value, 'script:') === false && stripos($value, 'data:') === false) {
			return false;
		}
		foreach (self::DANGEROUS_PATTERNS as $pattern) {
			if (Pcre::match($pattern, $value)) {
				return true;
			}
		}
		return false;
	}

	// Laufzeit-UI des Editors gehört nicht in gespeicherten Inhalt
	private static function isEditorUi(DOMElement $element, string $tag): bool {
		if (!$element->hasAttributes()) {
			return false;
		}
		$class = ' ' . strtr($element->getAttribute('class'), "\t\r\n", '   ') . ' ';
		return str_contains($class, ' tasklist-embed-widget ')
			|| ($tag === 'a' && (str_contains($class, ' heading-anchor ') || $element->getAttribute('data-heading-anchor') === 'true'));
	}

	/** @return array<string, int> */
	private static function allowedTags(): array {
		static $set = null;
		return $set ??= array_flip(self::ALLOWED_TAGS);
	}

	// false: Element ganz entfernen (iframe mit fremder Quelle)
	private function cleanAttributes(DOMElement $element, string $tag): bool {
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
			$probe = ($name === 'href' || $name === 'src') && !ctype_graph($value) ? Pcre::replace('/[\x00-\x20\x7F\p{Z}]+/u', '', $value) : $value;
			// data-excalidraw ist Diagramm-JSON, keine URL: Text wie „data:“ darin entfernte sonst das Diagramm (B35); Block-Attribute ebenso
			if ($name !== 'data-excalidraw' && !BlockMarkup::isBlockAttr($name) && (self::isDangerous($value) || ($probe !== $value && self::isDangerous($probe)))) {
				$remove[] = $attr->name;
				continue;
			}
			if ($name === 'href' || $name === 'src') {
				if ($tag === 'iframe' && $name === 'src') {
					if (!$this->iframeSrcIsTrusted($value)) {
						return false;
					}
					continue;
				}
				if ($name === 'src' && $tag === 'img' && str_starts_with($value, 'data:image/')) {
					continue;
				}
				if (!Pcre::match('/^(https?:\/\/|mailto:|\/|#|\.\/|\.\.\/)/i', $value)
					&& !str_starts_with($value, 'data:') && Pcre::match('/[<>"\']/', $value)) {
					$remove[] = $attr->name;
				}
			}
			if ($name === 'type' && $tag === 'ol' && !Pcre::match('/^[1aAiI]$/', $value)) {
				$remove[] = $attr->name;
			}
			if ($name === 'start' && $tag === 'ol' && !Pcre::match('/^-?\d{1,6}$/', $value)) {
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
		return true;
	}

	private static function listStartStyle(string $style, ?int $start): string {
		$style = Pcre::replace('/(^|;)\s*+counter-reset\s*+:[^;]*+/i', '$1', $style);
		$style = trim(Pcre::replace('/;(\s*+;)++/', ';', $style), "; \t\n\r");
		if ($start !== null && $start !== 1) {
			$style = ($style !== '' ? $style . '; ' : '') . 'counter-reset: item ' . ($start - 1);
		}
		return $style !== '' ? $style . ';' : '';
	}

	// Port sanitizeMarkdownContent; alle Muster possessiv/linear, Regex-Fehler wirft statt leer zu speichern (B37)
	public function sanitizeMarkdown(string $markdown): string {
		if ($markdown === '') {
			return $markdown;
		}
		$markdown = str_replace("\x00", '', $markdown);
		$segments = [];
		$markdown = self::maskCode($markdown, $segments);
		// ohne Grenze je Tag: Fließtext nach „a<b“ liest der Browser als ein Tag mit vielen Attributen
		self::assertAttributeBudget($markdown, PHP_INT_MAX);
		// Inhalt bis zum Schluss-Tag, nicht über eine Leerzeile hinweg (wie Upstream)
		$body = static fn (string $close): string => '(?:[^\n<]++|\n(?![ \t]*+\n)|<(?!\/' . $close . '\s*+>))*+<\/' . $close . '\s*+>';
		$markdown = Pcre::replace('/<script\b[^<>]*+>' . $body('script') . '/i', '', $markdown);
		$markdown = Pcre::replace('/<style\b[^<>]*+>' . $body('style') . '/i', '', $markdown);
		$markdown = Pcre::replace('/<(object|embed|applet)\b[^<>]*+>' . $body('\1') . '/i', '', $markdown);
		$markdown = Pcre::replace('/<(object|embed|applet)\b[^<>]*+>/i', '', $markdown);
		$markdown = Pcre::replace('/<form\b[^<>]*+>' . $body('form') . '/i', '', $markdown);
		$markdown = self::stripEventHandlers($markdown);
		$markdown = Pcre::replace('/(href|src)\s*+=\s*+(["\'])\s*+javascript:/i', '$1=$2', $markdown);
		$markdown = Pcre::replace('/(href|src)\s*+=\s*+(["\'])\s*+vbscript:/i', '$1=$2', $markdown);
		return self::restoreCode($markdown, $segments);
	}

	// Attribute je Start-Tag nach Tokenizer-Regeln zählen (wie Pass A), Tags ohne Attribut überspringt der Regex
	private static function assertAttributeBudget(string $s, int $perTag): void {
		$total = 0;
		$pos = 0;
		while (Pcre::match('/<[a-zA-Z][^\s\/>]*+(?=[\s\/]++[^\s\/>])/', $s, $m, PREG_OFFSET_CAPTURE, $pos)) {
			$at = $m[0][1] + strlen($m[0][0]);
			$count = 0;
			while (Pcre::match(self::MD_ATTR_AT, $s, $a, 0, $at)) {
				$at += strlen($a[0]);
				if (++$count > $perTag || ++$total > self::MAX_ATTRS) {
					throw new TooLargeException('Content has too many attributes (max ' . min($perTag, self::MAX_ATTRS) . ' per tag, ' . self::MAX_ATTRS . ' total)');
				}
			}
			$pos = $at;
		}
	}

	// on*-Attribute in Roh-HTML entfernen (Upstream: nur eines je Tag, Werte mit „>“ verdeckten weitere, B37)
	private static function stripEventHandlers(string $markdown): string {
		// Attributnamen beginnen nach Leerraum, „/“ oder Anführungszeichen: ohne solches „on“ nichts zu tun
		if (!Pcre::match('/[\s\/"\']on/i', $markdown)) {
			return $markdown;
		}
		// Markup- und Text-Sicht bis zum Fixpunkt: Entfernen in einer Sicht kann die Anführungszeichen der anderen verschieben
		for ($round = 0; $round < self::MD_MAX_ROUNDS; $round++) {
			$inMarkup = $inText = $inForeign = [];
			$next = self::scanHandlers(self::scanHandlers($markdown, self::VIEW_MARKUP, $inMarkup), self::VIEW_TEXT, $inText);
			// CDATA-Abschnitte gibt es nur in SVG/MathML: dritte Sicht nur, wenn einer vorkommt
			$foreign = stripos($next, '<![CDATA[') !== false;
			if ($foreign) {
				$next = self::scanHandlers($next, self::VIEW_FOREIGN, $inForeign);
			}
			if ($next === $markdown) {
				$closed = array_intersect_key($inMarkup, $inText);
				return self::neutralizeHandlers($markdown, $foreign ? array_intersect_key($closed, $inForeign) : $closed);
			}
			$markdown = $next;
		}
		throw new TooLargeException('Content too complex to process (event handlers)');
	}

	/**
	 * Tags wie der Browser zerlegen; Text-Sicht: Kommentare und RAWTEXT/RCDATA-Inhalt sind Text, Endtags werden mitgelesen;
	 * Fremd-Sicht (SVG/MathML): Kommentare und CDATA-Abschnitte sind Text, kein RAWTEXT
	 * @param array<int, true> $closed Start-Tags mit „on“ in den Attributen, die bis „>“ gelesen wurden (Position von „<“)
	 */
	private static function scanHandlers(string $s, int $view, array &$closed): string {
		$textView = $view !== self::VIEW_MARKUP;
		$out = '';
		$copied = 0;
		$pos = 0;
		$len = strlen($s);
		while (($lt = strpos($s, '<', $pos)) !== false) {
			$skip = $textView && strspn($s, '!?/', $lt + 1, 1) === 1 ? self::skipNonMarkup($s, $lt, $view === self::VIEW_FOREIGN) : null;
			if ($skip !== null) {
				$pos = $skip;
				continue;
			}
			// ganzer Tag samt Attributen in einem Zug; Einzelzerlegung nur, wenn „on“ darin vorkommt
			$full = preg_match(self::MD_TAG_FULL_AT, $s, $m, 0, $lt);
			if ($full === false && Pcre::match(self::MD_TAG_AT, $s, $m, 0, $lt)) {
				// PCRE-Grenze bei sehr vielen Attributen: einzeln weiterlesen
				$m[3] = substr($s, $lt + strlen($m[0]), self::attributesEnd($s, $lt + strlen($m[0])) - $lt - strlen($m[0]));
				$m[0] .= $m[3];
			} elseif ($full !== 1) {
				$m = null;
			}
			if ($m === null || ($m[1] !== '' && !$textView)) {
				$pos = $lt + 1;
				continue;
			}
			$end = $lt + strlen($m[0]);
			$at = $end - strlen($m[3]);
			$on = stripos($m[3], 'on');
			if ($on !== false) {
				$out .= substr($s, $copied, $at - $copied);
				while ($at < $end && Pcre::match(self::MD_ATTR_AT, $s, $a, 0, $at)) {
					if (strlen($a[2]) <= 2 || strncasecmp($a[2], 'on', 2) !== 0) {
						$out .= $a[0];
					} elseif (self::unterminated($a[3] ?? '')) {
						// offener Wert liefe bis Dokumentende: Namen entschärfen statt den Rest zu löschen
						$out .= $a[1] . 'x' . substr($a[0], strlen($a[1]));
					}
					$at += strlen($a[0]);
				}
				$copied = $at;
				// nur Tags merken, in denen das Netz „on…=“ fände
				if ($m[1] === '' && strpos($m[3], '=', $on) !== false && ($s[$end + strspn($s, " \t\n\r\f\v/", $end)] ?? '') === '>') {
					$closed[$lt] = true;
				}
			}
			$pos = $end;
			$name = strtolower($m[2]);
			if ($view === self::VIEW_TEXT && $m[1] === '' && in_array($name, self::MD_RAWTEXT_TAGS, true)) {
				$pos = $len;
				if ($name !== 'plaintext' && Pcre::match('/<\/' . $name . '(?:[\s\/>]|$)/i', $s, $e, PREG_OFFSET_CAPTURE, $end)) {
					$pos = $e[0][1];
				}
			}
		}
		return $copied === 0 ? $s : $out . substr($s, $copied);
	}

	private static function attributesEnd(string $s, int $at): int {
		while (Pcre::match(self::MD_ATTR_AT, $s, $a, 0, $at)) {
			$at += strlen($a[0]);
		}
		return $at;
	}

	// Ende von Kommentar, Bogus-Kommentar bzw. CDATA-Abschnitt ($cdata) ab $lt nach HTML-Tokenizer, sonst null
	private static function skipNonMarkup(string $s, int $lt, bool $cdata): ?int {
		if ($cdata && substr($s, $lt, 9) === '<![CDATA[') {
			$close = strpos($s, ']]>', $lt + 9);
			return $close === false ? strlen($s) : $close + 3;
		}
		if (substr($s, $lt, 4) === '<!--') {
			$from = $lt + 4;
			if (($s[$from] ?? '') === '>') {
				return $from + 1;
			}
			if (substr($s, $from, 2) === '->') {
				return $from + 2;
			}
			return Pcre::match('/--!?+>/', $s, $e, PREG_OFFSET_CAPTURE, $from) ? $e[0][1] + strlen($e[0][0]) : strlen($s);
		}
		$next = $s[$lt + 1] ?? '';
		if ($next === '!' || $next === '?' || ($next === '/' && !ctype_alpha($s[$lt + 2] ?? '') && $lt + 2 < strlen($s))) {
			$gt = strpos($s, '>', $lt + 1);
			return $gt === false ? strlen($s) : $gt + 1;
		}
		return null;
	}

	private static function unterminated(string $value): bool {
		$q = $value[0] ?? '';
		return ($q === '"' || $q === "'") && (strlen($value) === 1 || $value[strlen($value) - 1] !== $q);
	}

	/**
	 * Rest-Netz wie Upstream-Muster: in jeder Region „<x…“ bis < oder > wird „on…=“ zu „xon…=“ (ändert keine Tokenisierung);
	 * ausgenommen das Innere geschlossener Anführungszeichen-Werte eines Tags, den alle Sichten vollständig gelesen haben
	 * @param array<int, true> $closed
	 */
	private static function neutralizeHandlers(string $s, array $closed): string {
		if ($closed === []) {
			// nichts zu schonen: Regionen direkt (schneller bei vielen Treffern)
			return Pcre::replaceCallback('/<[a-zA-Z][^<>]*+/', static fn ($m) => stripos($m[0], 'on') === false
				? $m[0] : Pcre::replace('/(?<=[\s\/])(?=on\w++\s*+=)/i', 'x', $m[0]), $s);
		}
		$out = '';
		$copied = 0;
		$pos = $from = 0;
		$region = -1;
		$quoted = [];
		$q = 0;
		while (Pcre::match('/(?<=[\s\/])on\w++\s*+=/i', $s, $m, PREG_OFFSET_CAPTURE, $pos)) {
			[$hit, $at] = $m[0];
			// Region per letzter Grenze „<“/„>“ in der Lücke seit dem vorigen Treffer (linear)
			$gap = 'x' . substr($s, $from, $at - $from);
			$edge = max((int)strrpos($gap, '<'), (int)strrpos($gap, '>')) - 1;
			$from = $at;
			if ($edge >= 0) {
				$lt = $at - strlen($gap) + 1 + $edge;
				$region = $s[$lt] === '<' && ctype_alpha($s[$lt + 1]) ? $lt : -1;
				$quoted = $region >= 0 && isset($closed[$region]) ? self::quotedInside($s, $region) : [];
				$q = 0;
			}
			if ($region < 0) {
				// außerhalb einer Region bis zum nächsten „<“ nichts zu tun
				$pos = strpos($s, '<', $at);
				if ($pos === false) {
					break;
				}
				continue;
			}
			while (isset($quoted[$q]) && $quoted[$q][1] <= $at) {
				$q++;
			}
			if (!isset($quoted[$q]) || $quoted[$q][0] > $at) {
				$out .= substr($s, $copied, $at - $copied) . 'x';
				$copied = $at;
			}
			$pos = $at + strlen($hit);
		}
		return $copied === 0 ? $s : $out . substr($s, $copied);
	}

	/** @return list<array{int, int}> Innenbereiche [von, bis) geschlossener Anführungszeichen-Werte des Tags ab $lt */
	private static function quotedInside(string $s, int $lt): array {
		$quoted = [];
		Pcre::match(self::MD_TAG_AT, $s, $m, 0, $lt);
		$at = $lt + strlen($m[0]);
		while (Pcre::match(self::MD_ATTR_AT, $s, $a, 0, $at)) {
			$value = $a[3] ?? '';
			if ($value !== '' && ($value[0] === '"' || $value[0] === "'") && !self::unterminated($value)) {
				$start = $at + strlen($a[0]) - strlen($value) + 1;
				$quoted[] = [$start, $start + strlen($value) - 2];
			}
			$at += strlen($a[0]);
		}
		return $quoted;
	}

	/** @param array<int, string> $segments */
	public static function maskCode(string $markdown, array &$segments): string {
		$segments = [];
		$store = static function (string $code) use (&$segments): string {
			$i = count($segments);
			$segments[$i] = $code;
			return "\x00MDCODE" . $i . "\x00";
		};
		// Inhalt ohne Backtick: possessiv gleichwertig, linear auch bei MB-langen Zeilen
		$maskInline = static fn (string $line): string => Pcre::replaceCallback('/(?<!\\\\)`([^`\n]++)(?<!\\\\)`/', static fn ($m) => $store($m[0]), $line);
		$output = [];
		$pending = [];
		$inFence = false;
		foreach (explode("\n", $markdown) as $line) {
			if (!$inFence) {
				if (Pcre::match('/^[ \t]*+```/', $line)) {
					$inFence = true;
					$pending = [$line];
				} else {
					$output[] = $maskInline($line);
				}
				continue;
			}
			$pending[] = $line;
			if (Pcre::match('/^[ \t]*+```[ \t\r]*+$/', $line)) {
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
		return Pcre::replaceCallback('/\x00MDCODE(\d++)\x00/', static fn ($m) => $segments[(int)$m[1]] ?? '', $markdown);
	}
}
