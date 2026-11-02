<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Access;

use OCA\NextNotes\Tests\Support\ApiClient;
use OCA\NextNotes\Tests\Support\ApiTestCase;
use OCA\NextNotes\Tests\Support\Response;
use OCP\App\IAppManager;
use OCP\Server;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Done M2a 3 / SPEC §13 M2.2, Plan §3.3: Port von tests/integration/isolation.test.php.
 * Jede API-Route aus routes.php braucht einen Eintrag in routeTable() (sonst rot).
 * Platzhalter {ws} {f1} {f2} {n1} {n2} {n3} {tag} in Pfad/Query/Body sind ID-Stellen (Slots);
 * je Fall: Variante „alle A“ und je Slot „nur dieser A, Rest B“. B → 403/404 (strict) und kein
 * A-Marker; A-Daten vorher = nachher; Kontrolle A (alle A) und B (alle B) → 2xx.
 * Slots in 'hints' sind reine Filterangaben (z. B. ?workspace= neben einer Objekt-ID): dort gilt nur
 * „kein Marker, A unverändert“, kein Statuszwang.
 */
final class IsolationTest extends ApiTestCase {
	private const KINDS = ['owned', 'scoped', 'scoped-write'];

	/** @return array<string, array> Route (Verb + Pfad mit {}) → Eintrag */
	public static function routeTable(): array {
		$wsQ = ['workspace' => '{ws}'];
		$hq = ['query.workspace'];
		return [
			// Notizen
			'POST /notes/reorder' => ['owned', 'cases' => [
				['path' => '/notes/reorder', 'body' => ['note_id' => '{n2}', 'target_note_id' => '{n1}', 'position' => 'before', 'workspace' => '{ws}'], 'hints' => ['body.workspace']],
				// visible_order = angezeigte Reihenfolge; fremde IDs werden ignoriert (Bericht an Lead: Abweichung vom Plan-Wortlaut)
				['path' => '/notes/reorder', 'body' => ['note_id' => '{n2}', 'target_note_id' => '{n1}', 'position' => 'before', 'scope' => 'kanban', 'visible_order' => ['{n1}', '{n2}'], 'workspace' => '{ws}'], 'hints' => ['body.workspace', 'body.visible_order.0', 'body.visible_order.1']],
			]],
			'POST /notes/favorites/clear' => ['owned', 'cases' => [
				['path' => '/notes/favorites/clear', 'body' => ['workspace' => '{ws}']],
			]],
			'GET /notes/templates' => ['scoped', 'path' => '/notes/templates', 'sees' => null],
			'GET /notes' => ['scoped', 'path' => '/notes', 'sees' => 'n1'],
			'GET /notes/{}' => ['owned', 'cases' => [['path' => '/notes/{n1}', 'query' => $wsQ, 'hints' => $hq]]],
			'POST /notes' => ['owned', 'cases' => [
				['path' => '/notes', 'body' => ['heading' => 'Neu', 'content' => '<p>neu</p>', 'workspace' => '{ws}', 'folder_id' => '{f1}']],
				['path' => '/notes', 'body' => ['heading' => 'Kurz', 'type' => 'linked', 'workspace' => '{ws}', 'linked_note_id' => '{n2}']],
			]],
			'PATCH /notes/{}' => ['owned', 'cases' => [
				['path' => '/notes/{n1}', 'body' => ['heading' => 'Geändert', 'content' => '<p>x</p>']],
				['path' => '/notes/{n1}', 'body' => ['heading' => 'Geändert', 'folder_id' => '{f2}']],
				// PATCH ignoriert linked_note_id (Upstream update ebenso); Querverweis-Prüfung deckt es ab
				['path' => '/notes/{n1}', 'body' => ['heading' => 'Geändert', 'linked_note_id' => '{n2}'], 'hints' => ['body.linked_note_id']],
				['path' => '/notes/{n1}', 'body' => ['heading' => 'Geändert', 'workspace' => '{ws}']],
			]],
			'DELETE /notes/{}' => ['owned', 'cases' => [
				['path' => '/notes/{n1}', 'query' => $wsQ, 'body' => [], 'hints' => $hq],
				['path' => '/notes/{n1}', 'query' => ['permanent' => 'true', 'workspace' => '{ws}'], 'body' => [], 'hints' => $hq],
			]],
			'POST /notes/{}/restore' => ['owned', 'cases' => [['path' => '/notes/{n3}/restore', 'query' => $wsQ, 'body' => [], 'hints' => $hq]]],
			'POST /notes/{}/beacon' => ['owned', 'cases' => [['path' => '/notes/{n1}/beacon', 'body' => ['content' => '<p>beacon</p>']]]],
			'PUT /notes/{}/tags' => ['owned', 'cases' => [['path' => '/notes/{n1}/tags', 'body' => ['tags' => 'neu']]]],
			'PUT /notes/{}/icon' => ['owned', 'cases' => [['path' => '/notes/{n1}/icon', 'body' => ['icon' => 'lucide-star', 'icon_color' => '#ff0000']]]],
			'PUT /notes/{}/color' => ['owned', 'cases' => [['path' => '/notes/{n1}/color', 'body' => ['color' => '#00ff00']]]],
			'POST /notes/{}/favorite' => ['owned', 'cases' => [['path' => '/notes/{n1}/favorite', 'query' => $wsQ, 'body' => [], 'hints' => $hq]]],
			'POST /notes/{}/opened' => ['owned', 'cases' => [['path' => '/notes/{n1}/opened', 'body' => []]]],
			'PUT /notes/{}/pinned' => ['owned', 'cases' => [['path' => '/notes/{n1}/pinned', 'body' => ['pinned' => true]]]],
			'PUT /notes/{}/content-width' => ['owned', 'cases' => [['path' => '/notes/{n1}/content-width', 'body' => ['content_width' => 80]]]],
			'PUT /notes/{}/title-style' => ['owned', 'cases' => [['path' => '/notes/{n1}/title-style', 'body' => ['title_color' => '#112233', 'title_size' => 24]]]],
			'POST /notes/{}/folder' => ['owned', 'cases' => [
				['path' => '/notes/{n1}/folder', 'body' => ['folder_id' => '{f2}', 'workspace' => '{ws}']],
				['path' => '/notes/{n1}/folder', 'body' => ['parent_id' => '{f2}']],
			]],
			'POST /notes/{}/remove-folder' => ['owned', 'cases' => [['path' => '/notes/{n1}/remove-folder', 'query' => $wsQ, 'body' => [], 'hints' => $hq]]],
			'POST /notes/{}/archive' => ['owned', 'cases' => [['path' => '/notes/{n1}/archive', 'body' => []]]],
			'POST /notes/{}/kanban-completed' => ['owned', 'cases' => [['path' => '/notes/{n1}/kanban-completed', 'body' => ['completed' => true]]]],
			// Ordner
			'GET /folders/counts' => ['scoped', 'path' => '/folders/counts', 'sees' => null],
			'GET /folders/suggested' => ['scoped', 'path' => '/folders/suggested', 'sees' => null],
			'POST /folders/move-files' => ['owned', 'cases' => [
				['path' => '/folders/move-files', 'body' => ['source_folder_id' => '{f1}', 'target_folder_id' => '{f2}', 'workspace' => '{ws}'], 'hints' => ['body.workspace']],
				['path' => '/folders/move-files', 'body' => ['source_folder_id' => '{f1}', 'target_folder_id' => '{f2}', 'workspace' => '{ws}', 'target_workspace' => '{ws}'], 'hints' => ['body.workspace']],
			]],
			'POST /folders/reorder' => ['owned', 'cases' => [
				['path' => '/folders/reorder', 'body' => ['folder_id' => '{f2}', 'target_folder_id' => '{f1}', 'position' => 'before', 'workspace' => '{ws}'], 'hints' => ['body.workspace']],
			]],
			'POST /folders/restore' => ['owned', 'cases' => [
				['path' => '/folders/restore', 'body' => [
					'workspace' => '{ws}', 'folder_id' => 900000001,
					'folders' => [['id' => 900000001, 'name' => 'Wieder', 'parent_id' => '{f1}']],
					'notes' => [['id' => '{n3}', 'folder_id' => 900000001]],
				]],
			]],
			'GET /folders' => ['scoped', 'path' => '/folders', 'sees' => 'f1'],
			'GET /folders/{}' => ['owned', 'cases' => [['path' => '/folders/{f1}', 'query' => $wsQ, 'hints' => $hq]]],
			'POST /folders' => ['owned', 'cases' => [
				['path' => '/folders', 'body' => ['folder_name' => 'Neu', 'workspace' => '{ws}']],
				['path' => '/folders', 'body' => ['folder_name' => 'Neu', 'workspace' => '{ws}', 'parent_id' => '{f1}']],
				['path' => '/folders', 'body' => ['folder_name' => 'Neu', 'workspace' => '{ws}', 'parent_folder_id' => '{f1}']],
			]],
			'PATCH /folders/{}' => ['owned', 'cases' => [['path' => '/folders/{f1}', 'body' => ['name' => 'Umbenannt', 'workspace' => '{ws}'], 'hints' => ['body.workspace']]]],
			'DELETE /folders/{}' => ['owned', 'cases' => [['path' => '/folders/{f2}', 'query' => $wsQ, 'body' => [], 'hints' => $hq]]],
			'POST /folders/{}/move' => ['owned', 'cases' => [
				['path' => '/folders/{f2}/move', 'body' => ['new_parent_folder_id' => '{f1}', 'workspace' => '{ws}'], 'hints' => ['body.workspace']],
				['path' => '/folders/{f2}/move', 'body' => ['new_parent_id' => '{f1}']],
				['path' => '/folders/{f2}/move', 'body' => ['parent_id' => '{f1}']],
				['path' => '/folders/{f2}/move', 'body' => ['target_workspace' => '{ws}']],
			]],
			'POST /folders/{}/archive' => ['owned', 'cases' => [['path' => '/folders/{f2}/archive', 'body' => []]]],
			'POST /folders/{}/empty' => ['owned', 'cases' => [['path' => '/folders/{f1}/empty', 'query' => $wsQ, 'body' => [], 'hints' => $hq]]],
			'POST /folders/{}/tags' => ['owned', 'cases' => [['path' => '/folders/{f1}/tags', 'body' => ['tags' => ['neu'], 'workspace' => '{ws}'], 'hints' => ['body.workspace']]]],
			'DELETE /folders/{}/tags' => ['owned', 'cases' => [['path' => '/folders/{f1}/tags', 'body' => ['tags' => ['{tag}'], 'workspace' => '{ws}'], 'hints' => ['body.workspace', 'body.tags.0']]]],
			'PUT /folders/{}/icon' => ['owned', 'cases' => [['path' => '/folders/{f1}/icon', 'body' => ['icon' => 'lucide-star', 'icon_color' => '#ff0000']]]],
			'PUT /folders/{}/color' => ['owned', 'cases' => [['path' => '/folders/{f1}/color', 'body' => ['color' => '#00ff00']]]],
			'PUT /folders/{}/pinned' => ['owned', 'cases' => [['path' => '/folders/{f1}/pinned', 'body' => ['pinned' => true]]]],
			'PUT /folders/{}/favorite' => ['owned', 'cases' => [['path' => '/folders/{f1}/favorite', 'body' => ['favorite' => true]]]],
			'GET /folders/{}/notes' => ['owned', 'cases' => [['path' => '/folders/{f1}/notes', 'query' => $wsQ, 'hints' => $hq]]],
			'GET /folders/{}/path' => ['owned', 'cases' => [['path' => '/folders/{f1}/path', 'query' => $wsQ, 'hints' => $hq]]],
			// Papierkorb
			'GET /trash' => ['scoped', 'path' => '/trash', 'sees' => 'n3'],
			'DELETE /trash' => ['owned', 'cases' => [['path' => '/trash', 'query' => $wsQ, 'body' => []]]],
			'DELETE /trash/{}' => ['owned', 'cases' => [['path' => '/trash/{n3}', 'query' => $wsQ, 'body' => [], 'hints' => $hq]]],
			// Workspaces
			'GET /workspaces' => ['scoped', 'path' => '/workspaces', 'sees' => 'ws'],
			'POST /workspaces' => ['scoped-write', 'path' => '/workspaces', 'body' => ['name' => 'Neu Iso'], 'reason' => 'keine Objekt-ID; legt nur bei B an'],
			'PATCH /workspaces/{}' => ['owned', 'cases' => [['path' => '/workspaces/{ws}', 'body' => ['new_name' => 'Umbenannt Iso']]]],
			'DELETE /workspaces/{}' => ['owned', 'cases' => [['path' => '/workspaces/{ws}', 'body' => []]]],
			// Tags: {tag} ist ein Name, kein Objekt (Plan M2c: SCOPED); Wirkung nur im Workspace-Scope des Aufrufers
			'GET /tags' => ['scoped', 'path' => '/tags', 'sees' => 'tag'],
			'PATCH /tags/{}' => ['owned', 'cases' => [
				['path' => '/tags/{tag}', 'body' => ['new_name' => 'umbenannt', 'workspace' => '{ws}'], 'hints' => ['path.tag']],
				['path' => '/tags/{tag}', 'body' => ['new_name' => 'umbenannt'], 'hints' => ['path.tag']],
			]],
			'DELETE /tags/{}' => ['owned', 'cases' => [
				['path' => '/tags/{tag}', 'query' => $wsQ, 'body' => [], 'hints' => ['path.tag']],
				['path' => '/tags/{tag}', 'body' => [], 'hints' => ['path.tag']],
			]],
		];
	}

