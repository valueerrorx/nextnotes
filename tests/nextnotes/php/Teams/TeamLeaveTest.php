<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Teams;

use OCA\NextNotes\Tests\Support\ApiClient;
use OCA\NextNotes\Tests\Support\Response;
use OCA\NextNotes\Tests\Support\TeamTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Plan M5a Done 5 (BLOCKER 4, O5) und §2.8 (r2 BLOCKER 2): Inhalte verlassen einen Team-Workspace nur in einen Workspace desselben Teams;
 * Ausnahme eigene Notiz (created_by) in den eigenen privaten Workspace (Ordner/Workspace nur mit verwalten). Systemverwaltetes nie.
 */
final class TeamLeaveTest extends TeamTestCase {
	private const COMPAT = '/index.php/apps/nextnotes/workspaces.php';

	private ApiClient $a;
	private ApiClient $b;
	private string $w1;
	private string $w2;
	private string $w3;
	private string $pa;
	private string $pb;
	private int $w2Id;
	private int $paId;

	// A Owner von T und T2, B Mitglied (Level 1) von T; W1, W2 in T, W3 in T2; PA, PB privat
	private function fixture(): void {
		$this->a = $this->createUser('la');
		$this->b = $this->createUser('lb');
		$t = $this->teams->create($this->a->uid, 'Verlassen ' . bin2hex(random_bytes(3)));
		$t2 = $this->teams->create($this->a->uid, 'Anderes ' . bin2hex(random_bytes(3)));
		$this->teams->addUser($t, $this->a->uid, $this->b->uid);
		[$this->w1] = self::teamWs($this->a, $t, 'W1');
		[$this->w2, $this->w2Id] = self::teamWs($this->a, $t, 'W2');
		[$this->w3] = self::teamWs($this->a, $t2, 'W3');
		[$this->pa, $this->paId] = self::newWorkspace($this->a, 'PA');
		[$this->pb] = self::newWorkspace($this->b, 'PB');
	}

	private function note(ApiClient $author, string $ws, ?int $folder = null): int {
		return self::newNote($author, $ws, 'N ' . bin2hex(random_bytes(4)), '<p>n</p>', $folder);
	}

	private function folder(ApiClient $c, string $ws): int {
		return (int)self::ok($c->call('POST', '/folders', ['folder_name' => 'F ' . bin2hex(random_bytes(3)), 'workspace' => $ws]), 'Ordner')['folder_id'];
	}

	private static function wsIdOf(int $note): string {
		return (string)self::noteRow($note)['workspace_id'];
	}

	private static function denied(Response $r, string $what, array $codes = [403]): void {
		self::assertContains($r->status, $codes, "$what: erwartet " . implode('/', $codes) . ', erhalten ' . $r->describe());
	}

	/**
	 * Matrix je Notiz-Weg: $move(Akteur, Notiz, Ziel-Schlüssel) → Antwort
	 * @param callable(ApiClient, int, string): Response $move
	 */
	private function noteMatrix(string $way, callable $move, ?callable $prepare = null): void {
		[$a, $b] = [$this->a, $this->b];
		$cases = [
			['A verschiebt B-Notiz in eigenen privaten', $a, $b, $this->pa, false],
			['A verschiebt B-Notiz in W2 desselben Teams', $a, $b, $this->w2, true],
			['A verschiebt B-Notiz in W3 eines anderen Teams', $a, $b, $this->w3, false],
			['B verschiebt eigene Notiz in eigenen privaten', $b, $b, $this->pb, true],
			['B verschiebt A-Notiz in eigenen privaten', $b, $a, $this->pb, false],
			['A verschiebt eigene Notiz in eigenen privaten', $a, $a, $this->pa, true],
		];
		foreach ($cases as [$label, $actor, $author, $target, $allowed]) {
			$n = $this->note($author, $this->w1);
			$ctx = $prepare !== null ? $prepare($n) : null;
			$before = self::noteRow($n);
			$r = $move($actor, $n, $target, $ctx);
			if ($allowed) {
				self::ok($r, "$way: $label");
				self::assertNotSame($before['workspace_id'], self::wsIdOf($n), "$way: $label – Workspace gewechselt");
			} else {
				self::denied($r, "$way: $label");
				self::assertSame($before, self::noteRow($n), "$way: $label – Notiz unverändert");
			}
		}
	}

