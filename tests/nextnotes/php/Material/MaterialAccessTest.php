<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Material;

use OCA\NextNotes\Service\AccessService;
use OCA\NextNotes\Service\FolderService;
use OCA\NextNotes\Service\NoteService;
use OCA\NextNotes\Service\Exception\ForbiddenException;
use OCA\NextNotes\Service\TeamLifecycleService;
use OCA\NextNotes\Service\View\IndexViewService;
use OCA\NextNotes\Tests\Support\Teams;
use OCP\IDBConnection;
use OCP\Server;

// M6a Done 1–4, 5b, 6–8: Material-Ordner lesen ja, schreiben nur Lehrkraft (Level ≥ 4), Kennzeichen, Verschieben, Papierkorb, Verlassen
final class MaterialAccessTest extends MaterialTestCase {
	// Done 4: Kennzeichen setzen/entfernen, Fehlercodes (H6), Ordner-JSON
	public function testMarkingAndErrorCodes(): void {
		$x = $this->fixture();
		['l' => $l, 's' => $s, 'm' => $m, 'u' => $u, 'ws' => $ws] = $x;
		$mod = $this->createUser('mm');
		$this->teams->addUser($x['t'], $l->uid, $mod->uid, Teams::MODERATOR);

		$tree = self::ok($l->call('GET', '/folders' . self::q(['workspace' => $ws, 'hierarchical' => 1])), 'Ordnerbaum')['folders'];
		$mJson = array_values(array_filter($tree, static fn ($f) => $f['id'] === $m))[0];
		self::assertTrue($mJson['material']);
		self::assertFalse($mJson['material_inherited']);
		self::assertFalse($mJson['children'][0]['material']);
		self::assertTrue($mJson['children'][0]['material_inherited'], 'M/U erbt');
		self::assertTrue(self::ok($s->call('GET', "/folders/$u" . self::q(['workspace' => $ws])), 'S liest M/U')['folder']['material_inherited']);

		self::denied($s->call('PUT', "/folders/$m/material", ['material' => false]), 'S entfernt');
		self::denied($s->call('PUT', "/folders/$u/material", ['material' => true]), 'S setzt');
		$r = self::ok($mod->call('PUT', "/folders/$m/material", ['material' => false]), 'Moderator (Level 4) entfernt');
		self::assertFalse($r['folder']['material']);
		// Entfernen gibt sofort Schreibrecht
		self::ok($s->call('PATCH', "/notes/{$x['n1']}", ['content' => '<p>frei</p>']), 'S schreibt nach Entfernen');
		self::ok($l->call('PUT', "/folders/$m/material", ['material' => true]), 'L setzt erneut');
		self::denied($s->call('PATCH', "/notes/{$x['n1']}", ['content' => '<p>zu</p>']), 'S nach erneutem Setzen');
		$r = $l->call('PUT', "/folders/$u/material", ['material' => true]);
		self::denied($r, 'Vorfahr Material', 409);
		self::assertSame('already_material', $r->json()['code'] ?? null);
		self::denied($l->call('PUT', "/folders/$m/material", []), 'ohne material', 400);

		// privater, geteilter und kind=assignments-Workspace → 400 not_a_team_workspace; fremder unsichtbarer Ordner → 404
		[$pk, $pId] = self::newWorkspace($l, 'Privat');
		$pf = self::folder($l, $pk, 'P');
		$r = $l->call('PUT', "/folders/$pf/material", ['material' => true]);
		self::denied($r, 'privat', 400);
		self::assertSame('not_a_team_workspace', $r->json()['code'] ?? null);
		self::rawShare($pId, $s->uid, 'write');
		Teams::reset();
		self::assertSame('not_a_team_workspace', $s->call('PUT', "/folders/$pf/material", ['material' => true])->json()['code'] ?? null, 'geteilt');
		$asg = $this->rawWorkspace('user', $l->uid, $l->uid, 'assignments');
		$qb = self::db()->getQueryBuilder();
		$qb->insert('nextnotes_folders')->values(['workspace_id' => $qb->createNamedParameter($asg), 'name' => $qb->createNamedParameter('A'),
			'created_at' => $qb->createNamedParameter(time()), 'updated_at' => $qb->createNamedParameter(time())])->executeStatement();
		$af = $qb->getLastInsertId();
		self::assertSame('not_a_team_workspace', $l->call('PUT', "/folders/$af/material", ['material' => true])->json()['code'] ?? null, 'kind=assignments');
		$other = $this->createUser('mo');
		[$ok] = self::newWorkspace($other, 'Fremd');
		self::denied($l->call('PUT', '/folders/' . self::folder($other, $ok, 'F') . '/material', ['material' => true]), 'fremder Ordner', 404);
		self::assertSame('0', self::folderRow($pf)['material']);
		$qb = self::db()->getQueryBuilder();
		$qb->delete('nextnotes_folders')->where($qb->expr()->eq('id', $qb->createNamedParameter($af)))->executeStatement();

		// verwaistes Team → 403 (auch für created_by)
		self::setTeamState($x['t'], 'orphaned');
		try {
			self::denied($l->call('PUT', "/folders/$m/material", ['material' => false]), 'verwaist');
		} finally {
			self::setTeamState($x['t'], 'active');
		}
		self::assertSame('1', self::folderRow($m)['material']);
	}

