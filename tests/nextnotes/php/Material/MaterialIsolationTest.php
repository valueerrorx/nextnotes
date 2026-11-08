<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Material;

use OCA\NextNotes\Tests\Support\Teams;
use OCP\DB\QueryBuilder\IQueryBuilder;

// M6a Done 5, 5a, 5c, 9: Sammel-/Nebenschreiber (Plan M6 §2.1), Kompat-Routen und Duplikat Q1; je mit Positivkontrolle der Lehrkraft
final class MaterialIsolationTest extends MaterialTestCase {
	private const COMPAT = self::APP . 'workspaces.php';

	protected function tearDown(): void {
		exec('php /var/www/html/occ config:app:delete nextnotes attachment_quota_mb 2>&1');
		parent::tearDown();
	}

	/** @return string[] */
	private static function tags(int $note): array {
		$qb = self::db()->getQueryBuilder();
		$qb->select('tag')->from('nextnotes_note_tags')->where($qb->expr()->eq('note_id', $qb->createNamedParameter($note, IQueryBuilder::PARAM_INT)))->orderBy('tag');
		return $qb->executeQuery()->fetchFirstColumn();
	}

	/** @return array<int, array<string, mixed>> Anhänge je Notiz */
	private static function attachments(int $note): array {
		$qb = self::db()->getQueryBuilder();
		$qb->select('id', 'size', 'original_name')->from('nextnotes_attachments')->where($qb->expr()->eq('note_id', $qb->createNamedParameter($note, IQueryBuilder::PARAM_INT)));
		return $qb->executeQuery()->fetchAllAssociative();
	}

