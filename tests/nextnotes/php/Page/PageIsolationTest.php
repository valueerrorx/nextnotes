<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Page;

use OCA\NextNotes\Service\LockService;
use OCA\NextNotes\Service\WorkspaceService;
use OCA\NextNotes\Tests\Support\ApiClient;
use OCA\NextNotes\Tests\Support\ApiTestCase;
use OCA\NextNotes\Tests\Support\Response;
use OCP\Server;

// Done M3 6 / SPEC §3 (AccessService: alle Seiten- und Kompatibilitätsrouten), §4.2, §4.5, §11.2 B33:
// Seiten (revisions, attachments, kanban_content, audio_player) und Compat-Routen (api_note_info, api_download_note,
// api_export_*, api_attachments; M3c2: excalidraw_editor, api_save_excalidraw, workspaces.php) – jede ID-Stelle einzeln fremd → 404 ohne Daten; Lesefreigabe → lesend 200, schreibend 403.
final class PageIsolationTest extends ApiTestCase {
	private const APP = '/index.php/apps/nextnotes/';

	/** @return array{a: ApiClient, b: ApiClient, mark: string, ws: string, wsId: int, ws2: string, f: int, s: int, n: int, t: int, at: int} */
	private function fixture(): array {
		$a = $this->createUser('a');
		$b = $this->createUser('b');
		$mark = 'PISO' . bin2hex(random_bytes(4));
		[$ws, $wsId] = self::newWorkspace($a, 'Iso');
		[$ws2] = self::newWorkspace($a, 'Iso2');
		$f = (int)self::ok($a->call('POST', '/folders', ['folder_name' => "Board $mark", 'workspace' => $ws]), 'Ordner')['folder_id'];
		$s = (int)self::ok($a->call('POST', '/folders', ['folder_name' => 'Spalte', 'parent_folder_id' => $f, 'workspace' => $ws]), 'Unterordner')['folder_id'];
		$n = self::newNote($a, $ws, "Karte $mark", "<p>Text $mark</p>", $f);
		$tasks = json_encode([['id' => 1, 'text' => "Task $mark", 'completed' => false]], JSON_THROW_ON_ERROR);
		$t = (int)self::ok($a->call('POST', '/notes', ['heading' => "Aufgaben $mark", 'type' => 'tasklist', 'content' => $tasks, 'folder_id' => $s, 'workspace' => $ws]), 'Aufgabenliste')['note']['id'];
		$at = (int)self::ok($a->upload("/notes/$n/attachments", 'a.txt', "Anhang $mark\n", ['workspace' => $ws], 'text/plain'), 'Anhang')['attachment_id'];
		self::ok($a->call('POST', "/notes/$n/snapshot", ['manual' => true]), 'Snapshot');
		return compact('a', 'b', 'mark', 'ws', 'wsId', 'ws2', 'f', 's', 'n', 't', 'at');
	}

	private static function get(ApiClient $c, string $path): Response {
		return $c->absolute('GET', self::APP . $path);
	}

	private static function assertNotFoundClean(Response $r, string $mark, string $what): void {
		self::assertSame(404, $r->status, "$what: " . $r->describe());
		self::assertStringNotContainsString($mark, $r->body, "$what: Marker von A im Body");
		foreach (['nextnotes-root', 'revisions-config', 'uploadBtn', 'kanban-card', 'excalidraw-config', 'data-excalidraw'] as $leak) {
			self::assertStringNotContainsString($leak, $r->body, "$what: App-Inhalt ($leak) in 404");
		}
	}

	// Detektor-Selbsttest: assertNotFoundClean schlägt bei Marker im Body, App-Rahmen oder falschem Status an
	public function testDetectorCatchesLeaks(): void {
		$mark = 'PISOSELF';
		$cases = [
			'Marker' => new Response(404, "<p>x $mark y</p>", []),
			'Rahmen' => new Response(404, '<div id="nextnotes-root"></div>', []),
			'Status' => new Response(200, 'ok', []),
			'403' => new Response(403, 'forbidden', []),
		];
		foreach ($cases as $label => $r) {
			$caught = false;
			try {
				self::assertNotFoundClean($r, $mark, "Selbsttest $label");
			} catch (\PHPUnit\Framework\AssertionFailedError) {
				$caught = true;
			}
			self::assertTrue($caught, "Selbsttest $label: Detektor schlägt nicht an");
		}
		self::assertNotFoundClean(new Response(404, 'Not found', []), $mark, 'Selbsttest sauber');
	}

