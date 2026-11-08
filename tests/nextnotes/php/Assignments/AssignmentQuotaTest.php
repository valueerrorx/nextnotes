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
		$r = $fx['l']->call('POST', '/assignments', ['team' => $t, 'title' => 'Zu groß', 'due_at' => time() + 600, 'source_note_id' => $bigNote]);
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
}
