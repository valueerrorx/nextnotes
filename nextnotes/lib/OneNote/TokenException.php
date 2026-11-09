<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\OneNote;

// Token nicht verwendbar: reauth (Neuanmeldung nötig, Zeile reauth bzw. in select gelöscht), failed (invalid_client),
// stale (Zeile inzwischen geändert, neue Token verworfen), transient (Netz/5xx, später erneut)
class TokenException extends \RuntimeException {
	public function __construct(
		public readonly string $reason,
	) {
		parent::__construct($reason);
	}
}
