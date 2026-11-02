<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service;

use OCA\NextNotes\AppInfo\Application;
use OCP\Config\IUserConfig;

// Benutzereinstellungen in IUserConfig (Whitelist/Routen folgen in M2c); Werte immer als String
class SettingsService {
	public function __construct(
		private IUserConfig $config,
	) {
	}

	public function get(string $uid, string $key, string $default = ''): string {
		return $this->config->getValueString($uid, Application::APP_ID, $key, $default);
	}

	public function set(string $uid, string $key, string $value): void {
		$this->config->setValueString($uid, Application::APP_ID, $key, $value);
	}

	// Upstream poznoteNormalizeNoteSort
	public function noteListSort(string $uid): string {
		$value = $this->get($uid, 'note_list_sort', 'updated_desc');
		return in_array($value, ['heading_asc', 'updated_desc', 'created_desc', 'type_asc', 'manual'], true) ? $value : 'updated_desc';
	}
}
