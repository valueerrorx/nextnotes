<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Routes;

use OCA\NextNotes\Tests\Support\ApiClient;
use OCA\NextNotes\Tests\Support\ApiTestCase;
use OCA\NextNotes\Tests\Support\Response;

// SPEC §13 M4.3, Plan M4i Krit. 3: jede „streichen“-Route aus Inventur A (§1.1 Router, §1.2 Einzelskripte) und B (§1.2–1.6) → 404 (angemeldet);
// Ausnahmen: /shared* → leere Listen (ersetzen), login.php/logout.php → Weiterleitung auf die App (SPEC §4.2); nicht portierte Seiten (B11) → 404.
final class StrippedRoutesTest extends ApiTestCase {
	private const APP = '/index.php/apps/nextnotes';
	// SPEC §4.2: Sicherheitsnetz für alte Links, kein Inhalt
	private const REDIRECTS = ['/login.php', '/logout.php'];
	// SPEC §11.2 B11: Upstream-Seiten ohne Port
	private const NOT_PORTED = ['/favorites.php', '/welcome.php'];

	private static function refDir(): string {
		$dir = getenv('NN_REF_DIR');
		self::assertNotFalse($dir, 'NN_REF_DIR fehlt (run-phpunit.sh setzt es)');
		return $dir;
	}

	private static function section(string $md, string $from, ?string $to): string {
		$start = strpos($md, $from);
		self::assertNotFalse($start, "Abschnitt $from fehlt");
		$end = $to === null ? false : strpos($md, $to, $start);
		return substr($md, $start, $end === false ? null : $end - $start);
	}

	/** Zellen einer Tabellenzeile */
	private static function cells(string $line): array {
		return array_map('trim', explode('|', trim(trim($line), '|')));
	}

	/** @return list<array{0: string, 1: string}> [Verb, Pfad unter /api/v1] aus Inventur A §1.1, Status streichen */
	private static function apiRoutes(): array {
		$out = [];
		foreach (explode("\n", self::section((string)file_get_contents(self::refDir() . '/A-backend.md'), '### 1.1', "\n### ")) as $line) {
			$c = self::cells($line);
			if (count($c) >= 6 && ctype_digit($c[0]) && str_starts_with(end($c), 'streichen') && !str_contains($c[3], 'vor Router')) {
				$out[] = [$c[1], preg_replace('/\{[^}]+\}/', '1', $c[2])];
			}
		}
		return $out;
	}

	/** @return list<string> Pfade (App-relativ) gestrichener Einzeldateien aus Inventur A §1.2 und B §1.2–1.6 */
	private static function files(): array {
		$paths = [];
		$a = self::section((string)file_get_contents(self::refDir() . '/A-backend.md'), '### 1.2', "\n## ");
		foreach (explode("\n", $a) as $line) {
			$c = self::cells($line);
			if (count($c) >= 6 && ctype_digit($c[0]) && str_starts_with(end($c), 'streichen')) {
				preg_match_all('#/(?:[A-Za-z0-9_.\-/]|\{[^}]*\})+#', $c[2], $m);
				array_push($paths, ...$m[0]);
			}
		}
		$b = self::section((string)file_get_contents(self::refDir() . '/B-frontend.md'), '### 1.2', "\n## 2.");
		foreach (explode("\n", $b) as $line) {
			$c = self::cells($line);
			if (count($c) >= 5 && ctype_digit($c[0]) && preg_match('/^`([^`]+)`$/', $c[1], $f)) {
				foreach ($c as $cell) {
					if (str_starts_with($cell, 'streichen')) {
						$paths[] = '/' . $f[1];
						break;
					}
				}
			}
		}
		$out = [];
		foreach ($paths as $p) {
			// {a,b}-Aufzählungen ausmultiplizieren, übrige Platzhalter durch Beispielwert
			if (preg_match('/^(.*)\{([^}]*,[^}]*)\}(.*)$/', $p, $m)) {
				foreach (explode(',', $m[2]) as $alt) {
					$out[] = $m[1] . $alt . $m[3];
				}
			} else {
				$out[] = preg_replace('/\{[^}]+\}/', 'x', $p);
			}
		}
		return array_values(array_unique($out));
	}

