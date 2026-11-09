<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\OneNote;

use OCA\NextNotes\Db\OneNoteImport;
use OCA\NextNotes\Db\OneNoteImportMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * Graph-Abrufe (Plan M9 §2, §4.5, §6): nur https://graph.microsoft.com/v1.0/, Budget je uid auf der Import-Zeile
 * (optimistische Sperre rate_version), Abstand ≥ 0,6 s, Lease-Verlängerung als eigenes UPDATE nach jeder Anfrage.
 * Läuft nie in einer offenen DB-Transaktion (Aufrufer).
 */
class GraphClient {
	public const PREFIX = 'https://' . HttpGate::GRAPH_HOST . '/v1.0/';
	public const ID_PATTERN = '/^[A-Za-z0-9!._,-]{1,512}$/';
	public const LEASE_SECONDS = 300;
	private const CAS_TRIES = 5;

	private float $lastRequest = 0.0;

	public function __construct(
		private HttpGate $http,
		private OneNoteImportMapper $imports,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	// Muster aus §6; reine Punkt-IDs (Pfadsegment . oder ..) und leere !-Segmente nie (Review M9b-r1 Hinweis 3)
	public static function validId(string $id): bool {
		return preg_match(self::ID_PATTERN, $id) === 1 && trim($id, '.') !== '' && !in_array('', explode('!', $id), true);
	}

	// Wurzel aus der Auswahl: me | group:{id} → Pfad unter /v1.0/
	public static function rootPath(string $root): ?string {
		if ($root === 'me') {
			return 'me';
		}
		if (str_starts_with($root, 'group:') && self::validId(substr($root, 6))) {
			return 'groups/' . rawurlencode(substr($root, 6));
		}
		return null;
	}

	// @odata.nextLink nur mit Graph-Präfix (Plan M9 §6)
	public static function nextLinkAllowed(string $url): bool {
		return str_starts_with($url, self::PREFIX) && HttpGate::allowed($url, HttpGate::GRAPH_HOST);
	}

	// Ressourcen-URL aus dem Seiten-HTML: nur die ID zählt, fremde Hosts (auch www.onenote.com) → null
	public static function resourceIdFromUrl(string $url): ?string {
		if (!HttpGate::allowed($url, HttpGate::GRAPH_HOST)) {
			return null;
		}
		$path = (string)parse_url($url, PHP_URL_PATH);
		if (preg_match('#/onenote/resources/([^/]+)/\$value$#', $path, $m) !== 1) {
			return null;
		}
		$id = rawurldecode($m[1]);
		return self::validId($id) ? $id : null;
	}

	public static function resourceUrl(string $root, string $id): ?string {
		$base = self::rootPath($root);
		return $base !== null && self::validId($id) ? self::PREFIX . $base . '/onenote/resources/' . rawurlencode($id) . '/$value' : null;
	}

	/**
	 * GET mit Budget; $leaseToken gesetzt (Job): Lease wird danach verlängert, sonst GraphException lease
	 * @throws GraphException
	 */
	public function get(OneNoteImport $import, #[\SensitiveParameter] string $accessToken, string $url, ?string $leaseToken = null, int $limit = HttpGate::JSON_LIMIT,
		?string $file = null, int $timeout = HttpGate::TIMEOUT): HttpResult {
		if (!str_starts_with($url, self::PREFIX) || !HttpGate::allowed($url, HttpGate::GRAPH_HOST)) {
			throw new GraphException('host');
		}
		$this->reserve($import, $leaseToken);
		$this->space();
		try {
			$res = $this->http->send('GET', $url, HttpGate::GRAPH_HOST, ['Authorization' => 'Bearer ' . $accessToken], null, $limit, $file, $timeout);
		} catch (GateException $e) {
			$this->extendLease($import, $leaseToken);
			throw new GraphException($e->reason === 'too_large' ? 'too_large' : 'network');
		} finally {
			$this->lastRequest = $this->clock();
		}
		$this->extendLease($import, $leaseToken);
		if ($res->status >= 200 && $res->status < 300) {
			return $res;
		}
		$code = self::graphCode($res);
		$this->logger->info('nextnotes: graph request failed', ['import' => (int)$import->getId(), 'status' => $res->status, 'code' => $code, 'request-id' => $res->header('request-id')]);
		throw match (true) {
			$res->status >= 300 && $res->status < 400 => new GraphException('redirect', $res->status),
			$res->status === 401 => new GraphException('unauthorized', 401, 0, $code),
			$res->status === 403 => new GraphException('forbidden', 403, 0, $code),
			$res->status === 404 => new GraphException('not_found', 404, 0, $code),
			$res->status === 429 => new GraphException('throttled', 429, $this->time->getTime() + RateBudget::backoffSeconds((int)$import->getBackoffLevel(), $res->header('retry-after')), $code),
			$res->status >= 500 => new GraphException('server', $res->status, 0, $code),
			default => new GraphException('http', $res->status, 0, $code),
		};
	}

	// freie Anfragen im Stundenfenster der uid (Seitenstart §4.5) und Zeitpunkt, ab dem $need passen
	public function remaining(OneNoteImport $import): int {
		$now = $this->time->getTime();
		return max(0, RateBudget::MAX - RateBudget::used(RateBudget::decode($import->getRateBuckets(), $now)));
	}

	public function freeAt(OneNoteImport $import, int $need): int {
		$now = $this->time->getTime();
		return RateBudget::nextFreeAt(RateBudget::decode($import->getRateBuckets(), $now), $now, $need);
	}

	// Anfrage im Fenster buchen (bedingt über rate_version); voll → GraphException budget mit Freizeitpunkt
	// im Job ($leaseToken) nur mit eigener Lease: nach Abbruch keine weitere Anfrage
	private function reserve(OneNoteImport $import, ?string $leaseToken): void {
		$guard = $leaseToken !== null ? ['lease_token' => $leaseToken, 'status' => 'running'] : [];
		for ($i = 0; $i < self::CAS_TRIES; $i++) {
			$now = $this->time->getTime();
			$buckets = RateBudget::decode($import->getRateBuckets(), $now);
			$free = RateBudget::nextFreeAt($buckets, $now);
			if ($free > $now) {
				throw new GraphException('budget', 0, $free);
			}
			$version = (int)$import->getRateVersion();
			$encoded = RateBudget::encode(RateBudget::add($buckets, $now));
			$n = $this->imports->updateWhere((int)$import->getId(), (string)$import->getUid(),
				['rate_buckets' => $encoded, 'rate_version' => $version + 1], ['rate_version' => $version] + $guard);
			if ($n === 1) {
				$import->setRateBuckets($encoded);
				$import->setRateVersion($version + 1);
				return;
			}
			try {
				$fresh = $this->imports->findOwn((int)$import->getId(), (string)$import->getUid());
			} catch (\Throwable) {
				throw new GraphException('lease');
			}
			if ($leaseToken !== null && ($fresh->getLeaseToken() !== $leaseToken || $fresh->getStatus() !== 'running')) {
				throw new GraphException('lease');
			}
			$import->setRateBuckets($fresh->getRateBuckets());
			$import->setRateVersion($fresh->getRateVersion());
		}
		throw new GraphException('budget', 0, $this->time->getTime() + 1);
	}

	private function extendLease(OneNoteImport $import, ?string $leaseToken): void {
		if ($leaseToken === null) {
			return;
		}
		$until = $this->time->getTime() + self::LEASE_SECONDS;
		$n = $this->imports->updateWhere((int)$import->getId(), (string)$import->getUid(), ['lease_until' => $until],
			['lease_token' => $leaseToken, 'status' => 'running']);
		if ($n !== 1) {
			throw new GraphException('lease');
		}
		$import->setLeaseUntil($until);
	}

	// ≥ 0,6 s seit der letzten Anfrage dieses Prozesses (≤ 100/min, keine Parallelität)
	private function space(): void {
		if ($this->lastRequest <= 0.0) {
			return;
		}
		$wait = RateBudget::MIN_GAP - ($this->clock() - $this->lastRequest);
		if ($wait > 0) {
			$this->pause($wait);
		}
	}

	private static function graphCode(HttpResult $res): string {
		$data = $res->json();
		$code = is_array($data) && is_array($data['error'] ?? null) ? ($data['error']['code'] ?? '') : '';
		return is_scalar($code) ? substr((string)$code, 0, 32) : '';
	}

	protected function clock(): float {
		return microtime(true);
	}

	protected function pause(float $seconds): void {
		usleep((int)ceil($seconds * 1_000_000));
	}
}
