<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service\Exception;

use Exception;

// Basis aller App-Fehler; Middleware bildet sie auf Upstream-JSON ab
abstract class ApiException extends Exception {
	public const STATUS = 500;

	/** @param array<string, mixed> $extra zusätzliche Upstream-Felder (z. B. code, folder) */
	public function __construct(string $message, private array $extra = []) {
		parent::__construct($message);
	}

	public function getStatus(): int {
		return static::STATUS;
	}

	/** @return array<string, mixed> */
	public function getExtra(): array {
		return $this->extra;
	}
}
