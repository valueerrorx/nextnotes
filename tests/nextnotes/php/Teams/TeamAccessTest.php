<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Teams;

use OCA\NextNotes\Db\NoteMapper;
use OCA\NextNotes\Service\AccessService;
use OCA\NextNotes\Service\Exception\ForbiddenException;
use OCA\NextNotes\Service\WorkspaceService;
use OCA\NextNotes\Tests\Support\ApiClient;
use OCA\NextNotes\Tests\Support\Response;
use OCA\NextNotes\Tests\Support\Teams;
use OCA\NextNotes\Tests\Support\TeamTestCase;
use OCP\IDBConnection;
use OCP\Server;
use OCP\Teams\ITeamManager;

// Plan M5a Done 1, 2, 3, 6b, 6c, 8 (SPEC §3 Team-Rechte, §13 M5 Krit. 1, 2, 6)
final class TeamAccessTest extends TeamTestCase {
	private static function refused(Response $r, string $what): void {
		self::assertContains($r->status, [403, 404], "$what: erwartet 403/404, erhalten " . $r->describe());
	}

	private static function notFound(Response $r, string $what): void {
		self::assertSame(404, $r->status, "$what: erwartet 404, erhalten " . $r->describe());
	}

	// Antwort ohne das Echo des Suchbegriffs
	private static function withoutQuery(Response $r): string {
		$j = $r->json();
		if (is_array($j)) {
			unset($j['query'], $j['q']);
			return (string)json_encode($j, JSON_UNESCAPED_UNICODE);
		}
		return $r->body;
	}

	/** Alle Lesewege eines Benutzers ohne Inhalte von $mark bzw. mit 404 auf die Objekte */
	private static function assertNoAccess(ApiClient $c, string $wsKey, int $note, ?int $folder, ?int $att, string $mark, string $who): void {
		self::notFound($c->call('GET', "/notes/$note"), "$who GET Notiz");
		self::notFound($c->call('PATCH', "/notes/$note", ['heading' => 'x', 'content' => '<p>x</p>']), "$who PATCH Notiz");
		if ($folder !== null) {
			self::notFound($c->call('GET', "/folders/$folder"), "$who GET Ordner");
		}
		if ($att !== null) {
			self::notFound($c->call('GET', "/notes/$note/attachments/$att"), "$who GET Anhang");
		}
		self::assertNotContains($wsKey, self::workspaceNames($c), "$who sieht Workspace");
		self::notFound($c->call('GET', '/notes' . self::q(['workspace' => $wsKey])), "$who Liste mit Team-Workspace");
		foreach (['/notes', '/notes/search?q=' . rawurlencode($mark), '/graph', '/notes/with-attachments', '/tasks', '/folders'] as $p) {
			self::assertStringNotContainsString($mark, self::withoutQuery($c->call('GET', $p)), "$who $p zeigt Team-Inhalt");
		}
		self::refused($c->call('GET', '/changes' . self::q(['workspace' => $wsKey, 'note_ids' => (string)$note])), "$who /changes");
		$r = $c->absolute('GET', '/ocs/v2.php/search/providers/nextnotes/search?format=json&term=' . rawurlencode($mark));
		self::assertStringNotContainsString($mark, $r->body, "$who Unified Search");
	}

	// Krit. 1 und 2: Mitglied sieht, liest, schreibt; Ressource im Kern-TeamManager; Entfernen → sofort 404 ohne Job
	public function testMemberAccessAndRemoval(): void {
		$a = $this->createUser('ta');
		$b = $this->createUser('tb');
		$e = $this->createUser('te');
		$t = $this->teams->create($a->uid, 'Team ' . bin2hex(random_bytes(3)));
		$memberB = $this->teams->addUser($t, $a->uid, $b->uid);
		[$key, $wsId] = self::teamWs($a, $t);

		self::assertContains($key, self::workspaceNames($b), 'B sieht Team-Workspace');
		self::assertNotContains($key, self::workspaceNames($e), 'E sieht Team-Workspace');
		$mark = 'Teamgeheim' . bin2hex(random_bytes(3));
		$n = self::newNote($b, $key, $mark, "<p>$mark</p>");
		$f = (int)self::ok($b->call('POST', '/folders', ['folder_name' => "Ordner $mark", 'workspace' => $key]), 'Ordner')['folder_id'];
		$att = (int)self::ok($b->upload("/notes/$n/attachments", 'a.txt', "inhalt $mark"), 'Anhang')['attachment_id'];
		self::ok($b->call('PATCH', "/notes/$n", ['heading' => $mark, 'content' => "<p>$mark neu</p>"]), 'B schreibt');
		self::ok($a->call('GET', "/notes/$n"), 'A liest B-Notiz');
		self::assertSame($b->uid, self::noteRow($n)['created_by']);

		$tm = Server::get(ITeamManager::class);
		$ids = array_map(static fn ($r) => $r->getId(), $tm->getSharedWith($t, $b->uid));
		self::assertContains((string)$wsId, $ids, 'getSharedWith(T, B) enthält den Workspace');
		self::assertSame([], array_filter($tm->getSharedWith($t, $e->uid), static fn ($r) => $r->getProvider()->getId() === 'nextnotes'), 'getSharedWith für Nicht-Mitglied leer');
		self::assertSame([$t], $tm->getProvider('nextnotes')->getTeamsForResource((string)$wsId));

		self::assertNoAccess($e, $key, $n, $f, $att, $mark, 'E');

		$this->teams->remove($a->uid, $memberB);
		self::assertNoAccess($b, $key, $n, $f, $att, $mark, 'B nach Entfernen');
		self::ok($a->call('GET', "/notes/$n"), 'A liest weiter');
		self::assertSame('active', self::teamState($t), 'Zustand unverändert');
	}

