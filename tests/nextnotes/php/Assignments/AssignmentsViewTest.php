<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Assignments;

use OCA\NextNotes\Service\View\AssignmentsViewService;
use OCA\NextNotes\Service\WorkspaceService;
use OCP\Server;

// M6g/M6i: Seite assignments.php folgt dem Team-Workspace; Icon-Leiste und „Als Aufgabe austeilen…“ nur dort, Fremde sehen nichts
final class AssignmentsViewTest extends AssignmentTestCase {
	public function testPageFollowsTeamWorkspace(): void {
		$fx = $this->distributedFixture();
		['l' => $l, 'm' => $m, 's1' => $s1, 'n' => $n, 't' => $t, 'aid' => $aid, 'mark' => $mark, 'ws' => $ws] = $fx;
		$view = static fn () => clone Server::get(AssignmentsViewService::class);
		$wq = 'workspace=' . rawurlencode($ws);

		self::assertSame($t, $view()->teacherTeam($l->uid, $t)['id'] ?? null, 'Owner legt an');
		self::assertSame($t, $view()->teacherTeam($m->uid, $t)['id'] ?? null, 'Moderator legt an');
		self::assertNull($view()->teacherTeam($s1->uid, $t), 'Schüler nicht');
		self::assertNull($view()->teacherTeam($n->uid, $t), 'Fremder nicht');
		$row = $view()->teacherTeam($l->uid, $t);
		self::assertSame(3, $row['students'], 'S1, S2, X');
		self::assertGreaterThan(0, $row['used'], 'Belegung mit Kopien');
		// ?create=: nur Notizen aus Workspaces dieses Teams
		[$own] = self::newWorkspace($l, 'Privat');
		$private = self::newNote($l, $own, 'Privat', '<p>p</p>');
		self::assertSame($fx['src'], $view()->createSource($l->uid, (string)$fx['src'], $t), 'create: Notiz des Teams');
		self::assertNull($view()->createSource($l->uid, (string)$private, $t), 'create: private Notiz nicht');
		self::assertNull($view()->createSource($n->uid, (string)$fx['src'], $t), 'create: ohne Leserecht');
		self::assertNull($view()->createSource($l->uid, '1 OR 1', $t), 'create: ungültig');

		// Team-Workspace: Bereich fest, Chip statt Team-Filter, Anlegen nur Lehrkraft
		$p = self::page($l, "assignments.php?$wq&create=" . $fx['src']);
		self::assertSame(200, $p->status, $p->describe());
		self::assertStringContainsString('iconSidebarAssignmentsBtn', $p->body, 'L: Eintrag im Team-Workspace');
		self::assertStringContainsString('"team":"' . $t . '"', $p->body, 'Insel: Team des Workspace');
		self::assertStringContainsString('"createSource":' . $fx['src'], $p->body, 'Insel: Quelle');
		self::assertStringContainsString('id="nnAsgNew"', $p->body, 'Lehrkraft: Anlegen');
		self::assertStringContainsString('data-nn-ws-chip=', $p->body, 'Workspace-Chip');
		self::assertStringNotContainsString('nnAsgTeam', $p->body, 'kein Team-Filter');
		self::assertStringContainsString('"createSource":null', self::page($l, "assignments.php?$wq&create=$private")->body, 'private Quelle nicht übernommen');
		$p = self::page($s1, "assignments.php?$wq");
		self::assertStringContainsString('iconSidebarAssignmentsBtn', $p->body, 'S1: Eintrag im Team-Workspace');
		self::assertStringNotContainsString('id="nnAsgNew"', $p->body, 'Schüler: kein Anlegen');

		// privater Workspace: kein Eintrag, leerer Zustand, kein Anlegen; Team hat einen lesbaren Workspace
		$p = self::page($l, 'assignments.php?workspace=' . rawurlencode($own));
		self::assertSame(200, $p->status);
		self::assertStringNotContainsString('iconSidebarAssignmentsBtn', $p->body, 'privat: kein Eintrag');
		self::assertStringContainsString('nn-asg-no-team', $p->body, 'privat: Hinweis');
		self::assertStringContainsString('"team":null', $p->body);
		self::assertStringNotContainsString('id="nnAsgNew"', $p->body, 'privat: kein Anlegen');
		self::assertStringNotContainsString('iconSidebarAssignmentsBtn', self::page($l, 'index.php?workspace=' . rawurlencode($own))->body, 'Hauptansicht privat: kein Eintrag');
		self::assertStringContainsString('"nn-teacher":"0"', self::page($l, 'index.php?workspace=' . rawurlencode($own))->body, 'privat: kein Austeilen');
		$idx = self::page($l, "index.php?$wq")->body;
		self::assertStringContainsString('iconSidebarAssignmentsBtn', $idx, 'Hauptansicht Team: Eintrag');
		self::assertStringContainsString('"nn-teacher":"1"', $idx, 'Team-Workspace Lehrkraft: Austeilen');
		self::assertStringContainsString('"nn-teacher":"0"', self::page($s1, "index.php?$wq")->body, 'Schüler: kein Austeilen');

		// Fremder: Team-Workspace nicht lesbar → eigener Standard, kein Eintrag, keine Daten
		$p = self::page($n, "assignments.php?assignment=$aid&$wq");
		self::assertSame(200, $p->status);
		self::assertStringNotContainsString('iconSidebarAssignmentsBtn', $p->body, 'Fremder: kein Eintrag');
		self::assertStringNotContainsString($mark, $p->body, 'Fremder: keine Aufgabe');
		self::assertStringNotContainsString($t, $p->body, 'Fremder: keine Team-ID');

		// ohne lesbaren Team-Workspace (Austritt): Aufgabe erscheint im eigenen Workspace unter „ohne Team-Workspace“
		$wsRow = static fn (string $uid) => Server::get(WorkspaceService::class)->defaultWorkspace($uid);
		self::assertContains($t, $view()->page($s1->uid, $wsRow($s1->uid), null)['homeTeams'], 'S1 Mitglied: Team hat Heimat');
		$this->teams->remove($l->uid, $fx['memberS1']);
		Server::get(WorkspaceService::class)->reset();
		self::assertNotContains($t, $view()->page($s1->uid, $wsRow($s1->uid), null)['homeTeams'], 'S1 ausgetreten: ohne Team-Workspace');
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
		self::assertStringContainsString(htmlspecialchars('assignments.php?' . http_build_query(['workspace' => $fx['ws'], 'assignment' => $aid])), $p->body, 'S1: Link zur Aufgabe im Team-Workspace (M6i)');
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
