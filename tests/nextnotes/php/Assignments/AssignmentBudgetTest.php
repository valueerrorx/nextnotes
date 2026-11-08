<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Assignments;

use OCA\NextNotes\Tests\Support\ApiClient;
use OCA\NextNotes\Tests\Support\Response;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * M6h Budget (Plan M6 §3 M6h, R8): Team mit 30 Schülern.
 * R8: 30 parallele Uploads in die eigenen Kopien (Team-Zeile als Quote-Sperre) → alle 201, Team-Belegung exakt, Wandzeit ≤ 30 s.
 * „Meine Aufgaben“ mit 50 Aufgaben/30 Schülern: GET /assignments und assignments.php je ≤ 300 ms (Median aus 5).
 * Hauptansicht der Lehrkraft mit Material-Ordner und 50 Aufgaben: TTFB index.php ≤ +10 ms gegenüber vorher (Median aus 7).
 * Messwerte stehen in der Ausgabe (STDERR), damit der Bericht sie nennen kann.
 */
final class AssignmentBudgetTest extends AssignmentTestCase {
	private const STUDENTS = 30;
	private const ASSIGNMENTS = 50;

	private static function ms(callable $fn, int $n): array {
		$t = [];
		$last = null;
		for ($i = 0; $i < $n; $i++) {
			$s = hrtime(true);
			$last = $fn();
			$t[] = (hrtime(true) - $s) / 1e6;
		}
		sort($t);
		return [$t[intdiv($n, 2)], $last];
	}

	private static function report(string $what, float|int|string $value): void {
		fwrite(STDERR, sprintf("BUDGET %s: %s\n", $what, is_float($value) ? number_format($value, 1, '.', '') : (string)$value));
	}

	public function testThirtyStudentsFiftyAssignments(): void {
		$l = $this->createUser('bl', 'Lehrkraft Budget');
		$t = $this->teams->create($l->uid, 'Budget ' . bin2hex(random_bytes(3)));
		/** @var ApiClient[] $students */
		$students = [];
		for ($i = 0; $i < self::STUDENTS; $i++) {
			$students[$i] = $this->createUser("bs$i");
			$this->teams->addUser($t, $l->uid, $students[$i]->uid);
		}
		[$ws] = self::teamWs($l, $t, 'Budget');
		$mat = (int)self::ok($l->call('POST', '/folders', ['folder_name' => 'Material', 'workspace' => $ws]), 'Material-Ordner')['folder_id'];
		$src = self::newNote($l, $ws, 'Blatt', '<p>blatt</p>', $mat);
		self::ok($l->call('PUT', "/folders/$mat/material", ['material' => true]), 'Material');
		for ($i = 0; $i < 5; $i++) {
			self::newNote($l, $ws, "Mat $i", '<p>m</p>', $mat);
		}
		$index = fn () => self::page($l, 'index.php?workspace=' . rawurlencode($ws));
		self::page($l, 'index.php?workspace=' . rawurlencode($ws));
		[$before] = self::ms($index, 7);

		// 50 Aufgaben, alle ausgeteilt (30 × 50 Kopien)
		$t0 = hrtime(true);
		$first = null;
		for ($a = 0; $a < self::ASSIGNMENTS; $a++) {
			$asg = $this->createAssignment($l, $t, $src, "Aufgabe $a");
			$first ??= (int)$asg['id'];
			self::resetRateLimits();
			$d = $l->call('POST', "/assignments/{$asg['id']}/distribute");
			self::assertSame(200, $d->status, "Austeilen $a: " . substr($d->describe(), 0, 200));
		}
		self::report('Austeilen 50 × 30 (s)', (hrtime(true) - $t0) / 1e9);
		$qb = self::db()->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from('nextnotes_submissions')
			->where($qb->expr()->in('assignment_id', $qb->createNamedParameter($this->assignmentIds, IQueryBuilder::PARAM_INT_ARRAY)));
		self::assertSame(self::STUDENTS * self::ASSIGNMENTS, (int)$qb->executeQuery()->fetchOne(), 'alle Abgaben angelegt');

		// Hauptansicht der Lehrkraft
		[$after, $r] = self::ms($index, 7);
		self::assertSame(200, $r->status);
		self::report('index.php Lehrkraft vorher (ms)', $before);
		self::report('index.php Lehrkraft mit 50 Aufgaben (ms)', $after);
		self::assertLessThanOrEqual($before + 10.0, $after, "Hauptansicht +10 ms: vorher $before, nachher $after");

		// „Meine Aufgaben“ eines Schülers
		$s = $students[0];
		self::page($s, 'assignments.php');
		[$api, $ra] = self::ms(fn () => $s->call('GET', '/assignments'), 5);
		self::assertSame(200, $ra->status);
		self::assertCount(self::ASSIGNMENTS, $ra->json()['assignments'] ?? []);
		[$page, $rp] = self::ms(fn () => self::page($s, 'assignments.php'), 5);
		self::assertSame(200, $rp->status);
		self::report('GET /assignments Schüler (ms)', $api);
		self::report('assignments.php Schüler (ms)', $page);
		self::assertLessThanOrEqual(300.0, $api, 'GET /assignments ≤ 300 ms');
		self::assertLessThanOrEqual(300.0, $page, 'assignments.php ≤ 300 ms');

		// R8: 30 parallele Uploads, je Schüler in die eigene Kopie der ersten Aufgabe
		$qb = self::db()->getQueryBuilder();
		$qb->select('student_uid', 'note_id')->from('nextnotes_submissions')
			->where($qb->expr()->eq('assignment_id', $qb->createNamedParameter($first, IQueryBuilder::PARAM_INT)));
		$copies = [];
		foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
			$copies[(string)$row['student_uid']] = (int)$row['note_id'];
		}
		$bytes = str_repeat('u', 10000);
		$uploads = [];
		foreach ($students as $c) {
			$uploads[] = [$c, "/notes/{$copies[$c->uid]}/attachments", "up-{$c->uid}.bin", $bytes];
		}
		$attBefore = $this->teamAttachmentBytes($first);
		$t0 = hrtime(true);
		$rs = ApiClient::parallelUploadsOf($uploads);
		$wall = (hrtime(true) - $t0) / 1e9;
		self::report('30 parallele Uploads (s)', $wall);
		$bad = array_values(array_filter(array_map(static fn (Response $r) => $r->status === 201 ? null : substr($r->describe(), 0, 160), $rs)));
		self::report('Uploads nicht 201', count($bad));
		self::assertSame([], $bad, 'alle 30 Uploads 201');
		self::assertSame($attBefore + self::STUDENTS * strlen($bytes), $this->teamAttachmentBytes($first), 'Belegung exakt (keine verlorenen/doppelten Zählungen)');
		self::assertLessThanOrEqual(30.0, $wall, 'R8: 30 parallele Uploads ≤ 30 s');
	}

	private function teamAttachmentBytes(int $aid): int {
		$qb = self::db()->getQueryBuilder();
		$qb->select($qb->func()->sum('a.size'))->from('nextnotes_attachments', 'a')
			->innerJoin('a', 'nextnotes_notes', 'n', $qb->expr()->eq('n.id', 'a.note_id'))
			->where($qb->expr()->eq('n.assignment_id', $qb->createNamedParameter($aid, IQueryBuilder::PARAM_INT)));
		return (int)$qb->executeQuery()->fetchOne();
	}
}
