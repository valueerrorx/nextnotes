<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\OneNote;

use OCA\NextNotes\OneNote\OneNoteNotifications;
use OCA\NextNotes\Service\Exception\BadRequestException;
use OCA\NextNotes\Service\Exception\ConflictException;
use OCP\Notification\IManager as INotificationManager;
use OCP\Server;

// M9b Done 1, 1a, 2, 3, 3a, 8 (done), 12, 12a: Auswahl, Job, Struktur, Paging, Bericht
final class ImportJobTest extends ImportTestCase {
	// Done 1, 2: eigene vorausgewählt, Teams ohne Vorauswahl, 403 je Team still übersprungen
	public function testTreeWithTeams(): void {
		$u = $this->createUser('tr');
		$this->notebook('me', 'nbA', 2, 0);
		$this->graph->notebooks['me'][0]['sectionGroups'] = [['id' => 'gA', 'displayName' => 'Gruppe', 'sections' => []]];
		$this->graph->teams = [['id' => 'team1', 'displayName' => 'Klasse 7b'], ['id' => 'team2', 'displayName' => 'Verlassen']];
		$this->notebook('groups/team1', 'nbT', 1, 0);
		$this->graph->errors['groups/team2/onenote/notebooks'] = 403;
		$id = $this->signIn($this->connectService($this->http), $u->uid);
		$tree = $this->importer()->tree($u->uid, $id);
		self::assertCount(2, $tree['notebooks']);
		[$own, $team] = $tree['notebooks'];
		self::assertSame(['me', true, null], [$own['root'], $own['preselected'], $own['team']]);
		self::assertSame(['section', 'section', 'section_group'], array_column($own['items'], 'kind'));
		self::assertSame(['group:team1', false, 'Klasse 7b'], [$team['root'], $team['preselected'], $team['team']]);
		self::assertFalse($tree['teams_more']);
	}

	// Done 1: Authority common → kein joinedTeams-Aufruf
	public function testTreeCommonHasNoTeams(): void {
		$this->configure('common', self::CLIENT, self::SECRET, true);
		$u = $this->createUser('tc');
		$this->notebook('me', 'nbA', 1, 0);
		$id = $this->signIn($this->connectService($this->http), $u->uid);
		$tree = $this->importer()->tree($u->uid, $id);
		self::assertCount(1, $tree['notebooks']);
		self::assertSame(0, $this->graph->count('me/joinedTeams'));
		self::assertFalse($tree['teams']);
	}

	// Done 1a: abgelaufener Zugriffstoken → erst Refresh, dann Liste; invalid_grant in select löscht die Zeile
	public function testTreeRefreshesAndInvalidGrantDeletes(): void {
		$u = $this->createUser('tf');
		$this->notebook('me', 'nbA', 1, 0);
		$id = $this->signIn($this->connectService($this->http), $u->uid);
		$this->now += 3600;
		$tokenCalls = count(array_filter($this->http->urls(), static fn ($x) => str_contains($x, '/token')));
		$this->importer()->tree($u->uid, $id);
		self::assertSame($tokenCalls + 1, count(array_filter($this->http->urls(), static fn ($x) => str_contains($x, '/token'))), 'Refresh vor Graph');
		$this->now += 3600;
		$graph = $this->graph;
		$fail = $this->tokenResponder(fail: 'invalid_grant');
		$this->http->responder = fn ($m, $url, $o) => parse_url($url, PHP_URL_HOST) === 'graph.microsoft.com' ? $graph->respond($url) : $fail($m, $url, $o);
		try {
			$this->importer()->tree($u->uid, $id);
			self::fail('409 reconnect erwartet');
		} catch (ConflictException $e) {
			self::assertSame('reconnect', $e->getExtra()['code']);
		}
		self::assertNull(self::row($id));
	}

