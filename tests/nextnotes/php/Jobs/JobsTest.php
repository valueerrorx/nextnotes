<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Jobs;

use OCA\NextNotes\BackgroundJob\LockCleanupJob;
use OCA\NextNotes\BackgroundJob\SnapshotRetentionJob;
use OCA\NextNotes\Db\LockMapper;
use OCA\NextNotes\Db\Snapshot;
use OCA\NextNotes\Db\SnapshotMapper;
use OCA\NextNotes\Db\WorkspaceMapper;
use OCA\NextNotes\Service\AccessService;
use OCA\NextNotes\Service\ContentUrlRewriter;
use OCA\NextNotes\Service\LockService;
use OCA\NextNotes\Service\NoteService;
use OCA\NextNotes\Service\SettingsService;
use OCA\NextNotes\Service\SnapshotService;
use OCA\NextNotes\Service\TagService;
use OCA\NextNotes\Service\Transaction;
use OCA\NextNotes\Tests\Support\ApiClient;
use OCA\NextNotes\Tests\Support\ApiTestCase;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDateTimeZone;
use OCP\IUserManager;
use OCP\Server;

// SPEC §5 (LockCleanupJob, SnapshotRetentionJob), Plan Done M2b 5: Jobs per start() mit gemockter Zeit; endgültiges Löschen
final class JobsTest extends ApiTestCase {
	private int $now = 0;

