<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Teams;

use OCA\NextNotes\Tests\Access\IndirectReadTest;
use OCA\NextNotes\Tests\Support\ApiClient;
use OCA\NextNotes\Tests\Support\Response;
use OCA\NextNotes\Tests\Support\Teams;
use OCA\NextNotes\Tests\Support\TeamTestCase;
use OCP\Server;

// Plan M5e: Seiten-, Export-, Kompatibilitäts- und indirekte Lesepfade (PageIsolation/IndirectRead) über einen Team-Workspace
// für Ex-Mitglied, Eingeladenen und Mitglied bei eingefrorenem Team; Positivkontrolle als Mitglied (Detektor)
final class TeamIsolationTest extends TeamTestCase {
	private const APP = '/index.php/apps/nextnotes/';
	private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

	private static function occ(string ...$args): int {
		exec('php /var/www/html/occ ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $out, $rc);
		return $rc;
	}

	// ZIP-Exporte 10/min je Benutzer (DatabaseBackend ohne Redis): zwischen den Zuständen zurücksetzen
	private static function resetRateLimits(): void {
		self::db()->getQueryBuilder()->delete('ratelimit_entries')->executeStatement();
	}

	private static function page(ApiClient $c, string $path): Response {
		return $c->absolute('GET', self::APP . $path);
	}

	/** Antworttext inkl. ZIP-Einträge */
	private static function text(Response $r): string {
		if (!str_starts_with((string)$r->header('Content-Type'), 'application/zip')) {
			return $r->body;
		}
		$path = tempnam(sys_get_temp_dir(), 'nntiso');
		file_put_contents($path, $r->body);
		$zip = new \ZipArchive();
		$out = '';
		if ($zip->open($path) === true) {
			for ($i = 0; $i < $zip->numFiles; $i++) {
				$out .= $zip->getNameIndex($i) . "\n" . $zip->getFromIndex($i) . "\n";
			}
			$zip->close();
		}
		unlink($path);
		return $out;
	}

	/** Team T (Owner A, Mitglied B, eingeladen D), Team-Workspace von A mit allen Objektarten; B mit eigenen Verweisen auf A */
	private function fixture(): array {
		$a = $this->createUser('ti');
		$b = $this->createUser('tj');
		$d = $this->createUser('tk');
		$t = $this->teams->create($a->uid, 'Iso ' . bin2hex(random_bytes(3)));
		$memberB = $this->teams->addUser($t, $a->uid, $b->uid);
		$this->teams->setConfig($t, Teams::INVITE);
		$this->teams->addUser($t, $a->uid, $d->uid);
		[$ws, $wsId] = self::teamWs($a, $t, 'Iso');
		$m = 'Tiso' . bin2hex(random_bytes(4));
		$bytes = "A-BYTES-$m";
		$day = gmdate('Y-m-d');
		$f = (int)self::ok($a->call('POST', '/folders', ['folder_name' => "Board $m", 'workspace' => $ws]), 'Ordner')['folder_id'];
		$s = (int)self::ok($a->call('POST', '/folders', ['folder_name' => 'Spalte', 'parent_folder_id' => $f, 'workspace' => $ws]), 'Unterordner')['folder_id'];
		[$wsB] = self::newWorkspace($b, 'Eigen');
		$bN = self::newNote($b, $wsB, 'B Ziel', '<p>b</p>');
		$gA = self::newNote($a, $ws, "Geheim $m", "<p>Inhalt $m @{$b->uid}</p>", $f);
		$lA = self::newNote($a, $ws, "Verweis $m", "<p><a href=\"index.php?note=$gA\">x</a> $m</p>");
		$zA = self::newNote($a, $ws, "ZuB $m", "<p><a href=\"index.php?note=$bN\">b</a> <span data-note-id=\"$bN\">b</span> $m</p>");
		$tasks = json_encode([['id' => 1, 'text' => "Task $m", 'completed' => false]], JSON_THROW_ON_ERROR);
		$tl = (int)self::ok($a->call('POST', '/notes', ['heading' => "Aufgaben $m", 'type' => 'tasklist', 'content' => $tasks, 'folder_id' => $s, 'workspace' => $ws]), 'Aufgabenliste')['note']['id'];
		$att = (int)self::ok($a->upload("/notes/$gA/attachments", "bild-$m.png", $bytes, [], 'image/png'), 'Anhang')['attachment_id'];
		$snap = (string)self::ok($a->call('POST', "/notes/$gA/snapshot?manual=1"), 'Snapshot')['snapshot_key'];
		self::ok($a->call('POST', "/notes/$gA/lock", ['editor_session_id' => 'tiso-s1']), 'Sperre');
		self::ok($a->call('PUT', "/notes/$gA/tags", ['tags' => ['speed-dial', "tag$m"], 'workspace' => $ws]), 'Tags');
		$aDiary = (int)self::ok($a->call('POST', '/diary/diaries.php', ['name' => "Buch $m", 'workspace' => $ws]), 'Tagebuch')['diary']['id'];
		$look = self::ok($a->call('GET', '/calendar/diary-entry.php?' . http_build_query(['date' => $day, 'workspace' => $ws, 'diary' => $aDiary])), 'diary-entry');
		$aEntry = (int)self::ok($a->call('POST', '/notes', ['heading' => $look['title'], 'folder_name' => $look['folder'], 'workspace' => $ws,
			'content' => "<p>Eintrag $m</p>", 'created_date' => $day]), 'Eintrag')['note']['id'];
		self::ok($a->uploadField(self::APP . 'api_upload_background.php', 'background', 'bg.png', (string)base64_decode(self::PNG), ['workspace' => $ws, 'scope' => 'background'], 'image/png'), 'Hintergrund');
		// B (als Mitglied): Verweise in eigenem Workspace (Verknüpfung, Speed Dial, Favorit, Markdown-Verweise)
		self::newNote($b, $wsB, 'B Verweis', "<p><a href=\"index.php?note=$gA\">a</a></p>");
		$bF = (int)self::ok($b->call('POST', '/folders', ['folder_name' => 'Board', 'workspace' => $wsB]), 'B-Ordner')['folder_id'];
		$bLink = (int)self::ok($b->call('POST', '/notes', ['heading' => 'Hülle', 'type' => 'linked', 'workspace' => $wsB, 'folder_id' => $bF, 'linked_note_id' => $gA]), 'Verknüpfung')['note']['id'];
		self::ok($b->call('PUT', "/notes/$bLink/tags", ['tags' => ['speed-dial'], 'workspace' => $wsB]), 'B Speed Dial');
		self::ok($b->call('POST', "/notes/$bLink/favorite", []), 'B Favorit');
		$md = "[[$gA]]\n\n[x](index.php?note=$gA)\n\n![i](/api/v1/notes/$gA/attachments/$att)\n\n[d](api_attachments.php?action=download&note_id=$gA&attachment_id=$att)\n";
		$bRef = (int)self::ok($b->call('POST', '/notes', ['heading' => 'Verweise', 'type' => 'markdown', 'content' => $md, 'workspace' => $wsB, 'folder_id' => $bF]), 'B-Verweise')['note']['id'];
		// D: eigener Workspace als Gegenstück
		[$wsD] = self::newWorkspace($d, 'EigenD');
		$dN = self::newNote($d, $wsD, 'D Ziel', '<p>d</p>');
		return ['a' => $a, 'b' => $b, 'd' => $d, 't' => $t, 'memberB' => $memberB, 'ws' => $ws, 'wsId' => $wsId, 'mark' => $m, 'bytes' => $bytes,
			'f' => $f, 's' => $s, 'tl' => $tl, 'snap' => $snap, 'day' => $day, 'dN' => $dN, 'wsD' => $wsD,
			'fx' => ['mark' => $m, 'wsA' => $ws, 'wsB' => $wsB, 'gA' => $gA, 'lA' => $lA, 'zA' => $zA, 'bN' => $bN, 'att' => $att, 'snap' => $snap,
				'aEntry' => $aEntry, 'aDiary' => $aDiary, 'bF' => $bF, 'bLink' => $bLink, 'bRef' => $bRef, 'day' => $day]];
	}

	/** @return string[] Lecks von $c auf A's Team-Workspace über Seiten, Exporte, Kompatibilitätsrouten und indirekte Pfade */
	private static function leaks(ApiClient $c, array $x, bool $withM4Views, bool $writes = true): array {
		$fx = $x['fx'];
		$m = $x['mark'];
		$secrets = [$m, $x['bytes'], base64_encode($x['bytes'])];
		$wq = 'workspace=' . self::enc($x['ws']);
		['gA' => $n, 'att' => $at] = $fx;
		['f' => $f, 's' => $s, 'tl' => $tl] = $x;
		self::resetRateLimits();
		$errors = [];
		$scan = static function (string $what, Response $r, array $status) use ($secrets, &$errors): void {
			$body = self::text($r) . (string)$r->header('Location');
			foreach ($secrets as $sec) {
				if (str_contains($body, $sec)) {
					$errors[] = "$what: Daten von A sichtbar ($r->status)";
					break;
				}
			}
			if (!in_array($r->status, $status, true)) {
				$errors[] = "$what: Status $r->status statt " . implode('/', $status);
			}
		};
		// ID-Stellen (Seiten, Exporte, Kompatibilität) → 404
		foreach (["index.php?note=$n", "index.php?$wq&note=$n", "kanban_content.php?ajax=1&folder_id=$f", "kanban_content.php?folder_id=$s&$wq",
			"revisions.php?note_id=$n", "attachments.php?note_id=$n&$wq", "api_note_info.php?note_id=$tl", "api_download_note.php?id=$n",
			"api_export_note.php?id=$n", "api_export_note.php?id=$n&format=markdown", "api_export_folder.php?folder_id=$f",
			"api_attachments.php?action=download&note_id=$n&attachment_id=$at", "audio_player.php?note=$n&attachment=$at",
			"excalidraw_editor.php?note_id=$n&$wq", "api_export_entries.php?$wq", "api_export_structured.php?$wq",
			"api_export_structured.php?workspace={$x['wsId']}", "api_upload_background.php?$wq&image=1"] as $p) {
			$scan("GET $p", self::page($c, $p), [404]);
		}
		foreach (['left_col', 'right_col'] as $frag) {
			$scan("Fragment $frag", $c->withHeaders(["X-Poznote-Fragment: $frag"])->absolute('GET', self::APP . "index.php?$wq&note=$n"), [404]);
		}
		// Listen-Seiten mit A's Workspace → eigener Standard (200), nichts von A; eigene ZIP-Exporte ohne A
		foreach (["index.php?$wq", "?workspace={$x['wsId']}", "dashboard.php?$wq", 'dashboard.php?scope=all', "graph.php?$wq",
			"mentions.php?$wq", 'mentions.php?handled=1', "diary.php?$wq", "diary.php?$wq&diary={$fx['aDiary']}",
			"tasks.php?$wq", "settings.php?$wq", "trash.php?$wq", "list_tags.php?$wq", "list_folders.php?$wq", "attachments_list.php?$wq",
			"notes_manager.php?$wq", "storage-stats-user.php?fragment=1&$wq",
			'api_export_entries.php', 'api_export_structured.php', 'api_export_attachments.php'] as $p) {
			$scan("GET $p", self::page($c, $p), [200]);
		}
		// App-Start und alte Erstellen-Seite leiten weiter (UI-rail)
		$scan('GET / (App-Start)', self::page($c, ''), [303]);
		$scan("GET create.php?$wq", self::page($c, "create.php?$wq"), [303]);
		$scan("GET speed_dial.php?$wq", self::page($c, "speed_dial.php?$wq"), [303]);
		$scan('POST index.php Suche', $c->form(self::APP . 'index.php', ['search' => 'Geheim', 'workspace' => $x['ws']]), [200]);
		$scan('GET workspaces.php', self::page($c, "workspaces.php?$wq"), [302, 303]);
		// API: Liste, Lesen, Suche, Unified Search
		$scan('GET /workspaces', $c->call('GET', '/workspaces'), [200]);
		if (str_contains($c->call('GET', '/workspaces')->body, json_encode($x['ws']))) {
			$errors[] = 'GET /workspaces listet den Team-Workspace';
		}
		$scan('GET /notes?workspace', $c->call('GET', '/notes' . self::q(['workspace' => $x['ws']])), [404]);
		$scan('GET /folders?workspace', $c->call('GET', '/folders' . self::q(['workspace' => $x['ws']])), [200, 404]);
		$scan('Unified Search', $c->absolute('GET', '/ocs/v2.php/search/providers/nextnotes/search?format=json&term=' . rawurlencode($m)), [200]);
		// Schreiben (API, Seiten-APIs, workspaces.php) → 403/404, nichts geändert
		foreach (!$writes ? [] : [['PATCH', "/notes/$n", ['heading' => 'gekapert', 'content' => '<p>x</p>']], ['POST', '/notes', ['heading' => 'x', 'workspace' => $x['ws']]],
			['POST', '/notes', ['heading' => 'x', 'content' => '', 'folder_id' => $s]], ['DELETE', "/notes/$n/attachments/$at", null],
			['POST', "/notes/$n/snapshot", ['manual' => true]], ['POST', "/notes/$n/kanban-completed", ['completed' => true]],
			['POST', '/folders', ['folder_name' => 'x', 'workspace' => $x['ws']]], ['DELETE', '/workspaces/' . rawurlencode($x['ws']), null]] as [$verb, $p, $body]) {
			$scan("$verb $p", $c->call($verb, $p, $body), [403, 404]);
		}
		foreach (!$writes ? [] : ['rename' => ['new_name' => 'Gekapert'], 'set_color' => ['color' => '#ff0000'], 'set_tags' => ['tags' => 'gekapert'], 'delete' => [],
			'share_workspace' => ['allowed_users' => '[]'], 'unshare_workspace' => []] as $act => $extra) {
			$scan("workspaces.php $act", $c->form(self::APP . 'workspaces.php', ['action' => $act, 'name' => $x['ws']] + $extra), [403, 404]);
		}
		if ($writes) {
			$scan('Hintergrund löschen', $c->absolute('DELETE', self::APP . "api_upload_background.php?$wq&scope=background"), [400, 404]);
			$scan('Import', $c->uploadFiles(self::APP . 'api_import_dropped_notes.php', [['import.md', "# x\n"]], ['workspace' => $x['ws'], 'file_count' => 1]), [400, 404]);
		}
		// indirekte Lesepfade (IndirectReadTest) über eigene Objekte, die auf A zeigen
		foreach (IndirectReadTest::leaks($c, $fx) as $e) {
			$errors[] = "indirekt: $e";
		}
		if ($withM4Views) {
			self::resetRateLimits();
			foreach (IndirectReadTest::m4Views($c, $fx) as $p => $body) {
				foreach ($secrets as $sec) {
					if (str_contains($body, $sec)) {
						$errors[] = "M4-Ansicht $p: Daten von A";
						break;
					}
				}
			}
		}
		return $errors;
	}

	private static function contentOfA(array $x): array {
		$q = self::db()->getQueryBuilder();
		$q->select('*')->from('nextnotes_notes')
			->where($q->expr()->eq('workspace_id', $q->createNamedParameter($x['wsId'])))->orderBy('id');
		$notes = $q->executeQuery()->fetchAllAssociative();
		$q = self::db()->getQueryBuilder();
		$q->select('*')->from('nextnotes_workspaces')->where($q->expr()->eq('id', $q->createNamedParameter($x['wsId'])));
		return [$notes, $q->executeQuery()->fetchAssociative()];
	}

	public function testFormerInvitedAndFrozenSeeNothing(): void {
		$x = $this->fixture();
		['a' => $a, 'b' => $b, 'd' => $d] = $x;
		$before = self::contentOfA($x);

		// Positivkontrolle: Mitglied B erreicht A's Daten über dieselben Pfade (Detektor greift auf Seiten, Exporte und indirekte Pfade)
		$member = self::leaks($b, $x, true, false);
		foreach (['GET index.php?note=', 'GET api_export_note.php', 'GET kanban_content.php', 'indirekt: resolve', 'M4-Ansicht'] as $probe) {
			self::assertNotSame([], array_filter($member, static fn ($e) => str_starts_with($e, $probe)), "Detektor erkennt Mitgliedschaft nicht: $probe");
		}
		self::assertSame(200, self::page($b, "api_export_note.php?id={$x['fx']['gA']}")->status, 'Kontrolle: B exportiert als Mitglied');
		self::assertSame($before, self::contentOfA($x), 'Kontrolle: Lesen als Mitglied ändert nichts');

		// eingeladen, nicht beigetreten
		$fxD = ['wsB' => $x['wsD'], 'bN' => $x['dN']] + $x['fx'];
		self::assertSame([], self::leaks($d, ['fx' => $fxD] + $x, false), 'eingeladenes Mitglied D');

		// eingefroren (Circles aus): Mitglied B sieht nichts, created_by A liest weiter
		self::assertSame(0, self::occ('app:disable', 'circles'));
		try {
			sleep(4);
			Teams::reset();
			self::assertSame([], self::leaks($b, $x, true), 'Mitglied B bei eingefrorenem Team');
			self::ok($a->call('GET', "/notes/{$x['fx']['gA']}"), 'eingefroren: created_by liest');
		} finally {
			self::occ('app:enable', 'circles');
			sleep(4);
			Teams::reset();
		}
		self::assertSame('active', self::teamState($x['t']), 'Zustand bleibt active');

		// Ex-Mitglied (ohne Job)
		$this->teams->remove($a->uid, $x['memberB']);
		self::assertSame([], self::leaks($b, $x, true), 'Ex-Mitglied B');
		self::assertSame($before, self::contentOfA($x), 'Fremdzugriffe haben den Team-Workspace verändert');
		self::ok($a->call('GET', "/notes/{$x['fx']['gA']}"), 'A liest weiter');
	}
}