	// ---- Fixtures ----

	/** Legt bei $c einen vollständigen Satz Objekte an; alle Namen tragen $marker */
	private static function seed(ApiClient $c, string $marker, int $gen): array {
		$ws = "Iso $marker $gen";
		self::ok($c->call('POST', '/workspaces', ['name' => $ws]), "seed ws {$c->uid}");
		$f1 = (int)self::ok($c->call('POST', '/folders', ['folder_name' => "F1 $marker", 'workspace' => $ws]), 'seed f1')['folder_id'];
		$f2 = (int)self::ok($c->call('POST', '/folders', ['folder_name' => "F2 $marker", 'workspace' => $ws]), 'seed f2')['folder_id'];
		$n1 = (int)self::ok($c->call('POST', '/notes', ['heading' => "N1 $marker", 'content' => "<p>$marker</p>", 'tags' => $marker, 'workspace' => $ws, 'folder_id' => $f1]), 'seed n1')['note']['id'];
		$n2 = (int)self::ok($c->call('POST', '/notes', ['heading' => "N2 $marker", 'content' => "<p>$marker</p>", 'workspace' => $ws, 'folder_id' => $f1]), 'seed n2')['note']['id'];
		$n3 = (int)self::ok($c->call('POST', '/notes', ['heading' => "N3 $marker", 'content' => "<p>$marker</p>", 'workspace' => $ws]), 'seed n3')['note']['id'];
		self::ok($c->call('DELETE', "/notes/$n3" . self::q(['workspace' => $ws])), 'seed n3 trash');
		return ['ws' => $ws, 'f1' => $f1, 'f2' => $f2, 'n1' => $n1, 'n2' => $n2, 'n3' => $n3, 'tag' => $marker];
	}

