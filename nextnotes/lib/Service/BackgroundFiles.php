<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service;

use OCA\NextNotes\AppInfo\Application;
use OCP\Config\IUserConfig;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException as FilesNotFoundException;
use OCP\Files\NotPermittedException;
use OCP\Files\SimpleFS\ISimpleFolder;

// Ablage der Hintergrundbilder (IAppData backgrounds/<workspaceId>/<uid>/) ohne Rechteprüfung; Löschen nach dem Commit (FileCleanupQueue)
class BackgroundFiles {
	// interne Deckkraft-Schlüssel je Workspace-ID (BackgroundService::OPACITY)
	public const OPACITY_PREFIXES = ['bg_opacity_', 'sd_bg_opacity_'];

	public function __construct(
		private IAppData $appData,
		private IUserConfig $config,
		private FileCleanupQueue $cleanup,
	) {
	}

	private function root(): ISimpleFolder {
		try {
			return $this->appData->getFolder('backgrounds');
		} catch (FilesNotFoundException) {
			try {
				return $this->appData->newFolder('backgrounds');
			} catch (NotPermittedException) {
				return $this->appData->getFolder('backgrounds');
			}
		}
	}

	public function folder(int $wsId, string $uid, bool $create): ?ISimpleFolder {
		try {
			$wsFolder = $this->root()->getFolder((string)$wsId);
		} catch (FilesNotFoundException) {
			if (!$create) {
				return null;
			}
			$wsFolder = $this->root()->newFolder((string)$wsId);
		}
		try {
			return $wsFolder->getFolder($uid);
		} catch (FilesNotFoundException) {
			return $create ? $wsFolder->newFolder($uid) : null;
		}
	}

	/** Workspace gelöscht: Bilder aller Benutzer und deren Deckkraft-Einstellungen */
	public function queueWorkspace(int $wsId): void {
		$this->cleanup->add(function () use ($wsId): void {
			try {
				$this->root()->getFolder((string)$wsId)->delete();
			} catch (FilesNotFoundException) {
				// nichts gespeichert
			}
			foreach (self::OPACITY_PREFIXES as $prefix) {
				$this->config->deleteKey(Application::APP_ID, $prefix . $wsId);
			}
		});
	}

	/**
	 * Freigabe entzogen bzw. Konto gelöscht: Bilder und Deckkraft von $uid in diesen Workspaces
	 * @param int[] $wsIds
	 * @param bool $withConfig false bei Kontolöschung (der Kern löscht alle Benutzerwerte)
	 */
	public function queueUser(array $wsIds, string $uid, bool $withConfig = true): void {
		$wsIds = array_values(array_unique(array_map('intval', $wsIds)));
		if ($wsIds === []) {
			return;
		}
		$this->cleanup->add(function () use ($wsIds, $uid, $withConfig): void {
			foreach ($wsIds as $wsId) {
				$this->folder($wsId, $uid, false)?->delete();
				foreach ($withConfig ? self::OPACITY_PREFIXES : [] as $prefix) {
					$this->config->deleteUserConfig($uid, Application::APP_ID, $prefix . $wsId);
				}
			}
		});
	}
}