	public function testOwnerReadsPagesAndCompatRoutes(): void {
		$x = $this->fixture();
		['a' => $a, 'mark' => $mark, 'n' => $n, 'f' => $f, 'at' => $at] = $x;
		$wq = 'workspace=' . self::enc($x['ws']);

		$r = self::get($a, "kanban_content.php?ajax=1&folder_id=$f&$wq");
		self::assertSame(200, $r->status, 'Kanban: ' . $r->describe());
		self::assertStringContainsString("Karte $mark", $r->body);
		self::assertStringContainsString("Task $mark", $r->body);
		self::assertStringContainsString('data-action="create-kanban-note"', $r->body, 'Kanban: Anlegen-Knopf für Besitzer');

		$r = self::get($a, "kanban_content.php?folder_id=$f");
		self::assertContains($r->status, [302, 303], 'Kanban ohne ajax → Weiterleitung: ' . $r->describe());
		self::assertStringContainsString("kanban=$f", (string)$r->header('Location'));

		$r = self::get($a, "revisions.php?note_id=$n&$wq");
		self::assertSame(200, $r->status, 'Revisionen: ' . $r->describe());
		self::assertStringContainsString('id="revisions-config"', $r->body);

		$r = self::get($a, "attachments.php?note_id=$n&$wq");
		self::assertSame(200, $r->status, 'Anhänge: ' . $r->describe());
		self::assertStringContainsString('id="uploadBtn"', $r->body, 'Anhänge: Hochladen für Besitzer');
		self::assertStringNotContainsString('nextnotes-readonly', $r->body);

		$r = self::get($a, "api_note_info.php?note_id=$n&$wq");
		self::assertSame(200, $r->status, 'Notiz-Info: ' . $r->describe());
		$info = $r->json()['info'] ?? [];
		self::assertSame("Karte $mark", $info['title'] ?? null);
		self::assertSame($n, $info['note_id'] ?? null);
		self::assertArrayNotHasKey('full_path', $info, 'B33: full_path entfällt');

		$r = self::get($a, "api_download_note.php?id=$n");
		self::assertSame(200, $r->status, 'Download: ' . $r->describe());
		self::assertStringContainsString("Text $mark", $r->body);
		self::assertStringContainsString('attachment', (string)$r->header('Content-Disposition'));

		// M4c: Export portiert
		$r = self::get($a, "api_export_note.php?id=$n&format=html_embedded");
		self::assertSame(200, $r->status, 'Export Notiz: ' . $r->describe());
		self::assertStringContainsString("Text $mark", $r->body);
		self::assertSame(501, self::get($a, "api_export_folder.php?folder_id=$f")->status, 'Export Ordner: Prüfung bestanden, 501');

		$r = self::get($a, "api_attachments.php?action=download&note_id=$n&attachment_id=$at");
		self::assertSame(200, $r->status, 'Alt-URL Anhang: ' . $r->describe());
		self::assertStringContainsString("Anhang $mark", $r->body);
		self::assertSame('nosniff', strtolower(trim(explode(',', (string)$r->header('X-Content-Type-Options'))[0])));

		// Alt-URL nur Download; ungültige/fehlende IDs → 404
		foreach ([
			"api_attachments.php?action=delete&note_id=$n&attachment_id=$at",
			"api_attachments.php?note_id=$n&attachment_id=$at",
			"api_attachments.php?action=download&note_id=$n",
			'revisions.php', 'attachments.php', 'api_note_info.php', 'api_download_note.php', 'api_export_note.php', 'api_export_folder.php',
			'revisions.php?note_id=abc', "revisions.php?note_id=$n%20x", 'attachments.php?note_id=-1', 'api_note_info.php?note_id=0',
			'api_download_note.php?id=99999999', 'api_export_note.php?id=1e3', 'api_export_folder.php?folder_id=x',
			'kanban_content.php?ajax=1&folder_id=abc', 'kanban_content.php?ajax=1',
		] as $p) {
			self::assertSame(404, self::get($a, $p)->status, "A $p");
		}
		// Notiz existiert, aber ?workspace= ist ein anderer eigener Workspace → 404
		$wq2 = 'workspace=' . self::enc($x['ws2']);
		foreach (["revisions.php?note_id=$n&$wq2", "attachments.php?note_id=$n&$wq2", "api_note_info.php?note_id=$n&$wq2",
			"api_download_note.php?id=$n&$wq2", "api_export_note.php?id=$n&$wq2", "api_export_folder.php?folder_id=$f&$wq2",
			"kanban_content.php?ajax=1&folder_id=$f&$wq2", "api_attachments.php?action=download&note_id=$n&attachment_id=$at&$wq2"] as $p) {
			self::assertSame(404, self::get($a, $p)->status, "A Workspace-Abgleich $p");
		}
	}