	// ---- Slots und Varianten ----

	/** @return array<string, string> Slot-ID → Platzhaltername */
	public static function slots(array $case): array {
		$out = [];
		preg_match_all('/\{(\w+)\}/', $case['path'], $m);
		foreach ($m[1] as $name) {
			$out['path.' . $name] = $name;
		}
		foreach ($case['query'] ?? [] as $k => $v) {
			if (is_string($v) && preg_match('/^\{(\w+)\}$/', $v, $mm)) {
				$out['query.' . $k] = $mm[1];
			}
		}
		$walk = static function ($v, string $prefix) use (&$walk, &$out): void {
			if (is_array($v)) {
				foreach ($v as $k => $x) {
					$walk($x, $prefix . '.' . $k);
				}
			} elseif (is_string($v) && preg_match('/^\{(\w+)\}$/', $v, $mm)) {
				$out[$prefix] = $mm[1];
			}
		};
		$walk($case['body'] ?? null, 'body');
		return $out;
	}

	/** @return list<array<string, string>> je Variante Slot-ID → 'A'|'B' */
	public static function variants(array $slots): array {
		$ids = array_keys($slots);
		$all = array_fill_keys($ids, 'A');
		$out = [$all];
		if (count($ids) > 1) {
			foreach ($ids as $only) {
				$v = array_fill_keys($ids, 'B');
				$v[$only] = 'A';
				$out[] = $v;
			}
		}
		return $out;
	}

