<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\OneNote;

use OCA\NextNotes\Db\OneNoteImportMapper;
use OCA\NextNotes\OneNote\OAuthService;
use OCA\NextNotes\OneNote\OneNoteConfig;
use OCA\NextNotes\OneNote\TokenException;
use OCA\NextNotes\OneNote\TokenVault;
use OCA\NextNotes\Service\Exception\ConflictException;
use OCP\IURLGenerator;
use OCP\Server;

// M9a Done 3, 3a, 4, 4a, 4b, 5, 5a: Anmeldung mit PKCE, Callback-Regeln, Token verschlüsselt, Refresh, Konfigurationswechsel
final class OAuthTest extends OneNoteTestCase {
	private const FULL = 'openid offline_access Notes.Read Notes.Read.All Team.ReadBasic.All';
	private const BASE = 'openid offline_access Notes.Read';

	/** @return array<string, string> */
	private static function query(string $url): array {
		parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
		return $q;
	}

	public function testAuthorizeUrlWithPkce(): void {
		$u = $this->createUser('oa');
		$http = new FakeHttp();
		$c = $this->connectService($http)->connect($u->uid, null);
		$url = $c['authorize_url'];
		self::assertStringStartsWith('https://login.microsoftonline.com/' . self::TENANT . '/oauth2/v2.0/authorize?', $url);
		$q = self::query($url);
		self::assertSame('code', $q['response_type']);
		self::assertSame('query', $q['response_mode']);
		self::assertSame('S256', $q['code_challenge_method']);
		self::assertSame(self::FULL, $q['scope'], 'Tenant-ID + Schalter an: Team-Scopes');
		self::assertSame(self::CLIENT, $q['client_id']);
		self::assertSame('select_account', $q['prompt']);
		self::assertSame(Server::get(IURLGenerator::class)->linkToRouteAbsolute('nextnotes.one_note_api.callback'), $q['redirect_uri']);
		$row = self::row($c['import_id']);
		self::assertSame('auth', $row['status']);
		self::assertSame(hash('sha256', $q['state']), $row['state_hash']);
		self::assertSame($this->now + 600, (int)$row['auth_expires_at']);
		$verifier = Server::get(TokenVault::class)->open($row['pkce_enc']);
		self::assertNotNull($verifier);
		self::assertSame(64, strlen($verifier));
		self::assertStringNotContainsString($verifier, (string)$row['pkce_enc'], 'Verifier verschlüsselt');
		self::assertSame(OAuthService::challenge($verifier), $q['code_challenge']);
		self::assertSame(1, (int)$row['team_scopes']);
		self::assertSame([], $http->calls, 'connect ruft Microsoft nicht auf');
	}

	// Done 3a: common mit gespeichertem Schalter → nur Basis-Scopes; organizations → Team-Scopes
	public function testCommonIgnoresTeamSwitch(): void {
		$u = $this->createUser('oc');
		$this->configure('common', self::CLIENT, self::SECRET, true);
		$c = $this->connectService(new FakeHttp())->connect($u->uid, null);
		self::assertSame(self::BASE, self::query($c['authorize_url'])['scope']);
		self::assertStringStartsWith('https://login.microsoftonline.com/common/', $c['authorize_url']);
		self::assertSame(0, (int)self::row($c['import_id'])['team_scopes']);
		self::assertTrue(Server::get(OneNoteConfig::class)->teamsSwitch(), 'Schalter bleibt gespeichert');
		$this->configure('organizations', self::CLIENT, self::SECRET, false);
		$this->now += 10;
		$c = $this->connectService(new FakeHttp())->connect($u->uid, null);
		self::assertSame(self::BASE, self::query($c['authorize_url'])['scope'], 'Schalter aus');
		$this->configure('organizations', self::CLIENT, self::SECRET, true);
		$this->now += 10;
		$c = $this->connectService(new FakeHttp())->connect($u->uid, null);
		self::assertSame(self::FULL, self::query($c['authorize_url'])['scope']);
	}

