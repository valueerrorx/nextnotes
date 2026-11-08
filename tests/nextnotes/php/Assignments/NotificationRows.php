<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Assignments;

use OCA\NextNotes\Notification\Notifier;
use OCA\NextNotes\Service\AccessService;
use OCA\NextNotes\Service\AssignmentDeadlineService;
use OCA\NextNotes\Service\TeamService;
use OCP\App\IAppManager;
use OCP\Notification\AlreadyProcessedException;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use OCP\Server;

// M6e2: Benachrichtigungen aus oc_notifications lesen, Notifier direkt aufrufen, Fristen-Job ausführen
trait NotificationRows {
	// wie cron.php: ohne geladene notifications-App verwirft IManager::notify still (ReminderJobTest)
	protected static function loadNotifications(): void {
		Server::get(IAppManager::class)->loadApp('notifications');
	}

	/**
	 * Zeilen der App nextnotes zu Aufgaben/Abgaben
	 * @return list<array{user: string, object_type: string, object_id: string, subject: string, params: array<string, mixed>}>
	 */
	protected static function notes(?string $uid = null, ?string $subject = null, ?string $type = null, ?int $objectId = null): array {
		$qb = self::db()->getQueryBuilder();
		$qb->select('user', 'object_type', 'object_id', 'subject', 'subject_parameters')->from('notifications')
			->where($qb->expr()->eq('app', $qb->createNamedParameter('nextnotes')))
			->andWhere($qb->expr()->in('object_type', $qb->createNamedParameter(['assignment', 'submission'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)));
		foreach (['user' => $uid, 'subject' => $subject, 'object_type' => $type, 'object_id' => $objectId === null ? null : (string)$objectId] as $col => $v) {
			if ($v !== null) {
				$qb->andWhere($qb->expr()->eq($col, $qb->createNamedParameter($v)));
			}
		}
		$out = [];
		foreach ($qb->executeQuery()->fetchAll() as $r) {
			$out[] = ['user' => (string)$r['user'], 'object_type' => (string)$r['object_type'], 'object_id' => (string)$r['object_id'],
				'subject' => (string)$r['subject'], 'params' => (array)json_decode((string)$r['subject_parameters'], true)];
		}
		return $out;
	}

	/** @param array<string, mixed> $params */
	protected static function notice(string $uid, string $type, int $id, string $subject, array $params = []): INotification {
		$n = Server::get(INotificationManager::class)->createNotification();
		$n->setApp('nextnotes')->setUser($uid)->setDateTime(new \DateTime())->setObject($type, (string)$id)->setSubject($subject, $params);
		return $n;
	}

	/** @param array<string, mixed> $params */
	protected static function prepared(string $uid, string $type, int $id, string $subject, array $params = [], string $lang = 'en'): INotification {
		Server::get(AccessService::class)->resetCache();
		Server::get(TeamService::class)->resetCache();
		return Server::get(Notifier::class)->prepare(self::notice($uid, $type, $id, $subject, $params), $lang);
	}

	/** @param array<string, mixed> $params */
	protected static function assertGone(string $uid, string $type, int $id, string $subject, string $what, array $params = []): void {
		try {
			self::prepared($uid, $type, $id, $subject, $params);
			self::fail("$what: prepare liefert Anzeige statt AlreadyProcessedException");
		} catch (AlreadyProcessedException) {
			self::assertTrue(true);
		}
	}

	protected static function runDeadlineJob(int $budget = 120): void {
		Server::get(AccessService::class)->resetCache();
		Server::get(TeamService::class)->resetCache();
		Server::get(AssignmentDeadlineService::class)->run($budget);
	}
}
