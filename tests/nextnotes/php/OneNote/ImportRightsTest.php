<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\OneNote;

use OCA\NextNotes\OneNote\OneNoteConfig;
use OCA\NextNotes\Tests\Support\ApiClient;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Server;

// M9a über HTTP: Rechte (fremde ID → 404), Callback ohne gültigen state → 404 ohne Änderung, Fehler mit gültigem state → 303,
// Admin-Formular (Done 2), zwei gleichzeitige connect (Done 14)
final class ImportRightsTest extends OneNoteTestCase {
	private const APP = '/index.php/apps/nextnotes/';

	protected function setUp(): void {
		parent::setUp();
		// AppConfig-Lokalcache des Webservers (APCu, 3 s) abwarten, siehe AdminSettingsTest::settle
		sleep(4);
	}

	private static function callbackGet(ApiClient $c, array $q): \OCA\NextNotes\Tests\Support\Response {
		return $c->withHeaders(['Accept: text/html'])->absolute('GET', self::APP . 'onenote/callback?' . http_build_query($q));
	}

	public function testRightsAndCallback(): void {
		$a = $this->createUser('ra');
		$b = $this->createUser('rb');
		$r = $a->call('POST', '/onenote/connect');
		self::assertSame(200, $r->status, $r->describe());
		$id = (int)$r->json()['import_id'];
		$state = self::stateOf((string)$r->json()['authorize_url']);
		$before = self::row($id);

		foreach ([['GET', "/onenote/imports/$id", null], ['POST', "/onenote/imports/$id/cancel", null], ['POST', '/onenote/connect', ['import_id' => $id]]] as [$m, $p, $body]) {
			self::assertSame(404, $b->call($m, $p, $body)->status, "fremd: $m $p");
		}
		self::assertSame([], $b->call('GET', '/onenote/imports')->json()['imports']);
		$show = $a->call('GET', "/onenote/imports/$id");
		self::assertSame(200, $show->status);
		self::assertSame('auth', $show->json()['import']['status']);
		foreach (['state_hash', 'pkce_enc', 'access_enc', 'ms_account', 'redirect_uri'] as $k) {
			self::assertStringNotContainsString($k, $show->body);
		}
		self::assertSame(400, $a->call('POST', '/onenote/connect', ['import_id' => 'x'])->status);

		foreach ([[], ['error' => 'access_denied'], ['state' => 'falsch', 'error' => 'access_denied', 'error_description' => 'AADSTS-X']] as $q) {
			self::assertSame(404, self::callbackGet($a, $q)->status, json_encode($q) ?: '');
		}
		self::assertSame(404, self::callbackGet($b, ['state' => $state, 'error' => 'access_denied'])->status, 'fremde uid');
		self::assertSame($before, self::row($id), 'keine Zustandsänderung');

		$r = self::callbackGet($a, ['state' => $state, 'error' => 'access_denied', 'error_description' => 'AADSTS-GEHEIM']);
		self::assertSame(303, $r->status, $r->describe());
		self::assertStringEndsWith('/onenote.php?msg=cancelled', (string)$r->header('location'));
		self::assertStringNotContainsString('AADSTS', $r->body . json_encode($r->headers));
		self::assertNull(self::row($id), 'Zeile verworfen');
		$page = $a->withHeaders(['Accept: text/html'])->absolute('GET', self::APP . 'onenote.php?msg=cancelled');
		self::assertSame(200, $page->status);
		self::assertStringContainsString('id="nextnotes-onenote-message"', $page->body);
		self::assertStringContainsString('data-action="connect"', $page->body);
	}

	// Done 14: zwei gleichzeitige connect desselben Benutzers → genau eine offene Zeile, die andere Antwort 409
	public function testParallelConnect(): void {
		for ($round = 0; $round < 3; $round++) {
			$u = $this->createUser('pc');
			$res = $u->parallel([['POST', '/onenote/connect', []], ['POST', '/onenote/connect', []]]);
			$codes = [$res[0]->status, $res[1]->status];
			sort($codes);
			self::assertSame([200, 409], $codes, $res[0]->describe() . ' | ' . $res[1]->describe());
			$rows = self::rowsOf($u->uid);
			self::assertCount(1, $rows, 'genau eine Zeile');
			$winner = $res[0]->status === 200 ? $res[0] : $res[1];
			self::assertSame((int)$rows[0]['id'], (int)$winner->json()['import_id']);
		}
	}

