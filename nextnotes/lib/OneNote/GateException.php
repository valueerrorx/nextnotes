<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\OneNote;

// HttpGate: host (nicht erlaubt, kein Abruf), network, too_large
class GateException extends \RuntimeException {
	public function __construct(
		public readonly string $reason,
	) {
		parent::__construct($reason);
	}
}