	// Done 5: Sammel- und Nebenschreiber lassen Material aus bzw. 403
	public function testCollectiveWritersSkipMaterial(): void {
		$x = $this->fixture();
		['l' => $l, 's' => $s, 'm' => $m, 'u' => $u, 'n1' => $n1, 'sN' => $sN, 'ws' => $ws, 'mark' => $mark] = $x;
		$tag = "tg$mark";
		self::ok($l->call('PUT', "/notes/$n1/tags", ['tags' => [$tag, 'speed-dial'], 'workspace' => $ws]), 'L Tags n1');
		self::ok($s->call('PUT', "/notes/$sN/tags", ['tags' => [$tag, 'speed-dial'], 'workspace' => $ws]), 'S Tags sN');
		$sN2 = self::newNote($s, $ws, "S2 $mark", '<p>2</p>');
		self::ok($s->call('PUT', "/notes/$sN2/tags", ['tags' => ['speed-dial'], 'workspace' => $ws]), 'S Tags sN2');

		// Tag umbenennen/löschen: nur eigene Notiz
		self::ok($s->call('PATCH', '/tags/' . self::enc($tag), ['new_name' => "neu$mark", 'workspace' => $ws]), 'S benennt Tag um');
		self::assertSame(["neu$mark", 'speed-dial'], self::tags($sN));
		self::assertSame(['speed-dial', $tag], self::tags($n1), 'n1 unverändert');
		self::ok($s->call('DELETE', '/tags/' . self::enc($tag) . self::q(['workspace' => $ws])), 'S löscht Tag (nur Material trägt ihn)');
		self::assertSame(['speed-dial', $tag], self::tags($n1), 'n1 unverändert nach Löschen');

		// Favoriten leeren
		self::ok($l->call('POST', "/notes/$n1/favorite", []), 'L Favorit n1');
		self::ok($l->call('PUT', "/folders/$m/favorite", ['favorite' => true]), 'L Favorit M');
		self::ok($s->call('POST', "/notes/$sN/favorite", []), 'S Favorit sN');
		$y = self::folder($s, $ws, 'Y');
		self::ok($s->call('PUT', "/folders/$y/favorite", ['favorite' => true]), 'S Favorit Y');
		self::ok($s->call('POST', '/notes/favorites/clear', ['workspace' => $ws]), 'S leert Favoriten');
		self::assertSame('0', self::note($sN)['favorite']);
		self::assertSame('0', self::folderRow($y)['favorite']);
		self::assertSame('1', self::note($n1)['favorite'], 'n1 behält Favorit');
		self::assertSame('1', self::folderRow($m)['favorite'], 'M behält Favorit');

		// Reihenfolge speed_dial: Material-Position fest; andere Bereiche mit n1 → 403
		self::setColumn('nextnotes_notes', $sN, 'speed_dial_order', 1);
		self::setColumn('nextnotes_notes', $n1, 'speed_dial_order', 2);
		self::setColumn('nextnotes_notes', $sN2, 'speed_dial_order', 3);
		self::ok($s->call('POST', '/notes/reorder', ['note_id' => $sN2, 'target_note_id' => $sN, 'position' => 'before', 'scope' => 'speed_dial', 'workspace' => $ws]), 'S speed_dial');
		self::assertSame('2', self::note($n1)['speed_dial_order'], 'n1 behält speed_dial_order');
		self::assertSame(['1', '3'], [self::note($sN2)['speed_dial_order'], self::note($sN)['speed_dial_order']]);
		foreach (['sidebar', 'dashboard', 'kanban'] as $scope) {
			self::denied($s->call('POST', '/notes/reorder', ['note_id' => $n1, 'target_note_id' => $sN, 'position' => 'before', 'scope' => $scope]), "S $scope n1 als Notiz");
			self::denied($s->call('POST', '/notes/reorder', ['note_id' => $sN, 'target_note_id' => $n1, 'position' => 'before', 'scope' => $scope]), "S $scope n1 als Ziel");
		}
		// Ordner-Reihenfolge an der Wurzel neben M: M behält display_order
		$z = self::folder($s, $ws, 'Z');
		self::setColumn('nextnotes_folders', $y, 'display_order', 1);
		self::setColumn('nextnotes_folders', $m, 'display_order', 2);
		self::setColumn('nextnotes_folders', $z, 'display_order', 3);
		self::ok($s->call('POST', '/folders/reorder', ['folder_id' => $z, 'target_folder_id' => $y, 'position' => 'before', 'workspace' => $ws]), 'S Ordner-Reihenfolge');
		self::assertSame('2', self::folderRow($m)['display_order'], 'M behält Position');
		self::denied($s->call('POST', '/folders/reorder', ['folder_id' => $z, 'target_folder_id' => $u, 'position' => 'before', 'workspace' => $ws]), 'S Ordner neben M/U (in M)');
		self::denied($s->call('POST', '/folders/reorder', ['folder_id' => $m, 'target_folder_id' => $y, 'position' => 'before', 'workspace' => $ws]), 'S verschiebt M per Reihenfolge');

		// Notiz in Ordner per Name, Ordner-Tags, Leeren, Dateien verschieben
		self::denied($s->call('POST', "/notes/$sN/folder", ['folder' => "M $mark"]), 'S folder per Name');
		self::assertNull(self::note($sN)['folder_id']);
		self::denied($s->call('POST', "/folders/$m/tags", ['tags' => ['x']]), 'S Ordner-Tags M');
		self::denied($s->call('DELETE', "/folders/$u/tags", ['tags' => [$tag]]), 'S Ordner-Tags entfernen M/U');
		self::denied($s->call('POST', "/folders/$u/empty", []), 'S leert M/U');
		self::denied($s->call('POST', '/folders/move-files', ['source_folder_id' => $u, 'target_folder_id' => $y, 'workspace' => $ws]), 'S move-files aus M/U');
		$sY = self::newNote($s, $ws, "SY $mark", '<p>y</p>', $y);
		self::denied($s->call('POST', '/folders/move-files', ['source_folder_id' => $y, 'target_folder_id' => $u, 'workspace' => $ws]), 'S move-files nach M/U');
		self::assertSame((string)$y, self::note($sY)['folder_id']);
		// Ordner-Tags mit Unterordnern: Material-Unterordner ausgelassen
		$p = self::folder($s, $ws, 'P');
		$pm = self::folder($l, $ws, 'PM', $p);
		$pmN = self::newNote($l, $ws, "PMN $mark", '<p>p</p>', $pm);
		self::ok($l->call('PUT', "/folders/$pm/material", ['material' => true]), 'PM Material');
		$pN = self::newNote($s, $ws, "PN $mark", '<p>p</p>', $p);
		self::ok($s->call('POST', "/folders/$p/tags", ['tags' => ['zweig'], 'include_subfolders' => true]), 'S Ordner-Tags P mit Unterordnern');
		self::assertSame(['zweig'], self::tags($pN));
		self::assertSame([], self::tags($pmN), 'Material-Unterordner ausgelassen');

		// Verknüpfungen im Material auf eigene Notiz: Umbenennen/Löschen durch S ändert sie nicht
		$link = (int)self::ok($l->call('POST', '/notes', ['heading' => 'Link', 'type' => 'linked', 'linked_note_id' => $sN2, 'folder_id' => $m, 'workspace' => $ws]), 'L Verknüpfung in M')['note']['id'];
		$linkBefore = self::note($link);
		self::ok($s->call('PATCH', "/notes/$sN2", ['heading' => "S2 neu $mark"]), 'S benennt Ziel um');
		self::ok($s->call('DELETE', "/notes/$sN2"), 'S löscht Ziel');
		self::assertSame($linkBefore, self::note($link), 'Verknüpfung im Material verändert');

		// Positivkontrolle L
		self::ok($l->call('PATCH', '/tags/' . self::enc($tag), ['new_name' => "lneu$mark", 'workspace' => $ws]), 'L benennt Tag um');
		self::assertSame(["lneu$mark", 'speed-dial'], self::tags($n1));
		self::ok($l->call('POST', '/notes/favorites/clear', ['workspace' => $ws]), 'L leert Favoriten');
		self::assertSame('0', self::note($n1)['favorite']);
		self::assertSame('0', self::folderRow($m)['favorite']);
		self::ok($l->call('POST', "/folders/$u/tags", ['tags' => ['l']]), 'L Ordner-Tags');
		self::ok($l->call('POST', '/folders/move-files', ['source_folder_id' => $y, 'target_folder_id' => $u, 'workspace' => $ws]), 'L move-files nach M/U');
		self::ok($l->call('POST', "/notes/$sN/folder", ['folder' => "M $mark"]), 'L folder per Name');
	}

