<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/*
 * Plan M4d Done 1: erzeugt upstream.json aus den Upstream-Exporten (src/public/api_export_*.php, unverändert, nur gelesen)
 * mit dataset.php. Upstream-Umgebung (auth, config, db_connect, functions) ist durch Stubs ersetzt; Daten in SQLite.
 * Aufruf (braucht pdo_sqlite, zip, dom; z. B. im Testcontainer): php gen-upstream.php <src-Verzeichnis> > upstream.json
 */

$src = rtrim($argv[1] ?? '', '/');
if ($src === '' || !is_file("$src/export_helpers.php")) {
	fwrite(STDERR, "Aufruf: php gen-upstream.php <src>\n");
	exit(2);
}
$data = require __DIR__ . '/dataset.php';
require __DIR__ . '/summary.php';

$root = sys_get_temp_dir() . '/nn-upstream-export-' . bin2hex(random_bytes(4));
foreach (['public', 'lib', 'entries', 'attachments'] as $d) {
	mkdir("$root/$d", 0700, true);
}
foreach (['public/api_export_folder.php', 'public/api_export_entries.php', 'public/api_export_structured.php', 'public/api_export_attachments.php',
	'export_helpers.php', 'lib/export-staging.php', 'lib/folders.php', 'lib/tasklists.php'] as $f) {
	copy("$src/$f", "$root/$f");
}
// resolveAttachmentReferenceId aus src/lib/attachments.php (Rest der Datei braucht die Upstream-Speicherschicht)
$att = (string)file_get_contents("$src/lib/attachments.php");
preg_match('/function resolveAttachmentReferenceId\(.*?\n}\n/s', $att, $fn);
file_put_contents("$root/lib/resolve.php", "<?php\n" . $fn[0]);

file_put_contents("$root/auth.php", "<?php\nfunction requireApiAuth() {}\n");
file_put_contents("$root/version_helper.php", "<?php\nfunction poznoteAsset(\$p) { return \$p; }\n");
file_put_contents("$root/db_connect.php", "<?php\n\$con = new PDO('sqlite:' . getenv('NN_DB'));\n\$con->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);\n");
file_put_contents("$root/config.php", <<<'PHP'
<?php
function poznoteSendFile(string $path) { copy($path, getenv('NN_OUT')); }
function poznoteContentDisposition($type, $name, $fallback) { return $type . '; filename="' . $name . '"'; }
PHP);
file_put_contents("$root/functions.php", <<<'PHP'
<?php
require_once __DIR__ . '/lib/folders.php';
require_once __DIR__ . '/lib/resolve.php';
require_once __DIR__ . '/lib/tasklists.php';
function getEntriesPath() { return __DIR__ . '/entries'; }
function getAttachmentsPath() { return __DIR__ . '/attachments'; }
function getEntryFilename($id, $type) { return getEntriesPath() . '/' . (int)$id . ($type === 'markdown' ? '.md' : '.html'); }
function convertUtcToUserTimezone($s, $format = 'Y-m-d H:i:s') { return (string)$s; }
function poznoteAttachmentLocalFile($f) { $p = getAttachmentsPath() . '/' . $f; return is_file($p) ? $p : null; }
function poznoteAttachmentLocalOnlyFile($f) { return poznoteAttachmentLocalFile($f); }
function poznoteAttachmentsBucketMayHoldFiles() { return false; }
function t($key, $vars = [], $fallback = '') { return $fallback !== '' ? $fallback : $key; }
PHP);
file_put_contents("$root/run.php", "<?php\n\$_GET = json_decode(\$argv[2], true);\nrequire \$argv[1];\n");

