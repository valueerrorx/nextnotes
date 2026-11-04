<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Scenario;

use OCA\NextNotes\Service\ConversionService;
use OCA\NextNotes\Service\Convert\HtmlToMarkdown;
use OCA\NextNotes\Service\Convert\MarkdownRenderer;
use OCA\NextNotes\Service\Convert\MediaTags;
use OCA\NextNotes\Service\HtmlSanitizer;
use OCA\NextNotes\Service\WorkspaceService;
use OCA\NextNotes\Tests\Support\ApiClient;
use OCA\NextNotes\Tests\Support\ApiTestCase;
use OCP\IConfig;
use OCP\Server;

// Plan M4 §M4b: Umwandlung (Parität, Grenzen, Rechte), Base64-Bilder → Anhänge, Drop-Import (B44), Kanban-Links, Snapshot-Vorschau
final class ConversionTest extends ApiTestCase {
	private const APP = '/index.php/apps/nextnotes/';
	private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

	protected function tearDown(): void {
		self::occ('config:app:delete', 'nextnotes', 'attachment_max_file_mb');
		parent::tearDown();
	}

	private static function occ(string ...$args): int {
		exec('php /var/www/html/occ ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $out, $rc);
		return $rc;
	}

	private static function fixtures(): array {
		$dir = dirname(__DIR__, 2) . '/fixtures/convert';
		return [require $dir . '/corpus.php', json_decode((string)file_get_contents($dir . '/expected.json'), true, 512, JSON_THROW_ON_ERROR)];
	}

	private static function attachmentRows(int $noteId): int {
		$qb = self::db()->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from('nextnotes_attachments')->where($qb->expr()->eq('note_id', $qb->createNamedParameter($noteId)));
		return (int)$qb->executeQuery()->fetchOne();
	}

	/** @return string[] Dateien der Notiz in IAppData */
	private static function appDataFiles(int $noteId): array {
		$cfg = Server::get(IConfig::class);
		$dir = rtrim($cfg->getSystemValueString('datadirectory'), '/') . '/appdata_' . $cfg->getSystemValueString('instanceid') . '/nextnotes/attachments/' . $noteId;
		return is_dir($dir) ? array_values(array_diff((array)scandir($dir), ['.', '..'])) : [];
	}

	private static function note(ApiClient $c, int $id): array {
		return self::ok($c->call('GET', "/notes/$id"), "GET /notes/$id")['note'];
	}

	/** Port + Sanitizer liefert dasselbe wie Upstream-Parser + Sanitizer (Korpus, expected.json aus gen-expected.php) */
	public function testPortMatchesUpstreamCorpus(): void {
		[$corpus, $expected] = self::fixtures();
		$san = Server::get(HtmlSanitizer::class);
		// gen-expected.php läuft ohne t() und mit Upstream-iframe-Liste (youtube)
		MarkdownRenderer::setTranslator(null);
		MediaTags::setIframeHostCheck(static fn (string $h): bool => in_array($h, ['youtube.com', 'www.youtube.com'], true));
		$checked = 0;
		foreach ($corpus as [$kind, $name, $input]) {
			self::assertArrayHasKey($name, $expected, "expected.json ohne $name");
			$got = match ($kind) {
				'md' => MarkdownRenderer::parseMarkdownForRichText($input),
				'html' => HtmlToMarkdown::poznoteHtmlToMarkdown($input),
				default => ConversionService::noteToMarkdown($input),
			};
			self::assertSame($expected[$name], $got, "$kind $name");
			// mit Sanitizer (Auslieferung) ebenfalls gleich
			if ($kind === 'md') {
				self::assertSame($san->sanitizeHtml($expected[$name]), $san->sanitizeHtml($got), "$kind $name sanitisiert");
			}
			$checked++;
		}
		MediaTags::setIframeHostCheck(null);
		self::assertGreaterThan(50, $checked);
	}

	/** Grenzfall-Korpus an der Grenze im Container: je ≤ 1,5 s je HTTP-Anfrage (Umwandlung + Sanitizer ≤ 1 s); Grenze + 1 → 413 */
	public function testLimitsAndWorstCaseRuntime(): void {
		$cases = require dirname(__DIR__, 2) . '/fixtures/convert/limits.php';
		$a = $this->createUser('cl');
		$max = ConversionService::MAX_RENDER_INPUT;
		$slowest = ['', 0.0];
		foreach ($cases as $name => [$kind, $gen]) {
			$input = $gen($max);
			$t = microtime(true);
			$r = $a->call('POST', $kind === 'md' ? '/convert-markdown' : '/convert-html', [$kind === 'md' ? 'markdown' : 'html' => $input]);
			$dt = microtime(true) - $t;
			self::assertContains($r->status, [200, 413], "$name: " . $r->describe());
			self::assertLessThan(1.5, $dt, "$name dauerte " . round($dt, 2) . ' s');
			$slowest = $dt > $slowest[1] ? [$name, $dt] : $slowest;
		}
		fwrite(STDERR, sprintf("\nM4b langsamster Grenzfall bei %d KiB: %s %.2f s\n", intdiv($max, 1024), $slowest[0], $slowest[1]));
		$over = str_repeat('a', $max + 1);
		self::assertSame(413, $a->call('POST', '/convert-markdown', ['markdown' => $over])->status, 'convert-markdown über Grenze');
		self::assertSame(413, $a->call('POST', '/convert-html', ['html' => $over])->status, 'convert-html über Grenze');
		self::assertSame(400, $a->call('POST', '/convert-markdown', ['markdown' => ' '])->status, 'leer');

		[$ws] = self::newWorkspace($a, 'Lim');
		$md = (int)self::ok($a->call('POST', '/notes', ['heading' => 'Groß', 'type' => 'markdown', 'content' => $over, 'workspace' => $ws]), 'große MD-Notiz')['note']['id'];
		self::assertSame(413, $a->call('POST', "/notes/$md/convert", ['target' => 'html'])->status, 'Notiz umwandeln über Grenze');
		self::assertSame('markdown', self::note($a, $md)['type'], 'Typ nach 413 unverändert');
		$key = self::ok($a->call('POST', "/notes/$md/snapshot", ['manual' => true]), 'Snapshot')['snapshot_key'];
		self::assertSame(413, $a->call('GET', "/notes/$md/snapshot" . self::q(['snapshot_key' => $key, 'render' => 1]))->status, 'Snapshot render über Grenze');
		self::assertTrue($a->call('GET', "/notes/$md/snapshot" . self::q(['snapshot_key' => $key]))->ok(), 'Snapshot ohne render');
	}

	/** Endpunkte: Sanitizer greift, interne Links in Client-Form, Snapshot-Vorschau */
	public function testConvertEndpointsSanitize(): void {
		$a = $this->createUser('ce');
		$html = self::ok($a->call('POST', '/convert-markdown', ['markdown' => "# T\n\n<script>alert(1)</script>\n\n[x](javascript:alert(1)) <img src=x onerror=alert(2)>\n\n![b](/api/v1/notes/5/attachments/7)"]), 'convert-markdown')['html'];
		self::assertStringContainsString('<h1>T</h1>', $html);
		foreach (['<script', 'href="javascript:', '<img src=x', 'onerror="'] as $bad) {
			self::assertStringNotContainsString($bad, $html, "Sanitizer: $bad");
		}
		self::assertStringContainsString('/apps/nextnotes/api/v1/notes/5/attachments/7', $html, 'Anhang-URL in Client-Form');
		$md = self::ok($a->call('POST', '/convert-html', ['html' => '<h2>T</h2><p><a href="javascript:alert(1)">x</a> <b>b</b></p><script>alert(1)</script>']), 'convert-html')['markdown'];
		self::assertStringContainsString('## T', $md);
		self::assertStringContainsString('**b**', $md);
		self::assertStringNotContainsString('<script', $md);
		// javascript:-Ziel bleibt Markdown-Text (wie Upstream); beim Rendern entfernt
		self::assertStringNotContainsString('href="javascript', self::ok($a->call('POST', '/convert-markdown', ['markdown' => $md]), 'Rückweg')['html']);

		[$ws] = self::newWorkspace($a, 'Snap');
		$n = (int)self::ok($a->call('POST', '/notes', ['heading' => 'M', 'type' => 'markdown', 'content' => "**fett** <img src=x onerror=alert(1)>", 'workspace' => $ws]), 'MD')['note']['id'];
		$key = self::ok($a->call('POST', "/notes/$n/snapshot", ['manual' => true]), 'Snapshot')['snapshot_key'];
		$snap = self::ok($a->call('GET', "/notes/$n/snapshot" . self::q(['snapshot_key' => $key, 'render' => 1])), 'render=1')['snapshot'];
		self::assertStringContainsString('<strong>fett</strong>', $snap['html']);
		self::assertStringNotContainsString('onerror', $snap['html']);
	}

	/** Notiz umwandeln: beide Richtungen, Snapshot vorher, Rechte (fremd 404, nur lesbar 403), falscher Typ 400 */
	public function testConvertNoteRoundTripAndRights(): void {
		$a = $this->createUser('cn');
		$b = $this->createUser('cb');
		[$ws, $wsId] = self::newWorkspace($a, 'Conv');
		$n = self::newNote($a, $ws, 'H', '<h2>Titel</h2><p><strong>fett</strong> <a href="https://example.org">l</a></p><ul><li>eins</li></ul>');
		$before = count(self::ok($a->call('GET', "/notes/$n/snapshots"), 'snapshots')['snapshots']);

		self::assertSame(404, $b->call('POST', "/notes/$n/convert", ['target' => 'markdown'])->status, 'fremd');
		Server::get(WorkspaceService::class)->share($a->uid, $wsId, $b->uid, 'read');
		self::assertSame(403, $b->call('POST', "/notes/$n/convert", ['target' => 'markdown'])->status, 'nur lesbar');
		self::assertSame(400, $a->call('POST', "/notes/$n/convert", ['target' => 'html'])->status, 'HTML → HTML');
		self::assertSame(400, $a->call('POST', "/notes/$n/convert", ['target' => 'pdf'])->status, 'ungültiges Ziel');

		$r = self::ok($a->call('POST', "/notes/$n/convert", ['target' => 'markdown']), '→ Markdown');
		self::assertSame('markdown', $r['type']);
		$note = self::note($a, $n);
		self::assertSame('markdown', $note['type']);
		self::assertStringContainsString('## Titel', $note['content']);
		self::assertStringContainsString('**fett**', $note['content']);
		self::assertStringContainsString('[l](https://example.org)', $note['content']);
		self::assertGreaterThan($before, count(self::ok($a->call('GET', "/notes/$n/snapshots"), 'snapshots')['snapshots']), 'Snapshot vor Umwandlung');

		self::ok($a->call('POST', "/notes/$n/convert", ['target' => 'html']), '→ HTML');
		$note = self::note($a, $n);
		self::assertSame('note', $note['type']);
		self::assertStringContainsString('<h2>Titel</h2>', $note['content']);
		self::assertStringContainsString('<strong>fett</strong>', $note['content']);

		$tl = (int)self::ok($a->call('POST', '/notes', ['heading' => 'L', 'type' => 'tasklist', 'content' => '[]', 'workspace' => $ws]), 'tasklist')['note']['id'];
		self::assertSame(400, $a->call('POST', "/notes/$tl/convert", ['target' => 'markdown'])->status, 'Aufgabenliste');
	}

	/** Base64-Bilder → Anhänge (Speichern und Umwandeln); Bild über Grenze → 413 ohne neue Zeilen */
	public function testBase64ImagesBecomeAttachmentsAndRollBack(): void {
		$a = $this->createUser('cm');
		[$ws] = self::newWorkspace($a, 'B64');
		$img = '<img src="data:image/png;base64,' . self::PNG . '" alt="Punkt">';
		$n = self::newNote($a, $ws, 'Bild', '<p>x</p>');
		$r = self::ok($a->call('PATCH', "/notes/$n", ['content' => "<p>$img $img</p>"]), 'PATCH mit Base64');
		self::assertCount(1, $r['adopted_attachments'] ?? [], 'gleiches Bild einmal angelegt');
		self::assertStringContainsString('/attachments/', $r['adopted_attachments'][0]['new_url']);
		self::assertSame(1, self::attachmentRows($n));
		$content = self::note($a, $n)['content'];
		self::assertStringNotContainsString('data:image', $content);
		self::assertSame(2, substr_count($content, '/attachments/' . $r['adopted_attachments'][0]['id']));

		// Umwandeln nach Markdown: verbleibende Base64-Bilder werden Anhänge
		$m = self::newNote($a, $ws, 'Bild2', '<p>y</p>');
		$qb = self::db()->getQueryBuilder();
		$qb->update('nextnotes_notes')->set('content', $qb->createNamedParameter("<p>$img</p>"))->where($qb->expr()->eq('id', $qb->createNamedParameter($m)))->executeStatement();
		self::ok($a->call('POST', "/notes/$m/convert", ['target' => 'markdown']), 'convert mit Base64');
		self::assertSame(1, self::attachmentRows($m));
		$md = self::note($a, $m)['content'];
		self::assertMatchesRegularExpression('~!\[Punkt\]\([^)]*/notes/' . $m . '/attachments/\d+\)~', $md);

		// 3 Bilder, das 3. über attachment_max_file_mb → 413, nichts angelegt (Plan Done 5)
		self::assertSame(0, self::occ('config:app:set', 'nextnotes', 'attachment_max_file_mb', '--value=1', '--type=integer'));
		sleep(4);
		$big = '<img src="data:image/png;base64,' . base64_encode(random_bytes(1100000)) . '">';
		$k = self::newNote($a, $ws, 'Groß', '<p>z</p>');
		$gif = '<img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7">';
		$r = $a->call('PATCH', "/notes/$k", ['content' => "<p>$img $gif $big</p>"]);
		self::assertSame(413, $r->status, $r->describe());
		self::assertSame(0, self::attachmentRows($k), 'keine Zeilen nach 413');
		self::assertSame([], self::appDataFiles($k), 'keine Dateien in IAppData nach 413');
		self::assertSame('<p>z</p>', self::note($a, $k)['content']);

		// Fehler nach dem Anlegen der Anhänge (Inhalt > 16 MiB): Rollback, neue IAppData-Dateien gelöscht
		$r = $a->call('PATCH', "/notes/$k", ['content' => "<p>$img $gif</p><p>" . str_repeat('a', 16 * 1048576) . '</p>']);
		self::assertSame(413, $r->status, $r->describe());
		self::assertSame(0, self::attachmentRows($k), 'Rollback: keine Zeilen');
		self::assertSame([], self::appDataFiles($k), 'Rollback: keine Dateien');
		self::assertSame('<p>z</p>', self::note($a, $k)['content']);
	}

	/** Drop-Import: Typen, Rechte, Ordner im Workspace, Grenzen vor der Verarbeitung */
	public function testImportDroppedNotes(): void {
		$a = $this->createUser('ci');
		$b = $this->createUser('cj');
		[$ws, $wsId] = self::newWorkspace($a, 'Imp');
		[$ws2] = self::newWorkspace($a, 'Imp2');
		$f = (int)self::ok($a->call('POST', '/folders', ['folder_name' => 'Ziel', 'workspace' => $ws]), 'Ordner')['folder_id'];
		$path = self::APP . 'api_import_dropped_notes.php';
		$files = [
			['notiz.md', "---\ntitle: Aus MD\ntags: [eins, zwei]\n---\n# Kopf\n\ntext"],
			['seite.html', '<html><head><style>p{}</style></head><body><p>Hallo <script>x</script></p></body></html>'],
			['plain.txt', "Zeile 1\nZeile 2"],
			['bad.exe', 'MZ'],
		];
		$r = $a->uploadFiles($path, $files, ['workspace' => $ws, 'folder_id' => $f, 'file_count' => 4]);
		$j = self::ok($r, 'Import');
		self::assertStringContainsString('bad.exe', $j['error']);
		$notes = array_values(array_filter(self::dump($a->uid)['notes'], static fn (array $n) => (int)$n['workspace_id'] === $wsId));
		self::assertCount(3, $notes);
		$byTitle = array_column($notes, null, 'heading');
		self::assertSame('markdown', $byTitle['Aus MD']['type']);
		self::assertSame((string)$f, $byTitle['Aus MD']['folder_id']);
		self::assertContains('eins', array_column(array_filter(self::dump($a->uid)['tags'], static fn (array $t) => $t['note_id'] === $byTitle['Aus MD']['id']), 'tag'));
		foreach ($notes as $n) {
			self::assertStringNotContainsString('<script', (string)$n['content']);
			self::assertStringNotContainsString('<style', (string)$n['content']);
		}

		self::assertSame(400, $a->uploadFiles($path, [$files[0]], ['folder_id' => $f])->status, 'ohne Workspace');
		self::assertSame(400, $a->uploadFiles($path, [$files[0]], ['workspace' => $ws2, 'folder_id' => $f])->status, 'Ordner anderer Workspace');
		self::assertSame(400, $a->uploadFiles($path, [['a.zip', 'PK']], ['workspace' => $ws])->status, 'ZIP');
		$r = $a->uploadFiles($path, [$files[0]], ['workspace' => $ws, 'file_count' => 60]);
		self::assertSame(400, $r->status, 'mehr Dateien gesendet als angekommen: ' . $r->describe());
		self::assertNotSame(200, $b->uploadFiles($path, [$files[0]], ['workspace' => $ws])->status, 'fremder Workspace');
		Server::get(WorkspaceService::class)->share($a->uid, $wsId, $b->uid, 'read');
		self::assertSame(403, $b->uploadFiles($path, [$files[0]], ['workspace' => $ws])->status, 'nur lesbar');

		// 51 gezogene Dateien → 400: PHP behält nur max_file_uploads (hier 20), file_count verrät den Rest (P20).
		// Echte 51 Dateien bricht der Testcontainer (display_errors=On gibt die PHP-Warnung vor config.php aus) → simuliert mit 20.
		$many = [];
		for ($i = 0; $i < 20; $i++) {
			$many[] = ["m$i.txt", 'x'];
		}
		$count0 = count(self::dump($a->uid)['notes']);
		self::assertSame(400, $a->uploadFiles($path, $many, ['workspace' => $ws, 'file_count' => 51])->status, '51 Dateien');
		self::assertSame($count0, count(self::dump($a->uid)['notes']), '51 Dateien: nichts angelegt');

		// 5 × 13 MiB = 65 MiB > 64 MiB → 413 vor der Verarbeitung
		$count = count(self::dump($a->uid)['notes']);
		$big = [];
		for ($i = 0; $i < 5; $i++) {
			$big[] = ["g$i.txt", str_repeat('x', 13 * 1048576)];
		}
		self::assertSame(413, $a->uploadFiles($path, $big, ['workspace' => $ws, 'file_count' => 5])->status, 'Summe > 64 MiB');
		self::assertSame(413, $a->uploadFiles($path, [['h.txt', str_repeat('x', 16 * 1048576 + 1)]], ['workspace' => $ws])->status, 'Datei > 16 MiB');
		self::assertSame($count, count(self::dump($a->uid)['notes']), 'nichts angelegt');
	}

	/** Kanban-Karte: Text mit klickbaren Links (intern data-note-id, extern target=_blank), kein HTML aus dem Inhalt */
	public function testKanbanCardLinks(): void {
		$a = $this->createUser('ck');
		[$ws] = self::newWorkspace($a, 'Kan');
		$f = (int)self::ok($a->call('POST', '/folders', ['folder_name' => 'Board', 'workspace' => $ws]), 'Ordner')['folder_id'];
		$t = self::newNote($a, $ws, 'Ziel', '<p>t</p>');
		self::newNote($a, $ws, 'Karte', '<p>Siehe <a href="https://example.org/x" onclick="x()">extern</a> und <a href="index.php?note=' . $t . '" class="note-internal-link" data-note-reference="true">intern</a> <b>fett</b></p>', $f);
		$r = $a->absolute('GET', self::APP . 'kanban_content.php?ajax=1&folder_id=' . $f . '&workspace=' . self::enc($ws));
		self::assertSame(200, $r->status, $r->describe());
		self::assertMatchesRegularExpression('~<a [^>]*href="https://example.org/x"[^>]*target="_blank"[^>]*>extern</a>~', $r->body);
		self::assertMatchesRegularExpression('~<a [^>]*data-note-id="' . $t . '"[^>]*>intern</a>~', $r->body);
		self::assertStringNotContainsString('onclick="x()"', $r->body);
		self::assertStringNotContainsString('<b>fett</b>', $r->body);
	}
}