	// Done 5a: Kompatibilitätsrouten
	public function testCompatRoutes(): void {
		$x = $this->fixture();
		['l' => $l, 's' => $s, 'm' => $m, 'ex' => $ex, 'ws' => $ws, 'wsId' => $wsId, 't' => $t] = $x;
		[$pk] = self::newWorkspace($s, 'SP');
		$exBefore = self::note($ex);
		self::denied(self::excalidraw($s, $ex, 'gekapert'), 'S api_save_excalidraw.php');
		self::assertSame($exBefore, self::note($ex));
		$notes = self::noteIds($wsId);
		self::denied($s->uploadFiles(self::APP . 'api_import_dropped_notes.php', [['imp.md', "# Imp\n"]], ['workspace' => $ws, 'folder_id' => $m, 'file_count' => 1]), 'S Import nach M');
		self::denied($s->form(self::COMPAT, ['action' => 'move_notes', 'name' => $ws, 'target' => $pk]), 'S move_notes');
		self::assertSame($notes, self::noteIds($wsId), 'S hat Notizen bewegt/angelegt');
		self::ok($s->form(self::COMPAT, ['action' => 'reorder', 'names' => [$pk, $ws]]), 'S reorder');
		self::assertSame('1', self::folderRow($m)['material']);

		self::ok(self::excalidraw($l, $ex, 'Lehrkraft'), 'L api_save_excalidraw.php');
		self::ok($l->uploadFiles(self::APP . 'api_import_dropped_notes.php', [['imp.md', "# Imp\n"]], ['workspace' => $ws, 'folder_id' => $m, 'file_count' => 1]), 'L Import nach M');
		[$ws2, $ws2Id] = self::teamWs($l, $t, 'Mat2');
		self::ok($l->form(self::COMPAT, ['action' => 'move_notes', 'name' => $ws, 'target' => $ws2]), 'L move_notes im Team');
		self::assertSame((string)$ws2Id, self::folderRow($m)['workspace_id']);
		self::assertSame('1', self::folderRow($m)['material'], 'Material bleibt im selben Team');
		self::denied($s->call('PATCH', "/notes/{$x['n1']}", ['content' => '<p>x</p>']), 'S nach Umzug');
	}

