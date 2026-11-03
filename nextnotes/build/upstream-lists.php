<?php
// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// Nur Build/Prüfung (Host-php): gibt die Upstream-Asset-Listen als JSON aus. Aufruf: php upstream-lists.php <src>
declare(strict_types=1);

$src = rtrim($argv[1] ?? '', '/');
if ($src === '' || !is_dir($src . '/public')) {
	fwrite(STDERR, "Aufruf: php upstream-lists.php <src>\n");
	exit(2);
}
// index_js.php/index_css.php liefern nur bei direktem Aufruf aus; eingebunden definieren sie nur die Funktionen
$_SERVER['SCRIPT_NAME'] = 'upstream-lists.php';
require $src . '/public/index_js.php';
require $src . '/public/index_css.php';
require $src . '/css_assets.php';
// dark_mode_css.php ist keine Funktion: Liste aus dem Quelltext lesen
$dark = (string)file_get_contents($src . '/public/dark_mode_css.php');
if (!preg_match('/\$files\s*=\s*\[(.*?)\];/s', $dark, $m)) {
	fwrite(STDERR, "dark_mode_css.php: Liste \$files nicht gefunden\n");
	exit(1);
}
preg_match_all("/'([^']+)'/", $m[1], $files);
echo json_encode([
	'js' => poznoteGetIndexJsGroups(),
	'css' => poznoteGetIndexCssGroups(),
	'dark' => $files[1],
	'groups' => poznoteCssGroups(),
	'manifest' => poznoteCssManifest(),
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
