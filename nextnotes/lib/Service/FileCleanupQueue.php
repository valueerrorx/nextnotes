<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service;

use Psr\Log\LoggerInterface;

// Request-weite Liste von Löschaufträgen für IAppData; läuft erst nach dem äußersten Commit
class FileCleanupQueue {
	/** @var list<callable():void> */
	private array $jobs = [];

	public function __construct(
		private LoggerInterface $logger,
	) {
	}

	/** @param callable():void $job */
	public function add(callable $job): void {
		$this->jobs[] = $job;
	}

	public function discard(): void {
		$this->jobs = [];
	}

	public function flush(): void {
		$jobs = $this->jobs;
		$this->jobs = [];
		foreach ($jobs as $job) {
			try {
				$job();
			} catch (\Throwable $e) {
				// Datei bleibt verwaist, CleanupJob (M5) räumt sie auf
				$this->logger->warning('nextnotes: file cleanup failed', ['exception' => $e]);
			}
		}
	}

	public function count(): int {
		return count($this->jobs);
	}
}