	public function testInventoryListsAreComplete(): void {
		// Gegenkontrolle der Auswertung: bekannte Einträge aus jedem Abschnitt
		$api = array_map(static fn ($r) => "$r[0] $r[1]", self::apiRoutes());
		foreach (['GET /offline/manifest', 'DELETE /notes/1/share', 'POST /backups', 'GET /git-sync/status', 'POST /users/me/two-factor/setup', 'DELETE /admin/users/1', 'POST /public/notes/lock/release'] as $e) {
			self::assertContains($e, $api, "Inventur A §1.1: $e");
		}
		self::assertCount(72, $api, 'Inventur A §1.1: Zahl gestrichener Router-Zeilen (184 − 112 in routes.php)');
		$files = self::files();
		foreach (['/shared.php', '/public_note.php', '/login.php', '/oidc_callback.php', '/account_tree.php', '/admin/users.php', '/oauth/token.php', '/oauth/introspect.php',
			'/api-docs/index.php', '/offline.php', '/pwa/manifest.php', '/sw.js', '/api_upload_css.php', '/api_ai_chat.php', '/api_health.php', '/api/health', '/workspace/x'] as $e) {
			self::assertContains($e, $files, "Inventur A/B: $e");
		}
	}

	private static function assertGone(Response $r, string $what): void {
		self::assertSame(404, $r->status, "$what: " . $r->describe());
		self::assertStringNotContainsString('nextnotes-root', $r->body, "$what: App-Rahmen in 404");
	}

	public function testStrippedApiRoutesAre404(): void {
		$a = $this->createUser('sr');
		[$ws] = self::newWorkspace($a, 'Str');
		$n = self::newNote($a, $ws, 'Gestrichen', '<p>x</p>');
		$before = self::dump($a->uid);
		$routes = self::apiRoutes();
		// gleicher Pfad mit anderem Verb vorhanden (z. B. PATCH /users/me neben GET) → Symfony liefert 405; beides „nicht verfügbar“
		foreach ($routes as [$verb, $path]) {
			$path = str_replace('/notes/1/', "/notes/$n/", $path);
			$r = $a->call($verb, $path, in_array($verb, ['POST', 'PUT', 'PATCH'], true) ? ['x' => 1] : null);
			self::assertContains($r->status, [404, 405], "$verb $path: " . $r->describe());
		}
		self::assertSame($before, self::dump($a->uid), 'gestrichene Routen ändern nichts');
		// ersetzen statt streichen: leere Listen
		self::assertSame(['shared_notes' => [], 'shared_folders' => []], array_intersect_key(self::ok($a->call('GET', '/shared'), '/shared'), ['shared_notes' => 1, 'shared_folders' => 1]));
		self::assertSame(['items' => [], 'shared_workspaces' => []], array_intersect_key(self::ok($a->call('GET', '/shared/with-me'), '/shared/with-me'), ['items' => 1, 'shared_workspaces' => 1]));
	}

	public function testStrippedFilesAre404(): void {
		$a = $this->createUser('sf');
		$files = array_merge(self::files(), self::NOT_PORTED);
		foreach ($files as $p) {
			$redirect = in_array($p, self::REDIRECTS, true);
			foreach (['GET', 'POST'] as $verb) {
				$r = ApiClient::raw($verb, ApiClient::baseUrl() . self::APP . $p, null, ['Accept: text/html', 'OCS-APIRequest: true'], $a->uid . ':' . self::PASSWORD, null, $verb === 'POST' ? 'x=1' : null);
				if ($redirect && $verb === 'GET') {
					self::assertContains($r->status, [302, 303], "$p: Weiterleitung " . $r->describe());
					self::assertStringContainsString('/apps/nextnotes', (string)$r->header('Location'), "$p: Ziel App");
					continue;
				}
				// POST auf GET-Weiterleitung: Route existiert nur für GET → 405
				if ($redirect) {
					self::assertContains($r->status, [404, 405], "$verb $p: " . $r->describe());
					continue;
				}
				self::assertGone($r, "$verb $p");
			}
		}
	}
}
