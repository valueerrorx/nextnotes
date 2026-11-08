<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Admin;

use OCA\NextNotes\BackgroundJob\AssignmentDeadlineJob;
use OCA\NextNotes\Command\DeleteUserData;
use OCA\NextNotes\Db\AssignmentMapper;
use OCA\NextNotes\Service\AccessService;
use OCA\NextNotes\Service\BackgroundFiles;
use OCA\NextNotes\Service\DistributionService;
use OCA\NextNotes\Service\TeamService;
use OCA\NextNotes\Service\WorkspaceService;
use OCA\NextNotes\Tests\Assignments\NotificationRows;
use OCA\NextNotes\Tests\Support\Teams;
use OCP\BackgroundJob\IJobList;
use OCP\Config\IUserConfig;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use OCP\Server;

// M7b Done 2–6: occ nextnotes:delete-user-data nur für deaktivierte/gelöschte Konten, Ablauf wie Kontolöschung, synchron
final class DeleteUserDataTest extends AdminTestCase {
	use NotificationRows;

	protected function setUp(): void {
		parent::setUp();
		self::loadNotifications();
	}

	/** @return array{0: array<string, string>, 1: list<string>, 2: list<string>, 3: int} Zustand: Tabellen, IAppData, App-Werte, Benachrichtigungen */
	private static function state(string $uid): array {
		return [self::tableSums(), self::appDataFiles(), Server::get(IUserConfig::class)->getKeys($uid, 'nextnotes'), self::notificationCount($uid)];
	}

	/**
	 * Schüler S1 mit Abgabe (abgegeben) in Aufgabe A und offener Abgabe in Aufgabe B (beide ausgeteilt, Frist offen),
	 * eigenem Workspace, App-Wert, Hintergrund in Team-Workspace und geteiltem Workspace, offener Benachrichtigung
	 * @return array<string, mixed>
	 */
	private function studentScenario(): array {
		$fx = $this->distributedFixture();
		['l' => $l, 's1' => $s1, 'n' => $n, 't' => $t, 'wsId' => $teamWsId, 'src' => $src] = $fx;
		$uid = $s1->uid;
		$mk = 'du' . bin2hex(random_bytes(4));
		$sid = (int)$fx['subs'][$uid]['id'];
		$note = (int)$fx['subs'][$uid]['note_id'];
		self::ok($s1->call('PATCH', "/notes/$note", ['content' => "<p>arbeit-$mk</p>"]), 'S1 arbeitet');
		self::ok($s1->call('POST', "/submissions/$sid/submit"), 'S1 gibt ab');
		$b = $this->createAssignment($l, $t, $src, "B $mk");
		self::ok($l->call('POST', "/assignments/{$b['id']}/distribute"), 'B austeilen');
		$sidB = (int)self::submissionsOf((int)$b['id'])[$uid]['id'];
		[$own] = self::newWorkspace($s1, 'Eigen');
		self::newNote($s1, $own, "Eigen $mk", "<p>privat-$mk</p>");
		[, $sharedId] = self::newWorkspace($n, 'Geteilt');
		Server::get(WorkspaceService::class)->share($n->uid, $sharedId, $uid);
		Server::get(IUserConfig::class)->setValueString($uid, 'nextnotes', 'm7b_probe', 'x');
		$bg = Server::get(BackgroundFiles::class);
		foreach ([$teamWsId, $sharedId] as $wsId) {
			$bg->folder($wsId, $uid, true)->newFile('bg.png', (string)base64_decode(self::PNG));
		}
		Server::get(INotificationManager::class)->notify(self::notice($uid, 'note', $note, 'reminder'));
		self::assertGreaterThan(0, self::notificationCount($uid), 'Kontrolle: Benachrichtigung vorhanden');
		self::assertNotSame([], self::uidHits($uid), 'Kontrolle: UID-Spalten belegt');
		return $fx + ['uid' => $uid, 'mk' => $mk, 'sid' => $sid, 'note' => $note, 'bId' => (int)$b['id'], 'sidB' => $sidB, 'teamWsId' => $teamWsId, 'sharedId' => $sharedId];
	}

