<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\OneNote;

// M9b: Graph im Prozess (Notizbücher, Abschnitte, Seiten mit Paging, Teams, Fehler je Pfad, Zähler je Pfad); M9d: Ressourcen
final class FakeGraph {
	public const PREFIX = 'https://graph.microsoft.com/v1.0/';

	/** @var array<string, list<array<string, mixed>>> Wurzel (me | groups/{id}) → Notizbücher mit sections/sectionGroups, Abschnitte mit pages */
	public array $notebooks = [];
	/** @var list<array{id: string, displayName: string}> */
	public array $teams = [];
	// joinedTeams liefert immer einen nextLink (Liste bricht an der Anfragegrenze ab)
	public bool $teamsEndless = false;
	/** @var array<string, int|list<int>> Pfad → Status (Liste: je Aufruf der nächste Wert, danach 200) */
	public array $errors = [];
	/** @var array<string, int> Pfad → Aufrufe */
	public array $hits = [];
	/** @var (\Closure(string): void)|null vor jeder Antwort (z. B. Abbruch mitten im Lauf) */
	public ?\Closure $onRequest = null;
	public int $pageSize = 100;
	/** @var array<string, string|FakeResponse> Ressourcen-ID → Inhalt (200) oder ganze Antwort */
	public array $resources = [];

	public function count(string $prefix = ''): int {
		$n = 0;
		foreach ($this->hits as $path => $c) {
			$n += str_starts_with($path, $prefix) ? $c : 0;
		}
		return $n;
	}

	public function respond(string $url): FakeResponse {
		$path = substr((string)parse_url($url, PHP_URL_PATH), strlen('/v1.0/'));
		parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
		$this->hits[$path] = ($this->hits[$path] ?? 0) + 1;
		if ($this->onRequest !== null) {
			($this->onRequest)($path);
		}
		if (isset($this->errors[$path])) {
			$e = $this->errors[$path];
			$status = is_array($e) ? array_shift($this->errors[$path]) : $e;
			if (is_array($e) && $this->errors[$path] === []) {
				unset($this->errors[$path]);
			}
			if ($status !== null && $status !== 200) {
				return FakeHttp::json($status, ['error' => ['code' => (string)$status, 'message' => 'fake']]);
			}
		}
		if ($path === 'me/joinedTeams') {
			return FakeHttp::json(200, ['value' => $this->teams] + ($this->teamsEndless ? ['@odata.nextLink' => self::PREFIX . 'me/joinedTeams?$skiptoken=x'] : []));
		}
		if (preg_match('#^(me|groups/[^/]+)/onenote/notebooks$#', $path, $m)) {
			$out = [];
			foreach ($this->notebooks[$m[1]] ?? [] as $nb) {
				$out[] = ['id' => $nb['id'], 'displayName' => $nb['displayName'],
					'sections' => array_map(static fn ($s) => ['id' => $s['id'], 'displayName' => $s['displayName']], $nb['sections'] ?? []),
					'sectionGroups' => array_map(static fn ($g) => ['id' => $g['id'], 'displayName' => $g['displayName']], $nb['sectionGroups'] ?? [])];
			}
			return FakeHttp::json(200, ['value' => $out]);
		}
		if (preg_match('#^(me|groups/[^/]+)/onenote/notebooks/([^/]+)$#', $path, $m)) {
			$nb = $this->notebook($m[1], rawurldecode($m[2]));
			return $nb === null ? self::notFound() : FakeHttp::json(200, self::strip($nb));
		}
		if (preg_match('#^(me|groups/[^/]+)/onenote/sections/([^/]+)/pages$#', $path, $m)) {
			$section = $this->section($m[1], rawurldecode($m[2]));
			if ($section === null) {
				return self::notFound();
			}
			$skip = (int)($q['$skip'] ?? 0);
			$top = min((int)($q['$top'] ?? 20), $this->pageSize);
			$pages = $section['pages'] ?? [];
			$slice = array_map(static fn ($p) => ['id' => $p['id'], 'title' => $p['title'] ?? '', 'level' => $p['level'] ?? 0, 'order' => $p['order'] ?? 0,
				'createdDateTime' => $p['created'] ?? '2024-01-02T03:04:05Z', 'lastModifiedDateTime' => $p['modified'] ?? '2024-02-03T04:05:06Z'],
				array_slice($pages, $skip, $top));
			$data = ['value' => $slice];
			if ($skip + $top < count($pages)) {
				$data['@odata.nextLink'] = self::PREFIX . $m[1] . '/onenote/sections/' . $m[2] . '/pages?pagelevel=true&$top=' . $top . '&$skip=' . ($skip + $top);
			}
			return FakeHttp::json(200, $data);
		}
		if (preg_match('#^(me|groups/[^/]+)/onenote/pages/([^/]+)/content$#', $path, $m)) {
			$page = $this->page($m[1], rawurldecode($m[2]));
			return $page === null ? self::notFound()
				: new FakeResponse(200, '<html><head><title>x</title></head><body data-absolute-enabled="true">' . ($page['html'] ?? '<p>Inhalt ' . htmlspecialchars($page['id']) . '</p>') . '</body></html>', ['Content-Type' => 'text/html']);
		}
		if (preg_match('#^(me|groups/[^/]+)/onenote/resources/([^/]+)/\$value$#', $path, $m)) {
			$r = $this->resources[rawurldecode($m[2])] ?? null;
			return $r === null ? self::notFound() : ($r instanceof FakeResponse ? $r : new FakeResponse(200, $r, ['Content-Type' => 'application/octet-stream']));
		}
		return self::notFound();
	}

