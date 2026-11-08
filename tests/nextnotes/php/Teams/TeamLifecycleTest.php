<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Teams;

use OCA\NextNotes\Db\NoteMapper;
use OCA\NextNotes\Db\WorkspaceMapper;
use OCA\NextNotes\Db\WsShareMapper;
use OCA\NextNotes\Listener\CircleDestroyedListener;
use OCA\NextNotes\Listener\MembershipsRemovedListener;
use OCA\NextNotes\Notification\Notifier;
use OCA\NextNotes\Service\AccessService;
use OCA\NextNotes\Service\BackgroundFiles;
use OCA\NextNotes\Service\Exception\TeamUnavailableException;
use OCA\NextNotes\Service\TeamLifecycleService;
use OCA\NextNotes\Service\TeamService;
use OCA\NextNotes\Service\TeamStateService;
use OCA\NextNotes\Db\FolderMapper;
use OCA\NextNotes\Db\TeamMapper;
use OCA\NextNotes\Tests\Support\ApiClient;
use OCA\NextNotes\Tests\Support\Response;
use OCA\NextNotes\Tests\Support\TeamTestCase;
use OCA\NextNotes\Tests\Support\Teams;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Authentication\Token\IToken;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\Event;
use OCP\IUserManager;
use OCP\Notification\AlreadyProcessedException;
use OCP\Notification\IManager as INotificationManager;
use OCP\Security\ISecureRandom;
use OCP\Server;
use OCP\Teams\ITeamManager;
use OC\Authentication\Token\IProvider;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

// Plan M5b Done 1–6 (SPEC §3 Team-Zustand, §8 Lebenszyklus, §13 M5 Krit. 3/4)
final class TeamLifecycleTest extends TeamTestCase {
	private const APP = '/index.php/apps/nextnotes/';

	protected function setUp(): void {
		parent::setUp();
		// wie cron.php: ohne geladene notifications-App verwirft IManager::notify still
		Server::get(IAppManager::class)->loadApp('notifications');
	}

