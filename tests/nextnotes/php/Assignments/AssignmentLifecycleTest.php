<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Assignments;

use OCA\NextNotes\Db\AssignmentMapper;
use OCA\NextNotes\Db\SubmissionMapper;
use OCA\NextNotes\Service\AccessService;
use OCA\NextNotes\Service\AssignmentNotifications;
use OCA\NextNotes\Service\AssignmentOwnership;
use OCA\NextNotes\Service\BackgroundFiles;
use OCA\NextNotes\Service\DistributionService;
use OCA\NextNotes\Service\Exception\TeamUnavailableException;
use OCA\NextNotes\Service\TeamCleanupService;
use OCA\NextNotes\Service\TeamLifecycleService;
use OCA\NextNotes\Service\TeamService;
use OCA\NextNotes\Service\TeamStateService;
use OCA\NextNotes\Service\UserDataService;
use OCA\NextNotes\Tests\Support\Teams;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use OCP\Server;
use Psr\Log\LoggerInterface;

// Done M6f (Plan M6 §2.5): Austritt, Kontolöschung Lehrkraft, Übergang/occ/Herabstufung, Team-Löschung mit Aufgaben (O6), Purge mit Budget, H3, Review M6e2-r1
final class AssignmentLifecycleTest extends AssignmentTestCase {
	use NotificationRows;

	protected function setUp(): void {
		parent::setUp();
		self::loadNotifications();
	}

	// Done 1 / Krit. 7: Austritt → Austrittsmarke, L liest vollständig, S1 liest eigene, schreibt und gibt nicht ab
	public function testLeaveKeepsSubmissionReadOnly(): void {
		$fx = $this->distributedFixture();
		['l' => $l, 's1' => $s1] = $fx;
		$sid = (int)$fx['subs'][$s1->uid]['id'];
		$note = (int)$fx['subs'][$s1->uid]['note_id'];
		self::ok($s1->call('PATCH', "/notes/$note", ['content' => '<p>vor dem Austritt</p>']), 'S1 arbeitet');
		$att = (int)self::ok($l->call('GET', "/notes/$note/attachments"), 'Anhänge')['attachments'][0]['id'];

		$this->teams->remove($l->uid, $fx['memberS1']);
		$row = self::row('nextnotes_submissions', $sid);
		self::assertNotNull($row['left_team_at'], 'Austrittsmarke');
		self::assertSame([$s1->uid, 'assigned'], [$row['student_uid'], $row['status']], 'Abgabe unverändert');
		self::assertNull(self::row('nextnotes_submissions', (int)$fx['subs'][$fx['s2']->uid]['id'])['left_team_at'], 'S2 unberührt');

		$view = self::ok($l->call('GET', "/submissions/$sid"), 'L liest Abgabe')['submission'];
		self::assertNotNull($view['left_team_at']);
		self::assertSame([], self::readPaths($l, $note, $att, 200), 'L liest Inhalt, Anhang, Snapshots');
		self::assertStringContainsString('vor dem Austritt', (string)self::ok($l->call('GET', "/notes/$note"), 'Inhalt')['note']['content']);
		self::assertSame([], self::readPaths($s1, $note, $att, 200), 'S1 liest eigene Abgabe');
		$r = $s1->call('PATCH', "/notes/$note", ['content' => '<p>nach dem Austritt</p>']);
		self::assertSame([403, 'submission_locked'], [$r->status, $r->json()['code'] ?? null], 'S1 schreibt nicht');
		self::assertSame(403, $s1->call('POST', "/submissions/$sid/submit")->status, 'S1 gibt nicht ab');
		$ids = array_map(static fn ($a) => (int)$a['id'], self::ok($s1->call('GET', '/assignments'), 'Liste S1')['assignments']);
		self::assertContains($fx['aid'], $ids, 'S1 sieht die Aufgabe weiter');
	}

