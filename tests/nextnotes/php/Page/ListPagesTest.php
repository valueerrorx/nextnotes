<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Page;

use OCA\NextNotes\Service\View\FolderListViewService;
use OCA\NextNotes\Service\View\PageFrameService;
use OCA\NextNotes\Service\View\TagListViewService;
use OCA\NextNotes\Service\View\TrashViewService;
use OCA\NextNotes\Tests\Support\ApiClient;
use OCA\NextNotes\Tests\Support\ApiTestCase;
use OCA\NextNotes\Tests\Support\Response;
use OCP\IDBConnection;
use OCP\Server;

// Plan M4 §M4e Done 2 und 5: Listen-Seiten (trash, list_tags, list_folders, attachments_list, notes_manager, create) – Inhalte des Besitzers,
// Papierkorb-Suche/-Sortierung, Seed 1 000 Notizen: Abfragezähler je Seite ≤ 15 (ViewServices direkt), Antwortzeit ≤ 500 ms.
final class ListPagesTest extends ApiTestCase {
	private const APP = '/index.php/apps/nextnotes/';
	private const PAGES = ['trash.php', 'list_tags.php', 'list_folders.php', 'attachments_list.php', 'notes_manager.php', 'create.php'];

	private static function get(ApiClient $c, string $path): Response {
		return $c->absolute('GET', self::APP . $path);
	}