	// Graph-URL einer Ressource wie im Seiten-HTML (F9)
	public static function resourceUrl(string $id, string $root = 'me'): string {
		return self::PREFIX . $root . '/onenote/resources/' . $id . '/$value';
	}

	// kleines gültiges PNG (Typ laut Inhalt)
	public static function png(int $pad = 0): string {
		return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==') . str_repeat("\0", $pad);
	}

	public static function jpeg(): string {
		return "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00" . str_repeat("\x00", 64) . "\xFF\xD9";
	}

	public static function pdf(): string {
		return "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
	}

	private static function notFound(): FakeResponse {
		return FakeHttp::json(404, ['error' => ['code' => '20102', 'message' => 'fake not found']]);
	}

	/** @return array<string, mixed>|null */
	private function notebook(string $root, string $id): ?array {
		foreach ($this->notebooks[$root] ?? [] as $nb) {
			if ($nb['id'] === $id) {
				return $nb;
			}
		}
		return null;
	}

	/** @return list<array<string, mixed>> alle Abschnitte einer Wurzel, rekursiv */
	private function sections(string $root): array {
		$out = [];
		$walk = function (array $node) use (&$walk, &$out): void {
			foreach ($node['sections'] ?? [] as $s) {
				$out[] = $s;
			}
			foreach ($node['sectionGroups'] ?? [] as $g) {
				$walk($g);
			}
		};
		foreach ($this->notebooks[$root] ?? [] as $nb) {
			$walk($nb);
		}
		return $out;
	}

	/** @return array<string, mixed>|null */
	private function section(string $root, string $id): ?array {
		foreach ($this->sections($root) as $s) {
			if ($s['id'] === $id) {
				return $s;
			}
		}
		return null;
	}

	/** @return array<string, mixed>|null */
	private function page(string $root, string $id): ?array {
		foreach ($this->sections($root) as $s) {
			foreach ($s['pages'] ?? [] as $p) {
				if ($p['id'] === $id) {
					return $p;
				}
			}
		}
		return null;
	}

	/** @param array<string, mixed> $node @return array<string, mixed> ohne Seiten (Struktur-Antwort) */
	private static function strip(array $node): array {
		$out = ['id' => $node['id'], 'displayName' => $node['displayName'], 'lastModifiedDateTime' => '2024-05-06T07:08:09Z'];
		$out['sections'] = array_map(static fn ($s) => ['id' => $s['id'], 'displayName' => $s['displayName']], $node['sections'] ?? []);
		$out['sectionGroups'] = array_map(static fn ($g) => self::strip($g), $node['sectionGroups'] ?? []);
		return $out;
	}

	/** @return list<array<string, mixed>> n Seiten mit Präfix */
	public static function pages(string $prefix, int $n): array {
		$out = [];
		for ($i = 0; $i < $n; $i++) {
			$out[] = ['id' => $prefix . '-' . $i, 'title' => 'Seite ' . $prefix . ' ' . $i, 'order' => $i];
		}
		return $out;
	}
}
