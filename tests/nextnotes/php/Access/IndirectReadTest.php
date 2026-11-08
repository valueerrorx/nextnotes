<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Access;

use OCA\NextNotes\Service\WorkspaceService;
use OCA\NextNotes\Tests\Support\ApiClient;
use OCA\NextNotes\Tests\Support\ApiTestCase;
use OCA\NextNotes\Tests\Support\Response;
use OCP\Server;

/**
 * SPEC §3 (indirekte Lesepfade), Plan Done M2b 1: /notes/resolve (Titel, ID), Backlinks, Graph, /changes,
 * Suche, Anhänge, Snapshots – B sieht nichts von A, weder ohne Freigabe noch nach deren Entzug.
 * Querverweise in beide Richtungen (A-Notiz verweist auf B-Notiz und umgekehrt).
 * M4i (SPEC §13 M4.5, Plan M4i Krit. 5): M4-Ansichten und Exporte über B's eigene Objekte, die auf A zeigen
 * (Verknüpfung, [[ID]]/[[Titel]], index.php?note=, Anhang-URL, Erwähnung, Tagebuch-/Kalender-IDs).
 */
final class IndirectReadTest extends ApiTestCase {
	/** @return string[] Lecks */
	public static function leaks(ApiClient $b, array $fx): array {
		$m = $fx['mark'];
		$errors = [];
		$noMark = static function (string $what, $r) use ($m, &$errors): void {
			if (str_contains($r->body, $m)) {
				$errors[] = "$what: A-Marker sichtbar " . $r->describe();
			}
			if ($r->status >= 500) {
				$errors[] = "$what: Status " . $r->describe();
			}
		};
		$refused = static function (string $what, $r) use ($noMark, &$errors): void {
			$noMark($what, $r);
			if (!in_array($r->status, [403, 404], true)) {
				$errors[] = "$what: erwartet 403/404, erhalten " . $r->describe();
			}
		};
		$gA = $fx['gA'];
		$refused('resolve Titel', $b->call('GET', '/notes/resolve' . self::q(['reference' => "Geheim $m"])));
		$refused('resolve ID', $b->call('GET', '/notes/resolve' . self::q(['reference' => (string)$gA])));
		$refused('resolve ID im eigenen WS', $b->call('GET', '/notes/resolve' . self::q(['reference' => (string)$gA, 'workspace' => $fx['wsB']])));
		$refused('backlinks A-Notiz', $b->call('GET', "/notes/$gA/backlinks"));
		$refused('backlinks A-Notiz all', $b->call('GET', "/notes/$gA/backlinks" . self::q(['all_workspaces' => '1'])));
		foreach ([[], ['workspace' => $fx['wsB']], ['workspace' => $fx['wsB'], 'all_workspaces' => '1'], ['all_workspaces' => '1']] as $q) {
			$r = $b->call('GET', "/notes/{$fx['bN']}/backlinks" . self::q($q));
			$noMark('backlinks eigene B-Notiz ' . json_encode($q), $r);
			if ($r->ok() && array_map(static fn ($x) => (int)$x['id'], $r->json()['backlinks'] ?? []) !== []) {
				$errors[] = 'backlinks eigene B-Notiz listet A-Notiz ' . $r->describe();
			}
		}
		foreach ([[], ['workspace' => $fx['wsB']]] as $q) {
			$r = $b->call('GET', '/graph' . self::q($q));
			$noMark('graph ' . json_encode($q), $r);
			$nodes = array_map(static fn ($n) => (int)$n['id'], $r->json()['nodes'] ?? []);
			foreach (['gA', 'lA', 'zA'] as $k) {
				if (in_array($fx[$k], $nodes, true)) {
					$errors[] = "graph: Knoten $k von A";
				}
			}
			foreach ($r->json()['edges'] ?? [] as $e) {
				if (in_array((int)$e['source'], [$fx['gA'], $fx['lA'], $fx['zA']], true) || in_array((int)$e['target'], [$fx['gA'], $fx['lA'], $fx['zA']], true)) {
					$errors[] = 'graph: Kante zu A-Notiz ' . json_encode($e);
				}
			}
		}
		$r = $b->call('GET', '/changes' . self::q(['workspace' => $fx['wsB'], 'note_ids' => "$gA,{$fx['lA']},{$fx['bN']}"]));
		$noMark('changes', $r);
		$notes = $r->json()['notes'] ?? [];
		foreach (['gA', 'lA'] as $k) {
			$n = $notes[(string)$fx[$k]] ?? null;
			if ($n === null || $n['exists'] !== false || $n['version'] !== 'missing' || $n['content_version'] !== null) {
				$errors[] = "changes: $k nicht als missing " . json_encode($n);
			}
		}
		if (($notes[(string)$fx['bN']]['exists'] ?? false) !== true) {
			$errors[] = 'changes Kontrolle: eigene B-Notiz fehlt';
		}
		$refused('changes mit A-Workspace', $b->call('GET', '/changes' . self::q(['workspace' => $fx['wsA'], 'note_ids' => (string)$gA])));
		// Suchbegriff ohne Marker (die Antwort wiederholt q)
		foreach (['/notes/search' => ['q' => 'Geheim'], '/notes/search/ids' => ['q' => 'geheim inhalt']] as $p => $q) {
			$r = $b->call('GET', $p . self::q($q));
			$noMark($p, $r);
			if (in_array($gA, array_map('intval', $r->json()['ids'] ?? []), true)) {
				$errors[] = "$p: ID von A";
			}
			if (($r->json()['results'] ?? []) !== []) {
				$errors[] = "$p: Treffer " . $r->describe();
			}
		}
		$noMark('with-attachments', $b->call('GET', '/notes/with-attachments'));
		$refused('Anhang-Liste', $b->call('GET', "/notes/$gA/attachments"));
		$refused('Anhang-Download', $b->call('GET', "/notes/$gA/attachments/{$fx['att']}"));
		$refused('Anhang über eigene Notiz', $b->call('GET', "/notes/{$fx['bN']}/attachments/{$fx['att']}"));
		$refused('Snapshots', $b->call('GET', "/notes/$gA/snapshots"));
		$r = $b->call('GET', "/notes/$gA/snapshot" . self::q(['snapshot_key' => $fx['snap']]));
		$noMark('Snapshot lesen', $r);
		if ($r->ok() && ($r->json()['exists'] ?? null) !== false) {
			$errors[] = 'Snapshot lesen: ' . $r->describe();
		}
		$refused('Sperre lesen', $b->call('GET', "/notes/$gA/lock"));
		$refused('Notiz lesen', $b->call('GET', "/notes/$gA"));
		return $errors;
	}

