<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service;

use OCP\IDBConnection;

// Verschachtelbare Transaktion; FileCleanupQueue läuft nur nach dem äußersten Commit
class Transaction {
	private int $depth = 0;
	/** @var list<array{0: int, 1: callable():void}> Ebene, Aufräumen bei Rollback */
	private array $onRollback = [];

	public function __construct(
		private IDBConnection $db,
		private FileCleanupQueue $files,
	) {
	}

	/**
	 * @template T
	 * @param callable():T $fn
	 * @return T
	 */
	public function run(callable $fn): mixed {
		$this->db->beginTransaction();
		$level = ++$this->depth;
		$queued = $this->files->count();
		try {
			$result = $fn();
			$this->db->commit();
		} catch (\Throwable $e) {
			// auch bei Fehler im Commit genau eine Ebene zurück
			$this->depth = $level - 1;
			// nach Verbindungsabbruch keine Transaktion mehr: ursprüngliche Ausnahme nicht verdecken
			try {
				if ($this->db->inTransaction()) {
					$this->db->rollBack();
				}
			} catch (\Throwable) {
			}
			// Löschaufträge dieser Ebene verwerfen: ihre Zeilen sind wiederhergestellt
			$this->files->truncate($queued);
			$this->rollbackJobs($level);
			throw $e;
		}
		$this->depth = $level - 1;
		if ($this->depth === 0) {
			$this->onRollback = [];
			$this->files->flush();
		} else {
			// Zeilen gehören jetzt zur äußeren Ebene: nur deren Rollback räumt auf (Savepoint, kein Geschwister)
			foreach ($this->onRollback as $i => [$at, $job]) {
				if ($at >= $level) {
					$this->onRollback[$i] = [$this->depth, $job];
				}
			}
		}
		return $result;
	}

	/**
	 * Aufräumen, falls die aktuelle oder eine äußere Transaktion zurückrollt (z. B. neu geschriebene IAppData-Dateien)
	 * @param callable():void $job
	 */
	public function onRollback(callable $job): void {
		if ($this->depth > 0) {
			$this->onRollback[] = [$this->depth, $job];
		}
	}

	// Jobs dieser und tieferer Ebenen; Fehler verdecken die ursprüngliche Ausnahme nicht
	private function rollbackJobs(int $level): void {
		$keep = [];
		foreach ($this->onRollback as [$at, $job]) {
			if ($at < $level) {
				$keep[] = [$at, $job];
				continue;
			}
			try {
				$job();
			} catch (\Throwable) {
			}
		}
		$this->onRollback = $keep;
	}
}
