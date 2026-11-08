<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service;

use OCA\NextNotes\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

// Benachrichtigungen zu Aufgaben (SPEC §6, Plan M6 §2.4): nach dem Commit senden, Fehler nur protokollieren; Lehrkräfte nur Sammelmeldung (Q4)
class AssignmentNotifications {
	public const ASSIGNED = 'assignment_assigned';
	public const DEADLINE_PASSED = 'assignment_deadline_passed';
	public const RETURNED = 'submission_returned';
	public const REOPENED = 'submission_reopened';
	public const TRANSFERRED = 'assignment_transferred';

	public function __construct(
		private INotificationManager $notifications,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	public function assigned(int $assignmentId, string $uid): void {
		$this->send($uid, 'assignment', $assignmentId, self::ASSIGNED, []);
	}

	/** @param array{submitted: int, missed: int, not_distributed: int} $counts */
	public function deadlinePassed(int $assignmentId, string $uid, array $counts): void {
		$this->send($uid, 'assignment', $assignmentId, self::DEADLINE_PASSED, $counts);
	}

	// Q5: Name der Lehrkraft als Snapshot (bleibt nach Kontolöschung)
	public function returned(int $submissionId, string $uid, string $byName): void {
		$this->send($uid, 'submission', $submissionId, self::RETURNED, ['byName' => $byName]);
	}

	public function reopened(int $submissionId, string $uid, ?int $due): void {
		$this->send($uid, 'submission', $submissionId, self::REOPENED, ['due' => $due]);
	}

	// neue verantwortliche Lehrkraft (§9.7); Grund: deleted, left, demoted, admin
	public function transferred(int $assignmentId, string $uid, string $reason): void {
		$this->send($uid, 'assignment', $assignmentId, self::TRANSFERRED, ['reason' => $reason]);
	}

	// Alte Sammelmeldung entfernen (Fristverlängerung: neue Meldung zur neuen Frist)
	public function clearDeadline(int $assignmentId): void {
		try {
			$n = $this->notifications->createNotification();
			$n->setApp(Application::APP_ID)->setObject('assignment', (string)$assignmentId)->setSubject(self::DEADLINE_PASSED);
			$this->notifications->markProcessed($n);
		} catch (\Throwable $e) {
			$this->logger->warning('nextnotes: deadline notification not cleared', ['exception' => $e, 'app' => Application::APP_ID]);
		}
	}

	/** @param array<string, int|string|null> $params */
	private function send(string $uid, string $type, int $id, string $subject, array $params): void {
		try {
			$n = $this->notifications->createNotification();
			$n->setApp(Application::APP_ID)
				->setUser($uid)
				->setDateTime((new \DateTime())->setTimestamp($this->time->getTime()))
				->setObject($type, (string)$id)
				->setSubject($subject, $params);
			$this->notifications->notify($n);
		} catch (\Throwable $e) {
			$this->logger->error('nextnotes: notification ' . $subject . ' not sent', ['exception' => $e, 'app' => Application::APP_ID]);
		}
	}
}
