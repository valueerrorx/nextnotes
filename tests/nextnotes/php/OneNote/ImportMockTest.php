<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\OneNote;

use OCA\NextNotes\Command\DeleteUserData;
use OCA\NextNotes\Db\AttachmentMapper;
use OCA\NextNotes\Db\OneNoteImportMapper;
use OCA\NextNotes\OneNote\Convert\ConvertResource;
use OCA\NextNotes\OneNote\ConnectService;
use OCA\NextNotes\OneNote\GateException;
use OCA\NextNotes\OneNote\GraphClient;
use OCA\NextNotes\OneNote\HttpGate;
use OCA\NextNotes\OneNote\OneNoteImportService;
use OCA\NextNotes\OneNote\ResourceFetcher;
use OCA\NextNotes\Service\AttachmentService;
use OCA\NextNotes\Service\NoteService;
use OCP\ITempManager;
use OCP\IUserManager;
use OCP\Server;
use PHPUnit\Framework\Attributes\Group;

// M9b gegen den Mock (Profil msmock) mit echten Job-Prozessen: occ ohne Sitzung (3a), Paging, zwei parallele Läufe (4a),
// cancel während einer angehaltenen Antwort (4b, < 2 s), Kontolöschung und delete-user-data (4c), SIGKILL mit 12-h-Reservierung (4).
// M9d: Ressourcen über echtes curl (Bild, PDF, endlos, gzip-Bombe, 404/403, SVG, langsam), Messung einer Seite mit 80 Ressourcen
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

	// Review M9d-r1 Blocker: SIGKILL während eines Ressourcen-Downloads lässt eine Temp-Datei zurück; der nächste Job-Lauf räumt sie nach 1 h weg
	public function testSigkillTempLeftoverCleaned(): void {
		$u = $this->createUser('mt');
		self::ctl('op=set&res=slow&res_n=1');
		$id = $this->begin($u->uid, 1);
		$dir = rtrim((string)Server::get(ITempManager::class)->getTempBaseDir(), '/');
		$pattern = $dir . '/oc_tmp_*' . ResourceFetcher::TEMP_SUFFIX;
		$before = glob($pattern) ?: [];
		$path = 'graph.microsoft.com/v1.0/me/onenote/resources/slow-0-p-0/$value';
		self::ctl('op=hold&path=' . rawurlencode($path));
		$proc = self::spawn('exec php ' . self::OCC . ' background-job:execute ' . self::jobId() . ' --force-execute');
		self::waitFor(static fn () => (int)(self::ctl('op=counts')['counts'][$path] ?? 0) >= 1, 120);
		posix_kill(proc_get_status($proc)['pid'], SIGKILL);
		self::waitFor(static fn () => !proc_get_status($proc)['running']);
		proc_close($proc);
		self::ctl('op=release&path=' . rawurlencode($path));
		$left = array_values(array_diff(glob($pattern) ?: [], $before));
		self::assertCount(1, $left, 'Temp-Rest nach SIGKILL');
		// jünger als 1 h: bleibt (könnte einem laufenden Prozess gehören)
		self::execute();
		self::assertFileExists($left[0]);
		touch($left[0], time() - ResourceFetcher::TEMP_MAX_AGE - 60);
		self::execute();
		self::assertFileDoesNotExist($left[0], 'nächster Lauf räumt auf');
		self::assertSame('running', self::row($id)['status'], 'Lease des getöteten Laufs noch gültig');
	}

	// Done 4c (Review M9b-r1 Hinweis 9): delete-user-data für ein deaktiviertes Konto während der Mock anhält
	public function testDeleteUserDataWhileHeld(): void {
		$u = $this->createUser('mu');
		$id = $this->begin($u->uid, 4);
		$path = 'graph.microsoft.com/v1.0/me/onenote/pages/p-1/content';
		self::ctl('op=hold&path=' . rawurlencode($path));
		$proc = self::spawn('php ' . self::OCC . ' background-job:execute ' . self::jobId() . ' --force-execute');
		self::waitFor(static fn () => (int)(self::ctl('op=counts')['counts'][$path] ?? 0) >= 1);
		Server::get(IUserManager::class)->get($u->uid)->setEnabled(false);
		[$rc, $out] = self::runCommand(DeleteUserData::class, ['uid' => $u->uid]);
		self::assertSame(0, $rc, $out);
		self::ctl('op=release&path=' . rawurlencode($path));
		self::waitFor(static fn () => !proc_get_status($proc)['running'], 120);
		proc_close($proc);
		self::assertNull(self::row($id));
		self::assertSame([], self::rowsOf($u->uid));
		$qb = self::db()->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from('nextnotes_onenote_items')->where($qb->expr()->eq('uid', $qb->createNamedParameter($u->uid)));
		self::assertSame(0, (int)$qb->executeQuery()->fetchOne());
		self::assertSame([], self::ownedWorkspaceIds($u->uid), 'kein Workspace der uid');
		self::assertSame([], self::noteRows($u->uid));
		self::assertSame(0, self::hits('pages/p-2/content'));
	}

	// M9d Done 1–4, 6 über echtes curl: Bild, JPEG, PDF → Anhänge; 404/403/SVG/Text/endlos/gzip-Bombe → Platzhalter; Job per occ
	public function testResourcesThroughMock(): void {
		$u = $this->createUser('mr');
		self::ctl('op=set&res=png,jpg,pdf,e404,e403,svg,txt,big,bomb&res_n=1');
		$id = $this->begin($u->uid, 1);
		$t = microtime(true);
		$this->runToEnd($id, $u->uid);
		$seconds = microtime(true) - $t;
		$note = null;
		foreach (self::noteRows($u->uid) as $n) {
			$note = $n['heading'] === 'Seite p-0' ? $n : $note;
		}
		self::assertNotNull($note);
		$att = Server::get(AttachmentMapper::class)->findForNotes([(int)$note['id']])[(int)$note['id']] ?? [];
		self::assertSame(['image/png', 'image/jpeg', 'application/pdf'], array_map(static fn ($a) => $a->getMime(), $att));
		$row = self::row($id);
		self::assertSame([3, 6], [(int)$row['resources_done'], (int)$row['resources_failed']]);
		self::assertSame(1, self::hits('resources/bomb-0-p-0/$value'));
		self::assertLessThan(60.0, $seconds, 'endlose Ressource und Bombe brechen an der Grenze ab');
		fwrite(STDERR, sprintf("\nM9d Mock-Seite mit 9 Ressourcen (davon endlos + Bombe, Grenze 20 MiB): %.1f s\n", $seconds));
	}

	// M9d Done 3, 3a: Grenze gilt für dekodierte Bytes, Abbruch beim Lesen (Datei ≤ Grenze, Speicher klein)
	public function testGateCutsDecodedBytes(): void {
		$gate = Server::get(HttpGate::class);
		$limit = 1 << 20;
		foreach (['bomb', 'big'] as $kind) {
			$file = Server::get(ITempManager::class)->getTemporaryFile();
			memory_reset_peak_usage();
			$base = memory_get_usage();
			$t = microtime(true);
			try {
				$gate->send('GET', GraphClient::PREFIX . "me/onenote/resources/$kind-0-p-0/\$value", HttpGate::GRAPH_HOST,
					['Authorization' => 'Bearer msmock-access-test'], null, $limit, $file, 60);
				self::fail("too_large erwartet: $kind");
			} catch (GateException $e) {
				self::assertSame('too_large', $e->reason, $kind);
			}
			$seconds = microtime(true) - $t;
			$size = filesize($file);
			$peak = memory_get_peak_usage() - $base;
			unlink($file);
			self::assertLessThanOrEqual($limit + 65536, $size, "$kind: gelesen ≤ Grenze + 64 KiB");
			self::assertLessThan(16 << 20, $peak, "$kind: Speicher");
			self::assertLessThan(20.0, $seconds, "$kind: Abbruch statt Volllast");
			fwrite(STDERR, sprintf("\nM9d %s: Datei %d B, Spitze +%.1f MiB, %.2f s\n", $kind, $size, $peak / 1048576, $seconds));
		}
	}

	// M9d: langsame Ressource über die Zeitgrenze der Seite → Platzhalter, keine Ausnahme (Grenze im Test 3 s statt 15 min)
	public function testSlowResourceHitsPageDeadline(): void {
		$u = $this->createUser('ms');
		$import = Server::get(OneNoteImportMapper::class)->findOwn(self::insertImport($u->uid, 'select', ['created_at' => time(), 'updated_at' => time()]), $u->uid);
		$graph = Server::get(GraphClient::class);
		$fetcher = new class(Server::get(ITempManager::class), Server::get(AttachmentService::class)) extends ResourceFetcher {
			protected function pageSeconds(): int {
				return 3;
			}
		};
		$res = [new ConvertResource(0, 'slow-0-p-0', ConvertResource::KIND_IMAGE, '', ''), new ConvertResource(1, 'png-0-p-0', ConvertResource::KIND_IMAGE, '', '')];
		$t = microtime(true);
		$batch = $fetcher->fetch('me', $res, static fn (string $url, string $file, int $limit, int $timeout) => $graph->get($import, 'msmock-access-test', $url, null, $limit, $file, $timeout));
		$seconds = microtime(true) - $t;
		self::assertSame([0 => 'page_limit', 1 => 'page_limit'], $batch->failed);
		self::assertSame([], $batch->files);
		self::assertLessThan(5.0, $seconds, 'Zeitgrenze statt 5 s Wartezeit des Mocks');
		$batch->cleanup();
	}

	// M9d Done 8 (Messung): Seite mit 80 Ressourcen (je 1 MiB) in einem Lauf über echtes curl; Zeit und Speicher des Prozesses
	public function testEightyResourcesMeasured(): void {
		$u = $this->createUser('m8');
		self::ctl('op=set&res=mb&res_n=80');
		$id = $this->begin($u->uid, 2);
		Server::get(OneNoteImportMapper::class)->updateWhere($id, $u->uid, ['next_run_at' => 0]);
		gc_collect_cycles();
		memory_reset_peak_usage();
		$base = memory_get_usage();
		$t = microtime(true);
		Server::get(OneNoteImportService::class)->runDue();
		$seconds = microtime(true) - $t;
		$peak = memory_get_peak_usage() - $base;
		$row = self::row($id);
		self::assertSame(80, (int)$row['resources_done'], 'erste Seite ganz in einem Lauf');
		self::assertSame(1, (int)$row['pages_done']);
		self::assertSame(0, self::hits('pages/p-1/content'), 'danach keine neue Seite');
		self::assertLessThan(64 << 20, $peak, 'Ressourcen in Temp-Dateien, nicht im Speicher');
		fwrite(STDERR, sprintf("\nM9d Seite mit 80 Ressourcen à 1 MiB: %.1f s, Speicher-Spitze +%.1f MiB\n", $seconds, $peak / 1048576));
		self::ctl('op=set&res=&res_n=1');
	}
}