	// Review M6e2-r1 (1): Nachzügler aus dem eigenen GET ohne Meldung; Austeilen/Job mit Meldung
	public function testOwnListReconcileWithoutNotice(): void {
		$fx = $this->distributedFixture();
		['l' => $l, 't' => $t, 'aid' => $aid] = $fx;
		$y = $this->createUser('ay');
		$z = $this->createUser('az');
		$this->teams->addUser($t, $l->uid, $y->uid);
		$this->teams->addUser($t, $l->uid, $z->uid);
		self::ok($y->call('GET', '/assignments'), 'Y öffnet Liste');
		self::assertArrayHasKey($y->uid, self::submissionsOf($aid), 'Y hat Abgabe');
		self::assertSame([], self::notes($y->uid, 'assignment_assigned'), 'keine Meldung beim eigenen Zugriff');
		self::runDeadlineJob();
		self::assertArrayHasKey($z->uid, self::submissionsOf($aid), 'Job teilt Z aus');
		self::assertCount(1, self::notes($z->uid, 'assignment_assigned'), 'Meldung über den Job');
	}

	// Review M6e2-r1 (2), (3): veraltete Sammelmeldung verschwindet; Austrittsmarken verwaister Teams nicht im Job
	public function testDeadlineNoticeGuardAndLeftOnlyActive(): void {
		$fx = $this->distributedFixture();
		['l' => $l, 'aid' => $aid, 't' => $t] = $fx;
		self::setColumn('nextnotes_assignments', $aid, 'deadline_notified_at', time() - 10);
		self::setColumn('nextnotes_assignments', $aid, 'due_at', time() - 20);
		self::assertStringContainsString('deadline', self::prepared($l->uid, 'assignment', $aid, 'assignment_deadline_passed')->getParsedSubject());
		self::setColumn('nextnotes_assignments', $aid, 'due_at', time() + 3600);
		self::assertGone($l->uid, 'assignment', $aid, 'assignment_deadline_passed', 'Frist verlängert');
		self::setColumn('nextnotes_assignments', $aid, 'due_at', time() - 20);
		self::setColumn('nextnotes_assignments', $aid, 'deadline_notified_at', null);
		self::assertGone($l->uid, 'assignment', $aid, 'assignment_deadline_passed', 'nicht markiert');

		self::setColumn('nextnotes_submissions', (int)$fx['subs'][$fx['s1']->uid]['id'], 'left_team_at', time());
		self::assertContains($aid, Server::get(SubmissionMapper::class)->assignmentIdsWithLeft(), 'aktives Team');
		self::setTeamState($t, 'orphaned');
		self::assertNotContains($aid, Server::get(SubmissionMapper::class)->assignmentIdsWithLeft(), 'verwaistes Team');
		self::setTeamState($t, 'active');
	}