	// Done 5c: Duplikat einer Material-Notiz durch S → Kopie im privaten Workspace (Q1); Review r2 Hinweis 2 (Verknüpfung)
	public function testDuplicateToPrivate(): void {
		$x = $this->fixture();
		['l' => $l, 's' => $s, 'm' => $m, 'u' => $u, 'n1' => $n1, 'ws' => $ws, 'mark' => $mark] = $x;
		$srcBefore = self::note($n1);
		[$sk2, $s2Id] = self::newWorkspace($s, 'Zweit');
		// Schüler-Quote voll → 413, nichts kopiert (vor allen Kopien: Belegung bekannt)
		exec('php /var/www/html/occ config:app:set nextnotes attachment_quota_mb --value=1 --type=integer 2>&1');
		sleep(4);
		$filler = self::newNote($s, $sk2, 'Füller', '<p>f</p>');
		self::ok($s->upload("/notes/$filler/attachments", 'f.bin', str_repeat('f', 1048576 - 30)), 'Füller');
		$before = self::noteIds($s2Id);
		self::denied($s->call('POST', "/notes/$n1/duplicate", []), 'S Quote voll', 413);
		self::denied($s->call('POST', "/notes/$n1/duplicate", ['workspace' => $sk2]), 'S Quote voll (zweiter)', 413);
		self::assertSame($before, self::noteIds($s2Id));
		exec('php /var/www/html/occ config:app:delete nextnotes attachment_quota_mb 2>&1');
		sleep(4);

		$r = self::ok($s->call('POST', "/notes/$n1/duplicate", []), 'S dupliziert n1');
		$copy = (int)$r['id'];
		$copyRow = self::note($copy);
		$defaultId = self::ownWsId($s->uid, (string)$r['workspace']);
		self::assertNotSame($s2Id, $defaultId);
		self::assertSame((string)$defaultId, $copyRow['workspace_id'], 'Kopie im Standard-Workspace');
		self::assertNull($copyRow['folder_id'], 'Wurzel');
		self::assertSame("N1 $mark", $copyRow['heading']);
		self::assertIsString($r['workspace']);
		self::assertNotSame($ws, $r['workspace']);
		self::assertCount(1, self::attachments($copy), 'Anhang kopiert');
		self::assertNotSame(self::attachments($n1)[0]['id'], self::attachments($copy)[0]['id']);
		self::assertStringNotContainsString("/notes/$n1/", (string)$copyRow['content']);
		self::assertSame($srcBefore, self::note($n1), 'Quelle unverändert');
		self::ok($s->call('PATCH', "/notes/$copy", ['content' => '<p>meins</p>']), 'S schreibt Kopie');
		$q = self::db()->getQueryBuilder();
		$q->select('created_by')->from('nextnotes_notes')->where($q->expr()->eq('id', $q->createNamedParameter($copy)));
		self::assertSame($s->uid, $q->executeQuery()->fetchOne(), 'Kopie gehört S');
		self::assertSame("N1 $mark (1)", self::note((int)self::ok($s->call('POST', "/notes/$n1/duplicate", []), 'zweites Duplikat')['id'])['heading']);

		// workspace-Parameter: Team/fremd/Aufgaben → ignoriert; eigener zweiter privater Workspace → dort
		$other = $this->createUser('mo');
		[$foreign] = self::newWorkspace($other, 'Fremd');
		$asg = $this->rawWorkspace('user', $s->uid, $s->uid, 'assignments');
		foreach (['Team' => $ws, 'fremd' => $foreign, 'unbekannt' => 'Gibtsnicht'] as $label => $key) {
			$id = (int)self::ok($s->call('POST', "/notes/$n1/duplicate", ['workspace' => $key]), "Parameter $label")['id'];
			self::assertSame((string)$defaultId, self::note($id)['workspace_id'], "Parameter $label ignoriert");
		}
		$q = self::db()->getQueryBuilder();
		$q->select('name')->from('nextnotes_workspaces')->where($q->expr()->eq('id', $q->createNamedParameter($asg)));
		$id = (int)self::ok($s->call('POST', "/notes/$n1/duplicate", ['workspace' => (string)$q->executeQuery()->fetchOne()]), 'Parameter Aufgaben')['id'];
		self::assertSame((string)$defaultId, self::note($id)['workspace_id'], 'kind=assignments ignoriert');
		$id = (int)self::ok($s->call('POST', "/notes/$n1/duplicate", ['workspace' => $sk2]), 'eigener zweiter')['id'];
		self::assertSame((string)$s2Id, self::note($id)['workspace_id']);

		// Verknüpfung in M: Ziel Material → Kopie des Ziels; Ziel außerhalb Material (fremde Team-Notiz) → 403 (Hinweis 2)
		$lRoot = self::newNote($l, $ws, "LRoot $mark", '<p>root</p>');
		$toMat = (int)self::ok($l->call('POST', '/notes', ['heading' => 'L1', 'type' => 'linked', 'linked_note_id' => $n1, 'folder_id' => $m, 'workspace' => $ws]), 'Verknüpfung → n1')['note']['id'];
		$toRoot = (int)self::ok($l->call('POST', '/notes', ['heading' => 'L2', 'type' => 'linked', 'linked_note_id' => $lRoot, 'folder_id' => $m, 'workspace' => $ws]), 'Verknüpfung → Wurzel')['note']['id'];
		$viaLink = (int)self::ok($s->call('POST', "/notes/$toMat/duplicate", []), 'S dupliziert Verknüpfung auf Material')['id'];
		self::assertSame('note', self::note($viaLink)['type']);
		self::assertStringContainsString("n1 $mark", (string)self::note($viaLink)['content']);
		$count = count(self::noteIds($defaultId));
		self::denied($s->call('POST', "/notes/$toRoot/duplicate", []), 'S Verknüpfung auf Nicht-Material');
		self::assertSame($count, count(self::noteIds($defaultId)), 'nichts kopiert');

		// Nicht-Material von L in den privaten Workspace → 403 (Verlassen-Regel); Ordner M duplizieren → 403
		$pf = self::folder($s, $sk2, 'Privat');
		self::denied($s->call('POST', "/notes/$lRoot/duplicate", ['folder_id' => $pf]), 'S Nicht-Material privat');
		self::denied($s->call('POST', "/folders/$m/duplicate", []), 'S dupliziert Ordner M');
		self::denied($s->call('POST', "/notes/$n1/duplicate", ['folder_id' => $u]), 'S Duplikat in denselben Material-Ordner');

		// L dupliziert n1 → Kopie in M/U (Material bleibt Material)
		$lc = (int)self::ok($l->call('POST', "/notes/$n1/duplicate", []), 'L dupliziert n1')['id'];
		self::assertSame((string)$u, self::note($lc)['folder_id']);
		self::denied($s->call('PATCH', "/notes/$lc", ['content' => '<p>x</p>']), 'L-Kopie ist Material');
		Teams::reset();
	}

