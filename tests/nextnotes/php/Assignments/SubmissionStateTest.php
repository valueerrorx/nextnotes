<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Assignments;

// Done M6e1 1, 4, 9 (SPEC §9.2): jeder Übergang gelingt, jeder andere 409; closed sperrt Schüler und reopen, Feedback/Rückgabe bleiben; Feedback erst nach Rückgabe sichtbar (Thomas), Name der Lehrkraft (Q5)
final class SubmissionStateTest extends AssignmentTestCase {
	private const ALLOWED = [
		'submit' => ['assigned' => 'submitted', 'reopened' => 'submitted'],
		'return' => ['submitted' => 'returned', 'missed' => 'returned'],
		'reopen' => ['submitted' => 'reopened', 'missed' => 'reopened', 'returned' => 'reopened'],
		'feedback' => ['submitted' => 'submitted', 'missed' => 'missed', 'returned' => 'returned'],
	];

	public function testAllTransitions(): void {
		$fx = $this->distributedFixture();
		$sid = (int)$fx['subs'][$fx['s1']->uid]['id'];
		$calls = [
			'submit' => fn () => $fx['s1']->call('POST', "/submissions/$sid/submit"),
			'return' => fn () => $fx['l']->call('POST', "/submissions/$sid/return", ['feedback' => 'gut', 'points' => 3]),
			'reopen' => fn () => $fx['l']->call('POST', "/submissions/$sid/reopen", ['due_override_at' => time() + 600]),
			'feedback' => fn () => $fx['m']->call('PUT', "/submissions/$sid/feedback", ['feedback' => 'neu']),
		];
		$errors = [];
		foreach (['assigned', 'submitted', 'missed', 'returned', 'reopened'] as $from) {
			foreach ($calls as $action => $call) {
				self::setColumn('nextnotes_submissions', $sid, 'status', $from);
				self::setColumn('nextnotes_submissions', $sid, 'due_override_at', null);
				$r = $call();
				$to = self::ALLOWED[$action][$from] ?? null;
				$status = self::row('nextnotes_submissions', $sid)['status'];
				if ($to === null && ($r->status !== 409 || ($r->json()['code'] ?? null) !== 'invalid_transition' || $status !== $from)) {
					$errors[] = "$from --$action--> erwartet 409, erhalten $r->status/$status";
				}
				if ($to !== null && ($r->status !== 200 || $status !== $to)) {
					$errors[] = "$from --$action--> erwartet 200/$to, erhalten $r->status/$status " . substr($r->body, 0, 120);
				}
			}
		}
		self::assertSame([], $errors);

		// Rechte: S2 auf S1-Abgabe 404, S1 gibt nicht zurück (403 über Aufgabe read? nein: fremde Lehrkraft-Aktion → 404/403), Punkte > max 400
		self::setColumn('nextnotes_submissions', $sid, 'status', 'submitted');
		self::assertSame(404, $fx['s2']->call('POST', "/submissions/$sid/submit")->status, 'S2 submit fremd');
		self::assertContains($fx['s1']->call('POST', "/submissions/$sid/return")->status, [403, 404], 'S1 return');
		self::setColumn('nextnotes_assignments', $fx['aid'], 'max_points', 5);
		self::assertSame(400, $fx['l']->call('PUT', "/submissions/$sid/feedback", ['points' => 6])->status, 'Punkte über Maximum');
		self::assertSame(400, $fx['l']->call('PUT', "/submissions/$sid/feedback", [])->status, 'Feedback leer');

		// close (Thomas 2026-11-08): sperrt Schüler und reopen; Lehrkraft gibt weiter Feedback und zurück; Abgegebenes bleibt submitted
		self::ok($fx['l']->call('POST', "/assignments/{$fx['aid']}/close"), 'schließen');
		self::assertSame(409, $fx['l']->call('POST', "/assignments/{$fx['aid']}/close")->status, 'erneut schließen');
		$s2 = (int)$fx['subs'][$fx['s2']->uid]['id'];
		self::assertSame('missed', self::row('nextnotes_submissions', $s2)['status'], 'offene Abgabe beim Schließen → missed');
		self::assertSame('submitted', self::row('nextnotes_submissions', $sid)['status'], 'abgegebene Abgabe bleibt submitted');
		self::ok($fx['l']->call('GET', "/notes/{$fx['subs'][$fx['s1']->uid]['note_id']}"), 'Lehrkraft liest Abgabe nach close');
		foreach (['submit' => 'assigned', 'reopen' => 'submitted'] as $action => $from) {
			self::setColumn('nextnotes_submissions', $sid, 'status', $from);
			$r = $calls[$action]();
			self::assertSame([409, 'invalid_transition', $from], [$r->status, $r->json()['code'] ?? null, self::row('nextnotes_submissions', $sid)['status']], "closed: $action");
		}
		self::setColumn('nextnotes_submissions', $sid, 'status', 'assigned');
		$r = $fx['s1']->call('PATCH', '/notes/' . $fx['subs'][$fx['s1']->uid]['note_id'], ['content' => '<p>nach close</p>']);
		self::assertSame([403, 'submission_locked'], [$r->status, $r->json()['code'] ?? null], 'closed: Schüler bearbeitet');
		self::setColumn('nextnotes_submissions', $sid, 'status', 'submitted');
		self::ok($calls['feedback'](), 'closed: feedback');
		self::assertSame('submitted', self::row('nextnotes_submissions', $sid)['status'], 'feedback ohne Statuswechsel');
		self::ok($calls['return'](), 'closed: return');
		self::assertSame('returned', self::row('nextnotes_submissions', $sid)['status'], 'closed: zurückgegeben');
		self::ok($fx['l']->call('PUT', "/submissions/$sid/feedback", ['feedback' => 'nachträglich', 'points' => 4]), 'closed: feedback nach Rückgabe');
		$own = self::ok($fx['s1']->call('GET', "/submissions/$sid"), 'S1 liest nach close')['submission'];
		self::assertSame(['nachträglich', 4], [$own['feedback'], $own['points']], 'Feedback nach close sichtbar');
		self::ok($fx['l']->call('POST', "/submissions/$s2/return", ['feedback' => 'verpasst']), 'closed: missed → returned');
	}