	public function testOwnerSeesOwnData(): void {
		$a = $this->createUser('lp');
		$mark = 'LPAGE' . bin2hex(random_bytes(4));
		[$ws] = self::newWorkspace($a, 'Liste');
		$wq = 'workspace=' . self::enc($ws);
		$f = (int)self::ok($a->call('POST', '/folders', ['folder_name' => "Ordner $mark", 'workspace' => $ws]), 'Ordner')['folder_id'];
		$sub = (int)self::ok($a->call('POST', '/folders', ['folder_name' => "Unter $mark", 'parent_folder_id' => $f, 'workspace' => $ws]), 'Unterordner')['folder_id'];
		$n = self::newNote($a, $ws, "Notiz $mark", '<p>x</p>', $sub);
		self::ok($a->call('PUT', "/notes/$n/tags", ['tags' => ["tag$mark"], 'workspace' => $ws]), 'Tag');
		$t1 = self::newNote($a, $ws, "Weg $mark", "<p>Inhalt eins $mark</p><script>alert(1)</script>");
		$t2 = self::newNote($a, $ws, "Zwei $mark", '<p>zwei</p>');
		self::ok($a->call('DELETE', "/notes/$t1?$wq"), 'Papierkorb 1');
		self::ok($a->call('DELETE', "/notes/$t2?$wq"), 'Papierkorb 2');

		foreach (self::PAGES as $p) {
			$r = self::get($a, "$p?$wq");
			self::assertSame(200, $r->status, "$p: " . $r->describe());
			self::assertStringContainsString('id="icon_sidebar"', $r->body, "$p: Icon-Leiste");
			self::assertStringContainsString(htmlspecialchars($ws), $r->body, "$p: Workspace im Titel");
			foreach (['share-folder', 'offline-folder', 'iconSidebarLogoutBtn', 'data-create-type="diary"'] as $struck) {
				self::assertStringNotContainsString($struck, $r->body, "$p: gestrichen $struck");
			}
		}
		$trash = self::get($a, "trash.php?$wq")->body;
		self::assertStringContainsString("Weg $mark", $trash, 'Papierkorb: Titel');
		self::assertStringContainsString("Inhalt eins $mark", $trash, 'Papierkorb: Vorschau');
		self::assertStringNotContainsString('<script>alert', $trash, 'Papierkorb: Vorschau bereinigt');
		self::assertStringNotContainsString("Notiz $mark", $trash, 'Papierkorb: keine aktive Notiz');
		// Sortierung nach Löschzeitpunkt: t2 zuletzt gelöscht → zuerst
		self::assertLessThan(strpos($trash, "Weg $mark"), strpos($trash, "Zwei $mark"), 'Papierkorb: neueste zuerst');
		// GET-Suche (nach POST in der URL) und POST-Suche mit Token
		$s = self::get($a, "trash.php?search=" . self::enc("zwei $mark") . "&$wq")->body;
		self::assertStringContainsString("Zwei $mark", $s, 'Suche GET: Treffer');
		self::assertStringNotContainsString("Weg $mark", $s, 'Suche GET: kein Fremdtreffer');
		$post = $a->form(self::APP . 'trash.php', ['search' => "eins $mark", 'workspace' => $ws]);
		self::assertSame(200, $post->status, 'Suche POST: ' . $post->describe());
		self::assertStringContainsString("Weg $mark", $post->body, 'Suche POST: Treffer');
		self::assertStringNotContainsString("Zwei $mark", $post->body, 'Suche POST: kein Fremdtreffer');
		// B58: ?sort= wird nicht gespeichert
		self::get($a, "trash.php?sort=created&$wq");
		$set = $a->call('GET', '/settings/trash_sort');
		self::assertNotSame('created', $set->json()['value'] ?? null, 'B58: GET speichert trash_sort nicht');

		$tags = self::get($a, "list_tags.php?$wq")->body;
		self::assertMatchesRegularExpression('/data-tag="tag' . $mark . '"/', $tags, 'Tags: Tag gelistet');
		$folders = self::get($a, "list_folders.php?$wq")->body;
		self::assertStringContainsString("Ordner $mark", $folders, 'Ordner: gelistet');
		self::assertMatchesRegularExpression('/data-folder-id="' . $sub . '" data-depth="1"/', $folders, 'Ordner: Unterordner mit Tiefe 1');
		self::assertMatchesRegularExpression('/data-action="open-folder-actions-modal" data-folder-id="' . $f . '"[^>]*data-note-count="1"/', $folders, 'Ordner: Anzahl inkl. Unterordner');

		// M4e-fix: Fragmente (nur Liste, ohne Rahmen); fremder Workspace → eigener Standard wie die Seite
		$frag = $a->withHeaders(['X-Poznote-Fragment: folders_list']);
		$ff = self::get($frag, "list_folders.php?$wq");
		self::assertSame(200, $ff->status, 'Fragment Ordner: ' . $ff->describe());
		self::assertStringContainsString('id="foldersList"', $ff->body, 'Fragment Ordner: Liste');
		self::assertStringContainsString("Ordner $mark", $ff->body, 'Fragment Ordner: Zeile');
		self::assertStringNotContainsString('id="icon_sidebar"', $ff->body, 'Fragment Ordner: ohne Rahmen');
		$tf = self::get($a->withHeaders(['X-Poznote-Fragment: trash_cards']), "trash.php?$wq");
		self::assertSame(200, $tf->status, 'Fragment Papierkorb: ' . $tf->describe());
		self::assertStringContainsString('id="trashCardsFragment"', $tf->body, 'Fragment Papierkorb: Hülle');
		self::assertStringContainsString("Weg $mark", $tf->body, 'Fragment Papierkorb: Karte');
		self::assertStringNotContainsString('id="icon_sidebar"', $tf->body, 'Fragment Papierkorb: ohne Rahmen');
		$b = $this->createUser('lpb');
		$fb = self::get($b->withHeaders(['X-Poznote-Fragment: folders_list']), "list_folders.php?$wq");
		self::assertStringNotContainsString("Ordner $mark", $fb->body, 'Fragment Ordner: fremder Workspace');
		$tb = self::get($b->withHeaders(['X-Poznote-Fragment: trash_cards']), "trash.php?$wq");
		self::assertStringNotContainsString("Weg $mark", $tb->body, 'Fragment Papierkorb: fremder Workspace');
	}

