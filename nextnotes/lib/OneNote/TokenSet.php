<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\OneNote;

// Antwort des Token-Endpunkts; nur im Speicher, gespeichert wird verschlüsselt (TokenVault)
final class TokenSet {
	public function __construct(
		#[\SensitiveParameter] public readonly string $access,
		#[\SensitiveParameter] public readonly string $refresh,
		public readonly int $expiresIn,
		#[\SensitiveParameter] public readonly ?string $idToken,
	) {
	}

	public function __debugInfo(): array {
		return ['expiresIn' => $this->expiresIn];
	}
}