	public function testPatchWorkspace(): void {
		$this->fixture();
		$this->noteMatrix('PATCH workspace', static fn (ApiClient $c, int $n, string $ws) => $c->call('PATCH', "/notes/$n", ['workspace' => $ws]));
	}

	public function testMoveToFolder(): void {
		$this->fixture();
		$this->noteMatrix('moveToFolder', static fn (ApiClient $c, int $n, string $ws) => $c->call('POST', "/notes/$n/folder", ['workspace' => $ws]));
	}

	public function testMoveFiles(): void {
		$this->fixture();
		$this->noteMatrix(
			'moveFiles',
			static fn (ApiClient $c, int $n, string $ws, int $f) => $c->call('POST', '/folders/move-files', ['source_folder_id' => $f, 'target_folder_id' => 0, 'target_workspace' => $ws]),
			// eigener Quellordner je Fall, nur diese Notiz darin
			function (int $n): int {
				$f = $this->folder($this->a, $this->w1);
				self::ok($this->a->call('POST', "/notes/$n/folder", ['folder_id' => $f]), 'Notiz in Quellordner');
				return $f;
			},
		);
	}

	public function testDuplicateNote(): void {
		$this->fixture();
		$targets = [];
		foreach ([$this->pa => $this->a, $this->pb => $this->b, $this->w2 => $this->a, $this->w3 => $this->a] as $ws => $owner) {
			$targets[$ws] = $this->folder($owner, $ws);
		}
		$cases = [
			['A kopiert B-Notiz in eigenen privaten', $this->a, $this->b, $this->pa, false],
			['A kopiert B-Notiz in W2', $this->a, $this->b, $this->w2, true],
			['A kopiert B-Notiz in W3', $this->a, $this->b, $this->w3, false],
			['B kopiert eigene Notiz in eigenen privaten', $this->b, $this->b, $this->pb, true],
			['B kopiert A-Notiz in eigenen privaten', $this->b, $this->a, $this->pb, false],
			['A kopiert eigene Notiz in eigenen privaten', $this->a, $this->a, $this->pa, true],
		];
		foreach ($cases as [$label, $actor, $author, $target, $allowed]) {
			$n = $this->note($author, $this->w1);
			$r = $actor->call('POST', "/notes/$n/duplicate", ['folder_id' => $targets[$target]]);
			$allowed ? self::ok($r, "Duplikat: $label") : self::denied($r, "Duplikat: $label");
		}
	}

	public function testAttachmentMove(): void {
		$this->fixture();
		$targets = [$this->pa => $this->note($this->a, $this->pa), $this->pb => $this->note($this->b, $this->pb),
			$this->w2 => $this->note($this->a, $this->w2), $this->w3 => $this->note($this->a, $this->w3)];
		$cases = [
			['A: Anhang der B-Notiz an eigene private Notiz', $this->a, $this->b, $this->pa, false],
			['A: Anhang der B-Notiz an Notiz in W2', $this->a, $this->b, $this->w2, true],
			['A: Anhang der B-Notiz an Notiz in W3', $this->a, $this->b, $this->w3, false],
			['B: Anhang der eigenen Notiz an eigene private Notiz', $this->b, $this->b, $this->pb, true],
			['B: Anhang der A-Notiz an eigene private Notiz', $this->b, $this->a, $this->pb, false],
			['A: Anhang der eigenen Notiz an eigene private Notiz', $this->a, $this->a, $this->pa, true],
		];
		foreach ($cases as [$label, $actor, $author, $target, $allowed]) {
			$n = $this->note($author, $this->w1);
			$att = (int)self::ok($author->upload("/notes/$n/attachments", 'a.txt', 'bytes'), 'Anhang')['attachment_id'];
			$r = $actor->call('POST', "/notes/$n/attachments/$att/move", ['target_note_id' => $targets[$target]]);
			$allowed ? self::ok($r, "Anhang: $label") : self::denied($r, "Anhang: $label");
		}
	}

