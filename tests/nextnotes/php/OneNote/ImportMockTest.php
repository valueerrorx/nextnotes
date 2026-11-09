<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\OneNote;

use OCA\NextNotes\Db\OneNoteImportMapper;
use OCA\NextNotes\OneNote\ConnectService;
use OCA\NextNotes\OneNote\OneNoteImportService;
use OCA\NextNotes\Service\NoteService;
use OCP\IUserManager;
use OCP\Server;
use PHPUnit\Framework\Attributes\Group;

// M9b gegen den Mock (Profil msmock) mit echten Job-Prozessen: occ ohne Sitzung (3a), Paging, zwei parallele Läufe (4a),
// cancel während einer angehaltenen Antwort (4b, < 2 s), Kontolöschung (4c), SIGKILL mit 12-h-Reservierung (4)
#[Group('msmock')]
final class ImportMockTest extends OneNoteTestCase {
	private const OCC = '/var/www/html/occ';
	private const GRAPH = 'graph.microsoft.com/v1.0/me/onenote/';

	protected function setUp(): void {
		parent::setUp();
		self::ctl('op=reset');
		// andere offene Importe (frühere Läufe) dürfen die Job-Läufe nicht belegen
		self::db()->getQueryBuilder()->delete(OneNoteImportMapper::TABLE)->executeStatement();
	}

	/** @return array<string, mixed> */
	private static function ctl(string $query): array {
		$ch = curl_init('http://ms-mock/__ctl?' . $query);
		curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
		$body = curl_exec($ch);
		self::assertIsString($body, 'Mock-Steuerung erreichbar (msmock.sh up)');
		return (array)json_decode($body, true);
	}

	private static function hits(string $path): int {
		return (int)(self::ctl('op=counts')['counts'][self::GRAPH . $path] ?? 0);
	}

	private static function jobId(): int {
		$qb = self::db()->getQueryBuilder();
		$qb->select('id')->from('jobs')->where($qb->expr()->eq('class', $qb->createNamedParameter('OCA\\NextNotes\\BackgroundJob\\OneNoteImportJob')));
		$id = $qb->executeQuery()->fetchOne();
		self::assertNotFalse($id, 'OneNoteImportJob registriert');
		return (int)$id;
	}

	/** @return resource */
	private static function spawn(string $cmd) {
		$p = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
		self::assertIsResource($p);
		return $p;
	}

	private static function execute(): void {
		exec('php ' . self::OCC . ' background-job:execute ' . self::jobId() . ' --force-execute 2>&1', $out, $rc);
		self::assertSame(0, $rc, implode("\n", $out));
	}

	private static function waitFor(callable $cond, float $seconds = 60.0): void {
		$until = microtime(true) + $seconds;
		while (!$cond()) {
			self::assertLessThan($until, microtime(true), 'Zeitüberschreitung');
			usleep(200_000);
		}
	}

	private function begin(string $uid, int $pages): int {
		self::ctl('op=set&pages=' . $pages);
		$svc = Server::get(ConnectService::class);
		$c = $svc->connect($uid, null);
		$r = $svc->callback($uid, self::stateOf($c['authorize_url']), 'mock.sub-job', '');
		self::assertSame(['import' => $c['import_id']], $r->query);
		$tree = Server::get(OneNoteImportService::class)->tree($uid, $c['import_id']);
		self::assertSame('nb1', $tree['notebooks'][0]['id']);
		Server::get(OneNoteImportService::class)->start($uid, $c['import_id'], [['root' => 'me', 'notebook' => 'nb1', 'items' => null]]);
		return $c['import_id'];
	}

