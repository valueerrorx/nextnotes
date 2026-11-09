<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Assignments;

use OCA\NextNotes\Db\AssignmentMapper;
use OCA\NextNotes\Notification\Notifier;
use OCA\NextNotes\Service\AssignmentDeletion;
use OCA\NextNotes\Service\AssignmentNotifications;
use OCA\NextNotes\Service\Exception\ConflictException;
use OCA\NextNotes\Service\NoteService;
use OCA\NextNotes\Service\View\AssignmentsViewService;
use OCA\NextNotes\Service\WorkspaceDeletion;
use OCA\NextNotes\Service\WorkspaceService;
use OCA\NextNotes\Tests\Support\ApiClient;
use OCA\NextNotes\Tests\Support\Response;
use OCA\NextNotes\Tests\Support\Teams;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\NotFoundException;
use OCP\IDBConnection;
use OCP\Notification\IManager as INotificationManager;
use OCP\Server;

// M6-wsbind (ADR 0021): Aufgabe gehört genau einem Team-Workspace; Anlegen, Sicht, Löschen des Workspace, make-private, Links
final class AssignmentWorkspaceBindTest extends AssignmentTestCase {
	/** @return array<string, mixed> */
	private static function body(?string $ws, int $source): array {
		return ['title' => 'x', 'due_at' => time() + 600, 'source_note_id' => $source] + ($ws === null ? [] : ['workspace' => $ws]);
	}

	private static function key(int $wsId): string {
		return self::row('nextnotes_workspaces', $wsId)['name'] . " (x, #$wsId)";
	}

	private static function appFileExists(int $noteId, int $attachmentId): bool {
		try {
			Server::get(IAppDataFactory::class)->get('nextnotes')->getFolder('attachments')->getFolder((string)$noteId)->getFile((string)$attachmentId);
			return true;
		} catch (NotFoundException) {
			return false;
		}
	}

	/** @return int[] */
	private static function ids(string $table, string $col, int $value): array {
		$qb = self::db()->getQueryBuilder();
		$qb->select('id')->from($table)->where($qb->expr()->eq($col, $qb->createNamedParameter($value, IQueryBuilder::PARAM_INT)));
		return array_map('intval', $qb->executeQuery()->fetchFirstColumn());
	}

	/** @return array<string, mixed>|null Aufgabe aus GET /assignments */
	private static function listed(ApiClient $c, int $aid): ?array {
		foreach (self::ok($c->call('GET', '/assignments'), "GET /assignments {$c->uid}")['assignments'] as $a) {
			if ((int)$a['id'] === $aid) {
				return $a;
			}
		}
		return null;
	}