	// Ordner: nur mit verwalten und nur, wenn alle Notizen (auch Papierkorb) eigene sind; im selben Team genügt schreiben
	public function testFolderMove(): void {
		$this->fixture();
		[$a, $b] = [$this->a, $this->b];
		$mixed = $this->folder($a, $this->w1);
		$this->note($a, $this->w1, $mixed);
		$this->note($b, $this->w1, $mixed);
		self::denied($a->call('POST', "/folders/$mixed/move", ['target_workspace' => $this->pa]), 'A: Ordner mit gemischten Autoren → privat');

		$ownTrash = $this->folder($a, $this->w1);
		$this->note($a, $this->w1, $ownTrash);
		$trashed = $this->note($b, $this->w1, $ownTrash);
		self::ok($b->call('DELETE', "/notes/$trashed"), 'B-Notiz in den Papierkorb');
		self::denied($a->call('POST', "/folders/$ownTrash/move", ['target_workspace' => $this->pa]), 'A: Ordner mit fremder Notiz im Papierkorb → privat');

		$own = $this->folder($a, $this->w1);
		$n = $this->note($a, $this->w1, $own);
		self::ok($a->call('POST', "/folders/$own/move", ['target_workspace' => $this->pa]), 'A: Ordner nur mit eigenen Notizen → privat');
		self::assertSame((string)$this->paId, self::wsIdOf($n));

		$ownB = $this->folder($b, $this->w1);
		$this->note($b, $this->w1, $ownB);
		self::denied($b->call('POST', "/folders/$ownB/move", ['target_workspace' => $this->pb]), 'B (Level 1): eigener Ordner → privat braucht verwalten');
		self::ok($b->call('POST', "/folders/$ownB/move", ['target_workspace' => $this->w2]), 'B: Ordner in W2 desselben Teams');
		self::denied($a->call('POST', "/folders/$mixed/move", ['target_workspace' => $this->w3]), 'A: Ordner → W3 anderes Team');
	}

	// Archiv liegt bei Team-Quelle im selben Team; anlegen nur mit verwalten
	public function testArchive(): void {
		$this->fixture();
		[$a, $b] = [$this->a, $this->b];
		$nb = $this->note($b, $this->w1);
		self::denied($b->call('POST', "/notes/$nb/archive"), 'B archiviert ohne Team-Archiv');
		$fb = $this->folder($b, $this->w1);
		self::denied($b->call('POST', "/folders/$fb/archive"), 'B archiviert Ordner ohne Team-Archiv');
		self::ok($a->call('POST', "/notes/$nb/archive"), 'A archiviert (legt Team-Archiv an)');
		$archive = self::teamArchive($nb);
		self::assertNotNull($archive, 'Notiz liegt im Archives-Workspace des Teams');
		$nb2 = $this->note($b, $this->w1);
		self::ok($b->call('POST', "/notes/$nb2/archive"), 'B archiviert ins vorhandene Team-Archiv');
		self::ok($b->call('POST', "/folders/$fb/archive"), 'B archiviert Ordner ins Team-Archiv');
		self::assertSame([], self::ownedArchive($a->uid), 'kein privates Archiv angelegt');
	}

	// moveNotes: Quelle verwalten, Ziel schreiben; Team-Inhalt nur ins selbe Team (gemischte Autoren nie privat)
	public function testMoveNotes(): void {
		$this->fixture();
		[$a, $b] = [$this->a, $this->b];
		$n = $this->note($b, $this->w1);
		$this->note($a, $this->w1);
		self::denied($a->form(self::COMPAT, ['action' => 'move_notes', 'name' => $this->w1, 'target' => $this->pa]), 'A: W1 → privat');
		self::denied($a->form(self::COMPAT, ['action' => 'move_notes', 'name' => $this->w1, 'target' => $this->w3]), 'A: W1 → W3');
		self::denied($b->form(self::COMPAT, ['action' => 'move_notes', 'name' => $this->w1, 'target' => $this->w2]), 'B (kein verwalten): W1 → W2');
		self::ok($a->form(self::COMPAT, ['action' => 'move_notes', 'name' => $this->w1, 'target' => $this->w2]), 'A: W1 → W2');
		self::assertSame((string)$this->w2Id, self::wsIdOf($n));
	}