	// M4e-fix: „Mehr laden“ per Cursor – alle Notizen genau einmal, neueste zuerst, auch wenn gezeigte Notizen dazwischen verschwinden
	public function testTrashCursorPagesThroughAll(): void {
		$a = $this->createUser('lc');
		[$ws, $wsId] = self::newWorkspace($a, 'Seiten');
		$db = self::db();
		$now = time();
		$db->beginTransaction();
		for ($i = 0; $i < 120; $i++) {
			$qb = $db->getQueryBuilder();
			// je 3 mit gleichem Zeitstempel: Gleichstand über die id
			$qb->insert('nextnotes_notes')->values([
				'workspace_id' => $qb->createNamedParameter($wsId), 'heading' => $qb->createNamedParameter("C$i"), 'type' => $qb->createNamedParameter('note'),
				'role' => $qb->createNamedParameter('note'), 'content' => $qb->createNamedParameter("<p>c$i</p>"), 'search_text' => $qb->createNamedParameter("c$i"),
				'trashed_at' => $qb->createNamedParameter($now - intdiv($i, 3)), 'created_by' => $qb->createNamedParameter($a->uid),
				'created_at' => $qb->createNamedParameter($now - 1000 + $i), 'updated_at' => $qb->createNamedParameter($now),
			])->executeStatement();
		}
		$db->commit();
		$svc = Server::get(TrashViewService::class);
		$frame = Server::get(PageFrameService::class)->frame($a->uid, $ws);
		// Löschdatum: Seite 1, dann eine gezeigte Notiz endgültig löschen, weiterblättern
		$page = $svc->build($a->uid, $frame, 'trashed', null);
		self::assertSame(120, $page['total'], 'trashed: Gesamtzahl');
		self::assertSame(70, $page['remaining'], 'trashed: noch 70');
		$seen = array_column($page['notes'], 'id');
		$qb = $db->getQueryBuilder();
		$qb->delete('nextnotes_notes')->where($qb->expr()->eq('id', $qb->createNamedParameter($seen[10])))->executeStatement();
		while ($page['remaining'] > 0) {
			$page = $svc->build($a->uid, $frame, 'trashed', null, $page['cursor']);
			$seen = array_merge($seen, array_column($page['notes'], 'id'));
		}
		self::assertCount(120, $seen, 'trashed: alle Seiten zusammen');
		self::assertSame(120, count(array_unique($seen)), 'trashed: keine doppelt');
		// Anlagedatum: alle verbleibenden 119 in absteigender Reihenfolge
		$page = $svc->build($a->uid, $frame, 'created', null);
		self::assertSame(119, $page['total'], 'created: Gesamtzahl nach Löschen');
		$order = array_map(static fn (array $n) => (int)substr($n['heading'], 1), $page['notes']);
		while ($page['remaining'] > 0) {
			$page = $svc->build($a->uid, $frame, 'created', null, $page['cursor']);
			$order = array_merge($order, array_map(static fn (array $n) => (int)substr($n['heading'], 1), $page['notes']));
		}
		self::assertCount(119, $order, 'created: alle');
		$sorted = $order;
		rsort($sorted);
		self::assertSame($sorted, $order, 'created: neueste zuerst über alle Seiten');
		// ungültiger Cursor → erste Seite
		self::assertSame(119, $svc->build($a->uid, $frame, 'created', null, '1:x')['total'], 'ungültiger Cursor: erste Seite');
	}