	public function testForeignEveryIdPositionIs404(): void {
		$x = $this->fixture();
		['a' => $a, 'b' => $b, 'mark' => $mark, 'n' => $n, 't' => $t, 'f' => $f, 's' => $s, 'at' => $at] = $x;
		$wq = 'workspace=' . self::enc($x['ws']);
		[$bws] = self::newWorkspace($b, 'Eigen');
		$bn = self::newNote($b, $bws, 'Eigene', '<p>b</p>');
		$bf = (int)self::ok($b->call('POST', '/folders', ['folder_name' => 'B-Board', 'workspace' => $bws]), 'B-Ordner')['folder_id'];
		$bat = (int)self::ok($b->upload("/notes/$bn/attachments", 'b.txt', "B\n", ['workspace' => $bws], 'text/plain'), 'B-Anhang')['attachment_id'];
		$bwq = 'workspace=' . self::enc($bws);
		$before = self::dump($a->uid);

		$cases = [
			// Notiz/Ordner/Anhang von A (Query-ID)
			"kanban_content.php?ajax=1&folder_id=$f", "kanban_content.php?ajax=1&folder_id=$s", "kanban_content.php?folder_id=$f",
			"kanban_content.php?ajax=1&folder_id=$f&$wq",
			"revisions.php?note_id=$n", "revisions.php?note_id=$t&$wq", "revisions.php?note_id=$n&revision=x",
			"attachments.php?note_id=$n", "attachments.php?note_id=$n&$wq",
			"api_note_info.php?note_id=$n", "api_note_info.php?note_id=$t&$wq",
			"api_download_note.php?id=$n", "api_download_note.php?id=$t",
			"api_export_note.php?id=$n", "api_export_folder.php?folder_id=$f", "api_export_folder.php?folder_id=$s",
			"api_attachments.php?action=download&note_id=$n&attachment_id=$at",
			"audio_player.php?note=$n&attachment=$at",
			// gemischt: eigene ID + fremde ID an anderer Stelle
			"api_attachments.php?action=download&note_id=$bn&attachment_id=$at",
			"api_attachments.php?action=download&note_id=$n&attachment_id=$bat",
			"audio_player.php?note=$bn&attachment=$at",
			"revisions.php?note_id=$bn&$wq", "attachments.php?note_id=$bn&$wq", "api_note_info.php?note_id=$bn&$wq",
			"api_download_note.php?id=$bn&$wq", "api_export_note.php?id=$bn&$wq", "api_export_folder.php?folder_id=$bf&$wq",
			"kanban_content.php?ajax=1&folder_id=$bf&$wq", "kanban_content.php?folder_id=$bf&$wq",
			"api_attachments.php?action=download&note_id=$bn&attachment_id=$bat&$wq",
			"revisions.php?note_id=$n&$bwq", "api_note_info.php?note_id=$n&$bwq", "kanban_content.php?ajax=1&folder_id=$f&$bwq",
		];
		foreach ($cases as $p) {
			self::assertNotFoundClean(self::get($b, $p), $mark, "B $p");
		}
		// Gegenprobe: B erreicht eigene Daten
		foreach (["api_note_info.php?note_id=$bn", "revisions.php?note_id=$bn&$bwq", "attachments.php?note_id=$bn",
			"api_attachments.php?action=download&note_id=$bn&attachment_id=$bat", "kanban_content.php?ajax=1&folder_id=$bf"] as $p) {
			self::assertSame(200, self::get($b, $p)->status, "B eigen $p");
		}
		// Schreibende APIs der Seiten (Anhänge, Revisionen, Kanban) auf fremde Notiz → 404
		foreach ($this->writeCalls($x) as [$m, $p, $body]) {
			self::assertNotFoundClean($b->call($m, $p, $body), $mark, "B $m $p");
		}
		self::assertNotFoundClean($b->upload("/notes/$n/attachments", 'x.txt', 'x', [], 'text/plain'), $mark, 'B Upload fremd');
		self::assertSame($before, self::dump($a->uid), 'Fremdzugriff hat Daten von A verändert');
	}

