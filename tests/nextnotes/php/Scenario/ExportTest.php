<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Scenario;

use OCA\NextNotes\Service\AttachmentService;
use OCA\NextNotes\Service\ExportService;
use OCA\NextNotes\Service\NoteService;
use OCA\NextNotes\Service\Transaction;
use OCA\NextNotes\Service\WorkspaceService;
use OCA\NextNotes\Tests\Support\ApiClient;
use OCA\NextNotes\Tests\Support\ApiTestCase;
use OCA\NextNotes\Tests\Support\Response;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Server;

// Plan M4 §M4c: Export/Download je Typ und Format, verknüpfte Notizen (B42), Fremdreferenzen (B47), Disposition (B48),
// Rechte; gebündelte MINOR aus M4b-r1 (Rollback-Dateien, Quota des Ziel-Workspace)
final class ExportTest extends ApiTestCase {
	private const APP = '/index.php/apps/nextnotes/';
	private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

	protected function tearDown(): void {
		self::occ('config:app:delete', 'nextnotes', 'attachment_quota_mb');
		parent::tearDown();
	}

	private static function occ(string ...$args): int {
		exec('php /var/www/html/occ ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $out, $rc);
		return $rc;
	}

	private static function get(ApiClient $c, string $path): Response {
		return $c->absolute('GET', self::APP . $path);
	}

	private static function export(ApiClient $c, int $id, string $format = '', string $extra = ''): Response {
		return self::get($c, "api_export_note.php?id=$id" . ($format !== '' ? "&format=$format" : '') . $extra);
	}

	private static function assertHeaders(Response $r, string $mime, string $file, string $what): void {
		self::assertSame(200, $r->status, "$what: " . $r->describe());
		self::assertStringStartsWith($mime, (string)$r->header('Content-Type'), "$what: MIME");
		self::assertStringContainsString('attachment', (string)$r->header('Content-Disposition'), "$what: attachment");
		self::assertStringContainsString($file, (string)$r->header('Content-Disposition'), "$what: Dateiname");
		self::assertSame('nosniff', $r->header('X-Content-Type-Options'), "$what: nosniff");
	}

	/** @return array<string, string> Eintrag => Inhalt */
	private static function unzip(Response $r): array {
		$path = tempnam(sys_get_temp_dir(), 'nnzip');
		file_put_contents($path, $r->body);
		$zip = new \ZipArchive();
		self::assertTrue($zip->open($path) === true, 'ZIP lesbar');
		$out = [];
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$out[(string)$zip->getNameIndex($i)] = (string)$zip->getFromIndex($i);
		}
		$zip->close();
		unlink($path);
		return $out;
	}

