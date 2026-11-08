<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service;

use OCA\NextNotes\AppInfo\Application;
use OCA\NextNotes\Db\Reminder;
use OCA\NextNotes\Db\ReminderMapper;
use OCA\NextNotes\Service\Exception\BadRequestException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDateTimeZone;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

// Erinnerungen je Empfänger (Upstream RemindersController); Anzeige über NC-Benachrichtigungen (SPEC §6)
class ReminderService {
	private const BATCH = 200;
	private const BUDGET = 60;
	private const TASK_ID_MAX = 64;
	private const RECURRENCE_ERROR = 'Invalid recurrence format (expected e.g. "30i", "1h", "1d", "2w", "3m", "1y")';

	public function __construct(
		private ReminderMapper $reminders,
		private AccessService $access,
		private TaskService $tasks,
		private INotificationManager $notifications,
		private ITimeFactory $time,
		private IDateTimeZone $timeZone,
		private LoggerInterface $logger,
	) {
	}

	/** @return array<string, mixed> GET /notes/{id}/reminder */
	public function get(string $uid, int $noteId): array {
		$this->access->requireNote($uid, $noteId, 'read');
		$r = $this->reminders->findPending($uid, $noteId, null);
		return [
			'note_id' => $noteId,
			'reminder_at' => $r === null ? null : NoteSerializer::date($r->getTriggerAt()),
			'email_enabled' => false,
			'recurrence' => $r?->getRecurrence(),
		];
	}

	/** @return array<string, mixed> GET /notes/{id}/task-reminder?task_id=: nur die eigene offene Erinnerung des Aufrufers (Leserecht, sonst 404) */
	public function getTask(string $uid, int $noteId, mixed $taskId): array {
		$this->access->requireNote($uid, $noteId, 'read');
		$taskId = is_scalar($taskId) ? trim((string)$taskId) : '';
		if ($taskId === '') {
			throw new BadRequestException('task_id is required');
		}
		$r = strlen($taskId) > self::TASK_ID_MAX ? null : $this->reminders->findPending($uid, $noteId, $taskId);
		return [
			'note_id' => $noteId,
			'task_id' => $taskId,
			'reminder_at' => $r === null ? null : NoteSerializer::date($r->getTriggerAt()),
			'email_enabled' => false,
			'recurrence' => $r?->getRecurrence(),
		];
	}

	/** @return array<string, mixed> POST /notes/{id}/reminder bzw. /task-reminder */
	public function set(string $uid, int $noteId, array $in, bool $task): array {
		$taskId = null;
		if ($task) {
			$taskId = isset($in['task_id']) && is_scalar($in['task_id']) ? trim((string)$in['task_id']) : '';
		}
		$recurrence = Recurrence::normalize($in['recurrence'] ?? null);
		if ($recurrence === false) {
			throw new BadRequestException(self::RECURRENCE_ERROR);
		}
		if ($taskId === '') {
			throw new BadRequestException('task_id is required');
		}
		if ($taskId !== null && strlen($taskId) > self::TASK_ID_MAX) {
			throw new BadRequestException('task_id too long');
		}
		$raw = $in['reminder_at'] ?? null;
		if (!is_string($raw) || trim($raw) === '') {
			throw new BadRequestException('reminder_at is required');
		}
		$trigger = $this->parse($uid, trim($raw));
		$note = $this->access->requireNote($uid, $noteId, 'read');
		if ($taskId !== null) {
			$this->tasks->requireTask($note, $taskId);
		}
		if ($this->reminders->atLimit($uid, $noteId, $taskId)) {
			throw new BadRequestException('too many reminders');
		}
		$message = isset($in['message']) && is_scalar($in['message']) ? trim((string)$in['message']) : '';
		$this->reminders->replace($uid, $noteId, $taskId, $trigger, $message !== '' ? $message : (string)$note->getHeading(), $recurrence, $this->time->getTime());
		$out = ['note_id' => $noteId];
		if ($taskId !== null) {
			$out['task_id'] = $taskId;
		}
		return $out + ['reminder_at' => NoteSerializer::date($trigger), 'email_enabled' => false, 'recurrence' => $recurrence];
	}

