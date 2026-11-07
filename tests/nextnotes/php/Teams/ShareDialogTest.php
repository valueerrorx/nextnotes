<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Teams;

use OCA\NextNotes\Tests\Support\ApiClient;
use OCA\NextNotes\Tests\Support\ApiTestCase;
use OCA\NextNotes\Tests\Support\Response;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Server;

// Plan M5 §2.7 / M5d Done 0: NC-Freigaberegeln für share_workspace und GET /users/search (ShareTargetPolicy)
final class ShareDialogTest extends ApiTestCase {
	// Standardwerte der Kern-Einstellungen, nach jedem Test zurückgesetzt
	private const DEFAULTS = [
		'shareapi_enabled' => 'yes',
		'shareapi_only_share_with_group_members' => 'no',
		'shareapi_only_share_with_group_members_exclude_group_list' => '[]',
		'shareapi_exclude_groups' => 'no',
		'shareapi_exclude_groups_list' => '[]',
		'shareapi_allow_share_dialog_user_enumeration' => 'yes',
		'shareapi_restrict_user_enumeration_full_match' => 'yes',
	];

	/** @var string[] */
	private array $groups = [];
	private bool $configChanged = false;

	protected function tearDown(): void {
		if ($this->configChanged) {
			foreach (self::DEFAULTS as $k => $v) {
				self::occ('config:app:set', 'core', $k, '--value=' . $v);
			}
			self::waitForConfig();
		}
		foreach ($this->groups as $gid) {
			Server::get(IGroupManager::class)->get($gid)?->delete();
		}
		parent::tearDown();
	}