	// Done 3 / Krit. 9: Austritt und Herabstufung des Besitzers mit Co-Lehrkraft → Übergang an L (Level 9) mit Meldung; ohne Ziel, Circles-Fehler, verwaist → bleibt
	public function testTransferOnLeaveAndDemotion(): void {
		$fx = $this->fixture(false);
		['l' => $l, 't' => $t, 'src' => $src] = $fx;
		$k = $this->createUser('ak');
		$memberK = $this->teams->addUser($t, $l->uid, $k->uid, Teams::MODERATOR);
		$d = $this->createUser('ad');
		$memberD = $this->teams->addUser($t, $l->uid, $d->uid, Teams::MODERATOR);
		$aK = (int)$this->createAssignment($k, $t, $src, 'Von K')['id'];
		$aD = (int)$this->createAssignment($d, $t, $src, 'Von D')['id'];

		$this->teams->remove($l->uid, $memberK);
		self::assertSame($l->uid, self::row('nextnotes_assignments', $aK)['owner_uid'], 'Austritt → Übergang');
		self::assertSame('left', self::notes($l->uid, 'assignment_transferred', 'assignment', $aK)[0]['params']['reason'] ?? null);
		$p = self::prepared($l->uid, 'assignment', $aK, 'assignment_transferred');
		self::assertStringContainsString('Von K', $p->getParsedSubject());
		self::assertGone($k->uid, 'assignment', $aK, 'assignment_transferred', 'Meldung nur für den Besitzer');

		// Herabstufung: CleanupJob-Schritt
		$cm = Teams::cm();
		try {
			$cm->startSession($cm->getLocalFederatedUser($l->uid));
			$cm->levelMember($memberD, 1);
		} finally {
			$cm->stopSession();
		}
		Teams::reset();
		self::assertSame($d->uid, self::row('nextnotes_assignments', $aD)['owner_uid'], 'Herabstufung ohne Job: bleibt');
		Server::get(AssignmentOwnership::class)->checkDemoted(time() + 60);
		self::assertSame($l->uid, self::row('nextnotes_assignments', $aD)['owner_uid'], 'Herabstufung → Übergang');
		self::assertSame('demoted', self::notes($l->uid, 'assignment_transferred', 'assignment', $aD)[0]['params']['reason'] ?? null);

		// ohne Ziel, Circles-Fehler, verwaistes Team: unverändert
		self::setColumn('nextnotes_assignments', $aD, 'owner_uid', $d->uid);
		$this->ownership(['teachers' => []])->transferOwnership($aD, AssignmentOwnership::LEFT);
		self::assertSame($d->uid, self::row('nextnotes_assignments', $aD)['owner_uid'], 'kein Ziel');
		$this->ownership(['fail' => true])->transferOwnership($aD, AssignmentOwnership::DEMOTED);
		self::assertSame($d->uid, self::row('nextnotes_assignments', $aD)['owner_uid'], 'Circles-Fehler');
		self::setTeamState($t, 'orphaned');
		Server::get(AssignmentOwnership::class)->transferOwnership($aD, AssignmentOwnership::LEFT);
		self::assertSame($d->uid, self::row('nextnotes_assignments', $aD)['owner_uid'], 'verwaistes Team');
		self::setTeamState($t, 'active');
	}

	// Done 3 / Done 6 (H3): Kontolöschung Lehrkraft → Übergang + Meldung; ohne Ziel bzw. Circles-Fehler → NULL, Löschung gelingt
	public function testTeacherAccountDeletion(): void {
		$fx = $this->fixture(false);
		['l' => $l, 't' => $t, 'src' => $src] = $fx;
		$k = $this->createUser('ak');
		$this->teams->addUser($t, $l->uid, $k->uid, Teams::MODERATOR);
		$e = $this->createUser('ae');
		$this->teams->addUser($t, $l->uid, $e->uid, Teams::MODERATOR);
		$aK = (int)$this->createAssignment($k, $t, $src, 'Von K')['id'];
		$aE = (int)$this->createAssignment($e, $t, $src, 'Von E')['id'];

		self::assertTrue(Server::get(IUserManager::class)->get($k->uid)->delete(), 'Kontolöschung K');
		$row = self::row('nextnotes_assignments', $aK);
		self::assertSame([$l->uid, null], [$row['owner_uid'], $row['created_by']], 'Übergang an L, created_by NULL');
		self::assertSame('deleted', self::notes($l->uid, 'assignment_transferred', 'assignment', $aK)[0]['params']['reason'] ?? null);

		// Circles-Fehler im synchronen Listener: Löschung gelingt, owner_uid NULL
		$svc = Server::get(UserDataService::class);
		$prop = new \ReflectionProperty(UserDataService::class, 'ownership');
		$old = $prop->getValue($svc);
		$prop->setValue($svc, $this->ownership(['fail' => true]));
		try {
			self::assertTrue(Server::get(IUserManager::class)->get($e->uid)->delete(), 'Kontolöschung E trotz Circles-Fehler');
		} finally {
			$prop->setValue($svc, $old);
		}
		self::assertNull(Server::get(IUserManager::class)->get($e->uid), 'Konto E gelöscht');
		self::assertNull(self::row('nextnotes_assignments', $aE)['owner_uid'], 'ohne bestätigtes Ziel NULL');
		self::assertSame([], self::notes(null, 'assignment_transferred', 'assignment', $aE), 'keine Meldung ohne Ziel');

		// Team fehlt (kein Ziel, kein Fehler) → NULL; Daten bleiben
		self::setColumn('nextnotes_assignments', $aE, 'owner_uid', 'nn-weg');
		$this->ownership(['exists' => false])->releaseDeleted('nn-weg');
		self::assertNull(self::row('nextnotes_assignments', $aE)['owner_uid'], 'Team fehlt → NULL');
		self::assertNotNull(self::row('nextnotes_notes', (int)self::row('nextnotes_assignments', $aE)['template_note_id']), 'Vorlage bleibt');
	}