	// §2.8: Abgaben/Vorlagen und Inhalte systemverwalteter Workspaces verlassen nie ihren Workspace (alle Wege 403 bzw. 404)
	public function testSystemManagedContentNeverLeaves(): void {
		$a = $this->createUser('sa');
		[$pa] = self::newWorkspace($a, 'PA');
		[$pa2, $pa2Id] = self::newWorkspace($a, 'PA2');
		$asg = $this->rawWorkspace('user', $a->uid, $a->uid, 'assignments');
		$qb = self::db()->getQueryBuilder();
		$qb->update('nextnotes_workspaces')->set('linked_team_id', $qb->createNamedParameter('team-' . bin2hex(random_bytes(4))))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($asg, IQueryBuilder::PARAM_INT)))->executeStatement();
		$sub = self::rawNote($asg, 'Abgabe', $a->uid, 'submission');
		$plain = self::rawNote($asg, 'Notiz im Schüler-Workspace', $a->uid);
		$fPa2 = (int)self::ok($a->call('POST', '/folders', ['folder_name' => 'Ziel', 'workspace' => $pa2]), 'Zielordner')['folder_id'];
		$targetNote = self::newNote($a, $pa2, 'Ziel', '<p>z</p>');
		foreach ([$sub => [403, 404], $plain => [403]] as $n => $codes) {
			$before = self::noteRow($n);
			self::denied($a->call('PATCH', "/notes/$n", ['workspace' => $pa2]), "PATCH $n", $codes);
			self::denied($a->call('POST', "/notes/$n/folder", ['workspace' => $pa2]), "moveToFolder $n", $codes);
			self::denied($a->call('POST', "/notes/$n/archive"), "Archiv $n", array_merge($codes, [400]));
			self::denied($a->call('POST', "/notes/$n/duplicate", ['folder_id' => $fPa2]), "Duplikat $n", $codes);
			self::assertSame($before, self::noteRow($n), "Notiz $n unverändert");
		}

		// Ordner im eigenen privaten Workspace mit einer Abgabe-Notiz: Ordner-Wege → 403
		$f = (int)self::ok($a->call('POST', '/folders', ['folder_name' => 'Mit Abgabe', 'workspace' => $pa]), 'Ordner')['folder_id'];
		$subInFolder = self::rawNote(self::ownWsId($a->uid, $pa), 'Abgabe im Ordner', $a->uid, 'submission', $f);
		self::denied($a->call('POST', "/folders/$f/move", ['target_workspace' => $pa2]), 'Ordner mit Abgabe verschieben');
		self::denied($a->call('POST', "/folders/$f/duplicate"), 'Ordner mit Abgabe duplizieren');
		self::denied($a->form(self::COMPAT, ['action' => 'move_notes', 'name' => $pa, 'target' => $pa2]), 'move_notes mit Abgabe');
		self::assertSame((string)self::ownWsId($a->uid, $pa), self::wsIdOf($subInFolder), 'Abgabe bleibt');
		$att = (int)self::ok($a->upload("/notes/$targetNote/attachments", 'a.txt', 'x'), 'Anhang')['attachment_id'];
		self::denied($a->call('POST', "/notes/$targetNote/attachments/$att/move", ['target_note_id' => $sub]), 'Anhang an Abgabe', [403, 404]);
		self::assertNotSame((string)$pa2Id, '', 'PA2 vorhanden');
	}

	private static function teamArchive(int $note): ?int {
		$qb = self::db()->getQueryBuilder();
		$qb->select('w.id')->from('nextnotes_workspaces', 'w')
			->innerJoin('w', 'nextnotes_notes', 'n', $qb->expr()->eq('n.workspace_id', 'w.id'))
			->where($qb->expr()->eq('n.id', $qb->createNamedParameter($note, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('w.owner_type', $qb->createNamedParameter('team')))
			->andWhere($qb->expr()->eq('w.name', $qb->createNamedParameter('Archives')));
		$v = $qb->executeQuery()->fetchOne();
		return $v === false ? null : (int)$v;
	}

	/** @return int[] */
	private static function ownedArchive(string $uid): array {
		$qb = self::db()->getQueryBuilder();
		$qb->select('id')->from('nextnotes_workspaces')
			->where($qb->expr()->eq('owner_type', $qb->createNamedParameter('user')))
			->andWhere($qb->expr()->eq('owner_id', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('name', $qb->createNamedParameter('Archives')));
		return array_map('intval', $qb->executeQuery()->fetchFirstColumn());
	}
}