	// Krit. 6: Mitglied über Gruppe sieht und schreibt; Eingeladener (Status Invited) nicht
	public function testGroupMemberAndInvited(): void {
		$a = $this->createUser('ga');
		$c = $this->createUser('gc');
		$d = $this->createUser('gd');
		$t = $this->teams->create($a->uid, 'Gruppe ' . bin2hex(random_bytes(3)));
		$this->teams->addGroup($t, $a->uid, [$c->uid]);
		[$key] = self::teamWs($a, $t);
		self::assertContains($key, self::workspaceNames($c), 'C (Gruppe) sieht Team-Workspace');
		$n = self::newNote($c, $key, 'Gruppe schreibt', '<p>c</p>');
		self::ok($a->call('GET', "/notes/$n"), 'A liest C-Notiz');

		$ti = $this->teams->create($a->uid, 'Einladung ' . bin2hex(random_bytes(3)), Teams::INVITE);
		$this->teams->addUser($ti, $a->uid, $d->uid);
		[$keyI] = self::teamWs($a, $ti);
		$mark = 'Eingeladen' . bin2hex(random_bytes(3));
		$nI = self::newNote($a, $keyI, $mark, "<p>$mark</p>");
		self::assertNoAccess($d, $keyI, $nI, null, null, $mark, 'D (eingeladen)');
	}

	// Done 6b: Freigaben gelten nur für Benutzer-Workspaces; Freigabezeile auf Team-Workspace verleiht nichts (aktiv, verwaist, eingefroren)
	public function testSharesOnTeamWorkspacesGrantNothing(): void {
		$a = $this->createUser('sa');
		$b = $this->createUser('sb');
		$e = $this->createUser('se');
		$t = $this->teams->create($a->uid, 'Teilen ' . bin2hex(random_bytes(3)));
		$this->teams->addUser($t, $a->uid, $b->uid);
		[$key, $wsId] = self::teamWs($a, $t);
		$mark = 'Freigabe' . bin2hex(random_bytes(3));
		$n = self::newNote($a, $key, $mark, "<p>$mark</p>");
		try {
			Server::get(WorkspaceService::class)->share($a->uid, $wsId, $e->uid);
			self::fail('share() auf Team-Workspace muss 403 liefern');
		} catch (ForbiddenException) {
		}
		self::rawShare($wsId, $e->uid, 'write');
		self::assertNoAccess($e, $key, $n, null, null, $mark, 'E mit Freigabezeile (aktiv)');

		self::setTeamState($t, 'orphaned');
		self::assertNoAccess($e, $key, $n, null, null, $mark, 'E mit Freigabezeile (verwaist)');
		self::assertNoAccess($b, $key, $n, null, null, $mark, 'B (verwaist)');
		self::ok($a->call('GET', "/notes/$n"), 'created_by liest (verwaist)');
		self::assertSame(403, $a->call('PATCH', "/notes/$n", ['heading' => 'x', 'content' => '<p>x</p>'])->status, 'created_by schreibt nicht (verwaist)');
		self::assertSame(403, $a->call('POST', '/notes', ['heading' => 'neu', 'content' => '<p>n</p>', 'workspace' => $key])->status, 'kein Anlegen (verwaist)');

		// eingefroren (Circles deaktiviert, M5a-r1 H5): gleiche Regel wie verwaist, gespeicherter Zustand bleibt
		self::setTeamState($t, 'active');
		self::assertSame(0, self::occ('app:disable', 'circles'));
		try {
			sleep(4);
			self::assertNoAccess($e, $key, $n, null, null, $mark, 'E mit Freigabezeile (eingefroren)');
			self::assertNoAccess($b, $key, $n, null, null, $mark, 'B (eingefroren)');
			self::ok($a->call('GET', "/notes/$n"), 'created_by liest (eingefroren)');
			self::assertSame(403, $a->call('PATCH', "/notes/$n", ['heading' => 'x', 'content' => '<p>x</p>'])->status, 'created_by schreibt nicht (eingefroren)');
			self::assertSame(403, $a->call('POST', '/notes', ['heading' => 'neu', 'content' => '<p>n</p>', 'workspace' => $key])->status, 'kein Anlegen (eingefroren)');
			self::assertSame([], self::ok($a->call('GET', '/teams?all=1'), 'Teams ohne Circles')['teams']);
		} finally {
			self::occ('app:enable', 'circles');
			sleep(4);
		}
		self::assertSame('active', self::teamState($t), 'Zustand unverändert');
		self::ok($b->call('GET', "/notes/$n"), 'B liest wieder (aktiv)');
	}