	// Done 2: Admin speichert Authority, Client-ID, Secret; Secret in keiner Antwort und keinem HTML; ungültig → 400; Redirect-URI sichtbar
	public function testAdminSettings(): void {
		$admin = $this->createUser('adm');
		Server::get(IGroupManager::class)->get('admin')->addUser(Server::get(IUserManager::class)->get($admin->uid));
		$secret = 'Admin-Secret-' . bin2hex(random_bytes(6));
		$save = static fn (array $body) => $admin->absolute('POST', self::APP . 'admin/settings', $body);
		$r = $save(['onenote_authority' => ' ' . strtoupper(self::TENANT) . ' ', 'onenote_client_id' => self::CLIENT2, 'onenote_secret' => $secret, 'onenote_teams' => true]);
		self::assertSame(200, $r->status, $r->describe());
		self::assertStringNotContainsString($secret, $r->body);
		self::assertSame(self::TENANT, $r->json()['onenote_authority']);
		self::assertTrue($r->json()['onenote_secret_set']);
		self::assertSame($secret, self::storedSecret(), 'Secret gespeichert');
		self::assertNotSame($secret, self::rawConfig(OneNoteConfig::KEY_SECRET), 'Secret in der DB verschlüsselt');

		foreach ([['onenote_authority' => 'consumers'], ['onenote_authority' => 'https://evil.example'], ['onenote_authority' => 'localhost'],
			['onenote_client_id' => 'keine-guid'], ['onenote_client_id' => self::CLIENT . 'x']] as $bad) {
			$r = $save($bad);
			self::assertSame(400, $r->status, json_encode($bad) ?: '');
			self::assertStringNotContainsString($secret, $r->body);
		}
		self::assertSame(self::CLIENT2, self::rawConfig(OneNoteConfig::KEY_CLIENT_ID), '400 speichert nichts');
		foreach (['organizations', 'schule.onmicrosoft.com', 'common'] as $ok) {
			self::assertSame(200, $save(['onenote_authority' => $ok])->status, $ok);
		}

		sleep(4);
		$page = $admin->withHeaders(['Accept: text/html'])->absolute('GET', '/index.php/settings/admin/nextnotes');
		self::assertSame(200, $page->status);
		self::assertStringNotContainsString($secret, $page->body, 'Secret nie im HTML');
		self::assertStringContainsString('/apps/nextnotes/onenote/callback', $page->body, 'Redirect-URI angezeigt');
		self::assertMatchesRegularExpression('/id="nextnotes-onenote-teams"[^>]*disabled/', $page->body, 'common: Schalter deaktiviert');
		self::assertStringContainsString('id="nextnotes-onenote-teams-hint" class="settings-hint">', $page->body, 'Hinweis sichtbar');

		self::assertSame(200, $save(['onenote_secret_clear' => true])->status);
		self::assertSame('', self::rawConfig(OneNoteConfig::KEY_SECRET) ?? '', 'Secret entfernt');
		self::assertSame(403, $this->createUser('nu')->absolute('POST', self::APP . 'admin/settings', ['onenote_secret' => 'x'])->status, 'nur Admins');
	}

	private static function rawConfig(string $key): ?string {
		$qb = self::db()->getQueryBuilder();
		$qb->select('configvalue')->from('appconfig')
			->where($qb->expr()->eq('appid', $qb->createNamedParameter('nextnotes')))
			->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter($key)));
		$v = $qb->executeQuery()->fetchOne();
		return $v === false ? null : (string)$v;
	}

	private static function storedSecret(): string {
		$cfg = Server::get(IAppConfig::class);
		$cfg->clearCache(true);
		return $cfg->getValueString('nextnotes', OneNoteConfig::KEY_SECRET, '', true);
	}
}
