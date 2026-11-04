<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Scenario;

use OC\Authentication\Token\IProvider;
use OCA\NextNotes\BackgroundJob\ReminderJob;
use OCA\NextNotes\BackgroundJob\UserDataDeletionJob;
use OCA\NextNotes\Service\WorkspaceService;
use OCA\NextNotes\Tests\Support\ApiClient;
use OCA\NextNotes\Tests\Support\ApiTestCase;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Authentication\Token\IToken;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\IJobList;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use OCP\Server;

/**
 * SPEC §2 UID-Regel, §2.1 (owner_type=deleted, Löschkennung), §5 UserDataDeletionJob, §8 „Benutzer gelöscht“,
 * §13 M2.8; Plan Done M2d 1+2. Löschung echt über IUserManager (UserDeletedEvent), Job über IJobList wie Cron.
 */
final class UserDeletionTest extends ApiTestCase {
	/** UID-Spalten aus Plan M2d 1 / SPEC §13 M2.8 (Tabelle ohne Präfix => Spalten) */
	private const UID_COLUMNS = [
		'nextnotes_workspaces' => ['owner_id', 'created_by'],
		'nextnotes_ws_shares' => ['share_with', 'created_by'],
		'nextnotes_notes' => ['created_by', 'updated_by'],
		'nextnotes_attachments' => ['created_by'],
		'nextnotes_snapshots' => ['created_by'],
		'nextnotes_reminders' => ['user_id'],
		'nextnotes_locks' => ['holder_uid'],
		'nextnotes_mentions_handled' => ['user_id'],
	];
	/** Inhalts-/Namensspalten: laut §13 M2.8 nicht geprüft */
	private const CONTENT_COLUMNS = ['content', 'search_text', 'heading', 'name', 'original_name', 'message', 'tags', 'team_name_snapshot', 'title'];

	/** @var string[] */
	private array $extraUids = [];

	protected function tearDown(): void {
		// Reste eines abgebrochenen Laufs: offene Löschjobs ausführen, Konten entfernen
		foreach ($this->extraUids as $uid) {
			self::purgeAppData($uid);
			Server::get(IUserManager::class)->get($uid)?->delete();
		}
		$this->runDeletionJobs();
		// Reste abgebrochener Tests (owner_type=deleted ohne Job) über den Nachlauf
		$sweep = Server::get(\OCA\NextNotes\BackgroundJob\DeletedWorkspaceSweepJob::class);
		$sweep->setLastRun(0);
		$sweep->start(Server::get(IJobList::class));
		parent::tearDown();
	}

	private function clientFor(string $uid): ApiClient {
		$token = Server::get(ISecureRandom::class)->generate(72, ISecureRandom::CHAR_ALPHANUMERIC);
		Server::get(IProvider::class)->generateToken($token, $uid, $uid, null, 'nn-test', IToken::PERMANENT_TOKEN);
		return new ApiClient($uid, $token);
	}

	private function newUserC(string $uid): ApiClient {
		$user = Server::get(IUserManager::class)->createUser($uid, self::PASSWORD);
		self::assertNotFalse($user, "Benutzer $uid nicht angelegt");
		if (!in_array($uid, $this->extraUids, true)) {
			$this->extraUids[] = $uid;
		}
		return $this->clientFor($uid);
	}

	/** @return list<array{IJob, mixed}> */
	private static function deletionJobs(): array {
		$out = [];
		foreach (Server::get(IJobList::class)->getJobsIterator(UserDataDeletionJob::class, null, 0) as $job) {
			$out[] = [$job, $job->getArgument()];
		}
		return $out;
	}

	private function runDeletionJobs(): int {
		$n = 0;
		foreach (self::deletionJobs() as [$job]) {
			$job->start(Server::get(IJobList::class));
			$n++;
		}
		return $n;
	}

	/** Zeilen in Tabelle, deren Spalte exakt = $value */
	private static function countEq(string $table, string $col, string $value): int {
		$qb = self::db()->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from($table)
			->where($qb->expr()->eq($col, $qb->createNamedParameter($value)));
		return (int)$qb->executeQuery()->fetchOne();
	}

