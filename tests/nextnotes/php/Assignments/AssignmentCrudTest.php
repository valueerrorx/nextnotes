<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Assignments;

use OCA\NextNotes\Db\AttachmentMapper;
use OCA\NextNotes\Service\SettingsService;
use OCA\NextNotes\Service\View\PageFrameService;
use OCA\NextNotes\Service\WorkspaceService;
use OCA\NextNotes\Tests\Support\Teams;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\NotFoundException;
use OCP\Server;

// M6d Done 1, 4, 5: Anlegen (Vorlage eingefroren, auch aus Material), Ändern, Vorlage bearbeiten, Löschen mit confirm, Schüler-Workspace (Q3)
final class AssignmentCrudTest extends AssignmentTestCase {
	private static function appFileExists(int $noteId, int $attachmentId): bool {
		try {
			Server::get(IAppDataFactory::class)->get('nextnotes')->getFolder('attachments')->getFolder((string)$noteId)->getFile((string)$attachmentId);
			return true;
		} catch (NotFoundException) {
			return false;
		}
	}

	// Done 1: Lehrkraft legt an, Vorlage ohne Workspace mit physisch kopiertem Anhang; Quelle danach ändern/löschen → Vorlage unverändert
	public function testCreateFromNoteAndMaterial(): void {
		$fx = $this->fixture(false);
		['l' => $l, 'tpl' => $tpl, 'src' => $src, 'aid' => $aid] = $fx;
		$a = self::ok($l->call('GET', "/assignments/$aid"), 'Aufgabe')['assignment'];
		self::assertSame('draft', $a['status']);
		self::assertSame($fx['t'], $a['team']);
		self::assertTrue($a['is_owner']);
		self::assertSame('Lehrkraft L', $a['created_by_name']);
		$row = self::row('nextnotes_notes', $tpl);
		self::assertSame('template', $row['role']);
		self::assertNull($row['workspace_id']);
		self::assertNull($row['folder_id']);
		self::assertSame($aid, (int)$row['assignment_id']);
		$asg = self::row('nextnotes_assignments', $aid);
		self::assertSame($l->uid, $asg['owner_uid']);
		self::assertSame($l->uid, $asg['created_by']);
		self::assertSame($src, (int)$asg['source_note_id']);
		$atts = Server::get(AttachmentMapper::class)->findForNotes([$tpl])[$tpl] ?? [];
		self::assertCount(1, $atts, 'Anhang kopiert');
		$copyAtt = (int)$atts[0]->getId();
		self::assertNotSame($fx['att'], $copyAtt);
		self::assertTrue(self::appFileExists($tpl, $copyAtt), 'Datei physisch kopiert');
		self::assertStringContainsString("/api/v1/notes/$tpl/attachments/$copyAtt", (string)$row['content'], 'Inhalts-URL umgeschrieben');
		self::assertSame($fx['bytes'], $l->call('GET', "/notes/$tpl/attachments/$copyAtt")->body, 'Lehrkraft lädt Vorlagen-Anhang');

		// Quelle ändern und endgültig löschen → Vorlage bleibt
		self::ok($l->call('PATCH', "/notes/$src", ['content' => '<p>geändert</p>']), 'Quelle ändern');
		self::ok($l->call('DELETE', "/notes/$src?permanent=1"), 'Quelle löschen');
		self::assertSame($row['content'], self::row('nextnotes_notes', $tpl)['content'], 'Vorlage unverändert');
		self::assertTrue(self::appFileExists($tpl, $copyAtt), 'Vorlagen-Datei bleibt');

		// aus Material-Notiz (Q2), auch durch die Moderatorin
		$mFolder = (int)self::ok($l->call('POST', '/folders', ['folder_name' => 'Material', 'workspace' => $fx['ws']]), 'Ordner')['folder_id'];
		$matNote = self::newNote($l, $fx['ws'], 'Material-Notiz', '<p>mat [[Andere]]</p>', $mFolder);
		self::ok($l->call('PUT', "/folders/$mFolder/material", ['material' => true]), 'Material');
		$am = $this->createAssignment($fx['m'], $fx['t'], $matNote, 'Aus Material');
		self::assertStringContainsString('[[Andere]]', (string)self::row('nextnotes_notes', (int)$am['template_note_id'])['content'], 'Verweise nicht umgeschrieben (Q2)');

		// verknüpfte Notiz → Ziel
		$other = self::newNote($l, $fx['ws'], 'Ziel', '<p>ziel</p>');
		$link = (int)self::ok($l->call('POST', '/notes', ['heading' => 'Link', 'type' => 'linked', 'linked_note_id' => $other, 'workspace' => $fx['ws']]), 'Verknüpfung')['note']['id'];
		$al = $this->createAssignment($l, $fx['t'], $link, 'Aus Verknüpfung');
		self::assertSame($other, (int)self::row('nextnotes_assignments', (int)$al['id'])['source_note_id'], 'Ziel als Quelle');

		// Ablehnungen: Schüler 403, Nicht-Mitglied 404, versteckter Kreis 403, Quelle nicht lesbar 404, Vorlage als Quelle 400, Frist vorbei 400
		$body = static fn (string $ws, int $source, int $due = 7200) => ['workspace' => $ws, 'title' => 'x', 'due_at' => time() + $due, 'source_note_id' => $source];
		$count = self::countRows('nextnotes_assignments', 'team_id', $fx['t']);
		self::assertSame(403, $fx['s1']->call('POST', '/assignments', $body($fx['ws'], $other))->status, 'S1');
		self::assertSame(404, $fx['n']->call('POST', '/assignments', $body($fx['ws'], $other))->status, 'Nicht-Mitglied');
		[$nWs] = self::newWorkspace($fx['n'], 'Fremd');
		$foreign = self::newNote($fx['n'], $nWs, 'Fremd', '<p>f</p>');
		self::assertSame(404, $l->call('POST', '/assignments', $body($fx['ws'], $foreign))->status, 'Quelle nicht lesbar');
		self::assertSame(400, $l->call('POST', '/assignments', $body($fx['ws'], $tpl))->status, 'Vorlage als Quelle');
		self::assertSame(400, $l->call('POST', '/assignments', $body($fx['ws'], $other, -10))->status, 'Frist vorbei');
		self::assertSame(400, $l->call('POST', '/assignments', ['workspace' => $fx['ws'], 'title' => '', 'due_at' => time() + 60, 'source_note_id' => $other])->status, 'ohne Titel');
		self::assertSame($count, self::countRows('nextnotes_assignments', 'team_id', $fx['t']), 'nichts angelegt');
		$hidden = $this->teams->create($l->uid, 'Versteckt ' . bin2hex(random_bytes(3)), Teams::HIDDEN);
		// Workspace eines versteckten Kreises ist nicht lesbar (Probe-Regel) → 404
		$hiddenWs = $this->rawWorkspace('team', $hidden, $l->uid);
		self::assertSame(404, $l->call('POST', '/assignments', $body(self::row('nextnotes_workspaces', $hiddenWs)['name'] . " (x, #$hiddenWs)", $other))->status, 'versteckter Kreis');
		self::setTeamState($fx['t'], 'orphaned');
		self::assertSame(403, $l->call('POST', '/assignments', $body($fx['ws'], $other))->status, 'verwaistes Team');
		self::setTeamState($fx['t'], 'active');
	}