	private static function occ(string ...$args): void {
		exec('php /var/www/html/occ ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $out, $rc);
		self::assertSame(0, $rc, 'occ ' . implode(' ', $args) . ': ' . implode("\n", $out));
	}

	// AppConfig des Webservers hält Werte 3 s im lokalen Cache
	private static function waitForConfig(): void {
		sleep(4);
	}

	/** @param array<string, string> $values */
	private function config(array $values): void {
		$this->configChanged = true;
		foreach ($values as $k => $v) {
			self::occ('config:app:set', 'core', $k, '--value=' . $v);
		}
		self::waitForConfig();
	}

	private function group(string ...$uids): string {
		$gid = 'nn-g-' . bin2hex(random_bytes(4));
		$group = Server::get(IGroupManager::class)->createGroup($gid);
		self::assertNotNull($group);
		foreach ($uids as $uid) {
			$group->addUser(Server::get(IUserManager::class)->get($uid));
		}
		$this->groups[] = $gid;
		return $gid;
	}

	/** @param list<array{uid: string, permission: string}> $users */
	private static function share(ApiClient $c, string $ws, array $users): Response {
		return $c->form('/index.php/apps/nextnotes/workspaces.php', ['action' => 'share_workspace', 'name' => $ws, 'allowed_users' => json_encode($users)]);
	}

	/** @return array<string, string> uid → permission */
	private static function rows(int $wsId): array {
		$qb = self::db()->getQueryBuilder();
		$qb->select('share_with', 'permission')->from('nextnotes_ws_shares')
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($wsId, IQueryBuilder::PARAM_INT)));
		$out = [];
		foreach ($qb->executeQuery()->fetchAll() as $r) {
			$out[$r['share_with']] = $r['permission'];
		}
		return $out;
	}

	private static function assertInvalid(Response $r, string $what): void {
		self::assertSame(400, $r->status, "$what: " . $r->describe());
		self::assertSame('Invalid share target', $r->json()['error'] ?? null, "$what: einheitlicher Text");
	}

	/** @return string[] UIDs der Suchtreffer */
	private static function search(ApiClient $c, string $q): array {
		$j = self::ok($c->call('GET', '/users/search' . self::q(['q' => $q])), "Suche $q");
		foreach ($j['users'] as $u) {
			self::assertSame(['uid', 'displayName'], array_keys($u), 'nur uid und Anzeigename');
		}
		return array_column($j['users'], 'uid');
	}

	public function testPolicyForNewTargetsUniformAnswer(): void {
		$a = $this->createUser('sa', 'Sharer Anna');
		$c = $this->createUser('sc', 'Carol Zielperson');
		$d = $this->createUser('sd', 'Dora Fremd');
		Server::get(IUserManager::class)->get($c->uid)->setSystemEMailAddress('carol-m5d@example.org');
		[$ws, $wsId] = self::newWorkspace($a, 'Share');

		// erlaubtes Ziel → 200, Antwort mit Empfängerliste ohne E-Mail
		$r = self::share($a, $ws, [['uid' => $c->uid, 'permission' => 'write']]);
		$j = self::ok($r, 'Freigabe an C');
		self::assertTrue($j['shared']);
		self::assertSame([['uid' => $c->uid, 'displayName' => 'Carol Zielperson', 'permission' => 'write']], $j['allowed_users']);
		self::assertSame([$c->uid => 'write'], self::rows($wsId));
		self::assertStringNotContainsString('carol-m5d@example.org', $r->body);

		// Rechtewechsel per Update
		self::ok(self::share($a, $ws, [['uid' => $c->uid, 'permission' => 'read']]), 'C nur lesen');
		self::assertSame([$c->uid => 'read'], self::rows($wsId));

		// (a) unbekannte UID, sich selbst
		foreach (['nn-unknown-' . bin2hex(random_bytes(3)), $a->uid] as $bad) {
			self::assertInvalid(self::share($a, $ws, [['uid' => $c->uid, 'permission' => 'read'], ['uid' => $bad, 'permission' => 'write']]), "Ziel $bad");
			self::assertSame([$c->uid => 'read'], self::rows($wsId), 'alles oder nichts');
		}
		// deaktiviertes Konto
		Server::get(IUserManager::class)->get($d->uid)->setEnabled(false);
		self::assertInvalid(self::share($a, $ws, [['uid' => $d->uid, 'permission' => 'write']]), 'deaktiviert');
		Server::get(IUserManager::class)->get($d->uid)->setEnabled(true);

		// Suche: Treffer, ohne E-Mail, ohne sich selbst; zu kurz → leer
		self::assertContains($c->uid, self::search($a, 'Carol Ziel'));
		self::assertNotContains($a->uid, self::search($a, 'Sharer Anna'));
		self::assertSame([], self::search($a, 'C'));
		self::assertStringNotContainsString('carol-m5d@example.org', $a->call('GET', '/users/search?q=carol')->body);
		self::assertSame(400, $a->call('GET', '/users/search' . self::q(['q' => str_repeat('x', 101)]))->status);

		// (b) nur Gruppenmitglieder: A und C in einer Gruppe, D außerhalb
		$this->group($a->uid, $c->uid);
		$this->config(['shareapi_only_share_with_group_members' => 'yes']);
		self::assertInvalid(self::share($a, $ws, [['uid' => $c->uid, 'permission' => 'read'], ['uid' => $d->uid, 'permission' => 'write']]), '(b) außerhalb der Gruppe');
		self::assertSame([], self::search($a, 'Dora Fremd'), '(b) Suche');
		self::assertContains($c->uid, self::search($a, 'Carol Ziel'), '(b) Gruppe sichtbar');

		// bestehender Empfänger D nach (b): Rechteänderung und Entzug gehen
		$this->config(['shareapi_only_share_with_group_members' => 'no']);
		self::ok(self::share($a, $ws, [['uid' => $c->uid, 'permission' => 'read'], ['uid' => $d->uid, 'permission' => 'write']]), 'D vor (b)');
		$this->config(['shareapi_only_share_with_group_members' => 'yes']);
		self::ok(self::share($a, $ws, [['uid' => $c->uid, 'permission' => 'read'], ['uid' => $d->uid, 'permission' => 'read']]), '(b) Rechteänderung D');
		self::assertSame([$c->uid => 'read', $d->uid => 'read'], self::rows($wsId));
		self::ok(self::share($a, $ws, [['uid' => $c->uid, 'permission' => 'read']]), '(b) Entzug D');
		self::assertSame([$c->uid => 'read'], self::rows($wsId));
		self::ok($a->form('/index.php/apps/nextnotes/workspaces.php', ['action' => 'unshare_workspace', 'name' => $ws]), '(b) alle entziehen');
		self::assertSame([], self::rows($wsId));
		$this->config(['shareapi_only_share_with_group_members' => 'no']);

		// (c) Akteur in shareapi_exclude_groups
		$gx = $this->group($a->uid);
		$this->config(['shareapi_exclude_groups' => 'yes', 'shareapi_exclude_groups_list' => json_encode([$gx])]);
		self::assertInvalid(self::share($a, $ws, [['uid' => $c->uid, 'permission' => 'write']]), '(c) ausgeschlossen');
		self::assertSame([], self::search($a, 'Carol Ziel'), '(c) Suche');
		self::assertSame([], self::rows($wsId));
		$this->config(['shareapi_exclude_groups' => 'no', 'shareapi_exclude_groups_list' => '[]']);

		// (d) Freigabe-API aus
		$this->config(['shareapi_enabled' => 'no']);
		self::assertInvalid(self::share($a, $ws, [['uid' => $c->uid, 'permission' => 'write']]), '(d) API aus');
		self::assertSame([], self::search($a, 'Carol Ziel'), '(d) Suche');
		$this->config(['shareapi_enabled' => 'yes']);

		// (e) Aufzählung aus: unvollständiger Name ohne Treffer, volle UID weiter teilbar
		$this->config(['shareapi_allow_share_dialog_user_enumeration' => 'no']);
		self::assertNotContains($c->uid, self::search($a, 'Carol Ziel'), '(e) Teilname');
		self::ok(self::share($a, $ws, [['uid' => $c->uid, 'permission' => 'write']]), '(e) volle UID');
	}

	public function testTeamWorkspaceAndForeignCannotShare(): void {
		$a = $this->createUser('sx');
		$b = $this->createUser('sy');
		[$ws] = self::newWorkspace($a, 'Mine');
		// fremder Workspace: unbekannter Schlüssel für B
		self::assertSame(404, self::share($b, $ws, [['uid' => $a->uid, 'permission' => 'write']])->status);
		// kaputte Liste
		foreach (['{"x":1}', '[{"uid":""}]', '[{"uid":"x","permission":"admin"}]', json_encode(array_fill(0, 201, 'u'))] as $bad) {
			$r = $a->form('/index.php/apps/nextnotes/workspaces.php', ['action' => 'share_workspace', 'name' => $ws, 'allowed_users' => $bad]);
			self::assertSame(400, $r->status, "Liste $bad: " . $r->describe());
		}
	}

	public function testSearchRateLimit(): void {
		$a = $this->createUser('sr');
		$codes = [];
		for ($i = 0; $i < 31; $i++) {
			$codes[] = $a->call('GET', '/users/search?q=zz' . $i)->status;
		}
		self::assertSame(array_fill(0, 30, 200), array_slice($codes, 0, 30));
		self::assertSame(429, $codes[30], '31. Suche in 60 s');
	}
}
