<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\OneNote;

use OCA\NextNotes\Db\OneNoteImportMapper;
use OCA\NextNotes\OneNote\GraphClient;
use OCA\NextNotes\OneNote\GraphException;
use OCA\NextNotes\OneNote\HttpGate;
use OCA\NextNotes\OneNote\RateBudget;
use OCP\Server;

// M9a Done 7 und 8: feste Hosts, keine Redirects, nextLink/Ressourcen nur Graph, Backoff, gleitendes Fenster, Abstand, Local-Address-Schutz bleibt an
final class GraphClientTest extends OneNoteTestCase {
	private float $clockValue = 1000.0;
	/** @var list<float> */
	private array $pauses = [];

	private function graph(FakeHttp $http): GraphClient {
		$test = $this;
		return new class(new HttpGate($http), Server::get(OneNoteImportMapper::class), $this->time(), $this->logger(), $test) extends GraphClient {
			public function __construct($gate, $mapper, $time, $logger, private GraphClientTest $test) {
				parent::__construct($gate, $mapper, $time, $logger);
			}

			protected function clock(): float {
				return $this->test->clockNow();
			}

			protected function pause(float $seconds): void {
				$this->test->pauseFor($seconds);
			}
		};
	}

	public function clockNow(): float {
		return $this->clockValue;
	}

	public function pauseFor(float $s): void {
		$this->pauses[] = $s;
		$this->clockValue += $s;
	}

	private function import(string $uid, array $extra = []): \OCA\NextNotes\Db\OneNoteImport {
		$id = self::insertImport($uid, 'queued', $extra);
		return Server::get(OneNoteImportMapper::class)->findOwn($id, $uid);
	}

	private static function okResponse(): FakeResponse {
		return FakeHttp::json(200, ['value' => []]);
	}

	public function testUrlRules(): void {
		self::assertTrue(GraphClient::nextLinkAllowed('https://graph.microsoft.com/v1.0/me/onenote/sections/1-a/pages?$skip=100'));
		foreach ([
			'https://evil.example/v1.0/me/onenote/notebooks',
			'https://graph.microsoft.com.evil.example/v1.0/me',
			'https://graph.microsoft.com/beta/me/onenote/notebooks',
			'http://graph.microsoft.com/v1.0/me',
			'https://user@graph.microsoft.com/v1.0/me',
			'https://graph.microsoft.com:8443/v1.0/me',
			'https://graph.microsoft.com/v1.0/../v1.0/me',
			'https://graph.microsoft.com/v1.0/me\\x',
		] as $url) {
			self::assertFalse(GraphClient::nextLinkAllowed($url), $url);
		}
		self::assertSame('0-abc!1.2', GraphClient::resourceIdFromUrl('https://graph.microsoft.com/v1.0/me/onenote/resources/0-abc!1.2/$value'));
		self::assertNull(GraphClient::resourceIdFromUrl('https://evil.example/v1.0/me/onenote/resources/0-abc/$value'));
		self::assertNull(GraphClient::resourceIdFromUrl('https://www.onenote.com/api/v1.0/me/notes/resources/0-abc/$value'));
		self::assertNull(GraphClient::resourceIdFromUrl('https://graph.microsoft.com/v1.0/me/onenote/resources/a%2Fb/$value'));
		self::assertSame('https://graph.microsoft.com/v1.0/groups/g-1/onenote/resources/r-1/$value', GraphClient::resourceUrl('group:g-1', 'r-1'));
		self::assertNull(GraphClient::resourceUrl('group:../x', 'r-1'));
		self::assertNull(GraphClient::rootPath('site:1'));
	}

	// Done 7: Redirect wird nicht gefolgt, fremde Hosts werden nicht abgerufen
	public function testNoRedirectNoForeignHost(): void {
		$u = $this->createUser('gr');
		$http = new FakeHttp(static fn () => new FakeResponse(302, '', ['Location' => 'https://evil.example/x']));
		$g = $this->graph($http);
		$imp = $this->import($u->uid);
		try {
			$g->get($imp, 'tok', GraphClient::PREFIX . 'me/onenote/notebooks');
			self::fail('redirect erwartet');
		} catch (GraphException $e) {
			self::assertSame('redirect', $e->reason);
		}
		self::assertSame([GraphClient::PREFIX . 'me/onenote/notebooks'], $http->urls(), 'Location nicht abgerufen');
		foreach (['https://evil.example/v1.0/me', 'https://graph.microsoft.com.evil.example/v1.0/me', 'https://www.onenote.com/api/v1.0/me/notes'] as $url) {
			try {
				$g->get($imp, 'tok', $url);
				self::fail("host erwartet: $url");
			} catch (GraphException $e) {
				self::assertSame('host', $e->reason);
			}
		}
		self::assertCount(1, $http->calls, 'fremde Hosts: Zähler 0');
	}

