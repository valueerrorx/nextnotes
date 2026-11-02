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
use OCA\NextNotes\Service\Exception\LockedException;
use OCA\NextNotes\Service\Exception\NotFoundException;
use OCA\NextNotes\Service\LockService;
use OCA\NextNotes\Service\WorkspaceService;
use OCA\NextNotes\Tests\Support\ApiClient;
use OCA\NextNotes\Tests\Support\ApiTestCase;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use OCP\Server;

// SPEC §13 M2.6, Plan Done M2b 3, ADR 0005, §11.2 B13: Sperre mit TTL (gemockte Zeit) + HTTP-Gegenprobe
final class LockServiceTest extends ApiTestCase {
	private int $now = 0;

	private function service(): LockService {
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn () => $this->now);
		return new LockService(Server::get(LockMapper::class), Server::get(AccessService::class), $time, Server::get(IUserManager::class));
	}

	/** A mit Notiz in Workspace, B mit Freigabe ($perm) */
	private function setUpShared(string $perm = 'write'): array {
		$a = $this->createUser('la');
		$b = $this->createUser('lb');
		[$ws, $wsId] = self::newWorkspace($a, 'Lock');
		$n = self::newNote($a, $ws, 'Gesperrt', '<p>x</p>');
		Server::get(WorkspaceService::class)->share($a->uid, $wsId, $b->uid, $perm);
		Server::get(AccessService::class)->resetCache();
		return [$a, $b, $ws, $wsId, $n];
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

	public function testSecondSessionConflictsUntilTtlThenTakesLock(): void {
		[$a, $b, , , $n] = $this->setUpShared();
		$svc = $this->service();
		$t0 = $this->now = time();

		$got = $svc->acquire($a->uid, $n, 's1');
		self::assertTrue($got['lock']['holder_is_current_editor_session']);

		// Sitzung 2: derselbe Benutzer A, B mit Schreibrecht, B mit gleicher Sitzungs-ID → 409
		$e = self::expectApi(ConflictException::class, fn () => $svc->acquire($a->uid, $n, 's2'), 'A/s2 erwirbt');
		self::assertSame(409, $e->getStatus());
		self::assertArrayHasKey('lock', $e->getExtra(), 'Upstream-lock-Payload bei 409');
		self::assertTrue($e->getExtra()['lock']['holder_is_current_user']);
		self::assertFalse($e->getExtra()['lock']['holder_is_current_editor_session']);
		self::expectApi(ConflictException::class, fn () => $svc->acquire($b->uid, $n, 's2'), 'B/s2 erwirbt');
		self::expectApi(ConflictException::class, fn () => $svc->acquire($b->uid, $n, 's1'), 'B mit Sitzungs-ID von A erwirbt');

		// Speichern aus Sitzung 2 → 423; ohne Sitzung als B → 423
		$e = self::expectApi(LockedException::class, fn () => $svc->assertCanSave($a->uid, $n, 's2'), 'A/s2 speichert');
		self::assertSame(423, $e->getStatus());
		self::expectApi(LockedException::class, fn () => $svc->assertCanSave($b->uid, $n, 's2'), 'B/s2 speichert');
		self::expectApi(LockedException::class, fn () => $svc->assertCanSave($b->uid, $n, 's1'), 'B mit Sitzungs-ID von A speichert');
		self::expectApi(LockedException::class, fn () => $svc->assertCanSave($b->uid, $n, ''), 'B ohne Sitzung speichert');

		// Sitzung 1 speichert und erneuert ohne Konflikt; Heartbeat verlängert die TTL
		$svc->assertCanSave($a->uid, $n, 's1');
		$this->now = $t0 + 60;
		$svc->acquire($a->uid, $n, 's1');

		// Heartbeat/Release nur durch die haltende Sitzung desselben Kontos
		self::expectApi(ConflictException::class, fn () => $svc->acquire($b->uid, $n, 's1'), 'B Heartbeat mit fremder Sitzung');
		$svc->release($b->uid, $n, 's1');
		$svc->release($a->uid, $n, 's2');
		self::assertTrue($svc->status($a->uid, $n, 's1')['lock']['holder_is_current_editor_session'], 'Release durch Fremde hat die Sperre entfernt');

		// kurz vor Ablauf (60 + 89 s) weiterhin Konflikt
		$this->now = $t0 + 60 + 89;
		self::expectApi(ConflictException::class, fn () => $svc->acquire($b->uid, $n, 's2'), 'B/s2 vor Ablauf');

		// nach TTL (60 + 91 s) erhält Sitzung 2 die Sperre
		$this->now = $t0 + 60 + 91;
		$got = $svc->acquire($b->uid, $n, 's2');
		self::assertTrue($got['lock']['holder_is_current_editor_session']);
		self::assertFalse($svc->status($a->uid, $n, 's1')['lock']['holder_is_current_user']);
		// alte Sitzung 1 darf jetzt weder speichern noch erwerben
		self::expectApi(LockedException::class, fn () => $svc->assertCanSave($a->uid, $n, 's1'), 'A/s1 nach Übernahme speichert');
		self::expectApi(ConflictException::class, fn () => $svc->acquire($a->uid, $n, 's1'), 'A/s1 nach Übernahme erwirbt');

		$svc->release($b->uid, $n, 's2');
		self::assertNull($svc->status($a->uid, $n, 's1')['lock'], 'Release durch Halter');
		$svc->acquire($a->uid, $n, 's1');
	}

	// ADR 0005: gültig nur bei expires_at > now; TTL 90 s
	public function testSameAccountOtherSessionAfterTtl(): void {
		[$a, , , , $n] = $this->setUpShared();
		$svc = $this->service();
		$t0 = $this->now = time();
		$svc->acquire($a->uid, $n, 's1');
		$this->now = $t0 + 89;
		self::expectApi(ConflictException::class, fn () => $svc->acquire($a->uid, $n, 's2'), 'A/s2 bei t0+89');
		$this->now = $t0 + 90;
		self::assertTrue($svc->acquire($a->uid, $n, 's2')['lock']['holder_is_current_editor_session'], 'A/s2 bei t0+90');
	}

	public function testExpiryBoundary(): void {
		[$a, , , , $n] = $this->setUpShared();
		$svc = $this->service();
		$t0 = $this->now = time();
		$svc->acquire($a->uid, $n, 's1');
		$this->now = $t0 + 91;
		self::assertNull($svc->status($a->uid, $n, 's2')['lock'], 'abgelaufene Sperre gilt nicht');
		$svc->assertCanSave($a->uid, $n, 's2');
		$got = $svc->acquire($a->uid, $n, 's2');
		self::assertTrue($got['lock']['holder_is_current_editor_session']);
	}

	public function testNoAccessAndReadOnly(): void {
		[$a, $b, , $wsId, $n] = $this->setUpShared('read');
		$c = $this->createUser('lc');
		$svc = $this->service();
		$this->now = time();
		self::expectApi(NotFoundException::class, fn () => $svc->acquire($c->uid, $n, 's9'), 'C ohne Recht erwirbt');
		self::expectApi(NotFoundException::class, fn () => $svc->status($c->uid, $n, 's9'), 'C ohne Recht liest Status');
		$e = self::expectApi(ApiException::class, fn () => $svc->acquire($b->uid, $n, 's9'), 'B nur lesend erwirbt');
		self::assertSame(403, $e->getStatus());
		self::assertNull($svc->status($b->uid, $n, 's9')['lock']);
		Server::get(WorkspaceService::class)->unshare($a->uid, $wsId, $b->uid);
		Server::get(AccessService::class)->resetCache();
		self::expectApi(NotFoundException::class, fn () => $svc->status($b->uid, $n, 's9'), 'B nach Entzug liest Status');
	}

	// HTTP-Gegenprobe ohne Zeitmock (Plan Done M2b 3)
	public function testHttpConflictAndLocked(): void {
		[$a, $b, $ws, , $n] = $this->setUpShared();
		$s1 = ['X-Editor-Session-ID: h-s1'];
		$s2 = ['X-Editor-Session-ID: h-s2'];
		self::ok($a->call('POST', "/notes/$n/lock", ['editor_session_id' => 'h-s1'], $s1), 'A erwirbt');

		$r = $b->call('POST', "/notes/$n/lock", ['editor_session_id' => 'h-s2', 'takeover' => true], $s2);
		self::assertSame(409, $r->status, 'B erwirbt (takeover wird ignoriert): ' . $r->describe());
		$j = $r->json();
		self::assertFalse($j['success'] ?? null);
		self::assertIsArray($j['lock'] ?? null, 'lock-Payload bei 409');
		$r = $a->call('POST', "/notes/$n/lock", ['editor_session_id' => 'h-s2', 'takeover' => true], $s2);
		self::assertSame(409, $r->status, 'A aus zweiter Sitzung mit takeover: ' . $r->describe());

		foreach (['B/s2' => [$b, $s2], 'B ohne Sitzung' => [$b, []], 'A/s2' => [$a, $s2]] as $label => [$c, $h]) {
			$r = $c->call('PATCH', "/notes/$n", ['content' => '<p>fremd</p>'], $h);
			self::assertSame(423, $r->status, "$label PATCH: " . $r->describe());
			$r = $c->call('POST', "/notes/$n/beacon", ['content' => '<p>fremd</p>'], $h);
			self::assertSame(423, $r->status, "$label Beacon: " . $r->describe());
		}
		self::assertSame(409, $b->call('POST', "/notes/$n/lock/heartbeat", ['editor_session_id' => 'h-s1'], $s1)->status, 'B Heartbeat mit Sitzung von A');
		self::ok($b->call('POST', "/notes/$n/lock/release", ['editor_session_id' => 'h-s1'], $s1), 'B Release (wirkungslos)');
		$st = self::ok($a->call('GET', "/notes/$n/lock", null, $s1), 'A Status')['lock'];
		self::assertTrue($st['holder_is_current_editor_session'] ?? false, 'Sperre nach fremdem Release noch bei A/s1');

		$put = self::ok($a->call('PATCH', "/notes/$n", ['content' => '<p>eigen</p>'], $s1), 'A/s1 speichert');
		$version = (string)($put['note']['version'] ?? '');
		self::assertNotSame('', $version);
		// Versionskonflikt → 409
		$r = $a->call('PATCH', "/notes/$n", ['content' => '<p>alt</p>'], array_merge($s1, ['If-Match: "veraltet"']));
		self::assertSame(409, $r->status, 'If-Match veraltet: ' . $r->describe());
		self::ok($a->call('PATCH', "/notes/$n", ['content' => '<p>neu</p>'], array_merge($s1, ['If-Match: "' . $version . '"'])), 'If-Match aktuell');

		self::ok($a->call('POST', "/notes/$n/lock/release", ['editor_session_id' => 'h-s1'], $s1), 'A Release');
		self::ok($b->call('POST', "/notes/$n/lock", ['editor_session_id' => 'h-s2'], $s2), 'B erwirbt nach Release');
		self::assertSame(423, $a->call('PATCH', "/notes/$n", ['content' => '<p>a</p>'], $s1)->status, 'A/s1 nach Übernahme durch B');
	}

	// SPEC §11.2 B13: Speichern mit Sitzung ohne Sperre erwirbt sie; ohne Sitzung blockiert nur die Sperre eines anderen Kontos
	public function testHttpSaveAcquiresLockAndSessionlessSave(): void {
		[$a, $b, , , $n] = $this->setUpShared();
		self::assertNull(self::ok($a->call('GET', "/notes/$n/lock"), 'Status vorher')['lock']);
		self::ok($a->call('PATCH', "/notes/$n", ['content' => '<p>1</p>'], ['X-Editor-Session-ID: b13-s1']), 'A/s1 speichert ohne Sperre');
		$st = self::ok($a->call('GET', "/notes/$n/lock", null, ['X-Editor-Session-ID: b13-s1']), 'Status nachher')['lock'];
		self::assertTrue($st['holder_is_current_editor_session'] ?? false, 'PATCH hat keine Sperre angelegt');

		$s2 = ['X-Editor-Session-ID: b13-s2'];
		self::assertSame(409, $a->call('POST', "/notes/$n/lock", ['editor_session_id' => 'b13-s2'], $s2)->status, 'A/s2 erwirbt');
		self::assertSame(423, $a->call('PATCH', "/notes/$n", ['content' => '<p>2</p>'], $s2)->status, 'A/s2 speichert');
		self::assertSame(409, $b->call('POST', "/notes/$n/lock", ['editor_session_id' => 'b13-s2'], $s2)->status, 'B/s2 erwirbt');
		self::assertSame(423, $b->call('PATCH', "/notes/$n", ['content' => '<p>2</p>'], $s2)->status, 'B/s2 speichert');

		// ohne Sitzung: eigenes Konto darf, anderes Konto 423
		self::ok($a->call('PATCH', "/notes/$n", ['content' => '<p>ohne</p>']), 'A ohne Sitzung');
		self::assertSame(423, $b->call('PATCH', "/notes/$n", ['content' => '<p>ohne</p>'])->status, 'B ohne Sitzung');
		// Sperre unverändert bei A/s1
		$st = self::ok($a->call('GET', "/notes/$n/lock", null, ['X-Editor-Session-ID: b13-s1']), 'Status Ende')['lock'];
		self::assertTrue($st['holder_is_current_editor_session'] ?? false);
	}
}