	/** @return array{0: string, 1: ?array, 2: string[]} Pfad+Query, Body, eingesetzte A-Zeichenketten */
	private static function build(array $case, array $owner, array $fx): array {
		$echo = [];
		$val = static function (string $slot, string $name) use ($owner, $fx, &$echo) {
			$who = $owner[$slot];
			$v = $fx[$who][$name];
			if ($who === 'A' && is_string($v)) {
				$echo[] = $v;
			}
			return $v;
		};
		$path = preg_replace_callback('/\{(\w+)\}/', static fn ($m) => rawurlencode((string)$val('path.' . $m[1], $m[1])), $case['path']);
		$query = [];
		foreach ($case['query'] ?? [] as $k => $v) {
			$query[$k] = (is_string($v) && preg_match('/^\{(\w+)\}$/', $v, $mm)) ? $val('query.' . $k, $mm[1]) : $v;
		}
		$walk = static function ($v, string $prefix) use (&$walk, $val) {
			if (is_array($v)) {
				$o = [];
				foreach ($v as $k => $x) {
					$o[$k] = $walk($x, $prefix . '.' . $k);
				}
				return $o;
			}
			return (is_string($v) && preg_match('/^\{(\w+)\}$/', $v, $mm)) ? $val($prefix, $mm[1]) : $v;
		};
		$body = array_key_exists('body', $case) && $case['body'] !== null ? $walk($case['body'], 'body') : null;
		return [$path . self::q($query), $body === [] ? null : $body, $echo];
	}

