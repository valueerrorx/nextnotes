<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Assignments;

use OCA\NextNotes\Db\AssignmentMapper;
use OCA\NextNotes\Db\FolderMapper;
use OCA\NextNotes\Db\NoteMapper;
use OCA\NextNotes\Db\SubmissionMapper;
use OCA\NextNotes\Db\WorkspaceMapper;
use OCA\NextNotes\Db\WsShareMapper;
use OCA\NextNotes\Service\AccessService;
use OCA\NextNotes\Service\Exception\ForbiddenException;
use OCA\NextNotes\Service\Exception\NotFoundException;
use OCA\NextNotes\Service\TeamService;
use OCA\NextNotes\Service\TeamStateService;
use OCP\IDBConnection;
use OCP\Server;

// M6d Done 2, 3, 3a: Matrix SPEC §9.7 je Zelle (inkl. review), fremde Abgaben nur Lehrkraft-Aktion, Vorlage nur review, visibleAssignmentIds
final class AssignmentAccessTest extends AssignmentTestCase {
	private const ACTIONS = ['read', 'review', 'edit', 'distribute', 'close', 'feedback', 'return', 'reopen', 'export', 'delete'];

	/** @return array<string, int> Aktion → 200 erlaubt / 403 / 404 */
	private static function outcome(AccessService $access, string $uid, int $aid): array {
		$out = [];
		foreach (self::ACTIONS as $action) {
			try {
				$access->requireAssignment($uid, $aid, $action);
				$out[$action] = 200;
			} catch (ForbiddenException) {
				$out[$action] = 403;
			} catch (NotFoundException) {
				$out[$action] = 404;
			}
		}
		return $out;
	}

	/** @param array<string, int> $only Ausnahmen vom Standard */
	private static function expect(int $default, array $only = []): array {
		return array_merge(array_fill_keys(self::ACTIONS, $default), $only);
	}

	// Done 2: aktiv/verwaist/eingefroren × L (Ersteller, created_by), M (Lehrkraft), X (owner_uid, Level 1), S1, N; nie über created_by
	public function testMatrixPerCell(): void {
		$fx = $this->fixture();
		$aid = $fx['aid'];
		// owner_uid an X (Mitglied Level 1): im aktiven Team ohne Rechte, verwaist/eingefroren verantwortlich
		self::setColumn('nextnotes_assignments', $aid, 'owner_uid', $fx['x']->uid);
		$access = Server::get(AccessService::class);
		$teacher = self::expect(200);
		$student = self::expect(403, ['read' => 200, 'review' => 404]);
		$none = self::expect(404);
		$ownerOrphaned = self::expect(403, ['read' => 200, 'review' => 200, 'export' => 200, 'delete' => 200]);
		$ownerFrozen = self::expect(403, ['read' => 200, 'review' => 200, 'export' => 200]);
		$cases = [
			'active' => ['l' => $teacher, 'm' => $teacher, 'x' => $none, 's1' => $student, 'n' => $none],
			'orphaned' => ['l' => $none, 'm' => $none, 'x' => $ownerOrphaned, 's1' => $student, 'n' => $none],
		];
		foreach ($cases as $state => $actors) {
			self::setTeamState($fx['t'], $state);
			$access->resetCache();
			foreach ($actors as $who => $expected) {
				self::assertSame($expected, self::outcome($access, $fx[$who]->uid, $aid), "$state / $who");
			}
		}
		// eingefroren (Teams-App aus): Zustand berechnet, Level nie gefragt
		self::setTeamState($fx['t'], 'active');
		$frozen = $this->frozenAccess();
		foreach (['l' => $none, 'm' => $none, 'x' => $ownerFrozen, 's1' => $student, 'n' => $none] as $who => $expected) {
			self::assertSame($expected, self::outcome($frozen, $fx[$who]->uid, $aid), "frozen / $who");
		}

		// HTTP-Stichprobe der vorhandenen Routen (aktiv): read, review, edit, delete ohne confirm
		$access->resetCache();
		$probe = fn ($c) => [
			$c->call('GET', "/assignments/$aid")->status,
			$c->call('GET', "/assignments/$aid/submissions")->status,
			$c->call('PATCH', "/assignments/$aid", ['title' => 'Neu'])->status,
			$c->call('DELETE', "/assignments/$aid")->status,
		];
		self::assertSame([200, 200, 200, 400], $probe($fx['m']), 'M aktiv');
		self::assertSame([200, 404, 403, 403], $probe($fx['s1']), 'S1 aktiv');
		self::assertSame([404, 404, 404, 404], $probe($fx['x']), 'X (owner_uid, Level 1) aktiv');
		self::assertSame([404, 404, 404, 404], $probe($fx['n']), 'N aktiv');
		self::setTeamState($fx['t'], 'orphaned');
		self::assertSame([200, 200, 403, 400], $probe($fx['x']), 'X verwaist');
		self::assertSame([404, 404, 404, 404], $probe($fx['l']), 'L (created_by) verwaist');
		self::setTeamState($fx['t'], 'active');
	}

