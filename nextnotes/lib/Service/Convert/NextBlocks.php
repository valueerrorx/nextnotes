<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service\Convert;

use DOMDocument;
use DOMElement;
use DOMXPath;
use OCA\NextNotes\Service\HtmlSanitizer;
use OCA\NextNotes\Service\Pcre;

/**
 * Block-Hosts (SPEC §4.7) vor HtmlToMarkdown durch Platzhalter ersetzen und danach als Markdown einsetzen (M8-Plan §1.1a).
 * Formel: inline `$…$`, Block `$$…$$`; bricht der Rückweg über MarkdownRenderer, dann Code-Span bzw. Codeblock.
 * Tabellenblock: Markdown-Tabelle aus dem statischen Fallback (Ergebnisse, keine Formeln, M8-Plan §3).
 * Rechenblock: Codeblock ohne Sprache, je Zeile `Eingabe  # = Ergebnis` bzw. `# Fehler: …` (M8-Plan §4).
 */
final class NextBlocks {
	// Platzhalter nur aus Buchstaben/Ziffern: übersteht HtmlToMarkdown (Steuerzeichen nicht), Kennung je Aufruf
	private const MARK = 'NNBLK';

	/** @return array{0: string, 1: array{id: string, blocks: list<array{block: bool, top: bool, latex: string, rows?: list<list<string>>}>}|array{}} */
	public static function toMarkdownPre(string $html): array {
		if (stripos($html, 'data-math') === false && stripos($html, 'data-nn-sheet') === false && stripos($html, 'data-nn-calc') === false) {
			return [$html, []];
		}
		$id = self::MARK . bin2hex(random_bytes(6));
		$dom = new DOMDocument();
		$dom->encoding = 'UTF-8';
		HtmlSanitizer::loadHtmlQuiet($dom, '<html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_PARSEHUGE);
		$body = $dom->getElementsByTagName('body')->item(0);
		if ($body === null) {
			return [$html, []];
		}
		$xpath = new DOMXPath($dom);
		$nodes = $xpath->query('.//*[@data-math or @data-nn-sheet or @data-nn-calc]', $body);
		$blocks = [];
		$touched = false;
		foreach ($nodes === false ? [] : iterator_to_array($nodes) as $el) {
			// innerhalb eines schon ersetzten Hosts (abgehängt): überspringen
			if (!$el instanceof DOMElement || !self::below($el, $body)) {
				continue;
			}
			if ($el->hasAttribute('data-nn-sheet')) {
				$touched = true;
				self::sheet($dom, $xpath, $el, $body, $id, $blocks);
				continue;
			}
			if ($el->hasAttribute('data-nn-calc')) {
				$touched = true;
				self::calc($dom, $xpath, $el, $body, $id, $blocks);
				continue;
			}
			$class = ' ' . $el->getAttribute('class') . ' ';
			$block = strtolower($el->tagName) === 'div' || str_contains($class, ' math-block ');
			$top = $el->parentNode !== null && $el->parentNode->isSameNode($body);
			$token = $dom->createTextNode($id . 'I' . count($blocks) . 'E');
			$blocks[] = ['block' => $block, 'top' => $block && $top, 'latex' => $el->getAttribute('data-math')];
			if ($block && $top) {
				$p = $dom->createElement('p');
				$p->appendChild($token);
				$token = $p;
			}
			$el->parentNode->replaceChild($token, $el);
		}
		if ($blocks === [] && !$touched) {
			return [$html, []];
		}
		$out = '';
		for ($child = $body->firstChild; $child !== null; $child = $child->nextSibling) {
			$out .= $dom->saveHTML($child);
		}
		return [$out, $blocks === [] ? [] : ['id' => $id, 'blocks' => $blocks]];
	}

	/** @param array{id?: string, blocks?: list<array{block: bool, top: bool, latex: string}>} $map aus toMarkdownPre */
	public static function restore(string $markdown, array $map): string {
		if ($map === []) {
			return $markdown;
		}
		$blocks = $map['blocks'];
		Pcre::matchAll('/' . $map['id'] . 'I(\d++)E/', $markdown, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
		$out = '';
		$pos = 0;
		foreach ($m as [[$token, $at], [$index]]) {
			$b = $blocks[(int)$index] ?? null;
			$before = $at > 0 ? $markdown[$at - 1] : '';
			$after = $markdown[$at + strlen($token)] ?? '';
			$out .= substr($markdown, $pos, $at - $pos);
			if ($b !== null) {
				$out .= match (true) {
					isset($b['code']) => self::codeBlock($b['code']),
					isset($b['rows']) => self::table($b['rows']),
					$b['block'] => self::blockMath($b['latex'], $b['top']),
					default => self::inlineMath($b['latex'], $before, $after),
				};
			}
			$pos = $at + strlen($token);
		}
		$out .= substr($markdown, $pos);
		return trim(Pcre::replace('/\n{3,}/', "\n\n", $out));
	}

	// Inline: Renderer liest $…$ ohne $ im Inhalt, nicht hinter \ oder $, nicht vor einer Ziffer; Backticks sind vorher Code
	public static function inlineMath(string $latex, string $before = '', string $after = ''): string {
		$latex = trim(Pcre::replace('/[ \t]*+\n\s*+/', ' ', $latex));
		if ($latex === '' || str_contains($latex, '`') || $before === '\\' || $before === '$' || ctype_digit($after)) {
			return self::codeSpan($latex);
		}
		// $ ist im Inline-Inhalt nie lesbar: als \char36{} (KaTeX: Dollarzeichen)
		return '$' . str_replace(['\\$', '$'], ['\\char36{}', '\\char36{}'], $latex) . '$';
	}

	// Block: $ ohne \ davor wird \$, Leerzeilen werden ein Umbruch; Backticks → Codeblock latex
	public static function blockMath(string $latex, bool $top = true): string {
		$latex = trim(Pcre::replace('/\n[ \t]*+(?:\n[ \t]*+)++/', "\n", str_replace("\r", '', $latex)));
		if ($latex === '') {
			return '';
		}
		if (str_contains($latex, '`')) {
			// Renderer kennt nur Zäune aus genau drei Backticks; Zeile aus ``` im Inhalt → Code-Span
			return $top && !Pcre::match('/^[ \t]*+```/m', $latex) ? "\n\n```latex\n" . $latex . "\n```\n\n" : self::codeSpan(Pcre::replace('/[ \t]*+\n\s*+/', ' ', $latex));
		}
		$latex = Pcre::replace('/(?<!\\\\)\$/', '\\\\$', $latex);
		return $top ? "\n\n\$\$\n" . $latex . "\n\$\$\n\n" : '$$' . Pcre::replace('/[ \t]*+\n\s*+/', ' ', $latex) . '$$';
	}

	/**
	 * Tabellenblock: auf oberster Ebene Platzhalter mit den Zeilen des Fallbacks, sonst nur den Host auspacken (Tabellen-Konverter)
	 * @param list<array<string, mixed>> $blocks
	 */
	private static function sheet(DOMDocument $dom, DOMXPath $xpath, DOMElement $el, DOMElement $body, string $id, array &$blocks): void {
		$parent = $el->parentNode;
		if (!$parent->isSameNode($body)) {
			while ($el->firstChild !== null) {
				$parent->insertBefore($el->firstChild, $el);
			}
			$parent->removeChild($el);
			return;
		}
		$rows = [];
		foreach ($xpath->query('.//tr', $el) ?: [] as $tr) {
			$row = [];
			foreach ($xpath->query('./td|./th', $tr) ?: [] as $cell) {
				$row[] = trim(Pcre::replace('/\s+/u', ' ', $cell->textContent));
			}
			if ($row !== []) {
				$rows[] = $row;
			}
		}
		$p = $dom->createElement('p');
		$p->appendChild($dom->createTextNode($id . 'I' . count($blocks) . 'E'));
		$blocks[] = ['block' => true, 'top' => true, 'latex' => '', 'rows' => $rows];
		$parent->replaceChild($p, $el);
	}

	/**
	 * Rechenblock: auf oberster Ebene Codeblock aus `src` (Attribut) und den Ergebnissen des Fallbacks, sonst Host auspacken
	 * @param list<array<string, mixed>> $blocks
	 */
	private static function calc(DOMDocument $dom, DOMXPath $xpath, DOMElement $el, DOMElement $body, string $id, array &$blocks): void {
		$parent = $el->parentNode;
		$data = json_decode($el->getAttribute('data-nn-calc'), true);
		if (!$parent->isSameNode($body) || !is_array($data) || !is_string($data['src'] ?? null)) {
			while ($el->firstChild !== null) {
				$parent->insertBefore($el->firstChild, $el);
			}
			$parent->removeChild($el);
			return;
		}
		$outs = [];
		foreach ($xpath->query('./p[contains(concat(" ", normalize-space(@class), " "), " nn-calc-line ")]', $el) ?: [] as $p) {
			$span = $xpath->query('./span[contains(concat(" ", normalize-space(@class), " "), " nn-calc-out ")]', $p)->item(0);
			$outs[] = $span === null ? ['', false] : [trim(Pcre::replace('/\s+/u', ' ', $span->textContent)), str_contains(' ' . $span->getAttribute('class') . ' ', ' nn-err ')];
		}
		$lines = [];
		foreach (explode("\n", str_replace("\r", '', $data['src'])) as $i => $line) {
			$line = rtrim($line);
			[$out, $err] = $outs[$i] ?? ['', false];
			if ($line !== '' && $out !== '' && !str_starts_with(ltrim($line), '#')) {
				$line .= $err ? '  # ' . $out : '  # = ' . $out;
			}
			$lines[] = $line;
		}
		$p = $dom->createElement('p');
		$p->appendChild($dom->createTextNode($id . 'I' . count($blocks) . 'E'));
		$blocks[] = ['block' => true, 'top' => true, 'latex' => '', 'code' => $lines];
		$parent->replaceChild($p, $el);
	}

	/**
	 * Codeblock aus drei Backticks (der Port-Renderer kennt nur diesen Zaun): eine Zeile, die nur aus ``` besteht, beendete ihn;
	 * sie bekommt ein # dahinter (Kommentar in math.js)
	 * @param list<string> $lines
	 */
	public static function codeBlock(array $lines): string {
		while ($lines !== [] && trim($lines[array_key_last($lines)]) === '') {
			array_pop($lines);
		}
		if ($lines === []) {
			return '';
		}
		$lines = array_map(static fn (string $l): string => Pcre::match('/^[ \t]*+```[ \t]*+$/D', $l) ? rtrim($l) . ' #' : $l, $lines);
		return "\n\n```\n" . implode("\n", $lines) . "\n```\n\n";
	}

	/** @param list<list<string>> $rows Markdown-Tabelle, erste Zeile als Kopf, Zelltext mit escapten Markdown-Zeichen (Rückweg = Text) */
	public static function table(array $rows): string {
		if ($rows === []) {
			return '';
		}
		$cols = max(array_map('count', $rows));
		$out = "\n\n";
		foreach ($rows as $i => $row) {
			$row = array_map(self::cellText(...), array_pad($row, $cols, ''));
			$out .= '| ' . implode(' | ', $row) . " |\n";
			if ($i === 0) {
				$out .= '| ' . implode(' | ', array_fill(0, $cols, '---')) . " |\n";
			}
		}
		return $out . "\n";
	}

	// Tabellenzelle: Zeichen, die der Renderer als Spalte, Auszeichnung, Formel, Link oder HTML liest, mit \ escapen;
	// mit \ im Text Code-Span (der Renderer liest \\\| als Lauf \\ + Spaltengrenze)
	private static function cellText(string $c): string {
		return str_contains($c, '\\') ? self::codeSpan($c) : Pcre::replace('/[|*_~=$\[\]<>`]/', '\\\\$0', $c);
	}

	// Code-Span: Zaun = längste Backtick-Folge + 1, Leerzeichen innen, wenn der Inhalt mit ` beginnt oder endet
	public static function codeSpan(string $text): string {
		if ($text === '') {
			return '';
		}
		$fence = str_repeat('`', self::longestRun($text, '`') + 1);
		$pad = $text[0] === '`' || $text[strlen($text) - 1] === '`' ? ' ' : '';
		return $fence . $pad . $text . $pad . $fence;
	}

	public static function longestRun(string $text, string $char): int {
		$max = 0;
		Pcre::matchAll('/' . preg_quote($char, '/') . '++/', $text, $m);
		foreach ($m[0] as $run) {
			$max = max($max, strlen($run));
		}
		return $max;
	}

	private static function below(DOMElement $el, \DOMNode $root): bool {
		for ($n = $el->parentNode; $n !== null; $n = $n->parentNode) {
			if ($n->isSameNode($root)) {
				return true;
			}
		}
		return false;
	}
}
