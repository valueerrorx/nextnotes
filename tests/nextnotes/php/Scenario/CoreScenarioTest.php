<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Scenario;

use OCA\NextNotes\Tests\Support\ApiClient;
use OCA\NextNotes\Tests\Support\ApiTestCase;
use OCA\NextNotes\Tests\Support\Response;
use OCP\App\IAppManager;
use OCP\Config\IUserConfig;
use OCP\Server;

// Done M2a 4 / SPEC §13 M2.3 (Teil M2a): Kern-Szenario, A und B parallel; JSON-Schlüssel = Upstream-Fixtures (Plan §3.4)
final class CoreScenarioTest extends ApiTestCase {
	/** @var array<string, Response> Route → erste 2xx-Antwort (sonst letzte) */
	private array $recorded = [];

	private function rec(string $route, Response $r): array {
		if (!isset($this->recorded[$route]) || (!$this->recorded[$route]->ok() && $r->ok())) {
			$this->recorded[$route] = $r;
		}
		self::assertTrue($r->ok(), "$route: " . $r->describe());
		$j = $r->json();
		return is_array($j) ? $j : [];
	}

	private static function fixtureDir(): string {
		return dirname(__DIR__, 2) . '/fixtures';
	}

	/** Schlüsselunterschiede rekursiv: Objekte → Schlüsselmenge, Listen → erstes Element */
	private static function keyDiff(mixed $want, mixed $got, string $path, array &$out): void {
		if (!($want instanceof \stdClass) || !($got instanceof \stdClass)) {
			if (is_array($want) && is_array($got) && $want !== [] && $got !== []) {
				self::keyDiff($want[0], $got[0], $path . '[]', $out);
			}
			return;
		}
		// Zahlen-Schlüssel sind IDs (Map), nicht Teil des Formats
		$named = static fn (\stdClass $o) => array_values(array_filter(array_map('strval', array_keys(get_object_vars($o))), static fn ($x) => !ctype_digit($x)));
		$wk = $named($want);
		$gk = $named($got);
		foreach (array_diff($wk, $gk) as $k) {
			$out[] = ['path' => ltrim("$path.$k", '.'), 'kind' => 'missing'];
		}
		foreach (array_diff($gk, $wk) as $k) {
			$out[] = ['path' => ltrim("$path.$k", '.'), 'kind' => 'extra'];
		}
		foreach (array_intersect($wk, $gk) as $k) {
			self::keyDiff($want->$k, $got->$k, ltrim("$path.$k", '.'), $out);
		}
	}

