<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\BackgroundJob;

use OCA\NextNotes\Service\ReminderService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

// Fällige Erinnerungen als NC-Benachrichtigung senden (SPEC §5)
class ReminderJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private ReminderService $reminders,
	) {
		parent::__construct($time);
		$this->setInterval(300);
	}

	protected function run($argument): void {
		$this->reminders->sendDue();
	}
}
