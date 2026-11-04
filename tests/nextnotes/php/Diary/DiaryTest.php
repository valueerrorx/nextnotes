<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Diary;

use OCA\NextNotes\Service\ConversionService;
use OCA\NextNotes\Service\DiaryService;
use OCA\NextNotes\Service\View\TrashViewService;
use OCA\NextNotes\Tests\Support\ApiClient;
use OCA\NextNotes\Tests\Support\ApiTestCase;
use OCP\Server;

// Plan M4 §M4f Done 1–3, 5: Tagebuch-Automatik (Vorlage, Datumsordner), Isolation der fünf Endpunkte, Datumsprüfung, Render-Budget
final class DiaryTest extends ApiTestCase {
	private const APP = '/index.php/apps/nextnotes/';

	/** @return array{0: int, 1: array<string, mixed>} Eintrag für $date wie createDiaryEntryForToday anlegen */
	private static function createEntry(ApiClient $c, string $ws, string $date): array {
		$d = self::ok($c->call('GET', '/calendar/diary-entry.php?' . http_build_query(['date' => $date, 'workspace' => $ws])), 'diary-entry');
		self::assertFalse($d['exists'], "$date: noch kein Eintrag");
		$n = self::ok($c->call('POST', '/notes', ['heading' => $d['title'], 'folder_name' => $d['folder'], 'workspace' => $d['workspace'],
			'type' => $d['noteType'] === 'markdown' ? 'markdown' : 'note', 'created_date' => $date]), 'Eintrag anlegen');
		return [(int)$n['note']['id'], $d];
	}

	private static function folderPath(array $dump, int $folderId): string {
		$byId = array_column($dump['folders'], null, 'id');
		$parts = [];
		for ($f = $byId[(string)$folderId] ?? null; $f !== null; $f = $f['parent_id'] === null ? null : ($byId[$f['parent_id']] ?? null)) {
			array_unshift($parts, $f['name']);
		}
		return implode('/', $parts);
	}

	private static function note(array $dump, int $id): array {
		foreach ($dump['notes'] as $n) {
			if ((int)$n['id'] === $id) {
				return $n;
			}
		}
		self::fail("Notiz $id fehlt");
	}