	// Done 2: ungültige IDs, mehr als 200 Einträge, Team ohne Erlaubnis → 400; Positivliste
	public function testStartValidation(): void {
		$u = $this->createUser('sv');
		$id = $this->signIn($this->connectService($this->http), $u->uid);
		$svc = $this->importer();
		$bad = [
			[],
			[['root' => 'me', 'notebook' => '../x']],
			[['root' => 'evil', 'notebook' => 'a']],
			[['root' => 'me', 'notebook' => 'a', 'items' => [['kind' => 'page', 'id' => 'x']]]],
			[['root' => 'me', 'notebook' => 'a', 'items' => array_map(static fn ($i) => ['kind' => 'section', 'id' => "s$i"], range(1, 200))]],
		];
		foreach ($bad as $k => $sel) {
			try {
				$svc->start($u->uid, $id, $sel);
				self::fail("400 erwartet ($k)");
			} catch (BadRequestException) {
			}
		}
		self::assertSame('select', self::row($id)['status']);
		$this->configure(self::TENANT, self::CLIENT, self::SECRET, false);
		$teamless = $this->createUser('sv2');
		$id2 = $this->signIn($this->connectService($this->http), $teamless->uid);
		try {
			$svc->start($teamless->uid, $id2, [['root' => 'group:t1', 'notebook' => 'a']]);
			self::fail('400 erwartet (Team ohne Erlaubnis)');
		} catch (BadRequestException) {
		}
		$r = $svc->start($teamless->uid, $id2, [['root' => 'me', 'notebook' => 'a', 'items' => [['kind' => 'section', 'id' => 's1']]]]);
		self::assertSame('queued', $r['import']['status']);
		self::assertStringNotContainsString('selection', (string)json_encode($r));
	}

	// Done 3, 3a, 8: Struktur, Paging > 100, Besitz ohne Sitzung, done mit Benachrichtigung, Token gelöscht
	public function testJobImportsStructureWithPaging(): void {
		$u = $this->createUser('jb');
		$this->notebook('me', 'nbA', 1, 130);
		$this->graph->notebooks['me'][0]['sectionGroups'] = [['id' => 'gA', 'displayName' => 'Gruppe: 1', 'sections' => [
			['id' => 'gA-s', 'displayName' => 'Innen', 'pages' => [['id' => 'gp1', 'title' => 'Eltern', 'order' => 0], ['id' => 'gp2', 'title' => 'Kind', 'level' => 1, 'order' => 1]]],
		], 'sectionGroups' => []]];
		$id = $this->started($u->uid, [['root' => 'me', 'notebook' => 'nbA', 'name' => 'Buch A', 'items' => null]]);
		self::assertSame('done', $this->runUntilDone($id));
		self::assertSame(2, $this->graph->count('me/onenote/sections/nbA-s0/pages'), 'Paging über nextLink');
		$row = self::row($id);
		self::assertSame([132, 132, 0], [(int)$row['pages_total'], (int)$row['pages_done'], (int)$row['pages_skipped']]);
		self::assertNoSecrets($id);
		$ws = self::workspacesOf($u->uid);
		$imported = array_values(array_filter($ws, static fn ($w) => $w['name'] === 'Buch nbA'));
		self::assertCount(1, $imported);
		self::assertSame(['user', $u->uid, 'normal'], [$imported[0]['owner_type'], $imported[0]['owner_id'], $imported[0]['kind']]);
		$folders = self::foldersIn((int)$imported[0]['id']);
		self::assertSame(['Abschnitt 0', 'Gruppe- 1', 'Innen'], array_column($folders, 'name'));
		$inner = $folders[2];
		self::assertSame((int)$folders[1]['id'], (int)$inner['parent_id'], 'Abschnitt in der Gruppe');
		$notes = self::notesOf($u->uid);
		self::assertCount(132, $notes);
		foreach ($notes as $n) {
			self::assertSame([$u->uid, $u->uid], [$n['created_by'], $n['updated_by']]);
		}
		$byHeading = array_column($notes, null, 'heading');
		self::assertSame(1704164645, (int)$byHeading['Seite nbA-s0-p 0']['created_at'], 'Datum aus Graph');
		self::assertSame(1706933106, (int)$byHeading['Seite nbA-s0-p 0']['updated_at']);
		self::assertArrayHasKey('› Kind', $byHeading, 'Unterseiten-Präfix');
		self::assertSame((int)$inner['id'], (int)$byHeading['› Kind']['folder_id']);
		self::assertSame(1, $this->graph->count('me/onenote/pages/nbA-s0-p-7/content'), 'jede Seite genau einmal');
		$n = Server::get(INotificationManager::class)->createNotification();
		$n->setApp('nextnotes')->setUser($u->uid)->setObject(OneNoteNotifications::TYPE, (string)$id);
		self::assertSame(1, Server::get(INotificationManager::class)->getCount($n), 'onenote_done');
	}

