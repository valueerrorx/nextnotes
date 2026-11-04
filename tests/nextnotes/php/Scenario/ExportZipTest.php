<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Scenario;

use OCA\NextNotes\BackgroundJob\ExportTempCleanupJob;
use OCA\NextNotes\Controller\CompatController;
use OCA\NextNotes\Db\FolderMapper;
use OCA\NextNotes\Db\NoteMapper;
use OCA\NextNotes\Db\NoteTagMapper;
use OCA\NextNotes\Middleware\ApiExceptionMiddleware;
use OCA\NextNotes\Service\AccessService;
use OCA\NextNotes\Service\AttachmentService;
use OCA\NextNotes\Service\Exception\TooLargeException;
use OCA\NextNotes\Service\ExportArchive;
use OCA\NextNotes\Service\ExportService;
use OCA\NextNotes\Service\HtmlSanitizer;
use OCA\NextNotes\Service\Transaction;
use OCA\NextNotes\Service\WorkspaceService;
use OCA\NextNotes\Service\ZipExportService;
use OCA\NextNotes\Tests\Support\ApiClient;
use OCA\NextNotes\Tests\Support\ApiTestCase;
use OCA\NextNotes\Tests\Support\Response;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IDateTimeZone;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\ITempManager;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Lock\ILockingProvider;
use OCP\Server;

// Plan M4 §M4d: ZIP-Exporte wie Upstream (Fixture aus Upstream-Code), Upstream-Import liest sie, Umfang (ADR 0008, B45),
// Fremdreferenzen (B47), Rechte, Rate-Limit, Sperre, Gesamtgrenze, Aufräumjob; Nacharbeit M4c-r2 (verschachtelte Transaktion)
final class ExportZipTest extends ApiTestCase {
	private const APP = '/index.php/apps/nextnotes/';
	private const FIX = __DIR__ . '/../../fixtures/export';
	private const NAV_ACCEPT = 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8';
	private const NAV = 'Accept: ' . self::NAV_ACCEPT;

	private static function get(ApiClient $c, string $path): Response {
		return $c->absolute('GET', self::APP . $path);
	}

	private static function zipFile(Response $r, string $what): string {
		self::assertSame(200, $r->status, "$what: " . $r->describe());
		self::assertStringStartsWith('application/zip', (string)$r->header('Content-Type'), "$what: MIME");
		self::assertStringStartsWith('attachment', (string)$r->header('Content-Disposition'), "$what: attachment");
		self::assertSame('nosniff', $r->header('X-Content-Type-Options'), "$what: nosniff");
		self::assertSame((string)strlen($r->body), $r->header('Content-Length'), "$what: Content-Length");
		$path = tempnam(sys_get_temp_dir(), 'nnzip');
		file_put_contents($path, $r->body);
		$zip = new \ZipArchive();
		self::assertTrue($zip->open($path) === true, "$what: ZIP lesbar");
		$zip->close();
		return $path;
	}

