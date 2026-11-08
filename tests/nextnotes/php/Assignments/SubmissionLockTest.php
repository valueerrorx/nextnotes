<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Assignments;

use OCA\NextNotes\Tests\Support\ApiClient;
use OCA\NextNotes\Tests\Support\Response;

// Done M6e1 3, 5 (SPEC §9.3, Plan M6 §2.4): Schreibwege der Kopie nur mit studentCanEdit; Systeminhalte nie verschieben/löschen;
// Neuprüfung unter Notiz-Zeilensperre (Speichern gegen submit, Vorlage gegen Austeilen; Review M6-Plan r2 H1, M6d-r1 H3)
final class SubmissionLockTest extends AssignmentTestCase {
	private const APP_PATH = '/index.php/apps/nextnotes/';

	public function testWritePathsBeforeAndAfterSubmit(): void {
		$fx = $this->fixture(false);
		['l' => $l, 's1' => $s1, 't' => $t] = $fx;
		// zweite Aufgabe aus einer Tasklist, damit die Task-Routen greifen
		$tl = (int)self::ok($l->call('POST', '/notes', ['heading' => 'Liste', 'type' => 'tasklist', 'content' => '[]', 'workspace' => $fx['ws']]), 'Tasklist')['note']['id'];
		$tlAid = (int)$this->createAssignment($l, $t, $tl, 'Liste abarbeiten')['id'];
		self::ok($l->call('POST', "/assignments/{$fx['aid']}/distribute"), 'Austeilen');
		self::ok($l->call('POST', "/assignments/$tlAid/distribute"), 'Austeilen Liste');
		$sub = self::submissionsOf($fx['aid'])[$s1->uid];
		$note = (int)$sub['note_id'];
		$sid = (int)$sub['id'];
		$tlSub = self::submissionsOf($tlAid)[$s1->uid];
		$tlNote = (int)$tlSub['note_id'];

		// vor Abgabe: alle Schreibwege 200
		$errors = [];
		foreach (self::writes($s1, $note, $tlNote, 'vorher') as $what => $r) {
			if ($r->status >= 300) {
				$errors[] = "$what: $r->status " . substr($r->body, 0, 160);
			}
		}
		self::assertSame([], $errors, 'vor Abgabe');
		$task = self::ok($s1->call('GET', "/notes/$tlNote/tasks"), 'Tasks')['tasks'][0]['id'] ?? null;
		self::assertNotNull($task, 'Task angelegt');

		// Systeminhalt: Kopie, Aufgaben-Ordner, Schüler-Workspace weder verschieben noch löschen (S und L)
		$wsKey = self::ok($s1->call('GET', '/notes/' . $note), 'Notiz')['note']['workspace'] ?? null;
		foreach ([$s1, $l] as $c) {
			foreach ([
				'Notiz löschen' => $c->call('DELETE', "/notes/$note"),
				'Notiz endgültig' => $c->call('DELETE', "/notes/$note?permanent=1"),
				'Notiz in Ordner' => $c->call('POST', "/notes/$note/folder", ['folder_id' => null]),
				'Notiz archivieren' => $c->call('POST', "/notes/$note/archive"),
				'Aufgaben-Ordner umbenennen' => $c->call('PATCH', '/folders/' . self::row('nextnotes_notes', $note)['folder_id'], ['name' => 'x']),
				'Aufgaben-Ordner löschen' => $c->call('DELETE', '/folders/' . self::row('nextnotes_notes', $note)['folder_id']),
			] as $what => $r) {
				if (!in_array($r->status, [400, 403, 404], true)) {
					$errors[] = "$c->uid $what: $r->status";
				}
			}
		}
		if (is_string($wsKey)) {
			foreach (['umbenennen' => $s1->call('PATCH', '/workspaces/' . rawurlencode($wsKey), ['name' => 'x']),
				'löschen' => $s1->call('DELETE', '/workspaces/' . rawurlencode($wsKey)),
				'Papierkorb leeren' => $s1->call('DELETE', '/trash?workspace=' . rawurlencode($wsKey))] as $what => $r) {
				if ($r->status < 400 && $what !== 'Papierkorb leeren') {
					$errors[] = "Schüler-Workspace $what: $r->status";
				}
			}
		}
		self::assertSame([], $errors, 'Systeminhalte');
		self::assertNotNull(self::row('nextnotes_notes', $note), 'Kopie unberührt');
		self::assertNull(self::row('nextnotes_notes', $note)['trashed_at'], 'nicht im Papierkorb');

		// nach Abgabe: alle Schreibwege 403 submission_locked, Inhalt unverändert
		self::ok($s1->call('POST', "/submissions/$sid/submit"), 'abgeben');
		self::ok($s1->call('POST', '/submissions/' . (int)$tlSub['id'] . '/submit'), 'Liste abgeben');
		$content = self::row('nextnotes_notes', $note)['content'];
		$writes = self::writes($s1, $note, $tlNote, 'nachher');
		$writes['Task abhaken'] = $s1->call('PATCH', "/notes/$tlNote/tasks/$task", ['completed' => true]);
		foreach ($writes as $what => $r) {
			if ($r->status !== 403) {
				$errors[] = "$what: $r->status";
			} elseif (str_starts_with($what, 'API') && ($r->json()['code'] ?? null) !== 'submission_locked') {
				$errors[] = "$what: Code " . ($r->json()['code'] ?? '-');
			}
		}
		self::assertSame([], $errors, 'nach Abgabe');
		self::assertSame($content, self::row('nextnotes_notes', $note)['content'], 'Inhalt unverändert');
		self::ok($s1->call('GET', "/notes/$note"), 'S1 liest weiter');
	}

