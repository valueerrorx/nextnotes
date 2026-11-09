<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\OneNote\Convert;

use DOMDocument;
use DOMElement;
use DOMNode;
use OCA\NextNotes\OneNote\GraphClient;
use OCA\NextNotes\Service\Pcre;
use OCP\IL10N;

// Ein Durchlauf Graph-Seiten-DOM → neues DOM nach Positivliste (Plan M9 §5); Zustand je Seite, nur über PageConverter
final class PageWalker {
	// samt Inhalt entfernen
	private const DROP = ['script', 'style', 'template', 'noscript', 'head', 'title', 'frame', 'frameset', 'noframes',
		'select', 'option', 'optgroup', 'textarea', 'svg', 'button'];
	// nur das Element entfernen: libxml 2.9 schließt leere Elemente wie embed nicht, Folgeinhalt landet darin
	private const UNWRAP = ['meta', 'link', 'base', 'embed', 'param', 'source', 'track', 'area', 'input', 'applet', 'form',
		'audio', 'video', 'picture', 'map', 'dialog', 'datalist', 'canvas'];
	private const INK = ['ink', 'inkml', 'trace', 'tracegroup', 'traceview'];
	// Zeichenformat → eigenes Element (einheitlich mit dem Editor und der Markdown-Umwandlung)
	private const INLINE = ['b' => 'strong', 'strong' => 'strong', 'i' => 'em', 'em' => 'em', 'u' => 'u', 's' => 's',
		'strike' => 's', 'del' => 's', 'sup' => 'sup', 'sub' => 'sub', 'cite' => 'cite', 'code' => 'code', 'mark' => 'mark', 'small' => 'small'];
	private const HEADINGS = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'];
	private const CELLS = ['table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'caption'];
	// Kontrollkästchen-Formen der OneNote-Notizkennzeichen (note-tags, „check boxes“)
	private const CHECKBOX_TAGS = ['to-do', 'to-do-priority-1', 'to-do-priority-2', 'discuss-with-person-a', 'discuss-with-person-b',
		'discuss-with-manager', 'schedule-meeting', 'call-back', 'client-request'];
	private const TAG_PREFIX = ['important' => '⭐', 'question' => '❓', 'critical' => '❗', 'idea' => '💡'];
	private const ABSOLUTE = ['div', 'img', 'object'];
	// Standardlage ohne Angabe laut Learn (onenote-abs-pos)
	private const DEFAULT_TOP = 120.0;
	private const DEFAULT_LEFT = 48.0;
	// Dateiname: Byte-Grenze wie AttachmentService::cleanName
	private const NAME_BYTES = 255;

	private DOMDocument $out;
	/** @var list<ConvertResource> */
	private array $resources = [];
	/** @var array<string, int> Schlüssel Art + ID → Index */
	private array $known = [];
	private int $skipped = 0;
	private int $ink = 0;

	public function __construct(
		private IL10N $l,
	) {
		$this->out = new DOMDocument('1.0', 'UTF-8');
	}

	/** @return array{DOMElement, list<ConvertResource>, int, int} Wurzel, Ressourcen, übersprungen, Freihand */
	public function walk(DOMElement $body): array {
		$root = $this->out->createElement('div');
		$blocks = [];
		$seq = 0;
		for ($c = $body->firstChild; $c !== null; $c = $c->nextSibling) {
			[$top, $left] = $c instanceof DOMElement ? self::position($c) : [self::DEFAULT_TOP, self::DEFAULT_LEFT];
			$blocks[] = [$top, $left, $seq++, $c];
		}
		// oben nach unten, dann links nach rechts, sonst Quellreihenfolge
		usort($blocks, static fn (array $a, array $b): int => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);
		$run = [];
		foreach ($blocks as [, , , $node]) {
			// positionierte Container entfallen, ihr Inhalt folgt nacheinander
			if ($node instanceof DOMElement && MathMl::name($node) === 'div') {
				$this->flowNodes($run, $root);
				$run = [];
				$this->children($node, $root);
			} else {
				$run[] = $node;
			}
		}
		$this->flowNodes($run, $root);
		return [$root, $this->resources, $this->skipped, $this->ink];
	}

	/** @return array{float, float} */
	private static function position(DOMElement $el): array {
		$top = self::DEFAULT_TOP;
		$left = self::DEFAULT_LEFT;
		if (in_array(MathMl::name($el), self::ABSOLUTE, true)) {
			$style = strtolower($el->getAttribute('style'));
			if (Pcre::match('/(?:^|;)\s*+top\s*+:\s*+(-?\d{1,7}(?:\.\d{1,4})?)px/', $style, $m)) {
				$top = (float)$m[1];
			}
			if (Pcre::match('/(?:^|;)\s*+left\s*+:\s*+(-?\d{1,7}(?:\.\d{1,4})?)px/', $style, $m)) {
				$left = (float)$m[1];
			}
		}
		return [$top, $left];
	}

	// Kinder eines Fluss-Containers (body, div, td, li, blockquote); To-dos in Folge → eine Checkliste
	private function children(DOMNode $in, DOMElement $parent): void {
		$nodes = [];
		for ($c = $in->firstChild; $c !== null; $c = $c->nextSibling) {
			$nodes[] = $c;
		}
		$this->flowNodes($nodes, $parent);
	}

	/** @param list<DOMNode> $nodes */
	private function flowNodes(array $nodes, DOMElement $parent): void {
		foreach ($nodes as $node) {
			if ($node->nodeType === XML_TEXT_NODE && trim($node->textContent) === '') {
				continue;
			}
			if ($node instanceof DOMElement && ($item = $this->todoItem($node)) !== null) {
				$this->checklist($parent)->appendChild($item);
				continue;
			}
			$this->node($node, $parent, true);
		}
	}

	// Inhalt in einen Inline-Container (p, h*, span, a …)
	private function inline(DOMNode $in, DOMElement $parent): void {
		for ($c = $in->firstChild; $c !== null; $c = $c->nextSibling) {
			$this->node($c, $parent, false);
		}
	}

	private function node(DOMNode $node, DOMElement $parent, bool $flow): void {
		if ($node->nodeType === XML_TEXT_NODE || $node->nodeType === XML_CDATA_SECTION_NODE) {
			$parent->appendChild($this->out->createTextNode($node->textContent));
			return;
		}
		if (!$node instanceof DOMElement) {
			return;
		}
		$name = MathMl::name($node);
		if (in_array($name, self::INK, true) || self::isInkResource($node)) {
			$this->inkHint($parent, $flow);
			return;
		}
		if (in_array($name, self::DROP, true)) {
			return;
		}
		if (in_array($name, self::UNWRAP, true)) {
			$flow ? $this->children($node, $parent) : $this->inline($node, $parent);
			return;
		}
		switch (true) {
			case $name === 'p' || in_array($name, self::HEADINGS, true):
				$this->block($node, $name, $parent, $flow);
				return;
			case $name === 'ul' || $name === 'ol':
				$this->lists($node, $name, $parent);
				return;
			case $name === 'li':
				// li ohne Liste wie Absatz
				$this->block($node, 'p', $parent, $flow);
				return;
			case in_array($name, self::CELLS, true):
				$this->cell($node, $name, $parent);
				return;
			case $name === 'blockquote' || $name === 'pre':
				$el = $this->el($name);
				$parent->appendChild($el);
				$name === 'pre' ? $this->inline($node, $el) : $this->children($node, $el);
				return;
			case $name === 'br' || $name === 'hr':
				$parent->appendChild($this->el($name));
				return;
			case $name === 'a':
				$this->link($node, $parent);
				return;
			case $name === 'span':
				$this->span($node, $parent);
				return;
			case isset(self::INLINE[$name]):
				$el = $this->el(self::INLINE[$name]);
				$parent->appendChild($el);
				$this->inline($node, $el);
				return;
			case $name === 'img':
				$this->image($node, $parent, $flow);
				return;
			case $name === 'object' || $name === 'iframe':
				$name === 'object' ? $this->file($node, $parent, $flow) : $this->video($node, $parent, $flow);
				// „<object … />“ ist in HTML kein leeres Element: Folgeinhalt kann darin landen
				$flow ? $this->children($node, $parent) : $this->inline($node, $parent);
				return;
			case $name === 'math':
				$this->math($node, $parent, $flow);
				return;
			default:
				// div und Unbekanntes: auspacken
				$flow ? $this->children($node, $parent) : $this->inline($node, $parent);
		}
	}

	private function block(DOMElement $node, string $name, DOMElement $parent, bool $flow): void {
		$only = self::firstElement($node);
		if ($flow && $only !== null && MathMl::name($only) === 'math' && MathMl::isBlock($only) && trim($node->textContent) === trim($only->textContent)) {
			// Absatz nur mit abgesetzter Formel → Formelblock
			$this->math($only, $parent, true);
			return;
		}
		$heading = in_array($name, self::HEADINGS, true);
		$style = StyleFilter::parse($node->getAttribute('style'), $heading);
		unset($style['list-style-type']);
		$el = $this->el($flow ? $name : 'span', ['style' => StyleFilter::render($style)]);
		$parent->appendChild($el);
		$this->prefix($el, self::tags($node->getAttribute('data-tag')));
		$this->inline($node, $el);
	}

	private function lists(DOMElement $node, string $name, DOMElement $parent): void {
		$listTags = self::tags($node->getAttribute('data-tag'));
		$current = null;
		$count = 0;
		$checklist = null;
		for ($c = $node->firstChild; $c !== null; $c = $c->nextSibling) {
			if (!$c instanceof DOMElement) {
				if (trim($c->textContent) !== '' && $current !== null && $current->lastChild instanceof DOMElement) {
					$current->lastChild->appendChild($this->out->createTextNode($c->textContent));
				}
				continue;
			}
			if (MathMl::name($c) !== 'li') {
				// verschachtelte Liste direkt in der Liste: an den vorigen Eintrag hängen
				$target = $current?->lastChild instanceof DOMElement ? $current->lastChild : ($checklist?->lastChild instanceof DOMElement ? $checklist->lastChild : null);
				if ($target === null) {
					$current = $this->el($name);
					$parent->appendChild($current);
					$target = $this->el('li');
					$current->appendChild($target);
				}
				$this->node($c, $target, true);
				continue;
			}
			$item = $this->todoItem($c, $listTags);
			if ($item !== null) {
				$checklist = $this->checklist($parent);
				$checklist->appendChild($item);
				$count++;
				continue;
			}
			if ($current === null || !$current->isSameNode($parent->lastChild)) {
				// geteilte nummerierte Liste zählt weiter
				$current = $this->el($name, $name === 'ol' && $count > 0 ? ['start' => (string)($count + 1)] : []);
				$parent->appendChild($current);
			}
			$style = array_intersect_key(StyleFilter::parse($c->getAttribute('style')), ['list-style-type' => 1, 'text-align' => 1, 'color' => 1, 'background-color' => 1]);
			$li = $this->el('li', ['style' => StyleFilter::render($style)]);
			$current->appendChild($li);
			$this->prefix($li, $listTags !== [] ? $listTags : self::tags($c->getAttribute('data-tag')));
			$this->listItem($c, $li);
			$count++;
		}
	}

	// Inhalt eines Listeneintrags: Text inline, Unterlisten und Tabellen als Block
	private function listItem(DOMElement $in, DOMElement $li): void {
		for ($c = $in->firstChild; $c !== null; $c = $c->nextSibling) {
			$block = $c instanceof DOMElement && in_array(MathMl::name($c), ['ul', 'ol', 'table', 'p', 'div', 'blockquote', 'pre'], true);
			$this->node($c, $li, $block);
		}
	}

	private function cell(DOMElement $node, string $name, DOMElement $parent): void {
		$attrs = [];
		if ($name === 'td' || $name === 'th') {
			foreach (['colspan', 'rowspan'] as $span) {
				$v = trim($node->getAttribute($span));
				if ($v !== '' && ctype_digit($v) && (int)$v > 1 && (int)$v <= 1000) {
					$attrs[$span] = (string)(int)$v;
				}
			}
			$style = array_intersect_key(StyleFilter::parse($node->getAttribute('style')), ['text-align' => 1, 'background-color' => 1, 'color' => 1, 'font-weight' => 1, 'font-style' => 1]);
			$attrs['style'] = StyleFilter::render($style);
		}
		$el = $this->el($name, $attrs);
		$parent->appendChild($el);
		if ($name === 'td' || $name === 'th' || $name === 'caption') {
			$this->children($node, $el);
			return;
		}
		// Struktur: nur Elemente, Leerraum entfällt
		for ($c = $node->firstChild; $c !== null; $c = $c->nextSibling) {
			if ($c instanceof DOMElement) {
				$this->node($c, $el, true);
			}
		}
	}

	private function span(DOMElement $node, DOMElement $parent): void {
		$style = StyleFilter::parse($node->getAttribute('style'));
		$wrap = [];
		if (in_array($style['font-weight'] ?? '', ['bold', 'bolder', '600', '700', '800', '900'], true)) {
			$wrap[] = 'strong';
		}
		if (in_array($style['font-style'] ?? '', ['italic', 'oblique'], true)) {
			$wrap[] = 'em';
		}
		$deco = explode(' ', $style['text-decoration'] ?? '');
		if (in_array('underline', $deco, true)) {
			$wrap[] = 'u';
		}
		if (in_array('line-through', $deco, true)) {
			$wrap[] = 's';
		}
		$rest = array_intersect_key($style, ['color' => 1, 'background-color' => 1]);
		$target = $parent;
		$tags = self::tags($node->getAttribute('data-tag'));
		if ($rest !== []) {
			$target = $this->el('span', ['style' => StyleFilter::render($rest)]);
			$parent->appendChild($target);
		}
		foreach ($wrap as $tag) {
			$el = $this->el($tag);
			$target->appendChild($el);
			$target = $el;
		}
		$this->prefix($target, $tags);
		$this->inline($node, $target);
	}

	private function link(DOMElement $node, DOMElement $parent): void {
		$href = trim($node->getAttribute('href'));
		$scheme = strtolower((string)parse_url($href, PHP_URL_SCHEME));
		// nur druckbares ASCII ohne Anführungszeichen; onenote:, javascript: und Rest → nur Text
		if ($href !== '' && strlen($href) <= 4096 && in_array($scheme, ['http', 'https', 'mailto'], true)
			&& Pcre::match('/^[\x21-\x7E]++$/', $href) && strpbrk($href, '"\'<>\\`') === false) {
			$a = $this->el('a', ['href' => $href]);
			$parent->appendChild($a);
			$this->inline($node, $a);
			return;
		}
		$this->inline($node, $parent);
	}

	private function image(DOMElement $node, DOMElement $parent, bool $flow): void {
		$url = $node->getAttribute('data-fullres-src') !== '' ? $node->getAttribute('data-fullres-src') : $node->getAttribute('src');
		$type = $node->getAttribute('data-fullres-src') !== '' ? $node->getAttribute('data-fullres-src-type') : $node->getAttribute('data-src-type');
		$index = $this->resource($url, ConvertResource::KIND_IMAGE, '', $type);
		$target = $flow ? $this->para($parent) : $parent;
		if ($index === null) {
			$target->appendChild($this->out->createTextNode($this->l->t('[Image not imported]')));
			return;
		}
		$attrs = ['src' => PageConverter::PLACEHOLDER . $index];
		$alt = self::plain($node->getAttribute('alt'), 1000);
		if ($alt !== '') {
			$attrs['alt'] = $alt;
		}
		foreach (['width', 'height'] as $dim) {
			$v = trim($node->getAttribute($dim));
			if ($v !== '' && ctype_digit($v) && strlen($v) <= 5 && (int)$v > 0) {
				$attrs[$dim] = (string)(int)$v;
			}
		}
		$target->appendChild($this->el('img', $attrs));
	}

	private function file(DOMElement $node, DOMElement $parent, bool $flow): void {
		$name = self::fileName($node->getAttribute('data-attachment'));
		if ($name === '') {
			$name = $this->l->t('Attachment');
		}
		$index = $this->resource($node->getAttribute('data'), ConvertResource::KIND_FILE, $name, $node->getAttribute('type'));
		$target = $flow ? $this->para($parent) : $parent;
		if ($index === null) {
			$target->appendChild($this->out->createTextNode($this->l->t('[File not imported: %s]', [$name])));
			return;
		}
		$a = $this->el('a', ['href' => PageConverter::PLACEHOLDER . $index]);
		$a->appendChild($this->out->createTextNode($name));
		$target->appendChild($a);
	}

	private function video(DOMElement $node, DOMElement $parent, bool $flow): void {
		$src = trim($node->getAttribute('data-original-src'));
		$target = $flow ? $this->para($parent) : $parent;
		$label = $this->l->t('Video: %s', [self::plain($src, 2048)]);
		if (str_starts_with(strtolower($src), 'https://') && strlen($src) <= 2048 && Pcre::match('/^[\x21-\x7E]++$/', $src) && strpbrk($src, '"\'<>\\`') === false) {
			$a = $this->el('a', ['href' => $src]);
			$a->appendChild($this->out->createTextNode($label));
			$target->appendChild($a);
			return;
		}
		if ($src !== '') {
			$target->appendChild($this->out->createTextNode($label));
		}
	}

	private function math(DOMElement $node, DOMElement $parent, bool $flow): void {
		$tex = MathMl::toLatex($node);
		if ($tex === null) {
			// nicht umwandelbar: lesbarer Text
			$text = trim(Pcre::replace('/\s++/u', ' ', $node->textContent));
			if ($text !== '') {
				($flow ? $this->para($parent) : $parent)->appendChild($this->out->createTextNode($text));
			}
			return;
		}
		// Block nur dort, wo ein div stehen darf; sonst inline
		$block = MathMl::isBlock($node) && $flow;
		$el = $this->el($block ? 'div' : 'span', ['class' => $block ? 'math-block' : 'math-inline', 'data-math' => $tex, 'contenteditable' => 'false']);
		$el->appendChild($this->out->createTextNode($tex));
		($flow && !$block ? $this->para($parent) : $parent)->appendChild($el);
	}

	private function inkHint(DOMElement $parent, bool $flow): void {
		$this->ink++;
		$text = $this->out->createTextNode($this->l->t('[Ink drawing not imported]'));
		($flow ? $this->para($parent) : $parent)->appendChild($text);
	}

	private static function isInkResource(DOMElement $node): bool {
		foreach (['type', 'data-src-type', 'data-fullres-src-type'] as $attr) {
			if (str_contains(strtolower($node->getAttribute($attr)), 'inkml')) {
				return true;
			}
		}
		return false;
	}

	/**
	 * To-do-Kennzeichen → Checklisteneintrag nach Upstream-Markup (checklist.js); null = kein To-do
	 * @param list<array{string, bool}> $inherited Kennzeichen der Liste
	 */
	private function todoItem(DOMElement $node, array $inherited = []): ?DOMElement {
		$name = MathMl::name($node);
		if ($name !== 'p' && $name !== 'li' && !in_array($name, self::HEADINGS, true)) {
			return null;
		}
		$tags = $inherited !== [] ? $inherited : self::tags($node->getAttribute('data-tag'));
		$carrier = $node;
		if ($tags === [] && $name === 'li') {
			// Ausgabe-HTML: Kennzeichen am ersten span des Eintrags
			$first = self::firstElement($node);
			if ($first !== null && MathMl::name($first) === 'span' && $first->hasAttribute('data-tag')) {
				$tags = self::tags($first->getAttribute('data-tag'));
				$carrier = $first;
			}
		}
		$box = null;
		foreach ($tags as [$shape, $done]) {
			if (in_array($shape, self::CHECKBOX_TAGS, true)) {
				$box = ($box ?? false) || $done;
			}
		}
		if ($box === null) {
			return null;
		}
		$carrier->removeAttribute('data-tag');
		$li = $this->el('li', ['class' => 'checklist-item' . ($box ? ' checklist-item-checked' : '')]);
		$input = $this->el('input', ['type' => 'checkbox', 'class' => 'checklist-checkbox'] + ($box ? ['checked' => 'checked'] : []));
		$li->appendChild($input);
		$li->appendChild($this->out->createTextNode(' '));
		$text = $this->el('span', ['class' => 'checklist-text']);
		$li->appendChild($text);
		$this->prefix($text, $tags);
		$nested = [];
		for ($c = $node->firstChild; $c !== null; $c = $c->nextSibling) {
			if ($c instanceof DOMElement && in_array(MathMl::name($c), ['ul', 'ol'], true)) {
				$nested[] = $c;
				continue;
			}
			$this->node($c, $text, false);
		}
		foreach ($nested as $list) {
			$this->node($list, $li, true);
		}
		return $li;
	}

	// Angrenzende To-dos teilen eine Checkliste, auch über Absatz- und Listengrenzen
	private function checklist(DOMElement $parent): DOMElement {
		$last = $parent->lastChild;
		if ($last instanceof DOMElement && $last->tagName === 'ul' && $last->getAttribute('class') === 'checklist') {
			return $last;
		}
		$ul = $this->el('ul', ['class' => 'checklist']);
		$parent->appendChild($ul);
		return $ul;
	}

	/** @param list<array{string, bool}> $tags */
	private function prefix(DOMElement $el, array $tags): void {
		$marks = [];
		foreach ($tags as [$shape]) {
			if (isset(self::TAG_PREFIX[$shape])) {
				$marks[self::TAG_PREFIX[$shape]] = true;
			}
		}
		if ($marks !== []) {
			$el->appendChild($this->out->createTextNode(implode('', array_keys($marks)) . ' '));
		}
	}

	/** @return list<array{string, bool}> Form und erledigt, z. B. „project-a, to-do:completed“ */
	private static function tags(string $value): array {
		$out = [];
		if ($value === '' || strlen($value) > 512) {
			return $out;
		}
		foreach (explode(',', strtolower($value)) as $part) {
			$part = trim($part);
			if ($part === '') {
				continue;
			}
			[$shape, $status] = array_pad(explode(':', $part, 2), 2, '');
			$out[] = [trim($shape), trim($status) === 'completed'];
		}
		return $out;
	}

	// Ressource anlegen oder wiederverwenden; null = Platzhalter (fremde/ungültige URL, Grenze je Seite)
	private function resource(string $url, string $kind, string $name, string $type): ?int {
		// eine Regel für Ressourcen-URLs: nur die geprüfte ID zählt, die URL baut der Abruf neu (Plan M9 §6)
		$id = GraphClient::resourceIdFromUrl(trim($url));
		if ($id === null) {
			$this->skipped++;
			return null;
		}
		$key = $kind . ':' . $id;
		if (isset($this->known[$key])) {
			return $this->known[$key];
		}
		if (count($this->resources) >= PageConverter::MAX_RESOURCES) {
			$this->skipped++;
			return null;
		}
		$index = count($this->resources);
		$type = strtolower(trim($type));
		$this->resources[] = new ConvertResource($index, $id, $kind, $name, Pcre::match('#^[a-z0-9.+-]{1,64}/[a-z0-9.+-]{1,128}$#', $type) ? $type : '');
		$this->known[$key] = $index;
		return $index;
	}

	private static function fileName(string $name): string {
		$name = self::plain($name, 1000);
		$name = trim(strtr($name, ['/' => '_', '\\' => '_']), " .\t");
		if (strlen($name) <= self::NAME_BYTES) {
			return $name;
		}
		// auf 255 Bytes (Grenze der Upload-Regeln) an Zeichengrenzen kürzen, kurze Endung bleibt
		$dot = strrpos($name, '.');
		$ext = $dot !== false && $dot > 0 && strlen($name) - $dot <= 16 ? substr($name, $dot) : '';
		return rtrim(mb_strcut($name, 0, self::NAME_BYTES - strlen($ext), 'UTF-8'), " .\t") . $ext;
	}

	// Steuerzeichen raus, Länge begrenzt
	private static function plain(string $text, int $max): string {
		$text = trim(Pcre::replace('/[\x00-\x1F\x7F]++/u', ' ', $text));
		return mb_substr($text, 0, $max);
	}

	private static function firstElement(DOMElement $node): ?DOMElement {
		for ($c = $node->firstChild; $c !== null; $c = $c->nextSibling) {
			if ($c instanceof DOMElement) {
				return $c;
			}
			if (trim($c->textContent) !== '') {
				return null;
			}
		}
		return null;
	}

	// Inline-Inhalt (Bild, Datei, Video, Formel, Hinweis) im Fluss: eigener Absatz
	private function para(DOMElement $parent): DOMElement {
		$p = $this->el('p');
		$parent->appendChild($p);
		return $p;
	}

	/** @param array<string, string> $attrs leere Werte entfallen */
	private function el(string $name, array $attrs = []): DOMElement {
		$el = $this->out->createElement($name);
		foreach ($attrs as $k => $v) {
			if ($v !== '') {
				$el->setAttribute($k, $v);
			}
		}
		return $el;
	}
}