	/**
	 * Wettlauf wie raceOnNote: $lock sperrt in der Test-Transaktion, die Anfrage läuft an, $mutate ändert, Commit
	 * Nur MariaDB/PostgreSQL beweiskräftig; SQLite sperrt die ganze DB (Ergebnis gleich, Zeitpunkt früher)
	 */
	private static function race(ApiClient $c, string $method, string $path, ?array $body, callable $lock, callable $mutate): Response {
		$db = self::db();
		$db->beginTransaction();
		try {
			$lock();
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

	// Done 3: workspace Pflicht, Team-Workspace, Lehrkraft, Quelle genau dort; Erfolg speichert workspace_id
	public function testCreateNeedsTeamWorkspace(): void {
		$fx = $this->fixture(false);
		['l' => $l, 's1' => $s1, 'n' => $n, 't' => $t, 'ws' => $ws, 'wsId' => $wsId, 'src' => $src, 'aid' => $aid] = $fx;
		self::assertSame($wsId, (int)self::row('nextnotes_assignments', $aid)['workspace_id'], 'workspace_id gespeichert');
		$count = self::countRows('nextnotes_assignments', 'team_id', $t);
		self::assertSame(400, $l->call('POST', '/assignments', self::body(null, $src))->status, 'ohne workspace');
		self::assertSame(400, $l->call('POST', '/assignments', self::body('  ', $src))->status, 'leerer workspace');
		self::assertSame(404, $l->call('POST', '/assignments', self::body('Gibt es nicht ' . bin2hex(random_bytes(3)), $src))->status, 'unbekannt');
		[$own] = self::newWorkspace($l, 'Privat');
		$private = self::newNote($l, $own, 'Privat', '<p>p</p>');
		$r = $l->call('POST', '/assignments', self::body($own, $private));
		self::assertSame([400, 'not_a_team_workspace'], [$r->status, $r->json()['code'] ?? null], 'privater Workspace: ' . $r->describe());
		self::assertSame(403, $s1->call('POST', '/assignments', self::body($ws, $src))->status, 'Schüler');
		self::assertSame(404, $n->call('POST', '/assignments', self::body($ws, $src))->status, 'Nicht-Mitglied');
		[$wsB] = self::teamWs($l, $t, 'B');
		$inB = self::newNote($l, $wsB, 'In B', '<p>b</p>');
		$r = $l->call('POST', '/assignments', self::body($ws, $inB));
		self::assertSame([403, 'source_not_allowed'], [$r->status, $r->json()['code'] ?? null], 'Quelle aus anderem Workspace');
		self::assertSame($count, self::countRows('nextnotes_assignments', 'team_id', $t), 'nichts angelegt');
	}

	// Done 3: Workspace zwischen Prüfung und Sperre gelöscht → 404, keine Aufgabe, keine Vorlage
	public function testWorkspaceDeletedBeforeLock(): void {
		$fx = $this->fixture(false);
		['l' => $l, 't' => $t] = $fx;
		[$wsB, $wsBId] = self::teamWs($l, $t, 'Weg');
		$srcB = self::newNote($l, $wsB, 'Quelle B', '<p>b</p>');
		$count = self::countRows('nextnotes_assignments', 'team_id', $t);
		$templates = self::countRows('nextnotes_notes', 'role', 'template');
		$r = self::race($l, 'POST', '/assignments', self::body($wsB, $srcB),
			static function () use ($wsBId): void {
				$qb = self::db()->getQueryBuilder();
				$qb->update('nextnotes_workspaces')->set('id', 'id')->where($qb->expr()->eq('id', $qb->createNamedParameter($wsBId, IQueryBuilder::PARAM_INT)))->executeStatement();
			},
			static function () use ($wsBId): void {
				$qb = self::db()->getQueryBuilder();
				$qb->delete('nextnotes_workspaces')->where($qb->expr()->eq('id', $qb->createNamedParameter($wsBId, IQueryBuilder::PARAM_INT)))->executeStatement();
			});
		Server::get(NoteService::class)->purge([$srcB]);
		self::assertSame(404, $r->status, 'gelöscht unter Sperre: ' . $r->describe());
		self::assertSame($count, self::countRows('nextnotes_assignments', 'team_id', $t), 'keine Aufgabe');
		self::assertSame($templates, self::countRows('nextnotes_notes', 'role', 'template'), 'keine Vorlage');
	}

	// Done 4: Aufgaben je Workspace, Rechte/Liste unverändert, Löschen mit Ziel zieht um, ohne confirm 400, mit confirm alles weg
	public function testBindingAndWorkspaceDeletion(): void {
		$fx = $this->distributedFixture();
		['l' => $l, 'm' => $m, 's1' => $s1, 't' => $t, 'ws' => $ws, 'wsId' => $wsId, 'aid' => $aid] = $fx;
		[$wsB, $wsBId] = self::teamWs($m, $t, 'B');
		$srcB = self::newNote($m, $wsB, 'Quelle B', '<p>b</p>');
		$bid = (int)$this->createAssignment($m, $t, $srcB, 'In B')['id'];
		self::ok($m->call('POST', "/assignments/$bid/distribute"), 'B austeilen');
		self::assertSame($wsBId, (int)self::row('nextnotes_assignments', $bid)['workspace_id']);

		// Liste und Rechte team-basiert wie bisher; workspace_id/workspace mit Leserecht
		foreach ([$l, $m] as $c) {
			$a = self::listed($c, $aid);
			$b = self::listed($c, $bid);
			self::assertNotNull($a, "{$c->uid} sieht A");
			self::assertNotNull($b, "{$c->uid} sieht B");
			self::assertSame([$wsId, $wsBId], [$a['workspace_id'], $b['workspace_id']], "{$c->uid}: workspace_id");
			self::assertSame($ws, $a['workspace'], "{$c->uid}: Schlüssel");
			self::assertTrue($b['can']['delete'] && $b['can']['distribute'], "{$c->uid}: Lehrkraft-Rechte");
		}
		$own = self::listed($s1, $aid);
		self::assertSame($wsId, $own['workspace_id'] ?? null, 'S1 liest Team-Workspace');
		self::assertFalse($own['can']['review'], 'S1 ohne review');

		// Löschen B mit Ziel A: Aufgabe zieht um, Abgaben unverändert
		$subsB = self::submissionsOf($bid);
		self::ok($l->call('DELETE', '/workspaces/' . rawurlencode($wsB), ['target' => $ws]), 'B löschen mit Ziel');
		self::assertNull(self::row('nextnotes_workspaces', $wsBId), 'B weg');
		self::assertSame($wsId, (int)self::row('nextnotes_assignments', $bid)['workspace_id'], 'Aufgabe an A');
		self::assertEquals($subsB, self::submissionsOf($bid), 'Abgaben unverändert');

		// letzter Workspace ohne confirm → 400, nichts gelöscht
		$r = $l->call('DELETE', '/workspaces/' . rawurlencode($ws), []);
		self::assertSame([400, 'confirmation_required'], [$r->status, $r->json()['code'] ?? null], 'ohne confirm');
		self::assertNotNull(self::row('nextnotes_assignments', $aid));
		self::assertNotNull(self::row('nextnotes_assignments', $bid));

		// mit confirm: Aufgaben, Abgaben, Kopien, Anhänge (Zeilen und Dateien), Aufgaben-Ordner, leere Schüler-Workspaces weg
		$notes = [];
		$files = [];
		$studentWs = [];
		foreach ([$aid, $bid] as $id) {
			foreach (self::submissionsOf($id) as $sub) {
				$note = (int)$sub['note_id'];
				$notes[] = $note;
				$studentWs[] = (int)self::row('nextnotes_notes', $note)['workspace_id'];
				foreach (self::ids('nextnotes_attachments', 'note_id', $note) as $att) {
					$files[] = [$note, $att];
				}
			}
			$tpl = (int)self::row('nextnotes_assignments', $id)['template_note_id'];
			$notes[] = $tpl;
			foreach (self::ids('nextnotes_attachments', 'note_id', $tpl) as $att) {
				$files[] = [$tpl, $att];
			}
		}
		$folders = array_merge(self::ids('nextnotes_folders', 'assignment_id', $aid), self::ids('nextnotes_folders', 'assignment_id', $bid));
		self::assertNotEmpty($files, 'Kopien mit Anhang');
		self::assertNotEmpty($folders, 'Aufgaben-Ordner');
		foreach ($files as [$note, $att]) {
			self::assertTrue(self::appFileExists($note, $att), "Datei $note/$att vorher");
		}
		self::ok($l->call('DELETE', '/workspaces/' . rawurlencode($ws), ['confirm' => true]), 'letzten löschen');
		self::assertNull(self::row('nextnotes_workspaces', $wsId), 'Workspace weg');
		foreach ([$aid, $bid] as $id) {
			self::assertNull(self::row('nextnotes_assignments', $id), "Aufgabe $id weg");
			self::assertSame(0, self::countRows('nextnotes_submissions', 'assignment_id', $id), "Abgaben $id weg");
		}
		foreach ($notes as $note) {
			self::assertNull(self::row('nextnotes_notes', $note), "Notiz $note weg");
			self::assertSame(0, self::countRows('nextnotes_attachments', 'note_id', $note), "Anhang-Zeilen $note weg");
			self::assertSame(0, self::countRows('nextnotes_snapshots', 'note_id', $note), "Snapshots $note weg");
		}
		foreach ($files as [$note, $att]) {
			self::assertFalse(self::appFileExists($note, $att), "Datei $note/$att weg");
		}
		foreach ($folders as $folder) {
			self::assertNull(self::row('nextnotes_folders', $folder), "Aufgaben-Ordner $folder weg");
		}
		foreach (array_unique($studentWs) as $sws) {
			self::assertNull(self::row('nextnotes_workspaces', $sws), "Schüler-Workspace $sws weg");
		}
		$this->assignmentIds = [];
	}

	// Done 4: Kandidat entsteht zwischen Vorprüfung und Sperre (deterministisch, alle DBs) → 409 workspace_changed; Teilabbruch, Wiederholung setzt fort
	public function testNewCandidateBeforeLockConflicts(): void {
		$fx = $this->fixture(false);
		['l' => $l, 't' => $t, 'ws' => $ws, 'wsId' => $wsId, 'aid' => $aid] = $fx;
		$real = Server::get(AssignmentDeletion::class);
		$newWs = 0;
		$stub = $this->createStub(AssignmentDeletion::class);
		$stub->method('purge')->willReturnCallback(function (int $id) use ($real, $t, $l, &$newWs): bool {
			$newWs = $this->rawWorkspace('team', $t, $l->uid);
			return $real->purge($id);
		});
		$svc = clone Server::get(WorkspaceDeletion::class);
		(new \ReflectionProperty(WorkspaceDeletion::class, 'assignmentDeletion'))->setValue($svc, $stub);
		try {
			$svc->delete($l->uid, $ws, null, true);
			self::fail('409 erwartet');
		} catch (ConflictException $e) {
			self::assertSame(['code' => 'workspace_changed'], $e->getExtra());
		}
		self::assertNotNull(self::row('nextnotes_workspaces', $wsId), 'Workspace bleibt');
		self::assertGreaterThan(0, $newWs);
		self::assertNull(self::row('nextnotes_assignments', $aid), 'Aufgabe vorher gelöscht (Teilabbruch zulässig)');
		$this->assignmentIds = [];
		Server::get(WorkspaceService::class)->reset();
		self::ok($l->call('DELETE', '/workspaces/' . rawurlencode($ws), ['target' => self::key($newWs)]), 'Wiederholung mit Ziel');
	}

	// Done 4 über HTTP: neuer Kandidat unter der Team-Sperre → 409 (nur MariaDB/PostgreSQL; SQLite sperrt die ganze DB, die Anfrage läuft erst nach dem Commit)
	public function testNewCandidateUnderLockConflicts(): void {
		if (self::db()->getDatabaseProvider() === IDBConnection::PLATFORM_SQLITE) {
			$this->markTestSkipped('Zeilensperre nur auf MariaDB/PostgreSQL beweiskräftig');
		}
		$fx = $this->fixture(false);
		['l' => $l, 't' => $t, 'ws' => $ws, 'wsId' => $wsId, 'aid' => $aid] = $fx;
		$newWs = 0;
		$r = self::race($l, 'DELETE', '/workspaces/' . rawurlencode($ws), ['confirm' => true],
			static function () use ($t): void {
				$qb = self::db()->getQueryBuilder();
				$qb->update('nextnotes_teams')->set('team_id', 'team_id')->where($qb->expr()->eq('team_id', $qb->createNamedParameter($t)))->executeStatement();
			},
			function () use ($t, $l, &$newWs): void {
				$newWs = $this->rawWorkspace('team', $t, $l->uid);
			});
		self::assertSame([409, 'workspace_changed'], [$r->status, $r->json()['code'] ?? null], 'neuer Kandidat: ' . $r->describe());
		self::assertNotNull(self::row('nextnotes_workspaces', $wsId), 'Workspace bleibt');
		self::assertNotNull(self::row('nextnotes_workspaces', $newWs), 'neuer Workspace bleibt');
		self::assertNull(self::row('nextnotes_assignments', $aid), 'Aufgabe vorher gelöscht (Teilabbruch zulässig)');
		$this->assignmentIds = [];
		// Wiederholung mit Ziel: gelingt
		self::ok($l->call('DELETE', '/workspaces/' . rawurlencode($ws), ['target' => self::key($newWs)]), 'Wiederholung mit Ziel');
	}

	// Done 4: make-private mit Aufgaben erlaubt, workspace_id bleibt, Aufgabe über owner_uid lesbar; Sicht ohne Leserecht null; Links
	public function testMakePrivateNullViewAndLinks(): void {
		$l = $this->createUser('wl', 'Lehrkraft W');
		$m = $this->createUser('wm');
		$t = $this->teams->create($l->uid, 'Bind ' . bin2hex(random_bytes(3)));
		$this->teams->addUser($t, $l->uid, $m->uid, Teams::MODERATOR);
		[$wsL, $wsLId] = self::teamWs($l, $t, 'Von L');
		[$wsM, $wsMId] = self::teamWs($m, $t, 'Von M');
		$a1 = (int)$this->createAssignment($l, $t, self::newNote($l, $wsL, 'Q1', '<p>1</p>'), 'In L')['id'];
		$a2 = (int)$this->createAssignment($l, $t, self::newNote($l, $wsM, 'Q2', '<p>2</p>'), 'In M')['id'];
		$wsSvc = Server::get(WorkspaceService::class);
		$view = Server::get(AssignmentsViewService::class);
		$link = static function (string $uid, int $aid): string {
			Server::get(WorkspaceService::class)->reset();
			$n = Server::get(INotificationManager::class)->createNotification();
			$n->setApp('nextnotes')->setUser($uid)->setObject('assignment', (string)$aid)
				->setSubject(AssignmentNotifications::TRANSFERRED, ['reason' => 'admin'])->setDateTime(new \DateTime());
			$query = (string)parse_url(Server::get(Notifier::class)->prepare($n, 'en')->getLink(), PHP_URL_QUERY);
			parse_str($query, $q);
			return (string)($q['workspace'] ?? '');
		};
		// aktiv: Link auf den Workspace der Aufgabe
		$asg = Server::get(AssignmentMapper::class);
		$wsSvc->reset();
		self::assertSame($wsM, $wsSvc->assignmentKey($l->uid, $asg->find($a2)), 'aktiv: Workspace der Aufgabe');
		self::assertStringContainsString(http_build_query(['workspace' => $wsM]), $view->assignmentUrl($l->uid, $asg->find($a2)), 'Banner-Link');
		self::assertSame($wsM, $link($l->uid, $a2), 'Notifier-Link');
		self::assertSame($wsL, $link($l->uid, $a1), 'Notifier-Link A1');

		// Team gelöscht: L liest wsM nicht mehr (nur created_by M) → workspace_id/workspace null, Link auf eigenen Workspace
		$this->teams->destroy($l->uid, $t, true);
		self::assertSame('orphaned', self::teamState($t));
		$v = self::ok($l->call('GET', "/assignments/$a2"), 'Besitzer liest')['assignment'];
		self::assertSame([null, null], [$v['workspace_id'], $v['workspace']], 'ohne Leserecht am Workspace keine ID');
		$wsSvc->reset();
		$ownKey = $wsSvc->keyFor($l->uid, (int)$wsSvc->defaultWorkspace($l->uid)->getId());
		self::assertSame($ownKey, $wsSvc->assignmentKey($l->uid, $asg->find($a2)), 'verwaist: eigener Workspace');
		self::assertSame($ownKey, $wsSvc->assignmentKey($l->uid, $asg->find($a1)), 'verwaist: eigener auch bei lesbarem Team-Workspace');
		self::assertSame($ownKey, $link($l->uid, $a2), 'Notifier-Link verwaist');

		// make-private: erlaubt, workspace_id bleibt, Aufgabe weiter über owner_uid lesbar
		self::ok($l->call('POST', '/workspaces/' . rawurlencode($wsL) . '/make-private'), 'Umwandeln mit Aufgabe');
		self::assertSame('user', self::row('nextnotes_workspaces', $wsLId)['owner_type']);
		self::assertSame($wsLId, (int)self::row('nextnotes_assignments', $a1)['workspace_id'], 'workspace_id unverändert');
		$v = self::ok($l->call('GET', "/assignments/$a1"), 'Besitzer liest nach Umwandeln')['assignment'];
		self::assertSame('orphaned', $v['team_state']);
		self::assertNotNull(self::listed($l, $a1), 'in der Liste');
		self::assertSame($wsMId, (int)self::row('nextnotes_assignments', $a2)['workspace_id']);
	}
}
