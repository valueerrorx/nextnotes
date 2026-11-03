<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Security;

use OCA\NextNotes\Tests\Support\ApiClient;
use OCA\NextNotes\Tests\Support\ApiTestCase;
use OCA\NextNotes\Tests\Support\Response;

// M3c2 Nacharbeit r2 (Speccritic): Sanitizer ohne CPU-DoS (tiefe Verschachtelung), Inhalts-Obergrenze 16 MiB → 413,
// tiefe Verschachtelung vollständig erhalten (413 nur bei libxml-Abbruch), Markdown-Kommentar/Rawtext-Umgehung, ungültiges UTF-8 in Titel/Tags → 400.
final class SanitizerB38Test extends ApiTestCase {
	private const MAX_CONTENT = 16 * 1024 * 1024;
	private const MAX_SECONDS = 3.0;

	private static function content(ApiClient $c, int $note): string {
		return (string)(self::ok($c->call('GET', "/notes/$note"), 'GET Notiz')['note']['content'] ?? '');
	}

	/** PATCH mit Zeitmessung; gibt [Antwort, Sekunden] */
	private static function timedPatch(ApiClient $c, int $n, string $content, string $ws): array {
		$t = microtime(true);
		$r = $c->call('PATCH', "/notes/$n", ['content' => $content, 'workspace' => $ws]);
		return [$r, microtime(true) - $t];
	}

	private static function brief(Response $r): string {
		return $r->status . ' ' . substr($r->body, 0, 200);
	}

	/** 413 muss von der App kommen (JSON-Body), nicht vom Webserver-Limit */
	private static function assertApp413(Response $r, string $what): void {
		self::assertSame(413, $r->status, "$what: " . self::brief($r));
		self::assertIsArray(json_decode($r->body, true), "$what: 413 ohne JSON-Body (Webserver-Limit statt App?): " . self::brief($r));
	}

	public function testDeeplyNestedHtml5MbIsFast(): void {
		$a = $this->createUser('a');
		[$ws] = self::newWorkspace($a, 'Tief');
		$n = self::newNote($a, $ws, 'Tief', '<p>x</p>');
		// (a) viele Blöcke mit Tiefe 2000 (innerhalb der Grenze), ≈ 5 MB
		$block = str_repeat('<span>', 2000) . 'x' . str_repeat('</span>', 2000);
		$html = str_repeat($block, intdiv(5 * 1024 * 1024, strlen($block)) + 1);
		[$r, $s] = self::timedPatch($a, $n, $html, $ws);
		fwrite(STDERR, sprintf("\n[B38] 5 MB, Tiefe 2000 in Blöcken: HTTP %d in %.2f s\n", $r->status, $s));
		self::assertContains($r->status, [200, 413], 'Tiefe 2000 ×N: ' . self::brief($r));
		self::assertLessThan(self::MAX_SECONDS, $s, sprintf('Tiefe 2000 ×N: %.2f s', $s));
		// (b) 5 MB ungeschlossene <span> (Tiefe ≈ 870 000) → 413, schnell
		$before = self::content($a, $n);
		$html = str_repeat('<span>', intdiv(5 * 1024 * 1024, 6)) . 'x';
		[$r, $s] = self::timedPatch($a, $n, $html, $ws);
		fwrite(STDERR, sprintf("[B38] 5 MB ungeschlossene <span>: HTTP %d in %.2f s\n", $r->status, $s));
		self::assertApp413($r, 'ungeschlossen tief');
		self::assertLessThan(self::MAX_SECONDS, $s, sprintf('ungeschlossen tief: %.2f s', $s));
		self::assertSame($before, self::content($a, $n), 'ungeschlossen tief: Inhalt verändert');
		// (c) 5 MB geschlossen, durchgehend verschachtelt (Tiefe ≈ 400 000) → vollständig erhalten oder 413 (nur wenn libxml abbricht), schnell
		$d = intdiv(5 * 1024 * 1024, 13);
		[$r, $s] = self::timedPatch($a, $n, str_repeat('<span>', $d) . 'x' . str_repeat('</span>', $d), $ws);
		fwrite(STDERR, sprintf("[B38] 5 MB eine Kette <span>…</span>: HTTP %d in %.2f s\n", $r->status, $s));
		self::assertLessThan(self::MAX_SECONDS, $s, sprintf('Kette tief: %.2f s', $s));
		self::assertDeepKept($r, $a, $n, $before, $d, 'x', 'Kette tief');
	}

