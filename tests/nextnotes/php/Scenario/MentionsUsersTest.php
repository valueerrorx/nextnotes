<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Scenario;

use OCA\NextNotes\Service\WorkspaceService;
use OCA\NextNotes\Tests\Support\ApiClient;
use OCA\NextNotes\Tests\Support\ApiTestCase;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Server;

// SPEC §3 (Mentions über AccessService), §4.2 (/mentions/members, /users/lookup), Plan M2c (MentionsApiController, UsersApiController)
final class MentionsUsersTest extends ApiTestCase {
	private static function occ(string ...$args): int {
		exec('php /var/www/html/occ ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $out, $rc);
		return $rc;
	}

	/** Schlüssel, unter dem $c den Workspace mit Namen $name sieht */
	private static function keyFor(ApiClient $c, string $name): ?string {
		foreach (self::ok($c->call('GET', '/workspaces'), 'workspaces')['workspaces'] as $w) {
			if ($w['name'] === $name || str_starts_with($w['name'], $name . ' (')) {
				return $w['name'];
			}
		}
		return null;
	}

	public function testMentionsRequireReadRight(): void {
		$a = $this->createUser('ma');
		$b = $this->createUser('mb');
		$c = $this->createUser('mc');
		$mark = 'mmk' . bin2hex(random_bytes(4));
		[$ws, $wsId] = self::newWorkspace($a, 'Team');
		Server::get(WorkspaceService::class)->share($a->uid, $wsId, $c->uid);
		$n = self::newNote($a, $ws, "Titel $mark", "<p>Hallo @{$b->uid} $mark</p>");

		// B ohne Leserecht: nichts
		$r = self::ok($b->call('GET', '/mentions'), 'B mentions');
		self::assertSame(0, $r['open']);
		self::assertSame([], $r['mentions']);
		self::assertStringNotContainsString($mark, $b->call('GET', '/mentions?include_handled=1')->body);
		foreach (['POST', 'DELETE'] as $m) {
			$x = $b->call($m, "/mentions/{$a->uid}/$n/handled");
			self::assertSame(404, $x->status, "$m handled: " . $x->describe());
			self::assertStringNotContainsString($mark, $x->body);
		}
		foreach ([$ws, "$ws ({$a->uid})"] as $key) {
			$x = $b->call('GET', '/mentions/members' . self::q(['workspace' => $key]));
			self::assertContains($x->status, [403, 404], "members $key: " . $x->describe());
			self::assertStringNotContainsString($c->uid, $x->body, 'Mitglieder fremder Workspaces sichtbar');
		}

		// C (Leserecht, nicht erwähnt): keine Erwähnung von B in seiner Liste
		self::assertSame([], self::ok($c->call('GET', '/mentions'), 'C')['mentions']);

		// Freigabe an B: Erwähnung sichtbar, Mitglieder = Besitzer + Empfänger
		Server::get(WorkspaceService::class)->share($a->uid, $wsId, $b->uid, 'read');
		$r = self::ok($b->call('GET', '/mentions'), 'B mentions geteilt');
		self::assertSame(1, $r['open']);
		self::assertSame([$n], array_column($r['mentions'], 'note_id'));
		self::assertSame("Titel $mark", $r['mentions'][0]['title']);
		$key = self::keyFor($b, $ws);
		self::assertNotNull($key);
		$members = self::ok($b->call('GET', '/mentions/members' . self::q(['workspace' => $key])), 'members')['members'];
		self::assertSame($a->uid, $members[0] ?? null, 'Besitzer zuerst');
		$sorted = $members;
		sort($sorted);
		$want = [$a->uid, $b->uid, $c->uid];
		sort($want);
		self::assertSame($want, $sorted);
		self::ok($b->call('POST', "/mentions/{$a->uid}/$n/handled"), 'handled (nur Leserecht)');
		self::assertSame(0, self::ok($b->call('GET', '/mentions'), 'nach handled')['open']);

		// Entzug: wieder nichts
		Server::get(WorkspaceService::class)->unshare($a->uid, $wsId, $b->uid);
		$r = self::ok($b->call('GET', '/mentions?include_handled=1'), 'nach Entzug');
		self::assertSame([], $r['mentions']);
		self::assertStringNotContainsString($mark, $b->call('GET', '/mentions?include_handled=1')->body);
		self::assertContains($b->call('GET', '/mentions/members' . self::q(['workspace' => $key]))->status, [403, 404]);
	}

	public function testMembersOwnWorkspace(): void {
		$a = $this->createUser('oa');
		$b = $this->createUser('ob');
		$other = $this->createUser('ox');
		[$ws, $wsId] = self::newWorkspace($a, 'Allein');
		$r = self::ok($a->call('GET', '/mentions/members' . self::q(['workspace' => $ws])), 'ohne Freigabe');
		self::assertSame([], $r['members'], 'ohne Freigabe: keine Mitglieder');
		Server::get(WorkspaceService::class)->share($a->uid, $wsId, $b->uid);
		$m = self::ok($a->call('GET', '/mentions/members' . self::q(['workspace' => $ws])), 'mit Freigabe')['members'];
		self::assertSame([$a->uid, $b->uid], $m);
		self::assertNotContains($other->uid, $m);
		self::assertSame(400, $a->call('GET', '/mentions/members')->status, 'workspace fehlt');
		self::assertSame(404, $a->call('GET', '/mentions/members?workspace=gibtsnicht' . bin2hex(random_bytes(3)))->status);
	}

	public function testUsersMe(): void {
		$a = $this->createUser('me', 'Anzeige Name');
		$r = self::ok($a->call('GET', '/users/me'), 'me');
		self::assertSame($a->uid, $r['username']);
		self::assertSame($a->uid, $r['id']);
		self::assertSame('Anzeige Name', $r['display_name']);
		self::assertFalse($r['is_admin']);
	}

	// NC-Sichtbarkeit: „nur mit Gruppenmitgliedern teilen“ blendet fremde Konten aus
	public function testLookupRespectsSharingVisibility(): void {
		$a = $this->createUser('la');
		$b = $this->createUser('lb');
		$r = self::ok($a->call('GET', '/users/lookup/' . self::enc($b->uid)), 'lookup Kontrolle');
		self::assertSame(['id' => $b->uid, 'username' => $b->uid], array_intersect_key($r, ['id' => 1, 'username' => 1]));
		self::assertArrayNotHasKey('email', $r, 'lookup gibt E-Mail preis');
		self::assertSame(404, $a->call('GET', '/users/lookup/nn-gibtsnicht-' . bin2hex(random_bytes(4)))->status);

		$gid = 'nn-g-' . bin2hex(random_bytes(3));
		$gm = Server::get(IGroupManager::class);
		try {
			self::assertSame(0, self::occ('config:app:set', 'core', 'shareapi_only_share_with_group_members', '--value=yes'));
			sleep(4);
			$x = $a->call('GET', '/users/lookup/' . self::enc($b->uid));
			self::assertSame(404, $x->status, 'ohne gemeinsame Gruppe sichtbar: ' . $x->describe());
			self::assertStringNotContainsString($b->uid, $x->body);
			$g = $gm->createGroup($gid);
			$um = Server::get(IUserManager::class);
			$g->addUser($um->get($a->uid));
			$g->addUser($um->get($b->uid));
			$r = self::ok($a->call('GET', '/users/lookup/' . self::enc($b->uid)), 'gemeinsame Gruppe');
			self::assertSame($b->uid, $r['username']);
		} finally {
			self::occ('config:app:delete', 'core', 'shareapi_only_share_with_group_members');
			$gm->get($gid)?->delete();
			sleep(4);
		}
	}
}