	public function testCoreScenarioAndFixtureKeys(): void {
		$a = $this->createUser('sa');
		$b = $this->createUser('sb');
		$bMark = 'bmk' . bin2hex(random_bytes(4));
		$ws = 'Fixture ' . bin2hex(random_bytes(2));
		$ws2 = $ws . ' neu';
		$q = self::q(['workspace' => $ws2]);

		// B arbeitet parallel im gleichnamigen Workspace
		self::ok($b->call('POST', '/workspaces', ['name' => $ws]), 'B Workspace');
		self::ok($b->call('POST', '/notes', ['heading' => "B $bMark", 'content' => "<p>$bMark</p>", 'tags' => 'ta tb', 'workspace' => $ws]), 'B Notiz');
		self::ok($b->call('POST', '/folders', ['folder_name' => "Eins", 'workspace' => $ws]), 'B Ordner');
		$bBefore = self::dump($b->uid);

		$this->rec('GET /workspaces', $a->call('GET', '/workspaces'));
		$this->rec('POST /workspaces', $a->call('POST', '/workspaces', ['name' => $ws]));
		$this->rec('PATCH /workspaces/{name}', $a->call('PATCH', '/workspaces/' . self::enc($ws), ['new_name' => $ws2]));
		self::assertContains($ws2, self::workspaceNames($a));
		self::assertNotContains($ws, self::workspaceNames($a));

		$f1 = $this->rec('POST /folders', $a->call('POST', '/folders', ['folder_name' => 'Eins', 'workspace' => $ws2]))['folder_id'];
		$f2 = self::ok($a->call('POST', '/folders', ['folder_name' => 'Zwei', 'workspace' => $ws2]), 'f2')['folder_id'];
		$f3 = self::ok($a->call('POST', '/folders', ['folder_name' => 'Drei', 'workspace' => $ws2]), 'f3')['folder_id'];
		$this->rec('GET /folders', $a->call('GET', '/folders' . $q . '&hierarchical=true'));
		$this->rec('GET /folders/{id}', $a->call('GET', "/folders/$f1$q"));

		$n1 = $this->rec('POST /notes', $a->call('POST', '/notes', ['heading' => 'Notiz eins', 'content' => '<p>Inhalt</p>', 'tags' => 'ta tb', 'workspace' => $ws2, 'folder_id' => $f1]))['note']['id'];
		$n2 = self::ok($a->call('POST', '/notes', ['heading' => 'Notiz zwei', 'content' => '<p>zwei</p>', 'workspace' => $ws2]), 'n2')['note']['id'];
		$n3 = self::ok($a->call('POST', '/notes', ['heading' => 'Notiz drei', 'content' => '<p>drei</p>', 'workspace' => $ws2]), 'n3')['note']['id'];
		$this->rec('GET /notes', $a->call('GET', '/notes' . $q));
		$show = $a->call('GET', "/notes/$n1$q");
		$this->rec('GET /notes/{id}', $show);
		$this->rec('GET /notes/templates', $a->call('GET', '/notes/templates' . $q));

		// ETag / If-None-Match wie Upstream
		$etag = $show->header('etag');
		self::assertNotNull($etag, 'GET /notes/{id} ohne ETag');
		self::assertSame(304, $a->call('GET', "/notes/$n1$q", null, ['If-None-Match: ' . $etag])->status, 'If-None-Match → 304');

		$this->rec('PATCH /notes/{id}', $a->call('PATCH', "/notes/$n1", ['heading' => 'Notiz eins b', 'content' => '<p>neu</p>']));
		$after = $a->call('GET', "/notes/$n1$q", null, ['If-None-Match: ' . $etag]);
		self::assertSame(200, $after->status, 'nach Änderung alter ETag → 200');
		self::assertSame('Notiz eins b', $after->json()['note']['heading']);
		self::assertStringContainsString('neu', $after->json()['note']['content']);

		$this->rec('POST /notes/{id}/beacon', $a->call('POST', "/notes/$n1/beacon", ['content' => '<p>beacon</p>']));
		$this->rec('PUT /notes/{id}/tags', $a->call('PUT', "/notes/$n1/tags", ['tags' => 'ta tb']));
		$this->rec('PUT /notes/{id}/icon', $a->call('PUT', "/notes/$n1/icon", ['icon' => 'lucide-star', 'icon_color' => '#ff0000']));
		$this->rec('PUT /notes/{id}/color', $a->call('PUT', "/notes/$n1/color", ['color' => '#00ff00']));
		$this->rec('PUT /notes/{id}/pinned', $a->call('PUT', "/notes/$n1/pinned", ['pinned' => true]));
		$this->rec('PUT /notes/{id}/content-width', $a->call('PUT', "/notes/$n1/content-width", ['content_width' => 80]));
		$this->rec('PUT /notes/{id}/title-style', $a->call('PUT', "/notes/$n1/title-style", ['title_color' => '#112233', 'title_size' => 24]));
		$this->rec('POST /notes/{id}/favorite', $a->call('POST', "/notes/$n1/favorite" . $q));
		$this->rec('POST /notes/{id}/opened', $a->call('POST', "/notes/$n1/opened"));
		$this->rec('POST /notes/{id}/folder', $a->call('POST', "/notes/$n1/folder", ['folder_id' => $f2, 'workspace' => $ws2]));
		$this->rec('POST /notes/{id}/remove-folder', $a->call('POST', "/notes/$n1/remove-folder" . $q));
		$this->rec('POST /notes/{id}/kanban-completed', $a->call('POST', "/notes/$n1/kanban-completed", ['completed' => true]));
		$this->rec('POST /notes/reorder', $a->call('POST', '/notes/reorder', ['note_id' => $n2, 'target_note_id' => $n1, 'position' => 'before', 'workspace' => $ws2]));
		self::ok($a->call('POST', "/notes/$n1/folder", ['folder_id' => $f2, 'workspace' => $ws2]), 'n1 → f2');
		self::assertSame((int)$f2, (int)self::ok($a->call('GET', "/notes/$n1$q"), 'n1')['note']['folder_id']);

		$this->rec('GET /folders/counts', $a->call('GET', '/folders/counts' . $q));
		$this->rec('GET /folders/suggested', $a->call('GET', '/folders/suggested' . $q));
		$this->rec('GET /folders/{id}/notes', $a->call('GET', "/folders/$f2/notes$q"));
		$this->rec('GET /folders/{id}/path', $a->call('GET', "/folders/$f2/path$q"));
		$this->rec('PATCH /folders/{id}', $a->call('PATCH', "/folders/$f1", ['name' => 'Eins neu', 'workspace' => $ws2]));
		self::assertSame('Eins neu', self::ok($a->call('GET', "/folders/$f1$q"), 'f1')['folder']['name'] ?? null, 'Ordner umbenannt');
		$this->rec('PUT /folders/{id}/icon', $a->call('PUT', "/folders/$f1/icon", ['icon' => 'lucide-star', 'icon_color' => '#ff0000']));
		$this->rec('PUT /folders/{id}/color', $a->call('PUT', "/folders/$f1/color", ['color' => '#00ff00']));
		$this->rec('PUT /folders/{id}/pinned', $a->call('PUT', "/folders/$f1/pinned", ['pinned' => true]));
		$this->rec('PUT /folders/{id}/favorite', $a->call('PUT', "/folders/$f1/favorite", ['favorite' => true]));
		$this->rec('POST /folders/{id}/tags', $a->call('POST', "/folders/$f2/tags", ['tags' => ['tc'], 'workspace' => $ws2]));
		$this->rec('DELETE /folders/{id}/tags', $a->call('DELETE', "/folders/$f2/tags", ['tags' => ['tc'], 'workspace' => $ws2]));
		$this->rec('POST /folders/move-files', $a->call('POST', '/folders/move-files', ['source_folder_id' => $f2, 'target_folder_id' => $f1, 'workspace' => $ws2]));
		self::assertSame((int)$f1, (int)self::ok($a->call('GET', "/notes/$n1$q"), 'n1')['note']['folder_id'], 'move-files');
		$this->rec('POST /folders/{id}/move', $a->call('POST', "/folders/$f2/move", ['new_parent_folder_id' => $f1, 'workspace' => $ws2]));
		self::assertStringContainsString('Eins neu', (string)self::ok($a->call('GET', "/folders/$f2/path$q"), 'path')['path'], 'Ordner verschoben');
		$this->rec('POST /folders/reorder', $a->call('POST', '/folders/reorder', ['folder_id' => $f3, 'target_folder_id' => $f1, 'position' => 'before', 'workspace' => $ws2]));

		// Tags setzen/umbenennen/löschen
		$tags = $this->rec('GET /tags', $a->call('GET', '/tags' . $q))['tags'];
		self::assertContains('ta', $tags);
		$this->rec('PATCH /tags/{tag}', $a->call('PATCH', '/tags/ta', ['new_name' => 'tz', 'workspace' => $ws2]));
		$this->rec('DELETE /tags/{tag}', $a->call('DELETE', '/tags/tb' . $q));
		$tags = self::ok($a->call('GET', '/tags' . $q), 'tags')['tags'];
		self::assertContains('tz', $tags);
		self::assertNotContains('ta', $tags);
		self::assertNotContains('tb', $tags);

		$this->rec('POST /notes/favorites/clear', $a->call('POST', '/notes/favorites/clear', ['workspace' => $ws2]));

		// Papierkorb / wiederherstellen / endgültig löschen
		$this->rec('DELETE /notes/{id}', $a->call('DELETE', "/notes/$n2$q"));
		$trash = $this->rec('GET /trash', $a->call('GET', '/trash' . $q));
		self::assertContains((int)$n2, array_map(static fn ($n) => (int)$n['id'], $trash['notes']));
		$this->rec('POST /notes/{id}/restore', $a->call('POST', "/notes/$n2/restore$q"));
		self::assertSame(200, $a->call('GET', "/notes/$n2$q")->status);
		self::ok($a->call('DELETE', "/notes/$n2$q"), 'n2 erneut in Papierkorb');
		$this->rec('DELETE /trash/{id}', $a->call('DELETE', "/trash/$n2$q"));
		self::assertSame(404, $a->call('GET', "/notes/$n2$q")->status, 'endgültig gelöschte Notiz');
		$this->rec('POST /folders/{id}/empty', $a->call('POST', "/folders/$f1/empty$q"));
		$this->rec('DELETE /trash', $a->call('DELETE', '/trash' . $q));
		self::assertSame([], self::ok($a->call('GET', '/trash' . $q), 'trash')['notes']);

		// Ordner löschen und wiederherstellen
		$snap = $this->rec('DELETE /folders/{id}', $a->call('DELETE', "/folders/$f3$q"));
		self::assertSame(404, $a->call('GET', "/folders/$f3$q")->status, 'Ordner gelöscht');
		self::assertArrayHasKey('restore_snapshot', $snap);
		$this->rec('POST /folders/restore', $a->call('POST', '/folders/restore', $snap['restore_snapshot']));

		$n4 = self::ok($a->call('POST', '/notes', ['heading' => 'Archiv', 'content' => '<p>a</p>', 'workspace' => $ws2, 'folder_id' => $f1]), 'n4')['note']['id'];
		$this->rec('POST /notes/{id}/archive', $a->call('POST', "/notes/$n4/archive"));
		$this->rec('POST /folders/{id}/archive', $a->call('POST', "/folders/$f2/archive"));
		$this->rec('DELETE /notes/{id}?permanent', $a->call('DELETE', "/notes/$n3?permanent=true$q"));
		self::assertSame(404, $a->call('GET', "/notes/$n3$q")->status);
		$this->rec('DELETE /workspaces/{name}', $a->call('DELETE', '/workspaces/' . self::enc($ws2)));
		self::assertNotContains($ws2, self::workspaceNames($a));

		// A hat nie B's Daten gesehen, B ist unverändert
		foreach ($this->recorded as $route => $r) {
			self::assertStringNotContainsString($bMark, $r->body, "$route zeigt B's Daten");
		}
		self::assertSame($bBefore, self::dump($b->uid), 'B durch A verändert');

		$this->compareFixtures();
	}

