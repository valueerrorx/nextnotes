<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Teams;

use OCA\NextNotes\Teams\WorkspaceResourceProvider;
use OCA\NextNotes\Tests\Support\ApiClient;
use OCA\NextNotes\Tests\Support\Teams;
use OCA\NextNotes\Tests\Support\TeamTestCase;
use OCP\Server;

// M5d2: Team-Workspaces als Ressourcen in der Teams-Ansicht (OCS /teams/…, Kern-TeamManager + WorkspaceResourceProvider)
final class TeamResourceProviderTest extends TeamTestCase {
	/** @return list<array<string, mixed>> Next-Notes-Ressourcen des Teams aus Sicht von $c */
	private static function resources(ApiClient $c, string $teamId): array {
		$r = $c->withHeaders(['OCS-APIRequest: true'])->absolute('GET', '/ocs/v2.php/teams/' . rawurlencode($teamId) . '/resources?format=json');
		self::assertSame(200, $r->status, 'OCS resources: ' . $r->describe());
		$all = $r->json()['ocs']['data']['resources'] ?? [];
		return array_values(array_filter($all, static fn (array $x) => ($x['provider']['id'] ?? '') === 'nextnotes'));
	}

	/** @return string[] Team-IDs eines Workspace aus Sicht von $c (Kern filtert auf eigene Teams) */
	private static function teamsFor(ApiClient $c, int $wsId): array {
		$r = $c->withHeaders(['OCS-APIRequest: true'])->absolute('GET', '/ocs/v2.php/teams/resources/nextnotes/' . $wsId . '?format=json');
		self::assertSame(200, $r->status, 'OCS teams for resource: ' . $r->describe());
		return array_map(static fn (array $t) => (string)$t['teamId'], $r->json()['ocs']['data']['teams'] ?? []);
	}

	public function testMembersSeeTeamWorkspacesOnly(): void {
		$a = $this->createUser('ra');
		$b = $this->createUser('rb');
		$m = $this->createUser('rm');
		$x = $this->createUser('rx');
		$teamName = 'Klasse ' . bin2hex(random_bytes(3));
		$t = $this->teams->create($a->uid, $teamName);
		$this->teams->addUser($t, $a->uid, $b->uid);
		$this->teams->addUser($t, $a->uid, $m->uid, Teams::MODERATOR);
		$t2 = $this->teams->create($x->uid, 'Andere ' . bin2hex(random_bytes(3)));
		[$key1, $id1] = self::teamWs($m, $t, 'Mathe');
		[, $id2] = self::teamWs($a, $t, 'Deutsch');
		[, $idOther] = self::teamWs($x, $t2, 'Fremd');
		[, $own] = self::newWorkspace($a, 'Privat');
		$archive = $this->rawWorkspace('team', $t, $a->uid, 'archive');

		// Mitglied (Level 1), Moderator, Owner: genau die normalen Workspaces des Teams, mit App-Icon und auflösbarem Link
		foreach ([$a, $b, $m] as $c) {
			$res = self::resources($c, $t);
			self::assertSame([(string)$id1, (string)$id2, "assignments:$t"], array_column($res, 'id'), "{$c->uid}: Workspaces des Teams und Aufgaben (M6i)");
			self::assertNotEmpty($res[0]['iconSvg'] ?? null, 'Icon am Workspace');
			self::assertStringContainsString('currentColor', (string)$res[0]['provider']['icon'], 'Icon in Textfarbe');
			parse_str((string)parse_url((string)$res[0]['url'], PHP_URL_QUERY), $q);
			self::assertSame("$key1 ($teamName, #$id1)", $q['workspace'] ?? null, 'Link mit Langform-Schlüssel');
			// M6i: „Aufgaben“ mit Doktorhut, Link auf assignments.php im ersten Team-Workspace
			self::assertMatchesRegularExpression('/^(Aufgaben|Assignments)$/', (string)($res[2]['label'] ?? ''), 'Ressource Aufgaben');
			self::assertStringContainsString('currentColor', (string)($res[2]['iconSvg'] ?? ''), 'Doktorhut in Textfarbe');
			self::assertStringContainsString('/apps/nextnotes/assignments.php?', (string)$res[2]['url']);
			parse_str((string)parse_url((string)$res[2]['url'], PHP_URL_QUERY), $q);
			self::assertSame("$key1 ($teamName, #$id1)", $q['workspace'] ?? null, 'Aufgaben im ersten Team-Workspace');
		}
		$page = $b->absolute('GET', '/index.php/apps/nextnotes/assignments.php?workspace=' . rawurlencode("$key1 ($teamName, #$id1)"));
		self::assertSame(200, $page->status, 'Aufgaben-Link öffnet für Mitglied');
		self::assertStringContainsString('"team":"' . $t . '"', $page->body, 'Aufgaben-Seite im Team-Bereich');
		$page = $b->absolute('GET', '/index.php/apps/nextnotes/index.php?workspace=' . rawurlencode("$key1 ($teamName, #$id1)"));
		self::assertSame(200, $page->status, 'Link öffnet für Mitglied');
		self::assertStringContainsString('"selectedWorkspace":' . json_encode($key1), $page->body, 'Link wählt den Workspace');

		// Nicht-Mitglied: nichts (Kern prüft Mitgliedschaft); anderes Team liefert nur seine eigenen
		self::assertSame([], self::resources($x, $t), 'Nicht-Mitglied sieht nichts');
		self::assertSame([(string)$idOther, "assignments:$t2"], array_column(self::resources($x, $t2), 'id'), 'anderes Team');
		// Team ohne Team-Workspace: weder Workspaces noch Aufgaben (O6)
		$t3 = $this->teams->create($x->uid, 'Leer ' . bin2hex(random_bytes(3)));
		self::assertSame([], self::resources($x, $t3), 'ohne Team-Workspace keine Ressource');
		self::assertSame([], self::resources($a, $t2), 'A ist nicht im anderen Team');

		// getTeamsForResource: Team-Workspace → Team (nur für Mitglieder), privat/archiv → keins
		self::assertSame([$t], self::teamsFor($b, $id1));
		self::assertSame([], self::teamsFor($x, $id1));
		self::assertSame([], self::teamsFor($a, $own));

		$p = Server::get(WorkspaceResourceProvider::class);
		self::assertSame([$t], $p->getTeamsForResource((string)$id1));
		self::assertSame([], $p->getTeamsForResource((string)$own));
		self::assertSame([], $p->getTeamsForResource((string)$archive));
		self::assertSame([], $p->getTeamsForResource('abc'));
		self::assertTrue($p->isSharedWithTeam($t, (string)$id1));
		self::assertFalse($p->isSharedWithTeam($t2, (string)$id1));
		self::assertFalse($p->isSharedWithTeam($t, (string)$own));
		self::assertSame([$t], $p->getTeamsForResource("assignments:$t"));
		self::assertSame([], $p->getTeamsForResource("assignments:$t3"), 'Aufgaben ohne Team-Workspace');
		self::assertSame([], $p->getTeamsForResource('assignments:'));
		self::assertTrue($p->isSharedWithTeam($t, "assignments:$t"));
		self::assertFalse($p->isSharedWithTeam($t2, "assignments:$t"));

		// verwaistes Team: keine Ressourcen mehr
		self::setTeamState($t, 'orphaned');
		self::assertSame([], self::resources($a, $t), 'verwaist: keine Ressourcen');
		self::setTeamState($t, 'active');
	}
}
