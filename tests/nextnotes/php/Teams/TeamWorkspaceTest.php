<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Teams;

use OCA\NextNotes\Tests\Support\ApiClient;
use OCA\NextNotes\Tests\Support\Teams;
use OCA\NextNotes\Tests\Support\TeamTestCase;
use OCP\IDBConnection;

// Plan M5a Done 4, 6a, 6d, 7, reorder (SPEC §3 Rollen, §2.2 Quote je Team, §4.4 Schlüssel)
final class TeamWorkspaceTest extends TeamTestCase {
	private const COMPAT = '/index.php/apps/nextnotes/workspaces.php';

	protected function tearDown(): void {
		self::occ('config:app:delete', 'nextnotes', 'attachment_quota_mb');
		parent::tearDown();
	}

	private static function occ(string ...$args): int {
		exec('php /var/www/html/occ ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $out, $rc);
		return $rc;
	}

	private static function compat(ApiClient $c, array $fields): \OCA\NextNotes\Tests\Support\Response {
		return $c->form(self::COMPAT, $fields);
	}

	// Done 4 (Q1): Mitglied verwaltet nicht und legt keine Team-Workspaces an; Moderator ja; Nicht-Mitglied 404
	public function testManageRightsByLevel(): void {
		$a = $this->createUser('ma');
		$b = $this->createUser('mb');
		$m = $this->createUser('mm');
		$e = $this->createUser('me');
		$teamName = 'Klasse ' . bin2hex(random_bytes(3));
		$t = $this->teams->create($a->uid, $teamName);
		$this->teams->addUser($t, $a->uid, $b->uid);
		$this->teams->addUser($t, $a->uid, $m->uid, Teams::MODERATOR);
		[$key] = self::teamWs($a, $t);
		self::assertSame($teamName, self::snapshot($t), 'Team-Zeile mit Name-Snapshot');

		foreach ([['new_name' => $key . ' neu'], ['color' => 'blue'], ['tags' => ['x']]] as $body) {
			self::assertSame(403, $b->call('PATCH', '/workspaces/' . self::enc($key), $body)->status, 'B PATCH ' . json_encode($body));
		}
		self::assertSame(403, $b->call('DELETE', '/workspaces/' . self::enc($key))->status, 'B DELETE');
		self::assertSame(403, self::compat($b, ['action' => 'rename', 'name' => $key, 'new_name' => 'x'])->status, 'B workspaces.php rename');
		self::assertSame(403, $b->call('POST', '/workspaces', ['name' => 'Von B', 'team' => $t])->status, 'B legt Team-Workspace an');
		self::assertSame(404, $e->call('POST', '/workspaces', ['name' => 'Von E', 'team' => $t])->status, 'E (kein Mitglied) legt an');
		self::assertSame(404, $e->call('POST', '/workspaces', ['name' => 'Unbekannt', 'team' => 'gibt-es-nicht'])->status, 'unbekanntes Team');

		self::ok($m->call('PATCH', '/workspaces/' . self::enc($key), ['color' => 'blue']), 'Moderator Farbe');
		$new = $key . ' neu';
		self::ok($m->call('PATCH', '/workspaces/' . self::enc($key), ['new_name' => $new]), 'Moderator umbenennen');
		self::assertContains($new, self::workspaceNames($b), 'B sieht neuen Namen');
		[$keyM] = self::teamWs($m, $t, 'VonM');
		self::assertSame(409, $m->call('POST', '/workspaces', ['name' => $keyM, 'team' => $t])->status, 'Name je Team eindeutig');

		self::assertSame([], self::ok($b->call('GET', '/teams'), 'B /teams')['teams'], 'B: keine Teams zum Anlegen');
		self::assertContains($t, array_column(self::ok($b->call('GET', '/teams?all=1'), 'B /teams?all=1')['teams'], 'id'));
		self::assertSame([['id' => $t, 'name' => $teamName]], self::ok($m->call('GET', '/teams'), 'M /teams')['teams']);
	}

	// Done 6a (Q2): Inhalte in einen Workspace desselben Teams; letzter nur mit confirm; nie privat
	public function testDeleteTeamWorkspace(): void {
		$a = $this->createUser('da');
		$t = $this->teams->create($a->uid, 'Löschen ' . bin2hex(random_bytes(3)));
		[$k1] = self::teamWs($a, $t, 'Eins');
		[, $id2] = self::teamWs($a, $t, 'Zwei');
		$n = self::newNote($a, $k1, 'Wandert', '<p>w</p>');
		self::ok($a->upload("/notes/$n/attachments", 'a.txt', 'bytes'), 'Anhang');
		self::ok($a->call('DELETE', '/workspaces/' . self::enc($k1)), 'W1 löschen');
		self::assertSame((string)$id2, self::noteRow($n)['workspace_id'], 'Notiz liegt in W2');

		[$kPriv] = self::newWorkspace($a, 'Privat');
		[$k3] = self::teamWs($a, $t, 'Drei');
		$r = $a->call('DELETE', '/workspaces/' . self::enc($k3), ['target' => $kPriv]);
		self::assertSame(403, $r->status, 'Ziel privat: ' . $r->describe());
		self::ok(self::compat($a, ['action' => 'delete', 'name' => $k3]), 'workspaces.php delete W3 → W2');

		$k2 = null;
		foreach (self::workspaceNames($a) as $name) {
			if (str_starts_with($name, 'Zwei ')) {
				$k2 = $name;
			}
		}
		self::assertNotNull($k2);
		$r = $a->call('DELETE', '/workspaces/' . self::enc($k2));
		self::assertSame(400, $r->status, 'letzter ohne confirm: ' . $r->describe());
		self::assertSame('confirmation_required', $r->json()['code'] ?? null);
		self::assertSame(400, self::compat($a, ['action' => 'delete', 'name' => $k2])->status, 'workspaces.php ohne confirm');
		self::ok($a->call('DELETE', '/workspaces/' . self::enc($k2), ['confirm' => true]), 'mit confirm');
		$qb = self::db()->getQueryBuilder();
		$qb->select('id')->from('nextnotes_notes')->where($qb->expr()->eq('id', $qb->createNamedParameter($n)));
		self::assertFalse($qb->executeQuery()->fetchOne(), 'Notiz endgültig weg');
		$qb = self::db()->getQueryBuilder();
		$qb->select('id')->from('nextnotes_attachments')->where($qb->expr()->eq('note_id', $qb->createNamedParameter($n)));
		self::assertFalse($qb->executeQuery()->fetchOne(), 'Anhang weg');
	}

	// Done 6d (H6): zwei gleichnamige Teams mit gleichnamigem Workspace → verschiedene, auflösbare Schlüssel
	public function testKeysWithSameNamedTeams(): void {
		$a = $this->createUser('ka');
		$b = $this->createUser('kb');
		$t1 = $this->teams->create($a->uid, 'Klasse 7a');
		$t2 = $this->teams->create($a->uid, 'Klasse 7a');
		$this->teams->addUser($t1, $a->uid, $b->uid);
		$this->teams->addUser($t2, $a->uid, $b->uid);
		// Circles vergibt bei gleichem Namen einen Zusatz; Anzeigenamen per DB angleichen (zwei Teams, gleicher Name)
		$qb = self::db()->getQueryBuilder();
		$qb->update('circles_circle')->set('display_name', $qb->createNamedParameter('Klasse 7a'))
			->where($qb->expr()->in('unique_id', $qb->createNamedParameter([$t1, $t2], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))->executeStatement();
		$name = 'Mathe ' . bin2hex(random_bytes(3));
		self::ok($a->call('POST', '/workspaces', ['name' => $name, 'team' => $t1]), 'W in T1');
		self::ok($a->call('POST', '/workspaces', ['name' => $name, 'team' => $t2]), 'W in T2');
		$keys = array_values(array_filter(self::workspaceNames($b), static fn ($k) => str_starts_with($k, $name)));
		self::assertCount(2, $keys, 'B sieht beide');
		self::assertNotSame($keys[0], $keys[1], 'Schlüssel verschieden');
		foreach ($keys as $k) {
			self::assertMatchesRegularExpression('/^' . preg_quote($name, '/') . ' \(Klasse 7a, #\d+\)$/', $k, implode(' | ', $keys));
			self::ok($b->call('GET', '/notes' . self::q(['workspace' => $k])), "Schlüssel $k auflösbar");
		}
	}

	// Done 7: Uploads mehrerer Mitglieder zählen gegen die Team-Summe; parallele Uploads überschreiten sie nicht
	public function testTeamQuota(): void {
		$a = $this->createUser('qa');
		$b = $this->createUser('qb');
		$t = $this->teams->create($a->uid, 'Quote ' . bin2hex(random_bytes(3)));
		$this->teams->addUser($t, $a->uid, $b->uid);
		[$k1] = self::teamWs($a, $t, 'Q1');
		[$k2] = self::teamWs($a, $t, 'Q2');
		$n1 = self::newNote($b, $k1, 'B', '<p>b</p>');
		$n2 = self::newNote($a, $k2, 'A', '<p>a</p>');
		[$kPriv] = self::newWorkspace($a, 'Privat');
		$nPriv = self::newNote($a, $kPriv, 'P', '<p>p</p>');
		self::assertSame(0, self::occ('config:app:set', 'nextnotes', 'attachment_quota_mb', '--value=1', '--type=integer'));
		sleep(4);
		$att = (int)self::ok($b->upload("/notes/$n1/attachments", 'b.bin', str_repeat('b', 600000)), 'B lädt hoch')['attachment_id'];
		$r = $a->upload("/notes/$n2/attachments", 'a.bin', str_repeat('a', 600000));
		self::assertSame(413, $r->status, 'Team-Summe überschritten: ' . $r->describe());
		self::ok($a->upload("/notes/$nPriv/attachments", 'p.bin', str_repeat('p', 600000)), 'eigene Quote getrennt');
		self::ok($b->call('DELETE', "/notes/$n1/attachments/$att"), 'B löscht');

		$sqlite = self::db()->getDatabaseProvider() === IDBConnection::PLATFORM_SQLITE;
		for ($round = 0; $round < 2; $round++) {
			$rs = $a->parallelUpload([
				["/notes/$n1/attachments", "p$round-1.bin", str_repeat('x', 600000)],
				["/notes/$n2/attachments", "p$round-2.bin", str_repeat('y', 600000)],
			]);
			$st = array_map(static fn ($r) => $r->status, $rs);
			sort($st);
			self::assertContains($st, $sqlite ? [[201, 413], [201, 503]] : [[201, 413]], "Runde $round: " . implode('/', $st));
			foreach ([$n1, $n2] as $n) {
				foreach (self::ok($a->call('GET', "/notes/$n/attachments"), 'Liste')['attachments'] as $x) {
					self::ok($a->call('DELETE', "/notes/$n/attachments/{$x['id']}"), 'Löschen');
				}
			}
		}
	}

	// Reihenfolge je Benutzer: Mitglied ordnet Team-Workspaces für sich, andere bleiben unverändert (R8)
	public function testReorderPerUser(): void {
		$a = $this->createUser('ra');
		$b = $this->createUser('rb');
		$t = $this->teams->create($a->uid, 'Ordnen ' . bin2hex(random_bytes(3)));
		$this->teams->addUser($t, $a->uid, $b->uid);
		[$k1, $id1] = self::teamWs($a, $t, 'A-Erst');
		[$k2, $id2] = self::teamWs($a, $t, 'B-Zweit');
		$before = self::workspaceNames($a);
		$own = self::workspaceNames($b)[0];
		self::ok(self::compat($b, ['action' => 'reorder', 'names' => [$k2, $k1, $own]]), 'B ordnet');
		$afterB = array_values(array_filter(self::workspaceNames($b), static fn ($k) => in_array($k, [$k1, $k2], true)));
		self::assertSame([$k2, $k1], $afterB, 'B: eigene Reihenfolge');
		self::assertSame($before, self::workspaceNames($a), 'A unverändert');
		$qb = self::db()->getQueryBuilder();
		$qb->select('display_order')->from('nextnotes_workspaces')->where($qb->expr()->in('id', $qb->createNamedParameter([$id1, $id2], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
		self::assertSame(['0', '0'], array_map('strval', $qb->executeQuery()->fetchFirstColumn()), 'Team-Zeilen unverändert');
		self::assertSame(400, $b->call('PUT', '/settings/foreign_workspace_order', ['value' => '[1]'])->status, 'interner Schlüssel nicht über Settings-API');
	}

	private static function snapshot(string $teamId): ?string {
		$qb = self::db()->getQueryBuilder();
		$qb->select('team_name_snapshot')->from('nextnotes_teams')->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)));
		$v = $qb->executeQuery()->fetchOne();
		return $v === false ? null : (string)$v;
	}
}