	public function testReadShareReads200WritesForbidden(): void {
		$x = $this->fixture();
		['a' => $a, 'b' => $b, 'mark' => $mark, 'n' => $n, 'f' => $f, 'at' => $at] = $x;
		Server::get(WorkspaceService::class)->share($a->uid, $x['wsId'], $b->uid, 'read');
		$before = self::dump($a->uid);

		$r = self::get($b, "kanban_content.php?ajax=1&folder_id=$f");
		self::assertSame(200, $r->status, 'B Kanban lesend: ' . $r->describe());
		self::assertStringContainsString("Karte $mark", $r->body);
		foreach (['create-kanban-note', 'toggle-kanban-completed', 'rename-folder'] as $act) {
			self::assertStringNotContainsString('data-action="' . $act . '"', $r->body, "Kanban lesend: $act sichtbar");
		}
		$r = self::get($b, "revisions.php?note_id=$n");
		self::assertSame(200, $r->status, 'B Revisionen lesend: ' . $r->describe());
		$r = self::get($b, "attachments.php?note_id=$n");
		self::assertSame(200, $r->status, 'B Anhänge lesend: ' . $r->describe());
		self::assertStringContainsString('nextnotes-readonly', $r->body, 'Anhänge lesend: Readonly-Kennzeichen');
		self::assertStringNotContainsString('id="uploadBtn"', $r->body, 'Anhänge lesend: Hochladen sichtbar');
		$r = self::get($b, "api_note_info.php?note_id=$n");
		self::assertSame(200, $r->status, 'B Notiz-Info lesend: ' . $r->describe());
		self::assertSame("Karte $mark", $r->json()['info']['title'] ?? null);
		$r = self::get($b, "api_download_note.php?id=$n");
		self::assertSame(200, $r->status);
		self::assertStringContainsString("Text $mark", $r->body);
		self::assertSame(200, self::get($b, "api_export_note.php?id=$n")->status, 'B Export lesend (Q2 vorläufig)');
		self::assertSame(501, self::get($b, "api_export_folder.php?folder_id=$f")->status);
		$r = self::get($b, "api_attachments.php?action=download&note_id=$n&attachment_id=$at");
		self::assertSame(200, $r->status);
		self::assertStringContainsString("Anhang $mark", $r->body);

		foreach ($this->writeCalls($x) as [$m, $p, $body]) {
			$r = $b->call($m, $p, $body);
			self::assertSame(403, $r->status, "B lesend $m $p: " . $r->describe());
		}
		$r = $b->upload("/notes/$n/attachments", 'x.txt', 'x', [], 'text/plain');
		self::assertSame(403, $r->status, 'B lesend Upload: ' . $r->describe());
		self::assertSame($before, self::dump($a->uid), 'Lesefreigabe: Schreibversuche haben Daten von A verändert');
	}

	public function testLoggedOutGetsNoData(): void {
		$x = $this->fixture();
		['mark' => $mark, 'n' => $n, 'f' => $f, 'at' => $at] = $x;
		foreach (["revisions.php?note_id=$n", "attachments.php?note_id=$n", "kanban_content.php?ajax=1&folder_id=$f",
			"api_note_info.php?note_id=$n", "api_download_note.php?id=$n", "api_export_note.php?id=$n",
			"api_attachments.php?action=download&note_id=$n&attachment_id=$at"] as $p) {
			$r = ApiClient::raw('GET', ApiClient::baseUrl() . self::APP . $p, null, ['Accept: text/html'], null);
			self::assertContains($r->status, [302, 303, 401], "abgemeldet $p: " . $r->describe());
			self::assertStringNotContainsString($mark, $r->body, "abgemeldet $p: Daten");
		}
	}

	/** Schreibende API-Aufrufe, die von den Seiten aus erreichbar sind: [Verb, Pfad unter /api/v1, Body] */
	private function writeCalls(array $x): array {
		['n' => $n, 't' => $t, 's' => $s, 'at' => $at] = $x;
		return [
			['DELETE', "/notes/$n/attachments/$at", null],
			['POST', "/notes/$n/attachments/$at/move", ['target_note_id' => $t]],
			['POST', "/notes/$n/snapshot", ['manual' => true]],
			['POST', "/notes/$n/kanban-completed", ['completed' => true]],
			['POST', "/notes/$n/folder", ['folder_id' => $s]],
			['PATCH', "/notes/$n", ['heading' => 'gekapert', 'content' => '<p>x</p>']],
			['POST', '/notes', ['heading' => 'Neue Karte', 'content' => '', 'folder_id' => $s]],
		];
	}

