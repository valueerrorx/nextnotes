<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\OneNote;

// Fehler bei Anmeldung oder Token-Abruf; Nachricht = fester Code (invalid_grant, invalid_client, id_token, http, network …), nie error_description
class OAuthException extends \RuntimeException {
	public function __construct(
		public readonly string $error,
		public readonly int $httpStatus = 0,
	) {
		parent::__construct($error);
	}
}
