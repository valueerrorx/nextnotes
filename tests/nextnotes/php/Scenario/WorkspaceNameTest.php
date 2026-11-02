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
use OCP\Server;

// Done M2a 5 / SPEC §13 M2.4, §4.4: gleicher Workspace-Name bei zwei Benutzern; Schlüssel-Fallback
final class WorkspaceNameTest extends ApiTestCase {
	private static function wsId(string $uid, string $name): int {
		foreach (self::dump($uid)['workspaces'] as $w) {
			if ($w['name'] === $name) {
				return (int)$w['id'];
			}
		}
		self::fail("Workspace $name von $uid fehlt");
	}

	private static function notesBody(ApiClient $c, string $key): string {
		$r = $c->call('GET', '/notes' . self::q(['workspace' => $key]));
		self::assertSame(200, $r->status, "GET /notes?workspace=$key: " . $r->describe());
		return $r->body;
	}

	public function testSameNameForTwoUsers(): void {
		$a = $this->createUser('wsa');
		$b = $this->createUser('wsb');
		foreach ([$a, $b] as $c) {
			$r = $c->call('POST', '/workspaces', ['name' => 'Schule']);
			self::assertTrue($r->ok(), "{$c->uid} POST Schule: " . $r->describe());
			self::ok($c->call('POST', '/notes', ['heading' => 'Notiz ' . $c->uid, 'content' => '<p>' . $c->uid . '</p>', 'workspace' => 'Schule']), 'Notiz');
		}
		foreach ([$a, $b] as $c) {
			$names = self::workspaceNames($c);
			self::assertSame(1, count(array_keys($names, 'Schule', true)), "{$c->uid} sieht nicht genau einen Workspace Schule: " . json_encode($names));
		}
		self::assertStringNotContainsString($a->uid, self::notesBody($b, 'Schule'));
		self::assertStringNotContainsString($b->uid, self::notesBody($a, 'Schule'));

		$bBefore = self::dump($b->uid);
		self::ok($a->call('PATCH', '/workspaces/Schule', ['new_name' => 'Schule alt']), 'A umbenennen');
		self::assertSame($bBefore, self::dump($b->uid), 'Umbenennen bei A ändert B');
		self::assertContains('Schule', self::workspaceNames($b));
		self::ok($a->call('DELETE', '/workspaces/' . self::enc('Schule alt')), 'A löschen');
		self::assertSame($bBefore, self::dump($b->uid), 'Löschen bei A ändert B');
		self::assertStringContainsString($b->uid, self::notesBody($b, 'Schule'));
	}

	public function testKeyFallbackForEqualOwnerDisplayNames(): void {
		$t1 = $this->createUser('t1', 'Lehrer');
		$t2 = $this->createUser('t2', 'Lehrer');
		$t3 = $this->createUser('t3', 'Anna');
		$b = $this->createUser('kb');
		$svc = Server::get(WorkspaceService::class);
		$ids = [];
		foreach ([$t1, $t2, $t3] as $t) {
			self::ok($t->call('POST', '/workspaces', ['name' => 'Mathe']), 'Mathe');
			self::ok($t->call('POST', '/notes', ['heading' => 'von ' . $t->uid, 'content' => '<p>' . $t->uid . '</p>', 'workspace' => 'Mathe']), 'Notiz');
			$ids[$t->uid] = self::wsId($t->uid, 'Mathe');
			$svc->share($t->uid, $ids[$t->uid], $b->uid);
		}
		// B hat selbst auch „Mathe“
		self::ok($b->call('POST', '/workspaces', ['name' => 'Mathe']), 'B Mathe');

		$r = $b->call('GET', '/workspaces');
		self::assertSame(200, $r->status, $r->describe());
		$names = self::workspaceNames($b);
		$expected = [
			'Mathe',
			'Mathe (Lehrer, #' . $ids[$t1->uid] . ')',
			'Mathe (Lehrer, #' . $ids[$t2->uid] . ')',
			'Mathe (Anna)',
		];
		foreach ($expected as $key) {
			self::assertContains($key, $names, 'Schlüssel fehlt: ' . json_encode($names));
		}

		$check = [
			'Mathe (Lehrer, #' . $ids[$t1->uid] . ')' => $t1->uid,
			'Mathe (Lehrer, #' . $ids[$t2->uid] . ')' => $t2->uid,
			'Mathe (Anna)' => $t3->uid,
			'Mathe (Anna, #' . $ids[$t3->uid] . ')' => $t3->uid,
		];
		foreach ($check as $key => $owner) {
			$body = self::notesBody($b, $key);
			self::assertStringContainsString($owner, $body, "Schlüssel $key liefert nicht den Workspace von $owner");
			foreach ([$t1, $t2, $t3] as $t) {
				if ($t->uid !== $owner) {
					self::assertStringNotContainsString($t->uid, $body, "Schlüssel $key liefert fremden Workspace");
				}
			}
		}
		// Kurzform ist bei doppeltem Anzeigenamen mehrdeutig → nicht 409, sondern 404
		$r = $b->call('GET', '/notes' . self::q(['workspace' => 'Mathe (Lehrer)']));
		self::assertNotSame(409, $r->status);
		// B's eigener „Mathe“ enthält nichts von den Lehrern
		$own = self::notesBody($b, 'Mathe');
		foreach ([$t1, $t2, $t3] as $t) {
			self::assertStringNotContainsString($t->uid, $own);
		}
		// falsche ID in der Langform → 404
		$r = $b->call('GET', '/notes' . self::q(['workspace' => 'Mathe (Lehrer, #999999999)']));
		self::assertSame(404, $r->status, $r->describe());
	}
}