	// ---- Detektoren (geben Fehlertext zurück, '' = ok) ----

	public static function leak(Response $r, string $marker, array $echo): string {
		$body = $r->body;
		foreach ($echo as $e) {
			$body = str_replace([$e, json_encode($e), rawurlencode($e)], '', $body);
		}
		return stripos($body, $marker) !== false ? 'A-Marker in Antwort: ' . $r->describe() : '';
	}

	public static function refused(Response $r): string {
		return in_array($r->status, [403, 404], true) ? '' : 'erwartet 403/404, erhalten ' . $r->describe();
	}

	public static function control(Response $r): string {
		return $r->ok() ? '' : 'Kontrolle nicht 2xx (inconclusive): ' . $r->describe();
	}

	/** B's Zeilen dürfen nie auf A's Objekte zeigen (Workspace, Ordner, Notiz) */
	public static function crossRefs(string $bUid, string $aUid): string {
		$a = self::dump($aUid);
		$b = self::dump($bUid);
		$ids = static fn (array $rows) => array_map(static fn ($r) => (string)$r['id'], $rows);
		$aWs = $ids($a['workspaces']);
		$aFolders = $ids($a['folders']);
		$aNotes = $ids($a['notes']);
		$hits = [];
		foreach ($b['notes'] as $n) {
			foreach (['workspace_id' => $aWs, 'folder_id' => $aFolders, 'linked_note_id' => $aNotes] as $col => $set) {
				if ($n[$col] !== null && in_array($n[$col], $set, true)) {
					$hits[] = "B-Notiz {$n['id']}.$col → A {$n[$col]}";
				}
			}
		}
		foreach ($b['folders'] as $f) {
			foreach (['workspace_id' => $aWs, 'parent_id' => $aFolders] as $col => $set) {
				if ($f[$col] !== null && in_array($f[$col], $set, true)) {
					$hits[] = "B-Ordner {$f['id']}.$col → A {$f[$col]}";
				}
			}
		}
		return $hits === [] ? '' : 'Querverweis B → A: ' . implode(', ', $hits);
	}

	// ---- Tests ----

	public static function routeProvider(): array {
		$out = [];
		foreach (self::routeTable() as $route => $entry) {
			$out[$route] = [$route, $entry];
		}
		return $out;
	}

