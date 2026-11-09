<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\OneNote;

use OCA\NextNotes\Db\OneNoteImportMapper;
use OCA\NextNotes\OneNote\ConnectService;
use OCA\NextNotes\OneNote\GraphClient;
use OCA\NextNotes\OneNote\GraphException;
use OCA\NextNotes\OneNote\HttpGate;
use OCA\NextNotes\OneNote\OAuthService;
use OCA\NextNotes\OneNote\OneNoteConfig;
use OCA\NextNotes\OneNote\OneNoteNotifications;
use OCA\NextNotes\OneNote\TokenException;
use OCA\NextNotes\OneNote\TokenVault;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\Security\ISecureRandom;
use OCP\Server;
use PHPUnit\Framework\Attributes\Group;

// M9a gegen den Mock (Profil msmock, dev/nextcloud/msmock.sh): echter IClient mit NC-Zertifikatsbündel, Redirect nicht gefolgt,
// Refresh/invalid_grant/invalid_client, und ohne allow_local_remote_servers blockt der Local-Address-Schutz (App hebt ihn nicht auf)
#[Group('msmock')]
final class MockIntegrationTest extends OneNoteTestCase {
	protected function setUp(): void {
		parent::setUp();
		self::ctl('op=reset');
	}

	/** @return array<string, mixed> */
	private static function ctl(string $query): array {
		$ch = curl_init('http://ms-mock/__ctl?' . $query);
		curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
		$body = curl_exec($ch);
		self::assertIsString($body, 'Mock-Steuerung erreichbar (msmock.sh up)');
		return (array)json_decode($body, true);
	}

	private static function mockCount(string $key): int {
		return (int)(self::ctl('op=counts')['counts'][$key] ?? 0);
	}

	private function real(): ConnectService {
		return new ConnectService(
			Server::get(OneNoteConfig::class),
			Server::get(OneNoteImportMapper::class),
			Server::get(\OCA\NextNotes\Db\OneNoteItemMapper::class),
			new OAuthService(new HttpGate(Server::get(IClientService::class))),
			Server::get(TokenVault::class),
			Server::get(ISecureRandom::class),
			Server::get(ITimeFactory::class),
			Server::get(OneNoteNotifications::class),
			$this->logger(),
		);
	}

	private function signInReal(string $uid, string $sub = 'sub-int'): int {
		$svc = $this->real();
		$c = $svc->connect($uid, null);
		$r = $svc->callback($uid, self::stateOf($c['authorize_url']), 'mock.' . $sub, '');
		self::assertSame(['import' => $c['import_id']], $r->query, json_encode($this->logs) ?: '');
		return $c['import_id'];
	}

	public function testTokenFlowAndRefresh(): void {
		$u = $this->createUser('mi');
		self::ctl('op=set&expires_in=60');
		$id = $this->signInReal($u->uid);
		$vault = Server::get(TokenVault::class);
		$row = self::row($id);
		self::assertSame('select', $row['status']);
		self::assertStringStartsWith('msmock-access-', (string)$vault->open($row['access_enc']));
		self::assertSame(1, self::mockCount('login.microsoftonline.com/' . self::TENANT . '/oauth2/v2.0/token'));
		$imports = Server::get(OneNoteImportMapper::class);
		$imports->updateWhere($id, $u->uid, ['status' => 'queued']);
		$access = $this->real()->accessToken($imports->findOwn($id, $u->uid));
		self::assertSame(2, self::mockCount('login.microsoftonline.com/' . self::TENANT . '/oauth2/v2.0/token'), 'Refresh bei expires_in=60');
		$new = self::row($id);
		self::assertSame($access, $vault->open($new['access_enc']));
		self::assertNotSame($vault->open($row['refresh_enc']), $vault->open($new['refresh_enc']), 'Refresh-Token ersetzt');

		$graph = new GraphClient(new HttpGate(Server::get(IClientService::class)), $imports, Server::get(ITimeFactory::class), $this->logger());
		$res = $graph->get($imports->findOwn($id, $u->uid), $access, GraphClient::PREFIX . 'me/onenote/notebooks');
		self::assertSame(200, $res->status);
		try {
			$graph->get($imports->findOwn($id, $u->uid), $access, GraphClient::PREFIX . '__redirect');
			self::fail('redirect erwartet');
		} catch (GraphException $e) {
			self::assertSame('redirect', $e->reason);
		}
		self::assertSame(1, self::mockCount('graph.microsoft.com/v1.0/__redirect'));
		self::assertSame(0, self::mockCount('graph.microsoft.com/v1.0/__target'), 'Redirect nicht gefolgt');

		self::ctl('op=set&token_error=invalid_grant');
		try {
			$this->real()->refresh($imports->findOwn($id, $u->uid));
			self::fail('reauth erwartet');
		} catch (TokenException $e) {
			self::assertSame('reauth', $e->reason);
		}
		self::assertSame('reauth', self::row($id)['status']);
		self::assertNull(self::row($id)['refresh_enc']);
	}

	public function testInvalidClientAndWrongAudience(): void {
		$u = $this->createUser('mc');
		$id = $this->signInReal($u->uid);
		$imports = Server::get(OneNoteImportMapper::class);
		$imports->updateWhere($id, $u->uid, ['status' => 'queued']);
		self::ctl('op=set&token_error=invalid_client');
		try {
			$this->real()->refresh($imports->findOwn($id, $u->uid));
			self::fail('failed erwartet');
		} catch (TokenException $e) {
			self::assertSame('failed', $e->reason);
		}
		self::assertSame('invalid_client', self::row($id)['error_code']);
		self::ctl('op=reset');
		$w = $this->createUser('mw');
		$svc = $this->real();
		$c = $svc->connect($w->uid, null);
		self::assertSame(['msg' => 'failed'], $svc->callback($w->uid, self::stateOf($c['authorize_url']), 'mock-badaud.sub-x', '')->query);
		self::assertSame([], self::rowsOf($w->uid));
	}

	// Done 8 praktisch: ohne allow_local_remote_servers erreicht der Abruf den Mock (private Adresse) nicht
	public function testLocalAddressProtectionBlocksMock(): void {
		$u = $this->createUser('ml');
		$cfg = Server::get(IConfig::class);
		$cfg->setSystemValue('allow_local_remote_servers', false);
		try {
			$svc = $this->real();
			$c = $svc->connect($u->uid, null);
			self::assertSame(['msg' => 'failed'], $svc->callback($u->uid, self::stateOf($c['authorize_url']), 'mock.sub-l', '')->query);
		} finally {
			$cfg->setSystemValue('allow_local_remote_servers', true);
		}
		self::assertSame(0, self::mockCount('login.microsoftonline.com/' . self::TENANT . '/oauth2/v2.0/token'), 'kein Abruf');
	}
}
