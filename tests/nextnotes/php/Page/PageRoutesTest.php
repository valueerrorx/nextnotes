<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Page;

use OCA\NextNotes\Tests\Support\ApiClient;
use OCA\NextNotes\Tests\Support\ApiTestCase;

// Done M3a2 1, 5 / SPEC §4.4, §4.6, §11.2 B28: Hauptansicht-Status, Fremdzugriff ?note=/?workspace=, GET ohne Seiteneffekt, CSP
final class PageRoutesTest extends ApiTestCase {
	private const PAGE = '/index.php/apps/nextnotes/index.php';

	public function testIndexOwnAndForeign(): void {
		$a = $this->createUser('a');
		$b = $this->createUser('b');
		$mark = 'PAGEMARK' . bin2hex(random_bytes(4));
		[$ws] = self::newWorkspace($a, 'Seite');
		$nA = self::newNote($a, $ws, "Titel $mark", "<p>$mark</p>");

		// A: eigene Seite, eigene Notiz, eigener Workspace
		foreach (['', '?workspace=' . self::enc($ws), "?note=$nA", '?workspace=' . self::enc($ws) . "&note=$nA"] as $q) {
			$r = $a->absolute('GET', self::PAGE . $q);
			self::assertSame(200, $r->status, "A GET index.php$q: " . $r->describe());
			self::assertStringContainsString('id="nextnotes-root"', $r->body, "A $q: Rahmen fehlt");
			$csp = (string)$r->header('Content-Security-Policy');
			self::assertMatchesRegularExpression('/script-src [^;]*nonce-/', $csp, "A $q: CSP ohne Nonce");
			self::assertDoesNotMatchRegularExpression('/script-src [^;]*\'unsafe-(eval|inline)\'/', $csp, "A $q: CSP mit unsafe-eval/-inline");
		}
		self::assertStringContainsString("Titel $mark", $a->absolute('GET', self::PAGE . '?workspace=' . self::enc($ws))->body, 'A sieht eigene Notiz in der Liste');

		// B: fremde Notiz → 404 ohne Inhalt; fremder Workspace → eigener Standard ohne A-Marker
		foreach (["?note=$nA", '?workspace=' . self::enc($ws) . "&note=$nA"] as $q) {
			$r = $b->absolute('GET', self::PAGE . $q);
			self::assertSame(404, $r->status, "B GET index.php$q: " . $r->describe());
			self::assertStringNotContainsString($mark, $r->body, "B $q: A-Marker im Body");
			self::assertStringNotContainsString('nextnotes-root', $r->body, "B $q: App-Rahmen in 404");
		}
		$bDefault = self::workspaceNames($b)[0] ?? '';
		$r = $b->absolute('GET', self::PAGE . '?workspace=' . self::enc($ws));
		self::assertSame(200, $r->status, 'B ?workspace=<A>: ' . $r->describe());
		self::assertStringNotContainsString($mark, $r->body, 'B ?workspace=<A>: A-Marker');
		self::assertStringContainsString('class="workspace-title-text" title="' . htmlspecialchars($bDefault) . '">' . htmlspecialchars($bDefault) . '<', $r->body, "B ?workspace=<A>: nicht B-Standard „{$bDefault}“");

		// ungültige ?note= → 404
		foreach (['abc', '0', '-1', '99999999', '1e3', '%3Cscript%3E', "$nA%20x"] as $bad) {
			$r = $a->absolute('GET', self::PAGE . "?note=$bad");
			self::assertSame(404, $r->status, "A ?note=$bad: " . $r->describe());
		}
		// unbekannter Workspace → 200, Standard
		$names = self::workspaceNames($a);
		$aDefault = array_values(array_filter($names, static fn ($n) => $n !== $ws))[0] ?? '';
		$r = $a->absolute('GET', self::PAGE . '?workspace=Gibt%20es%20nicht');
		self::assertSame(200, $r->status);
		self::assertStringContainsString('class="workspace-title-text" title="' . htmlspecialchars($aDefault) . '">', $r->body, "unbekannter Workspace → Standard „{$aDefault}“");
	}

	public function testGetHasNoSideEffect(): void {
		$a = $this->createUser('a');
		[$ws] = self::newWorkspace($a, 'Seite');
		$nA = self::newNote($a, $ws, 'Seiteneffekt', '<p>x</p>');
		$before = self::dump($a->uid);
		$settingsBefore = [];
		foreach (['last_opened_workspace', 'default_workspace'] as $k) {
			$settingsBefore[$k] = self::ok($a->call('GET', "/settings/$k"), $k)['value'] ?? null;
		}
		foreach (['?workspace=' . self::enc($ws), "?note=$nA", '?workspace=Unbekannt', '?workspace=' . self::enc($ws) . '&folder=x&open_folder=y'] as $q) {
			self::assertSame(200, $a->absolute('GET', self::PAGE . $q)->status, $q);
		}
		foreach ($settingsBefore as $k => $v) {
			self::assertSame($v, self::ok($a->call('GET', "/settings/$k"), $k)['value'] ?? null, "B28: GET index.php schreibt $k");
		}
		self::assertSame($before, self::dump($a->uid), 'B28: GET index.php ändert App-Daten');
	}

	public function testLoggedOutRedirectsToLogin(): void {
		$r = ApiClient::raw('GET', ApiClient::baseUrl() . self::PAGE, null, ['Accept: text/html'], null);
		self::assertContains($r->status, [302, 303, 401], 'abgemeldet: ' . $r->describe());
		if ($r->status !== 401) {
			self::assertStringContainsString('/login', (string)$r->header('Location'));
		}
	}
}