	// Ändern (§9.5) und Vorlage im Editor (nur draft, nur Lehrkraft; kein Umzug, Review-Hinweis 8)
	public function testUpdateAndTemplateEditing(): void {
		$fx = $this->fixture(false);
		['l' => $l, 'aid' => $aid, 'tpl' => $tpl] = $fx;
		$due = (int)self::row('nextnotes_assignments', $aid)['due_at'];
		$a = self::ok($l->call('PATCH', "/assignments/$aid", ['title' => 'Neuer Titel', 'max_points' => 10, 'due_at' => $due + 100]), 'PATCH')['assignment'];
		self::assertSame(['Neuer Titel', 10, $due + 100], [$a['title'], $a['max_points'], $a['due_at']]);
		self::ok($l->call('PATCH', "/assignments/$aid", ['due_at' => $due + 50]), 'Entwurf: Frist darf früher werden');
		self::assertSame(400, $l->call('PATCH', "/assignments/$aid", ['due_at' => time() - 5])->status, 'Frist in der Vergangenheit');
		self::assertSame(400, $l->call('PATCH', "/assignments/$aid", ['max_points' => -1])->status, 'Punkte negativ');
		self::assertSame(404, $fx['s1']->call('PATCH', "/assignments/$aid", ['title' => 'x'])->status, 'S1 ohne Abgabe');
		// ausgeteilt: nur verlängern
		self::setColumn('nextnotes_assignments', $aid, 'status', 'distributed');
		self::assertSame(400, $l->call('PATCH', "/assignments/$aid", ['due_at' => $due])->status, 'ausgeteilt: nicht verkürzen');
		self::ok($l->call('PATCH', "/assignments/$aid", ['due_at' => $due + 500]), 'ausgeteilt: verlängern');
		self::setColumn('nextnotes_assignments', $aid, 'status', 'closed');
		self::assertSame(409, $l->call('PATCH', "/assignments/$aid", ['due_at' => $due + 900])->status, 'geschlossen: keine Frist');
		self::ok($l->call('PATCH', "/assignments/$aid", ['title' => 'Titel geschlossen']), 'geschlossen: Titel');

		// Vorlage: Entwurf → Lehrkraft schreibt; sonst nur lesen; kein Umzug, kein Löschen
		self::setColumn('nextnotes_assignments', $aid, 'status', 'draft');
		self::ok($fx['m']->call('PATCH', "/notes/$tpl", ['heading' => 'Vorlage neu', 'content' => '<p>neu</p>']), 'M bearbeitet Vorlage');
		$row = self::row('nextnotes_notes', $tpl);
		self::assertSame(['Vorlage neu', null, null, 'template'], [$row['heading'], $row['workspace_id'], $row['folder_id'], $row['role']], 'Vorlage ohne Ort');
		self::ok($l->upload("/notes/$tpl/attachments", 'neu.txt', 'neu'), 'Anhang an Vorlage (Team-Quote)');
		self::assertSame(400, $l->call('PATCH', "/notes/$tpl", ['content' => '<p>x</p>', 'workspace' => $fx['ws']])->status, 'Vorlage verschieben');
		self::assertSame(400, $l->call('PATCH', "/notes/$tpl", ['content' => '<p>x</p>', 'folder' => 'A/B'])->status, 'Vorlage mit Pfad');
		self::assertSame(403, $l->call('DELETE', "/notes/$tpl")->status, 'Vorlage in den Papierkorb');
		self::assertSame(404, $fx['s1']->call('PATCH', "/notes/$tpl", ['content' => '<p>x</p>'])->status, 'S1 auf Vorlage');
		self::setColumn('nextnotes_assignments', $aid, 'status', 'distributed');
		self::assertSame(403, $l->call('PATCH', "/notes/$tpl", ['content' => '<p>x</p>'])->status, 'ausgeteilt: Vorlage nur lesen');
		self::ok($l->call('GET', "/notes/$tpl"), 'ausgeteilt: Vorlage lesen');
		self::setColumn('nextnotes_assignments', $aid, 'status', 'draft');
		self::setTeamState($fx['t'], 'orphaned');
		self::assertSame(403, $l->call('PATCH', "/notes/$tpl", ['content' => '<p>x</p>'])->status, 'verwaist: Vorlage nur lesen');
		self::setTeamState($fx['t'], 'active');
	}

