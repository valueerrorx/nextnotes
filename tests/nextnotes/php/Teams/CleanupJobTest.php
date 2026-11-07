<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Teams;

use OCA\NextNotes\BackgroundJob\CleanupJob;
use OCA\NextNotes\Notification\Notifier;
use OCA\NextNotes\Service\BackgroundFiles;
use OCA\NextNotes\Service\Exception\TeamUnavailableException;
use OCA\NextNotes\Service\TeamService;
use OCA\NextNotes\Tests\Support\ApiClient;
use OCA\NextNotes\Tests\Support\Teams;
use OCP\BackgroundJob\IJobList;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\AppData\IAppDataFactory;
use OCP\IConfig;
use OCP\Notification\AlreadyProcessedException;
use OCP\Notification\IManager as INotificationManager;
use OCP\Server;
use OCP\Teams\ITeamManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

// Plan M5c Done 1–8 (SPEC §5 CleanupJob, §8, §13 M5 Krit. 4/7)
final class CleanupJobTest extends CleanupTestCase {
	private const APP = '/index.php/apps/nextnotes/';
	private const SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect x="0" y="0" width="10" height="10" fill="#ffffff"/></svg>';

	private static function attachmentDir(int $noteId): string {
		$cfg = Server::get(IConfig::class);
		return rtrim((string)$cfg->getSystemValueString('datadirectory'), '/') . '/appdata_' . $cfg->getSystemValueString('instanceid') . '/nextnotes/attachments/' . $noteId;
	}

	private static function wsExists(int $id): bool {
		return self::rowCount('nextnotes_workspaces', ['id' => $id]) === 1;
	}

	// Team gelöscht, Event verpasst: Zustand wie vor dem Event
	private function destroyWithoutEvent(string $owner, string $teamId): void {
		$this->teams->destroy($owner, $teamId, true);
		self::setTeamRow($teamId, ['state' => 'active', 'orphaned_at' => null, 'missing_count' => 0, 'deletion_notified_at' => null]);
		$qb = self::db()->getQueryBuilder();
		$qb->delete('notifications')->where($qb->expr()->eq('object_type', $qb->createNamedParameter('team')))
			->andWhere($qb->expr()->eq('object_id', $qb->createNamedParameter($teamId)))->executeStatement();
	}

	public function testJobRegistered(): void {
		self::assertTrue(Server::get(IJobList::class)->has(CleanupJob::class, null), 'CleanupJob nicht registriert');
	}

	// Done 1 (Krit. 7): ohne Event gelöscht → dry-run listet, ändert nichts; erst der zweite Lauf verwaist; Circles aus/Fehler zählt nicht; vorhanden → 0
	public function testMissedDeletionNeedsTwoRuns(): void {
		$a = $this->createUser('ca');
		$t = $this->teams->create($a->uid, 'Ohne Event ' . bin2hex(random_bytes(3)));
		[, $wsId] = self::teamWs($a, $t);
		$keep = $this->teams->create($a->uid, 'Bleibt ' . bin2hex(random_bytes(3)));
		self::teamWs($a, $keep);
		self::setTeamRow($keep, ['missing_count' => 1]);
		$this->destroyWithoutEvent($a->uid, $t);

		[$rc, $out] = self::occ('nextnotes:team:orphan-check', '--dry-run');
		self::assertSame(0, $rc, "dry-run: $out");
		self::assertMatchesRegularExpression('/' . preg_quote($t, '/') . '.*missing.*orphan/', $out, 'dry-run listet das fehlende Team');
		self::assertSame('active', self::teamRowOrNull($t)['state'], 'dry-run ändert nichts');
		self::assertSame(0, (int)self::teamRowOrNull($t)['missing_count']);
		self::assertSame(1, (int)self::teamRowOrNull($keep)['missing_count'], 'dry-run setzt nicht zurück');

		// Circles nicht verfügbar und Circles-Fehler: kein Hochzählen
		$off = $this->createStub(ITeamManager::class);
		$off->method('hasTeamSupport')->willReturn(false);
		$this->service(new TeamService($off, Server::get(ContainerInterface::class), Server::get(LoggerInterface::class)))->run(300);
		$broken = $this->createStub(ContainerInterface::class);
		$broken->method('get')->willThrowException(new \RuntimeException('circles kaputt'));
		$this->service(new TeamService(Server::get(ITeamManager::class), $broken, Server::get(LoggerInterface::class)))->run(300);
		self::assertSame(0, (int)self::teamRowOrNull($t)['missing_count'], 'Circles aus/Fehler zählt nicht');
		self::assertSame(1, (int)self::teamRowOrNull($keep)['missing_count'], 'Fehlerlauf setzt nicht zurück');

		$this->service()->run(300);
		self::assertSame(1, (int)self::teamRowOrNull($t)['missing_count'], 'erster Lauf zählt');
		self::assertSame('active', self::teamRowOrNull($t)['state']);
		self::assertSame(0, (int)self::teamRowOrNull($keep)['missing_count'], 'vorhanden setzt auf 0');
		self::assertSame(0, self::teamNotices($a->uid, $t, 'team_orphaned'));

		$this->now += self::DAY;
		$this->service()->run(300);
		$row = self::teamRowOrNull($t);
		self::assertSame('orphaned', $row['state'], 'zweiter Lauf verwaist');
		self::assertSame($this->now, (int)$row['orphaned_at']);
		self::assertSame(1, self::teamNotices($a->uid, $t, 'team_orphaned'), 'Benachrichtigung wie beim Event');
		self::assertTrue(self::wsExists($wsId), 'nichts gelöscht');
		self::assertSame('active', self::teamRowOrNull($keep)['state']);
	}

