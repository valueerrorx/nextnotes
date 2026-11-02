<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Search;

use OCA\NextNotes\Search\NotesProvider;
use OCA\NextNotes\Service\AccessService;
use OCA\NextNotes\Service\WorkspaceService;
use OCA\NextNotes\Tests\Support\ApiTestCase;
use OCP\IUserManager;
use OCP\Search\ISearchQuery;
use OCP\Server;

// SPEC §7, §13 M2.7 (Teil Suche), Plan Done M2b 4: nur lesbare Notizen, ohne Papierkorb, Paginierung, OCS-Weg
final class UnifiedSearchTest extends ApiTestCase {
	/** @return array<string, mixed> SearchResult als JSON-Struktur */
	private function search(string $uid, string $term, int $limit = 20, int|string|null $cursor = null): array {
		$q = $this->createStub(ISearchQuery::class);
		$q->method('getTerm')->willReturn($term);
		$q->method('getLimit')->willReturn($limit);
		$q->method('getCursor')->willReturn($cursor);
		$q->method('getRoute')->willReturn('files.view.index');
		$q->method('getRouteParameters')->willReturn([]);
		$user = Server::get(IUserManager::class)->get($uid);
		self::assertNotNull($user);
		Server::get(AccessService::class)->resetCache();
		$res = Server::get(NotesProvider::class)->search($user, $q)->jsonSerialize();
		return json_decode(json_encode($res, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
	}

	/** @return int[] Notiz-IDs aus den Einträgen (Link …?note=<id>) */
	private static function ids(array $res): array {
		$ids = [];
		foreach ($res['entries'] as $e) {
			self::assertMatchesRegularExpression('#/index\.php\?note=(\d+)&workspace=#', $e['resourceUrl'], 'Link-Format §7');
			preg_match('#[?&]note=(\d+)#', $e['resourceUrl'], $m);
			$ids[] = (int)$m[1];
		}
		return $ids;
	}

	public function testOnlyReadableNotesPaginatedWithoutTrash(): void {
		$a = $this->createUser('ua');
		$b = $this->createUser('ub');
		$mark = 'Uniq' . bin2hex(random_bytes(4));
		[$ws, $wsId] = self::newWorkspace($a, 'Such');
		$own = [];
		for ($i = 1; $i <= 5; $i++) {
			$own[] = self::newNote($a, $ws, "A$i Titel", "<p>Text $mark Élan nummer $i</p>");
		}
		$trashed = self::newNote($a, $ws, 'A Papierkorb', "<p>$mark Élan</p>");
		self::ok($a->call('DELETE', "/notes/$trashed"), 'in Papierkorb');
		[$wsB] = self::newWorkspace($b, 'SuchB');
		$bNote = self::newNote($b, $wsB, 'B eigene', "<p>$mark Élan von B</p>");

		// A: alle eigenen, nicht im Papierkorb, nicht die von B; Akzent und Groß/Klein egal
		$all = $this->search($a->uid, strtoupper($mark) . ' ');
		self::assertEqualsCanonicalizing($own, self::ids($all));
		$accent = $this->search($a->uid, 'ELAN');
		self::assertEqualsCanonicalizing($own, array_values(array_intersect(self::ids($accent), array_merge($own, [$trashed, $bNote]))));
		self::assertNotContains($trashed, self::ids($accent));

		// Paginierung über Cursor: 2 + 2 + 1, keine Doppelten
		$seen = [];
		$cursor = null;
		$pages = 0;
		do {
			$res = $this->search($a->uid, $mark, 2, $cursor);
			self::assertTrue($res['isPaginated'], 'paginiertes Ergebnis');
			self::assertLessThanOrEqual(2, count($res['entries']));
			$seen = array_merge($seen, self::ids($res));
			$cursor = $res['cursor'];
			$pages++;
		} while ($res['entries'] !== [] && $pages < 10);
		self::assertSame(4, $pages, 'Seiten 2+2+1+leer');
		self::assertSame(count($seen), count(array_unique($seen)), 'Doppelte über Seiten');
		self::assertEqualsCanonicalizing($own, $seen);

		// B: nur die eigene, keine von A
		self::assertSame([$bNote], self::ids($this->search($b->uid, $mark)));
		self::assertSame([], self::ids($this->search($b->uid, 'A3 Titel')));

		// Freigabe → B sieht A's Notizen (Kontrolle); nach Entzug wieder nicht
		$svc = Server::get(WorkspaceService::class);
		$svc->share($a->uid, $wsId, $b->uid);
		self::assertEqualsCanonicalizing(array_merge($own, [$bNote]), self::ids($this->search($b->uid, $mark)));
		$svc->unshare($a->uid, $wsId, $b->uid);
		self::assertSame([$bNote], self::ids($this->search($b->uid, $mark)));

		// OCS-Weg (Unified Search API) als B: keine A-Treffer; als A: Kontrolle
		$path = '/ocs/v2.php/search/providers/nextnotes/search?format=json&term=' . rawurlencode('A2 Titel');
		$rb = $b->absolute('GET', $path);
		self::assertSame(200, $rb->status, $rb->describe());
		self::assertSame([], $rb->json()['ocs']['data']['entries'] ?? null, 'B erhält A-Notiz über OCS');
		$ra = $a->absolute('GET', $path);
		self::assertSame(200, $ra->status, $ra->describe());
		self::assertCount(1, $ra->json()['ocs']['data']['entries'] ?? [], 'A findet eigene Notiz über OCS');
		self::assertStringNotContainsString('A2 Titel', $b->absolute('GET', '/ocs/v2.php/search/providers/nextnotes/search?format=json&term=' . rawurlencode($mark))->body);
	}

	public function testLikeWildcardsAreLiteral(): void {
		$a = $this->createUser('uw');
		$b = $this->createUser('ux');
		[$ws] = self::newWorkspace($a, 'Wild');
		self::newNote($a, $ws, 'Geheim', '<p>nichts</p>');
		[$wsB] = self::newWorkspace($b, 'WildB');
		self::newNote($b, $wsB, 'Eigen', '<p>nichts</p>');
		foreach (['%', '_', '%%'] as $term) {
			$res = $this->search($b->uid, $term);
			foreach ($res['entries'] as $e) {
				self::assertNotSame('Geheim', $e['title'], "Platzhalter $term");
			}
		}
	}
}
