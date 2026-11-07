<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\BackgroundJob;

use OCA\NextNotes\Service\TeamCleanupService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;

// Täglich (SPEC §5): Team-Existenz, Hinweis vor der Löschung, Löschung verwaister Teams nach der Frist, verwaiste Dateien
class CleanupJob extends TimedJob {
	private const BUDGET_SECONDS = 300;

	public function __construct(
		ITimeFactory $time,
		private TeamCleanupService $cleanup,
	) {
		parent::__construct($time);
		$this->setInterval(86400);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
	}

	protected function run($argument): void {
		$this->cleanup->run(self::BUDGET_SECONDS);
	}
}