	// Done 2 (Krit. 4 Rest): Tag 23 genau ein Hinweis, Tag 30 alles weg, umgewandelter Workspace bleibt, zweiter Lauf ohne Wirkung
	public function testNoticeAndPurgeAfterDeadline(): void {
		$a = $this->createUser('pa');
		$b = $this->createUser('pb');
		$t = $this->teams->create($a->uid, 'Frist ' . bin2hex(random_bytes(3)));
		$this->teams->addUser($t, $a->uid, $b->uid);
		[$k1, $w1] = self::teamWs($a, $t);
		[$k2, $w2] = self::teamWs($a, $t);
		[$k3, $w3] = self::teamWs($a, $t);
		$n1 = self::newNote($b, $k1, 'B in W1', '<p>1</p>');
		$att = (int)self::ok($b->upload("/notes/$n1/attachments", 'a.txt', 'bytes'), 'Anhang')['attachment_id'];
		self::ok($b->call('POST', "/notes/$n1/snapshot?manual=1"), 'Snapshot');
		$n2 = self::newNote($a, $k2, 'A in W2', '<p>2</p>');
		$n3 = self::newNote($b, $k3, 'B in W3', '<p>3</p>');
		$qb = self::db()->getQueryBuilder();
		$qb->insert('nextnotes_reminders')->values([
			'note_id' => $qb->createNamedParameter($n2, IQueryBuilder::PARAM_INT), 'user_id' => $qb->createNamedParameter($a->uid),
			'type' => $qb->createNamedParameter('reminder'), 'trigger_at' => $qb->createNamedParameter(strtotime('2030-01-01'), IQueryBuilder::PARAM_INT),
			'created_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
		])->executeStatement();
		Server::get(BackgroundFiles::class)->folder($w1, $b->uid, true)->newFile('bg.png', 'png');
		self::assertFileExists(self::attachmentDir($n1) . '/' . $att);

		$this->teams->destroy($a->uid, $t, true);
		self::ok($a->call('POST', '/workspaces/' . rawurlencode($k3) . '/make-private'), 'W3 umwandeln');
		$orphanedAt = (int)self::teamRowOrNull($t)['orphaned_at'];

		$this->now = $orphanedAt + 22 * self::DAY;
		$this->service()->run(300);
		self::assertSame(0, self::teamNotices($a->uid, $t, 'team_deletion_soon'), 'Tag 22: noch kein Hinweis');
		$this->now = $orphanedAt + 23 * self::DAY;
		$this->service()->run(300);
		$this->service()->run(300);
		self::assertSame(1, self::teamNotices($a->uid, $t, 'team_deletion_soon'), 'Tag 23: genau ein Hinweis');
		self::assertSame(0, self::teamNotices($b->uid, $t, 'team_deletion_soon'), 'kein Hinweis an Nicht-created_by');
		$n = Server::get(INotificationManager::class)->createNotification();
		$n->setApp('nextnotes')->setUser($a->uid)->setDateTime(new \DateTime())->setObject('team', $t)->setSubject('team_deletion_soon', ['team' => 'X', 'assignments' => 0]);
		$p = Server::get(Notifier::class)->prepare($n, 'de');
		self::assertStringContainsString('gelöscht', $p->getParsedSubject());
		self::assertStringContainsString('Exportiere', $p->getParsedMessage(), 'Hinweis auf den Export');
		self::assertTrue(self::wsExists($w1), 'vor Tag 30 nichts gelöscht');

		$this->now = $orphanedAt + 30 * self::DAY;
		$this->service()->run(300);
		self::assertFalse(self::wsExists($w1), 'W1 gelöscht');
		self::assertFalse(self::wsExists($w2), 'W2 gelöscht');
		self::assertSame(0, self::rowCount('nextnotes_notes', ['id' => $n1]) + self::rowCount('nextnotes_notes', ['id' => $n2]), 'Notizen gelöscht');
		self::assertSame(0, self::rowCount('nextnotes_attachments', ['note_id' => $n1]), 'Anhang-Zeilen gelöscht');
		self::assertDirectoryDoesNotExist(self::attachmentDir($n1), 'Anhangsdateien gelöscht');
		self::assertSame(0, self::rowCount('nextnotes_snapshots', ['note_id' => $n1]), 'Snapshots gelöscht');
		self::assertSame(0, self::rowCount('nextnotes_reminders', ['note_id' => $n2]), 'Erinnerungen gelöscht');
		self::assertNull(Server::get(BackgroundFiles::class)->folder($w1, $b->uid, false), 'Hintergründe gelöscht');
		self::assertNull(self::teamRowOrNull($t), 'Team-Zeile gelöscht');
		self::assertSame(0, self::teamNotices($a->uid, $t, 'team_deletion_soon') + self::teamNotices($a->uid, $t, 'team_orphaned'), 'Benachrichtigungen ausgeblendet');
		self::assertTrue(self::wsExists($w3), 'umgewandelter Workspace bleibt');
		self::assertSame(1, self::rowCount('nextnotes_notes', ['id' => $n3]), 'Notiz im umgewandelten Workspace bleibt');

		$this->now += self::DAY;
		$this->service()->run(300);
		self::assertTrue(self::wsExists($w3), 'zweiter Lauf ohne Wirkung');
		self::assertSame(1, self::rowCount('nextnotes_notes', ['id' => $n3]));
	}

