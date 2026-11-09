<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service;

use DOMElement;
use DOMXPath;

/**
 * Block-Vertrag in Richtext-Inhalten (SPEC §4.7, M8-Plan §1.1a): Hosts am Attribut erkennen, prüfen, normalisieren.
 * Läuft nach dem Sanitizer-Durchlauf; Attribute ohne Tag-Whitelist sind dann schon entfernt.
 */
final class BlockMarkup {
	// Block-Attribute; von isDangerous ausgenommen (Text bzw. JSON, nie URL)
	public const ATTRS = ['data-math', 'data-nn-sheet', 'data-nn-calc'];
	public const MATH_MAX = 10000;
	// Tabellenblock (M8-Plan §3): Attributlänge in Bytes, Rastergrenzen, Zelltext
	public const SHEET_MAX = 262144;
	public const SHEET_ROWS = 50;
	public const SHEET_COLS = 26;
	public const SHEET_TEXT_MAX = 1000;
	private const SHEET_KEYS = ['v', 'lang', 'rows', 'cols', 'cells', 'fmt'];
	// Rechenblock (M8-Plan §4): Attributlänge in Bytes, Quelltext in Zeichen, Zeilen, Zeilenlänge
	public const CALC_MAX = 65536;
	public const CALC_SRC_MAX = 20000;
	public const CALC_LINES = 200;
	public const CALC_LINE_MAX = 500;

	public static function isBlockAttr(string $name): bool {
		return in_array($name, self::ATTRS, true);
	}

	/** Alle Hosts unter $root prüfen und normalisieren (Dokumentreihenfolge) */
	public static function normalize(DOMElement $root): void {
		$doc = $root->ownerDocument;
		$xpath = new DOMXPath($doc);
		$nodes = $xpath->query('.//*[@data-math or @data-nn-sheet or @data-nn-calc]', $root);
		if ($nodes === false || $nodes->length === 0) {
			return;
		}
		/** @var array<int, true> $hosts spl_object_id gültiger Hosts */
		$hosts = [];
		foreach (iterator_to_array($nodes) as $el) {
			if (!$el instanceof DOMElement || !self::attachedBelow($el, $root)) {
				continue;
			}
			$present = array_values(array_filter(self::ATTRS, static fn (string $a): bool => $el->hasAttribute($a)));
			if (count($present) > 1) {
				foreach ($present as $a) {
					$el->removeAttribute($a);
				}
				continue;
			}
			$attr = $present[0];
			if (!self::valid($el, $attr)) {
				$el->removeAttribute($attr);
				continue;
			}
			// gültiger Host im Fallback eines anderen Hosts: samt Inhalt entfernen
			if (self::insideHost($el, $root, $hosts)) {
				$el->parentNode?->removeChild($el);
				continue;
			}
			self::normalizeHost($el, $attr);
			$hosts[spl_object_id($el)] = true;
		}
	}

	private static function valid(DOMElement $el, string $attr): bool {
		$value = $el->getAttribute($attr);
		return match ($attr) {
			'data-math' => trim($value) !== '' && mb_strlen($value, 'UTF-8') <= self::MATH_MAX,
			'data-nn-sheet' => self::validSheet($value),
			'data-nn-calc' => self::validCalc($value),
			default => false,
		};
	}

	// Feste Reihenfolge class, Block-Attribut, contenteditable (wie der Client schreibt; zweiter Durchlauf gleich)
	private static function normalizeHost(DOMElement $el, string $attr): void {
		$classes = array_values(array_filter(explode(' ', strtr($el->getAttribute('class'), "\t\r\n\f", '    ')), static fn (string $c): bool => $c !== ''));
		if ($attr === 'data-math' && !in_array('math-inline', $classes, true) && !in_array('math-block', $classes, true)) {
			$classes[] = strtolower($el->tagName) === 'div' ? 'math-block' : 'math-inline';
		}
		$own = ['data-nn-sheet' => 'nn-sheet', 'data-nn-calc' => 'nn-calc'][$attr] ?? null;
		if ($own !== null && !in_array($own, $classes, true)) {
			$classes[] = $own;
		}
		$value = $el->getAttribute($attr);
		foreach (['class', $attr, 'contenteditable'] as $name) {
			$el->removeAttribute($name);
		}
		if ($classes !== []) {
			$el->setAttribute('class', implode(' ', $classes));
		}
		$el->setAttribute($attr, $value);
		$el->setAttribute('contenteditable', 'false');
		if ($attr !== 'data-math') {
			return;
		}
		// Inhalt = LaTeX als Text (statischer Fallback); KaTeX-Ausgabe des Editors fällt weg (B69)
		while ($el->firstChild !== null) {
			$el->removeChild($el->firstChild);
		}
		$el->appendChild($el->ownerDocument->createTextNode($el->getAttribute('data-math')));
	}

