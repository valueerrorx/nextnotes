<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\OneNote;

use OCP\Http\Client\IResponse;

// Antwort für FakeHttp; Body standardmäßig als Stream wie IClient mit stream=true; FakeHttp schreibt ihn in die Option sink
final class FakeResponse implements IResponse {
	/**
	 * @param array<string, string> $headers
	 * @param (\Closure(): iterable<string>)|null $chunks Body als (auch endlose) Folge dekodierter Stücke statt $body
	 */
	public function __construct(
		private int $status,
		private string $body,
		private array $headers = [],
		private bool $asStream = true,
		private ?\Closure $chunks = null,
	) {
	}

	/** @return iterable<string> */
	public function chunks(): iterable {
		if ($this->chunks !== null) {
			yield from ($this->chunks)();
			return;
		}
		for ($i = 0; $i < strlen($this->body); $i += 16384) {
			yield substr($this->body, $i, 16384);
		}
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