	// Thomas: Feedback/Punkte erst nach Rückgabe; Q5 Name der Lehrkraft; reopen mit eigener Frist; Frist ohne Job (materialize)
	public function testFeedbackVisibilityAndDeadline(): void {
		$fx = $this->distributedFixture();
		['l' => $l, 's1' => $s1] = $fx;
		$sid = (int)$fx['subs'][$s1->uid]['id'];
		$note = (int)$fx['subs'][$s1->uid]['note_id'];
		self::ok($s1->call('POST', "/submissions/$sid/submit"), 'abgeben');
		self::ok($l->call('PUT', "/submissions/$sid/feedback", ['feedback' => 'Entwurf', 'points' => 2]), 'Feedback vor Rückgabe');
		$own = self::ok($s1->call('GET', "/submissions/$sid"), 'S1 liest')['submission'];
		self::assertSame([null, null, null], [$own['feedback'], $own['points'], $own['feedback_by_name']], 'vor Rückgabe unsichtbar');
		$list = self::ok($s1->call('GET', '/assignments'), 'Liste S1')['assignments'];
		self::assertNull($list[0]['submission']['feedback'], 'Liste: vor Rückgabe unsichtbar');
		self::ok($l->call('POST', "/submissions/$sid/return", ['feedback' => 'Gut gemacht']), 'zurückgeben');
		$own = self::ok($s1->call('GET', "/submissions/$sid"), 'S1 nach Rückgabe')['submission'];
		self::assertSame(['Gut gemacht', 2, 'Lehrkraft L'], [$own['feedback'], $own['points'], $own['feedback_by_name']], 'nach Rückgabe sichtbar (Q5)');

		// reopen mit eigener Frist → S1 schreibt; Frist abgelaufen ohne Job → 403 submission_locked + missed
		self::assertSame(400, $l->call('POST', "/submissions/$sid/reopen", ['due_override_at' => time() - 5])->status, 'Frist in der Vergangenheit');
		self::ok($l->call('POST', "/submissions/$sid/reopen", ['due_override_at' => time() + 600]), 'wiederöffnen');
		self::ok($s1->call('PATCH', "/notes/$note", ['content' => '<p>nachgebessert</p>']), 'S1 schreibt nach reopen');
		self::setColumn('nextnotes_submissions', $sid, 'due_override_at', time() - 5);
		$r = $s1->call('PATCH', "/notes/$note", ['content' => '<p>zu spät</p>']);
		self::assertSame([403, 'submission_locked'], [$r->status, $r->json()['code'] ?? null], 'nach Frist');
		self::assertSame('missed', self::row('nextnotes_submissions', $sid)['status'], 'materialisiert beim Zugriff');
		self::assertStringContainsString('nachgebessert', (string)self::row('nextnotes_notes', $note)['content']);

		// verwaistes Team: S liest, submit gesperrt (Krit. 5, gleicher Zweig wie Circles aus)
		$s2 = (int)$fx['subs'][$fx['s2']->uid]['id'];
		self::setTeamState($fx['t'], 'orphaned');
		self::ok($fx['s2']->call('GET', '/notes/' . $fx['subs'][$fx['s2']->uid]['note_id']), 'S2 liest im verwaisten Team');
		$r = $fx['s2']->call('POST', "/submissions/$s2/submit");
		self::assertSame([403, 'submission_locked'], [$r->status, $r->json()['code'] ?? null], 'submit im verwaisten Team');
		self::setTeamState($fx['t'], 'active');
	}
}