	// Done 5: DELETE ohne confirm 400; mit confirm Aufgabe, Vorlage, Abgaben, Kopien, Dateien, Ordner, leere Schüler-Workspaces weg; Wiederholung 404
	public function testDeleteWithConfirm(): void {
		$fx = $this->fixture();
		['l' => $l, 'aid' => $aid, 'tpl' => $tpl, 'sub1' => $sub1, 'sub2' => $sub2] = $fx;
		$tplAtt = (int)Server::get(AttachmentMapper::class)->findForNotes([$tpl])[$tpl][0]->getId();
		self::assertSame(403, $fx['s1']->call('DELETE', "/assignments/$aid", ['confirm' => true])->status, 'S1');
		self::assertSame(400, $l->call('DELETE', "/assignments/$aid")->status, 'ohne confirm');
		self::assertSame(400, $l->call('DELETE', "/assignments/$aid", ['confirm' => false])->status, 'confirm false');
		self::assertNotNull(self::row('nextnotes_assignments', $aid), 'noch da');
		// zweite Aufgabe im selben Schüler-Workspace: Workspace bleibt, solange sie existiert
		$other = $this->createAssignment($l, $fx['t'], $fx['src'], 'Zweite');
		$keep = $this->rawSubmission((int)$other['id'], $fx['s1'], $fx['t'], 'bleibt');
		self::ok($l->call('DELETE', "/assignments/$aid", ['confirm' => true]), 'löschen');
		self::assertNull(self::row('nextnotes_assignments', $aid));
		foreach ([$tpl, $sub1['note'], $sub2['note']] as $n) {
			self::assertNull(self::row('nextnotes_notes', $n), "Notiz $n weg");
		}
		self::assertSame(0, self::countRows('nextnotes_submissions', 'assignment_id', $aid), 'Abgaben weg');
		self::assertSame(0, self::countRows('nextnotes_attachments', 'note_id', $sub1['note']), 'Anhangszeilen weg');
		self::assertSame(0, self::countRows('nextnotes_snapshots', 'note_id', $sub1['note']), 'Snapshots weg');
		self::assertFalse(self::appFileExists($tpl, $tplAtt), 'Vorlagen-Datei weg');
		self::assertFalse(self::appFileExists($sub1['note'], $sub1['att']), 'Kopie-Datei weg');
		self::assertNull(self::row('nextnotes_folders', $sub1['folder']), 'Aufgaben-Ordner weg');
		self::assertNull(self::row('nextnotes_workspaces', $sub2['wsId']), 'leerer Schüler-Workspace S2 weg');
		self::assertNotNull(self::row('nextnotes_workspaces', $sub1['wsId']), 'Schüler-Workspace S1 mit anderer Aufgabe bleibt');
		self::assertNotNull(self::row('nextnotes_notes', $keep['note']), 'Abgabe der anderen Aufgabe bleibt');
		self::assertSame(404, $l->call('DELETE', "/assignments/$aid", ['confirm' => true])->status, 'Wiederholung');
		self::assertSame(404, $l->call('GET', "/assignments/$aid")->status, 'GET danach');
		// verwaist: owner_uid löscht, Lehrkraft ohne owner_uid nicht
		$third = $this->createAssignment($l, $fx['t'], $fx['src'], 'Dritte');
		self::setTeamState($fx['t'], 'orphaned');
		self::assertSame(404, $fx['m']->call('DELETE', '/assignments/' . $third['id'], ['confirm' => true])->status, 'M verwaist');
		self::ok($l->call('DELETE', '/assignments/' . $third['id'], ['confirm' => true]), 'owner_uid verwaist');
		self::setTeamState($fx['t'], 'active');
	}

