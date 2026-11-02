<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Support;

final class Response {
	public function __construct(
		public readonly int $status,
		public readonly string $body,
		public readonly array $headers,
	) {
	}

	public function json(): mixed {
		return json_decode($this->body, true);
	}

	/** JSON als stdClass-Baum (für den Schlüsselvergleich; {} und [] bleiben unterscheidbar) */
	public function object(): mixed {
		return json_decode($this->body);
	}

	public function ok(): bool {
		return $this->status >= 200 && $this->status < 300;
	}

	public function header(string $name): ?string {
		return $this->headers[strtolower($name)] ?? null;
	}

	public function describe(): string {
		return $this->status . ' ' . mb_substr($this->body, 0, 400);
	}
}
