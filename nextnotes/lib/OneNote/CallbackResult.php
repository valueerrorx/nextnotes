<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\OneNote;

// Ergebnis des Callbacks: null-Query = 404 ohne Details, sonst 303 auf die Ansicht mit dieser Query
final class CallbackResult {
	/** @param array<string, int|string>|null $query */
	public function __construct(
		public readonly ?array $query,
	) {
	}

	public static function notFound(): self {
		return new self(null);
	}

	/** @param array<string, int|string> $query */
	public static function view(array $query): self {
		return new self($query);
	}
}
