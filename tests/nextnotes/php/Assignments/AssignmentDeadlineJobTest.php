<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Assignments;

use OCA\NextNotes\BackgroundJob\AssignmentDeadlineJob;
use OCP\BackgroundJob\IJobList;
use OCP\Server;

// Done M6e2 1 + 3 (Plan M6 §2.4, SPEC §5/§6): verpasste Frist → missed ohne Zugriff; genau eine Sammelmeldung je Lehrkraft (Q4)
// mit submitted/missed/not_distributed, zweiter Lauf ohne Dublette; Fristverlängerung setzt zurück; Budget-Abbruch und Fortsetzung
final class AssignmentDeadlineJobTest extends AssignmentTestCase {
	use NotificationRows;

	protected function setUp(): void {
		parent::setUp();
		self::loadNotifications();
	}

	private static function subStatus(int $sid): string {
		return (string)self::row('nextnotes_submissions', $sid)['status'];
	}

	public function testJobRegistered(): void {
		self::assertTrue(Server::get(IJobList::class)->has(AssignmentDeadlineJob::class, null), 'AssignmentDeadlineJob nicht registriert');
	}

	public function testMissedAndSummaryOnce(): void {
		$fx = $this->distributedFixture();
		['l' => $l, 'm' => $m, 's1' => $s1, 's2' => $s2, 'x' => $x, 'n' => $n, 't' => $t, 'aid' => $aid] = $fx;
		$sid = static fn ($u) => (int)$fx['subs'][$u->uid]['id'];
		self::ok($s1->call('POST', '/submissions/' . $sid($s1) . '/submit'), 'S1 gibt ab');
		// Y kommt nach dem Austeilen ins Team und greift nie zu → nicht ausgeteilt
		$y = $this->createUser('ay');
		$this->teams->addUser($t, $l->uid, $y->uid);
		self::setColumn('nextnotes_assignments', $aid, 'due_at', time() - 60);
		self::assertSame('assigned', self::subStatus($sid($s2)), 'Kontrolle: vor dem Job noch assigned');

		// Lauf über die Job-Klasse (Budget 120 s)
		$job = Server::get(AssignmentDeadlineJob::class);
		$job->start(Server::get(IJobList::class));
		self::assertSame(['submitted', 'missed', 'missed'], [self::subStatus($sid($s1)), self::subStatus($sid($s2)), self::subStatus($sid($x))], 'missed ohne Zugriff');
		self::assertNotNull(self::row('nextnotes_assignments', $aid)['deadline_notified_at'], 'deadline_notified_at gesetzt');
		self::assertArrayNotHasKey($y->uid, self::submissionsOf($aid), 'nach Frist kein Nachzügler');
		foreach ([$l, $m] as $u) {
			$rows = self::notes($u->uid, 'assignment_deadline_passed', 'assignment', $aid);
			self::assertCount(1, $rows, "Sammelmeldung an $u->uid");
			self::assertEquals(['submitted' => 1, 'missed' => 2, 'not_distributed' => 1], $rows[0]['params'], "Zahlen an $u->uid");
			self::assertCount(1, self::notes($u->uid), "nur die Sammelmeldung an $u->uid (Q4)");
		}
		foreach ([$s1, $s2, $x, $y, $n] as $u) {
			self::assertSame([], self::notes($u->uid, 'assignment_deadline_passed'), "Sammelmeldung an $u->uid");
		}

		// zweiter Lauf: keine Dublette
		self::runDeadlineJob();
		foreach ([$l, $m] as $u) {
			self::assertCount(1, self::notes($u->uid, 'assignment_deadline_passed', 'assignment', $aid), "zweiter Lauf: Dublette an $u->uid");
		}

		// Fristverlängerung: Marke NULL, alte Meldung weg, verpasste wieder offen, Y ausgeteilt
		self::ok($l->call('PATCH', "/assignments/$aid", ['due_at' => time() + 3600]), 'Frist verlängern');
		self::assertNull(self::row('nextnotes_assignments', $aid)['deadline_notified_at'], 'Verlängerung setzt deadline_notified_at zurück');
		self::assertSame([], self::notes(null, 'assignment_deadline_passed', 'assignment', $aid), 'alte Sammelmeldung entfernt');
		self::assertNotSame('missed', self::subStatus($sid($s2)), 'Verlängerung öffnet missed wieder');
		$subs = self::submissionsOf($aid);
		self::assertArrayHasKey($y->uid, $subs, 'Nachzügler Y bei Verlängerung');
		self::assertCount(1, self::notes($y->uid, 'assignment_assigned', 'assignment', $aid), 'assigned an Y');
		self::runDeadlineJob();
		self::assertSame([], self::notes(null, 'assignment_deadline_passed', 'assignment', $aid), 'Frist offen: keine Sammelmeldung');

		// neue Frist abgelaufen → genau eine neue Meldung je Lehrkraft
		self::setColumn('nextnotes_assignments', $aid, 'due_at', time() - 60);
		self::runDeadlineJob();
		self::runDeadlineJob();
		foreach ([$l, $m] as $u) {
			$rows = self::notes($u->uid, 'assignment_deadline_passed', 'assignment', $aid);
			self::assertCount(1, $rows, "neue Frist: Sammelmeldung an $u->uid");
			self::assertEquals(['submitted' => 1, 'missed' => 3, 'not_distributed' => 0], $rows[0]['params'], "neue Frist: Zahlen an $u->uid");
		}
		self::assertSame('missed', self::subStatus((int)$subs[$y->uid]['id']), 'Y missed');
	}

