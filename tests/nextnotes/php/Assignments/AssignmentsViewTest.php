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

	// M6g2: Editor-Banner der eigenen Kopie (Abgeben, Nur-Lesen mit Grund, Zurückziehen), Vorlage für Lehrkraft lesbar mit Banner, Upstream-Speichern mit folder_id
	public function testEditorBannerAndTemplatePage(): void {
		$fx = $this->distributedFixture();
		['l' => $l, 's1' => $s1, 's2' => $s2, 'aid' => $aid, 'tpl' => $tpl] = $fx;
		$sub = $fx['subs'][$s1->uid];
		$note = (int)$sub['note_id'];
		$folder = (int)self::row('nextnotes_notes', $note)['folder_id'];
		self::assertGreaterThan(0, $folder, 'Kopie liegt im Aufgaben-Ordner');

		$p = self::page($s1, "index.php?note=$note");
		self::assertSame(200, $p->status, $p->describe());
		self::assertStringContainsString('nn-asg-banner', $p->body, 'S1: Banner');
		self::assertStringContainsString('nn-asg-submit', $p->body, 'S1: Abgeben');
		self::assertStringContainsString('assignments.php?assignment=' . $aid, $p->body, 'S1: Link zur Aufgabe');
		self::assertStringNotContainsString('data-nextnotes-readonly', $p->body, 'S1: bearbeitbar');
		// Upstream-Speichern (notes.js) mit unverändertem folder_id
		self::ok($s1->call('PATCH', "/notes/$note", ['content' => '<p>mit Ordner</p>', 'folder_id' => $folder, 'tags' => '']), 'Speichern mit folder_id');
		self::assertSame(400, $s1->call('PATCH', "/notes/$note", ['content' => '<p>x</p>', 'folder_id' => $folder + 999999])->status, 'anderer Ordner bleibt 400');

		self::ok($s1->call('POST', "/submissions/{$sub['id']}/submit"), 'abgeben');
		$p = self::page($s1, "index.php?note=$note");
		self::assertStringContainsString('data-nextnotes-readonly', $p->body, 'S1: nach Abgabe nur lesen');
		self::assertStringContainsString('nn-asg-unsubmit', $p->body, 'S1: Zurückziehen');
		self::assertStringNotContainsString('nn-asg-submit"', $p->body, 'S1: kein Abgeben');
		self::assertSame(404, self::page($s2, "index.php?note=$note")->status, 'S2: fremde Kopie 404');
		self::setColumn('nextnotes_assignments', $aid, 'due_at', time() - 5);
		$p = self::page($s1, "index.php?note=$note");
		self::assertStringNotContainsString('nn-asg-unsubmit', $p->body, 'nach Frist kein Zurückziehen');
		self::setColumn('nextnotes_assignments', $aid, 'due_at', time() + 3600);

		// Vorlage: Lehrkraft 200 mit Banner (ausgeteilt → nur lesen), Schüler 404
		$p = self::page($l, "index.php?note=$tpl");
		self::assertSame(200, $p->status, 'L: Vorlage öffnen ' . $p->describe());
		self::assertStringContainsString('data-nn-asg-kind="template"', $p->body, 'L: Vorlagen-Banner');
		self::assertStringContainsString('data-nextnotes-readonly', $p->body, 'L: ausgeteilt → nur lesen');
		self::assertSame(404, self::page($s1, "index.php?note=$tpl")->status, 'S1: Vorlage 404');
		$draft = $this->createAssignment($l, $fx['t'], $fx['src'], 'Entwurf');
		$p = self::page($l, 'index.php?note=' . (int)$draft['template_note_id']);
		self::assertSame(200, $p->status);
		self::assertStringNotContainsString('data-nextnotes-readonly', $p->body, 'L: Entwurf bearbeitbar');
		self::ok($l->call('PATCH', '/notes/' . (int)$draft['template_note_id'], ['content' => '<p>Vorlage neu</p>', 'folder_id' => null]), 'L speichert Vorlage');
	}
}
