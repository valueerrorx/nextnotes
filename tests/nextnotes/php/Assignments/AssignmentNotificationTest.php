<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Assignments;

use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\AlreadyProcessedException;
use OCP\Server;

// Done M6e2 1 (Plan M6 §2.4, SPEC §6): assignment_assigned an Schüler, keine Meldung an Lehrkräfte je Abgabe (Q4),
// submission_returned mit Lehrkraft-Name (Q5), submission_reopened mit Frist; prepare nur mit Recht, nach Löschen AlreadyProcessed
final class AssignmentNotificationTest extends AssignmentTestCase {
	use NotificationRows;

	protected function setUp(): void {
		parent::setUp();
		self::loadNotifications();
	}

	public function testStudentNoticesAndRights(): void {
		$fx = $this->distributedFixture();
		['l' => $l, 'm' => $m, 's1' => $s1, 's2' => $s2, 'x' => $x, 'n' => $n, 'aid' => $aid] = $fx;
		$sid1 = (int)$fx['subs'][$s1->uid]['id'];
		$sid2 = (int)$fx['subs'][$s2->uid]['id'];
		$note1 = (int)$fx['subs'][$s1->uid]['note_id'];

		// assignment_assigned: genau eine je Schüler, keine an L/M/N
		foreach ([$s1, $s2, $x] as $s) {
			self::assertCount(1, self::notes($s->uid, 'assignment_assigned', 'assignment', $aid), "assigned an $s->uid");
		}
		foreach ([$l, $m, $n] as $u) {
			self::assertSame([], self::notes($u->uid), "Meldung an $u->uid beim Austeilen");
		}
		$p = self::prepared($s1->uid, 'assignment', $aid, 'assignment_assigned');
		self::assertStringContainsString("Aufgabe {$fx['mark']}", $p->getParsedSubject());
		self::assertStringContainsString('note=' . $note1, $p->getLink(), 'Link auf eigene Kopie');
		self::assertStringContainsString('Due on', $p->getParsedMessage());
		// Rechte: Lehrkraft/Moderator/Fremder haben keine eigene Abgabe → weg
		foreach ([$l, $m, $n] as $u) {
			self::assertGone($u->uid, 'assignment', $aid, 'assignment_assigned', "assigned für $u->uid");
		}

		// Q4: Abgeben, Feedback, Rückgabe, Wiederöffnen erzeugen keine Meldung an Lehrkräfte
		self::ok($s1->call('POST', "/submissions/$sid1/submit"), 'S1 gibt ab');
		self::ok($s2->call('POST', "/submissions/$sid2/submit"), 'S2 gibt ab');
		self::ok($m->call('PUT', "/submissions/$sid1/feedback", ['feedback' => 'Entwurf']), 'Feedback');
		self::ok($l->call('POST', "/submissions/$sid1/return", ['feedback' => 'Gut gemacht']), 'Rückgabe');
		foreach ([$l, $m] as $u) {
			self::assertSame([], self::notes($u->uid), "Q4: Meldung je Abgabe an $u->uid");
		}

		// Q5: submission_returned an S1 mit Name der Lehrkraft (Snapshot), nicht UID
		$rows = self::notes($s1->uid, 'submission_returned', 'submission', $sid1);
		self::assertCount(1, $rows, 'returned an S1');
		self::assertSame('Lehrkraft L', $rows[0]['params']['byName'] ?? null, 'Snapshot-Name in den Parametern');
		self::assertSame([], self::notes($s2->uid, 'submission_returned'), 'returned an S2');
		$p = self::prepared($s1->uid, 'submission', $sid1, 'submission_returned', $rows[0]['params']);
		self::assertStringContainsString('Lehrkraft L', $p->getParsedSubject());
		self::assertStringNotContainsString($l->uid, $p->getParsedSubject(), 'UID statt Name');
		self::assertStringContainsString('note=' . $note1, $p->getLink());
		$p = self::prepared($s1->uid, 'submission', $sid1, 'submission_returned', $rows[0]['params'], 'de');
		self::assertStringContainsString('Lehrkraft L', $p->getParsedSubject());
		// Rechte: S2 (Mitschüler), L/M (review, aber nicht eigene), N → weg; Gegenprobe S1 oben
		foreach ([$s2, $l, $m, $n] as $u) {
			self::assertGone($u->uid, 'submission', $sid1, 'submission_returned', "returned von S1 für $u->uid", ['byName' => 'Lehrkraft L']);
		}
		self::assertGone($s1->uid, 'submission', $sid2, 'submission_returned', 'S1 auf S2-Abgabe', ['byName' => 'x']);

		// submission_reopened mit neuer Frist
		$due = time() + 3 * 86400;
		self::ok($l->call('POST', "/submissions/$sid1/reopen", ['due_override_at' => $due]), 'wiederöffnen');
		$rows = self::notes($s1->uid, 'submission_reopened', 'submission', $sid1);
		self::assertCount(1, $rows, 'reopened an S1');
		self::assertSame($due, $rows[0]['params']['due'] ?? null, 'neue Frist in den Parametern');
		$p = self::prepared($s1->uid, 'submission', $sid1, 'submission_reopened', $rows[0]['params']);
		self::assertStringContainsString('reopened', $p->getParsedSubject());
		self::assertStringContainsString('New deadline', $p->getParsedMessage());
		self::assertGone($s2->uid, 'submission', $sid1, 'submission_reopened', 'reopened von S1 für S2', ['due' => $due]);
		foreach ([$l, $m] as $u) {
			self::assertSame([], self::notes($u->uid), "Q4 nach reopen: $u->uid");
		}

		// Fremde Objekttypen-Kombination: assigned mit submission-Objekt → kein Weg über die Abgabe
		try {
			self::prepared($s1->uid, 'submission', $sid1, 'assignment_assigned');
			self::fail('assignment_assigned mit Objekt submission angezeigt');
		} catch (\Throwable $e) {
			self::assertNotInstanceOf(\PHPUnit\Framework\AssertionFailedError::class, $e, $e->getMessage());
		}

		// Aufgabe gelöscht → AlreadyProcessed; über den Manager: markProcessed entfernt die Zeilen
		self::ok($l->call('DELETE', "/assignments/$aid", ['confirm' => true]), 'Aufgabe löschen');
		$this->assignmentIds = array_values(array_diff($this->assignmentIds, [$aid]));
		self::assertGone($s1->uid, 'assignment', $aid, 'assignment_assigned', 'assigned nach Löschen');
		self::assertGone($s1->uid, 'submission', $sid1, 'submission_returned', 'returned nach Löschen', ['byName' => 'Lehrkraft L']);
		self::assertGone($l->uid, 'assignment', $aid, 'assignment_deadline_passed', 'Sammelmeldung nach Löschen', ['submitted' => 0, 'missed' => 0, 'not_distributed' => 0]);
		try {
			Server::get(INotificationManager::class)->prepare(self::notice($x->uid, 'assignment', $aid, 'assignment_assigned'), 'en');
			self::fail('Manager: prepare nach Löschen ohne AlreadyProcessed');
		} catch (AlreadyProcessedException) {
		}
		self::assertSame([], self::notes($x->uid, 'assignment_assigned', 'assignment', $aid), 'markProcessed: Zeile von X entfernt');
	}

