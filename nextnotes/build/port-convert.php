<?php
// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// Nur Build (Host-php): erzeugt die Rohfassung eines Parser-Ports (M4b) aus einer Upstream-Datei.
// Aufruf: php port-convert.php <upstream.php> <Klasse> > roh.php  – danach B46-Änderungen von Hand (SPEC §11.2), Hash in upstream-ported.txt.
// Umbau: Funktionen → static-Methoden, Aufrufe → self::, const/define → Klassenkonstanten, preg_* → self::preg* (Pcre), t() → self::tr().
declare(strict_types=1);

[$file, $class] = [$argv[1] ?? '', $argv[2] ?? ''];
if (!is_file($file) || $class === '') {
	fwrite(STDERR, "Aufruf: php port-convert.php <datei> <Klasse>\n");
	exit(2);
}
$tokens = token_get_all((string)file_get_contents($file));
$functions = [];
$constants = [];
foreach ($tokens as $i => $t) {
	if (is_array($t) && $t[0] === T_FUNCTION) {
		for ($j = $i + 1; isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE; $j++);
		if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
			$functions[$tokens[$j][1]] = true;
		}
	}
	if (is_array($t) && $t[0] === T_CONST) {
		$constants[$tokens[$i + 2][1]] = true;
	}
	if (is_array($t) && $t[0] === T_STRING && $t[1] === 'define') {
		$constants[trim($tokens[$i + 2][1], '\'"')] = true;
	}
}
$preg = ['preg_match' => 'pregMatch', 'preg_match_all' => 'pregMatchAll', 'preg_replace' => 'pregReplace',
	'preg_replace_callback' => 'pregReplaceCallback', 'preg_split' => 'pregSplit', 'preg_quote' => 'pregQuote'];
$out = '';
$depth = 0;
$n = count($tokens);
for ($i = 0; $i < $n; $i++) {
	$t = $tokens[$i];
	if (!is_array($t)) {
		if ($t === '{') {
			$depth++;
		} elseif ($t === '}') {
			$depth--;
		}
		$out .= $t;
		continue;
	}
	[$id, $text] = $t;
	if ($id === T_OPEN_TAG) {
		continue;
	}
	if ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
		$depth++;
	}
	$prev = '';
	for ($j = $i - 1; $j >= 0; $j--) {
		if (!is_array($tokens[$j]) || ($tokens[$j][0] !== T_WHITESPACE && $tokens[$j][0] !== T_COMMENT && $tokens[$j][0] !== T_DOC_COMMENT)) {
			$prev = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
			break;
		}
	}
	$next = '';
	for ($j = $i + 1; $j < $n; $j++) {
		if (!is_array($tokens[$j]) || $tokens[$j][0] !== T_WHITESPACE) {
			$next = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
			break;
		}
	}
	if ($id === T_FUNCTION && $depth === 0) {
		$out .= 'public static function';
		continue;
	}
	if ($id === T_CONST && $depth === 0) {
		$out .= 'public const';
		continue;
	}
	if ($id === T_STRING && $text === 'define' && $depth === 0) {
		// define('X', v); → public const X = v;
		$j = $i + 2;
		$name = trim($tokens[$j][1], '\'"');
		$out .= 'public const ' . $name . ' =';
		$i = $j + 1;
		// Rest bis ')' ';' übernehmen, schließende Klammer weglassen
		$buf = '';
		for ($i++; $i < $n && $tokens[$i] !== ';'; $i++) {
			$buf .= is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
		}
		$out .= preg_replace('/\)\s*$/', '', $buf) . ';';
		continue;
	}
	if ($id === T_STRING && !in_array($prev, ['function', '->', '::', 'const'], true)) {
		if (isset($preg[$text]) && $next === '(') {
			$out .= 'self::' . $preg[$text];
			continue;
		}
		if ($text === 'function_exists' && is_array($tokens[$i + 2]) && $tokens[$i + 2][1] === "'t'") {
			// function_exists('t') → Übersetzer gesetzt
			$out .= 'self::hasTr()';
			$i += 3;
			continue;
		}
		if ($text === 't' && $next === '(') {
			$out .= 'self::tr';
			continue;
		}
		if (isset($functions[$text]) && $next === '(') {
			$out .= 'self::' . $text;
			continue;
		}
		if (isset($constants[$text]) && $next !== '(') {
			$out .= 'self::' . $text;
			continue;
		}
	}
	if ($id === T_CONSTANT_ENCAPSED_STRING && isset($functions[trim($text, '\'"')])) {
		// String-Callable 'name' → [self::class, 'name']
		$out .= '[self::class, ' . $text . ']';
		continue;
	}
	$out .= $text;
}
// Einrückung um eine Ebene (Tab) für den Klassenrumpf
$body = preg_replace('/^(?=.)/m', "\t", trim($out));
echo "final class $class {\n\tuse \\OCA\\NextNotes\\Service\\PcreCalls;\n\n$body\n}\n";
