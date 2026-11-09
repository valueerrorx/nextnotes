<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\OneNote\Convert;

use OCA\NextNotes\Service\Pcre;

// Inline-Stile aus OneNote auf eine Positivliste mit strengen Werten kürzen (Plan M9 §5 „Stile“)
final class StyleFilter {
	private const COLOR = '/^(?:#[0-9a-f]{3,8}|[a-z]{3,30}|rgba?\(\s*+\d{1,3}%?+\s*+,\s*+\d{1,3}%?+\s*+,\s*+\d{1,3}%?+\s*+(?:,\s*+(?:0|1|0?\.\d{1,4})\s*+)?\))$/';
	private const KEYWORDS = [
		'font-weight' => ['normal', 'bold', 'bolder', 'lighter', '100', '200', '300', '400', '500', '600', '700', '800', '900'],
		'font-style' => ['normal', 'italic', 'oblique'],
		'text-align' => ['left', 'right', 'center', 'justify', 'start', 'end'],
		'list-style-type' => ['none', 'disc', 'circle', 'square', 'decimal', 'lower-alpha', 'upper-alpha', 'lower-latin', 'upper-latin', 'lower-roman', 'upper-roman'],
	];
	private const DECORATIONS = ['none', 'underline', 'line-through', 'overline'];
	// OneNote-Vorgaben: Schwarz auf Weiß und die Überschriftenfarben wären im dunklen Theme unlesbar
	private const DEFAULT_COLORS = ['black', '#000', '#000000', 'windowtext', 'rgb(0,0,0)', 'initial', 'inherit', 'currentcolor', 'transparent'];
	private const DEFAULT_BACKGROUNDS = ['white', '#fff', '#ffffff', 'window', 'rgb(255,255,255)', 'transparent', 'initial', 'inherit'];
	private const HEADING_COLORS = ['#1e4e79', '#2e75b5', '#1f3763', '#2f5496', '#5b9bd5', '#1f4d78'];

	/** @return array<string, string> erlaubte Eigenschaften mit normalisierten Werten */
	public static function parse(string $style, bool $heading = false): array {
		$out = [];
		if ($style === '' || strlen($style) > 4096) {
			return $out;
		}
		foreach (explode(';', $style) as $decl) {
			$colon = strpos($decl, ':');
			if ($colon === false) {
				continue;
			}
			$prop = strtolower(trim(substr($decl, 0, $colon)));
			$value = strtolower(trim(substr($decl, $colon + 1)));
			$value = trim(str_replace('!important', '', $value));
			$clean = self::value($prop, $value, $heading);
			if ($clean !== null) {
				$out[$prop] = $clean;
			}
		}
		return $out;
	}

	/** @param array<string, string> $props */
	public static function render(array $props): string {
		$parts = [];
		foreach ($props as $prop => $value) {
			$parts[] = $prop . ': ' . $value;
		}
		return $parts === [] ? '' : implode('; ', $parts) . ';';
	}

	private static function value(string $prop, string $value, bool $heading): ?string {
		if ($value === '' || strlen($value) > 64) {
			return null;
		}
		switch ($prop) {
			case 'color':
			case 'background-color':
				$value = Pcre::replace('/\s++/', '', $value);
				if (!Pcre::match(self::COLOR, $value)) {
					return null;
				}
				$defaults = $prop === 'color' ? self::DEFAULT_COLORS : self::DEFAULT_BACKGROUNDS;
				if (in_array($value, $defaults, true) || ($heading && $prop === 'color' && in_array($value, self::HEADING_COLORS, true))) {
					return null;
				}
				return $value;
			case 'text-decoration':
				$words = array_values(array_filter(explode(' ', $value), static fn (string $w): bool => $w !== ''));
				if ($words === [] || count($words) > 3 || array_diff($words, self::DECORATIONS) !== []) {
					return null;
				}
				return implode(' ', array_unique($words));
			default:
				return isset(self::KEYWORDS[$prop]) && in_array($value, self::KEYWORDS[$prop], true) ? $value : null;
		}
	}
}
