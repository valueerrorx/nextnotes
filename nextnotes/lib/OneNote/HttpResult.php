<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\OneNote;

// Antwort von HttpGate: Status, ausgewählte Kopfzeilen, Body (gekürzt auf die Grenze)
final class HttpResult {
	/** @param array<string, string> $headers Kleinbuchstaben-Namen */
	public function __construct(
		public readonly int $status,
		public readonly array $headers,
		public readonly string $body,
	) {
	}

	public function header(string $name): string {
		return $this->headers[strtolower($name)] ?? '';
	}

	/** @return array<string, mixed>|null */
	public function json(): ?array {
		$data = json_decode($this->body, true);
		return is_array($data) ? $data : null;
	}
}