	// Done 3: occ nextnotes:assignment:transfer – aktiv nur an Lehrkraft (auch mit --force nicht anders), verwaist nur mit --force, --all-unowned
	public function testOccTransfer(): void {
		$fx = $this->fixture(false);
		['l' => $l, 'm' => $m, 's1' => $s1, 't' => $t, 'aid' => $aid] = $fx;
		[$rc] = self::occ('nextnotes:assignment:transfer', (string)$aid, $s1->uid);
		self::assertSame(1, $rc, 'aktiv: Nicht-Lehrkraft');
		[$rc] = self::occ('nextnotes:assignment:transfer', (string)$aid, $s1->uid, '--force');
		self::assertSame(1, $rc, 'aktiv: Nicht-Lehrkraft auch mit --force');
		[$rc] = self::occ('nextnotes:assignment:transfer', (string)$aid, 'nn-gibt-es-nicht', '--force');
		self::assertSame(1, $rc, 'unbekannter Benutzer');
		[$rc, $out] = self::occ('nextnotes:assignment:transfer', (string)$aid, $m->uid);
		self::assertSame(0, $rc, "aktiv: Lehrkraft M\n$out");
		self::assertSame($m->uid, self::row('nextnotes_assignments', $aid)['owner_uid']);
		self::assertSame('admin', self::notes($m->uid, 'assignment_transferred', 'assignment', $aid)[0]['params']['reason'] ?? null);

		self::setTeamState($t, 'orphaned');
		[$rc] = self::occ('nextnotes:assignment:transfer', (string)$aid, $s1->uid);
		self::assertSame(1, $rc, 'verwaist ohne --force');
		[$rc] = self::occ('nextnotes:assignment:transfer', (string)$aid, $s1->uid, '--force');
		self::assertSame(0, $rc, 'verwaist mit --force');
		self::assertSame($s1->uid, self::row('nextnotes_assignments', $aid)['owner_uid']);

		self::setTeamState($t, 'active');
		self::setColumn('nextnotes_assignments', $aid, 'owner_uid', null);
		[$rc, $out] = self::occ('nextnotes:assignment:transfer', '--all-unowned', $l->uid);
		self::assertSame(0, $rc, "--all-unowned\n$out");
		self::assertSame($l->uid, self::row('nextnotes_assignments', $aid)['owner_uid']);
	}