	// Done 12a: 403 für Abschnitt und Seite → übersprungen, Bericht, keine Serie, kein Backoff, done
	public function testForbiddenIsSkipped(): void {
		$u = $this->createUser('fb');
		$this->notebook('me', 'nbF', 2, 3);
		$this->graph->errors['me/onenote/sections/nbF-s1/pages'] = 403;
		$this->graph->errors['me/onenote/pages/nbF-s0-p-1/content'] = 403;
		$id = $this->started($u->uid, [['root' => 'me', 'notebook' => 'nbF', 'items' => null]]);
		$this->importer()->runDue();
		$row = self::row($id);
		self::assertSame('done', $row['status']);
		self::assertSame([2, 0, 0], [(int)$row['pages_done'], (int)$row['not_found_streak'], (int)$row['backoff_level']]);
		self::assertSame(['forbidden', 'forbidden'], array_column(self::report($id), 'r'));
	}

	// Done 12: 20 Seiten-404 in Folge → failed; 19 + 1 angelegt + 19 → kein failed
	public function testNotFoundStreak(): void {
		$u = $this->createUser('nf');
		$this->notebook('me', 'nbN', 1, 39);
		for ($i = 0; $i < 39; $i++) {
			if ($i !== 19) {
				$this->graph->errors["me/onenote/pages/nbN-s0-p-$i/content"] = 404;
			}
		}
		$id = $this->started($u->uid, [['root' => 'me', 'notebook' => 'nbN', 'items' => null]]);
		self::assertSame('done', $this->runUntilDone($id));
		self::assertSame(19, (int)self::row($id)['not_found_streak']);
		self::assertSame(1, (int)self::row($id)['pages_done']);

		$v = $this->createUser('nf2');
		$this->graph->notebooks['me'] = [];
		$this->notebook('me', 'nbM', 1, 25);
		for ($i = 0; $i < 25; $i++) {
			$this->graph->errors["me/onenote/pages/nbM-s0-p-$i/content"] = 404;
		}
		$id2 = $this->started($v->uid, [['root' => 'me', 'notebook' => 'nbM', 'items' => null]]);
		self::assertSame('failed', $this->runUntilDone($id2));
		self::assertSame('graph_not_found', self::row($id2)['error_code']);
		self::assertSame(20, $this->graph->count('me/onenote/pages/nbM-'), 'Abbruch nach der 20.');
		self::assertNoSecrets($id2);
	}

	// Ressource fehlt (404) → Platzhalter, zählt nicht zur 404-Serie (M9d holt Ressourcen)
	public function testResourcesArePlaceholders(): void {
		$u = $this->createUser('rs');
		$this->notebook('me', 'nbR', 1, 1);
		$this->graph->notebooks['me'][0]['sections'][0]['pages'][0]['html'] = '<div><p>Text</p><img src="https://graph.microsoft.com/v1.0/me/onenote/resources/res1/$value"></div>';
		$id = $this->started($u->uid, [['root' => 'me', 'notebook' => 'nbR', 'items' => null]]);
		$this->importer()->runDue();
		$row = self::row($id);
		self::assertSame(['done', 1, 0], [$row['status'], (int)$row['resources_failed'], (int)$row['not_found_streak']]);
		self::assertSame(1, $this->graph->count('me/onenote/resources'));
		self::assertStringNotContainsString('graph.microsoft.com', (string)self::notesOf($u->uid)[0]['content']);
	}
}