	// Done 3: Callback legt die Token verschlüsselt ab, ms_account, 303 (Controller) auf die Ansicht
	public function testCallbackStoresEncryptedTokens(): void {
		$u = $this->createUser('ok');
		$http = new FakeHttp($this->tokenResponder('sub-A'));
		$svc = $this->connectService($http);
		$c = $svc->connect($u->uid, null);
		$state = self::stateOf($c['authorize_url']);
		$verifier = Server::get(TokenVault::class)->open(self::row($c['import_id'])['pkce_enc']);
		$r = $svc->callback($u->uid, $state, 'the-code', '');
		self::assertSame(['import' => $c['import_id']], $r->query);
		self::assertCount(1, $http->calls);
		$call = $http->calls[0];
		self::assertSame('POST', $call['method']);
		self::assertSame('https://login.microsoftonline.com/' . self::TENANT . '/oauth2/v2.0/token', $call['url']);
		$form = $call['options']['form_params'];
		self::assertSame(['grant_type' => 'authorization_code', 'code' => 'the-code', 'redirect_uri' => self::query($c['authorize_url'])['redirect_uri'],
			'code_verifier' => $verifier, 'client_id' => self::CLIENT, 'client_secret' => self::SECRET, 'scope' => self::FULL], $form);
		self::assertArrayNotHasKey('Authorization', $call['options']['headers'], 'kein Authorization-Header an login');
		$row = self::row($c['import_id']);
		self::assertSame('select', $row['status']);
		self::assertNull($row['state_hash']);
		self::assertNull($row['pkce_enc']);
		self::assertNull($row['auth_expires_at']);
		self::assertSame(hash('sha256', self::TENANT . ':sub-A'), $row['ms_account']);
		$vault = Server::get(TokenVault::class);
		$access = $vault->open($row['access_enc']);
		self::assertStringStartsWith('mock-access-', (string)$access);
		self::assertStringStartsWith('mock-refresh-', (string)$vault->open($row['refresh_enc']));
		self::assertStringNotContainsString((string)$access, (string)$row['access_enc'], 'DB-Wert ≠ Klartext');
		self::assertSame($this->now + 3600, (int)$row['access_expires_at']);
		$this->assertNoSecretsInLogs($access);
	}

	// Done 4: ohne gültigen state 404 ohne Zustandsänderung, auch mit error=access_denied
	public function testInvalidStateChangesNothing(): void {
		$u = $this->createUser('st');
		$other = $this->createUser('sx');
		$http = new FakeHttp($this->tokenResponder());
		$svc = $this->connectService($http);
		$c = $svc->connect($u->uid, null);
		$state = self::stateOf($c['authorize_url']);
		$before = self::row($c['import_id']);
		$cases = [
			'fehlend' => [$u->uid, ''],
			'falsch' => [$u->uid, $state . 'x'],
			'fremde uid' => [$other->uid, $state],
		];
		foreach ($cases as $what => [$uid, $s]) {
			foreach (['', 'access_denied'] as $error) {
				self::assertNull($svc->callback($uid, $s, 'code', $error)->query, "$what/$error → 404");
				self::assertSame($before, self::row($c['import_id']), "$what/$error: Zeile unverändert");
			}
		}
		$this->now += 601;
		foreach (['', 'access_denied'] as $error) {
			self::assertNull($svc->callback($u->uid, $state, 'code', $error)->query, "abgelaufen/$error → 404");
			self::assertSame($before, self::row($c['import_id']), 'abgelaufen: Zeile unverändert');
		}
		$this->now -= 601;
		self::assertSame([], $http->calls, 'kein Token-Abruf');
		self::assertSame(['import' => $c['import_id']], $svc->callback($u->uid, $state, 'code', '')->query);
		$after = self::row($c['import_id']);
		foreach (['', 'access_denied'] as $error) {
			self::assertNull($svc->callback($u->uid, $state, 'code', $error)->query, "wiederverwendet/$error → 404");
			self::assertSame($after, self::row($c['import_id']), 'wiederverwendet: Zeile unverändert');
		}
		self::assertCount(1, $http->calls);
	}