	private function compareFixtures(): void {
		$allowed = json_decode((string)file_get_contents(self::fixtureDir() . '/allowed-diff.json'), true, flags: JSON_THROW_ON_ERROR);
		$allow = [];
		foreach ($allowed['entries'] as $e) {
			self::assertNotSame('', trim((string)($e['reason'] ?? '')), 'allowed-diff ohne Grund: ' . json_encode($e));
			$allow[$e['route'] . '|' . $e['kind'] . '|' . $e['path']] = false;
		}
		$errors = [];
		$fixtures = glob(self::fixtureDir() . '/upstream/*.json');
		self::assertNotEmpty($fixtures);
		foreach ($fixtures as $file) {
			$fx = json_decode((string)file_get_contents($file), false, flags: JSON_THROW_ON_ERROR);
			$route = $fx->route;
			if (!isset($this->recorded[$route])) {
				$errors[] = "$route: im Szenario nicht abgespielt";
				continue;
			}
			$r = $this->recorded[$route];
			$diff = [];
			self::keyDiff($fx->body, $r->object(), '', $diff);
			foreach ($diff as $d) {
				$k = $route . '|' . $d['kind'] . '|' . $d['path'];
				if (array_key_exists($k, $allow)) {
					$allow[$k] = true;
				} else {
					$errors[] = "$route: Schlüssel {$d['path']} " . ($d['kind'] === 'missing' ? 'fehlt' : 'zusätzlich');
				}
			}
			$sk = $route . '|status|' . $fx->status;
			if ($r->status !== $fx->status) {
				if (array_key_exists($sk, $allow)) {
					$allow[$sk] = true;
				} else {
					$errors[] = "$route: Status {$r->status} statt {$fx->status}";
				}
			}
		}
		foreach ($allow as $k => $used) {
			if (!$used) {
				$errors[] = "allowed-diff ungenutzt: $k";
			}
		}
		self::assertSame([], $errors);

		// Fixtures decken alle M2a-Routen ab
		$file = Server::get(IAppManager::class)->getAppPath('nextnotes') . '/appinfo/routes.php';
		$routes = (static fn () => require $file)();
		$fxRoutes = array_map(static fn ($f) => json_decode((string)file_get_contents($f))->route, $fixtures);
		$norm = static fn (string $r) => preg_replace(['/\{[^}]+\}/', '/\?.*$/'], ['{}', ''], $r);
		$fxRoutes = array_unique(array_map($norm, $fxRoutes));
		$missing = [];
		foreach ($routes['routes'] as $r) {
			if (str_starts_with($r['url'], '/api/v1/')) {
				$k = $norm(strtoupper($r['verb']) . ' ' . substr($r['url'], 7));
				if (!in_array($k, $fxRoutes, true)) {
					$missing[] = $k;
				}
			}
		}
		self::assertSame([], $missing, 'M2a-Routen ohne Upstream-Fixture');
	}

