<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Security;

use OCA\NextNotes\Tests\Support\ApiTestCase;

// Done M2a 8: XSS beim Speichern entfernt; ungültige Farbe/Icon/Namen → 400, nichts gespeichert
final class XssValidationTest extends ApiTestCase {
	private const BAD = '"><img src=x onerror=alert(1)>';

	public function testStoredContentIsSanitized(): void {
		$a = $this->createUser('xss');
		$ws = 'Xss ' . bin2hex(random_bytes(3));
		self::ok($a->call('POST', '/workspaces', ['name' => $ws]), 'Workspace');
		$id = self::ok($a->call('POST', '/notes', ['heading' => 'X', 'content' => '<p>a</p>', 'workspace' => $ws]), 'POST /notes')['note']['id'];
		$payload = '<p>ok</p><script>alert(1)</script><img src="x" onerror="alert(2)"><a href="javascript:alert(3)">l</a>';
		self::ok($a->call('PATCH', "/notes/$id", ['content' => $payload]), 'PATCH');

		$content = self::ok($a->call('GET', "/notes/$id"), 'GET')['note']['content'];
		$stored = self::dump($a->uid)['notes'];
		$row = array_values(array_filter($stored, static fn ($r) => (int)$r['id'] === (int)$id))[0];
		foreach (['ausgeliefert' => $content, 'gespeichert' => $row['content']] as $what => $html) {
			self::assertStringContainsString('ok', $html, $what);
			foreach (['<script', 'onerror', 'javascript:'] as $bad) {
				self::assertStringNotContainsStringIgnoringCase($bad, $html, "$what enthält $bad");
			}
		}

		// Beacon-Speichern läuft durch denselben Sanitizer
		self::ok($a->call('POST', "/notes/$id/beacon", ['content' => '<p>b</p><script>alert(4)</script>']), 'beacon');
		$content = self::ok($a->call('GET', "/notes/$id"), 'GET')['note']['content'];
		self::assertStringNotContainsString('<script', $content);
	}

	// SPEC §11.2 B19: Schema mit Whitespace/Steuerzeichen wird erkannt; normale URLs bleiben
	public function testObfuscatedJavascriptHrefIsRemoved(): void {
		$a = $this->createUser('b19');
		$id = self::ok($a->call('POST', '/notes', ['heading' => 'B19', 'content' => '<p>a</p>']), 'POST /notes')['note']['id'];
		$payloads = [
			'<a href="java&#9;script:alert(1)">t</a>',
			'<a href="java&#10;script:alert(1)">n</a>',
			'<a href="java&#x0D;script:alert(1)">r</a>',
			'<a href=" javascript:alert(1)">s</a>',
			'<img src="java&#9;script:alert(1)">',
		];
		$content = '<p>start</p>' . implode('', $payloads) . '<a href="https://example.org/ok?a=1&amp;b=2">gut</a>';
		self::ok($a->call('PATCH', "/notes/$id", ['content' => $content]), 'PATCH');
		$out = self::ok($a->call('GET', "/notes/$id"), 'GET')['note']['content'];
		$row = array_values(array_filter(self::dump($a->uid)['notes'], static fn ($r) => (int)$r['id'] === (int)$id))[0]['content'];
		foreach (['ausgeliefert' => $out, 'gespeichert' => $row] as $what => $html) {
			$flat = preg_replace('/[\s\x00-\x1f]+/', '', html_entity_decode(html_entity_decode($html, ENT_QUOTES | ENT_HTML5), ENT_QUOTES | ENT_HTML5));
			self::assertStringNotContainsStringIgnoringCase('javascript:', $flat, "$what: $html");
			self::assertStringContainsString('https://example.org/ok', $html, "$what: normale URL fehlt");
		}
	}