	public function testIndirectPathsWithoutShareAndAfterRevoke(): void {
		$a = $this->createUser('ia');
		$b = $this->createUser('ib');
		$m = 'Mk' . bin2hex(random_bytes(4));
		[$wsA, $wsAId] = self::newWorkspace($a, 'IndA');
		[$wsB] = self::newWorkspace($b, 'IndB');
		$bN = self::newNote($b, $wsB, 'B Ziel', '<p>b</p>');
		$gA = self::newNote($a, $wsA, "Geheim $m", "<p>Inhalt $m</p>");
		$lA = self::newNote($a, $wsA, "Verweis $m", "<p><a href=\"index.php?note=$gA\">x</a> $m</p>");
		// A verweist auf B's Notiz (Leck über B's Backlinks/Graph)
		$zA = self::newNote($a, $wsA, "ZuB $m", "<p><a href=\"index.php?note=$bN\">b</a> <span data-note-id=\"$bN\">b</span> $m</p>");
		// B verweist auf A's Notiz (Leck über B's Graph)
		self::newNote($b, $wsB, 'B Verweis', "<p><a href=\"index.php?note=$gA\">a</a></p>");
		$att = (int)self::ok($a->upload("/notes/$gA/attachments", "datei-$m.txt", "inhalt $m"), 'Anhang')['attachment_id'];
		$snap = (string)self::ok($a->call('POST', "/notes/$gA/snapshot?manual=1"), 'Snapshot')['snapshot_key'];
		self::ok($a->call('POST', "/notes/$gA/lock", ['editor_session_id' => 'ind-s1']), 'Sperre');
		$fx = ['mark' => $m, 'wsA' => $wsA, 'wsB' => $wsB, 'gA' => $gA, 'lA' => $lA, 'zA' => $zA, 'bN' => $bN, 'att' => $att, 'snap' => $snap];

		self::assertSame([], self::leaks($b, $fx), 'ohne Freigabe');

		// Kontrolle mit Freigabe: dieselben Pfade liefern A's Daten (Detektor greift)
		$svc = Server::get(WorkspaceService::class);
		$svc->share($a->uid, $wsAId, $b->uid);
		self::assertSame($gA, (int)self::ok($b->call('GET', '/notes/resolve' . self::q(['reference' => "Geheim $m"])), 'resolve Titel mit Freigabe')['id']);
		self::assertContains($lA, array_map(static fn ($x) => (int)$x['id'], self::ok($b->call('GET', "/notes/$gA/backlinks"), 'backlinks mit Freigabe')['backlinks']));
		self::assertContains($zA, array_map(static fn ($x) => (int)$x['id'], self::ok($b->call('GET', "/notes/$bN/backlinks" . self::q(['all_workspaces' => '1'])), 'B-backlinks mit Freigabe')['backlinks']));
		self::assertContains($gA, array_map(static fn ($x) => (int)$x['id'], self::ok($b->call('GET', '/notes/search' . self::q(['q' => 'Geheim'])), 'Suche mit Freigabe')['results']));
		self::assertContains($gA, self::ok($b->call('GET', '/notes/search/ids' . self::q(['q' => 'geheim inhalt'])), 'Such-IDs mit Freigabe')['ids']);
		self::assertSame("inhalt $m", $b->call('GET', "/notes/$gA/attachments/$att")->body, 'Anhang mit Freigabe');
		self::assertNotSame([], self::leaks($b, $fx), 'Detektor erkennt Freigabe nicht');

		$svc->unshare($a->uid, $wsAId, $b->uid);
		self::assertSame([], self::leaks($b, $fx), 'nach Entzug der Freigabe');
	}