	// Cron war aus: Hinweis erst nach Tag 30 → Löschung frühestens 7 Tage danach (Notifier zeigt diesen Termin)
	public function testLateNoticeDelaysPurge(): void {
		$creator = $this->createUser('ln');
		$t = $this->rawTeam('late', 'orphaned', $this->now - 40 * self::DAY);
		$ws = $this->rawWorkspace('team', $t, $creator->uid);
		$svc = $this->service($this->stubTeams([$t]));
		$svc->run(300);
		self::assertTrue(self::wsExists($ws), 'kein Löschen ohne vorherigen Hinweis');
		self::assertSame(1, self::teamNotices($creator->uid, $t, 'team_deletion_soon'));
		$this->now += 6 * self::DAY;
		$svc->run(300);
		self::assertTrue(self::wsExists($ws), '6 Tage nach dem Hinweis');
		$this->now += self::DAY;
		$svc->run(300);
		self::assertFalse(self::wsExists($ws), '7 Tage nach dem Hinweis gelöscht');
		self::assertNull(self::teamRowOrNull($t));
	}

	// Done 3: Datei ohne Zeile > 24 h weg, jünger oder mit Zeile bleibt; Ordner gelöschter Notiz weg; Excalidraw-Vorschau ohne Verweis weg, mit Snapshot-Verweis bleibt
	public function testOrphanFilesAndPreviews(): void {
		$a = $this->createUser('fa');
		[$ws] = self::newWorkspace($a, 'Dateien');
		$n = self::newNote($a, $ws, 'Mit Anhang', '<p>x</p>');
		$att = (int)self::ok($a->upload("/notes/$n/attachments", 'a.txt', 'bytes'), 'Anhang')['attachment_id'];
		$own = (int)self::ok($a->upload("/notes/$n/attachments", 'excalidraw_preview.svg', self::SVG, [], 'image/svg+xml'), 'eigene SVG')['attachment_id'];
		$root = Server::get(IAppDataFactory::class)->get('nextnotes')->getFolder('attachments');
		$root->getFolder((string)$n)->newFile('999999999', 'verwaist');
		$ghost = 2000000000 + random_int(0, 100000000);
		$root->newFolder((string)$ghost)->newFile('5', 'rest');

		$files = $this->files();
		$this->now = time() + 3600;
		$files->files($this->now + 300);
		self::assertFileExists(self::attachmentDir($n) . '/999999999', 'jünger als 24 h bleibt');
		$this->now = time() + 2 * self::DAY;
		$files->files($this->now + 300);
		self::assertFileDoesNotExist(self::attachmentDir($n) . '/999999999', 'verwaiste Datei > 24 h gelöscht');
		self::assertFileExists(self::attachmentDir($n) . '/' . $att, 'Datei mit Zeile bleibt');
		self::assertDirectoryDoesNotExist(self::attachmentDir($ghost), 'Ordner einer gelöschten Notiz gelöscht');

		// Excalidraw: v1 mit manuellem Snapshot, dann v2 → v1-Vorschau bleibt (Snapshot), Vorschau ohne Verweis wird entfernt
		$d = $this->createUser('fd');
		[$ws2] = self::newWorkspace($d, 'Zeichnen');
		$m = self::newNote($d, $ws2, 'Zeichnung', '<p>z</p>');
		$save = fn (string $text) => self::ok($d->form(self::APP . 'api_save_excalidraw.php', ['action' => 'save_embedded_diagram', 'note_id' => $m, 'diagram_id' => 'excalidraw-1',
			'diagram_data' => json_encode(['type' => 'excalidraw', 'elements' => [['id' => 'e1', 'type' => 'text', 'text' => $text]], 'appState' => new \stdClass(), 'files' => new \stdClass()], JSON_THROW_ON_ERROR),
			'preview_svg' => self::SVG, 'workspace' => $ws2], true), "Diagramm $text");
		$save('v1');
		$p1 = $this->previewIds($m);
		self::assertCount(1, $p1, 'eine Vorschau mit Markierung');
		self::ok($d->call('POST', "/notes/$m/snapshot?manual=1"), 'Snapshot mit v1');
		$save('v2');
		$both = $this->previewIds($m);
		self::assertCount(2, $both, 'v1-Vorschau bleibt wegen Snapshot');
		$p2 = array_values(array_diff($both, $p1));
		$this->now = time() + 2 * self::DAY;
		$files->previews($this->now + 300);
		self::assertSame($both, $this->previewIds($m), 'mit Snapshot-Verweis bleibt');
		$qb = self::db()->getQueryBuilder();
		$qb->delete('nextnotes_snapshots')->where($qb->expr()->eq('note_id', $qb->createNamedParameter($m, IQueryBuilder::PARAM_INT)))->executeStatement();
		$files->previews($this->now + 300);
		self::assertSame($p2, $this->previewIds($m), 'ersetzte Vorschau ohne Verweis gelöscht, aktuelle bleibt');
		self::assertFileDoesNotExist(self::attachmentDir($m) . '/' . $p1[0], 'Datei der alten Vorschau');
		self::assertFileExists(self::attachmentDir($m) . '/' . $p2[0]);
		self::assertSame(1, self::rowCount('nextnotes_attachments', ['id' => $own]), 'eigene Datei ohne Markierung bleibt');
	}