	// Speichern und Abgeben gleichzeitig: PATCH hat die Rechte vor dem submit geprüft, wartet an der Notizzeile → nach submit 403, kein Schreiben
	public function testSaveRacingSubmit(): void {
		$fx = $this->distributedFixture();
		$sub = $fx['subs'][$fx['s1']->uid];
		$note = (int)$sub['note_id'];
		$before = self::row('nextnotes_notes', $note)['content'];
		foreach (['PATCH' => ['PATCH', "/notes/$note", ['content' => '<p>spät</p>']], 'beacon' => ['POST', "/notes/$note/beacon", ['content' => '<p>spät</p>']]] as $what => [$m, $p, $b]) {
			self::setColumn('nextnotes_submissions', (int)$sub['id'], 'status', 'assigned');
			$r = self::raceOnNote($fx['s1'], $m, $p, $b, $note, static fn () => self::setColumn('nextnotes_submissions', (int)$sub['id'], 'status', 'submitted'));
			self::assertSame([403, 'submission_locked'], [$r->status, $r->json()['code'] ?? null], "$what nach submit: " . $r->describe());
			self::assertSame($before, self::row('nextnotes_notes', $note)['content'], "$what schreibt nicht nach submitted");
		}
	}

	// Vorlage gegen Austeilen (M6d-r1 H3): beacon, Tags, Anhang prüfen den Status unter der Notizsperre neu
	public function testTemplateWriteRacingDistribute(): void {
		$fx = $this->fixture(false);
		['l' => $l, 'aid' => $aid, 'tpl' => $tpl] = $fx;
		$before = self::row('nextnotes_notes', $tpl)['content'];
		foreach ([
			'beacon' => ['POST', "/notes/$tpl/beacon", ['content' => '<p>umgeschrieben</p>']],
			'PATCH' => ['PATCH', "/notes/$tpl", ['content' => '<p>umgeschrieben</p>']],
			'Tags' => ['PUT', "/notes/$tpl/tags", ['tags' => 'neu']],
		] as $what => [$m, $p, $b]) {
			self::setColumn('nextnotes_assignments', $aid, 'status', 'draft');
			$r = self::raceOnNote($l, $m, $p, $b, $tpl, static fn () => self::setColumn('nextnotes_assignments', $aid, 'status', 'distributed'));
			self::assertSame(403, $r->status, "$what nach Austeilen: " . $r->describe());
			self::assertSame($before, self::row('nextnotes_notes', $tpl)['content'], "$what ändert die Vorlage nicht");
		}
		self::assertSame(0, self::countRows('nextnotes_note_tags', 'note_id', $tpl), 'keine Tags an der Vorlage');
	}