	// Benutzer nur aus dem Backend entfernen: kein UserDeletedEvent (gescheiterter Listener, LDAP-Konto weg)
	private static function backendDelete(string $uid): void {
		$um = Server::get(IUserManager::class);
		self::assertTrue($um->get($uid)->getBackend()->deleteUser($uid), 'Backend-Löschung');
		// Request-Cache des Managers leeren (sonst liefert get() das alte Objekt)
		$prop = new \ReflectionProperty($um, 'cachedUsers');
		$cached = $prop->getValue($um);
		unset($cached[$uid]);
		$prop->setValue($um, $cached);
		self::assertNull(Server::get(IUserManager::class)->get($uid), 'Kontrolle: Konto nicht auffindbar');
	}

	private static function disable(string $uid): void {
		Server::get(IUserManager::class)->get($uid)->setEnabled(false);
	}

	// Done 2: aktives Konto → Abbruch, nichts geändert
	public function testActiveAccountAbortsWithoutChange(): void {
		$fx = $this->studentScenario();
		$before = self::state($fx['uid']);
		[$rc, $out] = self::runCommand(DeleteUserData::class, ['uid' => $fx['uid']]);
		self::assertSame(1, $rc, $out);
		self::assertStringContainsString('Disable or delete the account first', $out);
		self::assertSame($before, self::state($fx['uid']), 'Tabellen, IAppData, App-Werte, Benachrichtigungen unverändert');
	}

	// Done 3 + 5: deaktivierter Schüler, Abgaben bleiben für die Lehrkraft, Nebenwerte sofort weg, kein Nachzügler-Austeilen
	public function testDisabledStudent(): void {
		$fx = $this->studentScenario();
		['l' => $l, 'uid' => $uid, 'mk' => $mk, 'sid' => $sid, 'note' => $note, 'aid' => $aid, 'bId' => $bId] = $fx;
		self::disable($uid);
		$attBefore = self::countRows('nextnotes_attachments', 'note_id', $note);
		$wsBefore = self::ownedWorkspaceIds($uid);
		self::assertGreaterThanOrEqual(2, count($wsBefore), 'Kontrolle: eigene Workspaces und Schüler-Workspace');
		[$rc, $out] = self::runCommand(DeleteUserData::class, ['uid' => $uid]);
		self::assertSame(0, $rc, $out);

		self::assertSame([], self::uidHits($uid), 'keine UID-Spalte trägt die UID');
		self::assertSame([], self::ownedWorkspaceIds($uid), 'eigene Workspaces gelöscht');
		foreach ($wsBefore as $wsId) {
			self::assertNull(self::row('nextnotes_workspaces', $wsId), "Workspace $wsId synchron gelöscht, kein Rest für den Job");
		}
		foreach ([$sid, $fx['sidB']] as $s) {
			$row = self::row('nextnotes_submissions', $s);
			self::assertSame([null, $uid], [$row['student_uid'], $row['student_uid_former']], "Abgabe $s gelöst");
		}
		self::assertSame('submitted', self::row('nextnotes_submissions', $sid)['status']);
		self::assertStringContainsString("arbeit-$mk", (string)self::row('nextnotes_notes', $note)['content'], 'Inhalt bleibt');
		self::assertSame($attBefore, self::countRows('nextnotes_attachments', 'note_id', $note), 'Anhänge bleiben');
		$view = self::ok($l->call('GET', "/submissions/$sid"), 'Lehrkraft liest Abgabe')['submission'];
		self::assertSame($uid, $view['student_uid_former']);

		// Done 5: App-Werte, Hintergründe (Team-Workspace = Teams gemerkt, geteilter Workspace), Benachrichtigung
		self::assertSame([], Server::get(IUserConfig::class)->getKeys($uid, 'nextnotes'), 'App-Werte gelöscht');
		$bg = Server::get(BackgroundFiles::class);
		self::assertNull($bg->folder($fx['teamWsId'], $uid, false), 'Hintergrund Team-Workspace entfernt');
		self::assertNull($bg->folder($fx['sharedId'], $uid, false), 'Hintergrund geteilter Workspace entfernt');
		self::assertSame(0, self::notificationCount($uid), 'Benachrichtigung verarbeitet');

		// Nachzügler: Konto bleibt Circles-Mitglied; Job und Lehrkraft-Liste teilen nicht neu aus
		Server::get(TeamService::class)->resetCache();
		Server::get(AccessService::class)->resetCache();
		Server::get(AssignmentDeadlineJob::class)->start(Server::get(IJobList::class));
		self::ok($l->call('GET', '/assignments'), 'Lehrkraft-Liste');
		foreach ([$aid, $bId] as $a) {
			self::assertArrayNotHasKey($uid, self::submissionsOf($a), "keine neue Kopie in Aufgabe $a");
		}
		self::assertSame([], self::uidHits($uid), 'auch danach keine UID');
		// Review r1 Hinweis 1: deaktiviertes Mitglied zählt nicht als „noch nicht ausgeteilt“
		$pending = Server::get(DistributionService::class)->pending(Server::get(AssignmentMapper::class)->find($bId));
		self::assertSame(0, $pending['not_distributed'], 'Hinweis ohne deaktiviertes Konto');
	}

