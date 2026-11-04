<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Gemeinsame Testdaten für gen-upstream.php (Upstream-Export) und ExportZipTest (Port), Plan M4d Done 1
return [
	'workspace' => 'Notes',
	// Schlüssel = symbolischer Name; parent = Schlüssel oder null
	'folders' => [
		'F1' => ['name' => 'Ordner Ä', 'parent' => null],
		'F2' => ['name' => 'Sub', 'parent' => 'F1'],
		'F3' => ['name' => 'Leer', 'parent' => 'F1'],
		'F4' => ['name' => 'Zweiter', 'parent' => null],
	],
	'notes' => [
		'N1' => ['heading' => 'Html eins', 'type' => 'note', 'folder' => 'F1', 'tags' => ['t1', 't2'], 'favorite' => true, 'content' => '<p>hallo eins</p>'],
		'N2' => ['heading' => 'MD zwei', 'type' => 'markdown', 'folder' => 'F2', 'tags' => ['t3'], 'favorite' => false, 'content' => "# K\n\n![b]({ATT:A1})\n"],
		'N3' => ['heading' => 'TL', 'type' => 'tasklist', 'folder' => null, 'tags' => [], 'favorite' => false, 'content' => '[{"id":1,"text":"Aufgabe a","completed":true},{"id":2,"text":"Aufgabe b","completed":false}]'],
		'N4' => ['heading' => 'Html vier', 'type' => 'note', 'folder' => 'F4', 'tags' => [], 'favorite' => false, 'content' => '<p>vier <a href="{ATT:A2}">datei</a></p>'],
		'N5' => ['heading' => 'MD fünf', 'type' => 'markdown', 'folder' => null, 'tags' => [], 'favorite' => false, 'content' => "fünf\n"],
		// Papierkorb: in keinem Export (B45)
		'N6' => ['heading' => 'Weg', 'type' => 'note', 'folder' => 'F1', 'tags' => [], 'favorite' => false, 'content' => '<p>weg</p>', 'trash' => true],
	],
	// Anhänge: note = Schlüssel
	'attachments' => [
		'A1' => ['note' => 'N2', 'name' => 'b.png', 'mime' => 'image/png', 'bytes' => "\x89PNG\r\n\x1a\nA1"],
		'A2' => ['note' => 'N4', 'name' => 'doc.txt', 'mime' => 'text/plain', 'bytes' => "text-a2\n"],
		'A3' => ['note' => 'N6', 'name' => 'weg.txt', 'mime' => 'text/plain', 'bytes' => "trash-a3\n"],
	],
];
