<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Admin;

use OCA\NextNotes\Command\Purge;
use OCA\NextNotes\Service\BackgroundFiles;
use OCA\NextNotes\Service\WorkspaceService;
use OCA\NextNotes\Tests\Assignments\NotificationRows;
use OCP\BackgroundJob\IJobList;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use OCP\Notification\IManager as INotificationManager;
use OCP\Server;
use PHPUnit\Framework\Attributes\Group;

// M7b Done 7: occ nextnotes:purge löscht alle Daten der App; zerstört alle Testdaten → nur mit --group destructive (dev/nextcloud/check-purge.sh)
#[Group('destructive')]
final class PurgeTest extends AdminTestCase {
	use NotificationRows;

	/** @return array<string, mixed> Daten in allen Tabellen, IAppData, App-/Benutzerwerten, Benachrichtigung */
	private function seed(): array {
		self::loadNotifications();
		$fx = $this->distributedFixture();
		['l' => $l, 's1' => $s1, 'n' => $n] = $fx;
		$sid = (int)$fx['subs'][$s1->uid]['id'];
		$note = (int)$fx['subs'][$s1->uid]['note_id'];
		self::ok($s1->call('POST', "/submissions/$sid/submit"), 'abgeben');
		[$ws, $wsId] = self::newWorkspace($l, 'P');
		$own = self::newNote($l, $ws, 'Purge', "<p>Hallo @{$n->uid}</p>");
		self::ok($l->call('PUT', "/notes/$own/tags", ['tags' => 'purge']), 'Tag');
		self::ok($l->call('POST', "/notes/$own/snapshot?manual=1"), 'Snapshot');
		self::ok($l->call('POST', "/notes/$own/reminder", ['reminder_at' => '2030-01-01T08:00:00Z']), 'Erinnerung');
		self::ok($l->call('POST', "/notes/$own/lock", ['editor_session_id' => 'p1'], ['X-Editor-Session-ID: p1']), 'Sperre');
		Server::get(WorkspaceService::class)->share($l->uid, $wsId, $n->uid);
		self::ok($n->call('POST', "/mentions/{$l->uid}/$own/handled"), 'Erwähnung markiert');
		Server::get(BackgroundFiles::class)->folder($wsId, $l->uid, true)->newFile('bg.png', 'x');
		Server::get(IUserConfig::class)->setValueString($l->uid, 'nextnotes', 'm7b_probe', 'x');
		Server::get(IAppConfig::class)->setValueString('nextnotes', 'm7b_probe', 'x');
		Server::get(INotificationManager::class)->notify(self::notice($l->uid, 'note', $own, 'reminder'));
		// M9a Done 6a: OneNote-Zeilen und App-Config onenote_* inkl. sensiblem Secret
		self::seedOneNote($l->uid);
		foreach (Purge::TABLES as $table) {
			$qb = self::db()->getQueryBuilder();
			self::assertGreaterThan(0, (int)$qb->select($qb->func()->count('*'))->from($table)->executeQuery()->fetchOne(), "Kontrolle: $table gefüllt");
		}
		self::assertNotSame([], self::appDataFiles(), 'Kontrolle: IAppData');
		self::assertGreaterThan(0, self::notificationCount(), 'Kontrolle: Benachrichtigung');
		self::assertGreaterThan(0, self::jobCount(), 'Kontrolle: Jobs');
		return $fx + ['note' => $note];
	}

	public const ONENOTE_SECRET = 'purge-onenote-secret-M9a';

	private static function seedOneNote(string $uid): void {
		$db = self::db();
		$qb = $db->getQueryBuilder();
		$qb->insert('nextnotes_onenote_imports')->values([
			'uid' => $qb->createNamedParameter($uid), 'status' => $qb->createNamedParameter('select'),
			'authority' => $qb->createNamedParameter('organizations'), 'client_id' => $qb->createNamedParameter('11111111-2222-3333-4444-555555555555'),
			'created_at' => $qb->createNamedParameter(1), 'updated_at' => $qb->createNamedParameter(1),
		])->executeStatement();
		$qb = $db->getQueryBuilder();
		$qb->insert('nextnotes_onenote_items')->values([
			'uid' => $qb->createNamedParameter($uid), 'source_hash' => $qb->createNamedParameter(hash('sha256', 'page:purge')),
			'kind' => $qb->createNamedParameter('page'), 'target_id' => $qb->createNamedParameter(1),
			'import_id' => $qb->createNamedParameter(1), 'created_at' => $qb->createNamedParameter(1),
		])->executeStatement();
		$cfg = Server::get(IAppConfig::class);
		$cfg->setValueString('nextnotes', 'onenote_client_id', '11111111-2222-3333-4444-555555555555');
		$cfg->setValueString('nextnotes', 'onenote_secret', self::ONENOTE_SECRET, lazy: true, sensitive: true);
	}