	public function testTodayEntryUsesTemplateAndDateFolder(): void {
		$a = $this->createUser('da');
		$mark = 'DIARY' . bin2hex(random_bytes(4));
		[$ws, $wsId] = self::newWorkspace($a, 'Tagebuch');
		$tpl = self::newNote($a, $ws, "Vorlage $mark", "<p>Vorlage $mark</p>");
		self::ok($a->call('PUT', '/settings/diary_templates', ['value' => json_encode([(string)$wsId => $tpl])]), 'Vorlage setzen');
		// GET schreibt nicht (B61): ohne Tagebuch kein Ordner, keine Markierung
		$before = count(self::dump($a->uid)['folders']);
		self::ok($a->call('GET', '/diary/diaries.php?workspace=' . self::enc($ws)), 'diaries GET');
		self::assertSame(200, self::get($a, 'diary.php?workspace=' . self::enc($ws))->status, 'diary.php');
		self::assertCount($before, self::dump($a->uid)['folders'], 'B61: GET legt nichts an');

		[$id, $d] = self::createEntry($a, $ws, '2026-03-14');
		$dump = self::dump($a->uid);
		$row = self::note($dump, $id);
		self::assertStringContainsString("Vorlage $mark", (string)$row['content'], 'Vorlage übernommen');
		self::assertSame($d['folder'], self::folderPath($dump, (int)$row['folder_id']), 'Datumsordner Tagebuch/JJJJ/MM');
		$root = explode('/', $d['folder'])[0];
		$rootRow = array_values(array_filter($dump['folders'], static fn ($f) => $f['name'] === $root && $f['parent_id'] === null))[0];
		self::assertSame('1', $rootRow['is_diary'], 'B61: erster Eintrag markiert das Tagebuch');
		$again = self::ok($a->call('GET', '/calendar/diary-entry.php?date=2026-03-14&workspace=' . self::enc($ws)), 'diary-entry 2');
		self::assertTrue($again['exists']);
		self::assertSame($id, $again['id']);
		self::assertSame([['id' => (int)$rootRow['id'], 'name' => $root]], self::ok($a->call('GET', '/diary/diaries.php?workspace=' . self::enc($ws)), 'diaries')['diaries']);

		// Titel auf ein anderes Datum → Ordner des Monats (bei Bedarf angelegt)
		$title = self::ok($a->call('GET', '/calendar/diary-entry.php?date=2026-05-02&workspace=' . self::enc($ws)), 'Titel Mai')['title'];
		self::ok($a->call('PATCH', "/notes/$id", ['heading' => $title, 'workspace' => $ws]), 'umbenennen');
		$dump = self::dump($a->uid);
		self::assertSame("$root/2026/05", self::folderPath($dump, (int)self::note($dump, $id)['folder_id']), 'Umbenennung verschiebt');
		// Notiz außerhalb des Tagebuchs bleibt, wo sie ist
		$other = self::newNote($a, $ws, "Andere $mark", '<p>x</p>');
		self::ok($a->call('PATCH', "/notes/$other", ['heading' => '2026-06-01', 'workspace' => $ws]), 'außerhalb umbenennen');
		self::assertNull(self::note(self::dump($a->uid), $other)['folder_id'], 'außerhalb: kein Verschieben');

		// Kalender: beide Zähler in einer Antwort (P25), Notizen am Tag
		$both = self::ok($a->call('GET', '/calendar/notes-by-date.php?mode=both&workspace=' . self::enc($ws)), 'notes-by-date');
		self::assertSame(1, $both['created']['2026-03-14'] ?? null, 'created-Zähler');
		self::assertNotEmpty($both['modified'], 'modified-Zähler');
		$on = self::ok($a->call('GET', '/calendar/notes-on-date.php?date=2026-03-14&workspace=' . self::enc($ws)), 'notes-on-date');
		self::assertSame([$id], array_column($on, 'id'));

		// Zweites Tagebuch; doppelter Name → 409
		$new = self::ok($a->call('POST', '/diary/diaries.php', ['name' => "Reise $mark", 'workspace' => $ws]), 'Tagebuch anlegen');
		self::assertSame("Reise $mark", $new['diary']['name']);
		$dup = $a->call('POST', '/diary/diaries.php', ['name' => "Reise $mark", 'workspace' => $ws]);
		self::assertSame(409, $dup->status, 'doppelt: ' . $dup->describe());
		$list = self::ok($a->call('GET', '/diary/diaries.php?workspace=' . self::enc($ws)), 'diaries 2')['diaries'];
		self::assertCount(2, $list, 'beide Tagebücher');
		$sel = self::ok($a->call('GET', '/calendar/diary-entry.php?' . http_build_query(['date' => '2026-03-14', 'workspace' => $ws, 'diary' => $new['diary']['id']])), 'diary-entry Reise');
		self::assertFalse($sel['exists'], 'zweites Tagebuch: eigener Eintrag');
		self::assertSame("Reise $mark/2026/03", $sel['folder']);

		// Journal: gerendert, Reihenfolge der Anfrage, Notiz außerhalb des Tagebuchs fehlt
		$e = self::ok($a->call('GET', "/diary/entries.php?ids=$other,$id&workspace=" . self::enc($ws)), 'entries');
		self::assertSame([$id], array_map('intval', array_keys($e['entries'])));
		self::assertStringContainsString("Vorlage $mark", $e['entries'][(string)$id]);
		self::assertSame([], $e['truncated']);
	}

	public function testInvalidDateAndIdsAre400(): void {
		$a = $this->createUser('dv');
		foreach (['/calendar/diary-entry.php?date=2026-02-30', '/calendar/notes-on-date.php?date=2026-02-30', '/calendar/diary-entry.php?date=heute',
			'/calendar/notes-on-date.php', '/diary/entries.php', '/diary/entries.php?ids=' . implode(',', range(1, DiaryService::MAX_IDS + 1))] as $path) {
			$r = $a->call('GET', $path);
			self::assertSame(400, $r->status, "$path: " . $r->describe());
		}
		self::assertSame(400, $a->call('POST', '/diary/diaries.php', ['name' => ''])->status, 'leerer Name');
	}