	public function testRouteTableCoversEveryApiRoute(): void {
		$file = Server::get(IAppManager::class)->getAppPath('nextnotes') . '/appinfo/routes.php';
		$routes = (static fn () => require $file)();
		$table = self::routeTable();
		$errors = [];
		foreach ($routes['routes'] as $r) {
			if (!str_starts_with($r['url'], '/api/v1/')) {
				continue;
			}
			$verb = strtoupper($r['verb'] ?? 'GET');
			$key = $verb . ' ' . preg_replace('/\{[^}]+\}/', '{}', substr($r['url'], 7));
			if (!isset($table[$key])) {
				$errors[] = "$key: kein Eintrag in routeTable()";
				continue;
			}
			$e = $table[$key];
			if (!in_array($e[0], self::KINDS, true)) {
				$errors[] = "$key: unbekannte Klasse";
			}
			if ($e[0] === 'owned') {
				foreach ($e['cases'] as $i => $case) {
					if ($verb !== 'GET' && !array_key_exists('body', $case)) {
						$errors[] = "$key Fall $i: Schreibroute ohne Body-Deklaration";
					}
					if (self::slots($case) === []) {
						$errors[] = "$key Fall $i: keine ID-Stelle";
					}
					foreach ($case['hints'] ?? [] as $h) {
						if (!isset(self::slots($case)[$h])) {
							$errors[] = "$key Fall $i: Hinweis $h ist keine ID-Stelle";
						}
					}
				}
			} elseif ($verb !== 'GET' && (!array_key_exists('body', $e) || trim((string)($e['reason'] ?? '')) === '')) {
				$errors[] = "$key: Schreibroute ohne ID-Stellen braucht body + reason";
			}
		}
		self::assertSame([], $errors);
		self::assertCount(53, $table);
	}

	public function testDetectorsObject(): void {
		$marker = 'amkdetector';
		self::assertNotSame('', self::leak(new Response(404, '{"error":"N1 amkdetector"}', []), $marker, []), 'Leck nicht erkannt');
		self::assertSame('', self::leak(new Response(404, '{"error":"Workspace Iso amkdetector 1 not found"}', []), $marker, ['Iso amkdetector 1']), 'Echo fälschlich als Leck');
		self::assertNotSame('', self::leak(new Response(404, '{"a":"Iso amkdetector 1","b":"F1 amkdetector"}', []), $marker, ['Iso amkdetector 1']), 'Leck neben Echo nicht erkannt');
		self::assertNotSame('', self::refused(new Response(200, '{}', [])), 'Erfolg nicht erkannt');
		self::assertNotSame('', self::refused(new Response(400, '{}', [])), '400 als Ablehnung gewertet');
		self::assertNotSame('', self::control(new Response(404, '{}', [])), 'gescheiterte Kontrolle nicht erkannt');
		self::assertCount(4, self::variants(['a' => 'n1', 'b' => 'f1', 'c' => 'ws']));
	}

	#[DataProvider('routeProvider')]
	public function testRoute(string $route, array $entry): void {
		$a = $this->createUser('iso-a');
		$b = $this->createUser('iso-b');
		$aMark = 'amk' . bin2hex(random_bytes(5));
		$bMark = 'bmk' . bin2hex(random_bytes(5));
		$fx = ['A' => self::seed($a, $aMark, 1), 'B' => self::seed($b, $bMark, 1)];
		$errors = [];
		match ($entry[0]) {
			'owned' => $this->owned($route, $entry, $a, $b, $aMark, $bMark, $fx, $errors),
			'scoped' => $this->scoped($route, $entry, $b, $aMark, $bMark, $fx, $errors),
			'scoped-write' => $this->scopedWrite($route, $entry, $a, $b, $aMark, $errors),
		};
		self::assertSame([], $errors, $route);
	}

