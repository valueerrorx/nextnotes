<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\BackgroundJob;

use OCA\NextNotes\Service\ExportArchive;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use OCP\ITempManager;

/**
 * Entfernt Export-Ordner oc_tmp_*.nextnotes-export, die ein harter Abbruch (Timeout, Fatal, Kill) liegen ließ (Plan M4 §1.3).
 * Der Kern ruft ITempManager::cleanOld() nicht auf. Schwelle max(1 h, max_execution_time + 10 min) seit der letzten
 * Änderung im Ordner (ein laufender Export schreibt fortlaufend); im Cron-CLI ist max_execution_time 0, dann gilt 1 h.
 */
class ExportTempCleanupJob extends TimedJob {
	private const MIN_AGE = 3600;
	private const RESERVE = 600;

	public function __construct(
		ITimeFactory $time,
		private ITempManager $temp,
	) {
		parent::__construct($time);
		$this->setInterval(3600);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
	}

	protected function run($argument): void {
		$this->sweep($this->time->getTime());
	}

	public static function threshold(): int {
		return max(self::MIN_AGE, (int)ini_get('max_execution_time') + self::RESERVE);
	}

	/** @return int Anzahl gelöschter Ordner */
	public function sweep(int $now): int {
		$base = rtrim((string)$this->temp->getTempBaseDir(), '/');
		$dirs = glob($base . '/oc_tmp_*.' . ExportArchive::TEMP_POSTFIX, GLOB_ONLYDIR | GLOB_NOSORT);
		$limit = self::threshold();
		$removed = 0;
		foreach ($dirs === false ? [] : $dirs as $dir) {
			if (is_link($dir) || $now - self::lastChange($dir) <= $limit) {
				continue;
			}
			ExportArchive::removeDir($dir);
			$removed++;
		}
		return $removed;
	}

	// jüngste mtime des Ordners und seiner Dateien (flach)
	private static function lastChange(string $dir): int {
		$newest = (int)@filemtime($dir);
		foreach ((array)@scandir($dir) as $f) {
			if (is_string($f) && $f !== '.' && $f !== '..') {
				$newest = max($newest, (int)@filemtime($dir . '/' . $f));
			}
		}
		return $newest;
	}
}