	public function testOtherUserSeesNothing(): void {
		$a = $this->createUser('di');
		$b = $this->createUser('dj');
		$mark = 'ISO' . bin2hex(random_bytes(4));
		[$ws] = self::newWorkspace($a, 'Geheim');
		[$id] = self::createEntry($a, $ws, '2026-04-09');
		self::ok($a->call('PATCH', "/notes/$id", ['content' => "<p>Inhalt $mark</p>", 'workspace' => $ws]), 'Inhalt');
		$root = (int)self::ok($a->call('GET', '/diary/diaries.php?workspace=' . self::enc($ws)), 'diaries A')['diaries'][0]['id'];
		$wq = '&workspace=' . self::enc($ws);

		// Mit A-Workspace: 404 an allen fünf Endpunkten
		foreach (['GET /calendar/diary-entry.php?date=2026-04-09' . $wq, 'GET /calendar/notes-by-date.php?mode=both' . $wq,
			'GET /calendar/notes-on-date.php?date=2026-04-09' . $wq, 'GET /diary/diaries.php?x=1' . $wq, "GET /diary/entries.php?ids=$id" . $wq] as $req) {
			[$m, $p] = explode(' ', $req, 2);
			$r = $b->call($m, $p);
			self::assertSame(404, $r->status, "$req: " . $r->describe());
			self::assertStringNotContainsString($mark, $r->body);
		}
		self::assertSame(404, $b->call('POST', '/diary/diaries.php', ['name' => 'X', 'workspace' => $ws])->status, 'POST in A-Workspace');
		// Tagebuch-ID von A → 404
		self::assertSame(404, $b->call('GET', "/calendar/diary-entry.php?date=2026-04-09&diary=$root")->status, 'Tagebuch-ID von A');
		// Ohne Workspace: nur eigene Daten
		$own = self::ok($b->call('GET', '/calendar/diary-entry.php?date=2026-04-09'), 'B diary-entry');
		self::assertFalse($own['exists']);
		self::assertArrayNotHasKey('2026-04-09', self::ok($b->call('GET', '/calendar/notes-by-date.php?mode=both'), 'B counts')['created']);
		self::assertSame([], self::ok($b->call('GET', '/calendar/notes-on-date.php?date=2026-04-09'), 'B on date'));
		self::assertNotContains($root, array_column(self::ok($b->call('GET', '/diary/diaries.php'), 'B diaries')['diaries'], 'id'));
		$e = self::ok($b->call('GET', "/diary/entries.php?ids=$id"), 'B entries');
		self::assertSame([], $e['entries'], 'entries: keine fremden Inhalte');
		$page = self::get($b, "diary.php?diary=$root");
		self::assertSame(200, $page->status);
		self::assertStringNotContainsString($mark, $page->body, 'diary.php: keine A-Daten');
		self::assertStringNotContainsString('"id":' . $id . ',', $page->body, 'diary.php: keine A-Notiz');
	}