	// AccessService mit Teams-App „aus“ (TeamService::isAvailable false → frozen)
	private function frozenAccess(): AccessService {
		$teams = $this->createStub(TeamService::class);
		$teams->method('isAvailable')->willReturn(false);
		$teams->method('memberTeams')->willReturn([]);
		$teams->method('getLevel')->willReturn(0);
		$state = new TeamStateService(Server::get(\OCA\NextNotes\Db\TeamMapper::class), $teams, Server::get(\OCP\AppFramework\Utility\ITimeFactory::class));
		return new AccessService(Server::get(WorkspaceMapper::class), Server::get(WsShareMapper::class), Server::get(NoteMapper::class),
			Server::get(FolderMapper::class), $teams, $state, Server::get(AssignmentMapper::class), Server::get(SubmissionMapper::class));
	}

	// Done 3a: S2 erreicht S1-Abgabe über keinen Pfad; L (aktiv), O (verwaist) ja; Lehrkraft ohne owner_uid im verwaisten Team nein; abgelöste Kopie nur L/O
	public function testForeignSubmissionsOnlyForTeacherAction(): void {
		$fx = $this->fixture();
		['aid' => $aid, 'sub1' => $sub1, 'sub2' => $sub2] = $fx;
		$n1 = $sub1['note'];
		self::assertSame([], self::readPaths($fx['s2'], $n1, $sub1['att'], 404), 'S2 auf S1-Kopie');
		self::assertSame(404, $fx['s2']->call('GET', "/submissions/{$sub1['sid']}")->status, 'S2: GET /submissions S1');
		self::assertSame(404, $fx['s2']->call('GET', "/assignments/$aid/submissions")->status, 'S2: Abgabenliste');
		$own = $fx['s2']->call('GET', "/assignments/$aid");
		self::assertSame(200, $own->status, 'S2 liest die Aufgabe');
		self::assertSame($sub2['sid'], $own->json()['assignment']['submission']['id'], 'S2 sieht nur eigene Abgabe');
		foreach (['abgabe1', (string)$sub1['sid'] . ',', 'template_note_id', 'submissions_total'] as $needle) {
			self::assertStringNotContainsString($needle, $own->body, "Aufgabe für S2 ohne $needle");
		}
		self::ok($fx['s2']->call('GET', "/submissions/{$sub2['sid']}"), 'S2 eigene Abgabe');
		self::assertSame([], self::readPaths($fx['s2'], $sub2['note'], $sub2['att'], 200), 'S2 liest eigene Kopie');
		self::assertSame(403, $fx['s2']->call('PATCH', "/notes/{$sub2['note']}", ['content' => '<p>x</p>'])->status, 'S2 schreibt Kopie nicht (M6d)');

		// Lehrkraft aktiv
		self::assertSame([], self::readPaths($fx['m'], $n1, $sub1['att'], 200), 'M (aktiv) auf S1-Kopie');
		$list = self::ok($fx['m']->call('GET', "/assignments/$aid/submissions"), 'Abgabenliste M');
		self::assertCount(2, $list['submissions']);
		self::ok($fx['m']->call('GET', "/submissions/{$sub1['sid']}"), 'M: Abgabe S1');
		self::assertSame(403, $fx['m']->call('PATCH', "/notes/$n1", ['content' => '<p>x</p>'])->status, 'M schreibt Kopie nicht');
		self::assertSame(403, $fx['m']->call('DELETE', "/notes/$n1")->status, 'M löscht Kopie nicht');

		// verwaist: nur owner_uid (L), nicht Lehrkraft M
		self::setTeamState($fx['t'], 'orphaned');
		self::assertSame([], self::readPaths($fx['l'], $n1, $sub1['att'], 200), 'O (verwaist) auf S1-Kopie');
		self::ok($fx['l']->call('GET', "/submissions/{$sub1['sid']}"), 'O: Abgabe S1');
		self::assertSame([], self::readPaths($fx['m'], $n1, $sub1['att'], 404), 'M ohne owner_uid (verwaist)');
		self::assertSame(404, $fx['m']->call('GET', "/assignments/$aid/submissions")->status, 'M Abgabenliste (verwaist)');
		self::assertSame([], self::readPaths($fx['s1'], $n1, $sub1['att'], 200), 'S1 liest eigene (verwaist)');
		self::setTeamState($fx['t'], 'active');

		// abgelöste Kopie (Konto gelöscht, M6f): nur L/O
		self::setColumn('nextnotes_notes', $sub2['note'], 'workspace_id', null);
		self::setColumn('nextnotes_notes', $sub2['note'], 'folder_id', null);
		self::setColumn('nextnotes_submissions', $sub2['sid'], 'student_uid', null);
		self::assertSame([], self::readPaths($fx['m'], $sub2['note'], $sub2['att'], 200), 'M auf abgelöste Kopie');
		self::assertSame([], self::readPaths($fx['s2'], $sub2['note'], $sub2['att'], 404), 'S2 auf abgelöste Kopie');
		self::assertSame([], self::readPaths($fx['s1'], $sub2['note'], $sub2['att'], 404), 'S1 auf abgelöste Kopie');
	}

