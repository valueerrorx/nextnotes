<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Page;

use OCA\NextNotes\BackgroundJob\UserDataDeletionJob;
use OCA\NextNotes\Service\UserDataService;
use OCA\NextNotes\Service\WorkspaceService;
use OCA\NextNotes\Tests\Support\ApiClient;
use OCA\NextNotes\Tests\Support\ApiTestCase;
use OCA\NextNotes\Tests\Support\Response;
use OCP\BackgroundJob\IJobList;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCA\NextNotes\Notification\Notifier;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
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

	/**
	 * Lösch-Job zur Kennung des entkoppelten Workspace $wsId ausführen und austragen (wie UserDataDeletionJob, alle Workspaces der Kennung);
	 * kein Job bleibt für spätere Tests liegen (IJobList baut Jobs als geteilte Instanz)
	 */
	private static function runDeletionJob(int $wsId): int {
		$qb = self::db()->getQueryBuilder();
		$token = (string)$qb->select('owner_id')->from('nextnotes_workspaces')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($wsId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('owner_type', $qb->createNamedParameter(UserDataService::DELETED)))
			->executeQuery()->fetchOne();
		self::assertStringStartsWith('#', $token, "Workspace $wsId entkoppelt");
		Server::get(IJobList::class)->remove(UserDataDeletionJob::class, ['token' => $token]);
		return Server::get(UserDataService::class)->purgeWorkspaces($token);
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

		foreach (['dashboard.php', 'graph.php', 'mentions.php'] as $p) {
			$r = self::get($a, "$p?$wq");
			self::assertSame(200, $r->status, "$p: " . $r->describe());
			self::assertStringContainsString('id="icon_sidebar"', $r->body, "$p: Icon-Leiste");
			foreach (['iconSidebarDashboardBtn', 'iconSidebarGraphBtn'] as $btn) {
				self::assertStringContainsString($btn, $r->body, "$p: $btn");
			}
			self::assertStringNotContainsString('iconSidebarSpeedDialBtn', $r->body, "$p: Speed Dial entfernt (M6g)");
			self::assertStringNotContainsString('iconSidebarMentionsBtn', $r->body, "$p: Erwähnungen nur mit geteiltem Workspace");
		}
		$dash = self::get($a, "dashboard.php?$wq")->body;
		self::assertStringContainsString("Karte $mark", $dash, 'Dashboard: Karte in DASHBOARD_DATA');
		self::assertStringNotContainsString('<script>window.', $dash, 'Dashboard: keine Inline-Skripte');
		// Speed Dial entfernt (Thomas 2026-11-08): alte Adresse → Dashboard mit Workspace, ohne Inhalt
		$sd = self::get($a, "speed_dial.php?$wq");
		self::assertSame(303, $sd->status, 'speed_dial.php: ' . $sd->describe());
		self::assertStringEndsWith('/apps/nextnotes/dashboard.php?' . http_build_query(['workspace' => $ws]), (string)$sd->header('Location'), 'speed_dial.php → Dashboard');
		self::assertStringNotContainsString($mark, $sd->body, 'speed_dial.php: kein Inhalt');

		// B: mit A-Workspace und in allen Bereichen keine A-Daten (eigener Standard-Workspace)
		foreach (["dashboard.php?$wq", 'dashboard.php?scope=all', "dashboard.php?scope=list&ws[]=" . self::enc($ws), "graph.php?$wq", "mentions.php?$wq"] as $p) {
			$r = self::get($b, $p);
			self::assertSame(200, $r->status, "B $p: " . $r->describe());
			self::assertStringNotContainsString($mark, $r->body, "B $p: keine A-Daten");
		}
		self::assertStringNotContainsString($mark, $b->call('GET', '/graph?' . $wq)->body, 'Graph-API: keine A-Daten');

		// B63/P28: GET mit scope= speichert nichts; gespeicherte Mehrfach-Wahl gilt ohne scope=
		[$ws2] = self::newWorkspace($a, 'Zwei');
		self::get($a, 'dashboard.php?scope=all');
		self::assertSame('', (string)(self::ok($a->call('GET', '/settings/dashboard_scope'), 'scope')['value'] ?? ''), 'B63: GET schreibt dashboard_scope nicht');
		self::assertMatchesRegularExpression('/"mode":"all"/', self::get($a, "dashboard.php?$wq")->body, 'ohne gespeicherte Wahl: Alle Workspaces (Thomas 2026-11-08)');
		self::ok($a->call('PUT', '/settings/dashboard_scope', ['value' => json_encode(['scope' => 'single', 'workspace' => $ws])]), 'Einzelwahl speichern');
		self::assertMatchesRegularExpression('/"mode":"single"/', self::get($a, "dashboard.php?$wq")->body, 'gespeicherte Einzelwahl folgt dem Workspace');
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
		self::assertNotContains('speed-dial', self::tagsOf($a, $n, $ws), 'eingetippt: verworfen');
		self::ok($a->call('PUT', "/notes/$n/tags", ['tags' => ["eigen$mark", 'speed-dial'], 'workspace' => $ws]), 'Speed-Dial-Aktion');
		self::ok($a->call('PATCH', "/notes/$n", ['tags' => "eigen$mark speed-dial", 'content' => '<p>https://example.org/r2</p>', 'workspace' => $ws]), 'Speichern behält');
		self::assertContains('speed-dial', self::tagsOf($a, $n, $ws), 'Aktion: bleibt beim Speichern');
	}

	/** @return list<string> Tags der Notiz laut GET /notes/{id} */
	private static function tagsOf(ApiClient $c, int $n, string $ws): array {
		$tags = self::ok($c->call('GET', "/notes/$n?workspace=" . self::enc($ws)), 'Notiz')['note']['tags'] ?? [];
		return is_array($tags) ? array_values(array_map('strval', $tags)) : (preg_split('/[\s,]+/', (string)$tags, -1, PREG_SPLIT_NO_EMPTY) ?: []);
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

	/** @return int[] eigene Workspace-IDs */
	private static function ownedIds(string $uid): array {
		$qb = self::db()->getQueryBuilder();
		$qb->select('id')->from('nextnotes_workspaces')
			->where($qb->expr()->eq('owner_type', $qb->createNamedParameter('user')))
			->andWhere($qb->expr()->eq('owner_id', $qb->createNamedParameter($uid)));
		return array_map('intval', $qb->executeQuery()->fetchFirstColumn());
	}

	/** Zähler der Icon-Leiste in einer Seite (null = Badge versteckt) */
	private static function railBadge(string $html): ?string {
		self::assertMatchesRegularExpression('/id="iconSidebarMentionsBtn"/', $html, 'Erwähnungen in der Icon-Leiste');
		preg_match('/id="iconSidebarMentionsBtn".*?<span class="mention-badge"( hidden)?>([^<]*)<\/span>/s', $html, $m);
		return ($m[1] ?? '') === ' hidden' ? null : ($m[2] ?? null);
	}

	// M4g-r1 Hinweis 1: Zähler zwischengespeichert, Speichern mit geänderter Erwähnung und die Erwähnungsseite aktualisieren sofort
	public function testMentionRailCountCache(): void {
		$a = $this->createUser('ra');
		$b = $this->createUser('rb');
		[$ws, $wsId] = self::newWorkspace($a, 'Zähler');
		Server::get(WorkspaceService::class)->share($a->uid, $wsId, $b->uid, 'write');
		$n = self::newNote($a, $ws, 'Zähler', "<p>Hallo @{$b->uid}</p>");
		$n2 = self::newNote($a, $ws, 'Zweite', "<p>Auch @{$b->uid}</p>");
		self::assertSame('2', self::railBadge(self::get($b, 'index.php')->body), 'zwei offen');
		self::ok($a->call('PATCH', "/notes/$n", ['content' => '<p>ohne</p>', 'workspace' => $ws]), 'Erwähnung entfernt');
		self::assertSame('1', self::railBadge(self::get($b, 'index.php')->body), 'Speichern leert den Zähler von B');
		self::ok($b->call('POST', "/mentions/{$a->uid}/$n2/handled"), 'erledigt');
		self::assertNull(self::railBadge(self::get($b, 'dashboard.php')->body), 'erledigt: Zähler 0');
		self::ok($a->call('PATCH', "/notes/$n", ['content' => "<p>wieder @{$b->uid}</p>", 'workspace' => $ws]), 'Erwähnung neu');
		self::assertSame('1', self::railBadge(self::get($b, 'mentions.php')->body), 'Erwähnungsseite zählt genau');
	}

	// M4g-r1 Hinweis 2: erwähnende Person gelöscht → neutraler Text; Kontolöschung entfernt eigene Benachrichtigungen
	public function testMentionNotificationAfterAccountDeletion(): void {
		$a = $this->createUser('da', 'Anna Autorin');
		$b = $this->createUser('db');
		$c = $this->createUser('dc');
		[$ws, $wsId] = self::newWorkspace($c, 'Dritte');
		Server::get(WorkspaceService::class)->share($c->uid, $wsId, $a->uid, 'write');
		Server::get(WorkspaceService::class)->share($c->uid, $wsId, $b->uid, 'read');
		$n = self::newNote($c, $ws, 'Gemeinsam', '<p>leer</p>');
		self::ok($a->call('PATCH', "/notes/$n", ['content' => "<p>Hallo @{$b->uid}</p>"]), 'A erwähnt B');
		self::assertSame(1, self::mentionNotifications($n, $b->uid));
		// gespeicherte Parameter: UID und Anzeigename beim Senden (Thomas, M4i)
		$qb = self::db()->getQueryBuilder();
		$stored = json_decode((string)$qb->select('subject_parameters')->from('notifications')
			->where($qb->expr()->eq('app', $qb->createNamedParameter('nextnotes')))->andWhere($qb->expr()->eq('user', $qb->createNamedParameter($b->uid)))
			->andWhere($qb->expr()->eq('object_id', $qb->createNamedParameter((string)$n)))->executeQuery()->fetchOne(), true);
		self::assertSame(['by' => $a->uid, 'byName' => 'Anna Autorin'], $stored, 'Parameter der Benachrichtigung');
		$prepare = static function (array $params) use ($b, $n): INotification {
			$x = Server::get(INotificationManager::class)->createNotification();
			$x->setApp('nextnotes')->setUser($b->uid)->setDateTime(new \DateTime())->setObject('note', (string)$n)->setSubject('mention', $params);
			return Server::get(Notifier::class)->prepare($x, 'de');
		};
		self::assertSame('Anna Autorin hat dich in Gemeinsam erwähnt', $prepare(['by' => $a->uid, 'byName' => 'Alter Name'])->getParsedSubject(), 'vorher: aktueller Anzeigename');
		$ownA = self::ownedIds($a->uid);
		Server::get(IUserManager::class)->get($a->uid)?->delete();
		if ($ownA !== []) {
			self::runDeletionJob($ownA[0]);
		}
		$after = $prepare($stored);
		self::assertSame('Anna Autorin hat dich in Gemeinsam erwähnt', $after->getParsedSubject(), 'nach Kontolöschung: gespeicherter Name');
		self::assertStringNotContainsString($a->uid, json_encode($after->getRichSubjectParameters()) ?: '', 'keine UID in den Parametern');
		$legacy = $prepare(['by' => $a->uid]);
		self::assertSame('Jemand hat dich in Gemeinsam erwähnt', $legacy->getParsedSubject(), 'Altbestand ohne byName');
		self::assertStringNotContainsString($a->uid, json_encode($legacy->getRichSubjectParameters()) ?: '', 'Altbestand: keine UID');
		$ownB = self::ownedIds($b->uid);
		Server::get(IUserManager::class)->get($b->uid)?->delete();
		if ($ownB !== []) {
			self::runDeletionJob($ownB[0]);
		}
		self::assertSame(0, self::mentionNotifications($n, $b->uid), 'Kontolöschung: Benachrichtigungen des Empfängers weg');
	}

	// M4g-r1 Hinweis 6 (P27): Ordner-Tags liefern je geänderter Notiz die Version davor und danach
	public function testFolderTagVersions(): void {
		$a = $this->createUser('ta');
		[$ws] = self::newWorkspace($a, 'Tags');
		$fid = (int)self::ok($a->call('POST', '/folders', ['folder_name' => 'Ordner', 'workspace' => $ws]), 'Ordner')['folder_id'];
		$n = self::newNote($a, $ws, 'Eins', '<p>eins ü</p>', $fid);
		$before = self::ok($a->call('GET', "/notes/$n?workspace=" . self::enc($ws)), 'vorher')['note']['version'];
		sleep(1);
		$r = self::ok($a->call('POST', "/folders/$fid/tags", ['tags' => ['neu'], 'workspace' => $ws, 'include_subfolders' => true]), 'Ordner-Tag');
		$after = self::ok($a->call('GET', "/notes/$n?workspace=" . self::enc($ws)), 'nachher')['note']['version'];
		self::assertNotSame($before, $after);
		self::assertSame(['from' => $before, 'to' => $after], $r['versions'][(string)$n] ?? null, 'from/to wie GET');
		$again = self::ok($a->call('POST', "/folders/$fid/tags", ['tags' => ['neu'], 'workspace' => $ws, 'include_subfolders' => true]), 'erneut');
		self::assertSame([], (array)$again['versions'], 'unverändert: keine Version');
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
		self::assertSame(400, self::upload($w, self::keyFor($w, $ws), $png, 'w.png', 'speed_dial')->status, 'Scope speed_dial entfernt (M6z)');
		self::assertSame(200, self::upload($w, self::keyFor($w, $ws), $png, 'w.png')->status, 'Schreib-Empfänger');
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
		self::assertSame(1, self::backgroundFiles($idA, $a->uid), 'eigener Workspace: Bild bis zum Lösch-Job');
		self::assertGreaterThanOrEqual(1, self::runDeletionJob($idA), 'Lösch-Job');
		self::assertSame(0, self::backgroundFiles($idA), 'Lösch-Job: eigene Bilder weg');
		self::assertSame(1, self::backgroundFiles($idB, $b->uid), 'Lösch-Job: B-Bild bleibt');
	}

	/** InitialState „config“ der Seite (Base64) */
	private static function configOf(string $html): ?string {
		return preg_match('/id="initial-state-nextnotes-config" value="([^"]+)"/', $html, $m) ? $m[1] : null;
	}
}