	// Review r1 Blocker 2: abweichende Schreibweise löscht die Daten der echten UID
	public function testUidOtherCase(): void {
		$fx = $this->studentScenario();
		$uid = $fx['uid'];
		self::disable($uid);
		self::assertNotSame($uid, strtoupper($uid), 'Kontrolle: Schreibweise weicht ab');
		[$rc, $out] = self::runCommand(DeleteUserData::class, ['uid' => strtoupper($uid)]);
		self::assertSame(0, $rc, $out);
		self::assertStringContainsString("data of $uid deleted", $out);
		self::assertSame([], self::uidHits($uid), 'keine UID-Spalte trägt die UID');
		self::assertSame([], self::ownedWorkspaceIds($uid), 'eigene Workspaces gelöscht');
		self::assertSame([], Server::get(IUserConfig::class)->getKeys($uid, 'nextnotes'), 'App-Werte gelöscht');
		self::assertSame(0, self::notificationCount($uid), 'Benachrichtigung verarbeitet');
	}

	// Review r1 Hinweis 2: nur Benachrichtigung und Hintergrund-Rest (fremder Workspace ohne Freigabe) gelten als Daten
	public function testLeftoverNotificationAndBackground(): void {
		$u = $this->createUser('lo');
		$n = $this->createUser('ln');
		[, $wsId] = self::newWorkspace($n, 'Fremd');
		$bg = Server::get(BackgroundFiles::class);
		$bg->folder($wsId, $u->uid, true)->newFile('bg.png', (string)base64_decode(self::PNG));
		Server::get(INotificationManager::class)->notify(self::notice($u->uid, 'workspace', $wsId, 'reminder'));
		self::assertGreaterThan(0, self::notificationCount($u->uid), 'Kontrolle: Benachrichtigung vorhanden');
		self::backendDelete($u->uid);
		self::assertSame([], self::uidHits($u->uid), 'Kontrolle: keine Zeile trägt die UID');

		[$rc, $out] = self::runCommand(DeleteUserData::class, ['uid' => $u->uid]);
		self::assertSame(1, $rc, $out);
		self::assertStringContainsString('notifications: ', $out);
		self::assertStringContainsString('backgrounds: 1', $out);
		[$rc, $out] = self::runCommand(DeleteUserData::class, ['uid' => $u->uid, '--user-deleted' => true]);
		self::assertSame(0, $rc, $out);
		self::assertSame(0, self::notificationCount($u->uid), 'Benachrichtigung verarbeitet');
		self::assertNull($bg->folder($wsId, $u->uid, false), 'Hintergrund-Rest entfernt');
		self::assertNotNull(self::row('nextnotes_workspaces', $wsId), 'fremder Workspace bleibt');
	}