	private static function setContent(int $id, string $content): void {
		$qb = self::db()->getQueryBuilder();
		$qb->update('nextnotes_notes')->set('content', $qb->createNamedParameter($content))->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))->executeStatement();
	}

	/** @return string[] Dateien der Notiz in IAppData */
	private static function appDataFiles(int $noteId): array {
		$cfg = Server::get(IConfig::class);
		$dir = rtrim($cfg->getSystemValueString('datadirectory'), '/') . '/appdata_' . $cfg->getSystemValueString('instanceid') . '/nextnotes/attachments/' . $noteId;
		return is_dir($dir) ? array_values(array_diff((array)scandir($dir), ['.', '..'])) : [];
	}

	private static function attachmentRows(int $noteId): int {
		$qb = self::db()->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from('nextnotes_attachments')->where($qb->expr()->eq('note_id', $qb->createNamedParameter($noteId)));
		return (int)$qb->executeQuery()->fetchOne();
	}

	/** Done 1, 6: je Typ und Format Name, Endung, MIME, Inhalt; Markdown-ZIP mit Front Matter und Anhang; Disposition */
	public function testFormatsPerType(): void {
		$a = $this->createUser('ex');
		[$ws] = self::newWorkspace($a, 'Exp');
		$html = self::newNote($a, $ws, 'HTML Notiz', '<p>Rich Text Inhalt</p>');
		$md = (int)self::ok($a->call('POST', '/notes', ['heading' => 'MD Notiz', 'type' => 'markdown', 'content' => "# Kopf\n\n**fett** md-text\n", 'workspace' => $ws, 'tags' => ['t1']]), 'MD')['note']['id'];
		$tl = (int)self::ok($a->call('POST', '/notes', ['heading' => 'Liste', 'type' => 'tasklist', 'content' => '[{"id":1,"text":"Aufgabe eins","completed":true},{"id":2,"text":"Aufgabe zwei","completed":false}]', 'workspace' => $ws]), 'TL')['note']['id'];
		$ex = (int)self::ok($a->call('POST', '/notes', ['heading' => 'Zeichnung', 'type' => 'excalidraw', 'content' => '<p>Excalidraw Vorschau</p>', 'workspace' => $ws]), 'EX')['note']['id'];

		foreach ([[$html, 'HTML Notiz', 'Rich Text Inhalt'], [$md, 'MD Notiz', '<strong>fett</strong>'], [$tl, 'Liste', 'Aufgabe eins'], [$ex, 'Zeichnung', 'Excalidraw Vorschau']] as [$id, $title, $text]) {
			foreach (['', 'html_embedded'] as $f) {
				$r = self::export($a, $id, $f);
				self::assertHeaders($r, 'text/html', "$title.html", "$title $f");
				self::assertStringContainsString("<title>$title</title>", $r->body);
				self::assertStringContainsString($text, $r->body, "$title $f: Text");
				self::assertStringNotContainsString('<script', $r->body, "$title $f: kein Skript");
			}
		}
		$r = self::export($a, $md, 'markdown');
		self::assertHeaders($r, 'text/markdown', 'MD Notiz.md', 'MD markdown');
		self::assertStringStartsWith("---\ntitle: \"MD Notiz\"\ntags:", $r->body);
		self::assertStringContainsString("**fett** md-text", $r->body);
		$r = self::export($a, $tl, 'markdown');
		self::assertHeaders($r, 'text/markdown', 'Liste.md', 'TL markdown');
		self::assertStringContainsString('type: tasklist', $r->body);
		self::assertStringContainsString('- [x] Aufgabe eins', $r->body);
		$r = self::export($a, $tl, 'json');
		self::assertHeaders($r, 'application/json', 'Liste.json', 'TL json');
		self::assertSame('Aufgabe zwei', json_decode($r->body, true)[1]['text']);
		self::assertSame(400, self::export($a, $html, 'json')->status, 'json nur Tasklist');
		self::assertSame(400, self::export($a, $html, 'markdown')->status, 'markdown nur MD/Tasklist (B56)');
		self::assertSame(400, self::export($a, $html, 'pdf')->status, 'unbekanntes Format');
		self::assertSame(400, self::export($a, $html, 'html', '&disposition=bogus')->status, 'unbekannte Disposition');

		// Download: Markdown roh als .md, sonst formatiertes HTML (B56)
		$r = self::get($a, "api_download_note.php?id=$md");
		self::assertHeaders($r, 'text/markdown', 'MD_Notiz.md', 'Download MD');
		self::assertSame("# Kopf\n\n**fett** md-text\n", $r->body);
		foreach ([[$html, 'HTML_Notiz', 'Rich Text Inhalt'], [$tl, 'Liste', 'Aufgabe eins'], [$ex, 'Zeichnung', 'Excalidraw Vorschau']] as [$id, $name, $text]) {
			$r = self::get($a, "api_download_note.php?id=$id");
			self::assertHeaders($r, 'text/html', "$name.html", "Download $name");
			self::assertStringContainsString($text, $r->body);
		}

		// B48: inline nur für format=html (Druckansicht), sonst attachment
		foreach ([[$md, 'markdown'], [$tl, 'json'], [$html, 'html_embedded']] as [$id, $f]) {
			$r = self::export($a, $id, $f, '&disposition=inline');
			self::assertStringStartsWith('attachment', (string)$r->header('Content-Disposition'), "inline + $f");
			self::assertSame('nosniff', $r->header('X-Content-Type-Options'));
		}
		$r = self::export($a, $md, 'html', '&disposition=inline');
		self::assertSame(200, $r->status, $r->describe());
		self::assertNull($r->header('Content-Disposition'), 'Druckansicht ohne Download');
		self::assertSame('nosniff', $r->header('X-Content-Type-Options'));
		self::assertStringContainsString('id="nextnotes-print-config"', $r->body);
		self::assertStringContainsString('<h1>Kopf</h1>', $r->body);

		// Markdown mit Anhang → ZIP mit Front Matter, Datei und relativem Verweis; HTML mit Anhang → ZIP
		$att = (int)self::ok($a->upload("/notes/$md/attachments", 'bild.png', (string)base64_decode(self::PNG), [], 'image/png'), 'Anhang')['attachment_id'];
		self::ok($a->call('PATCH', "/notes/$md", ['content' => "# Kopf\n\n![Bild](/api/v1/notes/$md/attachments/$att)\n"]), 'MD mit Bild');
		$r = self::export($a, $md, 'markdown');
		self::assertHeaders($r, 'application/zip', 'MD Notiz.zip', 'MD-ZIP');
		$z = self::unzip($r);
		self::assertArrayHasKey('MD Notiz.md', $z, implode(',', array_keys($z)));
		self::assertArrayHasKey("attachments/$att.png", $z);
		self::assertSame(base64_decode(self::PNG), $z["attachments/$att.png"]);
		self::assertStringStartsWith("---\ntitle: \"MD Notiz\"", $z['MD Notiz.md']);
		self::assertStringContainsString("](attachments/$att.png)", $z['MD Notiz.md']);
		$r = self::export($a, $md, 'html_embedded');
		self::assertStringContainsString('src="data:image/png;base64,' . self::PNG . '"', $r->body, 'html_embedded: Bild als data:');
		$r = self::export($a, $md);
		self::assertHeaders($r, 'application/zip', 'MD Notiz.zip', 'HTML-ZIP');
		$z = self::unzip($r);
		self::assertArrayHasKey("attachments/$att.png", $z);
		self::assertStringContainsString("src=\"attachments/$att.png\"", $z['MD Notiz.html']);
	}

	/** Done 4 (Q2 vorläufig): fremd → 404 in allen Formaten, Lesefreigabe → 200; ?workspace= muss passen */
	public function testRights(): void {
		$a = $this->createUser('ea');
		$b = $this->createUser('eb');
		[$ws, $wsId] = self::newWorkspace($a, 'ExR');
		[$ws2] = self::newWorkspace($a, 'ExR2');
		$n = self::newNote($a, $ws, 'Geheim', '<p>geheimer-text</p>');
		$t = (int)self::ok($a->call('POST', '/notes', ['heading' => 'TL', 'type' => 'tasklist', 'content' => '[{"id":1,"text":"geheimer-text"}]', 'workspace' => $ws]), 'TL')['note']['id'];
		$paths = ["api_export_note.php?id=$n", "api_export_note.php?id=$n&format=html_embedded", "api_export_note.php?id=$n&format=html&disposition=inline",
			"api_export_note.php?id=$t&format=json", "api_export_note.php?id=$t&format=markdown", "api_download_note.php?id=$n"];
		foreach ($paths as $p) {
			$r = self::get($b, $p);
			self::assertSame(404, $r->status, "B $p: " . $r->describe());
			self::assertStringNotContainsString('geheimer-text', $r->body);
			self::assertSame(404, self::get($a, $p . '&workspace=' . self::enc($ws2))->status, "A $p mit fremdem ?workspace=");
		}
		Server::get(WorkspaceService::class)->share($a->uid, $wsId, $b->uid, 'read');
		foreach ($paths as $p) {
			$r = self::get($b, $p);
			self::assertSame(200, $r->status, "B lesend $p: " . $r->describe());
			self::assertStringContainsString('geheimer-text', $r->body);
		}
	}

	/** Done 3 (B42): verknüpfte Notiz → Ziel mit Leserecht, sonst 404; ?note=<linked> zeigt Ziel mit Rechten des Ziels */
	public function testLinkedNotes(): void {
		$a = $this->createUser('la');
		$c = $this->createUser('lc');
		[$ws] = self::newWorkspace($a, 'Lnk');
		$x = self::newNote($a, $ws, 'Ziel X', '<p>ziel-x-text</p>');
		$l = (int)self::ok($a->call('POST', '/notes', ['heading' => 'Hülle', 'type' => 'linked', 'workspace' => $ws, 'linked_note_id' => $x]), 'linked')['note']['id'];
		foreach (["api_export_note.php?id=$l", "api_export_note.php?id=$l&format=html&disposition=inline", "api_download_note.php?id=$l"] as $p) {
			$r = self::get($a, $p);
			self::assertSame(200, $r->status, "$p: " . $r->describe());
			self::assertStringContainsString('ziel-x-text', $r->body, "$p liefert Ziel");
		}
		$r = self::get($a, "index.php?workspace=" . self::enc($ws) . "&note=$l");
		self::assertSame(200, $r->status);
		self::assertStringContainsString('ziel-x-text', $r->body, '?note=<linked> zeigt Ziel');
		self::assertStringContainsString("id=\"entry$x\"", $r->body);

		// Altbestand: Verknüpfung auf C-Notiz ohne Recht → 404 ohne C-Inhalt
		[$wsC, $wsCId] = self::newWorkspace($c, 'LnkC');
		$cn = self::newNote($c, $wsC, 'C geheim', '<p>c-geheim-text</p>');
		$qb = self::db()->getQueryBuilder();
		$qb->update('nextnotes_notes')->set('linked_note_id', $qb->createNamedParameter($cn))->where($qb->expr()->eq('id', $qb->createNamedParameter($l)))->executeStatement();
		foreach (["api_export_note.php?id=$l", "api_export_note.php?id=$l&format=html&disposition=inline", "api_download_note.php?id=$l", "index.php?workspace=" . self::enc($ws) . "&note=$l"] as $p) {
			$r = self::get($a, $p);
			self::assertSame(404, $r->status, "fremdes Ziel $p: " . $r->describe());
			self::assertStringNotContainsString('c-geheim', $r->body);
		}
		self::assertSame(404, self::get($a, 'index.php?note=' . $l)->status);

		// Ziel nur lesbar: Ansicht Nur-Lesen, PATCH auf das Ziel → 403
		Server::get(WorkspaceService::class)->share($c->uid, $wsCId, $a->uid, 'read');
		$r = self::get($a, "index.php?workspace=" . self::enc($ws) . "&note=$l");
		self::assertSame(200, $r->status, $r->describe());
		self::assertStringContainsString('c-geheim-text', $r->body);
		self::assertMatchesRegularExpression('~id="entry' . $cn . '"[^>]*data-nextnotes-readonly="true"~', $r->body, 'Ziel nur lesbar');
		self::assertSame(403, $a->call('PATCH', "/notes/$cn", ['content' => '<p>überschrieben</p>'])->status);
	}

	/** Done 5 (B47): fremde Anhänge/[[…]] nie eingebettet, nur eigene; Auflösung von 50 [[…]] ≤ 3 Abfragen */
	public function testForeignReferences(): void {
		$a = $this->createUser('ra');
		$c = $this->createUser('rc');
		[$ws] = self::newWorkspace($a, 'Ref');
		[$wsC] = self::newWorkspace($c, 'RefC');
		$cn = self::newNote($c, $wsC, 'C-Titel-geheim', '<p>c</p>');
		$cBytes = 'C-BYTES-' . bin2hex(random_bytes(8));
		$cAtt = (int)self::ok($c->upload("/notes/$cn/attachments", 'c.png', $cBytes, [], 'image/png'), 'C-Anhang')['attachment_id'];
		$own = self::newNote($a, $ws, 'Eigenes Ziel', '<p>o</p>');
		$n = (int)self::ok($a->call('POST', '/notes', ['heading' => 'Quelle', 'type' => 'markdown', 'content' => 'x', 'workspace' => $ws]), 'MD')['note']['id'];
		$aAtt = (int)self::ok($a->upload("/notes/$n/attachments", 'a.png', (string)base64_decode(self::PNG), [], 'image/png'), 'A-Anhang')['attachment_id'];
		$md = "![c](/api/v1/notes/$cn/attachments/$cAtt)\n\n[dl](api_attachments.php?action=download&note_id=$cn&attachment_id=$cAtt)\n\n[[$cn]]\n\n"
			. "![a](/api/v1/notes/$n/attachments/$aAtt)\n\n[[$own]] [[Eigenes Ziel]]\n";
		self::setContent($n, $md);
		$cB64 = base64_encode($cBytes);
		foreach (['html_embedded', 'html', 'markdown'] as $f) {
			$r = self::export($a, $n, $f);
			self::assertSame(200, $r->status, "$f: " . $r->describe());
			$all = str_starts_with((string)$r->header('Content-Type'), 'application/zip') ? implode("\n", array_keys($z = self::unzip($r))) . implode("\n", $z) : $r->body;
			self::assertStringNotContainsString('C-Titel-geheim', $all, "$f: C-Titel");
			self::assertStringNotContainsString($cBytes, $all, "$f: C-Bytes");
			self::assertStringNotContainsString($cB64, $all, "$f: C-Bytes base64");
			self::assertStringNotContainsString("attachments/$cAtt.", $all, "$f: C-Anhang im ZIP");
			if ($f === 'html_embedded') {
				self::assertStringContainsString('data:image/png;base64,' . self::PNG, $all, 'eigenes Bild eingebettet');
			}
			if ($f !== 'markdown') {
				self::assertStringContainsString('note-link-broken', $all, "$f: [[C]] nicht verfügbar");
				self::assertStringContainsString(">Eigenes Ziel</a>", $all, "$f: eigene Referenz mit Titel");
			}
		}

		// Abfragezähler: 50 [[…]] (Zahlen und Titel) kosten höchstens 3 Abfragen mehr als keine
		$targets = [];
		for ($i = 0; $i < 25; $i++) {
			$targets[] = self::newNote($a, $ws, "Ref $i", '<p>r</p>');
		}
		$refs = implode(' ', array_map(static fn (int $id) => "[[$id]]", $targets)) . ' ' . implode(' ', array_map(static fn (int $i) => "[[Ref $i]]", range(0, 24)));
		$with = self::newNote($a, $ws, 'Viele', "<p>$refs</p>");
		$without = self::newNote($a, $ws, 'Keine', '<p>ohne</p>');
		self::setContent($with, "<p>$refs</p>");
		$svc = Server::get(ExportService::class);
		$count = static function (int $id) use ($svc, $a): int {
			$inner = Server::get(IDBConnection::class)->getInner();
			$before = $inner->getStats()['executed'];
			$svc->export($a->uid, (string)$id, null, 'html', null);
			return $inner->getStats()['executed'] - $before;
		};
		$count($without);
		// Schlüssel des Workspaces (einmal je Request, nicht je Referenz) vorab berechnen
		Server::get(\OCA\NextNotes\Service\WorkspaceService::class)->keyFor($a->uid, (int)Server::get(\OCA\NextNotes\Db\NoteMapper::class)->find($with)->getWorkspaceId());
		$base = $count($without);
		$many = $count($with);
		self::assertLessThanOrEqual(3, $many - $base, "50 Referenzen: $many statt $base Abfragen");
		$body = $svc->export($a->uid, (string)$with, null, 'html', null)['body'];
		self::assertSame(50, substr_count($body, 'class="note-internal-link"'), 'alle 50 aufgelöst');
	}

	/** M4c-r1: Titelreferenzen über search_text-Kandidaten: Groß/Klein und Akzente gefaltet, LIKE-Zeichen wörtlich, Papierkorb/fremd nicht */
	public function testTitleReferencesMatchFoldedOnly(): void {
		$a = $this->createUser('tr');
		[$ws] = self::newWorkspace($a, 'Tr');
		[$other] = self::newWorkspace($a, 'Tr2');
		$hit = self::newNote($a, $ws, 'Ärger Über', '<p>inhalt</p>');
		self::newNote($a, $ws, 'Rx', '<p>r</p>');
		self::newNote($a, $other, 'Fremd', '<p>f</p>');
		$gone = self::newNote($a, $ws, 'Weg', '<p>w</p>');
		self::ok($a->call('DELETE', "/notes/$gone"), 'Papierkorb');
		$src = self::newNote($a, $ws, 'Quelle', '<p>q</p>');
		self::setContent($src, '<p>[[ärger über]] [[R%]] [[Fremd]] [[Weg]]</p>');
		$body = Server::get(ExportService::class)->export($a->uid, (string)$src, null, 'html', null)['body'];
		self::assertStringContainsString('note=' . $hit, $body, 'gefaltet gefunden');
		self::assertSame(1, substr_count($body, 'class="note-internal-link"'), 'nur ein Treffer: ' . $body);
		self::assertSame(3, substr_count($body, 'class="note-internal-link note-link-broken"'), 'R%, Fremd, Papierkorb nicht aufgelöst');
	}

	/** M4b-r1 MINOR 1: Rollback der äußeren Transaktion löscht neu angelegte IAppData-Dateien */
	public function testOuterRollbackRemovesEmbeddedFiles(): void {
		$a = $this->createUser('rb');
		[$ws] = self::newWorkspace($a, 'Rb');
		$n = self::newNote($a, $ws, 'Rb', '<p>x</p>');
		$img = '<img src="data:image/png;base64,' . self::PNG . '">';
		try {
			Server::get(Transaction::class)->run(function () use ($a, $n, $img): void {
				Server::get(NoteService::class)->update($a->uid, $n, ['content' => "<p>$img</p>"], '', '');
				self::assertCount(1, self::appDataFiles($n), 'Datei in der Transaktion angelegt');
				throw new \RuntimeException('äußerer Fehler');
			});
			self::fail('Ausnahme erwartet');
		} catch (\RuntimeException $e) {
			self::assertSame('äußerer Fehler', $e->getMessage());
		}
		self::assertSame(0, self::attachmentRows($n));
		self::assertSame([], self::appDataFiles($n), 'Rollback: Datei gelöscht');
		self::assertSame('<p>x</p>', self::ok($a->call('GET', "/notes/$n"), 'GET')['note']['content']);
	}

	/** M4c-r1: committete Unter-Transaktion gehört zur äußeren Ebene; Rollback eines Geschwisters löscht ihre Dateien nicht */
	public function testSiblingRollbackKeepsCommittedFiles(): void {
		$a = $this->createUser('rs');
		[$ws] = self::newWorkspace($a, 'Rs');
		$n = self::newNote($a, $ws, 'Rs', '<p>x</p>');
		$img = '<img src="data:image/png;base64,' . self::PNG . '">';
		$tx = Server::get(Transaction::class);
		$tx->run(function () use ($a, $n, $img, $tx): void {
			Server::get(NoteService::class)->update($a->uid, $n, ['content' => "<p>$img</p>"], '', '');
			try {
				$tx->run(static function (): void {
					throw new \RuntimeException('Geschwister');
				});
			} catch (\RuntimeException) {
			}
		});
		self::assertSame(1, self::attachmentRows($n));
		self::assertCount(1, self::appDataFiles($n), 'Datei der committeten Unter-Transaktion bleibt');
	}

	/** M4c-r1: Löschauftrag einer zurückgerollten inneren Ebene entfällt, Zeile und Datei bleiben */
	public function testInnerRollbackDropsQueuedDeletion(): void {
		$a = $this->createUser('rq');
		[$ws] = self::newWorkspace($a, 'Rq');
		$n = self::newNote($a, $ws, 'Rq', '<p>x</p>');
		$att = (int)self::ok($a->upload("/notes/$n/attachments", 'q.txt', 'bleibt'), 'Anhang')['attachment_id'];
		$tx = Server::get(Transaction::class);
		$tx->run(function () use ($a, $n, $att, $tx): void {
			try {
				$tx->run(function () use ($a, $n, $att): void {
					Server::get(AttachmentService::class)->destroy($a->uid, $n, $att, null);
					throw new \RuntimeException('innen');
				});
			} catch (\RuntimeException) {
			}
		});
		self::assertSame(1, self::attachmentRows($n));
		self::assertSame([(string)$att], self::appDataFiles($n), 'Datei bleibt zur wiederhergestellten Zeile');
	}

	/** M4b-r1 MINOR 2: PATCH mit Base64-Bild und Workspace-Wechsel prüft die Quota des Ziel-Workspace */
	public function testEmbeddedImageQuotaUsesTargetWorkspace(): void {
		$a = $this->createUser('qa');
		$b = $this->createUser('qb');
		[$wsA] = self::newWorkspace($a, 'QA');
		[$wsB, $wsBId] = self::newWorkspace($b, 'QB');
		Server::get(WorkspaceService::class)->share($b->uid, $wsBId, $a->uid, 'write');
		self::assertSame(0, self::occ('config:app:set', 'nextnotes', 'attachment_quota_mb', '--value=1', '--type=integer'));
		sleep(4);
		$fill = self::newNote($b, $wsB, 'Voll', '<p>v</p>');
		self::ok($b->upload("/notes/$fill/attachments", 'voll.bin', str_repeat('v', 800000)), 'B-Quota füllen');
		$big = '<img src="data:image/png;base64,' . base64_encode("\x89PNG\r\n\x1a\n" . random_bytes(400000)) . '">';
		$n = self::newNote($a, $wsA, 'Wandert', '<p>w</p>');
		$r = $a->call('PATCH', "/notes/$n", ['content' => "<p>$big</p>", 'workspace' => $wsB]);
		self::assertSame(413, $r->status, 'Quota von B zählt: ' . $r->describe());
		self::assertSame(0, self::attachmentRows($n));
		self::assertSame([], self::appDataFiles($n));
		$r = $a->call('PATCH', "/notes/$n", ['content' => "<p>$big</p>"]);
		self::assertTrue($r->ok(), 'eigener Workspace hat Platz: ' . $r->describe());
	}
}