	// Done 3: Vorlage für Schüler überall 404, für Lehrkraft nur per ID; visibleAssignmentIds = a ∪ b ∪ c mit wenigen Abfragen
	public function testTemplateAndVisibleAssignments(): void {
		$fx = $this->fixture();
		$tpl = $fx['tpl'];
		$tplAtt = (int)(Server::get(\OCA\NextNotes\Db\AttachmentMapper::class)->findForNotes([$tpl])[$tpl][0] ?? null)?->getId();
		self::assertGreaterThan(0, $tplAtt, 'Vorlage hat Anhang');
		foreach (['s1', 'x', 'n'] as $who) {
			self::assertSame([], self::readPaths($fx[$who], $tpl, $tplAtt, 404), "$who auf Vorlage");
			self::assertStringNotContainsString($fx['mark'] . '</p>', $fx[$who]->call('GET', '/changes?since=0')->body, "$who: /changes ohne Vorlage");
			self::assertSame(404, self::page($fx[$who], "index.php?note=$tpl")->status, "$who: index.php?note=Vorlage");
		}
		$search = $fx['s1']->call('GET', '/notes/search?q=' . rawurlencode($fx['mark']));
		self::assertStringNotContainsString('"id":' . $tpl . ',', $search->body, 'S1-Suche ohne Vorlage');
		self::assertSame([], self::readPaths($fx['m'], $tpl, $tplAtt, 200, false), 'M liest Vorlage per ID');

		$access = Server::get(AccessService::class);
		$aid = $fx['aid'];
		// zweite Aufgabe, deren owner_uid X ist, im verwaisten zweiten Team (b)
		$t2 = $this->teams->create($fx['l']->uid, 'Asg2 ' . bin2hex(random_bytes(3)));
		self::teamWs($fx['l'], $t2, 'Asg2');
		$a2 = $this->createAssignment($fx['l'], $t2, $fx['src'], 'Zweite');
		self::setColumn('nextnotes_assignments', (int)$a2['id'], 'owner_uid', $fx['x']->uid);
		self::setTeamState($t2, 'orphaned');
		$access->resetCache();
		self::assertSame([$aid], $access->visibleAssignmentIds($fx['m']->uid), 'M: (a)');
		self::assertSame([$aid], $access->visibleAssignmentIds($fx['s1']->uid), 'S1: (c)');
		self::assertSame([(int)$a2['id']], $access->visibleAssignmentIds($fx['x']->uid), 'X: (b), nicht (a) mit Level 1');
		self::assertSame([$aid], $access->visibleAssignmentIds($fx['l']->uid), 'L: (a) Team 1, Team 2 verwaist ohne owner_uid');
		self::assertSame([], $access->visibleAssignmentIds($fx['n']->uid), 'N');
		$ids = array_map(static fn ($a) => $a['id'], self::ok($fx['s1']->call('GET', '/assignments'), 'Liste S1')['assignments']);
		self::assertSame([$aid], $ids, 'GET /assignments S1');
		// höchstens 3 SQL-Abfragen für die drei Teile (Circles-Abfragen nicht gezählt)
		$access->resetCache();
		Server::get(TeamService::class)->memberTeams($fx['m']->uid);
		Server::get(TeamService::class)->getLevel($fx['t'], $fx['m']->uid);
		$inner = Server::get(IDBConnection::class)->getInner();
		$before = $inner->getStats()['executed'];
		$access->visibleAssignmentIds($fx['m']->uid);
		self::assertLessThanOrEqual(3, $inner->getStats()['executed'] - $before, 'visibleAssignmentIds ≤ 3 SQL');
	}