	// knapp unter 16 MiB nur ungeschlossene Kurz-Tags: libxml sammelt je Tag einen Fehler → Speicher; erwartet 200/413 schnell, nie 500
	public function testManyUnclosedTagsBelowLimitNo500(): void {
		$a = $this->createUser('a');
		[$ws] = self::newWorkspace($a, 'Viele');
		$n = self::newNote($a, $ws, 'Viele', '<p>bleibt</p>');
		foreach (['<b>' => 3, '<i>' => 3, '<p>' => 3] as $tag => $len) {
			$html = str_repeat($tag, intdiv(self::MAX_CONTENT - 1024, $len));
			[$r, $s] = self::timedPatch($a, $n, $html, $ws);
			fwrite(STDERR, sprintf("\n[B38] %.1f MiB ungeschlossene %s: HTTP %d in %.2f s", strlen($html) / 1048576, $tag, $r->status, $s));
			self::assertContains($r->status, [200, 413], "$tag ×" . strlen($html) / $len . ': ' . self::brief($r));
			self::assertLessThan(self::MAX_SECONDS * 3, $s, sprintf('%s: %.2f s', $tag, $s));
			if ($r->status === 413) {
				self::assertApp413($r, $tag);
			}
		}
		fwrite(STDERR, "\n");
	}

	public function testContentOverLimitIs413Unchanged(): void {
		$a = $this->createUser('a');
		[$ws] = self::newWorkspace($a, 'Groß');
		$n = self::newNote($a, $ws, 'Groß', '<p>bleibt</p>');
		$md = (int)self::ok($a->call('POST', '/notes', ['heading' => 'MD', 'type' => 'markdown', 'content' => 'bleibt', 'workspace' => $ws]), 'MD')['note']['id'];
		$p = '<p>' . str_repeat('a', 1000) . '</p>';
		$html = str_repeat($p, intdiv(self::MAX_CONTENT, strlen($p)) + 2);
		self::assertGreaterThan(self::MAX_CONTENT, strlen($html));
		[$r, $s] = self::timedPatch($a, $n, $html, $ws);
		fwrite(STDERR, sprintf("\n[B38] HTML %.1f MiB: HTTP %d in %.2f s\n", strlen($html) / 1048576, $r->status, $s));
		self::assertApp413($r, 'HTML > 16 MiB');
		self::assertSame('<p>bleibt</p>', self::content($a, $n), 'HTML > 16 MiB: Inhalt verändert');
		$text = str_repeat("Zeile mit Text\n", intdiv(self::MAX_CONTENT, 15) + 2);
		[$r] = self::timedPatch($a, $md, $text, $ws);
		self::assertApp413($r, 'Markdown > 16 MiB');
		self::assertSame('bleibt', self::content($a, $md), 'Markdown > 16 MiB: Inhalt verändert');
		// Anlegen mit zu großem Inhalt → 413, keine Notiz
		$count = count(self::dump($a->uid)['notes']);
		$r = $a->call('POST', '/notes', ['heading' => 'zu groß', 'content' => $html, 'workspace' => $ws]);
		self::assertApp413($r, 'POST > 16 MiB');
		self::assertCount($count, self::dump($a->uid)['notes'], 'POST > 16 MiB hat eine Notiz angelegt');
		// knapp unter der Grenze (Gegenprobe; ohne Markup-Wachstum) → erhalten, byte-gleich
		// MySQL/MariaDB (Fix r7): Grenze = maskierte Länge ≤ max_allowed_packet − 1 MiB; je Zeile 15 Bytes roh, 16 maskiert (\n doppelt)
		$line = "Zeile mit Text\n";
		$mysqlLimit = self::mysqlMaskedLimit();
		$okLines = $mysqlLimit === null ? intdiv(self::MAX_CONTENT - 4096, 15) : intdiv($mysqlLimit - 4096, 16);
		$ok = str_repeat($line, $okLines);
		[$r, $s] = self::timedPatch($a, $md, $ok, $ws);
		fwrite(STDERR, sprintf("[B38] Markdown knapp unter Grenze %.2f MiB roh (MySQL-Grenze maskiert: %s): HTTP %d in %.2f s\n",
			strlen($ok) / 1048576, $mysqlLimit === null ? '-' : $mysqlLimit, $r->status, $s));
		self::assertSame(200, $r->status, 'Markdown knapp unter Grenze: ' . self::brief($r));
		$got = self::content($a, $md);
		self::assertSame([strlen($ok), md5($ok)], [strlen($got), md5($got)], 'Markdown knapp unter Grenze: GET nicht byte-gleich');
		if ($mysqlLimit !== null) {
			// knapp über der MySQL-Grenze, aber unter 16 MiB roh → 413 (App-JSON), unverändert, nie 500
			$over = str_repeat($line, intdiv($mysqlLimit, 16) + 2);
			self::assertLessThan(self::MAX_CONTENT, strlen($over));
			[$r, $s] = self::timedPatch($a, $md, $over, $ws);
			fwrite(STDERR, sprintf("[B38] Markdown knapp über MySQL-Grenze %.2f MiB roh: HTTP %d in %.2f s\n", strlen($over) / 1048576, $r->status, $s));
			self::assertApp413($r, 'Markdown über MySQL-Paketgrenze');
			$got = self::content($a, $md);
			self::assertSame([strlen($ok), md5($ok)], [strlen($got), md5($got)], 'Markdown über MySQL-Paketgrenze: Inhalt verändert');
		}
	}