	// Done 4 / Krit. 8: Team mit Workspace und Aufgaben löschen → verwaist, Meldungen mit Aufgabenzahl, Besitzer liest; Purge löscht alles außer umgewandeltem Workspace
	public function testTeamDeletionWithAssignments(): void {
		$fx = $this->distributedFixture();
		['l' => $l, 'm' => $m, 's1' => $s1, 't' => $t, 'aid' => $aid, 'wsId' => $wsId, 'src' => $src] = $fx;
		$a2 = (int)$this->createAssignment($m, $t, $src, 'Nur M')['id'];
		[$key2, $ws2] = self::teamWs($l, $t, 'Bleibt');
		$y = $this->createUser('ay');
		$this->teams->addUser($t, $l->uid, $y->uid);
		$sub1 = $fx['subs'][$s1->uid];
		$studentWs = (int)self::row('nextnotes_notes', (int)$sub1['note_id'])['workspace_id'];

		$this->teams->destroy($l->uid, $t, true);
		self::assertSame('orphaned', self::teamState($t), 'verwaist');
		// Austritts-Events beim Auflösen: Besitzer und Abgaben unverändert (§9.7)
		self::assertSame([$l->uid, $m->uid], [self::row('nextnotes_assignments', $aid)['owner_uid'], self::row('nextnotes_assignments', $a2)['owner_uid']], 'owner_uid bleibt');
		self::assertNull(self::row('nextnotes_submissions', (int)$sub1['id'])['left_team_at'], 'keine Austrittsmarke beim Auflösen');
		$own = self::teamNotes($l->uid, $t, 'team_orphaned');
		self::assertCount(1, $own, 'team_orphaned an L');
		self::assertSame(1, $own[0]['assignments'] ?? null, 'L: eine Aufgabe mit Abgaben');
		self::assertSame(0, self::teamNotes($m->uid, $t, 'team_orphaned')[0]['assignments'] ?? null, 'M: Besitzer ohne Abgaben');
		self::assertSame([], self::teamNotes($s1->uid, $t, 'team_orphaned'), 'keine an Schüler');
		self::assertStringContainsString('export', (string)self::preparedTeam($l->uid, $t)->getParsedMessage());
		self::assertStringContainsString('assignments will be deleted', self::preparedTeam($m->uid, $t)->getParsedSubject());

		// in der Frist: Besitzer liest Abgaben, M nicht; kein Nachzügler (Purge ↔ reconcile)
		self::ok($l->call('GET', "/assignments/$aid/submissions"), 'O liest Abgaben');
		self::assertSame(404, $m->call('GET', "/assignments/$aid/submissions")->status, 'M nicht Besitzer');
		$r = Server::get(DistributionService::class)->reconcile(Server::get(AssignmentMapper::class)->find($aid), null, time() + 20);
		self::assertSame(0, $r['created'], 'verwaist: kein Austeilen');
		self::assertArrayNotHasKey($y->uid, self::submissionsOf($aid));

		Server::get(TeamLifecycleService::class)->makePrivate($l->uid, $key2);
		self::assertTrue(Server::get(TeamCleanupService::class)->purge($t, time() + 60), 'Purge vollständig');
		foreach ([$aid, $a2] as $id) {
			self::assertNull(self::row('nextnotes_assignments', $id), "Aufgabe $id gelöscht");
			self::assertSame(0, self::countRows('nextnotes_submissions', 'assignment_id', $id), "Abgaben $id gelöscht");
			self::assertSame(0, self::countRows('nextnotes_notes', 'assignment_id', $id), "Vorlage/Kopien $id gelöscht");
		}
		self::assertNull(self::row('nextnotes_notes', (int)$sub1['note_id']), 'Kopie gelöscht');
		self::assertNull(self::row('nextnotes_workspaces', $studentWs), 'Schüler-Workspace gelöscht');
		self::assertNull(self::row('nextnotes_workspaces', $wsId), 'Team-Workspace gelöscht');
		self::assertSame('user', self::row('nextnotes_workspaces', $ws2)['owner_type'], 'umgewandelter Workspace bleibt');
		self::assertNull(self::teamState($t), 'Team-Zeile gelöscht');
	}

