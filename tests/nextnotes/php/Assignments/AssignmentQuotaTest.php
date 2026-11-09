<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Assignments;

use OCA\NextNotes\Db\NoteMapper;
use OCA\NextNotes\Service\AttachmentService;
use OCA\NextNotes\Service\QuotaService;
use OCP\Server;

// M6d Done 7 (Plan M6 §2.3a): Vorlagen und Kopien zählen beim Team (über notes.assignment_id), nicht beim Schüler; Team voll → 413 ohne Anlage
final class AssignmentQuotaTest extends AssignmentTestCase {
	protected function tearDown(): void {
		exec('php /var/www/html/occ config:app:delete nextnotes attachment_quota_mb 2>&1');
		sleep(4);
		parent::tearDown();
	}

	public function testTeamQuotaForTemplatesAndCopies(): void {
		$fx = $this->fixture();
		$quota = Server::get(QuotaService::class);
		$notes = Server::get(NoteMapper::class);
		$t = $fx['t'];
		$tplBytes = strlen($fx['bytes']);
		$copyBytes = strlen('datei ' . $fx['sub1']['text']);
		$srcBytes = $tplBytes;
		// Team-Summe = Team-Workspace (Quelle) + Vorlage + beide Kopien
		$expectedTeam = $srcBytes + $tplBytes + $copyBytes + strlen('datei ' . $fx['sub2']['text']);
		self::assertSame($expectedTeam, $quota->used('team', $t), 'Team-Summe');
		// Schüler: Kopie zählt nicht (weder used noch usage)
		self::assertSame(0, $quota->used('user', $fx['s1']->uid), 'Benutzer-Summe S1 ohne Kopie');
		self::assertSame(0, Server::get(AttachmentService::class)->usage($fx['s1']->uid)['used'], 'usage S1 ohne Kopie');
		// quotaForNote: Kopie und Vorlage → Team
		$q = $quota->quotaForNote($notes->find($fx['sub1']['note']), 10);
		self::assertSame(['team', $t], [$q['type'], $q['id']], 'quotaForNote Kopie');
		$q = $quota->quotaForNote($notes->find($fx['tpl']), 10);
		self::assertSame(['team', $t], [$q['type'], $q['id']], 'quotaForNote Vorlage');
		// abgelöste Kopie (ohne Workspace) zählt weiter beim Team
		self::setColumn('nextnotes_notes', $fx['sub2']['note'], 'workspace_id', null);
		self::assertSame($expectedTeam, $quota->used('team', $t), 'abgelöste Kopie beim Team');
		// eigener Upload von S1 in seinen privaten Workspace zählt bei S1
		[$pws] = self::newWorkspace($fx['s1'], 'Privat');
		$own = self::newNote($fx['s1'], $pws, 'Eigen', '<p>e</p>');
		self::ok($fx['s1']->upload("/notes/$own/attachments", 'e.txt', 'eigen'), 'eigener Upload');
		self::assertSame(5, $quota->used('user', $fx['s1']->uid), 'S1 nur eigene Datei');

		// Team-Quote voll → Anlegen 413, nichts angelegt (weder Aufgabe noch Vorlage noch Datei)
		$big = str_repeat('x', 700 * 1024);
		$bigNote = self::newNote($fx['l'], $fx['ws'], 'Groß', '<p>g</p>');
		self::ok($fx['l']->upload("/notes/$bigNote/attachments", 'g.bin', $big), 'großer Anhang im Team');
		exec('php /var/www/html/occ config:app:set nextnotes attachment_quota_mb --value=1 --type=integer 2>&1');
		sleep(4);
		$assignments = self::countRows('nextnotes_assignments', 'team_id', $t);
		$templates = self::countRows('nextnotes_notes', 'role', 'template');
		$r = $fx['l']->call('POST', '/assignments', ['workspace' => $fx['ws'], 'title' => 'Zu groß', 'due_at' => time() + 600, 'source_note_id' => $bigNote]);
		self::assertSame(413, $r->status, 'Team-Quote voll: ' . $r->describe());
		self::assertSame($assignments, self::countRows('nextnotes_assignments', 'team_id', $t), 'keine Aufgabe');
		self::assertSame($templates, self::countRows('nextnotes_notes', 'role', 'template'), 'keine Vorlage');
		// Vorlage ohne Anhang braucht keine Quote
		$plain = self::newNote($fx['l'], $fx['ws'], 'Ohne Anhang', '<p>p</p>');
		$this->createAssignment($fx['l'], $t, $plain, 'Ohne Anhang');
		// Upload an die Vorlage zählt beim Team → 413
		self::assertSame(413, $fx['l']->upload('/notes/' . $fx['tpl'] . '/attachments', 'v.bin', str_repeat('y', 400 * 1024))->status, 'Upload an Vorlage über Team-Quote');
		// Benutzer-Quote von S1 ist davon unberührt
		self::ok($fx['s1']->upload("/notes/$own/attachments", 'e2.txt', str_repeat('z', 400 * 1024)), 'S1 privat trotz vollem Team');
	}