	// Done 6: requireLeave/Systemverwaltung mit echter Vorlage und Abgabe – alle Wege 403/404, nichts geändert
	public function testSystemManagedNeverLeaves(): void {
		$fx = $this->fixture();
		[$pa] = self::newWorkspace($fx['s1'], 'PA');
		[$pl] = self::newWorkspace($fx['l'], 'PL');
		$n1 = $fx['sub1']['note'];
		$tpl = $fx['tpl'];
		$before = [self::row('nextnotes_notes', $n1), self::row('nextnotes_notes', $tpl)];
		foreach ([[$fx['s1'], $n1, $pa], [$fx['m'], $n1, $fx['ws']], [$fx['l'], $tpl, $pl]] as [$c, $n, $target]) {
			self::expectStatus($c->call('PATCH', "/notes/$n", ['workspace' => $target]), [400, 403], "PATCH workspace $n");
			self::expectStatus($c->call('PATCH', "/notes/$n", ['folder' => 'Neu']), [400, 403], "PATCH folder $n");
			self::expectStatus($c->call('POST', "/notes/$n/folder", ['workspace' => $target]), [403, 404], "moveToFolder $n");
			self::expectStatus($c->call('POST', "/notes/$n/archive"), [400, 403, 404], "Archiv $n");
			self::expectStatus($c->call('POST', "/notes/$n/duplicate"), [403, 404], "Duplikat $n");
			self::expectStatus($c->call('DELETE', "/notes/$n"), [403], "Papierkorb $n");
			self::expectStatus($c->call('DELETE', "/notes/$n?permanent=1"), [403], "Löschen $n");
		}
		// Aufgaben-Ordner und Schüler-Workspace: systemverwaltet
		$f = $fx['sub1']['folder'];
		self::expectStatus($fx['s1']->call('PATCH', "/folders/$f", ['name' => 'x']), [403], 'Aufgaben-Ordner umbenennen');
		self::expectStatus($fx['s1']->call('DELETE', "/folders/$f"), [403], 'Aufgaben-Ordner löschen');
		self::expectStatus($fx['s1']->call('POST', '/folders', ['folder_name' => 'Sub', 'parent_folder_id' => $f]), [403], 'Unterordner im Aufgaben-Ordner');
		self::assertEquals($before, [self::row('nextnotes_notes', $n1), self::row('nextnotes_notes', $tpl)], 'Vorlage und Kopie unverändert');
		// Anhang der Lehrkraft an die Vorlage verschieben: Verlassen-Regel
		$att = (int)self::ok($fx['l']->upload("/notes/{$fx['src']}/attachments", 'z.txt', 'z'), 'Anhang')['attachment_id'];
		self::expectStatus($fx['l']->call('POST', "/notes/{$fx['src']}/attachments/$att/move", ['target_note_id' => $tpl]), [403, 404], 'Anhang an Vorlage');
	}

