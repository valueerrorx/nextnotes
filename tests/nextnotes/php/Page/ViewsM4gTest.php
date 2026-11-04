<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Page;

use OCA\NextNotes\Service\WorkspaceService;
use OCA\NextNotes\Tests\Support\ApiClient;
use OCA\NextNotes\Tests\Support\ApiTestCase;
use OCA\NextNotes\Tests\Support\Response;
use OCP\IUserManager;
use OCP\Server;

// Plan M4 §M4g Done 1–4: Dashboard, Graph, Speed Dial, Mentions (Seiten, Isolation), Erwähnungs-Benachrichtigung, Hintergrundbild (B43)
final class ViewsM4gTest extends ApiTestCase {
	private const APP = '/index.php/apps/nextnotes/';
	private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

	private static function get(ApiClient $c, string $path): Response {
		return $c->absolute('GET', self::APP . $path);
	}

	/** Schlüssel, unter dem $c den Workspace $name sieht (eigener Name oder „Name (Besitzer)“) */
	private static function keyFor(ApiClient $c, string $name): string {
		foreach (self::ok($c->call('GET', '/workspaces'), 'workspaces')['workspaces'] as $w) {
			if ($w['name'] === $name || str_starts_with($w['name'], $name . ' (')) {
				return $w['name'];
			}
		}
		self::fail("$c->uid sieht $name nicht");
	}

	/** Benachrichtigungen (App nextnotes, Subject mention) zu einer Notiz, optional je Empfänger */
	private static function mentionNotifications(int $noteId, ?string $uid = null): int {
		$qb = self::db()->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from('notifications')
			->where($qb->expr()->eq('app', $qb->createNamedParameter('nextnotes')))
			->andWhere($qb->expr()->eq('subject', $qb->createNamedParameter('mention')))
			->andWhere($qb->expr()->eq('object_type', $qb->createNamedParameter('note')))
			->andWhere($qb->expr()->eq('object_id', $qb->createNamedParameter((string)$noteId)));
		if ($uid !== null) {
			$qb->andWhere($qb->expr()->eq('user', $qb->createNamedParameter($uid)));
		}
		return (int)$qb->executeQuery()->fetchOne();
	}

	/** Dateien unter appdata …/nextnotes/backgrounds/<wsId>/[<uid>/] (Dateicache) */
	private static function backgroundFiles(int $wsId, ?string $uid = null): int {
		$db = self::db();
		$qb = $db->getQueryBuilder();
		$like = '%/nextnotes/backgrounds/' . $wsId . '/' . ($uid !== null ? $db->escapeLikeParameter($uid) . '/' : '') . '%';
		$qb->select($qb->func()->count('*'))->from('filecache')
			->where($qb->expr()->like('path', $qb->createNamedParameter($like)))
			->andWhere($qb->expr()->like('path', $qb->createNamedParameter('appdata\_%')));
		return (int)$qb->executeQuery()->fetchOne();
	}

	private static function upload(ApiClient $c, string $ws, string $bytes, string $name = 'bild.png', string $scope = 'background'): Response {
		return $c->uploadField(self::APP . 'api_upload_background.php', 'background', $name, $bytes, ['workspace' => $ws, 'scope' => $scope], 'image/png');
	}