	// Done 5: 1 000 Einträge à 16 KiB in einem Monat; Budget/truncated, Einzeleintrag über MAX_RENDER_INPUT
	public function testRenderBudgetWithThousandEntries(): void {
		$a = $this->createUser('db');
		[$ws, $wsId] = self::newWorkspace($a, 'Viel');
		[$first, $d] = self::createEntry($a, $ws, '2026-07-01');
		$folder = (int)self::note(self::dump($a->uid), $first)['folder_id'];
		$db = self::db();
		$now = time();
		$para = '<p>' . str_repeat('Tagebuch Absatz mit Text. ', 40) . '</p>';
		$html = str_repeat($para, (int)ceil(16384 / strlen($para)));
		$md = str_repeat("## Abschnitt\n\n" . str_repeat('Text **fett** und _kursiv_. ', 30) . "\n\n", 20);
		$ids = [];
		$db->beginTransaction();
		for ($i = 0; $i < 1000; $i++) {
			$markdown = $i % 4 === 0;
			$qb = $db->getQueryBuilder();
			$qb->insert('nextnotes_notes')->values([
				'workspace_id' => $qb->createNamedParameter($wsId), 'folder_id' => $qb->createNamedParameter($folder),
				'heading' => $qb->createNamedParameter(sprintf('2026-07-%02d #%d', $i % 28 + 1, $i)), 'type' => $qb->createNamedParameter($markdown ? 'markdown' : 'note'),
				'role' => $qb->createNamedParameter('note'), 'content' => $qb->createNamedParameter(substr($markdown ? $md : $html, 0, 16384)),
				'search_text' => $qb->createNamedParameter("e$i"), 'created_by' => $qb->createNamedParameter($a->uid),
				'created_at' => $qb->createNamedParameter($now - $i), 'updated_at' => $qb->createNamedParameter($now),
			])->executeStatement();
			$ids[] = $qb->getLastInsertId();
		}
		// 30 große (100 KiB) und ein Eintrag über der Render-Grenze
		$big = [];
		foreach (range(1, 30) as $i) {
			$qb = $db->getQueryBuilder();
			$qb->insert('nextnotes_notes')->values([
				'workspace_id' => $qb->createNamedParameter($wsId), 'folder_id' => $qb->createNamedParameter($folder), 'heading' => $qb->createNamedParameter("Gross $i"),
				'type' => $qb->createNamedParameter('note'), 'role' => $qb->createNamedParameter('note'), 'content' => $qb->createNamedParameter(str_repeat($para, 100)),
				'search_text' => $qb->createNamedParameter('g'), 'created_by' => $qb->createNamedParameter($a->uid),
				'created_at' => $qb->createNamedParameter($now - 5000 - $i), 'updated_at' => $qb->createNamedParameter($now),
			])->executeStatement();
			$big[] = $qb->getLastInsertId();
		}
		$qb = $db->getQueryBuilder();
		$qb->insert('nextnotes_notes')->values([
			'workspace_id' => $qb->createNamedParameter($wsId), 'folder_id' => $qb->createNamedParameter($folder), 'heading' => $qb->createNamedParameter('Riesig'),
			'type' => $qb->createNamedParameter('note'), 'role' => $qb->createNamedParameter('note'),
			'content' => $qb->createNamedParameter(str_repeat($para, (int)ceil((ConversionService::MAX_RENDER_INPUT + 1024) / strlen($para)))),
			'search_text' => $qb->createNamedParameter('r'), 'created_by' => $qb->createNamedParameter($a->uid),
			'created_at' => $qb->createNamedParameter($now - 9000), 'updated_at' => $qb->createNamedParameter($now),
		])->executeStatement();
		$huge = $qb->getLastInsertId();
		$db->commit();

		$wq = '&workspace=' . self::enc($ws);
		// 50 × 16 KiB passen ins Budget; Render-Anteil direkt gemessen
		$chunk = array_slice($ids, 0, DiaryService::MAX_IDS);
		$a->call('GET', '/diary/entries.php?ids=' . implode(',', $chunk) . $wq);
		$t0 = microtime(true);
		$r = $a->call('GET', '/diary/entries.php?ids=' . implode(',', $chunk) . $wq);
		$ms = (int)round((microtime(true) - $t0) * 1000);
		$j = self::ok($r, 'entries 50');
		self::assertCount(DiaryService::MAX_IDS, (array)$j['entries']);
		self::assertSame([], $j['truncated']);
		self::assertLessThanOrEqual(2000, $ms, "entries.php: $ms ms");
		$t0 = microtime(true);
		Server::get(DiaryService::class)->entries($a->uid, implode(',', $chunk), $ws);
		$renderMs = (int)round((microtime(true) - $t0) * 1000);
		self::assertLessThanOrEqual(1000, $renderMs, "Render-Anteil $renderMs ms");
		// Große Einträge: Budget 1 MiB, Rest in truncated (P26 fragt nach), Riesiger als Hinweis mit Link
		$req = array_merge($big, [$huge]);
		$j = self::ok($a->call('GET', '/diary/entries.php?ids=' . implode(',', $req) . $wq), 'entries groß');
		$entries = (array)$j['entries'];
		$sum = array_sum(array_map('strlen', $entries));
		self::assertLessThanOrEqual(TrashViewService::RENDER_BUDGET + 4096, $sum, "Ausgabe $sum Bytes");
		self::assertNotEmpty($j['truncated'], 'truncated gesetzt');
		self::assertSame([], array_values(array_intersect(array_map('intval', array_keys($entries)), $j['truncated'])), 'truncated nicht gerendert');
		self::assertSame(count($req), count($entries) + count($j['truncated']), 'jede ID genau einmal');
		self::assertStringContainsString('diary-journal-too-large', $entries[(string)$huge] ?? '', 'Riesig: Hinweis');
		self::assertStringContainsString('note=' . $huge, $entries[(string)$huge] ?? '', 'Riesig: Link');
		// Nachfrage mit den gekürzten IDs liefert sie
		$next = self::ok($a->call('GET', '/diary/entries.php?ids=' . implode(',', $j['truncated']) . $wq), 'entries Nachfrage');
		self::assertNotEmpty((array)$next['entries'], 'Nachfrage rendert');

		// Seite mit 1 031 Einträgen
		$page = self::get($a, 'diary.php?workspace=' . self::enc($ws));
		$t0 = microtime(true);
		$page = self::get($a, 'diary.php?workspace=' . self::enc($ws));
		$ms = (int)round((microtime(true) - $t0) * 1000);
		self::assertSame(200, $page->status, $page->describe());
		self::assertLessThanOrEqual(2000, $ms, "diary.php: $ms ms");
		self::assertLessThanOrEqual(2 * 1024 * 1024, strlen($page->body), 'diary.php: Seitengröße begrenzt (Suchtext je Karte gekürzt)');
	}

	private static function get(ApiClient $c, string $path): \OCA\NextNotes\Tests\Support\Response {
		return $c->absolute('GET', self::APP . $path);
	}
}