	private function time(): ITimeFactory {
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn () => $this->now);
		return $time;
	}

	private static function rowCount(string $table, string $col, int $id): int {
		$qb = self::db()->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from($table)->where($qb->expr()->eq($col, $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return (int)$qb->executeQuery()->fetchOne();
	}

	/** @return int[] Snapshot-IDs der Notiz */
	private static function snapIds(int $noteId): array {
		$qb = self::db()->getQueryBuilder();
		$qb->select('id')->from('nextnotes_snapshots')->where($qb->expr()->eq('note_id', $qb->createNamedParameter($noteId, IQueryBuilder::PARAM_INT)))->orderBy('id');
		return array_map('intval', $qb->executeQuery()->fetchFirstColumn());
	}

	private static function attachmentDir(int $noteId): string {
		$cfg = Server::get(IConfig::class);
		return rtrim((string)$cfg->getSystemValueString('datadirectory'), '/') . '/appdata_' . $cfg->getSystemValueString('instanceid') . '/nextnotes/attachments/' . $noteId;
	}

	private function snapshot(int $noteId, string $kind, int $createdAt): int {
		$s = new Snapshot();
		$s->setNoteId($noteId);
		$s->setHeading('h');
		$s->setContent('<p>c</p>');
		$s->setType('note');
		$s->setKind($kind);
		$s->setCreatedBy(null);
		$s->setCreatedAt($createdAt);
		return (int)Server::get(SnapshotMapper::class)->insert($s)->getId();
	}

	/** Notiz mit Anhang und Snapshot; liefert [Notiz-ID, Anhang-ID] */
	private static function noteWithData(ApiClient $a, string $ws, string $heading): array {
		$n = self::newNote($a, $ws, $heading, '<p>inhalt</p>');
		$att = (int)self::ok($a->upload("/notes/$n/attachments", 'a.txt', 'bytes'), 'Anhang')['attachment_id'];
		self::ok($a->call('POST', "/notes/$n/snapshot?manual=1"), 'Snapshot');
		self::assertFileExists(self::attachmentDir($n) . '/' . $att, 'Kontrolle: Datei in IAppData');
		self::assertGreaterThan(0, self::rowCount('nextnotes_snapshots', 'note_id', $n));
		return [$n, $att];
	}

	public function testJobsRegistered(): void {
		$jobs = Server::get(IJobList::class);
		self::assertTrue($jobs->has(LockCleanupJob::class, null), 'LockCleanupJob nicht registriert');
		self::assertTrue($jobs->has(SnapshotRetentionJob::class, null), 'SnapshotRetentionJob nicht registriert');
	}

	public function testLockCleanupJobDeletesOnlyExpired(): void {
		$a = $this->createUser('ja');
		[$ws] = self::newWorkspace($a, 'Job');
		$old = self::newNote($a, $ws, 'Alt', '<p>a</p>');
		$live = self::newNote($a, $ws, 'Gültig', '<p>b</p>');
		$edge = self::newNote($a, $ws, 'Grenze', '<p>c</p>');
		$this->now = time() + 100000;
		$mapper = Server::get(LockMapper::class);
		$mapper->create($old, $a->uid, 's-old', $this->now - 200, $this->now - 110);
		$mapper->create($live, $a->uid, 's-live', $this->now - 10, $this->now + 80);
		$mapper->create($edge, $a->uid, 's-edge', $this->now - 90, $this->now);

		$time = $this->time();
		$job = new LockCleanupJob($time, new LockService($mapper, Server::get(AccessService::class), $time, Server::get(IUserManager::class)));
		$job->start(Server::get(IJobList::class));

		self::assertSame(0, self::rowCount('nextnotes_locks', 'note_id', $old), 'abgelaufene Sperre bleibt');
		self::assertSame(0, self::rowCount('nextnotes_locks', 'note_id', $edge), 'Sperre mit expires_at = now bleibt');
		self::assertSame(1, self::rowCount('nextnotes_locks', 'note_id', $live), 'gültige Sperre gelöscht');
		// run() direkt: Ausnahmen würden start() verschluckt
		(new \ReflectionMethod($job, 'run'))->invoke($job, null);
		self::assertSame(1, self::rowCount('nextnotes_locks', 'note_id', $live));
	}

	public function testSnapshotRetentionJobDeletesOnlyExpired(): void {
		$a = $this->createUser('jr');
		[$ws] = self::newWorkspace($a, 'Snap');
		$n = self::newNote($a, $ws, 'Mit Snapshots', '<p>s</p>');
		foreach (self::snapIds($n) as $id) {
			Server::get(SnapshotMapper::class)->deleteByIds([$id]);
		}
		$this->now = $now = time();
		$day = 86400;
		$tooOld = $this->snapshot($n, 'auto', $now - 31 * $day);
		$recentManual = $this->snapshot($n, 'manual', $now - 2 * $day);
		$recentAuto = $this->snapshot($n, 'auto', $now - 3600);
		// zwei automatische am selben UTC-Tag, älter als 24 h → der neueste des Tages bleibt
		$dayStart = intdiv($now - 5 * $day, $day) * $day;
		$sameDayOld = $this->snapshot($n, 'auto', $dayStart + 3600);
		$sameDayNew = $this->snapshot($n, 'auto', $dayStart + 7200);
		$orphan = $this->snapshot(2000000000 + random_int(0, 1000000), 'manual', $now - 60);

		$job = new SnapshotRetentionJob($this->time(), $this->snapshotService());
		$job->start(Server::get(IJobList::class));

		$left = self::snapIds($n);
		self::assertNotContains($tooOld, $left, 'Snapshot älter als 30 Tage bleibt');
		self::assertNotContains($sameDayOld, $left, 'Ausdünnen: älterer Auto-Snapshot desselben Tages bleibt');
		self::assertContains($sameDayNew, $left, 'Ausdünnen: neuester des Tages gelöscht');
		self::assertContains($recentManual, $left, 'manueller Snapshot (2 Tage) gelöscht');
		self::assertContains($recentAuto, $left, 'Auto-Snapshot der letzten 24 h gelöscht');
		self::assertSame(0, self::rowCount('nextnotes_snapshots', 'id', $orphan), 'verwaister Snapshot bleibt');
		(new \ReflectionMethod($job, 'run'))->invoke($job, null);
		self::assertSame($left, self::snapIds($n), 'zweiter Lauf löscht weiter');
	}

	private function snapshotService(): SnapshotService {
		return new SnapshotService(
			Server::get(SnapshotMapper::class), Server::get(AccessService::class), Server::get(TagService::class),
			Server::get(SettingsService::class), Server::get(WorkspaceMapper::class), Server::get(ContentUrlRewriter::class),
			$this->time(), Server::get(IDateTimeZone::class),
		);
	}

	// Benutzereinstellung snapshots_keep_count des Workspace-Besitzers: Ausdünnen auf N Tage statt 30
	public function testSnapshotRetentionUsesOwnerKeepCount(): void {
		$a = $this->createUser('jk');
		$c = $this->createUser('jl');
		[$wsA] = self::newWorkspace($a, 'Keep');
		[$wsC] = self::newWorkspace($c, 'KeepC');
		$nA = self::newNote($a, $wsA, 'Keep A', '<p>a</p>');
		$nC = self::newNote($c, $wsC, 'Keep C', '<p>c</p>');
		Server::get(SnapshotMapper::class)->deleteForNotes([$nA, $nC]);
		Server::get(SettingsService::class)->set($a->uid, 'snapshots_keep_count', '2');
		$this->now = $now = time();
		$day = 86400;
		$ids = [];
		foreach ([$nA, $nC] as $n) {
			for ($d = 2; $d <= 5; $d++) {
				$ids[$n][$d] = $this->snapshot($n, 'auto', intdiv($now - $d * $day, $day) * $day + 43200);
			}
		}
		(new SnapshotRetentionJob($this->time(), $this->snapshotService()))->start(Server::get(IJobList::class));
		self::assertSame([$ids[$nA][3], $ids[$nA][2]], array_reverse(self::snapIds($nA)), 'A (keep 2): nur die zwei neuesten Tage');
		self::assertCount(4, self::snapIds($nC), 'C (Standard 30): alle vier Tage');
	}

	public function testPurgeRemovesFilesAndSnapshots(): void {
		$a = $this->createUser('jp');
		$b = $this->createUser('jq');
		[$ws] = self::newWorkspace($a, 'Purge');
		[$n1, $att1] = self::noteWithData($a, $ws, 'Einzeln');
		[$n2] = self::noteWithData($a, $ws, 'Leeren');
		[$keep, $attKeep] = self::noteWithData($a, $ws, 'Bleibt');

		// Fremder kann nicht endgültig löschen
		self::ok($a->call('DELETE', "/notes/$n1"), 'Papierkorb');
		self::assertSame(404, $b->call('DELETE', "/trash/$n1")->status);
		self::assertFileExists(self::attachmentDir($n1) . '/' . $att1);

		self::ok($a->call('DELETE', "/trash/$n1"), 'endgültig löschen');
		self::assertDirectoryDoesNotExist(self::attachmentDir($n1), 'IAppData-Ordner nach Purge');
		self::assertSame(0, self::rowCount('nextnotes_snapshots', 'note_id', $n1), 'Snapshots nach Purge');
		self::assertSame(0, self::rowCount('nextnotes_attachments', 'note_id', $n1), 'Anhang-Zeilen nach Purge');

		self::ok($a->call('DELETE', "/notes/$n2"), 'Papierkorb 2');
		self::ok($a->call('DELETE', '/trash'), 'Papierkorb leeren');
		self::assertDirectoryDoesNotExist(self::attachmentDir($n2));
		self::assertSame(0, self::rowCount('nextnotes_snapshots', 'note_id', $n2));

		// Nicht betroffene Notiz behält alles
		self::assertFileExists(self::attachmentDir($keep) . '/' . $attKeep);
		self::assertGreaterThan(0, self::rowCount('nextnotes_snapshots', 'note_id', $keep));
	}

	public function testPurgeRollbackKeepsFiles(): void {
		$a = $this->createUser('jx');
		[$ws] = self::newWorkspace($a, 'Rollback');
		[$n, $att] = self::noteWithData($a, $ws, 'Rollback');
		$tx = Server::get(Transaction::class);
		$notes = Server::get(NoteService::class);
		try {
			$tx->run(function () use ($notes, $n): void {
				$notes->purge([$n]);
				throw new \RuntimeException('erzwungener Rollback');
			});
			self::fail('keine Ausnahme');
		} catch (\RuntimeException $e) {
			self::assertSame('erzwungener Rollback', $e->getMessage());
		}
		self::assertFileExists(self::attachmentDir($n) . '/' . $att, 'Datei nach Rollback gelöscht');
		self::assertSame(1, self::rowCount('nextnotes_notes', 'id', $n), 'Notiz nach Rollback weg');
		self::assertGreaterThan(0, self::rowCount('nextnotes_snapshots', 'note_id', $n), 'Snapshots nach Rollback weg');
		self::assertSame('bytes', $a->call('GET', "/notes/$n/attachments/$att")->body);

		// ohne Rollback: Datei nach dem Commit weg
		$notes->purge([$n]);
		self::assertDirectoryDoesNotExist(self::attachmentDir($n));
		self::assertSame(0, self::rowCount('nextnotes_notes', 'id', $n));
	}
}
