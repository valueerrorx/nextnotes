<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Teams;

use OCA\NextNotes\Notification\Notifier;
use OCA\NextNotes\Service\WorkspaceService;
use OCA\NextNotes\Tests\Support\ApiClient;
use OCA\NextNotes\Tests\Support\Response;
use OCA\NextNotes\Tests\Support\Teams;
use OCA\NextNotes\Tests\Support\TeamTestCase;
use OCP\Notification\AlreadyProcessedException;
use OCP\Notification\IManager as INotificationManager;
use OCP\Server;

// Plan M5d Done 2–5: Team-Kennzeichen (API, Einstellungen), Banner verwaist, Erwähnungen in Team-Workspaces, R16 Notizfarben
final class TeamUiTest extends TeamTestCase {
	private const APP = '/index.php/apps/nextnotes/';

	private static function page(ApiClient $c, string $path): Response {
		$r = $c->absolute('GET', self::APP . $path);
		self::assertSame(200, $r->status, "$path: " . $r->describe());
		return $r;
	}

	// <li class="ws-row …" data-ws="KEY"> … </li> der Einstellungen
	private static function settingsRow(string $html, string $key): string {
		$attr = htmlspecialchars($key, ENT_QUOTES);
		self::assertSame(1, preg_match('/<li class="ws-row[^"]*" data-ws="' . preg_quote($attr, '/') . '">(.*?)<\/li>/s', $html, $m), "Zeile $key fehlt");
		return $m[1];
	}

	private static function mentionNotifications(int $noteId, string $uid): int {
		$qb = self::db()->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from('notifications')
			->where($qb->expr()->eq('app', $qb->createNamedParameter('nextnotes')))
			->andWhere($qb->expr()->eq('subject', $qb->createNamedParameter('mention')))
			->andWhere($qb->expr()->eq('object_id', $qb->createNamedParameter((string)$noteId)))
			->andWhere($qb->expr()->eq('user', $qb->createNamedParameter($uid)));
		return (int)$qb->executeQuery()->fetchOne();
	}

	/** @return array<string, mixed> Eintrag aus GET /workspaces */
	private static function wsEntry(ApiClient $c, string $key): array {
		foreach (self::ok($c->call('GET', '/workspaces'), 'GET /workspaces')['workspaces'] as $w) {
			if ($w['name'] === $key) {
				return $w;
			}
		}
		self::fail("$key fehlt für {$c->uid}");
	}

	// Done 2: Team-Workspace mit Teamname bei B; B ohne Verwalten-Einträge, Moderator mit; Teilen nur eigene
	public function testTeamMarkersAndSettings(): void {
		$a = $this->createUser('ua');
		$b = $this->createUser('ub');
		$m = $this->createUser('um');
		$teamName = 'Klasse ' . bin2hex(random_bytes(3));
		$t = $this->teams->create($a->uid, $teamName);
		$this->teams->addUser($t, $a->uid, $b->uid);
		$this->teams->addUser($t, $a->uid, $m->uid, Teams::MODERATOR);
		[$key] = self::teamWs($m, $t, 'Mathe');
		[$key2] = self::teamWs($m, $t, 'Deutsch');
		[$own] = self::newWorkspace($b, 'Eigen');

		self::assertSame($teamName, self::wsEntry($b, $key)['team']);
		self::assertNull(self::wsEntry($b, $own)['team']);

		$html = self::page($b, 'settings.php')->body;
		$row = self::settingsRow($html, $key);
		self::assertStringContainsString('nn-team-badge', $row);
		self::assertStringContainsString(htmlspecialchars($teamName), $row);
		foreach (['workspace-rename-action', 'workspace-color-action', 'btn-delete', 'btn-move', 'btn-share-toggle'] as $cls) {
			self::assertStringNotContainsString($cls, $row, "B: $cls am Team-Workspace");
		}
		self::assertStringContainsString('workspace-info-action', $row);
		self::assertStringContainsString('btn-share-toggle', self::settingsRow($html, $own), 'Teilen am eigenen Workspace');
		self::assertStringNotContainsString('nn-team-badge', self::settingsRow($html, $own));

		$rowM = self::settingsRow(self::page($m, 'settings.php')->body, $key);
		foreach (['workspace-rename-action', 'btn-delete'] as $cls) {
			self::assertStringContainsString($cls, $rowM, "Moderator: $cls");
		}
		self::assertStringNotContainsString('btn-share-toggle', $rowM, 'Team-Workspace ohne Teilen');
		self::assertStringContainsString('data-team-targets="' . htmlspecialchars(json_encode([$key2], JSON_UNESCAPED_UNICODE)) . '"', $rowM, 'Löschziel desselben Teams');
		// ohne Contacts-App kein Link „Teams verwalten“
		self::assertStringNotContainsString('class="nn-manage-teams"', self::page($m, 'settings.php')->body);
	}

