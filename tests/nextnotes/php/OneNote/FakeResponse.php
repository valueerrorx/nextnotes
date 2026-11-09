<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\OneNote;

use OCP\Http\Client\IResponse;

// Antwort für FakeHttp; Body standardmäßig als Stream wie IClient mit stream=true
final class FakeResponse implements IResponse {
	/** @param array<string, string> $headers */
	public function __construct(
		private int $status,
		private string $body,
		private array $headers = [],
		private bool $asStream = true,
	) {
	}

	public function getBody() {
		if (!$this->asStream) {
			return $this->body;
		}
		$h = fopen('php://temp', 'r+b');
		fwrite($h, $this->body);
		rewind($h);
		return $h;
	}

	public function getStatusCode(): int {
		return $this->status;
	}

	public function getHeader(string $key): string {
		foreach ($this->headers as $k => $v) {
			if (strtolower($k) === strtolower($key)) {
				return $v;
			}
		}
		return '';
	}

	public function getHeaders(): array {
		return array_map(static fn (string $v): array => [$v], $this->headers);
	}
}