	/** maskierte Grenze auf MySQL/MariaDB (max_allowed_packet − 1 MiB), sonst null */
	private static function mysqlMaskedLimit(): ?int {
		$db = self::db();
		if (!in_array($db->getDatabaseProvider(), [\OCP\IDBConnection::PLATFORM_MYSQL, \OCP\IDBConnection::PLATFORM_MARIADB], true)) {
			return null;
		}
		$res = $db->executeQuery('SELECT @@max_allowed_packet');
		$packet = (int)$res->fetchOne();
		$res->closeCursor();
		return $packet - 1024 * 1024;
	}

	// Lead-Entscheidung M3c2 r3: tiefe Verschachtelung bleibt vollständig; 413 nur, wenn libxml tatsächlich abbricht
	public function testDeepNestingKeptCompleteOr413(): void {
		$a = $this->createUser('a');
		[$ws] = self::newWorkspace($a, 'Tiefe');
		$n = self::newNote($a, $ws, 'Tiefe', '<p>bleibt</p>');
		foreach ([3000, 100000] as $depth) {
			$html = '<div>' . str_repeat('<span>', $depth) . 'ENDE' . str_repeat('</span>', $depth) . '</div>';
			[$r] = self::timedPatch($a, $n, $html, $ws);
			self::assertDeepKept($r, $a, $n, '<p>bleibt</p>', $depth, 'ENDE', "Tiefe $depth");
			if ($r->status === 200) {
				self::ok($a->call('PATCH', "/notes/$n", ['content' => '<p>bleibt</p>', 'workspace' => $ws]), 'zurücksetzen');
			}
		}
		// Gegenprobe: Tiefe 1000 → gespeichert, Text erhalten
		$html = str_repeat('<span>', 1000) . 'ENDE' . str_repeat('</span>', 1000);
		[$r] = self::timedPatch($a, $n, $html, $ws);
		self::assertSame(200, $r->status, 'Tiefe 1000: ' . self::brief($r));
		self::assertStringContainsString('ENDE', self::content($a, $n));
	}

	// 200: alle $depth <span> und der Text vorhanden; 413: App-JSON und Inhalt unverändert
	private static function assertDeepKept(Response $r, ApiClient $a, int $n, string $before, int $depth, string $text, string $what): void {
		self::assertContains($r->status, [200, 413], "$what: " . self::brief($r));
		$content = self::content($a, $n);
		if ($r->status === 413) {
			self::assertApp413($r, $what);
			self::assertSame($before, $content, "$what: Inhalt trotz 413 verändert");
			return;
		}
		self::assertSame($depth, substr_count($content, '<span>'), "$what: Verschachtelung gekürzt");
		self::assertStringContainsString($text, $content, "$what: Text fehlt (still abgeschnitten?)");
	}

