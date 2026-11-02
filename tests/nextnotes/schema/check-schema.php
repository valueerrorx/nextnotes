<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/*
 * Done M1.3: vergleicht `occ db:schema:export --output=json` mit der aus SPEC §2.1 abgeleiteten Erwartung.
 *
 * Aufruf: php tests/nextnotes/schema/check-schema.php <schema-<env>.json> [--db=sqlite|mysql|pgsql] [--prefix=oc_] [--expect=<datei>]
 * --db wird aus dem Dateinamen abgeleitet (schema-sqlite → sqlite, schema-mariadb → mysql, schema-pgsql → pgsql).
 * Exit 0 = keine Abweichung, 1 = Abweichungen (je Zeile eine), 2 = Aufruffehler.
 *
 * Normalisierung (docs/slices/M1-plan.md §1 „Tester“):
 * - Index-Namen klein vergleichen (PostgreSQL).
 * - Primärschlüssel nur über primary-Flag und Spalten, nicht über den Namen.
 * - Länge nur bei string vergleichen (Integer-Längen ignorieren).
 * - AI-bigint-PK auf SQLite als integer akzeptieren.
 * - Defaults als String vergleichen (0 und "0" gleich).
 */

$args = array_slice($argv, 1);
$file = null;
$db = null;
$prefix = 'oc_';
$expectFile = __DIR__ . '/expected-m1.json';
foreach ($args as $a) {
	if (str_starts_with($a, '--db=')) {
		$db = substr($a, 5);
	} elseif (str_starts_with($a, '--prefix=')) {
		$prefix = substr($a, 9);
	} elseif (str_starts_with($a, '--expect=')) {
		$expectFile = substr($a, 9);
	} else {
		$file = $a;
	}
}
if ($file === null || !is_readable($file)) {
	fwrite(STDERR, "Aufruf: check-schema.php <schema.json> [--db=sqlite|mysql|pgsql] [--prefix=oc_]\n");
	exit(2);
}
if ($db === null) {
	$base = basename($file);
	$db = match (true) {
		str_contains($base, 'sqlite') => 'sqlite',
		str_contains($base, 'mariadb'), str_contains($base, 'mysql') => 'mysql',
		str_contains($base, 'pgsql'), str_contains($base, 'postgres') => 'pgsql',
		default => 'unknown',
	};
}

$schema = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
$expect = json_decode((string)file_get_contents($expectFile), true, 512, JSON_THROW_ON_ERROR);
$errors = [];
$err = static function (string $msg) use (&$errors): void {
	$errors[] = $msg;
};
$norm = static fn (mixed $v): ?string => $v === null ? null : (is_bool($v) ? ($v ? '1' : '0') : (string)$v);

foreach ($expect['absent'] as $t) {
	if (isset($schema[$prefix . $t])) {
		$err("$t: Tabelle darf in M1 nicht existieren");
	}
}

// Keine unerwarteten nextnotes-Tabellen
foreach (array_keys($schema) as $t) {
	if (str_starts_with($t, $prefix . 'nextnotes_')) {
		$short = substr($t, strlen($prefix));
		if (!isset($expect['tables'][$short]) && !in_array($short, $expect['absent'], true)) {
			$err("$short: unerwartete Tabelle");
		}
	}
}

foreach ($expect['tables'] as $t => $et) {
	$at = $schema[$prefix . $t] ?? null;
	if ($at === null) {
		$err("$t: Tabelle fehlt");
		continue;
	}
	$acols = $at['columns'] ?? [];

	foreach ($et['columns'] as $c => [$type, $notnull, $default, $length, $ai]) {
		$ac = $acols[$c] ?? null;
		if ($ac === null) {
			$err("$t.$c: Spalte fehlt");
			continue;
		}
		$atype = $ac['type'];
		$typeOk = $atype === $type
			|| ($db === 'sqlite' && $type === 'bigint' && $ai && $atype === 'integer');
		if (!$typeOk) {
			$err("$t.$c: Typ $atype, erwartet $type");
		}
		if ((bool)$ac['notnull'] !== $notnull) {
			$err("$t.$c: notnull " . var_export((bool)$ac['notnull'], true) . ', erwartet ' . var_export($notnull, true));
		}
		if ($norm($ac['default']) !== $norm($default)) {
			$err("$t.$c: default " . var_export($ac['default'], true) . ', erwartet ' . var_export($default, true));
		}
		if ($type === 'string' && (int)$ac['length'] !== $length) {
			$err("$t.$c: length " . var_export($ac['length'], true) . ", erwartet $length");
		}
		if ((bool)$ac['autoincrement'] !== $ai) {
			$err("$t.$c: autoincrement " . var_export((bool)$ac['autoincrement'], true) . ', erwartet ' . var_export($ai, true));
		}
	}
	foreach (array_keys($acols) as $c) {
		if (!isset($et['columns'][$c])) {
			$err("$t.$c: unerwartete Spalte");
		}
	}

	$primary = null;
	$aidx = [];
	foreach ($at['indexes'] ?? [] as $name => $ix) {
		if (!empty($ix['primary'])) {
			$primary = $ix['columns'];
			continue;
		}
		$aidx[strtolower((string)($ix['name'] ?? $name))] = $ix;
	}
	if ($primary !== $et['primary']) {
		$err("$t: Primärschlüssel " . json_encode($primary) . ', erwartet ' . json_encode($et['primary']));
	}
	foreach ($et['indexes'] as $name => [$unique, $cols]) {
		$ix = $aidx[strtolower($name)] ?? null;
		if ($ix === null) {
			$err("$t: Index $name fehlt");
			continue;
		}
		if ((bool)$ix['unique'] !== $unique) {
			$err("$t: Index $name unique " . var_export((bool)$ix['unique'], true) . ', erwartet ' . var_export($unique, true));
		}
		if (array_map('strtolower', $ix['columns']) !== $cols) {
			$err("$t: Index $name Spalten " . json_encode($ix['columns']) . ', erwartet ' . json_encode($cols));
		}
	}
	$expNames = array_map('strtolower', array_keys($et['indexes']));
	foreach (array_keys($aidx) as $name) {
		if (!in_array($name, $expNames, true)) {
			$err("$t: unerwarteter Index $name");
		}
	}
}

if ($errors !== []) {
	foreach ($errors as $e) {
		echo "FAIL $e\n";
	}
	echo count($errors) . " Abweichung(en) gegen SPEC §2.1 ($db, $file)\n";
	exit(1);
}
echo 'Schema OK: ' . count($expect['tables']) . " Tabellen gemäß SPEC §2.1 ($db)\n";
exit(0);