	// Done 1: S liest alles, jeder Schreibweg an n1 → 403, Inhalt unverändert; L → 200
	public function testStudentReadsButCannotWrite(): void {
		$x = $this->fixture();
		['l' => $l, 's' => $s, 'n1' => $n1, 'att' => $att, 'tl' => $tl, 'ex' => $ex, 'ws' => $ws, 'mark' => $mark] = $x;
		self::ok($s->call('GET', "/notes/$n1"), 'S liest n1');
		self::assertSame(200, $s->call('GET', "/notes/$n1/attachments/$att")->status, 'S liest Anhang');
		self::ok($s->call('GET', "/notes/$n1/snapshots"), 'S liest Snapshots');
		self::assertContains($n1, self::ok($s->call('GET', '/notes/search/ids' . self::q(['q' => $mark])), 'Suche')['ids']);
		self::assertSame(200, $s->absolute('GET', self::APP . "api_export_note.php?id=$n1")->status, 'S exportiert');
		self::ok($s->call('GET', "/notes/$tl/tasks"), 'S liest Tasks');
		$before = array_map(static fn (int $id) => self::note($id), [$n1, $tl, $ex]);

		$taskId = (string)self::ok($l->call('GET', "/notes/$tl/tasks"), 'Tasks')['tasks'][0]['id'];
		$writes = [
			'PATCH' => fn ($c) => $c->call('PATCH', "/notes/$n1", ['content' => '<p>x</p>', 'heading' => 'x']),
			'beacon' => fn ($c) => $c->call('POST', "/notes/$n1/beacon", ['content' => '<p>b</p>']),
			'tags' => fn ($c) => $c->call('PUT', "/notes/$n1/tags", ['tags' => ['t1'], 'workspace' => $ws]),
			'icon' => fn ($c) => $c->call('PUT', "/notes/$n1/icon", ['icon' => 'fa-star']),
			'color' => fn ($c) => $c->call('PUT', "/notes/$n1/color", ['color' => 'red']),
			'pinned' => fn ($c) => $c->call('PUT', "/notes/$n1/pinned", ['pinned' => true]),
			'favorite' => fn ($c) => $c->call('POST', "/notes/$n1/favorite", []),
			'convert' => fn ($c) => $c->call('POST', "/notes/$n1/convert", ['target' => 'markdown']),
			'task abhaken' => fn ($c) => $c->call('PATCH', "/notes/$tl/tasks/$taskId", ['completed' => true]),
			'task anlegen' => fn ($c) => $c->call('POST', "/notes/$tl/tasks", ['text' => 'neu']),
			'task Unteraufgabe' => fn ($c) => $c->call('POST', "/notes/$tl/tasks/$taskId/subtasks", ['text' => 'unter']),
			'Anhang hoch' => fn ($c) => $c->upload("/notes/$n1/attachments", 'b.png', (string)base64_decode(self::PNG), [], 'image/png'),
			'Anhang verschieben' => fn ($c) => $c->call('POST', "/notes/$n1/attachments/$att/move", ['target_note_id' => $x['sN']]),
			'Excalidraw' => fn ($c) => self::excalidraw($c, $ex, 'gekapert'),
			'Snapshot-Restore' => fn ($c) => $c->call('POST', "/notes/$n1/snapshot/restore" . self::q(['snapshot_key' => $x['snap']])),
			'Lock' => fn ($c) => $c->call('POST', "/notes/$n1/lock", ['editor_session_id' => 'mat-s1']),
			'Kanban erledigt' => fn ($c) => $c->call('POST', "/notes/$n1/kanban-completed", ['completed' => true]),
			'Anhang löschen' => fn ($c) => $c->call('DELETE', "/notes/$n1/attachments/$att"),
			'löschen' => fn ($c) => $c->call('DELETE', "/notes/$n1"),
		];
		foreach ($writes as $what => $fn) {
			self::denied($fn($s), "S $what");
		}
		self::assertSame($before, array_map(static fn (int $id) => self::note($id), [$n1, $tl, $ex]), 'S hat Material verändert');
		self::assertSame(200, $s->call('GET', "/notes/$n1/attachments/$att")->status, 'Anhang noch da');
		// Erinnerung bleibt möglich (benutzerbezogen, Leserecht)
		self::ok($s->call('POST', "/notes/$n1/reminder", ['reminder_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600)]), 'S Erinnerung');

		foreach (['PATCH', 'tags', 'task abhaken', 'task anlegen', 'Excalidraw', 'Lock', 'Snapshot-Restore'] as $what) {
			self::assertTrue($writes[$what]($l)->ok(), "L $what");
		}
		self::ok($l->call('POST', "/notes/$n1/lock/release", ['editor_session_id' => 'mat-s1']), 'L gibt frei');
	}

	// Done 2: Anlegen im Material-Zweig (API, Pfade, Tagebuch, Import, Duplikat-Ziel) → 403, kein Ordner; L → 200
	public function testCreateInMaterial(): void {
		$x = $this->fixture();
		['l' => $l, 's' => $s, 'm' => $m, 'u' => $u, 'ws' => $ws, 'wsId' => $wsId, 'mark' => $mark] = $x;
		$mName = "M $mark";
		$folders = self::folderIds($wsId);
		$notes = self::noteIds($wsId);
		$attempts = [
			'Notiz folder_id' => fn ($c) => $c->call('POST', '/notes', ['heading' => 'a', 'folder_id' => $u, 'workspace' => $ws]),
			'Notiz Pfad mit Anlage' => fn ($c) => $c->call('POST', '/notes', ['heading' => 'b', 'folder' => "$mName/U/Neu", 'workspace' => $ws]),
			'Notiz Pfad vorhanden' => fn ($c) => $c->call('POST', '/notes', ['heading' => 'c', 'folder' => "$mName/U", 'workspace' => $ws]),
			'Ordner parent_folder_id' => fn ($c) => $c->call('POST', '/folders', ['folder_name' => 'd', 'parent_folder_id' => $u, 'workspace' => $ws]),
			'Ordner parent_folder' => fn ($c) => $c->call('POST', '/folders', ['folder_name' => 'e', 'parent_folder' => $mName, 'workspace' => $ws]),
			'Ordner folder_path' => fn ($c) => $c->call('POST', '/folders', ['folder_path' => "$mName/U/f/g", 'create_parents' => true, 'workspace' => $ws]),
			'Tagebuch-Datumsordner' => fn ($c) => $c->call('POST', '/notes', ['heading' => gmdate('Y-m-d'), 'folder' => "$mName/" . gmdate('Y') . '/' . gmdate('m'), 'workspace' => $ws]),
			'Import' => fn ($c) => $c->uploadFiles(self::APP . 'api_import_dropped_notes.php', [['imp.md', "# Imp\n"]], ['workspace' => $ws, 'folder_id' => $u, 'file_count' => 1]),
			'Duplikat-Ziel' => fn ($c) => $c->call('POST', "/notes/{$x['sN']}/duplicate", ['folder_id' => $u, 'workspace' => $ws]),
		];
		foreach ($attempts as $what => $fn) {
			self::denied($fn($s), "S $what");
		}
		self::assertSame($folders, self::folderIds($wsId), 'S hat Ordner angelegt');
		self::assertSame($notes, self::noteIds($wsId), 'S hat Notizen angelegt');
		// S eigene Notiz per PATCH in einen Pfad unter M
		self::denied($s->call('PATCH', "/notes/{$x['sN']}", ['folder' => "$mName/Neu2"]), 'S PATCH Pfad');
		self::assertSame($folders, self::folderIds($wsId));
		foreach ($attempts as $what => $fn) {
			if ($what !== 'Duplikat-Ziel') {
				self::assertTrue($fn($l)->ok(), "L $what");
			}
		}
		// Duplikat-Ziel mit eigener Notiz der Lehrkraft
		$own = self::newNote($l, $ws, "L $mark", '<p>l</p>');
		self::ok($l->call('POST', "/notes/$own/duplicate", ['folder_id' => $u, 'workspace' => $ws]), 'L Duplikat-Ziel');
	}

	// Done 3: Verschieben hinein/hinaus, Ordner verwalten → S 403, L 200
	public function testMovesAndFolderManagement(): void {
		$x = $this->fixture();
		['l' => $l, 's' => $s, 'm' => $m, 'u' => $u, 'n1' => $n1, 'sN' => $sN, 'ws' => $ws, 'wsId' => $wsId] = $x;
		$y = self::folder($s, $ws, 'Y');
		$p = self::folder($l, $ws, 'P');
		$pm = self::folder($l, $ws, 'PM', $p);
		self::ok($l->call('PUT', "/folders/$pm/material", ['material' => true]), 'PM Material');
		$notes = array_map(static fn (int $id) => self::note($id), [$n1, $sN]);
		$fRows = array_map(static fn (int $id) => self::folderRow($id), [$m, $u, $y, $p, $pm]);

		self::denied($s->call('POST', "/notes/$sN/folder", ['folder_id' => $m]), 'S eigene Notiz hinein');
		self::denied($s->call('PATCH', "/notes/$sN", ['folder_id' => $u]), 'S PATCH folder_id hinein');
		self::denied($s->call('POST', "/notes/$n1/folder", ['folder_id' => $y]), 'S n1 hinaus');
		self::denied($s->call('POST', "/notes/$n1/remove-folder", []), 'S n1 zur Wurzel');
		self::denied($s->call('POST', "/folders/$y/move", ['new_parent_folder_id' => $m]), 'S Ordner hinein');
		self::denied($s->call('POST', "/folders/$y/move", ['new_parent_folder' => "M {$x['mark']}"]), 'S Ordner hinein per Pfad');
		self::denied($s->call('POST', "/folders/$u/move", ['new_parent_folder_id' => $y]), 'S M/U hinaus');
		foreach ([$m, $u] as $f) {
			self::denied($s->call('PATCH', "/folders/$f", ['name' => 'neu']), "S benennt $f");
			self::denied($s->call('PUT', "/folders/$f/icon", ['icon' => 'fa-star']), "S Icon $f");
			self::denied($s->call('PUT', "/folders/$f/color", ['color' => 'red']), "S Farbe $f");
			self::denied($s->call('PUT', "/folders/$f/pinned", ['pinned' => true]), "S Pin $f");
			self::denied($s->call('PUT', "/folders/$f/favorite", ['favorite' => true]), "S Favorit $f");
			self::denied($s->call('POST', "/folders/$f/archive", []), "S archiviert $f");
			self::denied($s->call('DELETE', "/folders/$f"), "S löscht $f");
		}
		// Zweig mit Material (P enthält PM): löschen/verschieben/archivieren nur Lehrkraft
		self::denied($s->call('DELETE', "/folders/$p"), 'S löscht P mit Material');
		self::denied($s->call('POST', "/folders/$p/move", ['new_parent_folder_id' => $y]), 'S verschiebt P mit Material');
		self::denied($s->call('POST', "/folders/$p/archive", []), 'S archiviert P mit Material');
		self::ok($s->call('PATCH', "/folders/$p", ['name' => 'P2']), 'S benennt P (kein Material)');
		self::ok($l->call('PATCH', "/folders/$p", ['name' => 'P']), 'zurück');
		self::assertSame($notes, array_map(static fn (int $id) => self::note($id), [$n1, $sN]), 'Notizen verändert');
		self::assertSame($fRows, array_map(static fn (int $id) => self::folderRow($id), [$m, $u, $y, $p, $pm]), 'Ordner verändert');

		// L darf alles
		self::ok($l->call('POST', "/notes/$sN/folder", ['folder_id' => $m]), 'L Notiz hinein');
		self::ok($l->call('POST', "/notes/$n1/folder", ['folder_id' => $y]), 'L n1 hinaus');
		self::ok($l->call('POST', "/folders/$y/move", ['new_parent_folder_id' => $m]), 'L Ordner hinein');
		self::ok($l->call('PATCH', "/folders/$u", ['name' => 'U2']), 'L benennt');
		self::ok($l->call('PUT', "/folders/$m/icon", ['icon' => 'fa-book']), 'L Icon');
		self::ok($l->call('POST', "/folders/$p/move", ['new_parent_folder_id' => $m]), 'L verschiebt P');
		self::ok($l->call('DELETE', "/folders/$u"), 'L löscht M/U');
		self::assertSame((string)$wsId, self::folderRow($m)['workspace_id']);
	}

	// Done 5b (H2): Cache im selben Prozess nach Verschieben eines Ordners unter/aus M
	public function testCacheAfterFolderMoveInProcess(): void {
		$x = $this->fixture();
		['l' => $l, 's' => $s, 'm' => $m, 'ws' => $ws] = $x;
		$xf = self::folder($l, $ws, 'X');
		$nx = self::newNote($s, $ws, 'In X', '<p>x</p>', $xf);
		$folders = Server::get(FolderService::class);
		$notes = Server::get(NoteService::class);
		$access = Server::get(AccessService::class);
		$access->resetCache();
		$notes->update($s->uid, $nx, ['content' => '<p>vorher</p>'], '', '');
		$folders->move($l->uid, $xf, ['new_parent_folder_id' => $m]);
		try {
			$notes->update($s->uid, $nx, ['content' => '<p>unter M</p>'], '', '');
			self::fail('S schreibt nach Verschieben unter M');
		} catch (ForbiddenException) {
		}
		$folders->move($l->uid, $xf, ['new_parent_folder_id' => 0]);
		$notes->update($s->uid, $nx, ['content' => '<p>wieder frei</p>'], '', '');
		self::assertStringContainsString('wieder frei', (string)self::note($nx)['content']);
	}

	// Done 6: Papierkorb-Markierung, Wiederherstellen/Löschen nur Lehrkraft, gefälschter Snapshot
	public function testTrashMarking(): void {
		$x = $this->fixture();
		['l' => $l, 's' => $s, 'm' => $m, 'n1' => $n1, 'tl' => $tl, 'ws' => $ws, 'wsId' => $wsId] = $x;
		$sTrash = self::newNote($s, $ws, 'S weg', '<p>w</p>');
		self::ok($s->call('DELETE', "/notes/$sTrash"), 'S löscht eigene');
		$snap = self::ok($l->call('DELETE', "/folders/$m" . self::q(['workspace' => $ws])), 'L löscht M')['restore_snapshot'];
		self::assertSame('1', self::note($n1)['trash_material']);
		self::assertSame('1', self::note($tl)['trash_material']);
		self::assertSame('0', self::note($sTrash)['trash_material']);
		self::assertSame(1, array_values(array_filter($snap['folders'], static fn ($f) => $f['id'] === $m))[0]['material']);

		self::denied($s->call('POST', "/notes/$n1/restore", []), 'S stellt wieder her');
		self::denied($s->call('DELETE', "/trash/$n1"), 'S löscht endgültig');
		self::ok($s->call('DELETE', '/trash' . self::q(['workspace' => $ws])), 'S leert Papierkorb');
		self::assertNotNull(self::note($n1), 'n1 bleibt beim Leeren durch S');
		self::assertNull(self::note($sTrash), 'eigene Notiz geleert');
		$forged = $snap;
		self::denied($s->call('POST', '/folders/restore', $forged), 'S restoreDeleted mit Material-Notizen');
		// Ordner ohne Material-Notizen, Material im Snapshot gefälscht → ohne Kennzeichen
		$fake = ['workspace' => $ws, 'folder_id' => 1, 'folders' => [['id' => 1, 'name' => 'Fake', 'parent_id' => null, 'material' => 1]], 'notes' => []];
		$fid = (int)self::ok($s->call('POST', '/folders/restore', $fake), 'S restore ohne Material')['folder_id'];
		self::assertSame('0', self::folderRow($fid)['material'], 'Client setzt kein Material');
		self::assertNotNull(self::note($n1)['trashed_at']);

		$r = self::ok($l->call('POST', '/folders/restore', $snap), 'L stellt wieder her');
		$newM = (int)$r['folder_id'];
		self::assertSame('1', self::folderRow($newM)['material'], 'Material aus Snapshot (Lehrkraft)');
		self::assertNull(self::note($n1)['trashed_at']);
		self::assertSame('0', self::note($n1)['trash_material']);
		self::denied($s->call('PATCH', "/notes/$n1", ['content' => '<p>x</p>']), 'S nach Wiederherstellen');
		self::assertContains($newM, self::folderIds($wsId));
		// L löscht Notiz einzeln (Ordner bleibt): Schutz über die Kette, Wiederherstellen durch L setzt 0
		self::ok($l->call('DELETE', "/notes/$n1"), 'L Notiz in Papierkorb');
		self::denied($s->call('POST', "/notes/$n1/restore", []), 'S restore (Kette)');
		// Review M6a-r1 Blocker: gefälschter Snapshot holt die einzeln gelöschte Notiz aus dem bestehenden M nicht heraus
		$uId = (string)self::note($n1)['folder_id'];
		$name = 'Raus' . bin2hex(random_bytes(3));
		$forgedNew = ['workspace' => $ws, 'folder_id' => 1, 'folders' => [['id' => 1, 'name' => $name, 'parent_id' => null]], 'notes' => [['id' => $n1, 'folder_id' => 1]]];
		self::denied($s->call('POST', '/folders/restore', $forgedNew), 'S restoreDeleted, neuer Ordner (Kette)');
		$own = self::folder($s, $ws, 'Eigen' . bin2hex(random_bytes(3)));
		$forgedOwn = ['workspace' => $ws, 'folder_id' => 1, 'folders' => [['id' => 1, 'name' => (string)self::folderRow($own)['name'], 'parent_id' => null]], 'notes' => [['id' => $n1, 'folder_id' => 1]]];
		self::denied($s->call('POST', '/folders/restore', $forgedOwn), 'S restoreDeleted, vorhandener eigener Ordner (Kette)');
		self::assertNotNull(self::note($n1)['trashed_at'], 'n1 bleibt im Papierkorb');
		self::assertSame($uId, self::note($n1)['folder_id'], 'n1 bleibt in M/U');
		$names = array_map(static fn (int $f) => (string)self::folderRow($f)['name'], self::folderIds($wsId));
		self::assertNotContains($name, $names, 'kein Ordner angelegt');
		self::ok($l->call('POST', "/notes/$n1/restore", []), 'L restore');
	}

	// Done 7: Verlassen des Teams entfernt das Kennzeichen; make_private entfernt alle
	public function testLeavingClearsMaterial(): void {
		$x = $this->fixture();
		['l' => $l, 'm' => $m, 'u' => $u, 'ws' => $ws] = $x;
		[$pk, $pId] = self::newWorkspace($l, 'Privat');
		$mine = self::folder($l, $ws, 'Mein');
		self::newNote($l, $ws, 'Eigene', '<p>e</p>', $mine);
		$gone = self::newNote($l, $ws, 'Weg', '<p>w</p>', $mine);
		self::ok($l->call('PUT', "/folders/$mine/material", ['material' => true]), 'Mein Material');
		self::ok($l->call('DELETE', "/notes/$gone"), 'L Notiz in Papierkorb');
		self::setColumn('nextnotes_notes', $gone, 'trash_material', 1);
		self::ok($l->call('POST', "/folders/$mine/move", ['target_workspace' => $pk]), 'L verschiebt eigenen Material-Zweig privat');
		self::assertSame((string)$pId, self::folderRow($mine)['workspace_id']);
		self::assertSame('0', self::folderRow($mine)['material'], 'Kennzeichen im privaten Workspace entfernt');
		self::assertSame((string)$pId, self::note($gone)['workspace_id']);
		self::assertSame('0', self::note($gone)['trash_material'], 'Papierkorb-Markierung beim Verlassen entfernt (Review M6a-r1 Hinweis 2)');
		self::assertSame('1', self::folderRow($m)['material']);

		// make_private eines verwaisten Workspace: alle Kennzeichen weg; gelöschter Unterordner von M → Notiz trash_material=1
		$sub = self::folder($l, $ws, 'Sub', $m);
		$subNote = self::newNote($l, $ws, 'In Sub', '<p>s</p>', $sub);
		self::ok($l->call('DELETE', "/folders/$sub" . self::q(['workspace' => $ws])), 'L löscht Unterordner von M');
		$this->teams->destroy($l->uid, $x['t'], true);
		self::setTeamState($x['t'], 'orphaned');
		self::assertSame('1', self::folderRow($m)['material']);
		self::assertSame('1', self::note($subNote)['trash_material']);
		Server::get(TeamLifecycleService::class)->makePrivate($l->uid, (string)$ws);
		self::assertSame('0', self::folderRow($m)['material']);
		self::assertSame('0', self::folderRow($u)['material']);
		self::assertSame('0', self::note($subNote)['trash_material'], 'make_private entfernt Papierkorb-Markierung (Review M6a-r1 Hinweis 2)');
		Teams::purgeTeamData($x['t']);
	}

	// Done 8: Lesepfade ohne Material-Abfrage; filterReadable ≡ noteLevel ≥ READ auch für Material
	public function testReadPathsUnchanged(): void {
		$x = $this->fixture();
		['s' => $s, 'n1' => $n1, 'sN' => $sN] = $x;
		$access = Server::get(AccessService::class);
		$access->resetCache();
		$access->requireNote($s->uid, $n1, 'read');
		$access->canRead($s->uid, $n1);
		$cache = new \ReflectionProperty(AccessService::class, 'material');
		self::assertSame([], $cache->getValue($access), 'Lesen fragt Material ab');
		$mapper = Server::get(\OCA\NextNotes\Db\NoteMapper::class);
		$notes = $mapper->findByIds([$n1, $sN, $x['tl']]);
		$readable = array_map(static fn ($n) => (int)$n->getId(), $access->filterReadable($s->uid, $notes));
		$byLevel = array_map(static fn ($n) => (int)$n->getId(), array_filter($notes, static fn ($n) => $access->noteLevel($s->uid, $n) >= AccessService::READ));
		sort($readable);
		$byLevel = array_values($byLevel);
		sort($byLevel);
		self::assertSame($byLevel, $readable);
		self::assertSame(AccessService::READ, $access->noteLevel($s->uid, $mapper->find($n1)));
		self::assertSame(AccessService::WRITE, $access->noteLevel($s->uid, $mapper->find($sN)));
		self::assertSame(AccessService::WRITE, $access->noteLevel($x['l']->uid, $mapper->find($n1)));
		self::assertNotSame([], $cache->getValue($access), 'Schreibprüfung nutzt den Cache');

		// Hauptansicht (Review M6a-r1 Hinweis 3): gleiche Zahl ausgeführter Abfragen mit und ohne Kennzeichen, Material-Cache bleibt leer
		$view = Server::get(IndexViewService::class);
		$inner = Server::get(IDBConnection::class)->getInner();
		$count = static function () use ($access, $view, $inner, $s, $n1, $x, $cache): int {
			$access->resetCache();
			$before = $inner->getStats()['executed'];
			$view->build($s->uid, '/index.php/apps/nextnotes/', ['note' => (string)$n1, 'workspace' => (string)$x['ws']]);
			$executed = $inner->getStats()['executed'] - $before;
			self::assertSame([], $cache->getValue($access), 'Hauptansicht fragt Material ab');
			return $executed;
		};
		$count();
		$with = $count();
		self::setColumn('nextnotes_folders', $x['m'], 'material', 0);
		$without = $count();
		self::assertGreaterThan(0, $with);
		self::assertSame($without, $with, 'Hauptansicht mit Material: zusätzliche Abfragen');
	}
}
