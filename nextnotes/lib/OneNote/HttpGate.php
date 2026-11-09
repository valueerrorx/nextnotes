<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\OneNote;

use OCP\Http\Client\IClientService;

/**
 * Einziger Netzweg des OneNote-Imports (Plan M9 §6): nur HTTPS zu festen Microsoft-Hosts, keine Redirects, Timeout 30 s,
 * Body gestreamt mit Grenze. Setzt nie die Option nextcloud/allow_local_address: der Local-Address-Schutz von IClient bleibt an.
 */
class HttpGate {
	public const LOGIN_HOST = 'login.microsoftonline.com';
	public const GRAPH_HOST = 'graph.microsoft.com';
	public const TIMEOUT = 30;
	public const JSON_LIMIT = 4 * 1024 * 1024;

	public function __construct(
		private IClientService $clients,
	) {
	}

	// fester Host, https, ohne Benutzer, Port, Backslash und Pfad-Ausbruch
	public static function allowed(string $url, string $host): bool {
		$p = parse_url($url);
		if (!is_array($p) || ($p['scheme'] ?? '') !== 'https' || strtolower($p['host'] ?? '') !== $host) {
			return false;
		}
		if (isset($p['user']) || isset($p['pass']) || isset($p['port'])) {
			return false;
		}
		return !str_contains($url, '\\') && !str_contains($url, '@') && !str_contains($p['path'] ?? '', '..');
	}

	/**
	 * @param array<string, string> $headers
	 * @param array<string, string>|null $form application/x-www-form-urlencoded
	 * @throws GateException
	 */
	public function send(string $method, string $url, string $host, #[\SensitiveParameter] array $headers = [], #[\SensitiveParameter] ?array $form = null, int $limit = self::JSON_LIMIT): HttpResult {
		if (!self::allowed($url, $host)) {
			throw new GateException('host');
		}
		// Authorization nur an Graph (Plan M9 §6)
		if ($host !== self::GRAPH_HOST) {
			$headers = array_filter($headers, static fn (string $k): bool => strtolower($k) !== 'authorization', ARRAY_FILTER_USE_KEY);
		}
		$options = [
			'allow_redirects' => false,
			'timeout' => self::TIMEOUT,
			'http_errors' => false,
			'stream' => true,
			'headers' => $headers + ['Accept' => 'application/json'],
		];
		if ($form !== null) {
			$options['form_params'] = $form;
		}
		try {
			$response = $this->clients->newClient()->request($method, $url, $options);
		} catch (\Throwable) {
			throw new GateException('network');
		}
		$body = self::read($response->getBody(), $limit);
		$headers = [];
		foreach (['retry-after', 'request-id', 'content-type', 'location'] as $h) {
			$headers[$h] = $response->getHeader($h);
		}
		return new HttpResult($response->getStatusCode(), $headers, $body);
	}

	/** @param resource|string|null $body */
	private static function read(mixed $body, int $limit): string {
		if ($body === null) {
			return '';
		}
		if (is_string($body)) {
			if (strlen($body) > $limit) {
				throw new GateException('too_large');
			}
			return $body;
		}
		$out = '';
		try {
			while (!feof($body)) {
				$chunk = fread($body, 65536);
				if ($chunk === false) {
					throw new GateException('network');
				}
				$out .= $chunk;
				if (strlen($out) > $limit) {
					throw new GateException('too_large');
				}
			}
		} finally {
			fclose($body);
		}
		return $out;
	}
}
