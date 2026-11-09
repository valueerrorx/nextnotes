<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\OneNote;

use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IPromise;
use OCP\Http\Client\IResponse;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

// Gefälschter IClientService (M9a Done 8): zeichnet jede Anfrage mit allen Optionen auf, Antworten aus $responder
final class FakeHttp implements IClientService {
	/** @var list<array{method: string, url: string, options: array<string, mixed>}> */
	public array $calls = [];
	/** @var \Closure(string, string, array<string, mixed>): (IResponse|\Throwable) */
	public \Closure $responder;

	public function __construct(?\Closure $responder = null) {
		$this->responder = $responder ?? static fn () => new FakeResponse(500, '');
	}

	public function newClient(?callable $handler = null): IClient {
		return new FakeClient($this);
	}

	/** @param array<string, mixed> $options */
	public function handle(string $method, string $url, array $options): IResponse {
		$this->calls[] = ['method' => $method, 'url' => $url, 'options' => $options];
		$r = ($this->responder)($method, $url, $options);
		if ($r instanceof \Throwable) {
			throw $r;
		}
		return $r;
	}

	/** @return list<string> */
	public function urls(): array {
		return array_map(static fn (array $c): string => $c['url'], $this->calls);
	}

	/** @param array<string, mixed> $data */
	public static function json(int $status, array $data, array $headers = []): FakeResponse {
		return new FakeResponse($status, (string)json_encode($data), $headers);
	}
}