	private function owned(string $route, array $entry, ApiClient $a, ApiClient $b, string $aMark, string $bMark, array $fx, array &$errors): void {
		$method = explode(' ', $route)[0];
		$gen = 2;
		foreach ($entry['cases'] as $ci => $case) {
			$slots = self::slots($case);
			$hints = $case['hints'] ?? [];
			$before = self::dump($a->uid);
			foreach (self::variants($slots) as $owner) {
				[$url, $body, $echo] = self::build($case, $owner, $fx);
				$r = $b->call($method, $url, $body);
				$label = "Fall $ci " . json_encode($owner) . " $method $url " . json_encode($body);
				$strict = array_filter(array_keys($owner), static fn ($s) => $owner[$s] === 'A' && !in_array($s, $hints, true)) !== [];
				if ($strict && ($e = self::refused($r)) !== '') {
					$errors[] = "$label: $e";
				}
				if (!$strict && $r->status >= 500) {
					$errors[] = "$label: Serverfehler " . $r->describe();
				}
				if (($e = self::leak($r, $aMark, $echo)) !== '') {
					$errors[] = "$label: $e";
				}
				if (self::dump($a->uid) !== $before) {
					$errors[] = "$label: A-Daten verändert";
					$before = self::dump($a->uid);
				}
				if (($e = self::crossRefs($b->uid, $a->uid)) !== '') {
					$errors[] = "$label: $e";
				}
			}
			// Kontrolle A mit A's Objekten
			$allA = array_fill_keys(array_keys($slots), 'A');
			[$url, $body] = self::build($case, $allA, $fx);
			if (($e = self::control($a->call($method, $url, $body))) !== '') {
				$errors[] = "Fall $ci Kontrolle A $method $url: $e";
			}
			// Kontrolle B mit frischen B-Objekten (Angriffe könnten B's Satz berührt haben)
			$fxB = $fx;
			$fxB['B'] = self::seed($b, $bMark, $gen++);
			$allB = array_fill_keys(array_keys($slots), 'B');
			[$url, $body] = self::build($case, $allB, $fxB);
			if (($e = self::control($b->call($method, $url, $body))) !== '') {
				$errors[] = "Fall $ci Kontrolle B $method $url: $e";
			}
			// nächster Fall: frische Sätze für beide
			if ($method !== 'GET' && $ci < count($entry['cases']) - 1) {
				$fx = ['A' => self::seed($a, $aMark, $gen++), 'B' => self::seed($b, $bMark, $gen++)];
			}
		}
	}

	private function scoped(string $route, array $entry, ApiClient $b, string $aMark, string $bMark, array $fx, array &$errors): void {
		$variants = [
			'ohne Filter' => [],
			'workspace=A' => ['workspace' => $fx['A']['ws']],
			'folder_id=A' => ['folder_id' => $fx['A']['f1']],
			'workspace=B, folder_id=A' => ['workspace' => $fx['B']['ws'], 'folder_id' => $fx['A']['f1']],
			'note_id=A' => ['note_id' => $fx['A']['n1']],
		];
		foreach ($variants as $label => $query) {
			$r = $b->call('GET', $entry['path'] . self::q($query));
			if ($r->status >= 500 || !in_array($r->status, [200, 400, 403, 404], true)) {
				$errors[] = "$label: unerwarteter Status " . $r->describe();
			}
			if (($e = self::leak($r, $aMark, [$fx['A']['ws']])) !== '') {
				$errors[] = "$label: $e";
			}
		}
		// Kontrolle: B sieht im eigenen Workspace die eigenen Daten (Detektor greift tatsächlich)
		$r = $b->call('GET', $entry['path'] . self::q(['workspace' => $fx['B']['ws']]));
		if (($e = self::control($r)) !== '') {
			$errors[] = "Kontrolle B: $e";
		} elseif ($entry['sees'] !== null) {
			$needle = (string)$fx['B'][$entry['sees']];
			if (!in_array($entry['sees'], ['ws', 'tag'], true)) {
				$needle = $bMark;
			}
			if (stripos($r->body, $needle) === false) {
				$errors[] = "Kontrolle B: eigene Daten ($needle) fehlen: " . $r->describe();
			}
		}
	}

	private function scopedWrite(string $route, array $entry, ApiClient $a, ApiClient $b, string $aMark, array &$errors): void {
		$method = explode(' ', $route)[0];
		$before = self::dump($a->uid);
		$r = $b->call($method, $entry['path'], $entry['body']);
		if (($e = self::control($r)) !== '') {
			$errors[] = "B: $e";
		}
		if (($e = self::leak($r, $aMark, [])) !== '') {
			$errors[] = $e;
		}
		if (self::dump($a->uid) !== $before) {
			$errors[] = 'A-Daten verändert';
		}
	}
}
