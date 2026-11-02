<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\BackgroundJob;

use OCA\NextNotes\Service\SnapshotService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;

// Täglich: Snapshots über Höchstalter, verwaiste und ausgedünnte automatische Snapshots löschen
class SnapshotRetentionJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private SnapshotService $snapshots,
	) {
		parent::__construct($time);
		$this->setInterval(86400);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
	}

	protected function run($argument): void {
		$this->snapshots->retention();
	}
}