// Datenbank im Upstream-Schema (nur benutzte Spalten)
$db = "$root/db.sqlite";
$con = new PDO('sqlite:' . $db);
$con->exec('CREATE TABLE workspaces (name TEXT)');
$con->exec('CREATE TABLE folders (id INTEGER PRIMARY KEY, name TEXT, parent_id INTEGER, workspace TEXT, display_order INTEGER DEFAULT 0)');
$con->exec('CREATE TABLE entries (id INTEGER PRIMARY KEY, heading TEXT, type TEXT, folder TEXT, folder_id INTEGER, workspace TEXT, trash INTEGER, attachments TEXT, tags TEXT, favorite INTEGER, created TEXT, updated TEXT)');
$con->prepare('INSERT INTO workspaces (name) VALUES (?)')->execute([$data['workspace']]);
$ids = ['notes' => [], 'attachments' => []];
$folderIds = [];
$n = 0;
foreach ($data['folders'] as $key => $f) {
	$folderIds[$key] = ++$n;
	$con->prepare('INSERT INTO folders (id, name, parent_id, workspace) VALUES (?, ?, ?, ?)')
		->execute([$n, $f['name'], $f['parent'] !== null ? $folderIds[$f['parent']] : null, $data['workspace']]);
}
$noteIds = [];
$n = 0;
foreach (array_keys($data['notes']) as $key) {
	$noteIds[$key] = ++$n;
	$ids['notes'][(string)$n] = $key;
}
$attById = [];
$n = 100;
foreach ($data['attachments'] as $key => $a) {
	$id = (string)++$n;
	$file = $id . '.' . pathinfo($a['name'], PATHINFO_EXTENSION);
	$ids['attachments'][$id] = $key;
	$attById[$key] = $id;
	file_put_contents("$root/attachments/$file", $a['bytes']);
	$per[$a['note']][] = ['id' => $id, 'filename' => $file, 'original_filename' => $a['name'], 'file_size' => strlen($a['bytes']), 'file_type' => $a['mime'], 'uploaded_at' => '2026-01-01 00:00:00'];
}
$i = 0;
foreach ($data['notes'] as $key => $note) {
	$id = $noteIds[$key];
	$content = preg_replace_callback('/\{ATT:(A\d+)\}/', static fn ($m) => '/api/v1/notes/' . $noteIds[$data['attachments'][$m[1]]['note']] . '/attachments/' . $attById[$m[1]], $note['content']);
	file_put_contents($root . '/entries/' . $id . ($note['type'] === 'markdown' ? '.md' : '.html'), $content);
	$folder = $note['folder'] !== null ? $folderIds[$note['folder']] : null;
	$stamp = sprintf('2026-01-01 00:00:%02d', ++$i);
	$con->prepare('INSERT INTO entries (id, heading, type, folder, folder_id, workspace, trash, attachments, tags, favorite, created, updated) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
		->execute([$id, $note['heading'], $note['type'], $folder !== null ? $data['folders'][$note['folder']]['name'] : null, $folder, $data['workspace'],
			!empty($note['trash']) ? 1 : 0, isset($per[$key]) ? json_encode($per[$key]) : '[]', implode(',', $note['tags']), $note['favorite'] ? 1 : 0, $stamp, $stamp]);
}

$exports = [
	'folder' => ['api_export_folder.php', ['folder_id' => (string)$folderIds['F1']]],
	'entries' => ['api_export_entries.php', []],
	'structured' => ['api_export_structured.php', []],
	'structured_ws' => ['api_export_structured.php', ['workspace' => $data['workspace']]],
	'attachments' => ['api_export_attachments.php', []],
];
$out = [];
foreach ($exports as $name => [$file, $get]) {
	$zipOut = "$root/$name.zip";
	$cmd = 'NN_DB=' . escapeshellarg($db) . ' NN_OUT=' . escapeshellarg($zipOut) . ' ' . escapeshellarg(PHP_BINARY) . ' '
		. escapeshellarg("$root/run.php") . ' ' . escapeshellarg("$root/public/$file") . ' ' . escapeshellarg(json_encode($get));
	exec($cmd . ' 2>&1', $lines, $rc);
	if ($rc !== 0 || !is_file($zipOut)) {
		fwrite(STDERR, "$name: rc=$rc " . implode("\n", $lines) . "\n");
		exit(1);
	}
	$out[$name] = nnExportSummary($zipOut, $ids);
}
exec('rm -rf ' . escapeshellarg($root));
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