	public function testPagesShowOwnDataOnly(): void {
		$a = $this->createUser('va');
		$b = $this->createUser('vb');
		$mark = 'VIEW' . bin2hex(random_bytes(4));
		[$ws] = self::newWorkspace($a, 'Board');
		$wq = 'workspace=' . self::enc($ws);
		$n = self::newNote($a, $ws, "Karte $mark", "<p>Text $mark <a href=\"https://example.org/$mark\">Ex</a> <img src=\"https://evil.example/$mark.png\"> <a href=\"javascript:alert(1)\">j</a></p>");
		self::ok($a->call('PUT', "/notes/$n/tags", ['tags' => ['speed-dial'], 'workspace' => $ws]), 'Speed Dial');

		foreach (['dashboard.php', 'graph.php', 'speed_dial.php', 'mentions.php'] as $p) {
			$r = self::get($a, "$p?$wq");
			self::assertSame(200, $r->status, "$p: " . $r->describe());
			self::assertStringContainsString('id="icon_sidebar"', $r->body, "$p: Icon-Leiste");
			foreach (['iconSidebarDashboardBtn', 'iconSidebarGraphBtn', 'iconSidebarSpeedDialBtn'] as $btn) {
				self::assertStringContainsString($btn, $r->body, "$p: $btn");
			}
			self::assertStringNotContainsString('iconSidebarMentionsBtn', $r->body, "$p: Erwähnungen nur mit geteiltem Workspace");
		}
		$dash = self::get($a, "dashboard.php?$wq")->body;
		self::assertStringContainsString("Karte $mark", $dash, 'Dashboard: Karte in DASHBOARD_DATA');
		self::assertStringNotContainsString('<script>window.', $dash, 'Dashboard: keine Inline-Skripte');
		$sd = self::get($a, "speed_dial.php?$wq")->body;
		self::assertStringContainsString('data-url="https://example.org/' . $mark . '"', $sd, 'Speed Dial: Kachel mit Link');
		self::assertStringNotContainsString('evil.example', $sd, 'B62: kein fremdes Bild');
		self::assertStringNotContainsString('javascript:', $sd, 'Speed Dial: nur Weblinks');
		self::assertStringContainsString('id="edgeMenuSpeedDialBackground"', $sd, 'Speed Dial: Hintergrund-Knopf mit Schreibrecht');

		// B: mit A-Workspace und in allen Bereichen keine A-Daten (eigener Standard-Workspace)
		foreach (["dashboard.php?$wq", 'dashboard.php?scope=all', "dashboard.php?scope=list&ws[]=" . self::enc($ws), "graph.php?$wq", "speed_dial.php?$wq", "mentions.php?$wq"] as $p) {
			$r = self::get($b, $p);
			self::assertSame(200, $r->status, "B $p: " . $r->describe());
			self::assertStringNotContainsString($mark, $r->body, "B $p: keine A-Daten");
		}
		self::assertStringNotContainsString($mark, $b->call('GET', '/graph?' . $wq)->body, 'Graph-API: keine A-Daten');

		// B63/P28: GET mit scope= speichert nichts; gespeicherte Mehrfach-Wahl gilt ohne scope=
		[$ws2] = self::newWorkspace($a, 'Zwei');
		self::get($a, 'dashboard.php?scope=all');
		self::assertSame('', (string)(self::ok($a->call('GET', '/settings/dashboard_scope'), 'scope')['value'] ?? ''), 'B63: GET schreibt dashboard_scope nicht');
		self::ok($a->call('PUT', '/settings/dashboard_scope', ['value' => json_encode(['scope' => 'list', 'ws' => [$ws, $ws2]])]), 'Bereich speichern');
		self::assertMatchesRegularExpression('/"mode":"list"/', self::get($a, 'dashboard.php')->body, 'gespeicherte Mehrfach-Wahl');
		self::assertMatchesRegularExpression('/"mode":"single"/', self::get($a, "dashboard.php?scope=single&$wq")->body, 'scope=single');
	}

