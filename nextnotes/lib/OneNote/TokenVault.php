<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\OneNote;

use OCP\Security\ICrypto;

// Token und PKCE-Verifier verschlüsselt (ICrypto, Schlüssel aus config.php secret; Plan M9 §7)
class TokenVault {
	public function __construct(
		private ICrypto $crypto,
	) {
	}

	public function seal(#[\SensitiveParameter] string $plain): string {
		return $this->crypto->encrypt($plain);
	}

	// null bei Fehler (z. B. geändertes secret) → Aufrufer setzt reauth (§4.6)
	public function open(?string $sealed): ?string {
		if ($sealed === null || $sealed === '') {
			return null;
		}
		try {
			return $this->crypto->decrypt($sealed);
		} catch (\Throwable) {
			return null;
		}
	}
}
