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
			// Tabellen- und Rechenblock folgen in M8b/M8c; bis dahin lässt die Tag-Whitelist die Attribute nicht durch
			default => false,
		};
	}

	// Feste Reihenfolge class, Block-Attribut, contenteditable (wie der Client schreibt; zweiter Durchlauf gleich)
	private static function normalizeHost(DOMElement $el, string $attr): void {
		$classes = array_values(array_filter(explode(' ', strtr($el->getAttribute('class'), "\t\r\n\f", '    ')), static fn (string $c): bool => $c !== ''));
		if ($attr === 'data-math' && !in_array('math-inline', $classes, true) && !in_array('math-block', $classes, true)) {
			$classes[] = strtolower($el->tagName) === 'div' ? 'math-block' : 'math-inline';
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