	/**
	 * Review r1 Blocker 1: Team-Owner D (höchstes Level, Sortierung zuerst) deaktiviert und geleert, danach wird Lehrkraft K gelöscht
	 * @return string|null neuer owner_uid ('active' = aktive Co-Lehrkraft A)
	 */
	private function successorAfterDisabledOwner(bool $withActive): ?string {
		$d = $this->createUser('td', 'Lehrkraft D');
		$k = $this->createUser('tk', 'Lehrkraft K');
		$t = $this->teams->create($d->uid, 'T ' . bin2hex(random_bytes(3)));
		$this->teams->addUser($t, $d->uid, $k->uid, Teams::MODERATOR);
		$a = null;
		if ($withActive) {
			$a = $this->createUser('ta', 'Lehrkraft A');
			$this->teams->addUser($t, $d->uid, $a->uid, Teams::MODERATOR);
		}
		[$ws] = self::teamWs($k, $t, 'TK');
		$src = self::newNote($k, $ws, 'Quelle', '<p>q</p>');
		$aid = (int)$this->createAssignment($k, $t, $src, 'Von K')['id'];
		self::disable($d->uid);
		[$rc, $out] = self::runCommand(DeleteUserData::class, ['uid' => $d->uid]);
		self::assertSame(0, $rc, $out);
		Server::get(TeamService::class)->resetCache();
		self::assertTrue(Server::get(IUserManager::class)->get($k->uid)->delete(), 'Kontolöschung K');
		$owner = self::row('nextnotes_assignments', $aid)['owner_uid'];
		self::assertNotSame($d->uid, $owner, 'deaktiviertes Konto wird nie Nachfolger');
		return $owner === null ? null : ($owner === $a?->uid ? 'active' : 'other:' . $owner);
	}

	public function testDisabledCoTeacherNeverSuccessor(): void {
		self::assertSame('active', $this->successorAfterDisabledOwner(true), 'aktive Co-Lehrkraft übernimmt');
		self::assertNull($this->successorAfterDisabledOwner(false), 'ohne aktive Co-Lehrkraft gesperrt wie bisher');
	}

	// Done 6: Konto im Backend gelöscht, Listener nie gelaufen; ohne Bestätigung nichts, mit Bestätigung wie 3/5, zweiter Lauf ohne Änderung
	public function testDeletedAccountNeedsConfirmation(): void {
		$fx = $this->studentScenario();
		['uid' => $uid, 'sid' => $sid] = $fx;
		self::backendDelete($uid);

		$before = self::state($uid);
		[$rc, $out] = self::runCommand(DeleteUserData::class, ['uid' => $uid]);
		self::assertSame(1, $rc, $out);
		self::assertStringContainsString('--user-deleted', $out);
		self::assertSame($before, self::state($uid), 'ohne Bestätigung unverändert');
		[$rc, $out] = self::runCommand(DeleteUserData::class, ['uid' => $uid], 'falsch');
		self::assertSame(1, $rc, $out);
		self::assertSame($before, self::state($uid), 'falsche Eingabe unverändert');

		[$rc, $out] = self::runCommand(DeleteUserData::class, ['uid' => $uid], $uid);
		self::assertSame(0, $rc, $out);
		self::assertSame([], self::uidHits($uid), 'keine UID-Spalte trägt die UID');
		self::assertSame([null, $uid], [self::row('nextnotes_submissions', $sid)['student_uid'], self::row('nextnotes_submissions', $sid)['student_uid_former']]);
		self::assertSame([], Server::get(IUserConfig::class)->getKeys($uid, 'nextnotes'), 'App-Werte gelöscht');
		self::assertNull(Server::get(BackgroundFiles::class)->folder($fx['sharedId'], $uid, false), 'Hintergrund geteilter Workspace entfernt');
		self::assertSame(0, self::notificationCount($uid), 'Benachrichtigung verarbeitet');

		$after = self::state($uid);
		[$rc, $out] = self::runCommand(DeleteUserData::class, ['uid' => $uid, '--user-deleted' => true]);
		self::assertSame(0, $rc, $out);
		self::assertStringContainsString('nothing to do', $out);
		self::assertSame($after, self::state($uid), 'zweiter Lauf ohne Änderung');
	}

