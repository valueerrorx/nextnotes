<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Assignments;

use OCA\NextNotes\Db\AssignmentMapper;
use OCA\NextNotes\Service\DistributionService;
use OCA\NextNotes\Tests\Support\Teams;
use OCP\Server;

// Done M6e1 2, 4, 6 (SPEC §9.1/§9.4, Plan M6 §2.4): Austeilen vollständig je Schüler, idempotent, Nachzügler, Frist, Rennen mit Löschen/Schließen; Quelle (Lead 2026-11-08)
final class DistributeTest extends AssignmentTestCase {
	public function testDistributeCreatesCompleteSubmissions(): void {
		$fx = $this->distributedFixture();
		['l' => $l, 'aid' => $aid, 't' => $t] = $fx;
		self::assertSame(3, $fx['distribute']['created'], 'S1, S2, X (Level 1); L/M nicht');
		self::assertSame(0, $fx['distribute']['pending']);
		self::assertSame('distributed', self::row('nextnotes_assignments', $aid)['status']);
		self::assertSame([$fx['s1']->uid, $fx['s2']->uid, $fx['x']->uid], self::sorted(array_keys($fx['subs'])), 'Abgaben genau der Schüler');
		foreach ($fx['subs'] as $uid => $sub) {
			$note = self::row('nextnotes_notes', (int)$sub['note_id']);
			$ws = self::row('nextnotes_workspaces', (int)$note['workspace_id']);
			$folder = self::row('nextnotes_folders', (int)$note['folder_id']);
			self::assertSame(['submission', $aid, (int)$sub['id']], [$note['role'], (int)$note['assignment_id'], (int)$note['submission_id']], "Kopie $uid");
			self::assertSame(['user', $uid, 'assignments', $t], [$ws['owner_type'], $ws['owner_id'], $ws['kind'], $ws['linked_team_id']], "Schüler-Workspace $uid");
			self::assertSame([$aid, (int)$ws['id']], [(int)$folder['assignment_id'], (int)$folder['workspace_id']], "Aufgaben-Ordner $uid");
			self::assertSame('assigned', $sub['status']);
			self::assertMatchesRegularExpression('#/api/v1/notes/' . $note['id'] . '/attachments/\d+#', (string)$note['content'], 'Inhalts-URL umgeschrieben');
		}
		$s1Note = (int)$fx['subs'][$fx['s1']->uid]['note_id'];
		$att = (int)self::ok($fx['s1']->call('GET', "/notes/$s1Note/attachments"), 'Anhänge S1')['attachments'][0]['id'];
		self::assertSame($fx['bytes'], $fx['s1']->call('GET', "/notes/$s1Note/attachments/$att")->body, 'Anhang physisch kopiert');
		self::assertSame(404, $fx['s2']->call('GET', "/notes/$s1Note")->status, 'S2 auf S1-Kopie');
		self::assertSame('Schüler Eins', $fx['subs'][$fx['s1']->uid]['student_name'], 'student_name');

		// erneut austeilen: nichts doppelt; Schüler darf nicht
		$again = self::ok($l->call('POST', "/assignments/$aid/distribute"), 'erneut');
		self::assertSame([0, 0], [$again['created'], $again['pending']]);
		self::assertCount(3, self::submissionsOf($aid));
		self::assertSame(403, $fx['s1']->call('POST', "/assignments/$aid/distribute")->status, 'S1 austeilen (liest die Aufgabe über die eigene Abgabe)');
	}

	// §9.4: 2× distribute gleichzeitig → je Schüler genau eine Abgabe und eine Kopie
	public function testParallelDistributeCreatesNoDuplicates(): void {
		$fx = $this->fixture(false);
		$path = "/assignments/{$fx['aid']}/distribute";
		$rs = $fx['l']->parallel([['POST', $path], ['POST', $path]]);
		foreach ($rs as $r) {
			self::assertSame(200, $r->status, $r->describe());
		}
		self::assertSame(3, $rs[0]->json()['created'] + $rs[1]->json()['created'], 'zusammen genau 3 angelegt');
		self::assertCount(3, self::submissionsOf($fx['aid']));
		self::assertSame(4, count(Server::get(\OCA\NextNotes\Db\NoteMapper::class)->idsForAssignment($fx['aid'])), 'Vorlage + 3 Kopien');
		self::assertSame(3, self::countRows('nextnotes_folders', 'assignment_id', $fx['aid']), 'je Schüler ein Ordner');
	}