	// Done 3 (verwaist): Banner mit Umwandeln nur für created_by; aktiv ohne Banner
	public function testOrphanedBanner(): void {
		$a = $this->createUser('ba');
		$t = $this->teams->create($a->uid, 'Banner ' . bin2hex(random_bytes(3)));
		[$key] = self::teamWs($a, $t);
		self::newNote($a, $key, 'Bleibt', '<p>x</p>');
		self::assertStringNotContainsString('nn-team-banner', self::page($a, 'index.php?workspace=' . self::enc($key))->body);
		$this->teams->destroy($a->uid, $t, true);
		self::setTeamState($t, 'orphaned');
		$html = self::page($a, 'index.php?workspace=' . self::enc($key))->body;
		self::assertStringContainsString('nn-team-banner-orphaned', $html);
		self::assertStringContainsString('nn-team-convert', $html);
		self::assertStringContainsString('api_export_structured.php?workspace=' . htmlspecialchars(urlencode($key)), $html);
		self::assertStringNotContainsString('id="sidebarCreateBtn"', $html, 'nur lesbar: kein Erstellen');
		$name = self::ok($a->call('POST', '/workspaces/' . self::enc($key) . '/make-private'), 'Umwandeln')['name'];
		self::assertStringNotContainsString('nn-team-banner', self::page($a, 'index.php?workspace=' . self::enc($name))->body);
	}

	// Done 4: Erwähnung im Team-Workspace → Mitglied benachrichtigt, Nicht-Mitglied nicht; Mitgliederliste erst nach read; Austritt → AlreadyProcessed
	public function testTeamMentions(): void {
		$a = $this->createUser('na');
		$b = $this->createUser('nb');
		$e = $this->createUser('ne');
		$t = $this->teams->create($a->uid, 'Erwähnen ' . bin2hex(random_bytes(3)));
		$memberB = $this->teams->addUser($t, $a->uid, $b->uid);
		[$key] = self::teamWs($a, $t);

		$members = self::ok($b->call('GET', '/mentions/members' . self::q(['workspace' => $key])), 'members B')['members'];
		sort($members);
		$want = [$a->uid, $b->uid];
		sort($want);
		self::assertSame($want, $members);
		self::assertContains($e->call('GET', '/mentions/members' . self::q(['workspace' => $key]))->status, [403, 404], 'E ohne Leserecht');

		$n = self::newNote($a, $key, 'Hallo', "<p>Hallo @{$b->uid} und @{$e->uid}</p>");
		self::assertSame(1, self::mentionNotifications($n, $b->uid), 'B benachrichtigt');
		self::assertSame(0, self::mentionNotifications($n, $e->uid), 'E (kein Mitglied) nicht');
		$r = self::ok($b->call('GET', '/mentions'), 'B mentions');
		self::assertSame([$n], array_column($r['mentions'], 'note_id'));
		$path = $r['mentions'][0]['owner_path'];
		self::ok($b->call('POST', '/mentions/' . self::enc($path) . "/$n/handled"), 'erledigt im Team-Workspace');
		self::assertStringContainsString('mentions-item', self::page($b, 'mentions.php?handled=1')->body);

		$this->teams->remove($a->uid, $memberB);
		Server::get(WorkspaceService::class)->reset();
		$x = Server::get(INotificationManager::class)->createNotification();
		$x->setApp('nextnotes')->setUser($b->uid)->setDateTime(new \DateTime())->setObject('note', (string)$n)->setSubject('mention', ['by' => $a->uid, 'byName' => 'A']);
		try {
			Server::get(Notifier::class)->prepare($x, 'en');
			self::fail('prepare nach Austritt liefert Anzeige');
		} catch (AlreadyProcessedException) {
			self::assertTrue(true);
		}
	}

