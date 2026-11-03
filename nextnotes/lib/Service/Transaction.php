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
		$this->depth++;
		try {
			$result = $fn();
			$this->depth--;
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->depth--;
			// nach Verbindungsabbruch keine Transaktion mehr: ursprüngliche Ausnahme nicht verdecken
			try {
				if ($this->db->inTransaction()) {
					$this->db->rollBack();
				}
			} catch (\Throwable) {
			}
			if ($this->depth === 0) {
				$this->files->discard();
			}
			throw $e;
		}
		if ($this->depth === 0) {
			$this->files->flush();
		}
		return $result;
	}
}