	// Kanban-Karten von Verknüpfungen (type=linked) zeigen Inhalt, Tags, Farbe, Erinnerung des Ziels nur, wenn lesbar und nicht im Papierkorb
	public function testKanbanLinkedTargetOnlyWhenReadable(): void {
		$a = $this->createUser('a');
		$b = $this->createUser('b');
		$mark = 'PLNK' . bin2hex(random_bytes(4));
		[$ws, $wsId] = self::newWorkspace($a, 'Ziel');
		$t = self::newNote($a, $ws, "Ziel $mark", "<p>ZIEL$mark</p>");
		$t2 = self::newNote($a, $ws, "Weg $mark", "<p>WEG$mark</p>");
		self::ok($a->call('PUT', "/notes/$t/tags", ['tags' => "ztag$mark", 'workspace' => $ws]), 'Tags Ziel');
		self::ok($a->call('PUT', "/notes/$t/color", ['color' => '#ab12cd']), 'Farbe Ziel');
		Server::get(WorkspaceService::class)->share($a->uid, $wsId, $b->uid, 'read');
		[$bws] = self::newWorkspace($b, 'Board');
		$bf = (int)self::ok($b->call('POST', '/folders', ['folder_name' => 'Links', 'workspace' => $bws]), 'B-Ordner')['folder_id'];
		$l1 = (int)self::ok($b->call('POST', '/notes', ['heading' => 'L1', 'type' => 'linked', 'workspace' => $bws, 'folder_id' => $bf, 'linked_note_id' => $t]), 'Link 1')['note']['id'];
		self::ok($b->call('POST', '/notes', ['heading' => 'L2', 'type' => 'linked', 'workspace' => $bws, 'folder_id' => $bf, 'linked_note_id' => $t2]), 'Link 2');
		self::ok($b->call('POST', "/notes/$t/reminder", ['reminder_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 86400 * 3)]), 'B-Erinnerung auf Ziel');
		$card = static function (string $body, int $id): string {
			self::assertMatchesRegularExpression('/<div class="kanban-card[^"]*"[^>]*data-note-id="' . $id . '"/', $body, "Karte $id fehlt");
			preg_match('/<div class="kanban-card[^"]*"[^>]*data-note-id="' . $id . '".*?(?=<div class="kanban-card[ "]|$)/s', $body, $m);
			return $m[0] ?? '';
		};
		$kb = fn () => self::get($b, "kanban_content.php?ajax=1&folder_id=$bf");

		// lesbar: Ziel sichtbar (Positivkontrolle der Detektoren)
		$r = $kb();
		self::assertSame(200, $r->status, $r->describe());
		$c1 = $card($r->body, $l1);
		self::assertStringContainsString("ZIEL$mark", $c1, 'lesbar: Inhalt des Ziels');
		self::assertStringContainsString("ztag$mark", $c1, 'lesbar: Tags des Ziels');
		self::assertStringContainsString('ab12cd', strtolower($c1), 'lesbar: Farbe des Ziels');
		self::assertMatchesRegularExpression('/data-reminder-due="[^"]+"/', $c1, 'lesbar: Erinnerung des Ziels');
		self::assertStringContainsString("WEG$mark", $r->body, 'lesbar: zweites Ziel');

		// Ziel im Papierkorb → kein Zielinhalt
		self::ok($a->call('DELETE', "/notes/$t2"), 'Ziel 2 in Papierkorb');
		$r = $kb();
		self::assertSame(200, $r->status);
		self::assertStringNotContainsString("WEG$mark", $r->body, 'Papierkorb-Ziel: Inhalt sichtbar');
		self::assertStringContainsString("ZIEL$mark", $r->body);

		// Freigabe weg (Zeile direkt gelöscht, B-Erinnerung bleibt stehen) → fremd: nichts vom Ziel
		$qb = self::db()->getQueryBuilder();
		$qb->delete('nextnotes_ws_shares')->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter($wsId)))
			->andWhere($qb->expr()->eq('share_with', $qb->createNamedParameter($b->uid)))->executeStatement();
		$r = $kb();
		self::assertSame(200, $r->status, $r->describe());
		$c1 = $card($r->body, $l1);
		foreach (["ZIEL$mark", "ztag$mark", "Ziel $mark", "WEG$mark"] as $leak) {
			self::assertStringNotContainsString($leak, $r->body, "fremdes Ziel: $leak sichtbar");
		}
		self::assertStringNotContainsString('ab12cd', strtolower($r->body), 'fremdes Ziel: Farbe sichtbar');
		self::assertMatchesRegularExpression('/data-reminder-due=""/', $c1, 'fremdes Ziel: Erinnerung sichtbar');
		self::assertDoesNotMatchRegularExpression('/kanban-card-due\b/', $c1, 'fremdes Ziel: Fälligkeit sichtbar');
	}

	// §11.2 B34: Revisionen-Beschreibung ohne KI/MCP-Absatz und ohne Link auf settings.php, in de und en
	public function testRevisionsDescriptionWithoutAi(): void {
		$a = $this->createUser('a');
		[$ws] = self::newWorkspace($a, 'Rev');
		$n = self::newNote($a, $ws, 'Rev', '<p>x</p>');
		foreach (['de' => 'Version', 'en' => 'revision'] as $lang => $word) {
			// Sprache verwaltet Nextcloud (settings/language → 400), daher NC-Benutzereinstellung core/lang
			Server::get(\OCP\IConfig::class)->setUserValue($a->uid, 'core', 'lang', $lang);
			$r = self::get($a, "revisions.php?note_id=$n");
			self::assertSame(200, $r->status, "$lang: " . $r->describe());
			self::assertMatchesRegularExpression('#<template id="revisionsInfoContent">(.*?)</template>#s', $r->body);
			preg_match('#<template id="revisionsInfoContent">(.*?)</template>#s', $r->body, $m);
			$text = html_entity_decode(strip_tags($m[1]));
			self::assertStringContainsStringIgnoringCase($word, $text, "$lang: Beschreibung leer/unerwartet");
			self::assertDoesNotMatchRegularExpression('/\bMCP\b|\bKI\b|\bAI\b|assistant|assistent|künstliche/i', $text, "$lang: KI/MCP-Absatz: $text");
			self::assertStringNotContainsString('settings.php', $r->body, "$lang: Link auf settings.php");
		}
	}

	// ---- M3c2: excalidraw_editor.php, api_save_excalidraw.php, workspaces.php (POST) ----

	private const SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect x="0" y="0" width="10" height="10" fill="#ffffff"/></svg>';

	private static function diagram(string $text): string {
		return json_encode(['type' => 'excalidraw', 'elements' => [['id' => 'e1', 'type' => 'text', 'text' => $text]], 'appState' => new \stdClass(), 'files' => new \stdClass()], JSON_THROW_ON_ERROR);
	}

	/** Eingebettetes Diagramm speichern (Felder wie excalidraw-editor.js) */
	private static function saveEmbedded(ApiClient $c, int $note, string $text, array $extra = [], bool $multipart = false): Response {
		return $c->form(self::APP . 'api_save_excalidraw.php', ['action' => 'save_embedded_diagram', 'note_id' => $note, 'diagram_id' => 'excalidraw-1',
			'diagram_data' => self::diagram($text), 'preview_svg' => self::SVG] + $extra, $multipart);
	}

	private static function saveFull(ApiClient $c, int $note, string $text, array $extra = []): Response {
		return $c->form(self::APP . 'api_save_excalidraw.php', ['note_id' => $note, 'diagram_data' => self::diagram($text), 'preview_svg' => self::SVG] + $extra);
	}

	private static function wsPost(ApiClient $c, array $fields): Response {
		return $c->form(self::APP . 'workspaces.php', $fields);
	}

	/** alle 8 workspaces.php-Aktionen auf Workspace $ws (Ziel/Reihenfolge-Partner $other) */
	private static function wsActions(string $ws, string $other): array {
		return [
			'rename' => ['action' => 'rename', 'name' => $ws, 'new_name' => 'Gekapert ' . bin2hex(random_bytes(2))],
			'delete' => ['action' => 'delete', 'name' => $ws],
			'set_color' => ['action' => 'set_color', 'name' => $ws, 'color' => '#ff0000'],
			'set_tags' => ['action' => 'set_tags', 'name' => $ws, 'tags' => 'gekapert'],
			'reorder' => ['action' => 'reorder', 'names' => [$ws]],
			'move_notes' => ['action' => 'move_notes', 'name' => $ws, 'target' => $other],
			'share_workspace' => ['action' => 'share_workspace', 'name' => $ws],
			'unshare_workspace' => ['action' => 'unshare_workspace', 'name' => $ws],
		];
	}

	public function testExcalidrawAndWorkspacesOwner(): void {
		$x = $this->fixture();
		['a' => $a, 'mark' => $mark, 'n' => $n] = $x;
		$wq = 'workspace=' . self::enc($x['ws']);
		$r = self::saveEmbedded($a, $n, "Bild $mark", ['workspace' => $x['ws'], 'cursor_position' => 3], true);
		self::assertSame(200, $r->status, 'A speichert eingebettet (multipart): ' . $r->describe());
		$r = self::get($a, "excalidraw_editor.php?note_id=$n&diagram_id=excalidraw-1&$wq");
		self::assertSame(200, $r->status, 'A Editor: ' . $r->describe());
		self::assertStringContainsString('id="excalidraw-config"', $r->body);
		self::assertStringContainsString('id="saveBtn"', $r->body, 'A: Speichern-Knopf');
		self::assertStringContainsString("Bild $mark", $r->body, 'A: Diagramm geladen');
		self::assertStringNotContainsString('nextnotes-readonly', $r->body);
		self::assertSame(200, self::saveFull($a, $n, "Voll $mark")->status, 'A Vollmodus');
		// fehlende/ungültige IDs und fremder eigener Workspace → 404 bzw. 400
		foreach (['excalidraw_editor.php', 'excalidraw_editor.php?note_id=abc', 'excalidraw_editor.php?note_id=0', 'excalidraw_editor.php?note_id=99999999',
			"excalidraw_editor.php?note_id=$n&diagram_id=a%22b", "excalidraw_editor.php?note_id=$n&workspace=" . self::enc($x['ws2'])] as $p) {
			self::assertSame(404, self::get($a, $p)->status, "A $p");
		}
		self::assertSame(404, self::saveEmbedded($a, $n, 'x', ['workspace' => $x['ws2']])->status, 'A speichert mit anderem eigenen Workspace');
		self::assertSame(400, self::saveFull($a, 0, 'x')->status, 'note_id=0 legt nichts an');
		// workspaces.php: Besitzer
		$r = self::wsPost($a, ['action' => 'set_color', 'name' => $x['ws2'], 'color' => '#00ff00']);
		self::assertSame(200, $r->status, 'A set_color: ' . $r->describe());
		self::assertSame(501, self::wsPost($a, ['action' => 'share_workspace', 'name' => $x['ws2']])->status, 'A share → 501 (M5)');
		self::assertSame(400, self::wsPost($a, ['action' => 'frobnicate', 'name' => $x['ws2']])->status);
		self::assertSame(404, self::wsPost($a, ['action' => 'delete', 'name' => 'Gibtsnicht ' . $mark])->status);
		// CSRF: ohne OCS-APIRequest/Token → 412 (Anmeldung per Passwort statt App-Token, damit der CSRF-Check greift)
		$r = ApiClient::raw('POST', ApiClient::baseUrl() . self::APP . 'workspaces.php', null, [], $a->uid . ':' . self::PASSWORD, null,
			http_build_query(['action' => 'delete', 'name' => $x['ws2']]));
		self::assertContains($r->status, [401, 412], 'ohne CSRF-Token: ' . $r->describe());
		self::assertContains($x['ws2'], self::workspaceNames($a), 'ohne CSRF-Token gelöscht');
	}

	public function testExcalidrawAndWorkspacesForeignIs404(): void {
		$x = $this->fixture();
		['a' => $a, 'b' => $b, 'mark' => $mark, 'n' => $n, 't' => $t, 'ws' => $ws, 'ws2' => $ws2] = $x;
		self::ok(self::saveEmbedded($a, $n, "Geheim $mark", ['workspace' => $ws]), 'A Diagramm');
		[$bws] = self::newWorkspace($b, 'Eigen');
		$bn = self::newNote($b, $bws, 'Eigene', '<p>b</p>');
		$wq = 'workspace=' . self::enc($ws);
		$bwq = 'workspace=' . self::enc($bws);
		$before = self::dump($a->uid);

		foreach (["excalidraw_editor.php?note_id=$n", "excalidraw_editor.php?note_id=$n&diagram_id=excalidraw-1",
			"excalidraw_editor.php?note_id=$n&diagram_id=excalidraw-1&$wq", "excalidraw_editor.php?note_id=$t",
			"excalidraw_editor.php?note_id=$bn&$wq", "excalidraw_editor.php?note_id=$n&$bwq"] as $p) {
			self::assertNotFoundClean(self::get($b, $p), $mark, "B GET $p");
		}
		$saves = [
			'eingebettet' => self::saveEmbedded($b, $n, 'B'),
			'eingebettet multipart' => self::saveEmbedded($b, $n, 'B', [], true),
			'eingebettet ws A' => self::saveEmbedded($b, $n, 'B', ['workspace' => $ws]),
			'eingebettet ws B' => self::saveEmbedded($b, $n, 'B', ['workspace' => $bws]),
			'eigene Notiz, ws A' => self::saveEmbedded($b, $bn, 'B', ['workspace' => $ws]),
			'voll' => self::saveFull($b, $n, 'B'),
			'voll ws A' => self::saveFull($b, $n, 'B', ['workspace' => $ws]),
		];
		foreach ($saves as $label => $r) {
			self::assertNotFoundClean($r, $mark, "B api_save_excalidraw $label");
		}
		foreach (self::wsActions($ws, $ws2) as $act => $f) {
			self::assertNotFoundClean(self::wsPost($b, $f), $mark, "B workspaces.php $act (Workspace A)");
		}
		// gemischt: eigene Quelle/fremdes Ziel, fremde Quelle/eigenes Ziel, Reihenfolge mit fremdem Namen
		foreach (['move eigen→A' => ['action' => 'move_notes', 'name' => $bws, 'target' => $ws],
			'move A→eigen' => ['action' => 'move_notes', 'name' => $ws, 'target' => $bws],
			'reorder eigen+A' => ['action' => 'reorder', 'names' => [$bws, $ws]]] as $label => $f) {
			self::assertNotFoundClean(self::wsPost($b, $f), $mark, "B workspaces.php $label");
		}
		self::assertSame($before, self::dump($a->uid), 'Fremdzugriff hat Daten von A verändert');
		// Gegenprobe: B erreicht Eigenes
		self::assertSame(200, self::get($b, "excalidraw_editor.php?note_id=$bn&$bwq")->status, 'B eigener Editor');
		self::assertSame(200, self::saveEmbedded($b, $bn, 'B eigen')->status, 'B speichert eigene Notiz');
		self::assertSame(200, self::wsPost($b, ['action' => 'set_tags', 'name' => $bws, 'tags' => 'x'])->status, 'B eigener Workspace');
		self::assertContains($bws, self::workspaceNames($b), 'B-Workspace bleibt');
	}

	public function testExcalidrawAndWorkspacesReadShare(): void {
		$x = $this->fixture();
		['a' => $a, 'b' => $b, 'mark' => $mark, 'n' => $n, 'ws' => $ws, 'ws2' => $ws2] = $x;
		self::ok(self::saveEmbedded($a, $n, "Lesbar $mark", ['workspace' => $ws]), 'A Diagramm');
		Server::get(WorkspaceService::class)->share($a->uid, $x['wsId'], $b->uid, 'read');
		[$bws] = self::newWorkspace($b, 'Eigen');
		$before = self::dump($a->uid);

		$r = self::get($b, "excalidraw_editor.php?note_id=$n&diagram_id=excalidraw-1");
		self::assertSame(200, $r->status, 'B lesend Editor: ' . $r->describe());
		self::assertStringContainsString("Lesbar $mark", $r->body, 'lesend: Diagramm sichtbar');
		self::assertStringNotContainsString('id="saveBtn"', $r->body, 'lesend: Speichern-Knopf');
		self::assertStringNotContainsString('id="saveAndExitBtn"', $r->body, 'lesend: Speichern-und-Schließen');
		self::assertStringContainsString('"readOnly":true', $r->body, 'lesend: readOnly (P14)');
		self::assertStringContainsString('nextnotes-readonly', $r->body);

		foreach (['eingebettet' => self::saveEmbedded($b, $n, 'B'), 'eingebettet multipart' => self::saveEmbedded($b, $n, 'B', ['workspace' => $ws], true),
			'voll' => self::saveFull($b, $n, 'B')] as $label => $r) {
			self::assertSame(403, $r->status, "B lesend api_save_excalidraw $label: " . $r->describe());
		}
		foreach (self::wsActions($ws, $ws) + ['move eigen→A' => ['action' => 'move_notes', 'name' => $bws, 'target' => $ws],
			'move A→eigen' => ['action' => 'move_notes', 'name' => $ws, 'target' => $bws]] as $act => $f) {
			$r = self::wsPost($b, $f);
			self::assertSame(403, $r->status, "B lesend workspaces.php $act: " . $r->describe());
		}
		self::assertSame($before, self::dump($a->uid), 'Lesefreigabe: Schreibversuche haben Daten von A verändert');
		self::assertContains($ws, self::workspaceNames($b), 'Freigabe besteht weiter');
	}

	// ADR 0005: fremde gültige Sperre → 423, Inhalt unverändert; nach Freigabe 200
	public function testExcalidrawSaveRespectsLock(): void {
		$x = $this->fixture();
		['a' => $a, 'b' => $b, 'n' => $n] = $x;
		Server::get(WorkspaceService::class)->share($a->uid, $x['wsId'], $b->uid, 'write');
		$locks = Server::get(LockService::class);
		$locks->acquire($b->uid, $n, 'tester-b');
		$before = self::dump($a->uid);
		$r = self::saveEmbedded($a, $n, 'gesperrt');
		self::assertSame(423, $r->status, 'A speichert trotz B-Sperre: ' . $r->describe());
		self::assertSame(423, self::saveFull($a, $n, 'gesperrt')->status, 'Vollmodus trotz B-Sperre');
		self::assertSame($before, self::dump($a->uid), 'Sperre: Inhalt/Anhänge geändert');
		$locks->release($b->uid, $n, 'tester-b');
		self::assertSame(200, self::saveEmbedded($a, $n, 'frei')->status, 'nach Freigabe');
		self::assertSame(200, self::saveEmbedded($b, $n, 'B schreibt')->status, 'B mit Schreibfreigabe');
	}

}