	// SPEC §11.2 B20: Apostroph in Tags erlaubt
	public function testApostropheTagAllowed(): void {
		$a = $this->createUser('b20');
		$id = self::ok($a->call('POST', '/notes', ['heading' => 'B20', 'content' => '<p>a</p>']), 'POST /notes')['note']['id'];
		$r = $a->call('PUT', "/notes/$id/tags", ['tags' => "l'été"]);
		self::assertSame(200, $r->status, $r->describe());
		$tags = self::ok($a->call('GET', "/notes/$id"), 'GET')['note']['tags'];
		self::assertStringContainsString("l'été", is_array($tags) ? implode(',', $tags) : (string)$tags);
		self::assertContains("l'été", self::ok($a->call('GET', '/tags'), 'GET /tags')['tags']);
	}

	public function testBeaconOnTrashedNoteIs404(): void {
		$a = $this->createUser('bea');
		$id = self::ok($a->call('POST', '/notes', ['heading' => 'Bea', 'content' => '<p>vorher</p>']), 'POST /notes')['note']['id'];
		self::ok($a->call('DELETE', "/notes/$id"), 'in Papierkorb');
		$before = self::dump($a->uid);
		$r = $a->call('POST', "/notes/$id/beacon", ['content' => '<p>nachher</p>']);
		self::assertSame(404, $r->status, $r->describe());
		self::assertSame($before, self::dump($a->uid), 'Beacon hat Notiz im Papierkorb verändert');
	}

	public function testInvalidInputIs400AndNothingStored(): void {
		$a = $this->createUser('val');
		$ws = 'Val ' . bin2hex(random_bytes(3));
		self::ok($a->call('POST', '/workspaces', ['name' => $ws]), 'Workspace');
		$note = self::ok($a->call('POST', '/notes', ['heading' => 'V', 'content' => '<p>v</p>', 'tags' => 'gut', 'workspace' => $ws]), 'POST /notes')['note']['id'];
		$folder = self::ok($a->call('POST', '/folders', ['folder_name' => 'Ordner', 'workspace' => $ws]), 'POST /folders')['folder_id'];
		$q = self::q(['workspace' => $ws]);

		$cases = [
			['PUT', "/notes/$note/color", ['color' => self::BAD]],
			['PUT', "/notes/$note/color", ['color' => 'red;background:url(javascript:alert(1))']],
			['PUT', "/notes/$note/icon", ['icon' => self::BAD]],
			['PUT', "/notes/$note/icon", ['icon' => 'lucide-star', 'icon_color' => self::BAD]],
			['PUT', "/notes/$note/title-style", ['title_color' => self::BAD]],
			['PUT', "/notes/$note/tags", ['tags' => self::BAD]],
			['PUT', "/folders/$folder/color", ['color' => self::BAD]],
			['PUT', "/folders/$folder/icon", ['icon' => self::BAD]],
			['PUT', "/folders/$folder/icon", ['icon' => 'lucide-star', 'icon_color' => self::BAD]],
			['POST', '/folders', ['folder_name' => self::BAD, 'workspace' => $ws]],
			['PATCH', "/folders/$folder", ['name' => self::BAD, 'workspace' => $ws]],
			['POST', '/workspaces', ['name' => self::BAD]],
			['PATCH', '/workspaces/' . self::enc($ws), ['new_name' => self::BAD]],
			['PATCH', '/tags/gut', ['new_name' => self::BAD, 'workspace' => $ws]],
			['POST', "/folders/$folder/tags", ['tags' => [self::BAD], 'workspace' => $ws]],
		];
		$before = self::dump($a->uid);
		$errors = [];
		foreach ($cases as [$m, $p, $body]) {
			$r = $a->call($m, $p . ($m === 'PATCH' && str_starts_with($p, '/tags') ? $q : ''), $body);
			if ($r->status !== 400) {
				$errors[] = "$m $p " . json_encode($body) . ' → ' . $r->describe();
			}
			if (self::dump($a->uid) !== $before) {
				$errors[] = "$m $p: Daten verändert";
				$before = self::dump($a->uid);
			}
		}
		self::assertSame([], $errors);
		self::assertStringNotContainsString('onerror', json_encode($before));
	}
}