	// M6h: Aufgaben-Pfade (Q2) – Mitglied S legt aus Material keine Aufgabe an; Kopie/Vorlage/Abgabe-Wege ändern Material nie; Positivkontrolle L
	public function testAssignmentPathsKeepMaterial(): void {
		$x = $this->fixture();
		['l' => $l, 's' => $s, 'm' => $m, 'n1' => $n1, 'ws' => $ws, 't' => $t, 'att' => $att] = $x;
		$matBefore = [self::note($n1), self::folderRow($m), self::attachments($n1), self::tags($n1)];
		$body = ['team' => $t, 'title' => 'Aus Material', 'due_at' => time() + 7200, 'source_note_id' => $n1];
		self::assertContains($s->call('POST', '/assignments', $body)->status, [403, 404], 'S legt aus Material keine Aufgabe an');
		$r = $l->call('POST', '/assignments', $body);
		self::assertSame(201, $r->status, 'L legt Aufgabe aus Material an: ' . $r->describe());
		$aid = (int)$r->json()['assignment']['id'];
		$tpl = (int)$r->json()['assignment']['template_note_id'];
		try {
			self::assertContains($s->call('POST', "/assignments/$aid/distribute")->status, [403, 404], 'S teilt nicht aus');
			self::ok($l->call('POST', "/assignments/$aid/distribute"), 'L teilt aus');
			$qb = self::db()->getQueryBuilder();
			$qb->select('id', 'note_id')->from('nextnotes_submissions')
				->where($qb->expr()->eq('assignment_id', $qb->createNamedParameter($aid, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('student_uid', $qb->createNamedParameter($s->uid)));
			$sub = $qb->executeQuery()->fetchAssociative();
			self::assertIsArray($sub, 'Abgabe von S angelegt');
			$copy = (int)$sub['note_id'];
			self::assertNotSame($n1, $copy);
			// S schreibt die eigene Kopie, nicht die Vorlage, nicht das Material; Kopie nie in den Material-Ordner
			self::ok($s->call('PATCH', "/notes/$copy", ['content' => '<p>Lösung</p>']), 'S schreibt Kopie');
			self::assertContains($s->call('PATCH', "/notes/$copy", ['folder_id' => $m])->status, [400, 403, 404, 409], 'S verschiebt Kopie nach M');
			self::assertContains($s->call('POST', "/notes/$copy/folder", ['folder' => (string)self::folderRow($m)['name'], 'workspace' => $ws])->status, [400, 403, 404, 409], 'S Kopie per Ordnername nach M');
			self::assertContains($s->call('GET', "/notes/$tpl")->status, [403, 404], 'S liest Vorlage nicht');
			self::assertContains($s->call('PATCH', "/notes/$tpl", ['content' => '<p>x</p>'])->status, [403, 404], 'S schreibt Vorlage nicht');
			self::assertContains($s->call('PATCH', "/assignments/$aid", ['title' => 'gekapert'])->status, [403, 404], 'S ändert Aufgabe nicht');
			self::assertContains($s->call('DELETE', "/assignments/$aid?confirm=1")->status, [403, 404], 'S löscht Aufgabe nicht');
			self::denied($s->call('PATCH', "/notes/$n1", ['content' => '<p>x</p>']), 'S schreibt Material weiter nicht');
			self::ok($s->call('POST', "/submissions/{$sub['id']}/submit"), 'S gibt ab');
			self::assertSame($matBefore, [self::note($n1), self::folderRow($m), self::attachments($n1), self::tags($n1)], 'Material unverändert');
			// Positivkontrolle: L schreibt Material weiter
			self::ok($l->call('PATCH', "/notes/$n1", ['content' => '<p>L neu</p>']), 'L schreibt Material');
		} finally {
			\OCP\Server::get(\OCA\NextNotes\Service\AssignmentDeletion::class)->purge($aid);
		}
	}
}
