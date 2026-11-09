<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\OneNote;

/**
 * Budget je uid (Plan M9 §4.5, F2/F3): gleitendes 60-min-Fenster in Minuten-Eimern, ≤ 350 Anfragen; rein, ohne I/O.
 * Ein Eimer fällt erst 61 min nach Beginn seiner Minute heraus: so liegt jede gezählte Anfrage volle 60 min im Fenster.
 */
final class RateBudget {
	public const MAX = 350;
	public const KEEP_MINUTES = 61;
	public const MIN_GAP = 0.6;
	public const BACKOFF_BASE = 300;
	public const BACKOFF_MAX = 3600;
	public const BACKOFF_FAIL_LEVEL = 10;

	/** @return array<int, int> Minute → Anzahl, nur gültige Eimer */
	public static function decode(?string $json, int $now): array {
		$data = $json !== null && $json !== '' ? json_decode($json, true) : [];
		$out = [];
		$first = intdiv($now, 60) - self::KEEP_MINUTES + 1;
		foreach (is_array($data) ? $data : [] as $minute => $count) {
			if (is_numeric($minute) && is_int($count) && $count > 0 && (int)$minute >= $first) {
				$out[(int)$minute] = $count;
			}
		}
		ksort($out);
		return $out;
	}

	/** @param array<int, int> $buckets */
	public static function encode(array $buckets): string {
		return (string)json_encode((object)array_map('intval', $buckets));
	}

	/** @param array<int, int> $buckets */
	public static function used(array $buckets): int {
		return array_sum($buckets);
	}

	/** @param array<int, int> $buckets */
	public static function add(array $buckets, int $now, int $n = 1): array {
		$m = intdiv($now, 60);
		$buckets[$m] = ($buckets[$m] ?? 0) + $n;
		return $buckets;
	}

	/**
	 * Frühester Zeitpunkt, ab dem $need weitere Anfragen ins Fenster passen ($now, wenn sofort)
	 * @param array<int, int> $buckets gültige Eimer (decode)
	 */
	public static function nextFreeAt(array $buckets, int $now, int $need = 1): int {
		$used = self::used($buckets);
		if ($used + $need <= self::MAX) {
			return $now;
		}
		ksort($buckets);
		foreach ($buckets as $minute => $count) {
			$used -= $count;
			if ($used + $need <= self::MAX) {
				return ($minute + self::KEEP_MINUTES) * 60;
			}
		}
		return $now;
	}

	// 429/5xx/Netz: Retry-After (Sekunden) gilt, sonst 5 min × 2^level, höchstens 60 min
	public static function backoffSeconds(int $level, string $retryAfter = ''): int {
		$retryAfter = trim($retryAfter);
		if ($retryAfter !== '' && ctype_digit($retryAfter) && (int)$retryAfter > 0) {
			return min((int)$retryAfter, self::BACKOFF_MAX);
		}
		return min(self::BACKOFF_BASE * (2 ** max(0, min($level, 10))), self::BACKOFF_MAX);
	}
}
