<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service;

// Wiederholungen "<Anzahl><Einheit>" (i h d w m y) wie Upstream RemindersController/lib/tasklists.php
final class Recurrence {
	public const PATTERN = '/^([1-9]\d{0,2})([ihdwmy])$/';
	private const SECONDS = ['i' => 60, 'h' => 3600, 'd' => 86400, 'w' => 604800];

	// null = keine, false = ungültig
	public static function normalize(mixed $value): string|false|null {
		if ($value === null) {
			return null;
		}
		if (!is_string($value)) {
			return false;
		}
		$value = strtolower(trim($value));
		if ($value === '' || $value === 'none') {
			return null;
		}
		return preg_match(self::PATTERN, $value) ? $value : false;
	}

	// Erster Termin des Rhythmus nach $now, verankert an $from; d/w/m/y als Kalenderschritt in $tz (sommerzeitfest)
	public static function nextAfter(int $from, string $rule, int $now, ?\DateTimeZone $tz = null): ?int {
		if (!preg_match(self::PATTERN, $rule, $m)) {
			return null;
		}
		$count = (int)$m[1];
		if (isset(self::SECONDS[$m[2]]) && $m[2] !== 'd' && $m[2] !== 'w') {
			$step = $count * self::SECONDS[$m[2]];
			return $from + (intdiv(max(0, $now - $from), $step) + 1) * $step;
		}
		$unit = ['d' => 'day', 'w' => 'week', 'm' => 'month', 'y' => 'year'][$m[2]];
		$start = (new \DateTimeImmutable('@' . $from))->setTimezone($tz ?? new \DateTimeZone('UTC'));
		// Tage/Wochen: ganze Schritte bis kurz vor $now überspringen (Stundenversatz durch Sommerzeit bleibt unter einem Schritt)
		$n = isset(self::SECONDS[$m[2]]) ? max(0, intdiv(max(0, $now - $from), $count * self::SECONDS[$m[2]]) - 1) : 0;
		$date = $n > 0 ? $start->modify('+' . ($n * $count) . ' ' . $unit) : $start;
		for ($i = 0; $i < 1000; $i++) {
			$date = $date->modify('+' . $count . ' ' . $unit);
			if ($date->getTimestamp() > $now) {
				return $date->getTimestamp();
			}
		}
		return null;
	}

	// Fälligkeit 'Y-m-d' oder 'Y-m-dTH:i' (lokal) → Unix; ohne Uhrzeit 09:00 wie Upstream
	public static function dueToTimestamp(string $dueAt, \DateTimeZone $tz): ?int {
		if (!preg_match('/^(\d{4}-\d{2}-\d{2})(?:T(\d{2}:\d{2}))?$/', $dueAt, $m) || !InputValidator::validDateTime($dueAt)) {
			return null;
		}
		$dt = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $m[1] . ' ' . ($m[2] ?? '09:00'), $tz);
		return $dt === false ? null : $dt->getTimestamp();
	}

	// Unix → Fälligkeit im Format der Aufgabe (mit/ohne Uhrzeit)
	public static function timestampToDue(int $ts, \DateTimeZone $tz, bool $withTime): string {
		return (new \DateTimeImmutable('@' . $ts))->setTimezone($tz)->format($withTime ? 'Y-m-d\TH:i' : 'Y-m-d');
	}
}
