<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\BackgroundJob;

use OCA\NextNotes\Service\UserDataService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;

// Täglicher Nachlauf der Kontolöschung: löscht owner_type=deleted-Workspaces, die ein abgebrochener UserDataDeletionJob übrig ließ
class DeletedWorkspaceSweepJob extends TimedJob {
	private const BUDGET_SECONDS = 300;

	public function __construct(
		ITimeFactory $time,
		private UserDataService $userData,
	) {
		parent::__construct($time);
		$this->setInterval(86400);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
	}

	protected function run($argument): void {
		$this->userData->sweep(self::BUDGET_SECONDS);
	}
}