	// O6: Team nur mit Aufgaben → Event verwaist, Rückfall orphan-check ebenso; Purge löscht Aufgabe und Zeile
	public function testTeamOnlyWithAssignments(): void {
		$l = $this->createUser('ol', 'Lehrkraft O');
		$s = $this->createUser('os');
		$t = $this->teams->create($l->uid, 'Nur Aufgaben ' . bin2hex(random_bytes(3)));
		$this->teams->addUser($t, $l->uid, $s->uid);
		[$wsName] = self::newWorkspace($l, 'Privat');
		$src = self::newNote($l, $wsName, 'Quelle privat', '<p>q</p>');
		$aid = (int)$this->createAssignment($l, $t, $src, 'Ohne Workspace')['id'];
		self::ok($l->call('POST', "/assignments/$aid/distribute"), 'Austeilen');

		$this->teams->destroy($l->uid, $t, true);
		self::assertSame('orphaned', self::teamState($t), 'Event verwaist Team ohne Workspace');
		self::assertSame(1, self::teamNotes($l->uid, $t, 'team_orphaned')[0]['assignments'] ?? null, 'Meldung an Besitzer');

		// verpasstes Event: orphan-check verwaist erneut
		self::setTeamState($t, 'active');
		$check = Server::get(TeamCleanupService::class)->check(false, true);
		$rows = array_values(array_filter($check['rows'], static fn ($r) => $r['team'] === $t));
		self::assertSame('orphan', $rows[0]['action'] ?? null, 'orphan-check');
		self::assertSame('orphaned', self::teamState($t));

		self::assertTrue(Server::get(TeamCleanupService::class)->purge($t, time() + 60), 'Purge');
		self::assertNull(self::row('nextnotes_assignments', $aid));
		self::assertSame(0, self::countRows('nextnotes_submissions', 'assignment_id', $aid));
		self::assertNull(self::teamState($t), 'Zeile gelöscht');
	}

	// Done 5: Purge-Budget – nach jeder Aufgabe Abbruch, Fortsetzung im nächsten Lauf, Team-Zeile zuletzt
	public function testPurgeBudget(): void {
		$fx = $this->distributedFixture();
		['l' => $l, 'm' => $m, 't' => $t, 'src' => $src] = $fx;
		$this->createAssignment($m, $t, $src, 'Zwei');
		$this->createAssignment($l, $t, $src, 'Drei');
		self::assertSame(3, self::countRows('nextnotes_assignments', 'team_id', $t));
		$this->teams->destroy($l->uid, $t, true);
		$svc = Server::get(TeamCleanupService::class);
		self::assertFalse($svc->purge($t, 0), 'Abbruch nach der ersten Aufgabe');
		self::assertSame(2, self::countRows('nextnotes_assignments', 'team_id', $t), 'eine Aufgabe je Lauf');
		self::assertSame('orphaned', self::teamState($t));
		$done = false;
		for ($i = 0; $i < 10 && !$done; $i++) {
			$left = self::countRows('nextnotes_assignments', 'team_id', $t);
			$done = $svc->purge($t, 0);
			self::assertTrue(!$done || $left <= 1, 'Team-Zeile erst nach den Aufgaben');
		}
		self::assertTrue($done, 'endet');
		self::assertSame(0, self::countRows('nextnotes_assignments', 'team_id', $t));
		self::assertNull(self::teamState($t));
	}

