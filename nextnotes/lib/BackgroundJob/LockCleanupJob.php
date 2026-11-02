<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\BackgroundJob;

use OCA\NextNotes\Service\LockService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;

// Löscht abgelaufene Sperren (nur Aufräumen; gültig ist eine Sperre ohnehin nur bis expires_at, ADR 0005)
class LockCleanupJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private LockService $locks,
	) {
		parent::__construct($time);
		$this->setInterval(3600);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
	}

	protected function run($argument): void {
		$this->locks->deleteExpired();
	}
}
