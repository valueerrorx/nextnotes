<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service;

/**
 * preg_*-Ersatz für portierten Upstream-Code (Service/Convert, M4b): Argumente lose wie Upstream (String-Umwandlung),
 * Regex-Fehler werfen wie Pcre (413/400, nie leere Ausgabe, B46); t() über einen gesetzten Übersetzer.
 */
trait PcreCalls {
	/** @var (callable(string, array, ?string): string)|null */
	private static $translator = null;

	/** @param (callable(string, array, ?string): string)|null $t Upstream t(key, vars, default) */
	public static function setTranslator(?callable $t): void {
		self::$translator = $t;
	}

	private static function hasTr(): bool {
		return self::$translator !== null;
	}

	private static function tr(string $key, array $vars = [], ?string $default = null): string {
		return self::$translator !== null ? (string)(self::$translator)($key, $vars, $default) : ($default ?? $key);
	}

	private static function pregMatch(string $pattern, mixed $subject, mixed &$matches = null, int $flags = 0): int {
		return preg_match($pattern, (string)$subject, $matches, $flags) ?: (preg_last_error() === PREG_NO_ERROR ? 0 : Pcre::fail());
	}

	private static function pregMatchAll(string $pattern, mixed $subject, mixed &$matches = null, int $flags = PREG_PATTERN_ORDER): int {
		$r = preg_match_all($pattern, (string)$subject, $matches, $flags);
		return $r === false ? Pcre::fail() : $r;
	}

	private static function pregReplace(string $pattern, mixed $replacement, mixed $subject, int $limit = -1): string {
		return preg_replace($pattern, (string)$replacement, (string)$subject, $limit) ?? Pcre::fail();
	}

	private static function pregReplaceCallback(string $pattern, callable $callback, mixed $subject, int $limit = -1): string {
		return preg_replace_callback($pattern, $callback, (string)$subject, $limit) ?? Pcre::fail();
	}

	/** @return list<string> */
	private static function pregSplit(string $pattern, mixed $subject, int $limit = -1, int $flags = 0): array {
		$r = preg_split($pattern, (string)$subject, $limit, $flags);
		return $r === false ? Pcre::fail() : $r;
	}

	private static function pregQuote(string $text, ?string $delimiter = null): string {
		return preg_quote($text, $delimiter);
	}
}
