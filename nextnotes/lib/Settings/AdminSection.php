<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Settings;

use OCA\NextNotes\AppInfo\Application;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

// Abschnitt „Next Notes“ in der NC-Verwaltung
class AdminSection implements IIconSection {
	public function __construct(
		private IL10N $l,
		private IURLGenerator $urls,
	) {
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return $this->l->t('Next Notes');
	}

	public function getPriority(): int {
		return 75;
	}

	public function getIcon(): string {
		return $this->urls->imagePath(Application::APP_ID, 'app-dark.svg');
	}
}
