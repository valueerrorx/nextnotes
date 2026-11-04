<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Page;

use OCA\NextNotes\Service\NotePalette;
use OCA\NextNotes\Service\WorkspaceService;
use OCA\NextNotes\Tests\Support\ApiClient;
use OCA\NextNotes\Tests\Support\ApiTestCase;
use OCA\NextNotes\Tests\Support\Response;
use OCP\Server;

// Plan M4 §M4h Done 1–3: Einstellungsseite nur mit eigenen Daten, gestrichene Karten fehlen, Speicher nur eigene Zahlen
// (Lesefreigabe zählt beim Besitzer), workspaces.php GET → 303, Farbpalette bereinigt, Umbenennen zieht den Standard-Workspace mit
final class SettingsM4hTest extends ApiTestCase {
	private const APP = '/index.php/apps/nextnotes/';
	private const GONE = ['change-password-card', 'two-factor-card', 'app-passwords-card', 'delete-account-card', 'language-card',
		'timezone-card', 'backup-export-card', 'git-sync-card', 'main-font-card', 'markdown-font-card', 'icon-sidebar-order-card',
		'whats-new-card', 'discord-card', 'admin-tools-grid', 'login-display-card', 'import-notes-card', 'offline-notes-card'];

	private static function get(ApiClient $c, string $path): Response {
		return $c->absolute('GET', self::APP . $path);
	}

	/** Zahlen des Speicher-Fragments: [Notizen gesamt, Anhänge in MB] */
	private static function storage(ApiClient $c, string $query = ''): array {
		$r = self::get($c, 'storage-stats-user.php?fragment=1' . $query);
		self::assertSame(200, $r->status, 'Speicher-Fragment: ' . $r->describe());
		preg_match_all('/settings-storage-value">([^<]+)/', $r->body, $m);
		self::assertCount(2, $m[1], 'zwei Zeilen');
		return [(int)preg_replace('/\D/', '', $m[1][0]), (float)str_replace(',', '', explode("\u{00a0}", $m[1][1])[0])];
	}

	public function testSettingsPageOwnDataOnly(): void {
		$a = $this->createUser('sa');
		$b = $this->createUser('sb');
		$mark = 'SET' . bin2hex(random_bytes(4));
		[$ws] = self::newWorkspace($a, "Einst$mark");
		$r = self::get($a, 'settings.php?workspace=' . self::enc($ws));
		self::assertSame(200, $r->status, $r->describe());
		self::assertStringContainsString('id="settings-workspaces-list"', $r->body);
		self::assertStringContainsString('data-ws="' . htmlspecialchars($ws) . '"', $r->body, 'eigener Workspace in der Liste');
		self::assertStringContainsString('id="iconSidebarSettingsBtn"', $r->body, 'Icon-Leiste: Einstellungen');
		self::assertStringContainsString('id="iconSidebarAboutBtn"', $r->body, 'Icon-Leiste: Über');
		foreach (self::GONE as $id) {
			self::assertStringNotContainsString('id="' . $id . '"', $r->body, "gestrichen: $id");
		}
		self::assertStringNotContainsString('<script>', $r->body, 'keine Inline-Skripte');
		self::assertStringContainsString('/settings/user/language-locale', $r->body, 'Hinweis-Karte: NC-Sprache');

		// B: fremder Workspace im Parameter → eigener Standard, A-Daten fehlen
		$rb = self::get($b, 'settings.php?workspace=' . self::enc($ws));
		self::assertSame(200, $rb->status, $rb->describe());
		self::assertStringNotContainsString($mark, $rb->body, 'B: keine A-Daten');
	}

	public function testStorageCountsOwnWorkspacesOnly(): void {
		$a = $this->createUser('sta');
		$b = $this->createUser('stb');
		[$ws, $wsId] = self::newWorkspace($a, 'Speicher');
		$n = self::newNote($a, $ws, 'Anhang', '<p>x</p>');
		self::ok($a->upload("/notes/$n/attachments", 'a.txt', str_repeat('x', 300000), [], 'text/plain'), 'Anhang');
		Server::get(WorkspaceService::class)->share($a->uid, $wsId, $b->uid, 'read');
		[$notesA, $mbA] = self::storage($a);
		self::assertGreaterThanOrEqual(1, $notesA, 'A: Notizen gezählt');
		self::assertGreaterThan(0.2, $mbA, 'A: Anhang gezählt');
		[$notesB0, $mbB0] = self::storage($b, '&workspace=' . self::enc($ws));
		self::assertSame(0.0, $mbB0, 'B: Anhang im lesend geteilten Workspace zählt nicht');
		self::newNote($a, $ws, 'Noch eine', '<p>y</p>');
		self::assertSame($notesB0, self::storage($b)[0], 'B: Notiz im geteilten Workspace zählt nicht');
		[$bws] = self::newWorkspace($b, 'Eigen');
		self::newNote($b, $bws, 'Eigen', '<p>z</p>');
		self::assertSame($notesB0 + 1, self::storage($b)[0], 'B: eigene Notiz zählt');

		// ohne fragment=1 → Einstellungen, Bereich Konto; abgemeldet keine Daten
		$r = self::get($a, 'storage-stats-user.php');
		self::assertSame(303, $r->status, $r->describe());
		self::assertStringContainsString('settings.php?open=account', (string)$r->header('location'));
		$out = ApiClient::raw('GET', ApiClient::baseUrl() . self::APP . 'storage-stats-user.php?fragment=1', null, ['Accept: text/html'], null);
		self::assertNotSame(200, $out->status, 'abgemeldet: keine Zahlen');
		self::assertStringNotContainsString('settings-storage-row', $out->body);
	}

