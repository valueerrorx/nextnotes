<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\OneNote;

use OCP\Http\Client\IClientService;

/**
 * Einziger Netzweg des OneNote-Imports (Plan M9 §6): nur HTTPS zu festen Microsoft-Hosts, keine Redirects, Timeout 30 s.
 * Body immer über LimitedSink (dekodierte Bytes, Abbruch an der Grenze), in den Speicher bzw. php://temp oder in eine Datei.
 * Setzt nie die Option nextcloud/allow_local_address: der Local-Address-Schutz von IClient bleibt an.
 */
class HttpGate {
	public const LOGIN_HOST = 'login.microsoftonline.com';
	public const GRAPH_HOST = 'graph.microsoft.com';
	public const TIMEOUT = 30;
	public const JSON_LIMIT = 4 * 1024 * 1024;
	// Fehler-Body einer Datei-Antwort (Graph-Fehlercode)
	private const ERROR_BODY = 65536;

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
	 * @param string|null $file Ziel-Datei (Temp-Datei des Aufrufers); dann ist body nur bei Fehlerstatus gefüllt
	 * @param int $timeout Gesamtzeit der Anfrage in Sekunden (Verbindungsaufbau höchstens TIMEOUT)
	 * @throws GateException
	 */
	public function send(string $method, string $url, string $host, #[\SensitiveParameter] array $headers = [], #[\SensitiveParameter] ?array $form = null,
		int $limit = self::JSON_LIMIT, ?string $file = null, int $timeout = self::TIMEOUT): HttpResult {
		if (!self::allowed($url, $host)) {
			throw new GateException('host');
		}
		// Authorization nur an Graph (Plan M9 §6)
		if ($host !== self::GRAPH_HOST) {
			$headers = array_filter($headers, static fn (string $k): bool => strtolower($k) !== 'authorization', ARRAY_FILTER_USE_KEY);
		}
		$handle = fopen($file ?? 'php://temp', 'w+b');
		if ($handle === false) {
			throw new GateException('network');
		}
		$sink = new LimitedSink($handle, $limit);
		$options = [
			'allow_redirects' => false,
			'timeout' => max(1, $timeout),
			'connect_timeout' => min(self::TIMEOUT, max(1, $timeout)),
			'http_errors' => false,
			// CurlHandler (NC 35) schreibt nur über sink gestreamt; stream=true wirkt dort nicht
			'sink' => $sink,
			'headers' => $headers + ['Accept' => 'application/json'],
		];
		if ($form !== null) {
			$options['form_params'] = $form;
		}
		try {
			try {
				$response = $this->clients->newClient()->request($method, $url, $options);
			} catch (\Throwable) {
				throw new GateException($sink->exceeded() ? 'too_large' : 'network');
			}
			if ($sink->exceeded()) {
				throw new GateException('too_large');
			}
			$status = $response->getStatusCode();
			$body = $file === null ? $sink->contents($limit) : ($status >= 200 && $status < 300 ? '' : $sink->contents(self::ERROR_BODY));
			$out = [];
			foreach (['retry-after', 'request-id', 'content-type', 'location'] as $h) {
				$out[$h] = $response->getHeader($h);
			}
			return new HttpResult($status, $out, $body, $sink->bytes());
		} finally {
			$sink->close();
		}
	}
}
