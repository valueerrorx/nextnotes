<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Assignments;

use OCA\NextNotes\Db\NoteMapper;
use OCA\NextNotes\Service\AccessService;
use OCA\NextNotes\Service\AssignmentDeletion;
use OCA\NextNotes\Service\AttachmentService;
use OCA\NextNotes\Service\SnapshotService;
use OCA\NextNotes\Tests\Support\ApiClient;
use OCA\NextNotes\Tests\Support\Response;
use OCA\NextNotes\Tests\Support\TeamTestCase;
use OCA\NextNotes\Tests\Support\Teams;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Server;

// Basis M6d: Team T (L Owner/Lehrkraft, M Moderator, S1/S2 Mitglieder, X Mitglied ohne Abgabe), Aufgabe aus L-Notiz mit Anhang; Abgaben per DB (Austeilen erst M6e1)
abstract class AssignmentTestCase extends TeamTestCase {
	protected const APP = '/index.php/apps/nextnotes/';
	protected const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

	/** @var int[] Aufgaben, die im tearDown gelöscht werden */
	protected array $assignmentIds = [];

	protected function tearDown(): void {
		foreach ($this->assignmentIds as $id) {
			Server::get(AssignmentDeletion::class)->purge($id);
		}
		$this->assignmentIds = [];
		parent::tearDown();
	}

	/** @return array<string, mixed> */
	protected function fixture(bool $submissions = true): array {
		$l = $this->createUser('al', 'Lehrkraft L');
		$m = $this->createUser('am');
		$s1 = $this->createUser('as1', 'Schüler Eins');
		$s2 = $this->createUser('as2', 'Schüler Zwei');
		$x = $this->createUser('ax');
		$n = $this->createUser('an');
		$t = $this->teams->create($l->uid, 'Asg ' . bin2hex(random_bytes(3)));
		$this->teams->addUser($t, $l->uid, $m->uid, Teams::MODERATOR);
		$memberS1 = $this->teams->addUser($t, $l->uid, $s1->uid);
		$this->teams->addUser($t, $l->uid, $s2->uid);
		$this->teams->addUser($t, $l->uid, $x->uid);
		[$ws, $wsId] = self::teamWs($l, $t, 'Asg');
		$mark = 'Asg' . bin2hex(random_bytes(4));
		$src = self::newNote($l, $ws, "Quelle $mark", "<p>quelle $mark</p>");
		$bytes = (string)base64_decode(self::PNG);
		$att = (int)self::ok($l->upload("/notes/$src/attachments", 'bild.png', $bytes, [], 'image/png'), 'Anhang')['attachment_id'];
		self::ok($l->call('PATCH', "/notes/$src", ['content' => "<p>quelle $mark</p><img src=\"/api/v1/notes/$src/attachments/$att\">"]), 'Quelle mit Bild');
		$a = $this->createAssignment($l, $t, $src, "Aufgabe $mark");
		$fx = ['l' => $l, 'm' => $m, 's1' => $s1, 's2' => $s2, 'x' => $x, 'n' => $n, 't' => $t, 'memberS1' => $memberS1, 'ws' => $ws, 'wsId' => $wsId,
			'mark' => $mark, 'src' => $src, 'att' => $att, 'bytes' => $bytes, 'aid' => (int)$a['id'], 'tpl' => (int)$a['template_note_id']];
		if ($submissions) {
			$fx['sub1'] = $this->rawSubmission($fx['aid'], $s1, $t, "abgabe1 $mark");
			$fx['sub2'] = $this->rawSubmission($fx['aid'], $s2, $t, "abgabe2 $mark");
		}
		return $fx;
	}

	/** @return array<string, mixed> Aufgabe als JSON */
	protected function createAssignment(ApiClient $c, string $team, int $source, string $title, array $extra = []): array {
		$r = $c->call('POST', '/assignments', ['team' => $team, 'title' => $title, 'due_at' => time() + 7200, 'source_note_id' => $source] + $extra);
		self::assertSame(201, $r->status, 'Aufgabe anlegen: ' . $r->describe());
		$a = $r->json()['assignment'];
		$this->assignmentIds[] = (int)$a['id'];
		return $a;
	}