	/** @return array<string, string> Eintrag => Inhalt */
	private static function entries(string $path): array {
		$zip = new \ZipArchive();
		$zip->open($path);
		$out = [];
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$out[(string)$zip->getNameIndex($i)] = (string)$zip->getFromIndex($i);
		}
		$zip->close();
		return $out;
	}

	private static function all(string $path): string {
		$e = self::entries($path);
		return implode("\n", array_keys($e)) . "\n" . implode("\n", $e);
	}

	private static function set(int $id, string $col, mixed $value): void {
		$qb = self::db()->getQueryBuilder();
		$qb->update('nextnotes_notes')->set($col, $qb->createNamedParameter($value))->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))->executeStatement();
	}

	/** @return string[] Export-Temp-Ordner */
	private static function tempDirs(): array {
		$base = rtrim((string)Server::get(ITempManager::class)->getTempBaseDir(), '/');
		return glob($base . '/oc_tmp_*.' . ExportArchive::TEMP_POSTFIX, GLOB_ONLYDIR) ?: [];
	}

	/**
	 * dataset.php im Port anlegen
	 * @return array{ws: string, ids: array{notes: array<string, string>, attachments: array<string, string>}, notes: array<string, int>, folders: array<string, int>}
	 */
	private function seed(ApiClient $a): array {
		$data = require self::FIX . '/dataset.php';
		[$ws] = self::newWorkspace($a, 'Zip');
		$folders = [];
		foreach ($data['folders'] as $key => $f) {
			$body = ['folder_name' => $f['name'], 'workspace' => $ws] + ($f['parent'] !== null ? ['parent_id' => $folders[$f['parent']]] : []);
			$folders[$key] = (int)self::ok($a->call('POST', '/folders', $body), "Ordner $key")['folder_id'];
		}
		$notes = [];
		foreach ($data['notes'] as $key => $n) {
			$body = ['heading' => $n['heading'], 'type' => $n['type'], 'content' => 'x', 'workspace' => $ws, 'tags' => $n['tags']]
				+ ($n['folder'] !== null ? ['folder_id' => $folders[$n['folder']]] : []);
			$notes[$key] = (int)self::ok($a->call('POST', '/notes', $body), "Notiz $key")['note']['id'];
		}
		$atts = [];
		foreach ($data['attachments'] as $key => $att) {
			$atts[$key] = (int)self::ok($a->upload('/notes/' . $notes[$att['note']] . '/attachments', $att['name'], $att['bytes'], [], $att['mime']), "Anhang $key")['attachment_id'];
		}
		foreach ($data['notes'] as $key => $n) {
			$content = preg_replace_callback('/\{ATT:(A\d+)\}/', static fn ($m) => '/api/v1/notes/' . $notes[$data['attachments'][$m[1]]['note']] . '/attachments/' . $atts[$m[1]], $n['content']);
			self::set($notes[$key], 'content', $content);
			self::set($notes[$key], 'favorite', $n['favorite'] ? 1 : 0);
		}
		foreach ($data['notes'] as $key => $n) {
			if (!empty($n['trash'])) {
				self::ok($a->call('DELETE', '/notes/' . $notes[$key]), "Papierkorb $key");
			}
		}
		return ['ws' => $ws, 'notes' => $notes, 'folders' => $folders,
			'ids' => ['notes' => array_map('strval', array_flip(array_map('strval', $notes))), 'attachments' => array_map('strval', array_flip(array_map('strval', $atts)))]];
	}

	/** Done 1, 2: Aufbau = Upstream-Export derselben Daten (ohne Papierkorb, B45); Upstream-Import erkennt Notizen, Ordner, Anhänge */
	public function testUpstreamLayoutAndImport(): void {
		require_once self::FIX . '/summary.php';
		$a = $this->createUser('zu');
		$s = $this->seed($a);
		$upstream = json_decode((string)file_get_contents(self::FIX . '/upstream.json'), true);
		$paths = ['folder' => 'api_export_folder.php?folder_id=' . $s['folders']['F1'], 'entries' => 'api_export_entries.php',
			'structured' => 'api_export_structured.php', 'structured_ws' => 'api_export_structured.php?workspace=' . self::enc($s['ws']),
			'attachments' => 'api_export_attachments.php'];
		$helpers = (getenv('NN_REF_DIR') ?: '') . '/import_helpers.php';
		self::assertFileExists($helpers, 'src/import_helpers.php im Container (run-phpunit.sh)');
		// B45: Upstream exportiert Papierkorb-Notiz N6 und ihren Anhang A3 in „alle“/„Anhänge“, der Port nie
		$trash = static fn (array $list): array => array_values(array_filter($list, static fn (string $x) => !str_contains($x, '{N6}') && !str_contains($x, '{A3}') && !str_ends_with($x, '|N6')));
		foreach ($paths as $name => $path) {
			$file = self::zipFile(self::get($a, $path), $name);
			$port = nnExportSummary($file, $s['ids']);
			$want = $upstream[$name];
			self::assertSame($trash($want['files']), $port['files'], "$name: Dateiliste wie Upstream");
			self::assertSame(array_diff_key($want['frontmatter'], array_flip(['{N6}.md'])), $port['frontmatter'], "$name: Front-Matter-Schlüssel");
			self::assertSame($trash($want['manifest']), $port['manifest'], "$name: Manifest");
			self::assertStringNotContainsString('trash-a3', self::all($file), "$name: Papierkorb-Anhang nicht im ZIP");

			exec('php ' . escapeshellarg(self::FIX . '/check-import.php') . ' ' . escapeshellarg($helpers) . ' ' . escapeshellarg($file) . ' 2>&1', $out, $rc);
			self::assertSame(0, $rc, "$name: Upstream-Import liest: " . implode("\n", $out));
			$read = json_decode((string)end($out), true);
			$out = [];
			$titles = array_column($read['notes'], 'title');
			if ($name === 'structured' || $name === 'structured_ws') {
				foreach (['Html eins', 'MD zwei', 'TL', 'Html vier', 'MD fünf'] as $t) {
					self::assertContains($t, $titles, "$name: Import erkennt $t");
				}
				self::assertTrue($read['structure']['has_subfolders'], "$name: Ordnerstruktur erkannt");
				self::assertSame('Ordner Ä/Sub', $read['notes']['Ordner Ä/Sub/MD zwei.md']['folder']);
				self::assertTrue($read['notes']['Uncategorized/TL.md']['tasklist'], 'Aufgabenliste erkannt');
				self::assertSame('t3', $read['notes']['Ordner Ä/Sub/MD zwei.md']['tags']);
				self::assertCount(2, $read['attachments'], "$name: zwei Anhänge");
			}
			if ($name === 'folder') {
				self::assertSame('MD zwei', $read['notes']['Sub/MD zwei_' . $s['notes']['N2'] . '.md']['title']);
				self::assertSame(['attachments/' . array_search('A1', $s['ids']['attachments'], true) . '.png'], $read['attachments'], 'Ordner: Anhang A1');
			}
			if ($name === 'entries') {
				self::assertSame('MD zwei', $read['notes'][$s['notes']['N2'] . '.md']['title']);
				self::assertArrayNotHasKey($s['notes']['N6'] . '.html', $read['notes'], 'Papierkorb nicht exportiert');
			}
			if ($name === 'attachments') {
				self::assertSame(['poznote_attachments_metadata.json', 'index.html'], $read['skipped'], 'Manifest als Verwaltungsdatei erkannt');
				self::assertCount(2, $read['attachments']);
			}
			unlink($file);
		}
	}

	/** Done 3 (Q2 vorläufig): „alle“ nur eigene Workspaces, Workspace-Export eines lesend geteilten Workspace mit Inhalt; Papierkorb nie */
	public function testScope(): void {
		$a = $this->createUser('sa');
		$c = $this->createUser('sc');
		[$wsA] = self::newWorkspace($a, 'SA');
		[$wsC, $wsCId] = self::newWorkspace($c, 'SC');
		$own = self::newNote($a, $wsA, 'A eigen', '<p>a-eigen-text</p>');
		self::ok($a->upload("/notes/$own/attachments", 'a.txt', 'a-anhang'), 'A-Anhang');
		$cn = self::newNote($c, $wsC, 'C geteilt', '<p>c-geteilt-text</p>');
		self::ok($c->upload("/notes/$cn/attachments", 'c.txt', 'c-anhang-bytes'), 'C-Anhang');
		$gone = self::newNote($a, $wsA, 'A weg', '<p>a-weg-text</p>');
		self::ok($a->upload("/notes/$gone/attachments", 'w.txt', 'a-weg-anhang'), 'Papierkorb-Anhang');
		self::ok($a->call('DELETE', "/notes/$gone"), 'Papierkorb');
		Server::get(WorkspaceService::class)->share($c->uid, $wsCId, $a->uid, 'read');
		foreach (['api_export_entries.php', 'api_export_structured.php', 'api_export_attachments.php'] as $p) {
			$all = self::all(self::zipFile(self::get($a, $p), $p));
			self::assertStringNotContainsString('c-geteilt', $all, "$p: nur eigene Workspaces");
			self::assertStringNotContainsString('c-anhang-bytes', $all, "$p: kein geteilter Anhang");
			self::assertStringNotContainsString('a-weg', $all, "$p: kein Papierkorb (B45)");
			self::assertStringContainsString($p === 'api_export_attachments.php' ? 'a-anhang' : 'a-eigen-text', $all, "$p: eigene Daten");
		}
		// B57: gleicher Pfad aus zwei eigenen Workspaces überschreibt nicht
		[$wsA2] = self::newWorkspace($a, 'SA2');
		$d1 = self::newNote($a, $wsA, 'Doppelt', '<p>doppelt-eins</p>');
		$d2 = self::newNote($a, $wsA2, 'Doppelt', '<p>doppelt-zwei</p>');
		$e = self::entries(self::zipFile(self::get($a, 'api_export_structured.php'), 'B57'));
		$names = array_values(array_filter(array_keys($e), static fn (string $k) => str_starts_with($k, 'Uncategorized/Doppelt')));
		sort($names);
		self::assertCount(2, $names, 'B57: beide Notizen im ZIP: ' . implode(',', $names));
		self::assertContains('Uncategorized/Doppelt_' . max($d1, $d2) . '.html', $names);
		self::assertStringContainsString('doppelt-eins', implode('', $e));
		self::assertStringContainsString('doppelt-zwei', implode('', $e));
		$shared = self::all(self::zipFile(self::get($a, 'api_export_structured.php?workspace=' . self::enc($wsC)), 'strukturiert geteilt'));
		self::assertStringContainsString('c-geteilt-text', $shared, 'strukturiert mit workspace=<geteilt>: lesbarer Inhalt');
		self::assertStringContainsString('c-anhang-bytes', $shared, 'strukturiert geteilt: Anhang der exportierten Notiz');
		self::assertStringNotContainsString('a-eigen', $shared);
	}

	/** B57 (M4d-fix): Ordner „attachments“ mit Notiz „<Anhang-ID>“ (Markdown) und Anhang <ID>.md → beide im ZIP, Verweis zeigt auf den Anhang */
	public function testAttachmentFolderCollision(): void {
		$a = $this->createUser('ac');
		[$ws] = self::newWorkspace($a, 'AC');
		$top = (int)self::ok($a->call('POST', '/folders', ['folder_name' => 'Oben', 'workspace' => $ws]), 'Ordner Oben')['folder_id'];
		$sub = (int)self::ok($a->call('POST', '/folders', ['folder_name' => 'attachments', 'workspace' => $ws, 'parent_id' => $top]), 'Unterordner')['folder_id'];
		$root = (int)self::ok($a->call('POST', '/folders', ['folder_name' => 'attachments', 'workspace' => $ws]), 'Ordner oben')['folder_id'];
		$bytes = 'ANHANG-' . bin2hex(random_bytes(6));
		foreach ([[$sub, "api_export_folder.php?folder_id=$top"], [$root, 'api_export_structured.php?workspace=' . self::enc($ws)]] as [$folder, $path]) {
			$n = (int)self::ok($a->call('POST', '/notes', ['heading' => 'tmp', 'type' => 'markdown', 'content' => 'x', 'workspace' => $ws, 'folder_id' => $folder]), 'Notiz')['note']['id'];
			$att = (int)self::ok($a->upload("/notes/$n/attachments", 'a.md', $bytes, [], 'text/markdown'), 'Anhang .md')['attachment_id'];
			self::set($n, 'heading', (string)$att);
			self::set($n, 'content', "notiz-text [a](/api/v1/notes/$n/attachments/$att)");
			$e = self::entries(self::zipFile(self::get($a, $path), "B57 $path"));
			self::assertSame($bytes, $e["attachments/$att.md"] ?? null, "$path: Anhang nicht überschrieben: " . implode(',', array_keys($e)));
			$note = $e["attachments/{$att}_$n.md"] ?? '';
			self::assertStringContainsString('notiz-text', $note, "$path: Notiz mit Suffix: " . implode(',', array_keys($e)));
			self::assertStringContainsString("../attachments/$att.md", $note, "$path: Verweis auf den Anhang");
			self::set($n, 'trashed_at', time());
		}
	}

	/** Done 4 (B47): Fremd-Anhang-URL, [[C]], linked ohne Recht → nichts von C; linked auf eigene → Zielinhalt; Ziel-Auflösung ≤ 5 Abfragen */
	public function testForeignReferencesAndLinked(): void {
		$a = $this->createUser('fa');
		$c = $this->createUser('fc');
		[$ws, $wsId] = self::newWorkspace($a, 'FA');
		[$wsC] = self::newWorkspace($c, 'FC');
		$cn = self::newNote($c, $wsC, 'C-Titel-geheim', '<p>c-inhalt-geheim</p>');
		$cBytes = 'C-BYTES-' . bin2hex(random_bytes(6));
		$cAtt = (int)self::ok($c->upload("/notes/$cn/attachments", 'c.png', $cBytes, [], 'image/png'), 'C-Anhang')['attachment_id'];
		$folder = (int)self::ok($a->call('POST', '/folders', ['folder_name' => 'Fremd', 'workspace' => $ws]), 'Ordner')['folder_id'];
		$src = self::newNote($a, $ws, 'Quelle', '<p>q</p>', $folder);
		self::set($src, 'content', "<p><img src=\"/api/v1/notes/$cn/attachments/$cAtt\"> [[$cn]] <a href=\"api_attachments.php?action=download&amp;note_id=$cn&amp;attachment_id=$cAtt\">x</a></p>");
		$target = self::newNote($a, $ws, 'Eigenes Ziel', '<p>eigenes-ziel-inhalt</p>');
		$okLink = (int)self::ok($a->call('POST', '/notes', ['heading' => 'Hülle eigen', 'type' => 'linked', 'workspace' => $ws, 'linked_note_id' => $target, 'folder_id' => $folder]), 'linked eigen')['note']['id'];
		$spare = self::newNote($a, $ws, 'Platzhalter', '<p>p</p>');
		$badLink = (int)self::ok($a->call('POST', '/notes', ['heading' => 'Hülle fremd', 'type' => 'linked', 'workspace' => $ws, 'linked_note_id' => $spare, 'folder_id' => $folder]), 'linked fremd')['note']['id'];
		self::set($badLink, 'linked_note_id', $cn);
		foreach (["api_export_folder.php?folder_id=$folder", 'api_export_entries.php', 'api_export_structured.php', 'api_export_structured.php?workspace=' . self::enc($ws)] as $p) {
			$file = self::zipFile(self::get($a, $p), $p);
			$all = self::all($file);
			foreach (['C-Titel-geheim', 'c-inhalt-geheim', $cBytes] as $secret) {
				self::assertStringNotContainsString($secret, $all, "$p: nichts von C ($secret)");
			}
			self::assertStringNotContainsString("attachments/$cAtt.", implode("\n", array_keys(self::entries($file))), "$p: kein C-Anhang");
			$e = self::entries($file);
			$shell = array_values(array_filter(array_keys($e), static fn (string $k) => str_contains($k, 'Hülle fremd') || $k === "$badLink.html"));
			self::assertCount(1, $shell, "$p: leere Hülle vorhanden");
			self::assertSame('', $e[$shell[0]], "$p: Hülle ohne Inhalt");
			$good = array_values(array_filter(array_keys($e), static fn (string $k) => str_contains($k, 'Hülle eigen') || $k === "$okLink.html"));
			self::assertStringContainsString('eigenes-ziel-inhalt', $e[$good[0]], "$p: verknüpfte eigene Notiz mit Zielinhalt");
			unlink($file);
		}

		// Abfragezähler: 1 000 Notizen, davon 500 verknüpft → Export ohne N+1 (Ziel-Auflösung ≤ 5 Abfragen)
		$db = self::db();
		$now = time();
		$big = (int)self::ok($a->call('POST', '/folders', ['folder_name' => 'Viele', 'workspace' => $ws]), 'Ordner viele')['folder_id'];
		$db->beginTransaction();
		for ($i = 0; $i < 1000; $i++) {
			$qb = $db->getQueryBuilder();
			$linked = $i % 2 === 0;
			$qb->insert('nextnotes_notes')->values([
				'workspace_id' => $qb->createNamedParameter($wsId), 'folder_id' => $qb->createNamedParameter($big), 'heading' => $qb->createNamedParameter("V$i"),
				'type' => $qb->createNamedParameter($linked ? 'linked' : 'note'), 'role' => $qb->createNamedParameter('note'),
				'linked_note_id' => $qb->createNamedParameter($linked ? ($i % 4 === 0 ? $target : $cn) : null),
				'content' => $qb->createNamedParameter($linked ? '' : "<p>v$i</p>"), 'search_text' => $qb->createNamedParameter("v$i"),
				'created_by' => $qb->createNamedParameter($a->uid), 'created_at' => $qb->createNamedParameter($now), 'updated_at' => $qb->createNamedParameter($now),
			])->executeStatement();
		}
		$db->commit();
		$svc = Server::get(ZipExportService::class);
		$inner = Server::get(IDBConnection::class)->getInner();
		$before = $inner->getStats()['executed'];
		$t0 = microtime(true);
		$r = $svc->folder($a->uid, (string)$big, null);
		$secs = microtime(true) - $t0;
		$queries = $inner->getStats()['executed'] - $before;
		fclose($r['stream']);
		// requireFolder 2, Ordner 1, Liste 1, Ziele 1 + visibleWorkspaceIds 2, Größen 1, Anhänge 1, Tags 1, Inhalt 2–3, Sperre ≤ 3
		self::assertLessThanOrEqual(18, $queries, "1 000 Notizen: $queries Abfragen (kein N+1)");
		self::assertLessThan(10.0, $secs, 'Export 1 000 Notizen ≤ 10 s');
	}

	/** Done 5: fremder Ordner 404, „alle“ als B ohne A-Daten, 11. Aufruf in 60 s → 429 */
	public function testRightsAndRateLimit(): void {
		$a = $this->createUser('ra');
		$b = $this->createUser('rb');
		[$ws] = self::newWorkspace($a, 'RA');
		$folder = (int)self::ok($a->call('POST', '/folders', ['folder_name' => 'Geheim', 'workspace' => $ws]), 'Ordner')['folder_id'];
		self::newNote($a, $ws, 'A-geheim', '<p>a-geheim-text</p>', $folder);
		foreach (["api_export_folder.php?folder_id=$folder", "api_export_folder.php?folder_id=$folder&workspace=" . self::enc($ws), 'api_export_folder.php?folder_id=abc',
			'api_export_structured.php?workspace=' . self::enc($ws), 'api_export_entries.php?workspace=' . self::enc($ws)] as $p) {
			$r = self::get($b, $p);
			self::assertSame(404, $r->status, "B $p: " . $r->describe());
			self::assertStringNotContainsString('a-geheim', $r->body);
		}
		[$ws2] = self::newWorkspace($a, 'RA2');
		self::assertSame(404, self::get($a, "api_export_folder.php?folder_id=$folder&workspace=" . self::enc($ws2))->status, '?workspace= passt nicht');
		foreach (['api_export_entries.php', 'api_export_structured.php', 'api_export_attachments.php'] as $p) {
			$r = self::get($b, $p);
			self::assertStringNotContainsString('a-geheim', self::all(self::zipFile($r, "B $p")), "B $p ohne A-Daten");
		}
		$d = $this->createUser('rd');
		$codes = [];
		for ($i = 0; $i < 11; $i++) {
			$codes[] = self::get($d, 'api_export_attachments.php')->status;
		}
		self::assertSame(array_merge(array_fill(0, 10, 200), [429]), $codes, 'Rate-Limit 10/min: ' . implode(',', $codes));
	}

	/** M4d-fix: Link-Klick (Accept text/html, kein XHR) → NC-Fehlerseite mit Statuscode; fetch/XHR → JSON */
	public function testExportErrorPage(): void {
		$a = $this->createUser('ea');
		$b = $this->createUser('eb');
		[$ws] = self::newWorkspace($a, 'EA');
		$folder = (int)self::ok($a->call('POST', '/folders', ['folder_name' => 'Geheim-Ordner', 'workspace' => $ws]), 'Ordner')['folder_id'];
		$nav = $b->withHeaders([self::NAV]);
		foreach (["api_export_folder.php?folder_id=$folder", 'api_export_note.php?id=999999999&format=markdown', 'api_download_note.php?id=999999999'] as $p) {
			$r = self::get($nav, $p);
			self::assertSame(404, $r->status, "$p: " . $r->describe());
			self::assertStringStartsWith('text/html', (string)$r->header('Content-Type'), "$p: HTML");
			self::assertStringContainsString('id="nextnotes-export-error" data-status="404"', $r->body, "$p: Fehlerseite");
			self::assertStringContainsString('/apps/nextnotes/', $r->body, "$p: Zurück-Link");
			self::assertStringNotContainsString('Geheim-Ordner', $r->body);
		}
		$xhr = self::get($b->withHeaders([self::NAV, 'X-Requested-With: XMLHttpRequest']), "api_export_folder.php?folder_id=$folder");
		self::assertSame(404, $xhr->status);
		self::assertFalse(self::notJsonError($xhr), 'XHR: JSON');
		self::assertFalse(self::notJsonError(self::get($b, "api_export_folder.php?folder_id=$folder")), 'fetch (Accept JSON): JSON');
		// 413 als Seite: Middleware direkt (1 GiB lässt sich im Test nicht erzeugen)
		$req = $this->createStub(IRequest::class);
		$req->method('getHeader')->willReturnMap([['Accept', self::NAV_ACCEPT], ['X-Requested-With', '']]);
		$mw = new ApiExceptionMiddleware($req, Server::get(IFactory::class)->get('nextnotes', 'de'), Server::get(IURLGenerator::class));
		$r = $mw->afterException($this->createStub(CompatController::class), 'exportStructured', new TooLargeException('Export too large'));
		self::assertInstanceOf(TemplateResponse::class, $r);
		self::assertSame(413, $r->getStatus());
		self::assertSame('Export zu groß (max. 1 GiB).', $r->getParams()['message']);
		self::assertInstanceOf(JSONResponse::class, $mw->afterException($this->createStub(CompatController::class), 'listNotes', new TooLargeException('x')), 'andere Routen: JSON');
	}

	// JSON-Fehlerantwort {success: false}
	private static function notJsonError(Response $r): bool {
		$j = json_decode($r->body, true);
		return !is_array($j) || ($j['success'] ?? null) !== false;
	}

	/** Lead-Auflage r2: höchstens ein laufender ZIP-Export je Benutzer → 429; M4d-fix: 64-Zeichen-UID, Sperre in oc_file_locks */
	public function testOneExportPerUser(): void {
		foreach (['lo', str_repeat('l', 52)] as $role) {
			$a = $this->createUser($role);
			[$ws] = self::newWorkspace($a, 'LO');
			self::newNote($a, $ws, 'L', '<p>l</p>');
			$key = ZipExportService::lockKey($a->uid);
			self::assertLessThanOrEqual(64, strlen($key), 'Sperrschlüssel passt in oc_file_locks.key');
			// Sperre in einem eigenen Prozess wie ein laufender Export (PHPUnit nutzt eine prozesslokale Sperre)
			$code = 'require "/var/www/html/lib/base.php"; $l = \\OCP\\Server::get(\\OCP\\Lock\\ILockingProvider::class);'
				. '$l->acquireLock("' . $key . '", 2); echo "locked\\n"; fgets(STDIN); $l->releaseLock("' . $key . '", 2);';
			$proc = proc_open(['php', '-r', $code], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
			self::assertIsResource($proc);
			try {
				$line = fgets($pipes[1]);
				// stderr erst nach Fehlschlag lesen (Nebenprozess wartet sonst auf STDIN)
				self::assertSame("locked\n", $line, 'Sperre im Nebenprozess (' . strlen($a->uid) . ' Zeichen): ' . ($line === "locked\n" ? '' : stream_get_contents($pipes[2])));
				// ohne Locking-Memcache sperrt NC über die DB (DBLockingProvider)
				$qb = self::db()->getQueryBuilder();
				$row = $qb->select('lock')->from('file_locks')->where($qb->expr()->eq('key', $qb->createNamedParameter($key)))->executeQuery()->fetchOne();
				self::assertSame(-1, (int)$row, 'exklusive Sperre in oc_file_locks');
				self::assertSame(429, self::get($a, 'api_export_entries.php')->status, 'zweiter Export während eines laufenden');
				$page = self::get($a->withHeaders([self::NAV]), 'api_export_entries.php');
				self::assertSame(429, $page->status, 'Link-Klick während eines Exports');
				self::assertStringContainsString('id="nextnotes-export-error" data-status="429"', $page->body, 'Fehlerseite statt JSON');
			} finally {
				fwrite($pipes[0], "\n");
				fclose($pipes[0]);
				proc_close($proc);
			}
			self::zipFile(self::get($a, 'api_export_entries.php'), 'nach Ende wieder möglich (' . strlen($a->uid) . ' Zeichen)');
		}
	}

	/** Done 7: Summe > 1 GiB (Mapper liefert 1 GiB + 1) → 413, kein Temp-Ordner */
	public function testSizeLimitBeforeTempFolder(): void {
		$a = $this->createUser('gl');
		[$ws] = self::newWorkspace($a, 'GL');
		self::newNote($a, $ws, 'G', '<p>g</p>');
		$huge = new class(Server::get(IDBConnection::class)) extends NoteMapper {
			public function contentBytes(array $ids): array {
				return array_fill_keys($ids, intdiv(ExportArchive::MAX_EXPORT_BYTES + 1, max(1, count($ids))) + 1);
			}
		};
		$svc = new ZipExportService(Server::get(AccessService::class), Server::get(WorkspaceService::class), $huge, Server::get(NoteTagMapper::class),
			Server::get(FolderMapper::class), Server::get(AttachmentService::class), Server::get(HtmlSanitizer::class), Server::get(ExportService::class),
			Server::get(ExportArchive::class), Server::get(ILockingProvider::class), Server::get(IDateTimeZone::class));
		$before = self::tempDirs();
		try {
			$svc->entries($a->uid, null);
			self::fail('413 erwartet');
		} catch (TooLargeException $e) {
			self::assertSame(413, $e->getStatus());
		}
		self::assertSame($before, self::tempDirs(), 'kein Temp-Ordner angelegt');
		// nach jedem Export liegt nichts mehr im Temp-Verzeichnis
		self::zipFile(self::get($a, 'api_export_structured.php'), 'strukturiert');
		self::assertSame($before, self::tempDirs(), 'Export räumt Temp-Ordner auf');
	}

	/** Done 8: Aufräumjob löscht nur Export-Ordner, die länger als die Schwelle unverändert sind; fremde oc_tmp_* bleiben */
	public function testCleanupJob(): void {
		$base = rtrim((string)Server::get(ITempManager::class)->getTempBaseDir(), '/');
		$tag = bin2hex(random_bytes(4));
		$old = "$base/oc_tmp_old$tag." . ExportArchive::TEMP_POSTFIX;
		$fresh = "$base/oc_tmp_new$tag." . ExportArchive::TEMP_POSTFIX;
		$busy = "$base/oc_tmp_busy$tag." . ExportArchive::TEMP_POSTFIX;
		$foreign = "$base/oc_tmp_foreign$tag";
		$now = time();
		foreach ([$old => -7200, $fresh => -600, $busy => -7200, $foreign => -7200] as $dir => $age) {
			mkdir($dir, 0700);
			file_put_contents("$dir/s1", 'x');
			touch("$dir/s1", $now + $age);
			touch($dir, $now + $age);
		}
		// alter Ordner, in den gerade geschrieben wird (laufender Export)
		file_put_contents("$busy/s2", 'y');
		touch($busy, $now - 7200);
		self::assertGreaterThanOrEqual(3600, ExportTempCleanupJob::threshold());
		Server::get(ExportTempCleanupJob::class)->sweep($now);
		clearstatcache();
		self::assertDirectoryDoesNotExist($old, 'alter Export-Ordner gelöscht');
		self::assertDirectoryExists($fresh, 'junger Export-Ordner bleibt');
		self::assertDirectoryExists($busy, 'laufender Export bleibt');
		self::assertDirectoryExists($foreign, 'fremder oc_tmp_* bleibt');
		foreach ([$fresh, $busy, $foreign] as $dir) {
			ExportArchive::removeDir($dir);
		}
	}

	/** M4c-r2 Nacharbeit: Fehler einer inneren Ebene löscht nur die eigene neue Datei; Löschaufträge der offenen äußeren Transaktion laufen nicht */
	public function testNestedRollbackKeepsOuterQueue(): void {
		$a = $this->createUser('nt');
		[$ws] = self::newWorkspace($a, 'NT');
		$n = self::newNote($a, $ws, 'NT', '<p>x</p>');
		$keep = (int)self::ok($a->upload("/notes/$n/attachments", 'keep.txt', 'bleibt'), 'Anhang')['attachment_id'];
		$tx = Server::get(Transaction::class);
		$svc = Server::get(AttachmentService::class);
		$newId = null;
		try {
			$tx->run(function () use ($tx, $svc, $a, $n, $keep, &$newId): void {
				// äußere Ebene: Anhang löschen → Datei-Löschung wartet auf den äußeren Commit
				$svc->destroy($a->uid, $n, $keep, null);
				try {
					$tx->run(function () use ($svc, $a, $n, &$newId): void {
						$newId = (int)$svc->createFromString($n, 'neu.txt', 'text/plain', 'neu', $a->uid)->getId();
						throw new \RuntimeException('innen');
					});
				} catch (\RuntimeException) {
				}
				self::assertNotContains((string)$newId, self::files($n), 'innere neue Datei sofort entfernt');
				self::assertContains((string)$keep, self::files($n), 'Löschauftrag der äußeren Ebene noch nicht ausgeführt');
				throw new \LogicException('außen');
			});
		} catch (\LogicException) {
		}
		self::assertContains((string)$keep, self::files($n), 'äußerer Rollback: Datei zur wiederhergestellten Zeile bleibt');
		self::assertSame(200, $a->call('GET', "/notes/$n/attachments/$keep")->status);
	}

	/** @return string[] */
	private static function files(int $noteId): array {
		$cfg = Server::get(IConfig::class);
		$dir = rtrim($cfg->getSystemValueString('datadirectory'), '/') . '/appdata_' . $cfg->getSystemValueString('instanceid') . '/nextnotes/attachments/' . $noteId;
		clearstatcache();
		return is_dir($dir) ? array_values(array_diff((array)scandir($dir), ['.', '..'])) : [];
	}
}
