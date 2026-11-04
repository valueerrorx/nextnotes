<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/*
 * Plan M4d Done 2: liest ein Export-ZIP mit den Upstream-Importfunktionen (src/import_helpers.php, nur gelesen) und gibt
 * als JSON aus, was Poznote beim Import erkennen würde: Struktur, Notizen (Titel, Tags, Ordner, Typ), Anhänge.
 * Aufruf: php check-import.php <import_helpers.php> <zip>
 */

function t($key, $vars = [], $fallback = '') {
	return $fallback !== '' ? $fallback : $key;
}
require $argv[1];

$zip = new ZipArchive();
if ($zip->open($argv[2]) !== true) {
	fwrite(STDERR, "ZIP nicht lesbar\n");
	exit(1);
}
$out = ['structure' => poznoteAnalyzeImportZipStructure($zip), 'notes' => [], 'attachments' => [], 'skipped' => []];
for ($i = 0; $i < $zip->numFiles; $i++) {
	$name = (string)$zip->getNameIndex($i);
	if (str_ends_with($name, '/') || basename($name)[0] === '.') {
		continue;
	}
	if (poznoteIsAttachmentsArchiveManifest($name)) {
		$out['skipped'][] = $name;
		continue;
	}
	$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
	$content = (string)$zip->getFromIndex($i);
	if (in_array($ext, ['md', 'html'], true) && !str_starts_with($name, 'attachments/') && !str_starts_with($name, 'files/')) {
		$type = $ext === 'md' ? 'markdown' : 'note';
		$front = $type === 'markdown' ? parseFrontMatter($content)['metadata'] : null;
		$meta = extractNoteMetadata($content, $type, basename($name), $front ?: null);
		$out['notes'][$name] = ['title' => $meta['title'], 'tags' => $meta['tags'], 'favorite' => $meta['favorite'],
			'folder' => is_array($front) ? ($front['folder'] ?? null) : null, 'tasklist' => is_array($front) && ($front['type'] ?? null) === 'tasklist'];
		continue;
	}
	$out['attachments'][] = $name;
}
$zip->close();
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