	/** @return int[] Vorschau-Anhänge (origin=excalidraw) einer Notiz */
	private function previewIds(int $noteId): array {
		$qb = self::db()->getQueryBuilder();
		$qb->select('id')->from('nextnotes_attachments')
			->where($qb->expr()->eq('note_id', $qb->createNamedParameter($noteId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('origin', $qb->createNamedParameter('excalidraw')))->orderBy('id');
		return array_map('intval', $qb->executeQuery()->fetchFirstColumn());
	}

	// Done 4a: 1000 Teams, Budget endet → sauberer Abbruch, nächster Lauf setzt fort
	public function testBudgetStepOneResumes(): void {
		$ids = [];
		for ($i = 0; $i < 1000; $i++) {
			$ids[] = $this->rawTeam('bud');
		}
		sort($ids);
		$seen = [];
		$stub = $this->stubTeams([], [], function (string $id) use (&$seen) {
			$seen[] = $id;
			$this->now++;
		});
		$svc = $this->service($stub);
		$svc->run(300);
		$first = $seen;
		// Schritt 1 nutzt höchstens 3/5 des Budgets (H3)
		self::assertGreaterThan(150, count($first), 'Lauf 1 prüft bis zum Budget');
		self::assertLessThanOrEqual(182, count($first), 'Schritt 1 lässt Purge und Dateien ihre Reserve');
		self::assertLessThan(1000, count($first), 'Lauf 1 bricht nach dem Budget ab');
		self::assertSame(array_slice($ids, 0, count($first)), $first, 'Reihenfolge nach team_id');
		$seen = [];
		$svc->run(300);
		self::assertSame($ids[count($first)] ?? null, $seen[0] ?? null, 'Lauf 2 setzt nach dem letzten geprüften Team fort');
		self::assertSame([], array_intersect(array_slice($seen, 0, 1000 - count($first)), $first), 'keine Doppelprüfung vor dem Umlauf');
	}

	// Done 4b: Purge mit 3 Workspaces bei Budget 0 → ein Workspace je Lauf, Team-Zeile zuletzt
	public function testBudgetPurgeResumes(): void {
		$creator = $this->createUser('bp');
		$t = $this->rawTeam('pur', 'orphaned', $this->now - 31 * self::DAY, $this->now - 8 * self::DAY);
		$ws = [$this->rawWorkspace('team', $t, $creator->uid), $this->rawWorkspace('team', $t, $creator->uid), $this->rawWorkspace('team', $t, $creator->uid)];
		foreach ($ws as $w) {
			self::rawNote($w, 'Notiz ' . $w, $creator->uid);
		}
		$svc = $this->service($this->stubTeams([$t]));
		$svc->run(0);
		self::assertSame(2, count(array_filter($ws, [self::class, 'wsExists'])), 'Lauf 1: ein Workspace');
		self::assertNotNull(self::teamRowOrNull($t), 'Team-Zeile bleibt, solange Workspaces übrig sind');
		$svc->run(0);
		$svc->run(0);
		self::assertSame(0, count(array_filter($ws, [self::class, 'wsExists'])), 'alle Workspaces gelöscht');
		self::assertNull(self::teamRowOrNull($t), 'Team-Zeile zuletzt gelöscht');
	}

	// Done 5: 12 von 20 fehlen → Schritt 1 verworfen, Admin-Benachrichtigung; 2 von 20 → normal
	public function testMassProtection(): void {
		$ids = [];
		for ($i = 0; $i < 20; $i++) {
			$ids[] = $this->rawTeam('mass');
		}
		$this->service($this->stubTeams(array_slice($ids, 0, 12)))->run(300);
		foreach ($ids as $id) {
			self::assertSame(0, (int)self::teamRowOrNull($id)['missing_count'], 'kein Zähler geändert');
			self::assertSame('active', self::teamRowOrNull($id)['state']);
		}
		$notices = self::rowCount('notifications', ['app' => 'nextnotes', 'user' => 'admin', 'subject' => 'cleanup_suspicious']);
		self::assertSame(1, $notices, 'Admin-Benachrichtigung');
		$n = Server::get(INotificationManager::class)->createNotification();
		$n->setApp('nextnotes')->setUser('admin')->setDateTime(new \DateTime())->setObject('cleanup', 'teams')->setSubject('cleanup_suspicious', ['checked' => 20, 'missing' => 12]);
		self::assertStringContainsString('12', Server::get(Notifier::class)->prepare($n, 'de')->getParsedSubject());
		$u = $this->createUser('mu');
		$n->setUser($u->uid);
		try {
			Server::get(Notifier::class)->prepare($n, 'de');
			self::fail('cleanup_suspicious für Nicht-Admin');
		} catch (AlreadyProcessedException) {
		}
		// zweiter verdächtiger Lauf ersetzt die Meldung
		$this->service($this->stubTeams(array_slice($ids, 0, 12)))->run(300);
		self::assertSame(1, self::rowCount('notifications', ['app' => 'nextnotes', 'user' => 'admin', 'subject' => 'cleanup_suspicious']));

		$this->service($this->stubTeams(array_slice($ids, 0, 2)))->run(300);
		self::assertSame(1, (int)self::teamRowOrNull($ids[0])['missing_count'], '2 von 20: normal gezählt');
		self::assertSame(1, (int)self::teamRowOrNull($ids[1])['missing_count']);
		self::assertSame(0, (int)self::teamRowOrNull($ids[2])['missing_count']);
	}

	// Done 6: verwaistes Team existiert doch → active, Mitglieder lesen wieder; Tag 30 mit existierendem Team → kein Purge
	public function testReturnPath(): void {
		$a = $this->createUser('ra');
		$b = $this->createUser('rb');
		$t = $this->teams->create($a->uid, 'Zurück ' . bin2hex(random_bytes(3)));
		$this->teams->addUser($t, $a->uid, $b->uid);
		[$key, $wsId] = self::teamWs($a, $t);
		$n = self::newNote($b, $key, 'Noch da', '<p>r</p>');
		self::setTeamRow($t, ['state' => 'orphaned', 'orphaned_at' => $this->now - self::DAY]);
		self::assertSame(404, $b->call('GET', "/notes/$n")->status, 'verwaist: B liest nicht');
		$this->service()->run(300);
		self::assertSame('active', self::teamRowOrNull($t)['state'], 'Rückweg');
		self::assertNull(self::teamRowOrNull($t)['orphaned_at']);
		self::ok($b->call('GET', "/notes/$n"), 'B liest wieder');

		self::setTeamRow($t, ['state' => 'orphaned', 'orphaned_at' => $this->now - 31 * self::DAY, 'deletion_notified_at' => $this->now - 8 * self::DAY]);
		$this->service()->run(300);
		self::assertTrue(self::wsExists($wsId), 'kein Purge eines existierenden Teams');
		self::assertSame('active', self::teamRowOrNull($t)['state']);
		self::assertNull(self::teamRowOrNull($t)['deletion_notified_at']);
	}

	// Done 6 (Vorprüfung): Schritt 1 unterbrochen, Team taucht vor dem Purge wieder auf → Rückweg statt Löschen
	public function testPurgeRechecksExistence(): void {
		$creator = $this->createUser('rc');
		$t = $this->rawTeam('pre', 'orphaned', $this->now - 31 * self::DAY, $this->now - 8 * self::DAY);
		$ws = $this->rawWorkspace('team', $t, $creator->uid);
		$this->service($this->stubTeams([], [$t]))->run(300);
		self::assertTrue(self::wsExists($ws), 'Fehler bei der Vorprüfung → überspringen');
		self::assertSame('orphaned', self::teamRowOrNull($t)['state']);
		// Schritt 1 sieht es fehlen, die Prüfung direkt vor dem Purge findet es
		$calls = 0;
		$teams = $this->createStub(TeamService::class);
		$teams->method('isAvailable')->willReturn(true);
		$teams->method('exists')->willReturnCallback(function (string $id) use (&$calls) {
			return ++$calls === 1 ? null : ['id' => $id, 'name' => 'Wieder da'];
		});
		$this->service($teams)->run(300);
		self::assertSame(2, $calls, 'Existenz direkt vor dem Purge erneut geprüft');
		self::assertTrue(self::wsExists($ws), 'vorhanden → kein Purge');
		self::assertSame('active', self::teamRowOrNull($t)['state'], 'Rückweg statt Löschen');
	}

	// Done 7: versteckter bzw. Backend-Kreis gilt als vorhanden – nie verwaist, kein Purge; verwaister versteckter Kreis kommt zurück
	public function testHiddenCirclesStayActive(): void {
		$a = $this->createUser('ha');
		foreach (['versteckt' => Teams::HIDDEN, 'backend' => Teams::BACKEND] as $label => $cfg) {
			$t = $this->teams->create($a->uid, ucfirst($label) . ' ' . bin2hex(random_bytes(3)));
			[, $wsId] = self::teamWs($a, $t);
			$this->teams->setConfig($t, $cfg);
			$this->service()->run(300);
			$this->now += self::DAY;
			$this->service()->run(300);
			$res = $this->service()->check(false, false);
			self::assertFalse($res['error'], "$label: orphan-check ohne Fehler");
			$this->now += 31 * self::DAY;
			$this->service()->run(300);
			$row = self::teamRowOrNull($t);
			self::assertSame('active', $row['state'], "$label: bleibt active");
			self::assertSame(0, (int)$row['missing_count'], "$label: missing_count 0");
			self::assertTrue(self::wsExists($wsId), "$label: kein Purge");
			self::setTeamRow($t, ['state' => 'orphaned', 'orphaned_at' => $this->now - 31 * self::DAY, 'deletion_notified_at' => $this->now - 8 * self::DAY]);
			$this->service()->run(300);
			self::assertSame('active', self::teamRowOrNull($t)['state'], "$label: Rückweg");
			self::assertTrue(self::wsExists($wsId));
		}
	}

	// Done 8: 2 von 2 fehlen → Schritt 1 verworfen, Purge eines schon verwaisten dritten Teams läuft; --force verwaist trotzdem
	public function testMassProtectionAllMissing(): void {
		$creator = $this->createUser('am');
		$x = $this->rawTeam('all');
		$y = $this->rawTeam('all');
		$old = $this->rawTeam('old', 'orphaned', $this->now - 31 * self::DAY, $this->now - 8 * self::DAY);
		$oldWs = $this->rawWorkspace('team', $old, $creator->uid);
		$stub = $this->stubTeams([$x, $y, $old]);
		$this->service($stub)->run(300);
		self::assertSame(0, (int)self::teamRowOrNull($x)['missing_count'], 'Schritt 1 verworfen');
		self::assertSame(0, (int)self::teamRowOrNull($y)['missing_count']);
		self::assertSame(1, self::rowCount('notifications', ['app' => 'nextnotes', 'user' => 'admin', 'subject' => 'cleanup_suspicious']));
		self::assertFalse(self::wsExists($oldWs), 'Purge des schon verwaisten Teams läuft');
		self::assertNull(self::teamRowOrNull($old));

		$res = $this->service($stub)->check(false, false);
		self::assertTrue($res['suspicious']);
		self::assertSame('active', self::teamRowOrNull($x)['state'], 'orphan-check ohne --force verwaist nicht');
		$res = $this->service($stub)->check(false, true);
		self::assertSame('orphaned', self::teamRowOrNull($x)['state'], '--force verwaist');
		self::assertSame('orphaned', self::teamRowOrNull($y)['state']);
		self::assertSame($this->now, (int)self::teamRowOrNull($x)['orphaned_at']);
	}

	// H1: mehrdeutiges Team wird übersprungen, ein anderes fehlendes trotzdem gezählt; Admin-Meldung einmal ab dem dritten Fehlerlauf
	public function testAmbiguousTeamSkippedOthersCounted(): void {
		$x = $this->rawTeam('amb');
		$y = $this->rawTeam('amb', 'active', null, null, 1);
		$z = $this->rawTeam('amb', 'active', null, null, 1);
		$stub = $this->stubTeams([$x], [$y]);
		$this->service($stub)->run(300);
		self::assertSame(1, (int)self::teamRowOrNull($x)['missing_count'], 'fehlendes Team trotz Fehler bei y gezählt');
		self::assertSame(1, (int)self::teamRowOrNull($y)['missing_count'], 'Team mit Fehler unverändert');
		self::assertSame(0, (int)self::teamRowOrNull($z)['missing_count'], 'vorhandenes Team zurückgesetzt');
		$res = $this->service($stub)->check(true, false);
		self::assertFalse($res['error']);
		$byTeam = array_column($res['rows'], 'result', 'team');
		self::assertSame('error', $byTeam[$y], 'orphan-check listet error');
		self::assertSame(2, $res['checked'], 'nur fehlerfreie aktive Teams gezählt');
		self::assertSame(1, $res['missing']);
		$notices = fn () => self::rowCount('notifications', ['app' => 'nextnotes', 'user' => 'admin', 'subject' => 'cleanup_team_error']);
		$this->now += self::DAY;
		$this->service($stub)->run(300);
		self::assertSame('orphaned', self::teamRowOrNull($x)['state'], 'zweiter Lauf verwaist x');
		self::assertSame(0, $notices(), 'nach zwei Fehlerläufen noch keine Meldung');
		$this->service($stub)->run(300);
		self::assertSame(1, $notices(), 'dritter Fehlerlauf: Meldung');
		$this->service($stub)->run(300);
		self::assertSame(1, $notices(), 'nur einmal');
		$n = Server::get(INotificationManager::class)->createNotification();
		$n->setApp('nextnotes')->setUser('admin')->setDateTime(new \DateTime())->setObject('cleanup', 'team:' . $y)->setSubject('cleanup_team_error', ['team' => $y, 'name' => 'N', 'runs' => 3]);
		self::assertStringContainsString($y, Server::get(Notifier::class)->prepare($n, 'de')->getParsedSubject());
		$this->service($this->stubTeams([], []))->run(300);
		self::assertSame(0, $notices(), 'wieder prüfbar: Meldung ausgeblendet');
		self::assertSame(0, (int)self::teamRowOrNull($y)['missing_count']);
	}

	// Alle Abfragen scheitern: Schritt 1 entfällt ganz (nie „fehlt“)
	public function testAllLookupsFailDiscardStepOne(): void {
		$x = $this->rawTeam('err');
		$y = $this->rawTeam('err', 'active', null, null, 1);
		$this->service($this->stubTeams([], [$x, $y]))->run(300);
		self::assertSame(0, (int)self::teamRowOrNull($x)['missing_count'], 'nichts gezählt');
		self::assertSame(1, (int)self::teamRowOrNull($y)['missing_count'], 'nichts zurückgesetzt');
		self::assertTrue($this->service($this->stubTeams([], [$x, $y]))->check(true, false)['error']);
		try {
			$this->stubTeams([], [$y])->exists($y);
			self::fail('Stub wirft');
		} catch (TeamUnavailableException) {
		}
	}

	// H4: Vorschau bleibt, solange irgendeine Notiz (HTML-Kopie, Papierkorb) auf sie verweist; Duplikat: Kopie mit Verweis bleibt, ohne geht; Notiz < 24 h geändert bleibt
	public function testPreviewReferencedElsewhereStays(): void {
		$d = $this->createUser('pe');
		[$ws] = self::newWorkspace($d, 'Kopie');
		$m = self::newNote($d, $ws, 'Original', '<p>o</p>');
		$save = fn (string $text) => self::ok($d->form(self::APP . 'api_save_excalidraw.php', ['action' => 'save_embedded_diagram', 'note_id' => $m, 'diagram_id' => 'excalidraw-1',
			'diagram_data' => json_encode(['type' => 'excalidraw', 'elements' => [['id' => 'e1', 'type' => 'text', 'text' => $text]], 'appState' => new \stdClass(), 'files' => new \stdClass()], JSON_THROW_ON_ERROR),
			'preview_svg' => self::SVG, 'workspace' => $ws], true), "Diagramm $text");
		$save('v1');
		[$p1] = $this->previewIds($m);
		// Bild per HTML in Notiz B kopiert
		$b = self::newNote($d, $ws, 'Kopie B', '<p><img src="/api/v1/notes/' . $m . '/attachments/' . $p1 . '" alt="x"></p>');
		self::assertStringContainsString('/attachments/' . $p1, (string)self::rowValue('nextnotes_notes', 'content', $b), 'Kontrolle: Verweis in B gespeichert');
		self::ok($d->call('POST', "/notes/$m/snapshot?manual=1"), 'Snapshot');
		$save('v2');
		[, $p2] = $this->previewIds($m) + [null, null];
		self::assertNotNull($p2, 'v1-Vorschau bleibt wegen Snapshot');
		self::deleteWhere('nextnotes_snapshots', 'note_id', $m);
		// Duplikat von m: aktuelle Vorschau mit umgeschriebenem Verweis, alte ohne Verweis
		$copy = (int)self::ok($d->call('POST', "/notes/$m/duplicate", ['workspace' => $ws]), 'Duplikat')['id'];
		$copyPreviews = $this->previewIds($copy);
		self::assertCount(2, $copyPreviews, 'Vorschauen mitkopiert (mit Markierung)');
		self::deleteWhere('nextnotes_snapshots', 'note_id', $copy);

		$files = $this->files();
		$this->now = time() + 2 * self::DAY;
		$files->previews($this->now + 300);
		self::assertContains($p1, $this->previewIds($m), 'per HTML in B verwendet → bleibt');
		self::assertContains($p2, $this->previewIds($m), 'aktuelle Vorschau bleibt');
		$left = $this->previewIds($copy);
		self::assertCount(1, $left, 'Duplikat: Vorschau ohne Verweis gelöscht');
		self::assertStringContainsString('/attachments/' . $left[0], (string)self::rowValue('nextnotes_notes', 'content', $copy), 'Duplikat: verwendete Vorschau bleibt');

		// B im Papierkorb: Verweis zählt weiter
		self::setValue('nextnotes_notes', 'trashed_at', time(), $b);
		$files->previews($this->now + 300);
		self::assertContains($p1, $this->previewIds($m), 'Verweis aus dem Papierkorb hält die Vorschau');
		// erst ohne jeden Verweis weg
		self::setValue('nextnotes_notes', 'content', '<p>leer</p>', $b);
		$files->previews($this->now + 300);
		self::assertNotContains($p1, $this->previewIds($m), 'ohne Verweis gelöscht');
	}

	public function testPreviewKeptWhileNoteRecentlyChanged(): void {
		$d = $this->createUser('pr');
		[$ws] = self::newWorkspace($d, 'Neu');
		$m = self::newNote($d, $ws, 'Frisch', '<p>f</p>');
		$aid = (int)self::ok($d->upload("/notes/$m/attachments", 'p.svg', self::SVG, [], 'image/svg+xml'), 'Anhang')['attachment_id'];
		// als alte Excalidraw-Vorschau markieren, Notiz gerade geändert
		self::setValue('nextnotes_attachments', 'origin', 'excalidraw', $aid);
		self::setValue('nextnotes_attachments', 'created_at', time() - 3 * self::DAY, $aid);
		$this->now = time() + 3600;
		$this->files()->previews($this->now + 300);
		self::assertSame([$aid], $this->previewIds($m), 'Notiz < 24 h geändert → bleibt');
		self::setValue('nextnotes_notes', 'updated_at', time() - 2 * self::DAY, $m);
		$this->files()->previews($this->now + 300);
		self::assertSame([], $this->previewIds($m), 'Notiz seit 24 h unverändert, kein Verweis → gelöscht');
	}

	private static function rowValue(string $table, string $col, int $id): mixed {
		$qb = self::db()->getQueryBuilder();
		$qb->select($col)->from($table)->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$v = $qb->executeQuery()->fetchOne();
		return is_resource($v) ? stream_get_contents($v) : $v;
	}

	private static function setValue(string $table, string $col, mixed $v, int $id): void {
		$qb = self::db()->getQueryBuilder();
		$qb->update($table)->set($col, $qb->createNamedParameter($v, is_int($v) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeStatement();
	}

	private static function deleteWhere(string $table, string $col, int $id): void {
		$qb = self::db()->getQueryBuilder();
		$qb->delete($table)->where($qb->expr()->eq($col, $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeStatement();
	}
}
