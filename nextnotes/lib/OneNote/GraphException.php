<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\OneNote;

/**
 * Graph-Fehler mit festem Grund (Plan M9 §4.4–4.6): unauthorized (401), forbidden (403), not_found (404), throttled (429),
 * server (5xx), network, redirect (3xx, nie gefolgt), host (URL abgelehnt, kein Abruf), budget (Fenster voll), lease (Lauf beendet), too_large, http
 */
class GraphException extends \RuntimeException {
	public function __construct(
		public readonly string $reason,
		public readonly int $httpStatus = 0,
		public readonly int $retryAt = 0,
		public readonly string $graphCode = '',
	) {
		parent::__construct($reason);
	}

	// zählt wie 429 zum Backoff (§4.5)
	public function isTransient(): bool {
		return in_array($this->reason, ['throttled', 'server', 'network'], true);
	}
}