	private static function noteRows(string $uid): array {
		$ws = self::ownedWorkspaceIds($uid);
		$qb = self::db()->getQueryBuilder();
		$qb->select('id', 'heading', 'created_by')->from('nextnotes_notes')
			->where($qb->expr()->in('workspace_id', $qb->createNamedParameter($ws ?: [0], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
		return $qb->executeQuery()->fetchAllAssociative();
	}

	private function runToEnd(int $id, string $uid, int $max = 8): void {
		for ($i = 0; $i < $max && in_array(self::row($id)['status'], ['queued', 'running'], true); $i++) {
			Server::get(OneNoteImportMapper::class)->updateWhere($id, $uid, ['next_run_at' => 0]);
			self::execute();
		}
		self::assertSame('done', self::row($id)['status']);
	}

	// Done 3, 3a: occ ohne Sitzung, Paging > 100, Besitz = uid der Zeile, Seiten genau einmal
	public function testOccJobWithPaging(): void {
		$u = $this->createUser('mj');
		$id = $this->begin($u->uid, 105);
		$this->runToEnd($id, $u->uid);
		$notes = self::noteRows($u->uid);
		self::assertCount(107, $notes);
		self::assertSame([$u->uid], array_values(array_unique(array_column($notes, 'created_by'))));
		self::assertSame(2, self::hits('sections/s1/pages'));
		self::assertSame(1, self::hits('pages/p-104/content'));
		self::assertCount(1, self::ownedWorkspaceIds($u->uid), 'Import-Workspace');
		self::assertNull(self::row($id)['access_enc']);
	}

	// Done 4a: zwei parallele force-execute → genau einer übernimmt, jede Seite einmal
	public function testParallelExecute(): void {
		$u = $this->createUser('mp');
		$id = $this->begin($u->uid, 6);
		$cmd = 'php ' . self::OCC . ' background-job:execute ' . self::jobId() . ' --force-execute';
		$a = self::spawn($cmd);
		$b = self::spawn($cmd);
		self::waitFor(static fn () => !proc_get_status($a)['running'] && !proc_get_status($b)['running'], 120);
		proc_close($a);
		proc_close($b);
		$this->runToEnd($id, $u->uid);
		self::assertCount(8, self::noteRows($u->uid));
		foreach (['p-0', 'p-5', 'q-1'] as $p) {
			self::assertSame(1, self::hits("pages/$p/content"), $p);
		}
	}

	// Done 4b: cancel während der Mock eine Seite anhält → < 2 s; danach keine Schreibwirkung, keine Anfrage
	public function testCancelWhileHeld(): void {
		$u = $this->createUser('mc');
		$other = $this->createUser('mo');
		$id = $this->begin($u->uid, 5);
		$held = self::GRAPH . 'pages/p-2/content';
		self::ctl('op=hold&path=' . rawurlencode('graph.microsoft.com/v1.0/me/onenote/pages/p-2/content'));
		$proc = self::spawn('php ' . self::OCC . ' background-job:execute ' . self::jobId() . ' --force-execute');
		self::waitFor(static fn () => (int)(self::ctl('op=counts')['counts'][$held] ?? 0) >= 1);
		$t = microtime(true);
		Server::get(ConnectService::class)->cancel($u->uid, $id);
		self::assertLessThan(2.0, microtime(true) - $t, 'cancel < 2 s');
		// DB bleibt für andere schreibbar (SQLite: keine offene Transaktion des Jobs)
		[$wsName] = self::newWorkspace($other);
		$noteId = self::newNote($other, $wsName, 'Andere', '<p>a</p>');
		$t = microtime(true);
		$note = Server::get(\OCA\NextNotes\Db\NoteMapper::class)->find($noteId);
		Server::get(NoteService::class)->saveContent($note, '<p>b</p>', $other->uid);
		self::assertLessThan(2.0, microtime(true) - $t, 'Speichern < 2 s');
		$notesAtCancel = count(self::noteRows($u->uid));
		self::ctl('op=release&path=' . rawurlencode('graph.microsoft.com/v1.0/me/onenote/pages/p-2/content'));
		self::waitFor(static fn () => !proc_get_status($proc)['running'], 120);
		proc_close($proc);
		self::assertSame('cancelled', self::row($id)['status']);
		self::assertNull(self::row($id)['access_enc']);
		self::assertNull(self::row($id)['refresh_enc']);
		self::assertCount($notesAtCancel, self::noteRows($u->uid), 'keine Notiz nach dem Abbruch');
		self::assertSame(0, self::hits('pages/p-3/content'));
	}

	// Done 4c: Kontolöschung während der Mock anhält → keine Zeilen, keine neue Notiz
	public function testUserDeletedWhileHeld(): void {
		$u = $this->createUser('md');
		$id = $this->begin($u->uid, 4);
		$path = 'graph.microsoft.com/v1.0/me/onenote/pages/p-1/content';
		self::ctl('op=hold&path=' . rawurlencode($path));
		$proc = self::spawn('php ' . self::OCC . ' background-job:execute ' . self::jobId() . ' --force-execute');
		self::waitFor(static fn () => (int)(self::ctl('op=counts')['counts'][$path] ?? 0) >= 1);
		$wsBefore = self::ownedWorkspaceIds($u->uid);
		Server::get(IUserManager::class)->get($u->uid)->delete();
		self::ctl('op=release&path=' . rawurlencode($path));
		self::waitFor(static fn () => !proc_get_status($proc)['running'], 120);
		proc_close($proc);
		self::assertNull(self::row($id));
		self::assertSame([], self::rowsOf($u->uid));
		$qb = self::db()->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from('nextnotes_onenote_items')->where($qb->expr()->eq('uid', $qb->createNamedParameter($u->uid)));
		self::assertSame(0, (int)$qb->executeQuery()->fetchOne());
		self::assertSame(0, self::hits('pages/p-2/content'));
		self::assertSame([], array_values(array_diff(self::ownedWorkspaceIds($u->uid), $wsBefore)), 'kein neuer Workspace');
	}

	// Done 4: SIGKILL mitten im Lauf (cron.php), Reservierung 12 h zurückgestellt, Lease abgelaufen → normaler cron.php setzt fort
	public function testSigkillAndResume(): void {
		$u = $this->createUser('mk');
		$id = $this->begin($u->uid, 4);
		$jobId = self::jobId();
		$qb = self::db()->getQueryBuilder();
		$qb->update('jobs')->set('last_run', $qb->createNamedParameter(0))->set('reserved_at', $qb->createNamedParameter(0))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($jobId)))->executeStatement();
		$path = 'graph.microsoft.com/v1.0/me/onenote/pages/p-2/content';
		self::ctl('op=hold&path=' . rawurlencode($path));
		$proc = self::spawn('exec php /var/www/html/cron.php');
		self::waitFor(static fn () => (int)(self::ctl('op=counts')['counts'][$path] ?? 0) >= 1, 120);
		$pid = proc_get_status($proc)['pid'];
		posix_kill($pid, SIGKILL);
		self::waitFor(static fn () => !proc_get_status($proc)['running']);
		proc_close($proc);
		self::ctl('op=release&path=' . rawurlencode($path));
		self::assertSame('running', self::row($id)['status'], 'Lauf hart beendet');
		self::assertCount(2, self::noteRows($u->uid));
		// 12-h-Reservierung (JobList::getNext) und Lease-Ablauf nachstellen
		$qb = self::db()->getQueryBuilder();
		$qb->update('jobs')->set('reserved_at', $qb->createNamedParameter(time() - 12 * 3600 - 60))->set('last_run', $qb->createNamedParameter(0))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($jobId)))->executeStatement();
		Server::get(OneNoteImportMapper::class)->updateWhere($id, $u->uid, ['lease_until' => time() - 1]);
		exec('php /var/www/html/cron.php 2>&1', $out, $rc);
		self::assertSame(0, $rc, implode("\n", $out));
		self::assertSame('done', self::row($id)['status']);
		self::assertCount(6, self::noteRows($u->uid), 'keine Duplikate');
		self::assertSame(2, self::hits('pages/p-2/content'), 'abgebrochene Seite neu, sonst einmal');
		self::assertSame(1, self::hits('pages/p-3/content'));
	}
}