	// Done 4 (Q3): Schüler-Workspace kind=assignments – nichts anlegen/verwalten, nicht in Listen, Schlüssel öffnet den Editor
	public function testAssignmentWorkspace(): void {
		$fx = $this->fixture();
		$s1 = $fx['s1'];
		$sub1 = $fx['sub1'];
		$key = Server::get(WorkspaceService::class)->keyFor($s1->uid, $sub1['wsId']);
		self::assertNotNull($key, 'Schlüssel auflösbar');
		self::assertSame(403, $s1->call('POST', '/notes', ['heading' => 'x', 'content' => '<p>x</p>', 'workspace' => $key])->status, 'Notiz anlegen');
		self::assertSame(403, $s1->call('POST', '/notes', ['heading' => 'x', 'content' => '<p>x</p>', 'folder_id' => $sub1['folder']])->status, 'Notiz im Aufgaben-Ordner');
		self::assertSame(403, $s1->call('POST', '/folders', ['folder_name' => 'x', 'workspace' => $key])->status, 'Ordner anlegen');
		self::assertSame(403, $s1->call('PATCH', '/workspaces/' . rawurlencode($key), ['new_name' => 'x'])->status, 'umbenennen');
		self::assertSame(403, $s1->call('DELETE', '/workspaces/' . rawurlencode($key))->status, 'löschen');
		self::assertNotContains($key, self::workspaceNames($s1), 'GET /workspaces ohne Schüler-Workspace');
		self::assertStringNotContainsString(json_encode($key), self::page($s1, 'settings.php')->body, 'Einstellungen ohne Schüler-Workspace');
		self::assertSame(200, self::page($s1, 'index.php?workspace=' . rawurlencode($key) . '&note=' . $sub1['note'])->status, 'Editor über Schlüssel');
		// Startseite: zuletzt genutzter Schüler-Workspace → Standard-Workspace
		Server::get(SettingsService::class)->set($s1->uid, 'last_opened_workspace', $key);
		$frame = Server::get(PageFrameService::class);
		self::assertSame('normal', $frame->workspace($s1->uid, '')->getKind(), 'Startseite nicht im Schüler-Workspace');
		self::assertSame($sub1['wsId'], (int)$frame->workspace($s1->uid, $key)->getId(), 'ausdrücklich angefragt: Schüler-Workspace');
		self::assertSame(200, self::page($s1, 'index.php')->status, 'Startseite');
		// Standard-Workspace entsteht auch, wenn nur der Schüler-Workspace existiert
		$fresh = $this->createUser('fresh');
		$this->assignmentWorkspace($fresh->uid, $fx['t']);
		self::assertNotSame([], self::workspaceNames($fresh), 'Standard-Workspace angelegt');
	}
}
