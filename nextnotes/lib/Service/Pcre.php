<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service;

use OCA\NextNotes\Service\Exception\BadRequestException;
use OCA\NextNotes\Service\Exception\TooLargeException;

/**
 * preg_* für Schreibpfade: ein Regex-Fehler (Backtrack-/JIT-Grenze, ungültiges UTF-8) wirft,
 * statt null/false zu liefern, das sonst als leerer oder unbereinigter Inhalt gespeichert würde (B37).
 */
final class Pcre {
	public static function replace(string $pattern, string $replacement, string $subject, int $limit = -1): string {
		return preg_replace($pattern, $replacement, $subject, $limit) ?? self::fail();
	}

	public static function replaceCallback(string $pattern, callable $callback, string $subject, int $limit = -1): string {
		return preg_replace_callback($pattern, $callback, $subject, $limit) ?? self::fail();
	}

	public static function match(string $pattern, string $subject, mixed &$matches = null, int $flags = 0, int $offset = 0): bool {
		$result = preg_match($pattern, $subject, $matches, $flags, $offset);
		return $result === false ? self::fail() : $result === 1;
	}

	public static function matchAll(string $pattern, string $subject, mixed &$matches = null, int $flags = PREG_PATTERN_ORDER): int {
		$result = preg_match_all($pattern, $subject, $matches, $flags);
		return $result === false ? self::fail() : $result;
	}

	/** @return list<string> */
	public static function split(string $pattern, string $subject, int $flags = 0): array {
		$result = preg_split($pattern, $subject, -1, $flags);
		return $result === false ? self::fail() : $result;
	}

	public static function fail(): never {
		$code = preg_last_error();
		if ($code === PREG_BAD_UTF8_ERROR || $code === PREG_BAD_UTF8_OFFSET_ERROR) {
			throw new BadRequestException('Invalid UTF-8 in content');
		}
		throw new TooLargeException('Content too complex to process (' . preg_last_error_msg() . ')');
	}
}