	// Done 8: weder GraphClient noch OAuthService setzen nextcloud/allow_local_address; keine Redirects; Authorization nur an Graph
	public function testLocalAddressProtectionStaysOn(): void {
		$u = $this->createUser('la');
		$token = $this->tokenResponder();
		$http = new FakeHttp(static fn (string $m, string $url, array $o) => str_contains($url, 'login.microsoftonline.com') ? $token($m, $url, $o) : self::okResponse());
		$this->graph($http)->get($this->import($u->uid), 'tok', GraphClient::PREFIX . 'me/onenote/notebooks');
		$this->signIn($this->connectService($http), $this->createUser('lb')->uid);
		self::assertCount(2, $http->calls);
		foreach ($http->calls as $call) {
			self::assertArrayNotHasKey('nextcloud', $call['options'], $call['url']);
			self::assertFalse($call['options']['allow_redirects'], 'keine Redirects');
			self::assertSame(30, $call['options']['timeout']);
			self::assertTrue($call['options']['stream']);
			$auth = $call['options']['headers']['Authorization'] ?? null;
			if (str_starts_with($call['url'], 'https://graph.microsoft.com/')) {
				self::assertSame('Bearer tok', $auth);
			} else {
				self::assertNull($auth, 'kein Authorization-Header an login');
			}
		}
		// HttpGate entfernt Authorization auch bei falscher Verwendung
		$gate = new HttpGate($http);
		$gate->send('POST', 'https://login.microsoftonline.com/common/oauth2/v2.0/token', HttpGate::LOGIN_HOST, ['Authorization' => 'Bearer x'], ['a' => 'b']);
		self::assertArrayNotHasKey('Authorization', $http->calls[2]['options']['headers']);
	}

	// Done 7: 429 ohne Retry-After → 5 min (× 2^level, ≤ 60 min), mit Retry-After 120 → 120 s; 404/403/401/5xx als Grund
	public function testErrorMapping(): void {
		$u = $this->createUser('er');
		$status = 429;
		$headers = [];
		$http = new FakeHttp(function () use (&$status, &$headers) {
			return FakeHttp::json($status, ['error' => ['code' => '20102', 'message' => 'x']], $headers);
		});
		$g = $this->graph($http);
		$imp = $this->import($u->uid);
		$url = GraphClient::PREFIX . 'me/onenote/notebooks';
		$catch = function () use ($g, $imp, $url): GraphException {
			try {
				$g->get($imp, 'tok', $url);
			} catch (GraphException $e) {
				return $e;
			}
			self::fail('GraphException erwartet');
		};
		$e = $catch();
		self::assertSame('throttled', $e->reason);
		self::assertSame($this->now + 300, $e->retryAt);
		self::assertTrue($e->isTransient());
		$headers = ['Retry-After' => '120'];
		self::assertSame($this->now + 120, $catch()->retryAt);
		$headers = [];
		$imp->setBackoffLevel(2);
		self::assertSame($this->now + 1200, $catch()->retryAt);
		$imp->setBackoffLevel(9);
		self::assertSame($this->now + 3600, $catch()->retryAt, 'höchstens 60 min');
		foreach ([401 => 'unauthorized', 403 => 'forbidden', 404 => 'not_found', 503 => 'server'] as $status => $reason) {
			$e = $catch();
			self::assertSame($reason, $e->reason);
			self::assertSame('20102', $e->graphCode);
		}
		self::assertSame(300, RateBudget::backoffSeconds(0));
		self::assertSame(120, RateBudget::backoffSeconds(5, '120'));
		self::assertSame(600, RateBudget::backoffSeconds(1, 'Wed, 21 Oct 2026 07:28:00 GMT'), 'HTTP-Datum → Standard');
	}

	// Done 7: gleitendes Fenster ≤ 350 Anfragen je 60 min, auch über Fenstergrenzen; Abstand ≥ 0,6 s
	public function testSlidingWindowAndSpacing(): void {
		$u = $this->createUser('sw');
		$times = [];
		$http = new FakeHttp(function () use (&$times) {
			$times[] = $this->now;
			return self::okResponse();
		});
		$g = $this->graph($http);
		$imp = $this->import($u->uid);
		$url = GraphClient::PREFIX . 'me/onenote/notebooks';
		$this->now = 1_900_000_037;
		$blocked = 0;
		$end = $this->now + 3 * 3600;
		while ($this->now < $end) {
			try {
				$g->get($imp, 'tok', $url);
				$this->clockValue += 0.1;
				$this->now += 5;
			} catch (GraphException $e) {
				self::assertSame('budget', $e->reason);
				self::assertGreaterThan($this->now, $e->retryAt);
				$blocked++;
				$this->now = $e->retryAt;
			}
		}
		self::assertGreaterThan(700, count($times), 'mehrere Fenster durchlaufen');
		self::assertGreaterThan(0, $blocked, 'Fenster war voll');
		foreach ($times as $i => $t) {
			$inWindow = count(array_filter($times, static fn (int $x): bool => $x > $t - 3600 && $x <= $t));
			self::assertLessThanOrEqual(RateBudget::MAX, $inWindow, "Fenster bis $t (Anfrage $i)");
		}
		self::assertNotSame([], $this->pauses);
		foreach ($this->pauses as $p) {
			self::assertEqualsWithDelta(0.5, $p, 0.0001, 'Abstand auf 0,6 s aufgefüllt');
		}
		// Budget liegt auf der Zeile (rate_version zählt mit)
		$row = self::row((int)$imp->getId());
		self::assertSame(count($times), (int)$row['rate_version']);
	}

	// Lease: Verlängerung nach jeder Anfrage nur mit eigenem lease_token und status running
	public function testLeaseExtension(): void {
		$u = $this->createUser('ls');
		$http = new FakeHttp(static fn () => self::okResponse());
		$g = $this->graph($http);
		$imp = $this->import($u->uid, ['status' => 'running', 'lease_token' => 'lease-1', 'lease_until' => 1]);
		$g->get($imp, 'tok', GraphClient::PREFIX . 'me/onenote/notebooks', 'lease-1');
		self::assertSame($this->now + 300, (int)self::row((int)$imp->getId())['lease_until']);
		try {
			$g->get($imp, 'tok', GraphClient::PREFIX . 'me/onenote/notebooks', 'lease-2');
			self::fail('lease erwartet');
		} catch (GraphException $e) {
			self::assertSame('lease', $e->reason);
		}
	}
}