	public function testWorkspacesGetRedirectsToSettings(): void {
		$a = $this->createUser('wg');
		$r = self::get($a, 'workspaces.php?workspace=' . self::enc('Mein WS') . '&new=1');
		self::assertSame(303, $r->status, $r->describe());
		$loc = (string)$r->header('location');
		self::assertMatchesRegularExpression('#/settings\.php\?workspace=Mein%20WS&open=new-workspace\#section=settings-workspaces-section-grid$#', $loc);
		$r = self::get($a, 'workspaces.php?workspace=__last_opened__');
		self::assertMatchesRegularExpression('#/settings\.php\#section=settings-workspaces-section-grid$#', (string)$r->header('location'));
	}

	public function testPaletteSanitizedAndUsed(): void {
		$a = $this->createUser('pal');
		self::assertSame(400, $a->call('PUT', '/settings/' . NotePalette::SETTING, ['value' => '[{"id":"!!","hex":"nope"}]'])->status, 'ungültige Palette → 400');
		$raw = json_encode([['id' => 'Mint Grün', 'name' => '', 'hex' => '#ABC'], ['id' => 'mintgrün', 'name' => 'Doppelt', 'hex' => '#000000'], ['id' => 'x', 'hex' => '#123456', 'extra' => 1]]);
		$saved = self::ok($a->call('PUT', '/settings/' . NotePalette::SETTING, ['value' => $raw]), 'Palette');
		$stored = json_decode((string)($saved['value'] ?? self::ok($a->call('GET', '/settings/' . NotePalette::SETTING), 'Palette')['value']), true);
		self::assertSame([['id' => 'mintgrn', 'name' => 'Mintgrn', 'hex' => '#aabbcc'], ['id' => 'x', 'name' => 'X', 'hex' => '#123456']], $stored, 'bereinigt wie Upstream');
		// Workspace-Farbe aus der eigenen Palette in der Einstellungsliste
		[$ws] = self::newWorkspace($a, 'Farbe');
		self::ok($a->form(self::APP . 'workspaces.php', ['action' => 'set_color', 'name' => $ws, 'color' => 'x']), 'Farbe');
		$body = self::get($a, 'settings.php?workspace=' . self::enc($ws))->body;
		self::assertStringContainsString('background-color: #123456', $body, 'Farbe über die Benutzer-Palette');
		self::assertStringContainsString('data-color="mintgrn"', $body, 'Farbdialog mit Benutzer-Palette');
	}

	public function testRenameKeepsDefaultWorkspace(): void {
		$a = $this->createUser('ren');
		[$ws] = self::newWorkspace($a, 'Alt');
		self::ok($a->call('PUT', '/settings/default_workspace', ['value' => $ws]), 'Standard');
		self::ok($a->form(self::APP . 'workspaces.php', ['action' => 'rename', 'name' => $ws, 'new_name' => "$ws neu"]), 'Umbenennen');
		self::assertSame("$ws neu", self::ok($a->call('GET', '/settings/default_workspace'), 'Standard')['value'], 'Standard folgt dem Namen');
	}

	// Review M4h-r1 Hinweis 1: Abgleich über Schlüssel, nicht über den Rohnamen
	public function testRenameMatchesKeysNotNames(): void {
		$a = $this->createUser('rka');
		$b = $this->createUser('rkb', 'Bea Key');
		$name = 'Foo ' . bin2hex(random_bytes(3));
		self::ok($a->call('POST', '/workspaces', ['name' => $name]), 'A Foo');
		self::ok($b->call('POST', '/workspaces', ['name' => $name]), 'B Foo');
		Server::get(WorkspaceService::class)->share($b->uid, self::ownWsId($b->uid, $name), $a->uid, 'write');
		$shared = "$name (Bea Key)";
		self::assertContains($shared, self::workspaceNames($a), 'Schlüssel des geteilten Foo');
		self::ok($a->call('PUT', '/settings/default_workspace', ['value' => $name]), 'Standard eigenes Foo');
		self::ok($a->call('PUT', '/settings/last_opened_workspace', ['value' => $shared]), 'zuletzt geteiltes Foo');
		// geteilten gleichnamigen umbenennen: verweigert, Standard bleibt
		$r = $a->form(self::APP . 'workspaces.php', ['action' => 'rename', 'name' => $shared, 'new_name' => 'Anders']);
		self::assertContains($r->status, [403, 404], 'fremden Workspace umbenennen: ' . $r->describe());
		self::assertSame($name, self::ok($a->call('GET', '/settings/default_workspace'), 'Standard')['value'], 'Standard bleibt');
		// eigenes Foo umbenennen: Standard folgt, Schlüssel des geteilten Foo verschiebt sich auf den Rohnamen
		self::ok($a->form(self::APP . 'workspaces.php', ['action' => 'rename', 'name' => $name, 'new_name' => "$name neu"]), 'Umbenennen');
		self::assertSame("$name neu", self::ok($a->call('GET', '/settings/default_workspace'), 'Standard')['value'], 'Standard folgt');
		self::assertSame($name, self::ok($a->call('GET', '/settings/last_opened_workspace'), 'zuletzt')['value'], 'geteilter Workspace bleibt gewählt');
	}
}