	private const APP = '/index.php/apps/nextnotes/';

	private static function page(ApiClient $c, string $path): Response {
		return $c->absolute('GET', self::APP . $path);
	}

	/** Antworttext inkl. entpackter ZIP-Einträge (Namen + Inhalte) */
	private static function text(Response $r): string {
		if (!str_starts_with((string)$r->header('Content-Type'), 'application/zip')) {
			return $r->body;
		}
		$path = tempnam(sys_get_temp_dir(), 'nnind');
		file_put_contents($path, $r->body);
		$zip = new \ZipArchive();
		self::assertTrue($zip->open($path) === true, 'ZIP lesbar');
		$out = '';
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$out .= $zip->getNameIndex($i) . "\n" . $zip->getFromIndex($i) . "\n";
		}
		$zip->close();
		unlink($path);
		return $out;
	}

	/**
	 * M4-Lesepfade von B; $secrets dürfen nirgends erscheinen
	 * @return array<string, string> Pfad → Antworttext (für die Positivkontrolle)
	 */
	public static function m4Views(ApiClient $b, array $fx): array {
		$wq = 'workspace=' . self::enc($fx['wsB']);
		$out = [];
		foreach ([
			"dashboard.php?$wq", 'dashboard.php?scope=all', "mentions.php?$wq", 'mentions.php?handled=1',
			"graph.php?$wq", "kanban_content.php?ajax=1&folder_id={$fx['bF']}&$wq", "diary.php?$wq", "tasks.php?$wq",
			"notes_manager.php?$wq", "index.php?$wq&note={$fx['bRef']}",
		] as $p) {
			$out[$p] = self::page($b, $p)->body;
		}
		foreach ([
			'/graph?' . $wq, '/mentions', "/calendar/notes-by-date.php?mode=both&$wq", '/calendar/notes-by-date.php?mode=both&workspace=' . self::enc($fx['wsA']),
			"/calendar/notes-on-date.php?date={$fx['day']}&$wq", "/calendar/notes-on-date.php?date={$fx['day']}&mode=modified&$wq",
			"/calendar/diary-entry.php?date={$fx['day']}&diary={$fx['aDiary']}&$wq", "/diary/diaries.php?$wq",
			"/diary/entries.php?ids={$fx['gA']},{$fx['aEntry']},{$fx['bRef']}&$wq", "/diary/entries.php?ids={$fx['gA']},{$fx['aEntry']}&workspace=" . self::enc($fx['wsA']),
			"/notes/{$fx['bLink']}?$wq",
		] as $p) {
			$out["api$p"] = $b->call('GET', $p)->body;
		}
		foreach ([
			"api_export_note.php?id={$fx['bRef']}&format=html_embedded", "api_export_note.php?id={$fx['bRef']}&format=html",
			"api_export_note.php?id={$fx['bRef']}&format=markdown", "api_export_note.php?id={$fx['bRef']}&format=html&disposition=inline",
			"api_download_note.php?id={$fx['bRef']}", "api_export_folder.php?folder_id={$fx['bF']}", 'api_export_entries.php',
			'api_export_structured.php', "api_export_structured.php?$wq", 'api_export_attachments.php',
		] as $p) {
			$r = self::page($b, $p);
			self::assertSame(200, $r->status, "B $p: " . $r->describe());
			$out[$p] = self::text($r);
		}
		return $out;
	}

	public function testM4ViewsAndExportsWithoutShareAndAfterRevoke(): void {
		$a = $this->createUser('ma');
		$b = $this->createUser('mb');
		$m = 'Mv' . bin2hex(random_bytes(4));
		$bytes = "A-BYTES-$m";
		$day = gmdate('Y-m-d');
		[$wsA, $wsAId] = self::newWorkspace($a, 'IndA');
		[$wsB] = self::newWorkspace($b, 'IndB');
		$svc = Server::get(WorkspaceService::class);
		// A: Notiz mit Erwähnung von B, Anhang, Tagebuch mit Eintrag von heute
		$svc->share($a->uid, $wsAId, $b->uid, 'read');
		$gA = self::newNote($a, $wsA, "Geheim $m", "<p>Inhalt $m @{$b->uid}</p>");
		$att = (int)self::ok($a->upload("/notes/$gA/attachments", "bild-$m.png", $bytes, [], 'image/png'), 'Anhang')['attachment_id'];
		$aDiary = (int)self::ok($a->call('POST', '/diary/diaries.php', ['name' => "Buch $m", 'workspace' => $wsA]), 'Tagebuch A')['diary']['id'];
		$look = self::ok($a->call('GET', '/calendar/diary-entry.php?' . http_build_query(['date' => $day, 'workspace' => $wsA, 'diary' => $aDiary])), 'diary-entry A');
		$aEntry = (int)self::ok($a->call('POST', '/notes', ['heading' => $look['title'], 'folder_name' => $look['folder'], 'workspace' => $wsA,
			'content' => "<p>Eintrag $m</p>", 'created_date' => $day]), 'Eintrag A')['note']['id'];
		// B (mit Freigabe angelegt): Verknüpfung auf A in Kanban-Ordner, Speed Dial, Favorit; Notiz mit Verweisen auf A
		$bF = (int)self::ok($b->call('POST', '/folders', ['folder_name' => 'Board', 'workspace' => $wsB]), 'B-Ordner')['folder_id'];
		$bLink = (int)self::ok($b->call('POST', '/notes', ['heading' => 'Hülle', 'type' => 'linked', 'workspace' => $wsB, 'folder_id' => $bF, 'linked_note_id' => $gA]), 'Verknüpfung')['note']['id'];
		self::ok($b->call('PUT', "/notes/$bLink/tags", ['tags' => ['speed-dial'], 'workspace' => $wsB]), 'Speed Dial');
		self::ok($b->call('POST', "/notes/$bLink/favorite", []), 'Favorit');
		// Markdown-Notiz: alle Exportformate (html, html_embedded, markdown mit Anhängen) anwendbar
		$md = "[[$gA]]\n\n[x](index.php?note=$gA)\n\n![i](/api/v1/notes/$gA/attachments/$att)\n\n[d](api_attachments.php?action=download&note_id=$gA&attachment_id=$att)\n";
		$bRef = (int)self::ok($b->call('POST', '/notes', ['heading' => 'Verweise', 'type' => 'markdown', 'content' => $md, 'workspace' => $wsB, 'folder_id' => $bF]), 'B-Verweise')['note']['id'];
		$fx = ['wsA' => $wsA, 'wsB' => $wsB, 'gA' => $gA, 'aEntry' => $aEntry, 'aDiary' => $aDiary, 'bF' => $bF, 'bLink' => $bLink, 'bRef' => $bRef, 'day' => $day];
		$secrets = [$m, $bytes, base64_encode($bytes)];
		$leaks = static function (array $views) use ($secrets): array {
			$found = [];
			foreach ($views as $p => $body) {
				foreach ($secrets as $s) {
					if (str_contains($body, $s)) {
						$found[] = "$p enthält " . substr($s, 0, 12);
					}
				}
			}
			return $found;
		};

		// Positivkontrolle mit Lesefreigabe (Q2): Detektor sieht A-Daten über Verknüpfung, Erwähnung, Kanban
		$shared = self::m4Views($b, $fx);
		foreach (["kanban_content.php?ajax=1&folder_id=$bF&workspace=" . self::enc($wsB), 'api/mentions', "api_export_folder.php?folder_id=$bF"] as $p) {
			self::assertStringContainsString($m, $shared[$p], "Kontrolle mit Freigabe: $p zeigt A-Daten nicht");
		}
		self::assertNotSame([], $leaks($shared), 'Detektor erkennt Freigabe nicht');

		$svc->unshare($a->uid, $wsAId, $b->uid);
		self::assertSame([], $leaks(self::m4Views($b, $fx)), 'nach Entzug der Freigabe');
		// ohne je geteilt: C mit denselben Verweisen (Verknüpfung per DB, API verweigert fremdes Ziel)
		$c = $this->createUser('mc');
		[$wsC] = self::newWorkspace($c, 'IndC');
		$cF = (int)self::ok($c->call('POST', '/folders', ['folder_name' => 'Board', 'workspace' => $wsC]), 'C-Ordner')['folder_id'];
		$r = $c->call('POST', '/notes', ['heading' => 'Hülle', 'type' => 'linked', 'workspace' => $wsC, 'folder_id' => $cF, 'linked_note_id' => $gA]);
		self::assertSame(404, $r->status, 'Verknüpfung auf fremde Notiz: ' . $r->describe());
		$spare = self::newNote($c, $wsC, 'Platzhalter', '<p>p</p>');
		$cLink = (int)self::ok($c->call('POST', '/notes', ['heading' => 'Hülle', 'type' => 'linked', 'workspace' => $wsC, 'folder_id' => $cF, 'linked_note_id' => $spare]), 'C-Verknüpfung')['note']['id'];
		$qb = self::db()->getQueryBuilder();
		$qb->update('nextnotes_notes')->set('linked_note_id', $qb->createNamedParameter($gA))->where($qb->expr()->eq('id', $qb->createNamedParameter($cLink)))->executeStatement();
		self::ok($c->call('PUT', "/notes/$cLink/tags", ['tags' => ['speed-dial'], 'workspace' => $wsC]), 'C Speed Dial');
		$cRef = (int)self::ok($c->call('POST', '/notes', ['heading' => 'Verweise', 'type' => 'markdown', 'content' => $md, 'workspace' => $wsC, 'folder_id' => $cF]), 'C-Verweise')['note']['id'];
		$fxC = ['wsB' => $wsC, 'bF' => $cF, 'bLink' => $cLink, 'bRef' => $cRef] + $fx;
		self::assertSame([], $leaks(self::m4Views($c, $fxC)), 'ohne Freigabe');
		// Einzelexport der verknüpften Notiz ohne Leserecht → 404 (B42)
		foreach (["api_export_note.php?id=$cLink", "api_export_note.php?id=$cLink&format=html", "api_download_note.php?id=$cLink"] as $p) {
			$r = self::page($c, $p);
			self::assertSame(404, $r->status, "C $p: " . $r->describe());
			self::assertStringNotContainsString($m, $r->body, "C $p");
		}
	}
}