	// H1: Render-Budget je Request – große Aufgabenlisten (bis 256 KiB ganz geladen) und Mehrbyte-Notizen bleiben zusammen ≤ 1 MiB
	public function testTrashPreviewRenderBudget(): void {
		$a = $this->createUser('lr');
		[$ws, $wsId] = self::newWorkspace($a, 'Gross');
		$db = self::db();
		$now = time();
		$task = static fn (int $k) => ['id' => $k, 'text' => str_repeat('<&> https://example.org/' . $k . ' ', 40), 'completed' => $k % 2 === 0,
			'subtasks' => [['text' => str_repeat('s"', 300), 'completed' => false]]];
		$tasks = [];
		for ($k = 0; strlen((string)json_encode($tasks)) < 250000; $k++) {
			$tasks[] = $task($k);
		}
		$big = (string)json_encode($tasks, JSON_THROW_ON_ERROR);
		self::assertLessThanOrEqual(262144, strlen($big), 'Seed: Aufgabenliste unter der Ganz-Lade-Grenze');
		$db->beginTransaction();
		for ($i = 0; $i < 50; $i++) {
			$tasklist = $i % 2 === 0;
			$qb = $db->getQueryBuilder();
			$qb->insert('nextnotes_notes')->values([
				'workspace_id' => $qb->createNamedParameter($wsId), 'heading' => $qb->createNamedParameter("B$i"), 'type' => $qb->createNamedParameter($tasklist ? 'tasklist' : 'note'),
				'role' => $qb->createNamedParameter('note'), 'content' => $qb->createNamedParameter($tasklist ? $big : '<p>' . str_repeat('€', 40000) . '</p>'),
				'search_text' => $qb->createNamedParameter("b$i"), 'trashed_at' => $qb->createNamedParameter($now - $i), 'created_by' => $qb->createNamedParameter($a->uid),
				'created_at' => $qb->createNamedParameter($now), 'updated_at' => $qb->createNamedParameter($now),
			])->executeStatement();
		}
		$db->commit();
		$frame = Server::get(PageFrameService::class)->frame($a->uid, $ws);
		$data = Server::get(TrashViewService::class)->build($a->uid, $frame, null, null);
		self::assertCount(50, $data['notes']);
		$sum = array_sum(array_map(static fn (array $n) => strlen($n['html']), $data['notes']));
		self::assertLessThanOrEqual(TrashViewService::RENDER_BUDGET, $sum, "Vorschau gesamt $sum Bytes");
		$taskPreviews = array_values(array_filter($data['notes'], static fn (array $n) => str_contains($n['html'], 'task-list-container')));
		self::assertNotEmpty($taskPreviews, 'Aufgabenlisten-Vorschau vorhanden');
		foreach ($taskPreviews as $n) {
			self::assertLessThanOrEqual(2 * TrashViewService::PREVIEW_CHARS, strlen($n['html']), $n['heading'] . ': Aufgaben-Vorschau gekürzt');
			self::assertStringNotContainsString('<&>', $n['html'], $n['heading'] . ': Aufgabentext escaped');
		}
	}