	public function testUnknownUserWithoutData(): void {
		$before = self::tableSums();
		[$rc, $out] = self::runCommand(DeleteUserData::class, ['uid' => 'nn-nobody-' . bin2hex(random_bytes(4))]);
		self::assertSame(0, $rc, $out);
		self::assertStringContainsString('nothing to do', $out);
		self::assertSame($before, self::tableSums());
	}

	/**
	 * Done 4: Aufgabe einer Lehrkraft K; $co = Team-Owner L ist Co-Lehrkraft, sonst ist K einziges Mitglied (Owner)
	 * @return array{0: ?string, 1: ?string} owner_uid ('co' = L) und created_by danach
	 */
	private function teacherCase(bool $viaCommand, bool $co): array {
		$k = $this->createUser('tk', 'Lehrkraft K');
		$s = $this->createUser('ts');
		if ($co) {
			$l = $this->createUser('tl', 'Lehrkraft L');
			$t = $this->teams->create($l->uid, 'T ' . bin2hex(random_bytes(3)));
			$this->teams->addUser($t, $l->uid, $k->uid, \OCA\NextNotes\Tests\Support\Teams::MODERATOR);
			$owner = $l;
		} else {
			$t = $this->teams->create($k->uid, 'T ' . bin2hex(random_bytes(3)));
			$owner = $k;
			$l = null;
		}
		// allein: ohne weitere Mitglieder (Circles macht sonst beim Löschen des Owners ein Mitglied zum Owner = neue Lehrkraft)
		if ($co) {
			$this->teams->addUser($t, $owner->uid, $s->uid);
		}
		[$ws] = self::teamWs($k, $t, 'TK');
		$src = self::newNote($k, $ws, 'Quelle', '<p>q</p>');
		$aid = (int)$this->createAssignment($k, $t, $src, 'Von K')['id'];
		self::assertSame($k->uid, self::row('nextnotes_assignments', $aid)['owner_uid'], 'Kontrolle: K verantwortlich');
		if ($viaCommand) {
			self::disable($k->uid);
			[$rc, $out] = self::runCommand(DeleteUserData::class, ['uid' => $k->uid]);
			self::assertSame(0, $rc, $out);
		} else {
			self::assertTrue(Server::get(IUserManager::class)->get($k->uid)->delete(), 'Kontolöschung K');
		}
		$row = self::row('nextnotes_assignments', $aid);
		return [$row['owner_uid'] === null ? null : ($row['owner_uid'] === $l?->uid ? 'co' : 'other:' . $row['owner_uid']), $row['created_by']];
	}

	public function testTeacherWithCoTeacherSameAsAccountDeletion(): void {
		$cmd = $this->teacherCase(true, true);
		$del = $this->teacherCase(false, true);
		self::assertSame(['co', null], $cmd, 'delete-user-data: Co-Lehrkraft übernimmt');
		self::assertSame($del, $cmd, 'gleich wie Kontolöschung');
	}

	public function testTeacherAloneSameAsAccountDeletion(): void {
		$cmd = $this->teacherCase(true, false);
		$del = $this->teacherCase(false, false);
		self::assertSame([null, null], $cmd, 'delete-user-data: gesperrt (owner_uid NULL)');
		self::assertSame($del, $cmd, 'gleich wie Kontolöschung');
	}
}