	// Done 4a: gültiger state mit error= → Zeile verworfen bzw. bei Neuanmeldung nur state/Verifier weg; feste Meldung
	public function testCallbackErrorsWithValidState(): void {
		$u = $this->createUser('er');
		$http = new FakeHttp($this->tokenResponder());
		$svc = $this->connectService($http);
		foreach (['access_denied' => 'cancelled', 'consent_required' => 'consent', 'invalid_scope' => 'permissions', 'server_error' => 'failed'] as $error => $msg) {
			$this->now += 10;
			$c = $svc->connect($u->uid, null);
			$r = $svc->callback($u->uid, self::stateOf($c['authorize_url']), '', $error);
			self::assertSame(['msg' => $msg], $r->query, $error);
			self::assertNull(self::row($c['import_id']), "$error: neue Anmeldung verworfen");
		}
		// Neuanmeldung: Zeile bleibt reauth
		$this->now += 10;
		$id = $this->signIn($svc, $u->uid);
		Server::get(OneNoteImportMapper::class)->updateWhere($id, $u->uid, ['status' => 'reauth', 'access_enc' => null, 'refresh_enc' => null]);
		$c = $svc->connect($u->uid, $id);
		self::assertSame($id, $c['import_id']);
		$r = $svc->callback($u->uid, self::stateOf($c['authorize_url']), '', 'access_denied');
		self::assertSame(['msg' => 'cancelled'], $r->query);
		$row = self::row($id);
		self::assertSame('reauth', $row['status']);
		self::assertNull($row['state_hash']);
		self::assertNull($row['pkce_enc']);
		self::assertCount(1, $http->calls, 'nur der erste Callback holt Token');
		foreach ($this->logs as $l) {
			self::assertStringNotContainsString('DESCRIPTION', json_encode($l) ?: '', 'error_description nie im Log');
		}
	}

