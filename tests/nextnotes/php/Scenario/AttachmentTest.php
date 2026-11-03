<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Scenario;

use OCA\NextNotes\Service\WorkspaceService;
use OCA\NextNotes\Tests\Support\ApiTestCase;
use OCA\NextNotes\Tests\Support\Response;
use OCP\IDBConnection;
use OCP\Server;

// SPEC §4.2 Anhänge, §11.2 B21/B22, ADR 0002 (Quota), Plan Done M2b 2: Bytes, Kopfzeilen, Fremdzugriff, Quota
final class AttachmentTest extends ApiTestCase {
	private const PDF = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[]/Count 0>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
	private const SVG = '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(2)</script></svg>';

	protected function tearDown(): void {
		self::occ('config:app:delete', 'nextnotes', 'attachment_max_file_mb');
		self::occ('config:app:delete', 'nextnotes', 'attachment_quota_mb');
		parent::tearDown();
	}

	// App-Konfiguration über occ; der Webserver hält AppConfig bis 3 s im lokalen Cache (AppConfig::LOCAL_CACHE_TTL)
	private static function occ(string ...$args): int {
		exec('php /var/www/html/occ ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $out, $rc);
		return $rc;
	}

	private static function setInt(string $key, int $value): void {
		self::assertSame(0, self::occ('config:app:set', 'nextnotes', $key, '--value=' . $value, '--type=integer'), "occ config:app:set $key");
		sleep(4);
	}

	private static function disposition(Response $r): string {
		return strtolower(trim(explode(';', (string)$r->header('content-disposition'))[0]));
	}

	private static function sandboxed(Response $r): bool {
		return str_contains((string)$r->header('content-security-policy'), 'sandbox');
	}

	public function testBytesHeadersAndForeignAccess(): void {
		$a = $this->createUser('aa');
		$b = $this->createUser('ab');
		[$ws] = self::newWorkspace($a, 'Att');
		$n = self::newNote($a, $ws, 'Mit Anhang', '<p>x</p>');
		$n2 = self::newNote($a, $ws, 'Zweite', '<p>y</p>');
		[$wsB] = self::newWorkspace($b, 'AttB');
		$nB = self::newNote($b, $wsB, 'B Notiz', '<p>b</p>');

		$cases = [
			'bin' => ['daten.bin', random_bytes(70000) . "\0\xff", 'application/octet-stream'],
			'png' => ['bild.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='), 'image/png'],
			'gif' => ['bild.gif', base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'), 'image/gif'],
			'svg' => ['x.svg', self::SVG, 'image/svg+xml'],
			'svgpng' => ['tarn.png', self::SVG, 'image/png'],
			'html' => ['seite.html', '<html><body><script>alert(1)</script></body></html>', 'text/html'],
			'htmlgif' => ['tarn.gif', '<html><script>alert(1)</script></html>', 'image/gif'],
			'pdf' => ['dok.pdf', self::PDF, 'application/pdf'],
			'txt' => ['notiz.txt', "Klartext äöü\n", 'text/plain'],
		];
		$errors = [];
		$ids = [];
		foreach ($cases as $k => [$name, $bytes, $mime]) {
			$up = $a->upload("/notes/$n/attachments", $name, $bytes, [], $mime);
			if (!$up->ok()) {
				$errors[] = "$k Upload: " . $up->describe();
				continue;
			}
			$id = (int)$up->json()['attachment_id'];
			$ids[$k] = $id;
			$r = $a->call('GET', "/notes/$n/attachments/$id");
			if ($r->status !== 200 || $r->body !== $bytes) {
				$errors[] = "$k Download: Status $r->status, Bytes " . (($r->body === $bytes) ? 'gleich' : 'verschieden');
			}
			if ($r->header('x-content-type-options') !== 'nosniff') {
				$errors[] = "$k: nosniff fehlt";
			}
			$ct = strtolower((string)$r->header('content-type'));
			$disp = self::disposition($r);
			// B22: SVG/HTML nie inline; inline nur mit ungefährlichem Typ
			if ($disp === 'inline' && (str_contains($ct, 'svg') || str_contains($ct, 'html') || str_contains($ct, 'xml'))) {
				$errors[] = "$k: inline mit $ct";
			}
			if (in_array($k, ['svg', 'html', 'bin'], true) && $disp !== 'attachment') {
				$errors[] = "$k: erwartet attachment, erhalten '$disp' ($ct)";
			}
			if ($k === 'gif' && ($disp !== 'inline' || $ct !== 'image/gif')) {
				$errors[] = "gif: erwartet inline image/gif, erhalten '$disp' ($ct)";
			}
			// B22: CSP sandbox auf allen Anhängen außer PDF; frame-ancestors 'self' überall
			$csp = (string)$r->header('content-security-policy');
			if ($k !== 'pdf' && !self::sandboxed($r)) {
				$errors[] = "$k: CSP sandbox fehlt ($csp)";
			}
			if ($k === 'pdf' && ($disp !== 'inline' || self::sandboxed($r))) {
				$errors[] = "pdf: erwartet inline ohne sandbox, erhalten '$disp' ($csp)";
			}
			// PDF behält laut SPEC §11.2 B22 die NC-Standard-CSP (dort frame-ancestors 'none'), daher ausgenommen
			if ($k !== 'pdf' && !str_contains($csp, "frame-ancestors 'self'")) {
				$errors[] = "$k: CSP ohne frame-ancestors 'self' ($csp)";
			}
			if (in_array($k, ['png', 'txt'], true) && $disp !== 'inline') {
				$errors[] = "$k: erwartet inline, erhalten '$disp' ($ct)";
			}
			if ($k === 'txt' && !str_starts_with($ct, 'text/plain')) {
				$errors[] = "txt: erwartet text/plain, erhalten $ct";
			}
			$dl = $a->call('GET', "/notes/$n/attachments/$id?download=1");
			$dcsp = (string)$dl->header('content-security-policy');
			if (self::disposition($dl) !== 'attachment' || $dl->body !== $bytes || $dl->header('x-content-type-options') !== 'nosniff'
				|| !self::sandboxed($dl) || !str_contains($dcsp, "frame-ancestors 'self'")) {
				$errors[] = "$k ?download=1: '" . self::disposition($dl) . "' nosniff=" . $dl->header('x-content-type-options') . " csp=$dcsp";
			}
		}
		self::assertSame([], $errors);

		// Liste enthält die Anhänge (Upstream-Schlüssel)
		$list = self::ok($a->call('GET', "/notes/$n/attachments"), 'Liste')['attachments'];
		self::assertCount(count($cases), $list);
		foreach (['id', 'filename', 'original_filename', 'file_size', 'file_type', 'uploaded_at'] as $key) {
			self::assertArrayHasKey($key, $list[0]);
		}

		// Fremder Anhang → 404, auch über eigene Notiz oder falsche Notiz-ID von A
		$gif = $ids['gif'];
		foreach ([
			"B direkt" => $b->call('GET', "/notes/$n/attachments/$gif"),
			"B über eigene Notiz" => $b->call('GET', "/notes/$nB/attachments/$gif"),
			"B Download-Variante" => $b->call('GET', "/notes/$n/attachments/$gif?download=1"),
			"A falsche Notiz" => $a->call('GET', "/notes/$n2/attachments/$gif"),
			"B löscht" => $b->call('DELETE', "/notes/$n/attachments/$gif"),
			"B verschiebt zu sich" => $b->call('POST', "/notes/$n/attachments/$gif/move", ['target_note_id' => $nB]),
		] as $label => $r) {
			self::assertSame(404, $r->status, "$label: " . $r->describe());
			self::assertNotSame(base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'), $r->body);
		}
		// B lädt in A's Notiz hoch → 404; A's Anhänge unverändert
		self::assertSame(404, $b->upload("/notes/$n/attachments", 'x.txt', 'x')->status);
		self::assertCount(count($cases), self::ok($a->call('GET', "/notes/$n/attachments"), 'Liste danach')['attachments']);

		// unbekannte Anhang-ID → 404, Löschen entfernt den Download
		self::assertSame(404, $a->call('GET', "/notes/$n/attachments/999999999")->status);
		self::ok($a->call('DELETE', "/notes/$n/attachments/$gif"), 'Löschen');
		self::assertSame(404, $a->call('GET', "/notes/$n/attachments/$gif")->status);
	}

	// B21: Ziel braucht write, Quelle in anderem Workspace manage
	public function testSharedSourceCannotBeCopiedOut(): void {
		$a = $this->createUser('sa');
		$b = $this->createUser('sb');
		[$ws, $wsId] = self::newWorkspace($a, 'Quelle');
		$n = self::newNote($a, $ws, 'Geteilt', '<p>g</p>');
		$att = (int)self::ok($a->upload("/notes/$n/attachments", 'g.txt', 'geteilt'), 'Anhang')['attachment_id'];
		[$wsB] = self::newWorkspace($b, 'Ziel');
		$nB = self::newNote($b, $wsB, 'Eigen', '<p>e</p>');
		Server::get(WorkspaceService::class)->share($a->uid, $wsId, $b->uid);

		self::assertSame('geteilt', $b->call('GET', "/notes/$n/attachments/$att")->body, 'Kontrolle: B liest mit Freigabe');
		$r = $b->call('POST', "/notes/$n/attachments/$att/move", ['target_note_id' => $nB]);
		self::assertSame(403, $r->status, 'Anhang aus geteiltem WS in eigenen: ' . $r->describe());
		$fB = (int)self::ok($b->call('POST', '/folders', ['folder_name' => 'Ziel', 'workspace' => $wsB]), 'B-Ordner')['folder_id'];
		$r = $b->call('POST', "/notes/$n/duplicate", ['folder_id' => $fB, 'workspace' => $wsB]);
		self::assertSame(403, $r->status, 'Notiz aus geteiltem WS in eigenen duplizieren: ' . $r->describe());
		self::assertSame([], self::ok($b->call('GET', "/notes/$nB/attachments"), 'B-Liste')['attachments']);
		// innerhalb des geteilten Workspace (Ziel write, gleicher WS) erlaubt
		$n2 = self::newNote($a, $ws, 'Geteilt 2', '<p>g2</p>');
		self::ok($b->call('POST', "/notes/$n/attachments/$att/move", ['target_note_id' => $n2]), 'Verschieben im geteilten WS');
	}

	public function testQuota(): void {
		$a = $this->createUser('qa');
		[$ws] = self::newWorkspace($a, 'Quota');
		$n = self::newNote($a, $ws, 'Q', '<p>q</p>');

		// Dateigrenze (MB)
		self::setInt('attachment_max_file_mb', 1);
		$r = $a->upload("/notes/$n/attachments", 'gross.bin', str_repeat('x', 1048576 + 10));
		self::assertSame(413, $r->status, 'Datei über Grenze: ' . $r->describe());
		self::assertFalse($r->json()['success'] ?? null);
		self::ok($a->upload("/notes/$n/attachments", 'klein.bin', str_repeat('x', 1000)), 'Datei unter Grenze');
		self::occ('config:app:delete', 'nextnotes', 'attachment_max_file_mb');
		sleep(4);

		// Quota zählt gegen den Besitzer des Workspace (SPEC §2.2), auch bei Uploads von Freigabe-Empfängern
		self::setInt('attachment_quota_mb', 1);
		$b = $this->createUser('qb');
		[$ws2, $ws2Id] = self::newWorkspace($a, 'Quota2');
		$n2 = self::newNote($a, $ws2, 'Q2', '<p>q</p>');
		Server::get(WorkspaceService::class)->share($a->uid, $ws2Id, $b->uid);
		self::ok($b->upload("/notes/$n2/attachments", 'vonB.bin', str_repeat('b', 600000)), 'B lädt in A-Workspace');
		$r = $a->upload("/notes/$n/attachments", 'teil2.bin', str_repeat('a', 600000));
		self::assertSame(413, $r->status, 'Upload von B belastet A nicht: ' . $r->describe());
		[$wsB] = self::newWorkspace($b, 'QuotaB');
		$nB = self::newNote($b, $wsB, 'QB', '<p>b</p>');
		self::ok($b->upload("/notes/$nB/attachments", 'eigen.bin', str_repeat('c', 600000)), 'B eigene Quota unbelastet');
		$r = $b->upload("/notes/$n2/attachments", 'nochmal.bin', str_repeat('d', 600000));
		self::assertSame(413, $r->status, 'B über A-Quota: ' . $r->describe());
		self::occ('config:app:delete', 'nextnotes', 'attachment_quota_mb');
		sleep(4);
	}

	// SPEC §2.2: Prüfung und Einfügen unter Sperre der Besitzer-Zeile → parallele Uploads überschreiten die Quote nicht
	public function testParallelUploadsRespectQuota(): void {
		$a = $this->createUser('pq');
		[$ws] = self::newWorkspace($a, 'Parallel');
		$n1 = self::newNote($a, $ws, 'P1', '<p>1</p>');
		$n2 = self::newNote($a, $ws, 'P2', '<p>2</p>');
		self::setInt('attachment_quota_mb', 1);
		$size = 1048576 - 600000;
		$statuses = [];
		$errors = [];
		// mehrere Runden, damit die Anfragen tatsächlich überlappen
		for ($round = 0; $round < 3; $round++) {
			$rs = $a->parallelUpload([
				["/notes/$n1/attachments", "p$round-a.bin", str_repeat('a', 600000)],
				["/notes/$n2/attachments", "p$round-b.bin", str_repeat('b', 600000)],
			]);
			$st = array_map(static fn ($r) => $r->status, $rs);
			sort($st);
			$statuses[] = implode('/', $st);
			// Invariante überall: nie beide gespeichert, nie 500. SQLite: Sperr-Timeout → 503 mit Retry-After (SPEC §4.1)
			$sqlite = self::db()->getDatabaseProvider() === IDBConnection::PLATFORM_SQLITE;
			$allowed = $sqlite ? [[201, 413], [201, 503]] : [[201, 413]];
			if (!in_array($st, $allowed, true)) {
				$errors[] = "Runde $round: " . implode(' | ', array_map(static fn ($r) => mb_substr($r->describe(), 0, 160), $rs));
			}
			foreach ($rs as $r) {
				if ($r->status === 503 && trim((string)$r->header('Retry-After')) === '') {
					$errors[] = "Runde $round: 503 ohne Retry-After";
				}
			}
			if ($st === [201, 503]) {
				fwrite(STDERR, "Hinweis SQLite: paralleler Upload Runde $round → 503 Retry-After=" . implode(',', array_map(static fn ($r) => (string)$r->header('Retry-After'), $rs)) . "\n");
			}
			// zurücksetzen: alle Anhänge löschen
			foreach ([$n1, $n2] as $n) {
				foreach (self::ok($a->call('GET', "/notes/$n/attachments"), 'Liste')['attachments'] as $att) {
					self::ok($a->call('DELETE', "/notes/$n/attachments/{$att['id']}"), 'Löschen');
				}
			}
		}
		self::occ('config:app:delete', 'nextnotes', 'attachment_quota_mb');
		sleep(4);
		self::assertSame([], $errors, 'Statusfolgen: ' . implode(', ', $statuses) . " (Größe $size frei)");
	}

	// Verknüpfung (type=linked): Anhänge des Ziels; ohne Leserecht aufs Ziel 404; Upload auf Verknüpfung 400
	public function testLinkedNoteAttachments(): void {
		$a = $this->createUser('la');
		$b = $this->createUser('lb');
		[$ws, $wsId] = self::newWorkspace($a, 'Ziel');
		$target = self::newNote($a, $ws, 'Ziel', '<p>z</p>');
		$att = (int)self::ok($a->upload("/notes/$target/attachments", 'z.txt', 'ziel-bytes'), 'Anhang Ziel')['attachment_id'];
		$ownLink = (int)self::ok($a->call('POST', '/notes', ['heading' => 'Verweis', 'type' => 'linked', 'workspace' => $ws, 'linked_note_id' => $target]), 'Verknüpfung A')['note']['id'];
		$list = self::ok($a->call('GET', "/notes/$ownLink/attachments"), 'Liste über Verknüpfung')['attachments'];
		self::assertSame([(string)$att], array_map(static fn ($x) => (string)$x['id'], $list));
		self::assertSame('ziel-bytes', $a->call('GET', "/notes/$ownLink/attachments/$att")->body);
		$r = $a->upload("/notes/$ownLink/attachments", 'x.txt', 'x');
		self::assertSame(400, $r->status, 'Upload auf Verknüpfung: ' . $r->describe());

		// B: Verknüpfung im eigenen Workspace auf geteilte Notiz, dann Entzug
		Server::get(WorkspaceService::class)->share($a->uid, $wsId, $b->uid);
		[$wsB] = self::newWorkspace($b, 'Eigen');
		// je Notiz nur eine Verknüpfung (Upstream) → eigenes Ziel für B
		$target = self::newNote($a, $ws, 'Ziel B', '<p>z</p>');
		$att = (int)self::ok($a->upload("/notes/$target/attachments", 'z.txt', 'ziel-bytes'), 'Anhang Ziel B')['attachment_id'];
		$link = (int)self::ok($b->call('POST', '/notes', ['heading' => 'B Verweis', 'type' => 'linked', 'workspace' => $wsB, 'linked_note_id' => $target]), 'Verknüpfung B')['note']['id'];
		self::assertCount(1, self::ok($b->call('GET', "/notes/$link/attachments"), 'B mit Freigabe')['attachments']);
		Server::get(WorkspaceService::class)->unshare($a->uid, $wsId, $b->uid);
		foreach ([$b->call('GET', "/notes/$link/attachments"), $b->call('GET', "/notes/$link/attachments/$att")] as $r) {
			self::assertSame(404, $r->status, 'ohne Leserecht aufs Ziel: ' . $r->describe());
			self::assertStringNotContainsString('ziel-bytes', $r->body);
			self::assertStringNotContainsString('z.txt', $r->body);
		}
	}
}