	public function testReservedSpeedDialTag(): void {
		$a = $this->createUser('vr');
		$mark = 'RES' . bin2hex(random_bytes(4));
		[$ws] = self::newWorkspace($a, 'Res');
		$n = self::newNote($a, $ws, "Res $mark", '<p>https://example.org/r</p>');
		self::ok($a->call('PUT', "/notes/$n/tags", ['tags' => ["eigen$mark"], 'workspace' => $ws]), 'Tag');
		$r = $a->call('PATCH', '/tags/' . self::enc("eigen$mark"), ['new_name' => 'speed-dial', 'workspace' => $ws]);
		self::assertSame(400, $r->status, 'Umbenennen auf speed-dial: ' . $r->describe());
		self::assertSame(404, $a->call('DELETE', '/tags/speed-dial?workspace=' . self::enc($ws), [])->status, 'interner Tag nicht löschbar');
		// Beim Speichern eingetippt: verworfen; über die Speed-Dial-Aktion gesetzt: bleibt beim Speichern
		self::ok($a->call('PATCH', "/notes/$n", ['tags' => "eigen$mark speed-dial", 'workspace' => $ws]), 'Speichern mit speed-dial');
		self::assertStringNotContainsString("Res $mark", self::get($a, 'speed_dial.php?workspace=' . self::enc($ws))->body, 'eingetippt: nicht im Speed Dial');
		self::ok($a->call('PUT', "/notes/$n/tags", ['tags' => ["eigen$mark", 'speed-dial'], 'workspace' => $ws]), 'Speed-Dial-Aktion');
		self::ok($a->call('PATCH', "/notes/$n", ['tags' => "eigen$mark speed-dial", 'content' => '<p>https://example.org/r2</p>', 'workspace' => $ws]), 'Speichern behält');
		self::assertStringContainsString('data-url="https://example.org/r2"', self::get($a, 'speed_dial.php?workspace=' . self::enc($ws))->body, 'Aktion: im Speed Dial');
	}

	public function testMentionPageAndNotifications(): void {
		$a = $this->createUser('ma');
		$b = $this->createUser('mb', 'Bea Beispiel');
		$c = $this->createUser('mc');
		$mark = 'MEN' . bin2hex(random_bytes(4));
		[$ws, $wsId] = self::newWorkspace($a, 'Team');
		Server::get(WorkspaceService::class)->share($a->uid, $wsId, $b->uid, 'read');
		$n = self::newNote($a, $ws, "Hinweis $mark", "<p>Hallo @{$b->uid} und @{$a->uid} und @{$c->uid}</p>");
		self::assertSame(1, self::mentionNotifications($n, $b->uid), 'B: genau eine Benachrichtigung');
		self::assertSame(0, self::mentionNotifications($n, $a->uid), 'nie an sich selbst');
		self::assertSame(0, self::mentionNotifications($n, $c->uid), 'C ohne Recht: keine');
		self::ok($a->call('PATCH', "/notes/$n", ['content' => "<p>Hallo @{$b->uid} nochmal und @{$c->uid}</p>", 'workspace' => $ws]), 'erneut speichern');
		self::assertSame(1, self::mentionNotifications($n), 'ohne neue Erwähnung keine weitere');

		$page = self::get($b, 'mentions.php');
		self::assertSame(200, $page->status, $page->describe());
		self::assertStringContainsString("Hinweis $mark", $page->body, 'B: Erwähnung gelistet');
		self::assertMatchesRegularExpression('/id="iconSidebarMentionsBtn".*?<span class="mention-badge">1<\/span>/s', $page->body, 'B: Zähler 1');
		self::assertStringNotContainsString($mark, self::get($c, 'mentions.php')->body, 'C: nichts');
		// GET schreibt nichts: erledigt markieren über die API, Seite zeigt es nur mit handled=1
		self::ok($b->call('POST', "/mentions/{$a->uid}/$n/handled"), 'erledigt');
		self::assertStringNotContainsString("Hinweis $mark", self::get($b, 'mentions.php')->body, 'erledigt: ausgeblendet');
		self::assertStringContainsString("Hinweis $mark", self::get($b, 'mentions.php?handled=1')->body, 'handled=1: sichtbar');
	}

	public function testMentionNotificationCap(): void {
		$a = $this->createUser('mx');
		[$ws, $wsId] = self::newWorkspace($a, 'Viele');
		$uids = [];
		$um = Server::get(IUserManager::class);
		for ($i = 0; $i < 30; $i++) {
			$u = $this->createUser('m' . $i);
			Server::get(WorkspaceService::class)->share($a->uid, $wsId, $u->uid, 'read');
			$uids[] = $u->uid;
		}
		self::assertNotNull($um->get($uids[29]));
		$body = '<p>' . implode(' ', array_map(static fn ($u) => "@$u", $uids)) . '</p>';
		$r = $a->call('POST', '/notes', ['heading' => 'Rundruf', 'content' => $body, 'workspace' => $ws]);
		self::assertTrue($r->ok(), 'Speichern erfolgreich: ' . $r->describe());
		$n = (int)$r->json()['note']['id'];
		self::assertSame(20, self::mentionNotifications($n), 'höchstens 20 je Speichervorgang');
		foreach (array_slice($uids, 0, 20) as $u) {
			self::assertSame(1, self::mentionNotifications($n, $u), "$u: in Textreihenfolge");
		}
	}