	/** @return array<string, mixed> DELETE /notes/{id}/reminder bzw. /task-reminder */
	public function remove(string $uid, int $noteId, ?string $taskId): array {
		$this->access->requireNote($uid, $noteId, 'read');
		if ($taskId !== null && trim($taskId) === '') {
			throw new BadRequestException('task_id is required');
		}
		$this->reminders->deleteFor($uid, $noteId, $taskId === null ? null : trim($taskId));
		return $taskId === null ? ['note_id' => $noteId] : ['note_id' => $noteId, 'task_id' => trim($taskId)];
	}

	// ISO-Zeit; ohne Zonenangabe in der Zeitzone des Benutzers
	private function parse(string $uid, string $raw): int {
		if (!InputValidator::validDateTime($raw)) {
			throw new BadRequestException('Invalid datetime format for reminder_at');
		}
		try {
			$dt = new \DateTimeImmutable($raw, $this->timeZone->getTimeZone(false, $uid));
		} catch (\Exception) {
			throw new BadRequestException('Invalid datetime format for reminder_at');
		}
		return $dt->getTimestamp();
	}

	// ---------- ReminderJob ----------

	// Fällige senden (älteste zuerst, Stapel bis leer oder Zeitbudget); ohne Leserecht löschen; claim() verhindert Doppelversand
	public function sendDue(): int {
		$start = $this->time->getTime();
		$sent = 0;
		$this->access->resetCache();
		do {
			$now = $this->time->getTime();
			$batch = $this->reminders->findDue($now, self::BATCH);
			foreach ($batch as $r) {
				$sent += $this->sendOne($r, $now) ? 1 : 0;
			}
		} while (count($batch) === self::BATCH && $this->time->getTime() - $start < self::BUDGET);
		return $sent;
	}

	// Jede Zeile wird gelöscht oder beansprucht und fällt so aus findDue(): die Schleife kommt voran
	private function sendOne(Reminder $r, int $now): bool {
		$id = (int)$r->getId();
		$uid = (string)$r->getUserId();
		if (!$this->access->canRead($uid, (int)$r->getNoteId())) {
			$this->reminders->deleteById($id);
			return false;
		}
		if (!$this->reminders->claim($id, $now)) {
			return false;
		}
		$ok = true;
		try {
			$this->notify($r);
		} catch (\Throwable $e) {
			$ok = false;
			$this->logger->error('Reminder ' . $id . ' could not be sent', ['exception' => $e, 'app' => Application::APP_ID]);
		}
		$this->scheduleNext($r, $now);
		return $ok;
	}

	private function notify(Reminder $r): void {
		$taskId = $r->getTaskId();
		$n = $this->notifications->createNotification();
		$n->setApp(Application::APP_ID)
			->setUser((string)$r->getUserId())
			->setDateTime((new \DateTime())->setTimestamp((int)$r->getTriggerAt()))
			->setObject('note', (string)$r->getNoteId())
			->setSubject($taskId === null ? 'reminder' : 'task_reminder', ['message' => (string)$r->getMessage(), 'task_id' => $taskId]);
		$this->notifications->notify($n);
	}

	private function scheduleNext(Reminder $r, int $now): void {
		$rule = $r->getRecurrence();
		if ($rule === null || $rule === '') {
			return;
		}
		$uid = (string)$r->getUserId();
		$next = Recurrence::nextAfter((int)$r->getTriggerAt(), $rule, $now, $this->timeZone->getTimeZone(false, $uid));
		if ($next === null) {
			return;
		}
		$taskId = $r->getTaskId();
		// Aufgaben-Erinnerung endet mit gelöschter oder erledigter Aufgabe; sonst wandert die Fälligkeit mit
		if ($taskId !== null && !$this->tasks->advanceDue($uid, (int)$r->getNoteId(), $taskId, $next)) {
			return;
		}
		$this->reminders->replace($uid, (int)$r->getNoteId(), $taskId, $next, (string)$r->getMessage(), $rule, $now);
	}
}