	public function testMarkdownCommentAndRawtextBypass(): void {
		$a = $this->createUser('a');
		[$ws] = self::newWorkspace($a, 'MD');
		$n = (int)self::ok($a->call('POST', '/notes', ['heading' => 'MD', 'type' => 'markdown', 'content' => 'x', 'workspace' => $ws]), 'MD')['note']['id'];
		$payload = '<p title="--><img src=x onerror=alert(1)>">';
		$cases = ['Kommentar' => '<!-- ' . $payload . ' -->', 'Kommentar offen' => '<!-- ' . $payload];
		foreach (['textarea', 'title', 'noscript', 'xmp', 'iframe', 'noembed', 'style', 'noframes', 'plaintext'] as $tag) {
			$p = '<p title="</' . $tag . '><img src=x onerror=alert(1)>">';
			$cases[$tag] = "<$tag>$p</$tag>";
			$cases["$tag GROSS"] = '<' . strtoupper($tag) . '>' . str_replace("</$tag>", '</' . strtoupper($tag) . '>', $p) . '</' . strtoupper($tag) . '>';
		}
		foreach ($cases as $label => $md) {
			self::ok($a->call('PATCH', "/notes/$n", ['content' => "Vorher\n\n$md\n\nNachher", 'workspace' => $ws]), $label);
			$c = self::content($a, $n);
			self::assertDoesNotMatchRegularExpression('/<img\b[^>]*\bonerror\b/i', $c, "$label: onerror am <img>: $c");
			self::assertDoesNotMatchRegularExpression('/<[a-z][^>]*\son[a-z]+\s*=/i', $c, "$label: Handler im gespeicherten Inhalt: $c");
			self::assertStringContainsString('Vorher', $c, "$label: Inhalt verloren");
		}
	}

	public function testInvalidUtf8InTitleAndTagsIs400(): void {
		$a = $this->createUser('a');
		[$ws] = self::newWorkspace($a, 'U8');
		$n = self::newNote($a, $ws, 'Gut', '<p>gut</p>');
		$bad = "kaputt\xC3\x28\xFF";
		$before = self::dump($a->uid);
		foreach ([
			'Titel PATCH' => ['PATCH', "/notes/$n", ['heading' => $bad, 'workspace' => $ws]],
			'Titel POST' => ['POST', '/notes', ['heading' => $bad, 'content' => '<p>x</p>', 'workspace' => $ws]],
			'Tags PUT' => ['PUT', "/notes/$n/tags", ['tags' => "gut,$bad", 'workspace' => $ws]],
			'Inhalt PATCH' => ['PATCH', "/notes/$n", ['content' => "<p>$bad</p>", 'workspace' => $ws]],
			'Workspace POST' => ['POST', '/workspaces', ['name' => $bad]],
			'Ordner POST' => ['POST', '/folders', ['folder_name' => $bad, 'workspace' => $ws]],
		] as $label => [$m, $path, $fields]) {
			$r = $m === 'POST' ? $a->form(ApiClient::PREFIX . $path, $fields) : $a->formMethod($m, ApiClient::PREFIX . $path, $fields);
			$changed = self::dump($a->uid) !== $before;
			self::assertSame([400, false], [$r->status, $changed], "$label ungültiges UTF-8 (Status, Daten verändert): " . self::brief($r));
		}
		self::assertSame($before, self::dump($a->uid), 'ungültiges UTF-8 hat Daten verändert');
	}
	// Fix r8: Kleinschreibung von U+0130 (2 → 3 Byte) lässt search_text über die Grenze wachsen → kürzen statt 500; Inhalt byte-gleich, Suche findet den Wortanfang
	public function testDottedCapitalISearchTextFitsNo500(): void {
		$a = $this->createUser('a');
		[$ws] = self::newWorkspace($a, 'Iota');
		foreach (['markdown' => 10.5, 'note' => 11.0] as $type => $mib) {
			$n = (int)self::ok($a->call('POST', '/notes', ['heading' => "Groß $type", 'type' => $type, 'content' => 'bleibt', 'workspace' => $ws]), "Notiz $type")['note']['id'];
			$prefix = 'wortanfang ';
			$content = $prefix . str_repeat("\u{0130}", intdiv((int)($mib * 1048576) - strlen($prefix), 2));
			[$r, $s] = self::timedPatch($a, $n, $content, $ws);
			fwrite(STDERR, sprintf("\n[B38 r8] %s %.2f MiB „wortanfang “+İ: HTTP %d in %.2f s", $type, strlen($content) / 1048576, $r->status, $s));
			self::assertNotSame(500, $r->status, "$type İ: 500 " . self::brief($r));
			self::assertSame(200, $r->status, "$type İ: " . self::brief($r));
			$got = self::content($a, $n);
			if ($type === 'markdown') {
				self::assertSame([strlen($content), md5($content)], [strlen($got), md5($got)], "$type İ: GET nicht byte-gleich");
			} else {
				self::assertStringContainsString($content, $got, "$type İ: Text nicht vollständig erhalten");
			}
			$ids = self::ok($a->call('GET', '/notes/search/ids' . self::q(['q' => 'wortanfang', 'workspace' => $ws])), "Suche $type")['ids'] ?? [];
			self::assertContains($n, array_map('intval', $ids), "$type İ: Suche nach „wortanfang“ findet die Notiz nicht");
		}
		fwrite(STDERR, "\n");
	}