	private static function countIn(string $table, string $col, array $ids): int {
		if ($ids === []) {
			return 0;
		}
		$qb = self::db()->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from($table)
			->where($qb->expr()->in($col, $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
		return (int)$qb->executeQuery()->fetchOne();
	}

	/** @return list<string> Fundstellen „tabelle.spalte“ mit Wert = uid (explizite UID-Spalten + alle String-Spalten laut SPEC-Schema außer Inhalt) */
	private static function uidHits(string $uid): array {
		$expected = json_decode((string)file_get_contents(__DIR__ . '/../../schema/expected-m1.json'), true);
		$cols = self::UID_COLUMNS;
		foreach ($expected['tables'] as $table => $def) {
			foreach ($def['columns'] as $col => $spec) {
				if ($spec[0] === 'string' && !in_array($col, self::CONTENT_COLUMNS, true)) {
					$cols[$table][] = $col;
				}
			}
		}
		$hits = [];
		foreach ($cols as $table => $list) {
			foreach (array_unique($list) as $col) {
				$n = self::countEq($table, $col, $uid);
				if ($n > 0) {
					$hits[] = "$table.$col ($n)";
				}
			}
		}
		return $hits;
	}

	/** A's Zustand mit C neutralisiert: Zeilen mit C in rechtetragenden Zeilentabellen fallen weg, sonst C → NULL */
	private static function expectedAfter(array $dump, string $c): array {
		foreach ($dump as $part => $rows) {
			if ($part === 'prefs') {
				continue;
			}
			$out = [];
			foreach ($rows as $row) {
				if (in_array($part, ['shares', 'locks', 'reminders', 'mentions'], true) && in_array($c, $row, true)) {
					continue;
				}
				$out[] = array_map(static fn ($v) => $v === $c ? null : $v, $row);
			}
			$dump[$part] = $out;
		}
		return $dump;
	}

	private static function attachmentDir(int $noteId): string {
		$cfg = Server::get(IConfig::class);
		return rtrim($cfg->getSystemValueString('datadirectory'), '/') . '/appdata_' . $cfg->getSystemValueString('instanceid') . '/nextnotes/attachments/' . $noteId;
	}

	private static function nextnotesNotifications(string $uid): int {
		$qb = self::db()->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from('notifications')
			->where($qb->expr()->eq('app', $qb->createNamedParameter('nextnotes')))
			->andWhere($qb->expr()->eq('user', $qb->createNamedParameter($uid)));
		return (int)$qb->executeQuery()->fetchOne();
	}

	public function testDeletionNeutralizesImmediatelyAndJobPurges(): void {
		$a = $this->createUser('da', 'Anna Del');
		$b = $this->createUser('db');
		$cUid = 'nn-dc-' . bin2hex(random_bytes(4));
		$c = $this->newUserC($cUid);
		$mk = 'udm' . bin2hex(random_bytes(4));
		$ws = Server::get(WorkspaceService::class);

		// A: geteilter Workspace an C (Schreibrecht), eigene Notiz mit Erwähnung von C
		[$wsA, $wsAId] = self::newWorkspace($a, 'Geteilt');
		$nA = self::newNote($a, $wsA, "A $mk", "<p>Hallo @$cUid $mk</p>");
		$ws->share($a->uid, $wsAId, $cUid);
		$keyA = null;
		foreach (self::ok($c->call('GET', '/workspaces'), 'C workspaces')['workspaces'] as $w) {
			if (str_starts_with($w['name'], $wsA)) {
				$keyA = $w['name'];
			}
		}
		self::assertNotNull($keyA, 'Kontrolle: C sieht A-Workspace');

		// C in A's Workspace: eigene Notiz, Änderung an A's Notiz, Anhang, Snapshot, Erinnerung, Sperre, Mention-Status
		$nCinA = self::newNote($c, $keyA, "C in A $mk", "<p>von C $mk</p>");
		self::ok($c->call('PATCH', "/notes/$nA", ['content' => "<p>Hallo @$cUid $mk geändert</p>"]), 'C ändert A-Notiz');
		$attCinA = (int)self::ok($c->upload("/notes/$nA/attachments", 'c-in-a.txt', "c-in-a $mk"), 'C-Anhang an A-Notiz')['attachment_id'];
		self::ok($c->call('POST', "/notes/$nA/snapshot?manual=1"), 'C-Snapshot an A-Notiz');
		self::ok($c->call('POST', "/notes/$nA/reminder", ['reminder_at' => '2030-01-01T08:00:00Z']), 'C-Erinnerung an A-Notiz');
		self::ok($c->call('POST', "/notes/$nCinA/lock", ['editor_session_id' => 'c-s1'], ['X-Editor-Session-ID: c-s1']), 'C-Sperre in A');
		self::ok($c->call('POST', "/mentions/{$a->uid}/$nA/handled"), 'C markiert Erwähnung');

		// C: eigener Workspace mit Ordner, Notizen, Tags, Anhang, Snapshot, Erinnerung, Sperre; Freigabe an B; B erwähnt/markiert
		[$wsC, $wsCId] = self::newWorkspace($c, 'Eigen');
		$fC = (int)self::ok($c->call('POST', '/folders', ['folder_name' => "F$mk", 'workspace' => $wsC]), 'C-Ordner')['folder_id'];
		$nC = self::newNote($c, $wsC, "C eigen $mk", "<p>Hallo @{$b->uid} $mk</p>", $fC);
		self::ok($c->call('PUT', "/notes/$nC/tags", ['tags' => "t$mk"]), 'C-Tags');
		$attC = (int)self::ok($c->upload("/notes/$nC/attachments", 'c.txt', "c-own $mk"), 'C-Anhang')['attachment_id'];
		self::ok($c->call('POST', "/notes/$nC/snapshot?manual=1"), 'C-Snapshot');
		self::ok($c->call('POST', "/notes/$nC/reminder", ['reminder_at' => '2030-01-01T08:00:00Z']), 'C-Erinnerung');
		self::ok($c->call('POST', "/notes/$nC/lock", ['editor_session_id' => 'c-s2'], ['X-Editor-Session-ID: c-s2']), 'C-Sperre');
		$ws->share($cUid, $wsCId, $b->uid);
		self::ok($b->call('POST', "/mentions/$cUid/$nC/handled"), 'B markiert Erwähnung in C-Notiz');
		self::assertFileExists(self::attachmentDir($nC) . '/' . $attC, 'Kontrolle: C-Datei in IAppData');
		self::assertFileExists(self::attachmentDir($nA) . '/' . $attCinA, 'Kontrolle: C-Datei an A-Notiz');

		// B-Kontrolle für ReminderJob
		[$wsB] = self::newWorkspace($b, 'Kontrolle');
		$nB = self::newNote($b, $wsB, "B $mk", '<p>b</p>');
		self::ok($b->call('POST', "/notes/$nB/reminder", ['reminder_at' => '2030-01-01T08:00:00Z']), 'B-Erinnerung');

		// Kontrolle: vorher alle UID-Spalten von C belegt (sonst wäre der Test wirkungslos)
		$before = [];
		foreach (self::UID_COLUMNS as $t => $cols) {
			foreach ($cols as $col) {
				$before["$t.$col"] = self::countEq($t, $col, $cUid);
			}
		}
		self::assertSame([], array_keys(array_filter($before, static fn ($n) => $n === 0)), 'Kontrolle: UID-Spalten ohne C-Wert vor Löschung');

		$oldWs = self::ownedWorkspaceIds($cUid);
		$oldNotes = array_map(static fn ($r) => (int)$r['id'], self::dump($cUid)['notes']);
		self::assertContains($wsCId, $oldWs);
		self::assertContains($nC, $oldNotes);
		$aExpected = self::expectedAfter(self::dump($a->uid), $cUid);
		$jobsBefore = count(self::deletionJobs());

		// Kontolöschung über IUserManager → UserDeletedEvent
		self::assertTrue(Server::get(IUserManager::class)->get($cUid)->delete(), 'Kontolöschung');

		// Sofort (vor Job): keine UID-Spalte enthält C; C's Workspaces owner_type=deleted mit Löschkennung, Job eingereiht
		self::assertSame([], self::uidHits($cUid), 'UID-Reste direkt nach UserDeletedEvent');
		foreach (self::rowsById('nextnotes_workspaces', $oldWs) as $row) {
			self::assertSame('deleted', $row['owner_type'], 'owner_type nach Löschung');
			self::assertMatchesRegularExpression('/^#[0-9a-f]{16}$/', (string)$row['owner_id'], 'Löschkennung §2.1');
		}
		$jobsNow = self::deletionJobs();
		self::assertCount($jobsBefore + 1, $jobsNow, 'UserDataDeletionJob nicht eingereiht');
		$jobArg = end($jobsNow)[1];
		self::assertStringNotContainsString($cUid, json_encode($jobArg), 'Job-Argument enthält UID');
		self::assertSame(0, self::countIn('nextnotes_ws_shares', 'workspace_id', [$wsCId]), 'Freigabe von C an B');
		self::assertStringNotContainsString($mk, $b->call('GET', '/workspaces')->body, 'B sieht C-Workspace');
		self::assertContains($b->call('GET', "/notes/$nC")->status, [403, 404], 'B liest C-Notiz');
		self::assertSame(self::expectedAfter(self::dump($a->uid), $cUid), $aExpected, 'A-Daten nach Listener');

		// UID-Wiederverwendung VOR Joblauf
		$c2 = $this->newUserC($cUid);
		$wsList = self::ok($c2->call('GET', '/workspaces'), 'neuer C workspaces')['workspaces'];
		self::assertCount(1, $wsList, 'neuer C: nur Standard-Workspace');
		$newWs = self::ownedWorkspaceIds($cUid);
		self::assertCount(1, $newWs, 'neuer C: genau ein eigener Workspace');
		self::assertSame([], array_intersect($newWs, $oldWs), 'neuer C erbt alten Workspace');
		foreach (['/workspaces', '/notes', '/notes/search?q=' . $mk, '/notes/search/ids?q=' . $mk, '/tags', '/reminders', '/reminders/count',
			'/mentions?include_handled=1', '/trash', '/tasks', '/graph', '/notes/with-attachments', '/folders', '/shared', '/shared/with-me'] as $p) {
			$r = $c2->call('GET', $p);
			self::assertLessThan(500, $r->status, "$p: " . $r->describe());
			// Suche spiegelt den Suchbegriff im Feld "query" → vor dem Vergleich entfernen
			$body = (string)preg_replace('/"query":"[^"]*"/', '', $r->body);
			self::assertStringNotContainsString($mk, $body, "neuer C sieht Altdaten über $p");
		}
		foreach ([$keyA, $wsA, "$wsA (Anna Del)", "$wsA (Anna Del, #$wsAId)"] as $key) {
			$r = $c2->call('GET', '/notes' . self::q(['workspace' => $key]));
			self::assertSame(404, $r->status, "A-Workspace über Schlüssel $key: " . $r->describe());
		}
		foreach (["/notes/$nA", "/notes/$nCinA", "/notes/$nC", "/notes/$nA/attachments/$attCinA", "/notes/$nC/attachments/$attC", "/notes/$nC/lock", "/notes/$nCinA/lock", "/notes/$nA/reminder"] as $p) {
			$r = $c2->call('GET', $p);
			self::assertSame(404, $r->status, "neuer C: $p " . $r->describe());
		}
		self::assertSame(404, $c2->call('PATCH', "/notes/$nA", ['content' => '<p>x</p>'])->status, 'neuer C schreibt in A-Notiz');
		self::assertSame(404, $c2->call('POST', '/notes', ['heading' => 'x', 'workspace' => $keyA])->status, 'neuer C legt in A-Workspace an');

		// alte Erinnerungen: ReminderJob (Zeit nach Fälligkeit) erzeugt für C nichts, B-Kontrolle genau eine
		$this->reminderJobAt(strtotime('2031-01-01T00:00:00Z'));
		self::assertSame(0, self::nextnotesNotifications($cUid), 'alte Erinnerung von C erzeugt Benachrichtigung');
		self::assertSame(1, self::nextnotesNotifications($b->uid), 'Kontrolle: B-Erinnerung');

		// neuer C legt Daten an
		$newKey = $wsList[0]['name'];
		$nNew = self::newNote($c2, $newKey, 'Neu', '<p>neu</p>');
		$attNew = (int)self::ok($c2->upload("/notes/$nNew/attachments", 'n.txt', 'neu-bytes'), 'neuer Anhang')['attachment_id'];
		$c2Dump = self::dump($cUid);

		// Joblauf wie Cron (aus IJobList)
		self::assertGreaterThanOrEqual(1, $this->runDeletionJobs(), 'kein Job ausgeführt');
		self::assertSame(0, self::countIn('nextnotes_workspaces', 'id', $oldWs), 'alte C-Workspaces');
		self::assertSame(0, self::countEq('nextnotes_workspaces', 'owner_type', 'deleted'), 'Zeilen owner_type=deleted übrig');
		foreach (['nextnotes_folders', 'nextnotes_notes', 'nextnotes_ws_shares'] as $t) {
			self::assertSame(0, self::countIn($t, 'workspace_id', $oldWs), "$t in alten C-Workspaces");
		}
		foreach (['nextnotes_notes' => 'id', 'nextnotes_note_tags' => 'note_id', 'nextnotes_attachments' => 'note_id', 'nextnotes_snapshots' => 'note_id',
			'nextnotes_reminders' => 'note_id', 'nextnotes_locks' => 'note_id', 'nextnotes_mentions_handled' => 'note_id'] as $t => $col) {
			self::assertSame(0, self::countIn($t, $col, $oldNotes), "$t zu alten C-Notizen");
		}
		self::assertDirectoryDoesNotExist(self::attachmentDir($nC), 'IAppData: C-Anhangsordner');
		self::assertFileExists(self::attachmentDir($nA) . '/' . $attCinA, 'Anhang von C an A-Notiz gehört zu A, bleibt');
		self::assertSame($aExpected, self::expectedAfter(self::dump($a->uid), $cUid), 'A-Daten nach Job');
		$nCinARow = self::rowsById('nextnotes_notes', [$nCinA]);
		self::assertCount(1, $nCinARow, 'C-Notiz in A-Workspace bleibt');
		self::assertNull($nCinARow[0]['created_by']);
		self::assertSame($c2Dump, self::dump($cUid), 'neuer C nach Job verändert');
		self::assertFileExists(self::attachmentDir($nNew) . '/' . $attNew, 'neue C-Datei');
		self::assertSame('neu-bytes', $c2->call('GET', "/notes/$nNew/attachments/$attNew")->body);
		self::assertSame(404, $c2->call('GET', '/notes' . self::q(['workspace' => $keyA]))->status, 'nach Job: A-Workspace');
		// außer den neuen Daten trägt keine Zeile die UID: neue Notiz/Anhang/Workspace sind die einzigen Treffer
		foreach (self::uidHits($cUid) as $hit) {
			self::assertMatchesRegularExpression('/^nextnotes_(workspaces\.(owner_id|created_by)|notes\.(created_by|updated_by)|attachments\.created_by) \(1\)$/', $hit, 'unerwartete UID-Zeile nach Job');
		}

		// Job zweimal: gleiche Argumente erneut → kein Fehler, nichts verändert
		$jl = Server::get(IJobList::class);
		$jl->add(UserDataDeletionJob::class, $jobArg);
		self::assertGreaterThanOrEqual(1, $this->runDeletionJobs());
		self::assertSame($c2Dump, self::dump($cUid), 'zweiter Job');
		self::assertSame($aExpected, self::expectedAfter(self::dump($a->uid), $cUid), 'A nach zweitem Job');
	}

	// SPEC §5 DeletedWorkspaceSweepJob: abgebrochener UserDataDeletionJob → Nachlauf räumt Reste; Budget/Deadline; registriert
	public function testSweepCleansAbortedDeletion(): void {
		$cUid = 'nn-sw-' . bin2hex(random_bytes(4));
		$c = $this->newUserC($cUid);
		$atts = [];
		$wsIds = [];
		for ($i = 0; $i < 3; $i++) {
			[$wsName, $wsId] = self::newWorkspace($c, "Sw$i");
			$wsIds[] = $wsId;
			$n = self::newNote($c, $wsName, "S$i", '<p>s</p>');
			$att = (int)self::ok($c->upload("/notes/$n/attachments", "s$i.txt", "s$i"), "Anhang $i")['attachment_id'];
			$atts[$n] = $att;
			self::assertFileExists(self::attachmentDir($n) . '/' . $att, 'Kontrolle: Datei');
		}
		$allWs = self::ownedWorkspaceIds($cUid);
		self::assertTrue(Server::get(IJobList::class)->has(\OCA\NextNotes\BackgroundJob\DeletedWorkspaceSweepJob::class, null), 'DeletedWorkspaceSweepJob nicht in oc_jobs');
		self::assertTrue(Server::get(IUserManager::class)->get($cUid)->delete(), 'Kontolöschung');
		$rows = self::rowsById('nextnotes_workspaces', $allWs);
		self::assertCount(count($allWs), $rows);
		$tokens = array_values(array_unique(array_map(static fn ($r) => (string)$r['owner_id'], $rows)));
		self::assertCount(1, $tokens, 'eine Löschkennung je Konto');
		$token = $tokens[0];
		// Queued Job entfernen: simuliert, dass NC ihn vor dem (abgebrochenen) Lauf aus der Liste genommen hat
		foreach (self::deletionJobs() as [$job, $arg]) {
			if (($arg['token'] ?? null) === $token) {
				Server::get(IJobList::class)->remove(UserDataDeletionJob::class, $arg);
			}
		}

		// Abbruch nach dem ersten Workspace: NoteService::purge wirft für die Notizen des zweiten Workspace
		$secondNotes = self::noteIdsIn([$allWs[1]]);
		$crashing = $this->userDataService($this->failingNoteService($secondNotes, 'Abbruch im Test'), Server::get(ITimeFactory::class), Server::get(\Psr\Log\LoggerInterface::class));
		try {
			$crashing->purgeWorkspaces($token);
			self::fail('Abbruch nicht ausgelöst');
		} catch (\RuntimeException $e) {
			self::assertSame('Abbruch im Test', $e->getMessage());
		}
		$left = self::rowsById('nextnotes_workspaces', $allWs);
		self::assertCount(count($allWs) - 1, $left, 'nach Abbruch: genau ein Workspace weg');
		foreach ($left as $r) {
			self::assertSame('deleted', $r['owner_type']);
		}
		$leftIds = array_map(static fn ($r) => (int)$r['id'], $left);
		$filesLeft = 0;
		foreach ($atts as $n => $att) {
			$filesLeft += is_file(self::attachmentDir($n) . '/' . $att) ? 1 : 0;
		}
		self::assertSame(count($allWs) - 1, $filesLeft, 'nach Abbruch: Dateien der übrigen Workspaces noch da');

		// sweep mit Zeit bereits nach Deadline → löscht nichts
		$t = time();
		$calls = 0;
		$late = $this->createStub(ITimeFactory::class);
		$late->method('getTime')->willReturnCallback(static function () use (&$calls, $t) {
			return $calls++ === 0 ? $t : $t + 301;
		});
		$late->method('getDateTime')->willReturnCallback(static fn () => new \DateTime());
		self::assertSame(0, $this->userDataService(Server::get(\OCA\NextNotes\Service\NoteService::class), $late, Server::get(\Psr\Log\LoggerInterface::class))->sweep(300), 'sweep nach Deadline löscht');
		self::assertCount(count($left), self::rowsById('nextnotes_workspaces', $leftIds), 'sweep nach Deadline hat gelöscht');

		// echter Nachlauf über den registrierten Job
		Server::get(\OCA\NextNotes\BackgroundJob\DeletedWorkspaceSweepJob::class)->start(Server::get(IJobList::class));
		self::assertSame(0, self::countEq('nextnotes_workspaces', 'owner_type', 'deleted'), 'deleted-Zeilen nach Sweep');
		self::assertSame(0, self::countIn('nextnotes_workspaces', 'id', $allWs));
		self::assertSame(0, self::countIn('nextnotes_notes', 'id', array_keys($atts)));
		foreach ($atts as $n => $att) {
			self::assertDirectoryDoesNotExist(self::attachmentDir($n), "IAppData nach Sweep (Notiz $n)");
		}
		foreach (['nextnotes_attachments', 'nextnotes_snapshots', 'nextnotes_note_tags'] as $tb) {
			self::assertSame(0, self::countIn($tb, 'note_id', array_keys($atts)), "$tb nach Sweep");
		}
		self::assertSame([], self::uidHits($cUid), 'UID-Reste');
	}

	// SPEC §5 sweep: Fehler je Kennung protokolliert, nächste Kennung läuft weiter, fehlerhafte zurückgerollt
	public function testSweepContinuesAfterFailingToken(): void {
		$files = [];
		$ws = [];
		$uids = [];
		foreach (['s1', 's2'] as $r) {
			$uid = 'nn-' . $r . '-' . bin2hex(random_bytes(4));
			$uids[$r] = $uid;
			$c = $this->newUserC($uid);
			for ($i = 0; $i < 2; $i++) {
				[$name] = self::newWorkspace($c, "Sk$i");
				$n = self::newNote($c, $name, "K$i", '<p>k</p>');
				$files[$r][$n] = (int)self::ok($c->upload("/notes/$n/attachments", "k$i.txt", "k$i"), 'Anhang')['attachment_id'];
			}
			$ws[$r] = self::ownedWorkspaceIds($uid);
			self::assertTrue(Server::get(IUserManager::class)->get($uid)->delete(), "Kontolöschung $uid");
		}
		// Queued Jobs entfernen: nur der Nachlauf soll löschen
		foreach (self::deletionJobs() as [$job, $arg]) {
			Server::get(IJobList::class)->remove(UserDataDeletionJob::class, $arg);
		}
		$tok = [];
		foreach ($ws as $r => $ids) {
			$tok[$r] = (string)self::rowsById('nextnotes_workspaces', $ids)[0]['owner_id'];
		}
		// erste Kennung (nach owner_id sortiert) schlägt dauerhaft fehl
		$sorted = $tok;
		asort($sorted, SORT_STRING);
		$failR = array_key_first($sorted);
		$okR = $failR === 's1' ? 's2' : 's1';
		// andere deleted-Kennungen (Reste anderer Tests) dürfen nicht vor der fehlerhaften liegen
		$qb = self::db()->getQueryBuilder();
		$qb->selectDistinct('owner_id')->from('nextnotes_workspaces')->where($qb->expr()->eq('owner_type', $qb->createNamedParameter('deleted')))->orderBy('owner_id');
		self::assertSame(array_values($sorted), array_map('strval', $qb->executeQuery()->fetchFirstColumn()), 'unerwartete deleted-Kennungen');
		$failNotes = self::noteIdsIn($ws[$failR]);
		// je Workspace eine Transaktion (SPEC §5): leere Workspaces vor dem fehlschlagenden dürfen schon weg sein; geprüft werden die mit Notizen
		$failWs = array_values(array_unique(array_map(static fn ($r) => (int)$r['workspace_id'], self::rowsById('nextnotes_notes', $failNotes))));
		sort($failWs);
		$failRowsBefore = self::rowsById('nextnotes_workspaces', $failWs);

		$logged = [];
		$log = $this->createStub(\Psr\Log\LoggerInterface::class);
		$log->method('error')->willReturnCallback(static function ($msg, array $ctx = []) use (&$logged) {
			$logged[] = [(string)$msg, $ctx['token'] ?? null];
		});
		$svc = $this->userDataService($this->failingNoteService($failNotes, 'dauerhaft kaputt'), Server::get(ITimeFactory::class), $log);
		$svc->sweep(300);

		self::assertSame(0, self::countIn('nextnotes_workspaces', 'id', $ws[$okR]), 'Workspaces der zweiten Kennung nach sweep');
		foreach ($files[$okR] as $n => $att) {
			self::assertDirectoryDoesNotExist(self::attachmentDir($n), "Dateien der zweiten Kennung (Notiz $n)");
		}
		self::assertSame(0, self::countIn('nextnotes_notes', 'id', array_keys($files[$okR])));
		self::assertEquals($failRowsBefore, self::rowsById('nextnotes_workspaces', $failWs), 'fehlerhafte Kennung: deleted-Zeilen verändert');
		self::assertCount(count($failNotes), self::rowsById('nextnotes_notes', $failNotes), 'fehlerhafte Kennung: Notizen nicht zurückgerollt');
		foreach ($files[$failR] as $n => $att) {
			self::assertFileExists(self::attachmentDir($n) . '/' . $att, 'fehlerhafte Kennung: Datei gelöscht');
		}
		self::assertContains($tok[$failR], array_column($logged, 1), 'kein Log-Eintrag für fehlerhafte Kennung: ' . json_encode($logged));
		self::assertNotContains($tok[$okR], array_column($logged, 1), 'Log für erfolgreiche Kennung');

		// Aufräumen über den echten Pfad
		Server::get(\OCA\NextNotes\BackgroundJob\DeletedWorkspaceSweepJob::class)->start(Server::get(IJobList::class));
		self::assertSame(0, self::countEq('nextnotes_workspaces', 'owner_type', 'deleted'));
	}

	/** NoteService, dessen purge für die genannten Notizen wirft, sonst an den echten Dienst weiterreicht */
	private function failingNoteService(array $failIds, string $msg): \OCA\NextNotes\Service\NoteService {
		$real = Server::get(\OCA\NextNotes\Service\NoteService::class);
		$mock = $this->createStub(\OCA\NextNotes\Service\NoteService::class);
		$mock->method('purge')->willReturnCallback(static function (array $ids) use ($real, $failIds, $msg): void {
			if (array_intersect(array_map('intval', $ids), $failIds) !== []) {
				throw new \RuntimeException($msg);
			}
			$real->purge($ids);
		});
		return $mock;
	}

	/** @return int[] */
	private static function noteIdsIn(array $wsIds): array {
		$qb = self::db()->getQueryBuilder();
		$qb->select('id')->from('nextnotes_notes')
			->where($qb->expr()->in('workspace_id', $qb->createNamedParameter($wsIds, IQueryBuilder::PARAM_INT_ARRAY)));
		return array_map('intval', $qb->executeQuery()->fetchFirstColumn());
	}

	private function userDataService(\OCA\NextNotes\Service\NoteService $notes, ITimeFactory $time, \Psr\Log\LoggerInterface $log): \OCA\NextNotes\Service\UserDataService {
		return new \OCA\NextNotes\Service\UserDataService(
			self::db(), Server::get(\OCA\NextNotes\Service\Transaction::class), Server::get(IJobList::class), $notes,
			Server::get(WorkspaceService::class), Server::get(\OCA\NextNotes\Db\NoteMapper::class), Server::get(\OCA\NextNotes\Db\FolderMapper::class),
			Server::get(\OCA\NextNotes\Db\WsShareMapper::class), Server::get(\OCA\NextNotes\Db\WorkspaceMapper::class), $time, $log,
			Server::get(\OCA\NextNotes\Service\BackgroundFiles::class),
		);
	}

	private ?int $now = null;

	private function reminderJobAt(int $now): void {
		Server::get(IAppManager::class)->loadApp('notifications');
		$this->now = $now;
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn () => $this->now);
		$service = new \OCA\NextNotes\Service\ReminderService(
			Server::get(\OCA\NextNotes\Db\ReminderMapper::class), Server::get(\OCA\NextNotes\Service\AccessService::class), Server::get(\OCA\NextNotes\Service\TaskService::class),
			Server::get(\OCP\Notification\IManager::class), $time, Server::get(\OCP\IDateTimeZone::class), Server::get(\Psr\Log\LoggerInterface::class),
		);
		(new ReminderJob($time, $service))->start(Server::get(IJobList::class));
	}

	/** @return list<array<string, mixed>> */
	private static function rowsById(string $table, array $ids): array {
		if ($ids === []) {
			return [];
		}
		$qb = self::db()->getQueryBuilder();
		$qb->select('*')->from($table)
			->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
			->orderBy('id');
		return $qb->executeQuery()->fetchAllAssociative();
	}
}