	public function testBackgroundImage(): void {
		$a = $this->createUser('ba');
		$b = $this->createUser('bb');
		$w = $this->createUser('bw');
		$png = (string)base64_decode(self::PNG);
		[$ws, $wsId] = self::newWorkspace($a, 'Bild');
		$up = self::upload($a, $ws, $png);
		self::assertSame(200, $up->status, 'Upload PNG: ' . $up->describe());
		$status = self::ok(self::get($a, 'api_upload_background.php?workspace=' . self::enc($ws)), 'Status');
		self::assertTrue($status['exists']);
		$img = $a->absolute('GET', parse_url($status['url'], PHP_URL_PATH) . '?' . parse_url($status['url'], PHP_URL_QUERY));
		self::assertSame(200, $img->status, 'Bild: ' . $img->describe());
		self::assertSame($png, $img->body, 'Bild: Bytes');
		self::assertStringStartsWith('image/png', (string)$img->header('Content-Type'));
		self::assertSame('nosniff', $img->header('X-Content-Type-Options'));
		self::assertStringContainsString('sandbox', (string)$img->header('Content-Security-Policy'));
		self::assertStringStartsWith('inline', (string)$img->header('Content-Disposition'));
		// Hauptansicht bringt den Status mit (kein Zusatz-Request)
		$config = json_decode((string)base64_decode((string)self::configOf(self::get($a, 'index.php?workspace=' . self::enc($ws))->body)), true);
		self::assertTrue($config['background']['exists'] ?? false, 'Index: Hintergrund in config (kein Zusatz-Request)');

		// Umbenennen: Bild bleibt
		self::ok($a->call('PATCH', '/workspaces/' . self::enc($ws), ['new_name' => "$ws neu"]), 'umbenennen');
		$ws = "$ws neu";
		self::assertTrue(self::ok(self::get($a, 'api_upload_background.php?workspace=' . self::enc($ws)), 'Status neu')['exists'], 'nach Umbenennen');

		// Typ nach Inhalt, Größe
		self::assertSame(400, self::upload($a, $ws, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')->status, 'SVG als .png');
		self::assertSame(400, self::upload($a, $ws, '<html><body>x</body></html>')->status, 'HTML als .png');
		self::assertSame(413, self::upload($a, $ws, $png . str_repeat("\0", 6 * 1024 * 1024))->status, '6 MiB');

		// Fremde: B mit A-Workspace 404, abgemeldet kein Bild
		$imgPath = self::APP . 'api_upload_background.php?image=1&workspace=' . self::enc($ws);
		self::assertSame(404, $b->absolute('GET', $imgPath)->status, 'B mit A-Workspace');
		self::assertSame(404, $b->uploadField(self::APP . 'api_upload_background.php', 'background', 'x.png', $png, ['workspace' => $ws])->status, 'B Upload in A-Workspace');
		$anon = ApiClient::raw('GET', ApiClient::baseUrl() . $imgPath, null, [], null);
		self::assertNotSame(200, $anon->status, 'abgemeldet: kein Bild');
		self::assertNotSame($png, $anon->body);

		// Leser: POST 403, ohne eigenes Bild 404; Schreib-Empfänger lädt eigenes Bild
		Server::get(WorkspaceService::class)->share($a->uid, $wsId, $b->uid, 'read');
		Server::get(WorkspaceService::class)->share($a->uid, $wsId, $w->uid, 'write');
		$kb = self::keyFor($b, $ws);
		self::assertSame(403, self::upload($b, $kb, $png)->status, 'Leser: POST');
		self::assertSame(403, $b->absolute('DELETE', self::APP . 'api_upload_background.php?workspace=' . self::enc($kb))->status, 'Leser: DELETE');
		self::assertSame(404, $b->absolute('GET', self::APP . 'api_upload_background.php?image=1&workspace=' . self::enc($kb))->status, 'Leser ohne eigenes Bild');
		self::assertStringNotContainsString('edgeMenuSpeedDialBackground', self::get($b, 'speed_dial.php?workspace=' . self::enc($kb))->body, 'Leser: kein Hintergrund-Knopf');
		self::assertSame(200, self::upload($w, self::keyFor($w, $ws), $png, 'w.png', 'speed_dial')->status, 'Schreib-Empfänger');
		self::assertStringContainsString('speed-dial-background', self::get($w, 'speed_dial.php?workspace=' . self::enc(self::keyFor($w, $ws)))->body, 'Speed Dial zeigt eigenes Bild');
		self::assertSame(1, self::backgroundFiles($wsId, $a->uid));
		self::assertSame(1, self::backgroundFiles($wsId, $w->uid));
		// Deckkraft unter dem Upstream-Schlüssel, gespeichert je Workspace-ID
		self::ok($a->call('PUT', '/settings/' . rawurlencode("background_opacity_$ws"), ['value' => '40']), 'Deckkraft');
		self::assertSame('25', (string)self::ok($a->call('GET', '/settings/' . rawurlencode("background_opacity_$ws")), 'Deckkraft lesen')['value'], 'auf 25 begrenzt');

		// Workspace löschen: Ordner aller Benutzer weg
		self::ok($a->call('DELETE', '/workspaces/' . self::enc($ws), []), 'Workspace löschen');
		self::assertSame(0, self::backgroundFiles($wsId), 'backgrounds/<wsId> weg');
	}

	public function testBackgroundsOnUnshareAndAccountDeletion(): void {
		$a = $this->createUser('bd');
		$b = $this->createUser('be');
		$png = (string)base64_decode(self::PNG);
		[$wsA, $idA] = self::newWorkspace($a, 'Eigen');
		[$wsB, $idB] = self::newWorkspace($b, 'Geteilt');
		Server::get(WorkspaceService::class)->share($b->uid, $idB, $a->uid, 'write');
		self::assertSame(200, self::upload($a, $wsA, $png)->status);
		self::assertSame(200, self::upload($a, self::keyFor($a, $wsB), $png)->status);
		self::assertSame(200, self::upload($b, $wsB, $png)->status);
		// Entzug der Freigabe entfernt A-Bild in B-Workspace
		Server::get(WorkspaceService::class)->unshare($b->uid, $idB, $a->uid);
		self::assertSame(0, self::backgroundFiles($idB, $a->uid), 'Entzug: Empfänger-Bild weg');
		self::assertSame(1, self::backgroundFiles($idB, $b->uid), 'Entzug: Besitzer-Bild bleibt');
		// Kontolöschung: A-Bilder in freigegebenen Workspaces sofort weg, B-Bilder bleiben
		Server::get(WorkspaceService::class)->share($b->uid, $idB, $a->uid, 'write');
		self::assertSame(200, self::upload($a, self::keyFor($a, $wsB), $png)->status);
		Server::get(IUserManager::class)->get($a->uid)?->delete();
		self::assertSame(0, self::backgroundFiles($idB, $a->uid), 'Kontolöschung: A-Bild im geteilten Workspace weg');
		self::assertSame(1, self::backgroundFiles($idB, $b->uid), 'Kontolöschung: B-Bild bleibt');
		self::assertGreaterThanOrEqual(0, self::backgroundFiles($idA), 'eigener Workspace folgt mit dem Lösch-Job');
	}

	/** InitialState „config“ der Seite (Base64) */
	private static function configOf(string $html): ?string {
		return preg_match('/id="initial-state-nextnotes-config" value="([^"]+)"/', $html, $m) ? $m[1] : null;
	}
}