	// Budget 0: je Schritt genau eine Aufgabe, Rest im nächsten Lauf (Sammelmeldung und Nachzügler)
	public function testBudgetContinuation(): void {
		$fx = $this->distributedFixture();
		['l' => $l, 'm' => $m, 't' => $t, 'aid' => $a1] = $fx;
		$a2 = (int)$this->createAssignment($l, $t, $fx['src'], "Zweite {$fx['mark']}")['id'];
		self::ok($l->call('POST', "/assignments/$a2/distribute"), 'Austeilen 2');
		self::runDeadlineJob();
		$mine = [$a1, $a2];

		// Nachzügler: Y in beide Aufgaben, mit Budget 0 nicht in einem Lauf
		$y = $this->createUser('ayb');
		$this->teams->addUser($t, $l->uid, $y->uid);
		$has = static fn () => count(array_filter($mine, static fn ($id) => isset(self::submissionsOf($id)[$y->uid])));
		self::runDeadlineJob(0);
		self::assertLessThan(2, $has(), 'Budget 0: Nachzügler in beiden Aufgaben in einem Lauf');
		for ($i = 0; $i < 10 && $has() < 2; $i++) {
			self::runDeadlineJob(0);
		}
		self::assertSame(2, $has(), 'Fortsetzung: Nachzügler in beiden Aufgaben');
		self::runDeadlineJob(0);
		self::assertCount(2, self::notes($y->uid, 'assignment_assigned'), 'assigned an Y je Aufgabe genau einmal');

		// Sammelmeldung: erster Lauf genau eine Aufgabe, zweiter die andere
		foreach ($mine as $id) {
			self::setColumn('nextnotes_assignments', $id, 'due_at', time() - 60);
		}
		$notified = static fn () => count(array_filter($mine, static fn ($id) => self::row('nextnotes_assignments', $id)['deadline_notified_at'] !== null));
		self::runDeadlineJob(0);
		self::assertSame(1, $notified(), 'Budget 0: genau eine Sammelmeldung im ersten Lauf');
		self::runDeadlineJob(0);
		self::assertSame(2, $notified(), 'Fortsetzung: zweite Sammelmeldung im nächsten Lauf');
		self::runDeadlineJob(0);
		foreach ($mine as $id) {
			foreach ([$l, $m] as $u) {
				self::assertCount(1, self::notes($u->uid, 'assignment_deadline_passed', 'assignment', $id), "Aufgabe $id an $u->uid");
			}
			foreach (self::submissionsOf($id) as $uid => $row) {
				self::assertSame('missed', $row['status'], "Aufgabe $id: $uid nach Fortsetzung");
			}
		}
	}
}