	// Fix r8: Duplikat mit längerer Notiz-ID in den Anhang-Links sprengt die Grenze → 413 (App-JSON), keine Teilkopie
	public function testDuplicateGrowingOverLimitIs413NoCopy(): void {
		$a = $this->createUser('a');
		[$ws, $wsId] = self::newWorkspace($a, 'Dup');
		$f = (int)self::ok($a->call('POST', '/folders', ['folder_name' => 'Quelle', 'workspace' => $ws]), 'Ordner')['folder_id'];
		$src = (int)self::ok($a->call('POST', '/notes', ['heading' => 'Quelle', 'type' => 'markdown', 'content' => 'x', 'workspace' => $ws, 'folder_id' => $f]), 'Quelle')['note']['id'];
		$att = (int)self::ok($a->upload("/notes/$src/attachments", 'a.txt', 'bytes', ['workspace' => $ws]), 'Anhang')['attachment_id'];
		// Grenze: 16 MiB bzw. MySQL-Paketgrenze (keine maskierten Zeichen im Inhalt)
		$limit = min(self::MAX_CONTENT, self::mysqlMaskedLimit() ?? PHP_INT_MAX);
		$ref = "![a](/api/v1/notes/$src/attachments/$att) ";
		$refs = str_repeat($ref, 3000);
		$content = $refs . str_repeat('y', $limit - 1000 - strlen($refs));
		self::ok($a->call('PATCH', "/notes/$src", ['content' => $content, 'workspace' => $ws]), 'Quelle knapp unter Grenze');
		// GET schreibt Anhang-URLs für die Ausgabe um (ContentUrlRewriter) → gespeicherte Zeile prüfen
		$row = array_values(array_filter(self::dump($a->uid)['notes'], static fn ($r) => (int)$r['id'] === $src))[0] ?? [];
		self::assertSame(md5($content), md5((string)($row['content'] ?? '')), 'Quelle nicht byte-gleich gespeichert');
		// nächste Notiz-ID mindestens zwei Stellen länger → je Link ≥ 2 Byte mehr (≥ 6000 > 1000 Reserve)
		self::bumpNoteIds((int)('1' . str_repeat('0', strlen((string)$src) + 2)));
		$before = self::dump($a->uid);
		$r = $a->call('POST', "/notes/$src/duplicate", ['workspace' => $ws]);
		fwrite(STDERR, sprintf("\n[B38 r8] Duplikat Notiz %.2f MiB: %s\n", strlen($content) / 1048576, self::brief($r)));
		self::assertApp413($r, 'Duplikat Notiz über Grenze');
		self::assertSame($before, self::dump($a->uid), 'Duplikat Notiz 413: Daten verändert (Teilkopie?)');
		$r = $a->call('POST', "/folders/$f/duplicate" . self::q(['workspace' => $ws]), []);
		fwrite(STDERR, sprintf("[B38 r8] Duplikat Ordner: %s\n", self::brief($r)));
		self::assertApp413($r, 'Duplikat Ordner über Grenze');
		self::assertSame($before, self::dump($a->uid), 'Duplikat Ordner 413: Daten verändert (Teilkopie?)');
	}

	/** Notiz-ID-Folge auf mindestens $next setzen (je Plattform) */
	private static function bumpNoteIds(int $next): void {
		$db = self::db();
		$table = \OCP\Server::get(\OCP\IConfig::class)->getSystemValueString('dbtableprefix', 'oc_') . 'nextnotes_notes';
		switch ($db->getDatabaseProvider()) {
			case \OCP\IDBConnection::PLATFORM_MYSQL:
			case \OCP\IDBConnection::PLATFORM_MARIADB:
				$db->executeStatement("ALTER TABLE `$table` AUTO_INCREMENT = $next");
				break;
			case \OCP\IDBConnection::PLATFORM_POSTGRES:
				$db->executeQuery("SELECT setval(pg_get_serial_sequence('$table', 'id'), " . ($next - 1) . ')')->closeCursor();
				break;
			default:
				if ($db->executeStatement('UPDATE sqlite_sequence SET seq = ? WHERE name = ?', [$next - 1, $table]) === 0) {
					$db->executeStatement('INSERT INTO sqlite_sequence (name, seq) VALUES (?, ?)', [$table, $next - 1]);
				}
		}
	}
}