	// Done 6 (H3): Fehler zwischen Lösen und Ende der Transaktion → alles zurück; Wiederholung idempotent
	public function testAccountDeletionRollsBack(): void {
		$fx = $this->distributedFixture();
		$s1 = $fx['s1'];
		$sub = $fx['subs'][$s1->uid];
		$note = (int)$sub['note_id'];
		$wsId = (int)self::row('nextnotes_notes', $note)['workspace_id'];
		$svc = Server::get(UserDataService::class);
		$prop = new \ReflectionProperty(UserDataService::class, 'backgrounds');
		$old = $prop->getValue($svc);
		$broken = $this->createStub(BackgroundFiles::class);
		$broken->method('queueUser')->willThrowException(new \RuntimeException('injiziert'));
		$prop->setValue($svc, $broken);
		try {
			$svc->neutralize($s1->uid, 'Schüler Eins');
			self::fail('Fehler erwartet');
		} catch (\RuntimeException $e) {
			self::assertSame('injiziert', $e->getMessage());
		} finally {
			$prop->setValue($svc, $old);
		}
		self::assertSame($s1->uid, self::row('nextnotes_submissions', (int)$sub['id'])['student_uid'], 'Abgabe nicht gelöst');
		self::assertSame($wsId, (int)self::row('nextnotes_notes', $note)['workspace_id'], 'Kopie im Workspace');
		self::assertSame('user', self::row('nextnotes_workspaces', $wsId)['owner_type'], 'Workspace nicht entkoppelt');
		self::assertNotNull($svc->neutralize($s1->uid, 'Schüler Eins'), 'Wiederholung');
		self::assertNull(self::row('nextnotes_submissions', (int)$sub['id'])['student_uid'], 'gelöst');
		self::assertNull(self::row('nextnotes_notes', $note)['workspace_id']);
		self::assertSame('deleted', self::row('nextnotes_workspaces', $wsId)['owner_type']);
		self::assertNull($svc->neutralize($s1->uid, 'Schüler Eins'), 'idempotent');
	}

	/**
	 * Übergang mit gestubbtem Circles: exists false → Team fehlt, fail → Fehler, teachers → Kandidaten
	 * @param array{exists?: bool, fail?: bool, teachers?: array<string, int>} $o
	 */
	private function ownership(array $o): AssignmentOwnership {
		$teams = $this->createStub(TeamService::class);
		$teams->method('isAvailable')->willReturn(true);
		$teams->method('exists')->willReturnCallback(static function (string $id) use ($o) {
			if ($o['fail'] ?? false) {
				throw new TeamUnavailableException('stub');
			}
			return ($o['exists'] ?? true) ? ['id' => $id, 'name' => 'Stub'] : null;
		});
		$teams->method('teachers')->willReturn($o['teachers'] ?? []);
		$state = new TeamStateService(Server::get(\OCA\NextNotes\Db\TeamMapper::class), $teams, Server::get(ITimeFactory::class));
		return new AssignmentOwnership(Server::get(AssignmentMapper::class), $teams, $state, Server::get(AssignmentNotifications::class),
			Server::get(IUserManager::class), Server::get(ITimeFactory::class), Server::get(LoggerInterface::class));
	}

	/** @return list<array<string, mixed>> Parameter der Team-Meldungen */
	private static function teamNotes(string $uid, string $teamId, string $subject): array {
		$qb = self::db()->getQueryBuilder();
		$qb->select('subject_parameters')->from('notifications')
			->where($qb->expr()->eq('app', $qb->createNamedParameter('nextnotes')))
			->andWhere($qb->expr()->eq('user', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('object_type', $qb->createNamedParameter('team')))
			->andWhere($qb->expr()->eq('object_id', $qb->createNamedParameter($teamId)))
			->andWhere($qb->expr()->eq('subject', $qb->createNamedParameter($subject)));
		return array_map(static fn ($p) => (array)json_decode((string)$p, true), $qb->executeQuery()->fetchFirstColumn());
	}

	private static function preparedTeam(string $uid, string $teamId): \OCP\Notification\INotification {
		$n = Server::get(\OCP\Notification\IManager::class)->createNotification();
		$n->setApp('nextnotes')->setUser($uid)->setDateTime(new \DateTime())->setObject('team', $teamId)->setSubject('team_orphaned', []);
		Server::get(AccessService::class)->resetCache();
		return Server::get(\OCA\NextNotes\Notification\Notifier::class)->prepare($n, 'en');
	}

	/** @return array{0: int, 1: string} */
	private static function occ(string ...$args): array {
		exec('php /var/www/html/occ ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $out, $rc);
		return [$rc, implode("\n", $out)];
	}
}