	private static function occ(string ...$args): int {
		exec('php /var/www/html/occ ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $out, $rc);
		return $rc;
	}

	// Done 6c: versteckte und Backend-Kreise zählen nie (Anlegen 403, /teams ohne sie, Rohdaten 404, filterReadable ≡ noteLevel); nachträglich versteckt → 404, Zustand bleibt
	public function testHiddenAndBackendCirclesDoNotCount(): void {
		$a = $this->createUser('ha');
		$b = $this->createUser('hb');
		$access = Server::get(AccessService::class);
		foreach (['versteckt' => Teams::HIDDEN, 'Backend' => Teams::BACKEND] as $label => $flag) {
			$th = $this->teams->create($a->uid, "Kreis $label " . bin2hex(random_bytes(3)), $flag);
			$this->teams->addUser($th, $a->uid, $b->uid);
			$r = $a->call('POST', '/workspaces', ['name' => "WS $label", 'team' => $th]);
			self::assertSame(403, $r->status, "$label: Anlegen " . $r->describe());
			$all = self::ok($a->call('GET', '/teams?all=1'), '/teams?all=1')['teams'];
			self::assertNotContains($th, array_column($all, 'id'), "$label: /teams?all=1");
			$raw = $this->rawWorkspace('team', $th, $a->uid);
			$mark = "Roh$label" . bin2hex(random_bytes(3));
			$n = self::rawNote($raw, $mark, $a->uid);
			foreach ([$a, $b] as $c) {
				self::notFound($c->call('GET', "/notes/$n"), "$label: {$c->uid} Rohnotiz");
				self::assertStringNotContainsString($mark, self::withoutQuery($c->call('GET', '/notes/search?q=' . rawurlencode($mark))), "$label: Suche");
				$access->resetCache();
				$note = Server::get(NoteMapper::class)->find($n);
				self::assertSame(AccessService::NONE, $access->noteLevel($c->uid, $note), "$label: noteLevel");
				self::assertSame([], $access->filterReadable($c->uid, [$note]), "$label: filterReadable");
			}
		}

		$tl = $this->teams->create($a->uid, 'Später versteckt ' . bin2hex(random_bytes(3)));
		$this->teams->addUser($tl, $a->uid, $b->uid);
		[$key] = self::teamWs($a, $tl);
		$mark = 'Spaet' . bin2hex(random_bytes(3));
		$n = self::newNote($b, $key, $mark, "<p>$mark</p>");
		$this->teams->setConfig($tl, Teams::HIDDEN);
		self::assertNoAccess($b, $key, $n, null, null, $mark, 'B nach Verstecken');
		self::notFound($a->call('GET', "/notes/$n"), 'A (Owner) nach Verstecken');
		self::assertSame('active', self::teamState($tl), 'Zustand bleibt active');
	}

	// Done 8: visibleWorkspaceIds fragt Circles einmal und Team-Workspaces mit einer SQL-Abfrage, unabhängig von der Zahl der Teams
	public function testVisibleWorkspaceIdsQueryCountIndependentOfTeams(): void {
		$a = $this->createUser('qa');
		$b = $this->createUser('qb');
		$access = Server::get(AccessService::class);
		$count = function (string $uid) use ($access): int {
			$access->resetCache();
			$inner = Server::get(IDBConnection::class)->getInner();
			$before = $inner->getStats()['executed'];
			$access->visibleWorkspaceIds($uid);
			$access->writableWorkspaceIds($uid);
			$access->visibleWorkspaceIds($uid);
			return $inner->getStats()['executed'] - $before;
		};
		$t = $this->teams->create($a->uid, 'Q 0');
		$this->teams->addUser($t, $a->uid, $b->uid);
		self::teamWs($a, $t);
		$count($b->uid); // Circles legt beim ersten Zugriff ggf. Einträge an
		$one = $count($b->uid);
		for ($i = 1; $i < 20; $i++) {
			$ti = $this->teams->create($a->uid, "Q $i");
			$this->teams->addUser($ti, $a->uid, $b->uid);
			self::teamWs($a, $ti);
		}
		$twenty = $count($b->uid);
		self::assertGreaterThanOrEqual(20, count($access->visibleWorkspaceIds($b->uid)), 'B sieht 20 Team-Workspaces');
		self::assertSame($one, $twenty, "Abfragen mit 1 Team: $one, mit 20 Teams: $twenty");
	}
}