	// Review M6e1-r1 Blocker: Abgabe nie per Sortieren/PATCH in einen anderen Ordner; reine Reihenfolge nur mit Schreibrecht
	public function testSubmissionNeverChangesFolder(): void {
		$fx = $this->fixture(false);
		['l' => $l, 's1' => $s1, 't' => $t] = $fx;
		$src2 = self::newNote($l, $fx['ws'], 'Quelle B ' . $fx['mark'], '<p>b</p>');
		$bid = (int)$this->createAssignment($l, $t, $src2, 'Aufgabe B')['id'];
		self::ok($l->call('POST', "/assignments/{$fx['aid']}/distribute"), 'Austeilen A');
		self::ok($l->call('POST', "/assignments/$bid/distribute"), 'Austeilen B');
		$subA = self::submissionsOf($fx['aid'])[$s1->uid];
		$a = (int)$subA['note_id'];
		$b = (int)self::submissionsOf($bid)[$s1->uid]['note_id'];
		$fa = self::row('nextnotes_notes', $a)['folder_id'];
		$fb = (int)self::row('nextnotes_notes', $b)['folder_id'];
		self::assertNotSame((int)$fa, $fb, 'zwei Aufgaben-Ordner');
		$errors = [];
		foreach ([
			'Sortieren in Ordner B' => $s1->call('POST', '/notes/reorder', ['note_id' => $a, 'target_note_id' => $b, 'position' => 'before', 'scope' => 'sidebar']),
			'PATCH folder_id B' => $s1->call('PATCH', "/notes/$a", ['folder_id' => $fb]),
			'PATCH folder_id 0' => $s1->call('PATCH', "/notes/$a", ['folder_id' => 0]),
			'PATCH Pfad leer' => $s1->call('PATCH', "/notes/$a", ['folder' => '']),
		] as $what => $r) {
			// PATCH lehnt Ortsfelder für Systeminhalte schon vorab ab (400, M6d)
			if ($r->status !== (str_starts_with($what, 'PATCH') ? 400 : 403)) {
				$errors[] = "$what: $r->status " . substr($r->body, 0, 120);
			}
		}
		self::assertSame([], $errors);
		self::assertSame($fa, self::row('nextnotes_notes', $a)['folder_id'], 'A bleibt im eigenen Ordner');

		// gleicher Ordner (B per DB daneben): Systeminhalte stehen nicht in der Seitenleisten-Reihenfolge (404), nach Abgabe 403 submission_locked
		self::setColumn('nextnotes_notes', $b, 'folder_id', (int)$fa);
		self::assertSame(404, $s1->call('POST', '/notes/reorder', ['note_id' => $a, 'target_note_id' => $b, 'position' => 'after', 'scope' => 'sidebar'])->status, 'Reihenfolge im Ordner');
		self::ok($s1->call('POST', '/submissions/' . (int)$subA['id'] . '/submit'), 'A abgeben');
		$r = $s1->call('POST', '/notes/reorder', ['note_id' => $a, 'target_note_id' => $b, 'position' => 'before', 'scope' => 'sidebar']);
		self::assertSame([403, 'submission_locked'], [$r->status, $r->json()['code'] ?? null], 'Reihenfolge nach Abgabe');
		self::assertSame($fa, self::row('nextnotes_notes', $a)['folder_id'], 'A unverändert');
	}

	/** @return array<string, Response> alle Schreibwege einer Kopie nacheinander (API … = Fehlercode submission_locked erwartet) */
	private static function writes(ApiClient $c, int $note, int $tlNote, string $mark): array {
		$atts = $c->call('GET', "/notes/$note/attachments")->json()['attachments'] ?? [];
		$out = ['API PATCH' => $c->call('PATCH', "/notes/$note", ['content' => "<p>$mark</p>"])];
		$snap = $c->call('GET', "/notes/$note/snapshots")->json()['snapshots'][0]['snapshot_key'] ?? 'x';
		$out += [
			'API beacon' => $c->call('POST', "/notes/$note/beacon", ['content' => "<p>$mark beacon</p>"]),
			'API Tags' => $c->call('PUT', "/notes/$note/tags", ['tags' => $mark]),
			'API Icon' => $c->call('PUT', "/notes/$note/icon", ['icon' => 'fa-star']),
			'API Farbe' => $c->call('PUT', "/notes/$note/color", ['color' => 'red']),
			'API Breite' => $c->call('PUT', "/notes/$note/content-width", ['content_width' => 80]),
			'API Anhang hochladen' => $c->upload("/notes/$note/attachments", "$mark.txt", $mark),
			'API Task anlegen' => $c->call('POST', "/notes/$tlNote/tasks", ['text' => "Task $mark"]),
			'API Snapshot-Restore' => $c->call('POST', "/notes/$note/snapshot/restore", ['snapshot_key' => $snap]),
			'API Konvertieren' => $c->call('POST', "/notes/$note/convert", ['target' => 'markdown']),
		];
		if ($mark === 'vorher') {
			// zurück zu HTML, damit „nachher“ dieselben Wege trifft
			$out['API Konvertieren zurück'] = $c->call('POST', "/notes/$note/convert", ['target' => 'html']);
		} elseif ($atts !== []) {
			$out['API Anhang löschen'] = $c->call('DELETE', "/notes/$note/attachments/" . $atts[0]['id']);
		}
		$out['Excalidraw'] = $c->form(self::APP_PATH . 'api_save_excalidraw.php', ['note_id' => $note,
			'diagram_data' => json_encode(['type' => 'excalidraw', 'elements' => [], 'appState' => new \stdClass(), 'files' => new \stdClass()])], true);
		return $out;
	}
}
