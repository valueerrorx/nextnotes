<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\BackgroundJob;

use OCA\NextNotes\Service\AssignmentDeadlineService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

// Fristen der Aufgaben: missed ohne Zugriff, Sammelmeldung an Lehrkräfte, Nachzügler (Plan M6 §2.4)
class AssignmentDeadlineJob extends TimedJob {
	private const BUDGET_SECONDS = 120;

	public function __construct(
		ITimeFactory $time,
		private AssignmentDeadlineService $deadlines,
	) {
		parent::__construct($time);
		$this->setInterval(300);
	}

	protected function run($argument): void {
		$this->deadlines->run(self::BUDGET_SECONDS);
	}
}