	/** Schema v1 (SPEC §4.7.6): sonst ungültig, nur das Attribut fällt weg */
	public static function validSheet(string $value): bool {
		if ($value === '' || strlen($value) > self::SHEET_MAX) {
			return false;
		}
		try {
			$data = json_decode($value, false, 4, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return false;
		}
		if (!$data instanceof \stdClass) {
			return false;
		}
		$keys = array_keys(get_object_vars($data));
		if (array_diff($keys, self::SHEET_KEYS) !== [] || !isset($data->v, $data->lang, $data->rows, $data->cols, $data->cells)) {
			return false;
		}
		if ($data->v !== 1 || $data->lang !== 'deDE' || !self::intIn($data->rows, 1, self::SHEET_ROWS) || !self::intIn($data->cols, 1, self::SHEET_COLS)) {
			return false;
		}
		if (!is_array($data->cells) || count($data->cells) !== $data->rows) {
			return false;
		}
		foreach ($data->cells as $row) {
			if (!is_array($row) || count($row) !== $data->cols) {
				return false;
			}
			foreach ($row as $cell) {
				$ok = $cell === null || is_bool($cell) || is_int($cell)
					|| (is_float($cell) && is_finite($cell))
					|| (is_string($cell) && mb_strlen($cell, 'UTF-8') <= self::SHEET_TEXT_MAX);
				if (!$ok) {
					return false;
				}
			}
		}
		if (!property_exists($data, 'fmt')) {
			return true;
		}
		if (!$data->fmt instanceof \stdClass) {
			return false;
		}
		foreach (get_object_vars($data->fmt) as $addr => $f) {
			if (!Pcre::match('/^[A-Z][1-9][0-9]?$/D', (string)$addr) || ord($addr[0]) - 65 >= $data->cols || (int)substr($addr, 1) > $data->rows) {
				return false;
			}
			if (!$f instanceof \stdClass) {
				return false;
			}
			foreach (get_object_vars($f) as $k => $x) {
				$ok = match ($k) {
					'b', 'p' => $x === 0 || $x === 1,
					'd' => self::intIn($x, 0, 4),
					default => false,
				};
				if (!$ok) {
					return false;
				}
			}
		}
		return true;
	}

	/** Schema v1 (SPEC §4.7.10): genau v = 1, src (Grenzen), graph 0/1; sonst ungültig */
	public static function validCalc(string $value): bool {
		if ($value === '' || strlen($value) > self::CALC_MAX) {
			return false;
		}
		try {
			$data = json_decode($value, false, 2, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return false;
		}
		if (!$data instanceof \stdClass) {
			return false;
		}
		$keys = array_keys(get_object_vars($data));
		sort($keys);
		if ($keys !== ['graph', 'src', 'v'] || $data->v !== 1 || ($data->graph !== 0 && $data->graph !== 1) || !is_string($data->src)) {
			return false;
		}
		if (mb_strlen($data->src, 'UTF-8') > self::CALC_SRC_MAX) {
			return false;
		}
		$lines = explode("\n", $data->src);
		if (count($lines) > self::CALC_LINES) {
			return false;
		}
		foreach ($lines as $line) {
			if (mb_strlen($line, 'UTF-8') > self::CALC_LINE_MAX) {
				return false;
			}
		}
		return true;
	}

	private static function intIn(mixed $x, int $min, int $max): bool {
		return is_int($x) && $x >= $min && $x <= $max;
	}

	private static function attachedBelow(DOMElement $el, DOMElement $root): bool {
		for ($n = $el->parentNode; $n !== null; $n = $n->parentNode) {
			if ($n->isSameNode($root)) {
				return true;
			}
		}
		return false;
	}

	/** @param array<int, true> $hosts */
	private static function insideHost(DOMElement $el, DOMElement $root, array $hosts): bool {
		if ($hosts === []) {
			return false;
		}
		for ($n = $el->parentNode; $n instanceof DOMElement && !$n->isSameNode($root); $n = $n->parentNode) {
			if (isset($hosts[spl_object_id($n)])) {
				return true;
			}
		}
		return false;
	}
}
