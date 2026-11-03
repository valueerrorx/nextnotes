<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\BackgroundJob;

use OCA\NextNotes\Service\UserDataService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

// Löscht nach einer Kontolöschung die vom Listener entkoppelten Workspaces (Argument: Kennung, nie die UID)
class UserDataDeletionJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private UserDataService $userData,
	) {
		parent::__construct($time);
	}

	protected function run($argument): void {
		$token = is_array($argument) ? (string)($argument['token'] ?? '') : '';
		if (str_starts_with($token, '#')) {
			$this->userData->purgeWorkspaces($token);
		}
	}
}