	// Done 4b: Neuanmeldung mit anderem Konto → account_mismatch ohne Token; falsches aud → keine Token
	public function testAccountMismatchAndWrongAudience(): void {
		$u = $this->createUser('am');
		$id = $this->signIn($this->connectService(new FakeHttp($this->tokenResponder('sub-A'))), $u->uid);
		Server::get(OneNoteImportMapper::class)->updateWhere($id, $u->uid, ['status' => 'reauth', 'access_enc' => null, 'refresh_enc' => null, 'access_expires_at' => null]);
		$svc = $this->connectService(new FakeHttp($this->tokenResponder('sub-B')));
		$c = $svc->connect($u->uid, $id);
		$r = $svc->callback($u->uid, self::stateOf($c['authorize_url']), 'code', '');
		self::assertSame(['import' => $id, 'msg' => 'account_mismatch'], $r->query);
		$row = self::row($id);
		self::assertSame('reauth', $row['status']);
		self::assertSame('account_mismatch', $row['error_code']);
		self::assertNull($row['access_enc']);
		self::assertNull($row['refresh_enc']);
		self::assertNull($row['state_hash']);
		// dasselbe Konto: Fortsetzen (queued)
		$svc = $this->connectService(new FakeHttp($this->tokenResponder('sub-A')));
		$c = $svc->connect($u->uid, $id);
		self::assertSame(['import' => $id], $svc->callback($u->uid, self::stateOf($c['authorize_url']), 'code', '')->query);
		$row = self::row($id);
		self::assertSame('queued', $row['status']);
		self::assertNull($row['error_code']);
		self::assertNotNull($row['access_enc']);
		self::assertSame($this->now, (int)$row['next_run_at']);

		$w = $this->createUser('aud');
		$svc = $this->connectService(new FakeHttp($this->tokenResponder('sub-A', self::CLIENT2)));
		$c = $svc->connect($w->uid, null);
		self::assertSame(['msg' => 'failed'], $svc->callback($w->uid, self::stateOf($c['authorize_url']), 'code', '')->query);
		self::assertSame([], self::rowsOf($w->uid), 'falsches aud: keine Zeile, keine Token');
		// abgelaufenes ID-Token
		$svc = $this->connectService(new FakeHttp(function () {
			return FakeHttp::json(200, ['access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 3600, 'id_token' => self::idToken(self::CLIENT, self::TENANT, 's', $this->now)]);
		}));
		$this->now += 10;
		$c = $svc->connect($w->uid, null);
		self::assertSame(['msg' => 'failed'], $svc->callback($w->uid, self::stateOf($c['authorize_url']), 'code', '')->query);
		self::assertSame([], self::rowsOf($w->uid));
	}

	// Done 5: Refresh bei expires_in=60 ersetzt beide Token; invalid_grant → reauth (select: Zeile weg); invalid_client → failed
	public function testRefresh(): void {
		$u = $this->createUser('rf');
		$imports = Server::get(OneNoteImportMapper::class);
		$vault = Server::get(TokenVault::class);
		$http = new FakeHttp($this->tokenResponder('sub-A', null, null, 60));
		$svc = $this->connectService($http);
		$id = $this->signIn($svc, $u->uid);
		$imports->updateWhere($id, $u->uid, ['status' => 'queued']);
		$old = self::row($id);
		$import = $imports->findOwn($id, $u->uid);
		$access = $svc->accessToken($import);
		$new = self::row($id);
		self::assertCount(2, $http->calls, 'Code + Refresh');
		self::assertSame('refresh_token', $http->calls[1]['options']['form_params']['grant_type']);
		self::assertSame($vault->open($old['refresh_enc']), $http->calls[1]['options']['form_params']['refresh_token']);
		self::assertSame($access, $vault->open($new['access_enc']));
		self::assertNotSame($vault->open($old['access_enc']), $vault->open($new['access_enc']), 'Zugriffstoken ersetzt');
		self::assertNotSame($vault->open($old['refresh_enc']), $vault->open($new['refresh_enc']), 'Refresh-Token ersetzt');
		// noch gültig (> 5 min): kein Refresh
		$imports->updateWhere($id, $u->uid, ['access_expires_at' => $this->now + 3600]);
		$svc->accessToken($imports->findOwn($id, $u->uid));
		self::assertCount(2, $http->calls);
		// alter Refresh-Token zählt nicht mehr (bedingter Schreibvorgang)
		$stale = $imports->findOwn($id, $u->uid);
		$stale->setRefreshEnc($old['refresh_enc']);
		try {
			$this->connectService(new FakeHttp($this->tokenResponder()))->refresh($stale);
			self::fail('stale erwartet');
		} catch (TokenException $e) {
			self::assertSame('stale', $e->reason);
		}

		$svc = $this->connectService(new FakeHttp($this->tokenResponder('sub-A', null, 'invalid_grant')));
		try {
			$svc->refresh($imports->findOwn($id, $u->uid));
			self::fail('reauth erwartet');
		} catch (TokenException $e) {
			self::assertSame('reauth', $e->reason);
		}
		$row = self::row($id);
		self::assertSame('reauth', $row['status']);
		self::assertNull($row['access_enc']);
		self::assertNull($row['refresh_enc']);

		// in select: Zeile gelöscht
		$v = $this->createUser('rs');
		$sid = $this->signIn($this->connectService(new FakeHttp($this->tokenResponder())), $v->uid);
		try {
			$svc->refresh($imports->findOwn($sid, $v->uid));
			self::fail('reauth erwartet');
		} catch (TokenException) {
		}
		self::assertNull(self::row($sid), 'select + invalid_grant → Zeile gelöscht');

		$w = $this->createUser('rc');
		$cid = $this->signIn($this->connectService(new FakeHttp($this->tokenResponder())), $w->uid);
		$imports->updateWhere($cid, $w->uid, ['status' => 'running']);
		$this->logs = [];
		try {
			$this->connectService(new FakeHttp($this->tokenResponder('x', null, 'invalid_client')))->refresh($imports->findOwn($cid, $w->uid));
			self::fail('failed erwartet');
		} catch (TokenException $e) {
			self::assertSame('failed', $e->reason);
		}
		$row = self::row($cid);
		self::assertSame('failed', $row['status']);
		self::assertSame('invalid_client', $row['error_code']);
		self::assertNull($row['access_enc']);
		self::assertContains('warning', array_column($this->logs, 'level'), 'Warnung für den Admin');
		$this->assertNoSecretsInLogs(null);
	}

	// Done 5a: Wechsel der Client-ID bzw. nur der Authority → failed/config_changed, Token NULL, Benachrichtigung, auth gelöscht; neuer Start ohne account_mismatch
	public function testConfigChange(): void {
		foreach (['client' => [self::TENANT, self::CLIENT2], 'authority' => ['organizations', self::CLIENT]] as $case => [$authority, $client]) {
			$this->configure(self::TENANT, self::CLIENT, self::SECRET, true);
			$imports = Server::get(OneNoteImportMapper::class);
			$ids = [];
			$users = [];
			foreach (['select', 'queued', 'reauth', 'running'] as $status) {
				$x = $this->createUser('cc');
				$users[$status] = $x->uid;
				$ids[$status] = $this->signIn($this->connectService(new FakeHttp($this->tokenResponder('sub-A'))), $x->uid);
				if ($status !== 'select') {
					$imports->updateWhere($ids[$status], $x->uid, ['status' => $status]);
				}
			}
			$a = $this->createUser('ca');
			$authId = $this->connectService(new FakeHttp())->connect($a->uid, null)['import_id'];

			$this->configure($authority, $client, self::SECRET, true);
			$n = $this->connectService(new FakeHttp())->reconcile(null);
			self::assertGreaterThanOrEqual(5, $n, $case);
			foreach ($ids as $status => $id) {
				$row = self::row($id);
				self::assertSame('failed', $row['status'], "$case/$status");
				self::assertSame('config_changed', $row['error_code'], "$case/$status");
				self::assertNull($row['access_enc']);
				self::assertNull($row['refresh_enc']);
				self::assertNull($row['lease_token']);
				self::assertNotNull($row['finished_at']);
				self::assertSame(1, $this->notifications($users[$status], 'onenote_failed'), "$case/$status: Benachrichtigung");
			}
			self::assertNull(self::row($authId), "$case: auth-Zeile gelöscht");
			// neuer Start mit anderem sub
			$x = $users['select'];
			$svc = $this->connectService(new FakeHttp($this->tokenResponder('sub-Z')));
			$c = $svc->connect($x, null);
			self::assertSame(['import' => $c['import_id']], $svc->callback($x, self::stateOf($c['authorize_url']), 'code', '')->query, $case);
			self::assertSame('select', self::row($c['import_id'])['status']);
		}
		// Neues Secret bei gleicher Client-ID ändert nichts
		$y = $this->createUser('cs');
		$id = $this->signIn($this->connectService(new FakeHttp($this->tokenResponder())), $y->uid);
		$this->configure('organizations', self::CLIENT, 'anderes-Secret', true);
		self::assertSame(0, $this->connectService(new FakeHttp())->reconcile(null));
		self::assertSame('select', self::row($id)['status']);
		// Team-Schalter aus: select mit team_scopes=1 → failed
		$this->configure('organizations', self::CLIENT, self::SECRET, false);
		$this->connectService(new FakeHttp())->reconcile(null);
		self::assertSame('config_changed', self::row($id)['error_code']);
	}

	// Funktion aus (Secret leer): connect 403, reconcile ruht
	public function testDisabledFeature(): void {
		$u = $this->createUser('ds');
		$id = $this->signIn($this->connectService(new FakeHttp($this->tokenResponder())), $u->uid);
		Server::get(OneNoteConfig::class)->save(null, self::CLIENT2, '', null);
		self::assertSame(0, $this->connectService(new FakeHttp())->reconcile(null), 'abgeschaltet: kein Statuswechsel');
		self::assertSame('select', self::row($id)['status']);
		try {
			$this->connectService(new FakeHttp())->connect($u->uid, null);
			self::fail('403 erwartet');
		} catch (\OCA\NextNotes\Service\Exception\ForbiddenException) {
		}
	}

	// ein offener Import je uid: queued blockiert (409), auth/select werden ersetzt, auth jünger als 5 s blockiert
	public function testOneOpenImport(): void {
		$u = $this->createUser('oo');
		$svc = $this->connectService(new FakeHttp($this->tokenResponder()));
		$first = $svc->connect($u->uid, null)['import_id'];
		try {
			$svc->connect($u->uid, null);
			self::fail('409 connect_pending erwartet');
		} catch (ConflictException $e) {
			self::assertSame('connect_pending', $e->getExtra()['code']);
		}
		$this->now += 6;
		$second = $this->signIn($svc, $u->uid);
		self::assertNull(self::row($first), 'auth ersetzt');
		$this->now += 6;
		$third = $svc->connect($u->uid, null)['import_id'];
		self::assertNull(self::row($second), 'select ersetzt');
		Server::get(OneNoteImportMapper::class)->updateWhere($third, $u->uid, ['status' => 'queued']);
		$this->now += 6;
		try {
			$svc->connect($u->uid, null);
			self::fail('409 import_open erwartet');
		} catch (ConflictException $e) {
			self::assertSame('import_open', $e->getExtra()['code']);
		}
		self::assertCount(1, self::rowsOf($u->uid));
	}

	private function notifications(string $uid, string $subject): int {
		$qb = self::db()->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from('notifications')
			->where($qb->expr()->eq('app', $qb->createNamedParameter('nextnotes')))
			->andWhere($qb->expr()->eq('user', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('subject', $qb->createNamedParameter($subject)));
		return (int)$qb->executeQuery()->fetchOne();
	}

	// Done 6 (Prozess-Logger): weder Token noch Secret noch Code noch error_description
	private function assertNoSecretsInLogs(?string $access): void {
		$all = json_encode($this->logs) ?: '';
		foreach (array_filter([$access, self::SECRET, 'mock-access-', 'mock-refresh-', 'the-code', 'DESCRIPTION', 'eyJ']) as $needle) {
			self::assertStringNotContainsString($needle, $all, "Log enthält $needle");
		}
	}
}