	// Done 3b (Invariante SPEC §3 neu): role=note ≡ noteLevel ≥ READ; Abgaben ⊆ (nur eigene); Vorlagen nie; Lehrkraft nur per Direktzugriff
	public function testFilterReadableInvariant(): void {
		$fx = $this->fixture();
		$mapper = Server::get(NoteMapper::class);
		$access = Server::get(AccessService::class);
		$access->resetCache();
		$other = self::newNote($fx['s1'], $fx['ws'], 'Team-Notiz S1', '<p>t</p>');
		$ids = ['src' => $fx['src'], 'tpl' => $fx['tpl'], 'c1' => $fx['sub1']['note'], 'c2' => $fx['sub2']['note'], 'teamNote' => $other];
		// Kopie mit falscher Verknüpfung (Abgabe ohne Rückverweis) im eigenen Schüler-Workspace: nie lesbar
		$forged = self::rawNote($fx['sub1']['wsId'], 'Gefälscht', $fx['s1']->uid, 'submission', $fx['sub1']['folder']);
		self::setColumn('nextnotes_notes', $forged, 'assignment_id', $fx['aid']);
		self::setColumn('nextnotes_notes', $forged, 'submission_id', 2000000000 + random_int(0, 100000));
		$ids['forged'] = $forged;
		$byId = array_flip($ids);
		$notes = $mapper->findByIds(array_values($ids));
		$label = static fn (array $list) => array_values(array_map(static fn ($n) => $byId[(int)$n->getId()], $list));
		$expect = [
			's1' => [['src', 'c1', 'teamNote'], ['src', 'c1', 'teamNote']],
			's2' => [['src', 'c2', 'teamNote'], ['src', 'c2', 'teamNote']],
			'm' => [['src', 'teamNote'], ['src', 'tpl', 'c1', 'c2', 'teamNote']],
			'n' => [[], []],
		];
		foreach ($expect as $who => [$filtered, $readable]) {
			$uid = $fx[$who]->uid;
			$f = $label($access->filterReadable($uid, $notes));
			$l = $label(array_filter($notes, static fn ($n) => $access->noteLevel($uid, $n) >= AccessService::READ));
			sort($f);
			sort($l);
			sort($filtered);
			sort($readable);
			self::assertSame($filtered, $f, "$who: filterReadable");
			self::assertSame($readable, $l, "$who: noteLevel ≥ READ");
			self::assertSame([], array_values(array_diff($f, $l)), "$who: filterReadable ⊆ noteLevel");
			$notesOnly = static fn (array $x) => array_values(array_filter($x, static fn ($k) => in_array($k, ['src', 'teamNote'], true)));
			self::assertSame($notesOnly($l), $notesOnly($f), "$who: role=note äquivalent");
		}
	}
}