	// Sammelmeldung: nur Lehrkräfte (review); Schüler/Fremde nie (Gegenprobe L/M zeigt an)
	public function testDeadlineNoticeRights(): void {
		$fx = $this->distributedFixture();
		['l' => $l, 'm' => $m, 's1' => $s1, 'n' => $n, 'aid' => $aid] = $fx;
		$params = ['submitted' => 1, 'missed' => 2, 'not_distributed' => 1];
		// Meldung gilt nur nach Fristende mit Markierung (Review M6e2-r1 Hinweis 2)
		self::setColumn('nextnotes_assignments', $aid, 'due_at', time() - 10);
		self::setColumn('nextnotes_assignments', $aid, 'deadline_notified_at', time());
		foreach ([$l, $m] as $u) {
			$p = self::prepared($u->uid, 'assignment', $aid, 'assignment_deadline_passed', $params);
			self::assertStringContainsString("Aufgabe {$fx['mark']}", $p->getParsedSubject(), "Sammelmeldung $u->uid");
			self::assertStringContainsString('1 submitted, 2 missed', $p->getParsedMessage());
			self::assertStringContainsString('1 team member did not receive', $p->getParsedMessage());
		}
		$p = self::prepared($l->uid, 'assignment', $aid, 'assignment_deadline_passed', $params, 'de');
		self::assertNotSame('', $p->getParsedMessage());
		foreach ([$s1, $n] as $u) {
			self::assertGone($u->uid, 'assignment', $aid, 'assignment_deadline_passed', "Sammelmeldung für $u->uid", $params);
		}
	}
}