	// Done M6e1 7 (Plan M6 §2.3a): 413 vorab ohne Anlage; knappe Quote → teilweise, je Schüler vollständig oder gar nicht; Rest nach Freigabe; Uploads in Kopien gegen die Team-Quote
	public function testDistributeAgainstTeamQuota(): void {
		$fx = $this->fixture(false);
		['l' => $l, 't' => $t, 'aid' => $old] = $fx;
		$size = 300 * 1024;
		$src = self::newNote($l, $fx['ws'], 'Groß', '<p>g</p>');
		self::ok($l->upload("/notes/$src/attachments", 'g.bin', str_repeat('q', $size)), 'großer Anhang');
		$aid = (int)$this->createAssignment($l, $t, $src, 'Quote')['id'];
		self::ok($l->call('DELETE', "/notes/$src?permanent=1"), 'Quelle endgültig löschen');
		Server::get(\OCA\NextNotes\Service\AssignmentDeletion::class)->purge($old);
		exec('php /var/www/html/occ config:app:set nextnotes attachment_quota_mb --value=1 --type=integer 2>&1');
		sleep(4);
		$quota = Server::get(QuotaService::class);
		$available = 1048576 - $quota->used('team', $t);
		self::assertGreaterThan(2 * $size, $available, 'Annahme: zwei Kopien passen');
		self::assertLessThan(3 * $size, $available, 'Annahme: drei Kopien passen nicht');

		$r = $l->call('POST', "/assignments/$aid/distribute");
		self::assertSame([413, 'quota_exceeded', 3 * $size, $available], [$r->status, $r->json()['code'] ?? null, $r->json()['needed'] ?? null, $r->json()['available'] ?? null], $r->describe());
		self::assertSame('draft', self::row('nextnotes_assignments', $aid)['status']);
		self::assertSame([], self::submissionsOf($aid), 'nichts angelegt');

		// wie nach parallelem Upload: Vorprüfung schon bestanden (Status ausgeteilt) → zwei vollständig, einer ohne jede Zeile
		self::setColumn('nextnotes_assignments', $aid, 'status', 'distributed');
		$r = self::ok($l->call('POST', "/assignments/$aid/distribute"), 'teilweise');
		self::assertSame([2, 1, 'quota_exceeded'], [$r['created'], $r['pending'], $r['error'] ?? null]);
		$subs = self::submissionsOf($aid);
		self::assertCount(2, $subs);
		foreach ($subs as $uid => $sub) {
			$note = self::row('nextnotes_notes', (int)$sub['note_id']);
			self::assertNotNull($note, "Kopie $uid");
			self::assertNotNull(self::row('nextnotes_folders', (int)$note['folder_id']), "Ordner $uid");
			self::assertSame(1, self::countRows('nextnotes_attachments', 'note_id', (int)$sub['note_id']), "Anhang $uid");
		}
		$missing = array_values(array_diff([$fx['s1']->uid, $fx['s2']->uid, $fx['x']->uid], array_keys($subs)));
		self::assertCount(1, $missing);
		self::assertSame(0, self::countRows('nextnotes_workspaces', 'owner_id', $missing[0]), 'kein Schüler-Workspace für den Übrigen');
		self::assertSame(1 + 2, count(Server::get(NoteMapper::class)->idsForAssignment($aid)), 'keine halbe Kopie');
		// Freigabe → Rest
		exec('php /var/www/html/occ config:app:set nextnotes attachment_quota_mb --value=2 --type=integer 2>&1');
		sleep(4);
		$r = self::ok($l->call('POST', "/assignments/$aid/distribute"), 'Rest');
		self::assertSame([1, 0], [$r['created'], $r['pending']]);

		// S1 mit voller Benutzer-Quote lädt in die Kopie hoch (Team-Quote)
		$subs = self::submissionsOf($aid);
		[$pws] = self::newWorkspace($fx['s1'], 'Privat');
		$own = self::newNote($fx['s1'], $pws, 'Eigen', '<p>e</p>');
		self::ok($fx['s1']->upload("/notes/$own/attachments", 'voll.bin', str_repeat('v', 2 * 1024 * 1024 - 20 * 1024)), 'Benutzer-Quote fast voll');
		self::assertSame(413, $fx['s1']->upload("/notes/$own/attachments", 'mehr.bin', str_repeat('m', 100 * 1024))->status, 'Benutzer-Quote voll');
		$s1Note = (int)$subs[$fx['s1']->uid]['note_id'];
		self::ok($fx['s1']->upload("/notes/$s1Note/attachments", 'kopie.bin', str_repeat('k', 100 * 1024)), 'Upload in Kopie über Team-Quote');
		// zwei Schüler gleichzeitig, Team-Rest reicht für einen
		$rest = 2 * 1048576 - $quota->used('team', $t);
		$part = (int)($rest * 0.6);
		$s2Note = (int)$subs[$fx['s2']->uid]['note_id'];
		$xNote = (int)$subs[$fx['x']->uid]['note_id'];
		$codes = array_map(static fn ($r) => $r->status, \OCA\NextNotes\Tests\Support\ApiClient::parallelUploadsOf([
			[$fx['s2'], "/notes/$s2Note/attachments", 'p.bin', str_repeat('p', $part)],
			[$fx['x'], "/notes/$xNote/attachments", 'p.bin', str_repeat('p', $part)],
		]));
		sort($codes);
		self::assertSame([201, 413], $codes, 'genau einer 413');
	}
}
