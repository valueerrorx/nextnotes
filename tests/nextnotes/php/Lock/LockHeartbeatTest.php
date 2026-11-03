<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Lock;

use OCA\NextNotes\Db\LockMapper;
use OCA\NextNotes\Service\AccessService;
use OCA\NextNotes\Service\Exception\ApiException;
use OCA\NextNotes\Service\Exception\ConflictException;
use OCA\NextNotes\Service\Exception\NotFoundException;
use OCA\NextNotes\Service\LockService;
use OCA\NextNotes\Service\WorkspaceService;
use OCA\NextNotes\Tests\Support\ApiTestCase;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use OCP\Server;

// SPEC §11.2 B53 (M3-fix4): Heartbeat verlängert nur, Speichern legt nie an, nur POST /lock erwirbt
final class LockHeartbeatTest extends ApiTestCase {
	private int $now = 0;

	private function service(): LockService {
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn () => $this->now);
		return new LockService(Server::get(LockMapper::class), Server::get(AccessService::class), $time, Server::get(IUserManager::class));
	}

	private function setUpShared(string $perm = 'write'): array {
		$a = $this->createUser('ha');
		$b = $this->createUser('hb');
		[$ws, $wsId] = self::newWorkspace($a, 'Herz');
		$n = self::newNote($a, $ws, 'Puls', '<p>x</p>');
		Server::get(WorkspaceService::class)->share($a->uid, $wsId, $b->uid, $perm);
		Server::get(AccessService::class)->resetCache();
		return [$a, $b, $n];
	}

	private static function expectApi(string $class, callable $fn, string $what): ApiException {
		try {
			$fn();
		} catch (ApiException $e) {
			self::assertInstanceOf($class, $e, "$what: falsche Ausnahme " . get_class($e) . ' ' . $e->getMessage());
			return $e;
		}
		self::fail("$what: erwartet $class, keine Ausnahme");
	}

	public function testHeartbeatNeverCreates(): void {
		[$a, , $n] = $this->setUpShared();
		$svc = $this->service();
		$this->now = time();
		self::assertSame(['lock' => null], $svc->heartbeat($a->uid, $n, 's1'), 'Heartbeat ohne Sperre');
		self::assertNull($svc->status($a->uid, $n, 's1')['lock'], 'Heartbeat hat eine Sperre angelegt');
		$svc->assertCanSave($a->uid, $n, 's1');
		self::assertNull($svc->status($a->uid, $n, 's1')['lock'], 'Speichern hat eine Sperre angelegt');
	}

	public function testHeartbeatExtendsOwnLock(): void {
		[$a, , $n] = $this->setUpShared();
		$svc = $this->service();
		$t0 = $this->now = time();
		$svc->acquire($a->uid, $n, 's1');
		$this->now = $t0 + 80;
		$got = $svc->heartbeat($a->uid, $n, 's1');
		self::assertTrue($got['lock']['holder_is_current_editor_session']);
		$this->now = $t0 + 160;
		self::assertTrue($svc->status($a->uid, $n, 's1')['lock']['holder_is_current_editor_session'] ?? false, 'Heartbeat hat nicht verlängert');
		// abgelaufene eigene Sperre wird nicht wiederbelebt
		$this->now = $t0 + 80 + 91;
		self::assertSame(['lock' => null], $svc->heartbeat($a->uid, $n, 's1'));
		self::assertNull($svc->status($a->uid, $n, 's1')['lock']);
	}

	// Rückkehr von der Anhang-Seite: Freigabe, später Heartbeat/PATCH der alten Seite, Erwerb der neuen Seite
	public function testLateHeartbeatAfterReleaseKeepsNoteFree(): void {
		[$a, , $n] = $this->setUpShared();
		$svc = $this->service();
		$this->now = time();
		$svc->acquire($a->uid, $n, 'alt');
		$svc->release($a->uid, $n, 'alt');
		self::assertSame(['lock' => null], $svc->heartbeat($a->uid, $n, 'alt'));
		$svc->assertCanSave($a->uid, $n, 'alt');
		self::assertTrue($svc->acquire($a->uid, $n, 'neu')['lock']['holder_is_current_editor_session'], 'neue Seite erwirbt sofort');
	}

	// ADR 0005: fremde Sitzung → 409, fremdes Konto → 409 mit fremdem Halter
	public function testHeartbeatForeignHolderConflicts(): void {
		[$a, $b, $n] = $this->setUpShared();
		$svc = $this->service();
		$t0 = $this->now = time();
		$svc->acquire($a->uid, $n, 's1');
		$e = self::expectApi(ConflictException::class, fn () => $svc->heartbeat($a->uid, $n, 's2'), 'A/s2 Heartbeat');
		self::assertSame(409, $e->getStatus());
		self::assertTrue($e->getExtra()['lock']['holder_is_current_user']);
		self::assertFalse($e->getExtra()['lock']['holder_is_current_editor_session']);
		$e = self::expectApi(ConflictException::class, fn () => $svc->heartbeat($b->uid, $n, 's1'), 'B mit Sitzung von A');
		self::assertFalse($e->getExtra()['lock']['holder_is_current_user']);
		// fremde Heartbeats verlängern nicht
		$this->now = $t0 + 91;
		self::assertNull($svc->status($a->uid, $n, 's1')['lock']);
	}

	public function testHeartbeatRights(): void {
		[$a, $b, $n] = $this->setUpShared('read');
		$c = $this->createUser('hc');
		$svc = $this->service();
		$this->now = time();
		self::expectApi(NotFoundException::class, fn () => $svc->heartbeat($c->uid, $n, 's9'), 'C ohne Recht');
		self::assertSame(403, self::expectApi(ApiException::class, fn () => $svc->heartbeat($b->uid, $n, 's9'), 'B nur lesend')->getStatus());
		self::assertSame(400, self::expectApi(ApiException::class, fn () => $svc->heartbeat($a->uid, $n, ' '), 'ohne Sitzung')->getStatus());
	}

	public function testHttpHeartbeat(): void {
		[$a, $b, $n] = $this->setUpShared();
		$s1 = ['X-Editor-Session-ID: hb-s1'];
		$j = self::ok($a->call('POST', "/notes/$n/lock/heartbeat", ['editor_session_id' => 'hb-s1'], $s1), 'Heartbeat ohne Sperre');
		self::assertArrayHasKey('lock', $j);
		self::assertNull($j['lock']);
		self::assertNull(self::ok($a->call('GET', "/notes/$n/lock", null, $s1), 'Status')['lock'], 'Heartbeat hat angelegt');
		self::ok($a->call('POST', "/notes/$n/lock", ['editor_session_id' => 'hb-s1'], $s1), 'A erwirbt');
		$j = self::ok($a->call('POST', "/notes/$n/lock/heartbeat", ['editor_session_id' => 'hb-s1'], $s1), 'eigener Heartbeat');
		self::assertTrue($j['lock']['holder_is_current_editor_session'] ?? false);
		$r = $a->call('POST', "/notes/$n/lock/heartbeat", ['editor_session_id' => 'hb-s2'], ['X-Editor-Session-ID: hb-s2']);
		self::assertSame(409, $r->status, 'A/s2 Heartbeat: ' . $r->describe());
		self::assertIsArray($r->json()['lock'] ?? null);
		self::assertSame(409, $b->call('POST', "/notes/$n/lock/heartbeat", ['editor_session_id' => 'hb-b'], ['X-Editor-Session-ID: hb-b'])->status, 'B Heartbeat');
	}
}