	/**
	 * Abgabe wie M6e1 sie anlegt: Schüler-Workspace kind=assignments, Aufgaben-Ordner, Abgabe, Kopie (role=submission) mit Anhang und Snapshot
	 * @return array<string, mixed>
	 */
	protected function rawSubmission(int $aid, ApiClient $student, string $teamId, string $text): array {
		$db = self::db();
		$wsId = $this->assignmentWorkspace($student->uid, $teamId);
		$qb = $db->getQueryBuilder();
		$qb->insert('nextnotes_folders')->values([
			'workspace_id' => $qb->createNamedParameter($wsId, IQueryBuilder::PARAM_INT),
			'name' => $qb->createNamedParameter('Aufgabe ' . $aid),
			'assignment_id' => $qb->createNamedParameter($aid, IQueryBuilder::PARAM_INT),
			'created_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
			'updated_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
		])->executeStatement();
		$folder = $qb->getLastInsertId();
		$qb = $db->getQueryBuilder();
		$qb->insert('nextnotes_submissions')->values([
			'assignment_id' => $qb->createNamedParameter($aid, IQueryBuilder::PARAM_INT),
			'student_uid' => $qb->createNamedParameter($student->uid),
			'student_name' => $qb->createNamedParameter($student->uid),
			'status' => $qb->createNamedParameter('assigned'),
			'created_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
			'updated_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
		])->executeStatement();
		$sid = $qb->getLastInsertId();
		$note = self::rawNote($wsId, "Kopie $text", $student->uid, 'submission', $folder);
		$qb = $db->getQueryBuilder();
		$qb->update('nextnotes_notes')
			->set('assignment_id', $qb->createNamedParameter($aid, IQueryBuilder::PARAM_INT))
			->set('submission_id', $qb->createNamedParameter($sid, IQueryBuilder::PARAM_INT))
			->set('content', $qb->createNamedParameter("<p>$text</p>"))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($note, IQueryBuilder::PARAM_INT)))->executeStatement();
		self::setColumn('nextnotes_submissions', $sid, 'note_id', $note);
		$att = (int)Server::get(AttachmentService::class)->createFromString($note, 'kopie.txt', 'text/plain', "datei $text", $student->uid)->getId();
		$entity = Server::get(NoteMapper::class)->find($note);
		Server::get(SnapshotService::class)->autoBefore($entity, $student->uid);
		Server::get(AccessService::class)->resetCache();
		return ['wsId' => $wsId, 'folder' => $folder, 'sid' => $sid, 'note' => $note, 'att' => $att, 'text' => $text];
	}

	// Schüler-Workspace je Team (UNIQUE owner_id, linked_team_id), wird mit der letzten Aufgabe gelöscht
	protected function assignmentWorkspace(string $uid, string $teamId): int {
		$qb = self::db()->getQueryBuilder();
		$qb->select('id')->from('nextnotes_workspaces')
			->where($qb->expr()->eq('owner_id', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('linked_team_id', $qb->createNamedParameter($teamId)));
		$id = $qb->executeQuery()->fetchOne();
		if ($id !== false) {
			return (int)$id;
		}
		$wsId = $this->rawWorkspace('user', $uid, $uid, 'assignments');
		self::setColumn('nextnotes_workspaces', $wsId, 'linked_team_id', $teamId);
		self::setColumn('nextnotes_workspaces', $wsId, 'name', 'Aufgaben – Test');
		return $wsId;
	}

	protected static function setColumn(string $table, int $id, string $col, mixed $value): void {
		$qb = self::db()->getQueryBuilder();
		$type = $value === null ? IQueryBuilder::PARAM_NULL : (is_int($value) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR);
		$qb->update($table)->set($col, $qb->createNamedParameter($value, $type))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeStatement();
	}

	/** @return array<string, mixed>|null Zeile ohne Suchtext/Inhalt-Spalten außer content */
	protected static function row(string $table, int $id): ?array {
		$qb = self::db()->getQueryBuilder();
		$qb->select('*')->from($table)->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetchAssociative();
		return $row === false ? null : $row;
	}

	protected static function countRows(string $table, string $col, int|string $value): int {
		$qb = self::db()->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from($table)
			->where($qb->expr()->eq($col, $qb->createNamedParameter($value, is_int($value) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR)));
		return (int)$qb->executeQuery()->fetchOne();
	}

	protected static function page(ApiClient $c, string $path): Response {
		return $c->absolute('GET', self::APP . $path);
	}

	protected static function expectStatus(Response $r, int|array $expected, string $what): void {
		$expected = (array)$expected;
		self::assertContains($r->status, $expected, "$what: erwartet " . implode('/', $expected) . ', erhalten ' . substr($r->describe(), 0, 300));
	}

	protected static function resetRateLimits(): void {
		self::db()->getQueryBuilder()->delete('ratelimit_entries')->executeStatement();
	}

	/**
	 * Alle Lesewege einer Notiz (API, Anhang, Snapshots, Seiten, Kompat) mit erwartetem Status
	 * @return string[] Abweichungen
	 */
	protected static function readPaths(ApiClient $c, int $note, int $att, int $expected, bool $withSnapshot = true): array {
		self::resetRateLimits();
		$errors = [];
		$snapKey = null;
		$list = $c->call('GET', "/notes/$note/snapshots");
		if ($list->ok()) {
			$snapKey = $list->json()['snapshots'][0]['snapshot_key'] ?? null;
		}
		$checks = [
			"GET /notes/$note" => $c->call('GET', "/notes/$note"),
			"GET Anhang $att" => $c->call('GET', "/notes/$note/attachments/$att"),
			'GET Snapshot-Liste' => $list,
			'GET Snapshot' => $c->call('GET', "/notes/$note/snapshot?snapshot_key=" . rawurlencode((string)($snapKey ?? '2000-01-01'))),
			'revisions.php' => self::page($c, "revisions.php?note_id=$note"),
			'api_download_note.php' => self::page($c, "api_download_note.php?id=$note"),
			'api_export_note.php' => self::page($c, "api_export_note.php?id=$note"),
		];
		foreach ($checks as $what => $r) {
			if ($r->status !== $expected) {
				$errors[] = "$what: $r->status statt $expected";
			}
		}
		if ($expected === 200 && $withSnapshot && $snapKey === null) {
			$errors[] = 'Snapshot-Liste leer';
		}
		return $errors;
	}

	/** @return array<string, mixed> Fixture mit echtem Austeilen (M6e1): subs[uid] = Abgabe-Zeile (Schüler S1, S2, X) */
	protected function distributedFixture(): array {
		$fx = $this->fixture(false);
		$fx['distribute'] = self::ok($fx['l']->call('POST', "/assignments/{$fx['aid']}/distribute"), 'Austeilen');
		$fx['subs'] = self::submissionsOf($fx['aid']);
		return $fx;
	}

	/** @return array<string, array<string, mixed>> student_uid → Abgabe-Zeile */
	protected static function submissionsOf(int $aid): array {
		$qb = self::db()->getQueryBuilder();
		$qb->select('*')->from('nextnotes_submissions')->where($qb->expr()->eq('assignment_id', $qb->createNamedParameter($aid, IQueryBuilder::PARAM_INT)));
		$out = [];
		foreach ($qb->executeQuery()->fetchAll() as $row) {
			$out[(string)($row['student_uid'] ?? '#' . $row['id'])] = $row;
		}
		return $out;
	}

	/**
	 * Wettlauf deterministisch: dieser Prozess hält die Notizzeile, die Anfrage läuft an (Rechteprüfung vor der Sperre),
	 * $mutate ändert in derselben Transaktion den Zustand, dann Commit; die Anfrage muss unter Sperre neu prüfen
	 * Nur MariaDB/PostgreSQL beweiskräftig (Zeilensperre); SQLite sperrt die ganze DB, die Anfrage wartet dort schon vor der Rechteprüfung
	 */
	protected static function raceOnNote(ApiClient $c, string $method, string $path, ?array $body, int $noteId, callable $mutate): Response {
		$db = self::db();
		$db->beginTransaction();
		try {
			$qb = $db->getQueryBuilder();
			$qb->update('nextnotes_notes')->set('id', 'id')->where($qb->expr()->eq('id', $qb->createNamedParameter($noteId, IQueryBuilder::PARAM_INT)))->executeStatement();
			$req = $c->start($method, $path, $body);
			for ($t = microtime(true); microtime(true) - $t < 2.0; usleep(50000)) {
				($req->pump)();
			}
			$mutate();
			$db->commit();
		} catch (\Throwable $e) {
			$db->rollBack();
			throw $e;
		}
		return ($req->wait)();
	}
}