	// Done 5: 1 000 Notizen (Hälfte im Papierkorb, Tags, 20 Ordner) → je Seite ≤ 15 Abfragen, kein N+1; HTTP ≤ 500 ms nach Aufwärmen
	public function testQueryBudgetWithThousandNotes(): void {
		$a = $this->createUser('lq');
		[$ws, $wsId] = self::newWorkspace($a, 'Viele');
		$db = self::db();
		$now = time();
		$folders = [];
		for ($i = 0; $i < 20; $i++) {
			$parent = $i >= 10 ? $folders[$i - 10] : null;
			$folders[] = (int)self::ok($a->call('POST', '/folders', ['folder_name' => "F$i", 'workspace' => $ws] + ($parent ? ['parent_folder_id' => $parent] : [])), "Ordner $i")['folder_id'];
		}
		$db->beginTransaction();
		for ($i = 0; $i < 1000; $i++) {
			$qb = $db->getQueryBuilder();
			$tasklist = $i % 10 === 0;
			$content = $tasklist ? json_encode([['id' => 1, 'text' => "t$i https://example.org", 'completed' => false]], JSON_THROW_ON_ERROR) : str_repeat("<p>Absatz $i</p>", 50);
			$qb->insert('nextnotes_notes')->values([
				'workspace_id' => $qb->createNamedParameter($wsId), 'folder_id' => $qb->createNamedParameter($folders[$i % 20]), 'heading' => $qb->createNamedParameter("N$i"),
				'type' => $qb->createNamedParameter($tasklist ? 'tasklist' : ($i % 7 === 0 ? 'markdown' : 'note')), 'role' => $qb->createNamedParameter('note'),
				'content' => $qb->createNamedParameter($i % 7 === 0 && !$tasklist ? "# N$i\n\n**fett**" : $content), 'search_text' => $qb->createNamedParameter("n$i"),
				'trashed_at' => $qb->createNamedParameter($i % 2 === 0 ? $now - $i : null),
				'created_by' => $qb->createNamedParameter($a->uid), 'created_at' => $qb->createNamedParameter($now - $i), 'updated_at' => $qb->createNamedParameter($now),
			])->executeStatement();
			$id = $qb->getLastInsertId();
			foreach (['t' . ($i % 30), 'alle'] as $tag) {
				$tq = $db->getQueryBuilder();
				$tq->insert('nextnotes_note_tags')->values(['note_id' => $tq->createNamedParameter($id), 'tag' => $tq->createNamedParameter($tag)])->executeStatement();
			}
		}
		$db->commit();

		$frameSvc = Server::get(PageFrameService::class);
		$inner = Server::get(IDBConnection::class)->getInner();
		$builders = [
			'trash' => static fn (array $f) => Server::get(TrashViewService::class)->build($a->uid, $f, null, null),
			'trash-suche' => static fn (array $f) => Server::get(TrashViewService::class)->build($a->uid, $f, 'created', 'n1 n'),
			'trash-mehr' => static fn (array $f) => Server::get(TrashViewService::class)->build($a->uid, $f, null, null, ($now - 500) . ':999999999'),
			'list_tags' => static fn (array $f) => Server::get(TagListViewService::class)->build($a->uid, $f),
			'list_folders' => static fn (array $f) => Server::get(FolderListViewService::class)->build($a->uid, $f),
			'frame' => static fn (array $f) => [],
		];
		foreach ($builders as $label => $build) {
			$before = $inner->getStats()['executed'];
			$frame = $frameSvc->frame($a->uid, $ws);
			$data = $build($frame);
			$queries = $inner->getStats()['executed'] - $before;
			self::assertLessThanOrEqual(15, $queries, "$label: $queries Abfragen bei 1 000 Notizen");
			if ($label === 'trash') {
				self::assertSame(500, $data['total'], 'Papierkorb: Gesamtzahl');
				self::assertCount(TrashViewService::LIMIT, $data['notes'], 'Papierkorb: begrenzt');
				self::assertTrue($data['limited'], 'Papierkorb: Hinweis auf Begrenzung');
				self::assertSame(450, $data['remaining'], 'Papierkorb: noch 450');
				$next = Server::get(TrashViewService::class)->build($a->uid, $frame, null, null, $data['cursor']);
				self::assertSame(400, $next['remaining'], 'Papierkorb: zweite Seite');
				self::assertSame([], array_intersect(array_column($data['notes'], 'id'), array_column($next['notes'], 'id')), 'Papierkorb: Seiten disjunkt');
			}
			if ($label === 'list_tags') {
				$counts = array_column($data['tags'], 'count', 'name');
				self::assertSame(500, $counts['alle'] ?? null, 'Tags: nur aktive Notizen gezählt');
			}
			if ($label === 'list_folders') {
				self::assertCount(20, $data['rows'], 'Ordner: alle Zeilen');
				self::assertSame(500, array_sum(array_column($data['rows'], 'direct')), 'Ordner: nur aktive Notizen gezählt');
			}
		}
		$wq = 'workspace=' . self::enc($ws);
		foreach (self::PAGES as $p) {
			self::get($a, "$p?$wq");
			$t0 = microtime(true);
			$r = self::get($a, "$p?$wq");
			$ms = (int)round((microtime(true) - $t0) * 1000);
			self::assertSame(200, $r->status, "$p: " . $r->describe());
			self::assertLessThanOrEqual(500, $ms, "$p: Antwortzeit $ms ms bei 1 000 Notizen");
		}
	}
}
