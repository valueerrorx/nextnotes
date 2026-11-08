<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Assignments;

use OCA\NextNotes\Service\View\AssignmentsViewService;
use OCP\Server;

// M6g: Seite assignments.php – Icon-Leiste nur mit Team oder Abgabe, Teams zum Anlegen nur als Lehrkraft, Fremde sehen nichts
final class AssignmentsViewTest extends AssignmentTestCase {
	public function testRailAndTeacherTeams(): void {
		$fx = $this->distributedFixture();
		['l' => $l, 'm' => $m, 's1' => $s1, 'n' => $n, 't' => $t, 'aid' => $aid, 'mark' => $mark] = $fx;
		$view = static fn () => clone Server::get(AssignmentsViewService::class);

		self::assertTrue($view()->railVisible($l->uid), 'Lehrkraft');
		self::assertTrue($view()->railVisible($s1->uid), 'Schüler');
		self::assertFalse($view()->railVisible($n->uid), 'ohne Team und Abgabe');
		self::assertSame([$t], array_column($view()->teacherTeams($l->uid), 'id'), 'Owner legt an');
		self::assertSame([$t], array_column($view()->teacherTeams($m->uid), 'id'), 'Moderator legt an');
		self::assertSame([], $view()->teacherTeams($s1->uid), 'Schüler nicht');
		self::assertTrue($view()->teachesAny($l->uid), 'Notiz-Menü: L');
		self::assertFalse($view()->teachesAny($s1->uid), 'Notiz-Menü: S1 nicht');
		// ?create=: Team des Notiz-Workspace nur für Lehrkräfte mit Leserecht
		self::assertSame($t, $view()->createTeam($l->uid, (string)$fx['src'], $view()->teacherTeams($l->uid)), 'create: Team der Notiz');
		self::assertNull($view()->createTeam($s1->uid, (string)$fx['src'], []), 'create: Schüler');
		self::assertNull($view()->createTeam($n->uid, (string)$fx['src'], [['id' => $t]]), 'create: ohne Leserecht');
		self::assertNull($view()->createTeam($l->uid, '1 OR 1', $view()->teacherTeams($l->uid)), 'create: ungültig');
		$row = $view()->teacherTeams($l->uid)[0];
		self::assertSame(3, $row['students'], 'S1, S2, X');
		self::assertGreaterThan(0, $row['used'], 'Belegung mit Kopien');

		// Seite: Rail-Eintrag, Insel; Fremder ohne Eintrag und ohne Daten
		$p = self::page($l, 'assignments.php?create=' . $fx['src']);
		self::assertStringContainsString('"createTeam":"' . $t . '"', $p->body, 'Insel: createTeam');
		self::assertSame(200, $p->status, $p->describe());
		self::assertStringContainsString('iconSidebarAssignmentsBtn', $p->body);
		self::assertStringContainsString('id="nnAsgNew"', $p->body, 'Lehrkraft: Anlegen');
		$p = self::page($s1, 'assignments.php');
		self::assertStringContainsString('iconSidebarAssignmentsBtn', $p->body);
		self::assertStringNotContainsString('id="nnAsgNew"', $p->body, 'Schüler: kein Anlegen');
		$p = self::page($n, "assignments.php?assignment=$aid&team=" . rawurlencode($t));
		self::assertSame(200, $p->status);
		self::assertStringNotContainsString('iconSidebarAssignmentsBtn', $p->body, 'Fremder: kein Eintrag');
		self::assertStringNotContainsString($mark, $p->body, 'Fremder: keine Aufgabe');
		self::assertStringNotContainsString($t, $p->body, 'Fremder: keine Team-ID');
		self::assertStringNotContainsString('iconSidebarAssignmentsBtn', self::page($n, 'index.php')->body, 'Fremder: Hauptansicht ohne Eintrag');
	}
}