	// Detektor-Selbsttest: fehlende/zusätzliche Schlüssel werden erkannt, Zahlen-Schlüssel ignoriert
	public function testKeyDiffDetector(): void {
		$want = json_decode('{"a":1,"b":{"c":1,"7":1},"l":[{"x":1}]}');
		$got = json_decode('{"a":1,"b":{"d":1,"9":1},"l":[{"x":1,"y":2}]}');
		$out = [];
		self::keyDiff($want, $got, '', $out);
		self::assertSame([
			['path' => 'b.c', 'kind' => 'missing'],
			['path' => 'b.d', 'kind' => 'extra'],
			['path' => 'l[].y', 'kind' => 'extra'],
		], $out);
	}

	public function testFirstAccessCreatesExactlyOneDefaultWorkspace(): void {
		foreach ([1, 4] as $parallel) {
			$c = $this->createUser('first');
			Server::get(IUserConfig::class)->setValueString($c->uid, 'core', 'lang', 'de');
			$requests = array_fill(0, $parallel, ['GET', '/workspaces']);
			foreach ($c->parallel($requests) as $r) {
				self::assertSame(200, $r->status, $r->describe());
			}
			$ws = self::dump($c->uid)['workspaces'];
			self::assertCount(1, $ws, "$parallel Erstzugriffe → " . count($ws) . ' Workspaces');
			self::assertSame('Notizen', $ws[0]['name']);
			self::assertSame([], self::dump($c->uid)['notes'], 'keine Willkommensnotiz');
			self::assertSame(['Notizen'], self::workspaceNames($c));
		}
	}
}
