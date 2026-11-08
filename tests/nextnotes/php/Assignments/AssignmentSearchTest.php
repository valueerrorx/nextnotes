<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Assignments;

use OCA\NextNotes\Search\NotesProvider;
use OCA\NextNotes\Service\AccessService;
use OCA\NextNotes\Tests\Support\ApiClient;
use OCP\IUserManager;
use OCP\Search\ISearchQuery;
use OCP\Server;

// Done M6e2 2 (Plan M6 §2.4, SPEC §7): Schüler finden eigene Abgabe (/notes/search, Unified Search), nie fremde, nie Vorlagen;
// Lehrkraft findet Abgaben nicht global; /changes, Backlinks, /notes/resolve geben S1-Abgabe an S2 nicht heraus
final class AssignmentSearchTest extends AssignmentTestCase {
	/** @return int[] */
	private static function apiIds(ApiClient $c, string $q): array {
		$r = self::ok($c->call('GET', '/notes/search' . self::q(['q' => $q])), "/notes/search $c->uid");
		return array_map(static fn ($x) => (int)$x['id'], $r['results']);
	}

	/** @return int[] Notiz-IDs aus Unified Search (Link …note=<id>) */
	private function unifiedIds(string $uid, string $term): array {
		$q = $this->createStub(ISearchQuery::class);
		$q->method('getTerm')->willReturn($term);
		$q->method('getLimit')->willReturn(50);
		$q->method('getCursor')->willReturn(null);
		$q->method('getRoute')->willReturn('files.view.index');
		$q->method('getRouteParameters')->willReturn([]);
		$user = Server::get(IUserManager::class)->get($uid);
		self::assertNotNull($user);
		Server::get(AccessService::class)->resetCache();
		$res = json_decode(json_encode(Server::get(NotesProvider::class)->search($user, $q)->jsonSerialize(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
		$ids = [];
		foreach ($res['entries'] as $e) {
			self::assertSame(1, preg_match('#[?&]note=(\d+)#', $e['resourceUrl'], $m), 'Link ohne note=: ' . $e['resourceUrl']);
			$ids[] = (int)$m[1];
		}
		return $ids;
	}

	public function testOwnSubmissionsOnlyNeverTemplates(): void {
		$fx = $this->distributedFixture();
		['l' => $l, 'm' => $m, 's1' => $s1, 's2' => $s2, 'mark' => $mark, 'tpl' => $tpl, 'src' => $src] = $fx;
		$c1 = (int)$fx['subs'][$s1->uid]['note_id'];
		$c2 = (int)$fx['subs'][$s2->uid]['note_id'];
		$cx = (int)$fx['subs'][$fx['x']->uid]['note_id'];
		$secret = 'Geheim' . bin2hex(random_bytes(4));
		self::ok($s1->call('PATCH', "/notes/$c1", ['content' => "<p>$secret quelle $mark</p>"]), 'S1 schreibt eigene Abgabe');
		self::assertSame('template', self::row('nextnotes_notes', $tpl)['role'], 'Kontrolle: Vorlage');

		foreach (['api' => fn (ApiClient $c, string $q) => self::apiIds($c, $q), 'unified' => fn (ApiClient $c, string $q) => $this->unifiedIds($c->uid, $q)] as $way => $search) {
			// S1: eigene Abgabe ja (auch per Geheimwort), fremde/Vorlage nie; Team-Notiz der Lehrkraft als Gegenprobe
			$ids = $search($s1, $mark);
			self::assertContains($c1, $ids, "$way: S1 findet eigene Abgabe");
			self::assertContains($src, $ids, "$way: Gegenprobe S1 findet Team-Notiz");
			foreach (['S2-Abgabe' => $c2, 'X-Abgabe' => $cx, 'Vorlage' => $tpl] as $what => $id) {
				self::assertNotContains($id, $ids, "$way: S1 findet $what");
			}
			self::assertSame([$c1], $search($s1, $secret), "$way: S1 per Geheimwort");
			// S2: nur eigene; S1-Geheimwort nie
			$ids = $search($s2, $mark);
			self::assertContains($c2, $ids, "$way: S2 findet eigene Abgabe");
			self::assertNotContains($c1, $ids, "$way: S2 findet S1-Abgabe");
			self::assertNotContains($tpl, $ids, "$way: S2 findet Vorlage");
			self::assertSame([], $search($s2, $secret), "$way: S2 per Geheimwort");
			// Lehrkraft/Moderator: keine Abgaben, keine Vorlage (nur Aufgabenansicht); Gegenprobe Quelle
			foreach ([$l, $m] as $u) {
				$ids = $search($u, $mark);
				self::assertContains($src, $ids, "$way: Gegenprobe $u->uid findet Quelle");
				foreach ([$c1, $c2, $cx, $tpl] as $id) {
					self::assertNotContains($id, $ids, "$way: $u->uid findet Abgabe/Vorlage $id");
				}
				self::assertSame([], $search($u, $secret), "$way: $u->uid per Geheimwort");
			}
		}
	}

	// Indirekte Wege (Invariante filterReadable): S2 erhält nichts zur S1-Abgabe; Gegenprobe eigene Abgabe
	public function testIndirectPathsHideForeignSubmission(): void {
		$fx = $this->distributedFixture();
		['s1' => $s1, 's2' => $s2] = $fx;
		$c1 = (int)$fx['subs'][$s1->uid]['note_id'];
		$c2 = (int)$fx['subs'][$s2->uid]['note_id'];
		$secret = 'Geheim' . bin2hex(random_bytes(4));
		self::ok($s1->call('PATCH', "/notes/$c1", ['content' => "<p>$secret</p>"]), 'S1 schreibt');
		$own = self::ok($s2->call('GET', '/notes/search' . self::q(['q' => $fx['mark']])), 'S2 sucht')['results'];
		$ws2 = null;
		foreach ($own as $r) {
			if ((int)$r['id'] === $c2) {
				$ws2 = $r['workspace'];
			}
		}
		self::assertNotNull($ws2, 'Workspace-Schlüssel der eigenen Abgabe');

		$refused = static function (string $what, $r) use ($secret): void {
			self::assertContains($r->status, [403, 404], "$what: " . $r->describe());
			self::assertStringNotContainsString($secret, $r->body, "$what: Inhalt sichtbar");
		};
		$refused('resolve ID', $s2->call('GET', '/notes/resolve' . self::q(['reference' => (string)$c1])));
		$refused('resolve ID mit eigenem WS', $s2->call('GET', '/notes/resolve' . self::q(['reference' => (string)$c1, 'workspace' => $ws2])));
		$refused('backlinks', $s2->call('GET', "/notes/$c1/backlinks"));
		// Titel: Kopien heißen gleich → S2 erhält höchstens die eigene
		$r = $s2->call('GET', '/notes/resolve' . self::q(['reference' => (string)self::row('nextnotes_notes', $c1)['heading'], 'workspace' => $ws2]));
		self::assertStringNotContainsString($secret, $r->body, 'resolve Titel: S1-Inhalt');
		if ($r->ok()) {
			self::assertSame($c2, (int)$r->json()['id'], 'resolve Titel: nur eigene Abgabe');
		}
		self::assertSame($c1, (int)self::ok($s1->call('GET', '/notes/resolve' . self::q(['reference' => (string)$c1])), 'Gegenprobe S1 resolve')['id']);

		$r = $s2->call('GET', '/changes' . self::q(['workspace' => $ws2, 'note_ids' => "$c1,$c2"]));
		self::assertStringNotContainsString($secret, $r->body, 'changes: S1-Inhalt');
		$notes = self::ok($r, 'changes')['notes'] ?? [];
		self::assertFalse((bool)($notes[(string)$c1]['exists'] ?? false), 'changes: S1-Abgabe für S2 nicht vorhanden ' . json_encode($notes[(string)$c1] ?? null));
		self::assertTrue((bool)($notes[(string)$c2]['exists'] ?? false), 'changes Gegenprobe: eigene Abgabe');
	}
}