	/** @return int Zeilen in oc_appconfig mit onenote_*-Schlüssel oder dem Secret im Klartext */
	private static function oneNoteConfigRows(): int {
		$qb = self::db()->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from('appconfig')
			->where($qb->expr()->eq('appid', $qb->createNamedParameter('nextnotes')))
			->andWhere($qb->expr()->orX(
				$qb->expr()->like('configkey', $qb->createNamedParameter(self::db()->escapeLikeParameter('onenote_') . '%')),
				$qb->expr()->like('configvalue', $qb->createNamedParameter('%' . self::db()->escapeLikeParameter(self::ONENOTE_SECRET) . '%')),
			));
		return (int)$qb->executeQuery()->fetchOne();
	}

	private static function jobCount(): int {
		$n = 0;
		foreach (Purge::jobClasses() as $class) {
			foreach (Server::get(IJobList::class)->getJobsIterator($class, null, 0) as $job) {
				$n++;
			}
		}
		return $n;
	}

	/** @return array<string, mixed> */
	private static function state(): array {
		return [self::tableSums(), self::appDataFiles(), Server::get(IAppConfig::class)->getKeys('nextnotes'),
			Server::get(IUserConfig::class)->getUserIds('nextnotes'), self::notificationCount(), self::jobCount()];
	}

	public function testPurge(): void {
		$this->seed();
		$before = self::state();
		[$rc, $out] = self::runCommand(Purge::class);
		self::assertSame(0, $rc, $out);
		self::assertStringContainsString('Nothing was deleted', $out);
		self::assertStringContainsString('maintenance mode is off', $out, 'Warnung ohne Wartungsmodus');
		self::assertSame($before, self::state(), 'ohne --force unverändert');

		[$rc, $out] = self::runCommand(Purge::class, ['--force' => true]);
		self::assertSame(1, $rc, $out);
		self::assertSame($before, self::state(), '--force ohne Bestätigung unverändert');
		[$rc, $out] = self::runCommand(Purge::class, ['--force' => true], 'nextnote');
		self::assertSame(1, $rc, $out);
		self::assertSame($before, self::state(), 'falsche App-ID unverändert');
		[$rc, $out] = self::runCommand(Purge::class, ['--force' => true, '--confirm' => 'other']);
		self::assertSame(1, $rc, $out);
		self::assertSame($before, self::state(), 'falsches --confirm unverändert');

		[$rc, $out] = self::runCommand(Purge::class, ['--force' => true], 'nextnotes');
		self::assertSame(0, $rc, $out);
		foreach (self::tableSums() as $table => $sum) {
			self::assertStringStartsWith('0:', $sum, "$table leer");
		}
		self::assertSame([], self::appDataFiles(), 'IAppData leer');
		$keys = Server::get(IAppConfig::class)->getKeys('nextnotes');
		sort($keys);
		self::assertSame(['enabled', 'installed_version', 'types'], $keys, 'nur Kern-Schlüssel bleiben');
		self::assertSame(0, self::oneNoteConfigRows(), 'kein onenote_*-Schlüssel und kein Secret in oc_appconfig');
		self::assertSame([], Server::get(IUserConfig::class)->getUserIds('nextnotes'), 'keine Benutzerwerte');
		self::assertSame(0, self::notificationCount(), 'keine Benachrichtigungen');
		self::assertSame(0, self::jobCount(), 'keine Jobs');
		self::assertStringContainsString('occ app:remove', $out);

		// erneut: idempotent, nicht-interaktiv mit --confirm
		[$rc, $out] = self::runCommand(Purge::class, ['--force' => true, '--confirm' => 'nextnotes']);
		self::assertSame(0, $rc, $out);
	}
}
