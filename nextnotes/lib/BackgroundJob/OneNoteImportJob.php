<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\BackgroundJob;

use OCA\NextNotes\OneNote\OneNoteImportService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

// OneNote-Import (Plan M9 §2 Job): fällige Importe per Lease, Laufziel 120 s; Aufräumen auch bei abgeschalteter Funktion
class OneNoteImportJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private OneNoteImportService $service,
	) {
		parent::__construct($time);
		$this->setInterval(300);
	}

	protected function run($argument): void {
		$this->service->runDue();
	}
}