	// Done 5 (R16): geteilt → Palette des Besitzers; Team → Standardpalette für alle
	public function testNoteColorPalettes(): void {
		$a = $this->createUser('ca');
		$c = $this->createUser('cc');
		$custom = [['id' => 'blue', 'name' => 'Meer', 'hex' => '#123456']];
		self::ok($a->call('PUT', '/settings/note_color_palette', ['value' => json_encode($custom)]), 'Palette A');
		self::ok($c->call('PUT', '/settings/note_color_palette', ['value' => json_encode([['id' => 'blue', 'name' => 'Himmel', 'hex' => '#abcdef']])]), 'Palette C');
		[$ws, $wsId] = self::newWorkspace($a, 'Farben');
		self::ok($a->call('PATCH', '/workspaces/' . self::enc($ws), ['color' => 'blue']), 'Workspace-Farbe');
		Server::get(WorkspaceService::class)->share($a->uid, $wsId, $c->uid);
		$keyC = null;
		foreach (self::ok($c->call('GET', '/workspaces'), 'C')['workspaces'] as $w) {
			if (str_starts_with($w['name'], $ws)) {
				$keyC = $w['name'];
				self::assertSame('#123456', $w['color_hex'], 'C sieht Farbe des Besitzers');
			}
		}
		self::assertNotNull($keyC);
		self::assertStringContainsString('#123456', self::page($c, 'index.php?workspace=' . self::enc($keyC))->body, 'Palette des Besitzers in der Hauptansicht');
		// JSON-API: C setzt Farb-ID in A's Workspace → sofort und nach Reload A's Hex
		$note = self::newNote($c, $keyC, 'Farbnotiz', '<p>x</p>');
		self::assertSame('#123456', self::ok($c->call('PUT', "/notes/$note/color", ['color' => 'blue']), 'Notizfarbe C')['color_hex']);
		self::assertSame('#123456', self::ok($c->call('GET', "/notes/$note"), 'Notiz C')['note']['color_hex'] ?? null, 'GET /notes/{id}');
		$list = self::ok($c->call('GET', '/notes?workspace=' . self::enc($keyC)), 'Liste C')['notes'];
		self::assertSame(['#123456'], array_column(array_filter($list, static fn ($n) => $n['id'] === $note), 'color_hex'), 'GET /notes');
		$folder = (int)self::ok($c->call('POST', '/folders', ['folder_name' => 'Farbordner', 'workspace' => $keyC]), 'Ordner C')['folder_id'];
		self::assertSame('#123456', self::ok($c->call('PUT', "/folders/$folder/color", ['color' => 'blue']), 'Ordnerfarbe C')['color_hex']);
		$folders = self::ok($c->call('GET', '/folders?workspace=' . self::enc($keyC)), 'Ordner C')['folders'];
		self::assertSame(['#123456'], array_column(array_filter($folders, static fn ($f) => $f['id'] === $folder), 'color_hex'), 'GET /folders');

		$t = $this->teams->create($a->uid, 'Farbteam ' . bin2hex(random_bytes(3)));
		$this->teams->addUser($t, $a->uid, $c->uid);
		[$key] = self::teamWs($a, $t);
		self::ok($a->call('PATCH', '/workspaces/' . self::enc($key), ['color' => 'blue']), 'Team-Farbe');
		$default = self::wsEntry($a, $key)['color_hex'];
		self::assertNotSame('#123456', $default);
		self::assertSame($default, self::wsEntry($c, $key)['color_hex'], 'Team: gleiche Standardfarbe für alle');
	}
}