	private static function occ(string ...$args): int {
		exec('php /var/www/html/occ ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $out, $rc);
		return $rc;
	}

	private static function notFound(Response $r, string $what): void {
		self::assertSame(404, $r->status, "$what: erwartet 404, erhalten " . $r->describe());
	}

	private static function rowCount(string $table, array $where): int {
		$qb = self::db()->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from($table);
		foreach ($where as $col => $v) {
			$qb->andWhere(is_int($v)
				? $qb->expr()->eq($col, $qb->createNamedParameter($v, IQueryBuilder::PARAM_INT))
				: $qb->expr()->eq($col, $qb->createNamedParameter($v)));
		}
		return (int)$qb->executeQuery()->fetchOne();
	}

	private static function teamRow(string $teamId): array {
		$qb = self::db()->getQueryBuilder();
		$qb->select('*')->from('nextnotes_teams')->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)));
		$row = $qb->executeQuery()->fetchAssociative();
		self::assertIsArray($row, "Team-Zeile $teamId fehlt");
		return $row;
	}

	private static function wsRow(int $id): array {
		$qb = self::db()->getQueryBuilder();
		$qb->select('*')->from('nextnotes_workspaces')->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetchAssociative();
		self::assertIsArray($row, "Workspace $id fehlt");
		return $row;
	}

	// Erinnerung, Erwähnungs-Status und Hintergrundbild von $uid an Notiz/Workspace (Rohdaten wie aus der Oberfläche)
	private static function traces(string $uid, int $noteId, int $wsId): void {
		$db = self::db();
		$qb = $db->getQueryBuilder();
		$qb->insert('nextnotes_reminders')->values([
			'note_id' => $qb->createNamedParameter($noteId, IQueryBuilder::PARAM_INT),
			'user_id' => $qb->createNamedParameter($uid),
			'type' => $qb->createNamedParameter('reminder'),
			'trigger_at' => $qb->createNamedParameter(strtotime('2030-01-01'), IQueryBuilder::PARAM_INT),
			'created_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
		])->executeStatement();
		$db->insertIgnoreConflict('nextnotes_mentions_handled', ['note_id' => $noteId, 'user_id' => $uid, 'handled_count' => 1, 'updated_at' => time()]);
		Server::get(BackgroundFiles::class)->folder($wsId, $uid, true)->newFile('bg.png', 'png');
	}

	/** @return array{0: int, 1: int, 2: bool} Erinnerungen, Erwähnungs-Status, Hintergrund vorhanden */
	private static function tracesOf(string $uid, int $noteId, int $wsId): array {
		return [
			self::rowCount('nextnotes_reminders', ['user_id' => $uid, 'note_id' => $noteId]),
			self::rowCount('nextnotes_mentions_handled', ['user_id' => $uid, 'note_id' => $noteId]),
			Server::get(BackgroundFiles::class)->folder($wsId, $uid, false) !== null,
		];
	}

	private static function teamNotifications(string $uid, string $teamId): int {
		return self::rowCount('notifications', ['app' => 'nextnotes', 'user' => $uid, 'object_type' => 'team', 'object_id' => $teamId, 'subject' => 'team_orphaned']);
	}

	private static function prepare(string $uid, string $teamId, string $subject): \OCP\Notification\INotification {
		$n = Server::get(INotificationManager::class)->createNotification();
		$n->setApp('nextnotes')->setUser($uid)->setDateTime(new \DateTime())->setObject('team', $teamId)->setSubject($subject, ['team' => 'X', 'assignments' => 0]);
		return Server::get(Notifier::class)->prepare($n, 'de');
	}

	// Done 1 (Krit. 3) + Done 4 eingefroren: Circles aus → private Workspaces normal, keine 500, Team-Workspace nur created_by lesend, Umwandeln 403, Zustand bleibt
	public function testCirclesDisabledFreezes(): void {
		$a = $this->createUser('fa');
		$b = $this->createUser('fb');
		$t = $this->teams->create($a->uid, 'Frost ' . bin2hex(random_bytes(3)));
		$this->teams->addUser($t, $a->uid, $b->uid);
		[$key] = self::teamWs($a, $t);
		$n = self::newNote($b, $key, 'Frostnotiz', '<p>f</p>');
		[$pk] = self::newWorkspace($b, 'FP');
		$own = self::newNote($b, $pk, 'Privat', '<p>p</p>');
		self::assertSame(0, self::occ('app:disable', 'circles'));
		try {
			sleep(4);
			self::ok($b->call('GET', "/notes/$own"), 'privat lesen');
			self::ok($b->call('PATCH', "/notes/$own", ['heading' => 'Privat', 'content' => '<p>neu</p>']), 'privat schreiben');
			foreach (['/workspaces', '/notes', '/notes/search?q=f', '/graph', '/tasks', '/folders', '/reminders', '/mentions', '/teams', '/tags'] as $p) {
				foreach ([$a, $b] as $c) {
					self::assertLessThan(500, $c->call('GET', $p)->status, "$p ohne Circles");
				}
			}
			foreach (['index.php', 'settings.php', 'workspaces.php'] as $page) {
				$r = $b->withHeaders(['Accept: text/html'])->absolute('GET', self::APP . $page);
				self::assertLessThan(500, $r->status, "$page ohne Circles");
			}
			self::ok($a->call('GET', "/notes/$n"), 'created_by liest');
			self::notFound($b->call('GET', "/notes/$n"), 'B (eingefroren)');
			self::assertSame(403, $a->call('PATCH', "/notes/$n", ['heading' => 'x', 'content' => '<p>x</p>'])->status, 'created_by schreibt nicht');
			self::assertSame(403, $a->call('POST', '/workspaces/' . rawurlencode($key) . '/make-private')->status, 'Umwandeln bei eingefroren');
		} finally {
			self::occ('app:enable', 'circles');
			sleep(4);
		}
		self::assertSame('active', self::teamState($t), 'Zustand bleibt active');
		self::ok($b->call('PATCH', "/notes/$n", ['heading' => 'Frostnotiz', 'content' => '<p>wieder</p>']), 'B schreibt wieder');
	}

	// Done 2 (Krit. 4 Teil): zerstören → verwaist, nur created_by liest, Benachrichtigung an created_by; prepare verschwindet nach dem Umwandeln
	public function testDestroyOrphansAndNotifies(): void {
		$a = $this->createUser('da');
		$b = $this->createUser('db');
		$t = $this->teams->create($a->uid, 'Weg ' . bin2hex(random_bytes(3)));
		$this->teams->addUser($t, $a->uid, $b->uid);
		[$key, $wsId] = self::teamWs($a, $t);
		$n = self::newNote($b, $key, 'B schrieb', '<p>b</p>');
		$before = time();
		$this->teams->destroy($a->uid, $t, true);
		$row = self::teamRow($t);
		self::assertSame('orphaned', $row['state'], 'Zustand nach Zerstören');
		self::assertGreaterThanOrEqual($before, (int)$row['orphaned_at'], 'orphaned_at');
		self::ok($a->call('GET', "/notes/$n"), 'created_by liest');
		self::notFound($b->call('GET', "/notes/$n"), 'B nach Löschung');
		self::assertSame(403, $a->call('PATCH', "/notes/$n", ['heading' => 'x', 'content' => '<p>x</p>'])->status, 'created_by schreibt nicht');
		self::assertSame(1, self::teamNotifications($a->uid, $t), 'genau eine Benachrichtigung an created_by');
		self::assertSame(0, self::teamNotifications($b->uid, $t), 'keine an B');
		$p = self::prepare($a->uid, $t, 'team_orphaned');
		self::assertStringContainsString('gelöscht', $p->getParsedSubject(), 'Text');
		self::assertStringContainsString('workspace=', $p->getLink(), 'Link auf den Workspace');
		self::prepare($a->uid, $t, 'team_deletion_soon');
		// zweites Event (z. B. CircleDestroyedEvent im Loopback) ändert nichts
		Server::get(TeamLifecycleService::class)->teamDeleted($t, 'egal');
		self::assertSame(1, self::teamNotifications($a->uid, $t), 'kein zweites Mal');
		try {
			self::prepare($b->uid, $t, 'team_orphaned');
			self::fail('prepare für Nicht-created_by');
		} catch (AlreadyProcessedException) {
		}
		self::ok($a->call('POST', '/workspaces/' . rawurlencode($key) . '/make-private'), 'Umwandeln');
		try {
			self::prepare($a->uid, $t, 'team_orphaned');
			self::fail('prepare nach dem Umwandeln');
		} catch (AlreadyProcessedException) {
		}
		self::assertSame('user', self::wsRow($wsId)['owner_type']);
	}

	// Plan R17: Löschen über die Weboberfläche (OCS, async) → Event im Circles-Loopback-Request
	public function testDestroyViaWebLoopback(): void {
		$a = $this->createUser('la');
		$t = $this->teams->create($a->uid, 'Loop ' . bin2hex(random_bytes(3)));
		self::teamWs($a, $t);
		$r = $a->withHeaders(['OCS-APIRequest: true'])->absolute('DELETE', '/ocs/v2.php/apps/circles/circles/' . $t . '?format=json');
		self::assertSame(200, $r->status, 'Circles-Löschung über OCS: ' . $r->describe());
		$state = null;
		for ($i = 0; $i < 20; $i++) {
			$state = self::teamState($t);
			// Benachrichtigung folgt dem Zustandswechsel im selben Request
			if ($state === 'orphaned' && self::teamNotifications($a->uid, $t) === 1) {
				break;
			}
			usleep(500000);
		}
		self::assertSame('orphaned', $state, 'Loopback-Event kam nicht an (R17: Rückfall CleanupJob)');
		self::assertSame(1, self::teamNotifications($a->uid, $t), 'Benachrichtigung aus dem Loopback');
	}

	// Done 3: Circles-Fehler und „nicht gefunden“ beim Zugriff → kein Recht für den Request, Zustand unverändert, kein Austritt
	public function testCirclesErrorDoesNotChangeState(): void {
		$a = $this->createUser('ea');
		$b = $this->createUser('eb');
		$t = $this->teams->create($a->uid, 'Fehler ' . bin2hex(random_bytes(3)));
		$this->teams->addUser($t, $a->uid, $b->uid);
		[$key, $wsId] = self::teamWs($a, $t);
		$n = self::newNote($b, $key, 'Fehlernotiz', '<p>e</p>');
		self::traces($b->uid, $n, $wsId);

		$broken = $this->createStub(ContainerInterface::class);
		$broken->method('get')->willThrowException(new \RuntimeException('circles kaputt'));
		$teams = new TeamService(Server::get(ITeamManager::class), $broken, Server::get(LoggerInterface::class));
		$state = new TeamStateService(Server::get(TeamMapper::class), $teams, Server::get(ITimeFactory::class));
		$access = new AccessService(Server::get(WorkspaceMapper::class), Server::get(WsShareMapper::class), Server::get(NoteMapper::class), Server::get(FolderMapper::class), $teams, $state, Server::get(\OCA\NextNotes\Db\AssignmentMapper::class), Server::get(\OCA\NextNotes\Db\SubmissionMapper::class));
		$ws = Server::get(WorkspaceMapper::class)->find($wsId);
		self::assertSame(AccessService::NONE, $access->workspaceLevel($b->uid, $ws), 'B ohne Recht bei Circles-Fehler');
		self::assertNotContains($wsId, $access->visibleWorkspaceIds($b->uid), 'nicht sichtbar bei Fehler');
		try {
			$teams->exists($t);
			self::fail('exists() bei Fehler muss werfen');
		} catch (TeamUnavailableException) {
		}
		try {
			$teams->currentLevel($t, $b->uid);
			self::fail('currentLevel() bei Fehler muss werfen');
		} catch (TeamUnavailableException) {
		}
		self::assertSame('active', self::teamState($t), 'Zustand nach Fehler');

		// „nicht gefunden“: Team-Workspace eines unbekannten Kreises → 404, Zustand bleibt
		$ghost = 'ghost' . bin2hex(random_bytes(8));
		Server::get(TeamStateService::class)->ensure($ghost, 'Geist');
		$raw = $this->rawWorkspace('team', $ghost, $a->uid);
		$gn = self::rawNote($raw, 'Geisternotiz', $a->uid);
		self::notFound($a->call('GET', "/notes/$gn"), 'unbekannter Kreis');
		self::assertSame('active', self::teamState($ghost), 'Zustand unbekannter Kreis');
		// Austritt für unbekanntes Team löscht nichts
		Server::get(TeamLifecycleService::class)->memberLeft($t, $b->uid);
		self::assertSame([1, 1, true], self::tracesOf($b->uid, $n, $wsId), 'B ist noch Mitglied: nichts gelöscht');
		Teams::purgeTeamData($ghost);
	}

	// Done 4: Umwandeln durch created_by → privat mit allen Notizen, Spuren anderer weg, Freigabezeilen weg; andere 404, aktiv 403
	public function testMakePrivate(): void {
		$a = $this->createUser('ma');
		$b = $this->createUser('mb');
		$e = $this->createUser('me');
		$t = $this->teams->create($a->uid, 'Privat ' . bin2hex(random_bytes(3)));
		$this->teams->addUser($t, $a->uid, $b->uid);
		[$key, $wsId] = self::teamWs($a, $t, 'Mathe');
		$name = (string)self::wsRow($wsId)['name'];
		self::ok($a->call('POST', '/workspaces', ['name' => $name]), 'gleichnamiger privater Workspace');
		$nB = self::newNote($b, $key, 'Von B', '<p>b</p>');
		$nA = self::newNote($a, (string)Server::get(\OCA\NextNotes\Service\WorkspaceService::class)->keyFor($a->uid, $wsId), 'Von A', '<p>a</p>');
		self::traces($b->uid, $nB, $wsId);
		self::traces($a->uid, $nA, $wsId);
		self::rawShare($wsId, $e->uid);
		$orphanKey = (string)Server::get(\OCA\NextNotes\Service\WorkspaceService::class)->keyFor($a->uid, $wsId);
		self::assertNotSame($name, $orphanKey, 'Kollisionsschlüssel');
		self::assertSame(403, $a->call('POST', '/workspaces/' . rawurlencode($orphanKey) . '/make-private')->status, 'aktives Team');
		$this->teams->destroy($a->uid, $t, true);
		Server::get(\OCA\NextNotes\Service\WorkspaceService::class)->reset();
		$orphanKey = (string)Server::get(\OCA\NextNotes\Service\WorkspaceService::class)->keyFor($a->uid, $wsId);
		self::assertNotSame('', $orphanKey, 'created_by sieht den verwaisten Workspace');
		self::notFound($b->call('POST', '/workspaces/' . rawurlencode($orphanKey) . '/make-private'), 'B');
		self::notFound($e->call('POST', '/workspaces/' . rawurlencode($orphanKey) . '/make-private'), 'E mit Freigabezeile');
		$new = (string)self::ok($a->call('POST', '/workspaces/' . rawurlencode($orphanKey) . '/make-private'), 'Umwandeln')['name'];
		$row = self::wsRow($wsId);
		self::assertSame(['user', $a->uid, $name . ' Team'], [$row['owner_type'], $row['owner_id'], $row['name']], 'Besitz und Name');
		self::assertSame($name . ' Team', $new, 'Schlüssel');
		foreach ([$nA, $nB] as $n) {
			self::assertSame((string)$wsId, self::noteRow($n)['workspace_id'], "Notiz $n bleibt im Workspace");
		}
		self::assertSame($b->uid, self::noteRow($nB)['created_by'], 'Autor B bleibt erhalten');
		self::ok($a->call('PATCH', "/notes/$nB", ['heading' => 'Von B', 'content' => '<p>jetzt A</p>']), 'A schreibt B-Notiz');
		self::assertSame([0, 0, false], self::tracesOf($b->uid, $nB, $wsId), 'Spuren von B weg');
		self::assertSame([1, 1], array_slice(self::tracesOf($a->uid, $nA, $wsId), 0, 2), 'Erinnerung/Erwähnung von A bleiben');
		self::assertSame(0, self::rowCount('nextnotes_ws_shares', ['workspace_id' => $wsId]), 'Freigabezeilen entfernt');
		self::notFound($e->call('GET', "/notes/$nA"), 'E nach dem Umwandeln');
		self::notFound($b->call('GET', "/notes/$nB"), 'B nach dem Umwandeln');
		self::assertSame('orphaned', self::teamState($t), 'Team-Zeile bleibt');
	}

	// Done 5: Austritt direkt und über Gruppe räumt nur Spuren der UID in Team-Workspaces dieses Teams; zweiter Mitgliedschaftsweg → nichts (H1)
	public function testLeaveCleansUpOnlyTeamTraces(): void {
		$a = $this->createUser('xa');
		$b = $this->createUser('xb');
		$c = $this->createUser('xc');
		$t = $this->teams->create($a->uid, 'Austritt ' . bin2hex(random_bytes(3)));
		$t2 = $this->teams->create($a->uid, 'Bleibt ' . bin2hex(random_bytes(3)));
		$mB = $this->teams->addUser($t, $a->uid, $b->uid);
		$this->teams->addUser($t2, $a->uid, $b->uid);
		$mC = $this->teams->addUser($t, $a->uid, $c->uid);
		$grp = $this->teams->addGroup($t, $a->uid, [$c->uid]);
		[$key, $wsId] = self::teamWs($a, $t);
		[$key2, $ws2] = self::teamWs($a, $t2);
		[$pk, $pId] = self::newWorkspace($b, 'XP');
		$n = self::newNote($a, $key, 'Team', '<p>t</p>');
		$n2 = self::newNote($a, $key2, 'Team2', '<p>t</p>');
		$np = self::newNote($b, $pk, 'Privat', '<p>p</p>');
		$asg = $this->rawWorkspace('team', $t, $a->uid, 'assignments');
		$na = self::rawNote($asg, 'Aufgabe', $a->uid);
		self::traces($b->uid, $n, $wsId);
		self::traces($b->uid, $n2, $ws2);
		self::traces($b->uid, $np, $pId);
		self::traces($b->uid, $na, $asg);
		self::traces($c->uid, $n, $wsId);
		self::traces($a->uid, $n, $wsId);

		$this->teams->remove($a->uid, $mB);
		self::assertSame([0, 0, false], self::tracesOf($b->uid, $n, $wsId), 'B in Team-Workspace');
		self::assertSame([1, 1, true], self::tracesOf($b->uid, $n2, $ws2), 'B im anderen Team unberührt');
		self::assertSame([1, 1, true], self::tracesOf($b->uid, $np, $pId), 'B privat unberührt');
		self::assertSame([1, 1, true], self::tracesOf($b->uid, $na, $asg), 'kind=assignments unberührt');
		self::assertSame([1, 1, true], self::tracesOf($a->uid, $n, $wsId), 'A unberührt');

		// C: Gruppe weg, direkte Mitgliedschaft bleibt → nichts; dann direkt weg → aufgeräumt
		$this->teams->remove($a->uid, $grp);
		self::assertSame([1, 1, true], self::tracesOf($c->uid, $n, $wsId), 'C weiter direkt Mitglied');
		self::ok($c->call('GET', "/notes/$n"), 'C liest weiter');
		$this->teams->remove($a->uid, $mC);
		self::assertSame([0, 0, false], self::tracesOf($c->uid, $n, $wsId), 'C nach Austritt');

		// über Gruppe allein: D nur über Gruppe, Gruppe entfernen → aufgeräumt
		$d = $this->createUser('xd');
		$grp2 = $this->teams->addGroup($t, $a->uid, [$d->uid]);
		self::traces($d->uid, $n, $wsId);
		$this->teams->remove($a->uid, $grp2);
		self::assertSame([0, 0, false], self::tracesOf($d->uid, $n, $wsId), 'D nach Austritt über Gruppe');
	}

	// Review M5b-r1 H3: Benutzer wird aus einer NC-Gruppe entfernt (Klassenwechsel); über zweite Gruppe weiter Mitglied → nichts, sonst aufgeräumt
	public function testUserRemovedFromGroup(): void {
		$a = $this->createUser('ga');
		$c = $this->createUser('gc');
		$t = $this->teams->create($a->uid, 'Gruppe ' . bin2hex(random_bytes(3)));
		$this->teams->addGroup($t, $a->uid, [$c->uid]);
		$g1 = $this->teams->lastGroup;
		$this->teams->addGroup($t, $a->uid, [$c->uid]);
		$g2 = $this->teams->lastGroup;
		[$key, $wsId] = self::teamWs($a, $t);
		$n = self::newNote($a, $key, 'Gruppennotiz', '<p>g</p>');
		self::traces($c->uid, $n, $wsId);
		$um = Server::get(IUserManager::class);
		$gm = Server::get(\OCP\IGroupManager::class);
		$gm->get($g1)->removeUser($um->get($c->uid));
		Teams::reset();
		self::assertSame([1, 1, true], self::tracesOf($c->uid, $n, $wsId), 'C über zweite Gruppe weiter Mitglied');
		self::ok($c->call('GET', "/notes/$n"), 'C liest weiter');
		$gm->get($g2)->removeUser($um->get($c->uid));
		Teams::reset();
		self::notFound($c->call('GET', "/notes/$n"), 'C nach Entfernen aus beiden Gruppen');
		self::assertSame([0, 0, false], self::tracesOf($c->uid, $n, $wsId), 'C aufgeräumt');
	}

	// Review M5b-r1 BLOCKER: Zustand orphaned, Team existiert noch (z. B. Löschen nach DestroyingCircleEvent gescheitert) → 403, nichts geändert
	public function testMakePrivateRefusedWhileTeamExists(): void {
		$a = $this->createUser('pa');
		$b = $this->createUser('pb');
		$t = $this->teams->create($a->uid, 'Lebt ' . bin2hex(random_bytes(3)));
		$this->teams->addUser($t, $a->uid, $b->uid);
		[$key, $wsId] = self::teamWs($a, $t);
		$nB = self::newNote($b, $key, 'Von B', '<p>b</p>');
		self::traces($b->uid, $nB, $wsId);
		self::rawShare($wsId, $a->uid);
		self::setTeamState($t, 'orphaned');
		$r = $a->call('POST', '/workspaces/' . rawurlencode($key) . '/make-private');
		self::assertSame(403, $r->status, 'Umwandeln bei existierendem Team: ' . $r->describe());
		$row = self::wsRow($wsId);
		self::assertSame(['team', $t], [$row['owner_type'], $row['owner_id']], 'Besitz unverändert');
		self::assertSame((string)$wsId, self::noteRow($nB)['workspace_id'], 'Notiz unverändert');
		self::assertSame([1, 1, true], self::tracesOf($b->uid, $nB, $wsId), 'Spuren von B unverändert');
		self::assertSame(1, self::rowCount('nextnotes_ws_shares', ['workspace_id' => $wsId]), 'Freigabezeile unverändert');
		self::assertSame('orphaned', self::teamState($t));
	}

	// Done 5a: Listener werfen nie (Adapter wirft), Log-Eintrag
	public function testListenersNeverThrow(): void {
		$teams = $this->createStub(TeamService::class);
		$teams->method('removedMemberships')->willReturn([['team' => 't1', 'single' => 's1'], ['team' => 't2', 'single' => 's2']]);
		$teams->method('uidForSingleId')->willReturn('u1');
		$teams->method('destroyedTeam')->willReturn(['id' => 't1', 'name' => 'T']);
		$lifecycle = $this->createMock(TeamLifecycleService::class);
		$lifecycle->method('hasTeamWorkspaces')->willReturn(true);
		$lifecycle->expects(self::exactly(2))->method('memberLeft')->willThrowException(new \RuntimeException('Adapter wirft'));
		$lifecycle->method('teamDeleted')->willThrowException(new TeamUnavailableException('Adapter wirft'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::exactly(3))->method('error');
		(new MembershipsRemovedListener($teams, $lifecycle, $logger))->handle(new Event());
		(new CircleDestroyedListener($teams, $lifecycle, $logger))->handle(new Event());

		// echte Circles-Aufrufe mit Team-Workspace laufen durch (Listener im Prozess)
		$a = $this->createUser('na');
		$b = $this->createUser('nb');
		$t = $this->teams->create($a->uid, 'Wirft ' . bin2hex(random_bytes(3)));
		$m = $this->teams->addUser($t, $a->uid, $b->uid);
		self::teamWs($a, $t);
		$this->teams->remove($a->uid, $m);
		$this->teams->destroy($a->uid, $t, true);
		self::assertSame('orphaned', self::teamState($t));
	}

	// Done 6: Kontolöschung des created_by: created_by NULL, Workspace bleibt für Mitglieder, Hintergrund weg; neue gleiche UID erbt nichts
	public function testAccountDeletionOfCreator(): void {
		$o = $this->createUser('ko');
		$m = $this->createUser('km');
		$b = $this->createUser('kb');
		$t = $this->teams->create($o->uid, 'Konto ' . bin2hex(random_bytes(3)));
		$this->teams->addUser($t, $o->uid, $m->uid, Teams::MODERATOR);
		$this->teams->addUser($t, $o->uid, $b->uid);
		[$key, $wsId] = self::teamWs($m, $t);
		$nM = self::newNote($m, $key, 'Von M', '<p>m</p>');
		Server::get(BackgroundFiles::class)->folder($wsId, $m->uid, true)->newFile('bg.png', 'png');
		// einfaches Mitglied (nicht created_by): Hintergrund über die vor der Löschung gemerkten Teams (H1)
		$k = $this->createUser('kk');
		$this->teams->addUser($t, $o->uid, $k->uid);
		$bg = Server::get(BackgroundFiles::class);
		$bg->folder($wsId, $k->uid, true)->newFile('bg.png', 'png');
		$other = $this->rawWorkspace('team', 'fremdesteam' . bin2hex(random_bytes(4)), $o->uid);
		$bg->folder($other, $k->uid, true)->newFile('bg.png', 'png');
		$kUid = $k->uid;
		Server::get(IUserManager::class)->get($kUid)->delete();
		self::assertNull($bg->folder($wsId, $kUid, false), 'Hintergrund des Mitglieds im Team-Workspace');
		self::assertNotNull($bg->folder($other, $kUid, false), 'fremdes Team nicht angefasst (gezielt, nicht alle Team-Workspaces)');
		$bg->folder($other, $kUid, false)?->delete();
		$uid = $m->uid;
		Server::get(IUserManager::class)->get($uid)->delete();
		Teams::reset();
		self::assertNull(self::wsRow($wsId)['created_by'], 'workspaces.created_by');
		self::assertNull(self::noteRow($nM)['created_by'], 'notes.created_by');
		self::assertNull(Server::get(BackgroundFiles::class)->folder($wsId, $uid, false), 'Hintergrund von M');
		self::ok($b->call('GET', "/notes/$nM"), 'B liest weiter');

		// gleiche UID neu: wieder Mitglied, aber keine Eigene-Notiz-Ausnahme und kein created_by-Lesen
		$again = $this->recreate($uid);
		try {
			$this->teams->addUser($t, $o->uid, $uid);
			[$pk] = self::newWorkspace($again, 'KP');
			self::assertSame(403, $again->call('PATCH', "/notes/$nM", ['workspace' => $pk])->status, 'alte Notiz in privaten Workspace');
			self::setTeamState($t, 'orphaned');
			self::notFound($again->call('GET', "/notes/$nM"), 'neue UID liest verwaisten Workspace');
		} finally {
			self::purgeAppData($uid);
			Server::get(IUserManager::class)->get($uid)?->delete();
			// entkoppelte Workspaces beider Löschungen sofort entfernen (sonst Reste für UserDeletionTest)
			Server::get(\OCA\NextNotes\Service\UserDataService::class)->sweep(60);
		}
	}

	// M5a-r2 H1: gegenläufiges Verschieben privat ↔ Team mit Anhängen verklemmt nicht (mariadb/pgsql)
	public function testOppositeMovesDoNotDeadlock(): void {
		$a = $this->createUser('dl');
		$t = $this->teams->create($a->uid, 'Sperre ' . bin2hex(random_bytes(3)));
		[$tk] = self::teamWs($a, $t);
		[$pk] = self::newWorkspace($a, 'DP');
		$fp = (int)self::ok($a->call('POST', '/folders', ['folder_name' => 'FP', 'workspace' => $pk]), 'Ordner privat')['folder_id'];
		$ft = (int)self::ok($a->call('POST', '/folders', ['folder_name' => 'FT', 'workspace' => $tk]), 'Ordner Team')['folder_id'];
		foreach ([[$pk, $fp], [$tk, $ft]] as [$ws, $f]) {
			for ($i = 0; $i < 3; $i++) {
				$n = self::newNote($a, $ws, "N$f-$i", '<p>n</p>', $f);
				self::ok($a->upload("/notes/$n/attachments", 'a.bin', str_repeat('a', 2000)), 'Anhang');
			}
		}
		$where = [$fp => [$pk, $tk], $ft => [$tk, $pk]];
		for ($round = 0; $round < 8; $round++) {
			$res = $a->parallel([
				['POST', "/folders/$fp/move", ['target_workspace' => $where[$fp][1]]],
				['POST', "/folders/$ft/move", ['target_workspace' => $where[$ft][1]]],
			]);
			foreach ($res as $i => $r) {
				self::assertSame(200, $r->status, "Runde $round, Zug $i: " . $r->describe());
			}
			$where[$fp] = array_reverse($where[$fp]);
			$where[$ft] = array_reverse($where[$ft]);
		}
	}

	// M5a-r2 H2: Bytes unter Sperre neu gezählt, auch wenn die Vorprüfung 0 ergab
	public function testLeaveQuotaCountsBytesUnderLock(): void {
		$a = $this->createUser('qb');
		$t = $this->teams->create($a->uid, 'Quote ' . bin2hex(random_bytes(3)));
		[, $tId] = self::teamWs($a, $t);
		[, $pId] = self::newWorkspace($a, 'QP');
		$n = self::rawNote($pId, 'Ohne Anhang', $a->uid);
		$mapper = Server::get(WorkspaceMapper::class);
		$quota = Server::get(\OCA\NextNotes\Service\QuotaService::class);
		$leave = new \OCA\NextNotes\Service\LeaveRequest($mapper->find($pId), $mapper->find($tId), \OCA\NextNotes\Service\LeaveRequest::NOTES, [$n]);
		$q = $quota->forLeave($leave);
		self::assertNotNull($q, 'Besitzerwechsel ohne Bytes wird trotzdem unter Sperre geprüft');
		// paralleler Upload nach der Vorprüfung
		$qb = self::db()->getQueryBuilder();
		$qb->insert('nextnotes_attachments')->values([
			'note_id' => $qb->createNamedParameter($n, IQueryBuilder::PARAM_INT),
			'original_name' => $qb->createNamedParameter('gross.bin'),
			'mime' => $qb->createNamedParameter('application/octet-stream'),
			'size' => $qb->createNamedParameter($quota->limit() + 1, IQueryBuilder::PARAM_INT),
			'created_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
		])->executeStatement();
		$db = self::db();
		$db->beginTransaction();
		try {
			$quota->enforce($q);
			self::fail('Quote unter Sperre nicht geprüft');
		} catch (\OCA\NextNotes\Service\Exception\TooLargeException) {
		} finally {
			$db->rollBack();
		}
	}

	private function recreate(string $uid): ApiClient {
		$user = Server::get(IUserManager::class)->createUser($uid, self::PASSWORD);
		self::assertNotFalse($user, "Benutzer $uid nicht neu angelegt");
		$token = Server::get(ISecureRandom::class)->generate(72, ISecureRandom::CHAR_ALPHANUMERIC);
		Server::get(IProvider::class)->generateToken($token, $uid, $uid, null, 'nn-test', IToken::PERMANENT_TOKEN);
		return new ApiClient($uid, $token);
	}
}