	// Done 2/4: Nachzügler vor Frist genau 1, nach Frist 0; distribute nach Frist 409; Verlängerung setzt missed → assigned und teilt Nachzügler aus
	public function testLateJoinerDeadlineAndExtension(): void {
		$fx = $this->distributedFixture();
		['l' => $l, 'aid' => $aid, 't' => $t] = $fx;
		$y = $this->createUser('ay');
		$this->teams->addUser($t, $l->uid, $y->uid);
		self::ok($y->call('GET', '/assignments'), 'Y öffnet Aufgabenliste');
		self::ok($y->call('GET', '/assignments'), 'zweimal');
		self::assertArrayHasKey($y->uid, self::submissionsOf($aid), 'Nachzügler vor Frist');
		self::assertCount(4, self::submissionsOf($aid));

		self::setColumn('nextnotes_assignments', $aid, 'due_at', time() - 10);
		$z = $this->createUser('az');
		$this->teams->addUser($t, $l->uid, $z->uid);
		self::ok($z->call('GET', '/assignments'), 'Z öffnet Aufgabenliste');
		self::assertArrayNotHasKey($z->uid, self::submissionsOf($aid), 'Nachzügler nach Frist');
		$r = $l->call('POST', "/assignments/$aid/distribute");
		self::assertSame([409, 'deadline_passed'], [$r->status, $r->json()['code'] ?? null], 'distribute nach Frist');
		// Zugriff materialisiert: S1 → missed
		self::ok($fx['s1']->call('GET', '/notes/' . $fx['subs'][$fx['s1']->uid]['note_id']), 'S1 liest');
		self::assertSame('missed', self::submissionsOf($aid)[$fx['s1']->uid]['status']);
		// Verlängerung: missed ohne Abgabezeit → assigned, Z bekommt seine Abgabe
		self::ok($l->call('PATCH', "/assignments/$aid", ['due_at' => time() + 3600]), 'verlängern');
		$subs = self::submissionsOf($aid);
		self::assertSame('assigned', $subs[$fx['s1']->uid]['status'], 'missed → assigned');
		self::assertArrayHasKey($z->uid, $subs, 'Nachzügler nach Verlängerung');
	}

	// Done 6 (H1): DELETE bzw. close parallel zum Austeilen → keine Waisen, nach close keine neue Abgabe
	public function testRaceWithDeleteAndClose(): void {
		$fx = $this->fixture(false);
		['l' => $l, 'aid' => $aid] = $fx;
		$rs = $l->parallel([['POST', "/assignments/$aid/distribute"], ['DELETE', "/assignments/$aid", ['confirm' => true]]]);
		self::assertContains($rs[1]->status, [200], 'DELETE');
		self::assertContains($rs[0]->status, [200, 404], 'distribute');
		self::assertSame(0, self::countRows('nextnotes_submissions', 'assignment_id', $aid), 'keine Abgabe');
		self::assertSame(0, self::countRows('nextnotes_notes', 'assignment_id', $aid), 'keine Kopie/Vorlage');
		self::assertSame(0, self::countRows('nextnotes_folders', 'assignment_id', $aid), 'kein Ordner');

		$b = $this->createAssignment($l, $fx['t'], $fx['src'], 'Schließen');
		$bid = (int)$b['id'];
		$rs = $l->parallel([['POST', "/assignments/$bid/distribute"], ['POST', "/assignments/$bid/close"]]);
		// close vor dem Statuswechsel trifft noch einen Entwurf (409); dann nach dem Austeilen schließen
		if ($rs[1]->status === 409) {
			self::ok($l->call('POST', "/assignments/$bid/close"), 'close nach distribute');
		}
		$before = count(self::submissionsOf($bid));
		self::assertSame('closed', self::row('nextnotes_assignments', $bid)['status'], 'geschlossen: ' . $rs[1]->describe());
		$r = Server::get(DistributionService::class)->reconcile(Server::get(AssignmentMapper::class)->find($bid));
		self::assertSame(0, $r['created'], 'reconcile nach close');
		$y = $this->createUser('ay');
		$this->teams->addUser($fx['t'], $l->uid, $y->uid);
		self::ok($y->call('GET', '/assignments'), 'Y');
		self::assertCount($before, self::submissionsOf($bid), 'nach close keine neue Abgabe');
		self::assertSame(409, $l->call('POST', "/assignments/$bid/distribute")->status, 'distribute nach close');
	}

	// Lead 2026-11-08: Quelle nur eigene Notizen oder Workspaces desselben Teams; fremde Lesefreigabe und anderes Team → 403
	public function testSourceRestriction(): void {
		$fx = $this->fixture(false);
		['l' => $l, 't' => $t] = $fx;
		[$own] = self::newWorkspace($l, 'Privat');
		$this->createAssignment($l, $t, self::newNote($l, $own, 'Eigen', '<p>e</p>'), 'aus privat');
		$other = $this->teams->create($l->uid, 'Anderes ' . bin2hex(random_bytes(3)));
		[$otherWs] = self::teamWs($l, $other, 'Andere');
		$foreignTeam = self::newNote($l, $otherWs, 'Fremdes Team', '<p>f</p>');
		[$nWs, $nWsId] = self::newWorkspace($fx['n'], 'Geteilt');
		$shared = self::newNote($fx['n'], $nWs, 'Geteilt', '<p>g</p>');
		self::rawShare($nWsId, $l->uid, 'read');
		$link = (int)self::ok($l->call('POST', '/notes', ['heading' => 'Link fremd', 'type' => 'linked', 'linked_note_id' => $foreignTeam, 'workspace' => $fx['ws']]), 'Link')['note']['id'];
		$body = static fn (int $src) => ['team' => $t, 'title' => 'x', 'due_at' => time() + 600, 'source_note_id' => $src];
		$count = self::countRows('nextnotes_assignments', 'team_id', $t);
		foreach (['anderes Team' => $foreignTeam, 'Lesefreigabe' => $shared, 'Verknüpfung auf anderes Team' => $link] as $what => $src) {
			$r = $l->call('POST', '/assignments', $body($src));
			self::assertSame([403, 'source_not_allowed'], [$r->status, $r->json()['code'] ?? null], $what);
		}
		self::assertSame($count, self::countRows('nextnotes_assignments', 'team_id', $t), 'nichts angelegt');
	}

	/** @param string[] $list */
	private static function sorted(array $list): array {
		sort($list);
		return $list;
	}
}
