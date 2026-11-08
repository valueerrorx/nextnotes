<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Scenario;

use OCA\NextNotes\Tests\Support\ApiClient;
use OCA\NextNotes\Tests\Support\ApiTestCase;
use OCA\NextNotes\Tests\Support\Response;
use OCA\NextNotes\Service\TaskService;
use OCP\App\IAppManager;
use OCP\Config\IUserConfig;
use OCP\Server;

// Done M2a 4, M2b 2, M2c 4 / SPEC §13 M2.3: Kern-Szenario, A und B parallel; JSON-Schlüssel = Upstream-Fixtures (Plan §3.4)
final class CoreScenarioTest extends ApiTestCase {
	/** @var array<string, Response> Route → erste 2xx-Antwort (sonst letzte) */
	private array $recorded = [];

	private function rec(string $route, Response $r): array {
		if (!isset($this->recorded[$route]) || (!$this->recorded[$route]->ok() && $r->ok())) {
			$this->recorded[$route] = $r;
		}
		self::assertTrue($r->ok(), "$route: " . $r->describe());
		$j = $r->json();
		return is_array($j) ? $j : [];
	}

	private static function fixtureDir(): string {
		return dirname(__DIR__, 2) . '/fixtures';
	}

	/** Schlüsselunterschiede rekursiv: Objekte → Schlüsselmenge, Listen → erstes Element */
	private static function keyDiff(mixed $want, mixed $got, string $path, array &$out): void {
		if (!($want instanceof \stdClass) || !($got instanceof \stdClass)) {
			if (is_array($want) && is_array($got) && $want !== [] && $got !== []) {
				self::keyDiff($want[0], $got[0], $path . '[]', $out);
			}
			return;
		}
		// Zahlen-Schlüssel sind IDs (Map), nicht Teil des Formats
		$named = static fn (\stdClass $o) => array_values(array_filter(array_map('strval', array_keys(get_object_vars($o))), static fn ($x) => !ctype_digit($x)));
		$wk = $named($want);
		$gk = $named($got);
		foreach (array_diff($wk, $gk) as $k) {
			$out[] = ['path' => ltrim("$path.$k", '.'), 'kind' => 'missing'];
		}
		foreach (array_diff($gk, $wk) as $k) {
			$out[] = ['path' => ltrim("$path.$k", '.'), 'kind' => 'extra'];
		}
		foreach (array_intersect($wk, $gk) as $k) {
			self::keyDiff($want->$k, $got->$k, ltrim("$path.$k", '.'), $out);
		}
	}

	public function testCoreScenarioAndFixtureKeys(): void {
		$a = $this->createUser('sa');
		$b = $this->createUser('sb');
		$bMark = 'bmk' . bin2hex(random_bytes(4));
		$ws = 'Fixture ' . bin2hex(random_bytes(2));
		$ws2 = $ws . ' neu';
		$q = self::q(['workspace' => $ws2]);

		// B arbeitet parallel im gleichnamigen Workspace
		self::ok($b->call('POST', '/workspaces', ['name' => $ws]), 'B Workspace');
		self::ok($b->call('POST', '/notes', ['heading' => "B $bMark", 'content' => "<p>$bMark</p>", 'tags' => 'ta tb', 'workspace' => $ws]), 'B Notiz');
		self::ok($b->call('POST', '/folders', ['folder_name' => "Eins", 'workspace' => $ws]), 'B Ordner');
		$bBefore = self::dump($b->uid);

		$this->rec('GET /workspaces', $a->call('GET', '/workspaces'));
		$this->rec('POST /workspaces', $a->call('POST', '/workspaces', ['name' => $ws]));
		$this->rec('PATCH /workspaces/{name}', $a->call('PATCH', '/workspaces/' . self::enc($ws), ['new_name' => $ws2]));
		self::assertContains($ws2, self::workspaceNames($a));
		self::assertNotContains($ws, self::workspaceNames($a));

		$f1 = $this->rec('POST /folders', $a->call('POST', '/folders', ['folder_name' => 'Eins', 'workspace' => $ws2]))['folder_id'];
		$f2 = self::ok($a->call('POST', '/folders', ['folder_name' => 'Zwei', 'workspace' => $ws2]), 'f2')['folder_id'];
		$f3 = self::ok($a->call('POST', '/folders', ['folder_name' => 'Drei', 'workspace' => $ws2]), 'f3')['folder_id'];
		$this->rec('GET /folders', $a->call('GET', '/folders' . $q . '&hierarchical=true'));
		$this->rec('GET /folders/{id}', $a->call('GET', "/folders/$f1$q"));

		$n1 = $this->rec('POST /notes', $a->call('POST', '/notes', ['heading' => 'Notiz eins', 'content' => '<p>Inhalt</p>', 'tags' => 'ta tb', 'workspace' => $ws2, 'folder_id' => $f1]))['note']['id'];
		$n2 = self::ok($a->call('POST', '/notes', ['heading' => 'Notiz zwei', 'content' => '<p>zwei</p>', 'workspace' => $ws2]), 'n2')['note']['id'];
		$n3 = self::ok($a->call('POST', '/notes', ['heading' => 'Notiz drei', 'content' => '<p>drei</p>', 'workspace' => $ws2]), 'n3')['note']['id'];
		$this->rec('GET /notes', $a->call('GET', '/notes' . $q));
		$show = $a->call('GET', "/notes/$n1$q");
		$this->rec('GET /notes/{id}', $show);
		$this->rec('GET /notes/templates', $a->call('GET', '/notes/templates' . $q));

		// ETag / If-None-Match wie Upstream
		$etag = $show->header('etag');
		self::assertNotNull($etag, 'GET /notes/{id} ohne ETag');
		self::assertSame(304, $a->call('GET', "/notes/$n1$q", null, ['If-None-Match: ' . $etag])->status, 'If-None-Match → 304');

		$this->rec('PATCH /notes/{id}', $a->call('PATCH', "/notes/$n1", ['heading' => 'Notiz eins b', 'content' => '<p>neu</p>']));
		$after = $a->call('GET', "/notes/$n1$q", null, ['If-None-Match: ' . $etag]);
		self::assertSame(200, $after->status, 'nach Änderung alter ETag → 200');
		self::assertSame('Notiz eins b', $after->json()['note']['heading']);
		self::assertStringContainsString('neu', $after->json()['note']['content']);

		$this->rec('POST /notes/{id}/beacon', $a->call('POST', "/notes/$n1/beacon", ['content' => '<p>beacon</p>']));
		$this->rec('PUT /notes/{id}/tags', $a->call('PUT', "/notes/$n1/tags", ['tags' => 'ta tb']));
		$this->rec('PUT /notes/{id}/icon', $a->call('PUT', "/notes/$n1/icon", ['icon' => 'lucide-star', 'icon_color' => '#ff0000']));
		$this->rec('PUT /notes/{id}/color', $a->call('PUT', "/notes/$n1/color", ['color' => '#00ff00']));
		$this->rec('PUT /notes/{id}/pinned', $a->call('PUT', "/notes/$n1/pinned", ['pinned' => true]));
		$this->rec('PUT /notes/{id}/content-width', $a->call('PUT', "/notes/$n1/content-width", ['content_width' => 80]));
		$this->rec('PUT /notes/{id}/title-style', $a->call('PUT', "/notes/$n1/title-style", ['title_color' => '#112233', 'title_size' => 24]));
		$this->rec('POST /notes/{id}/favorite', $a->call('POST', "/notes/$n1/favorite" . $q));
		$this->rec('POST /notes/{id}/opened', $a->call('POST', "/notes/$n1/opened"));
		$this->rec('POST /notes/{id}/folder', $a->call('POST', "/notes/$n1/folder", ['folder_id' => $f2, 'workspace' => $ws2]));
		$this->rec('POST /notes/{id}/remove-folder', $a->call('POST', "/notes/$n1/remove-folder" . $q));
		$this->rec('POST /notes/{id}/kanban-completed', $a->call('POST', "/notes/$n1/kanban-completed", ['completed' => true]));
		$this->rec('POST /notes/reorder', $a->call('POST', '/notes/reorder', ['note_id' => $n2, 'target_note_id' => $n1, 'position' => 'before', 'workspace' => $ws2]));
		self::ok($a->call('POST', "/notes/$n1/folder", ['folder_id' => $f2, 'workspace' => $ws2]), 'n1 → f2');
		self::assertSame((int)$f2, (int)self::ok($a->call('GET', "/notes/$n1$q"), 'n1')['note']['folder_id']);

		$this->rec('GET /folders/counts', $a->call('GET', '/folders/counts' . $q));
		$this->rec('GET /folders/suggested', $a->call('GET', '/folders/suggested' . $q));
		$this->rec('GET /folders/{id}/notes', $a->call('GET', "/folders/$f2/notes$q"));
		$this->rec('GET /folders/{id}/path', $a->call('GET', "/folders/$f2/path$q"));
		$this->rec('PATCH /folders/{id}', $a->call('PATCH', "/folders/$f1", ['name' => 'Eins neu', 'workspace' => $ws2]));
		self::assertSame('Eins neu', self::ok($a->call('GET', "/folders/$f1$q"), 'f1')['folder']['name'] ?? null, 'Ordner umbenannt');
		$this->rec('PUT /folders/{id}/icon', $a->call('PUT', "/folders/$f1/icon", ['icon' => 'lucide-star', 'icon_color' => '#ff0000']));
		$this->rec('PUT /folders/{id}/color', $a->call('PUT', "/folders/$f1/color", ['color' => '#00ff00']));
		$this->rec('PUT /folders/{id}/pinned', $a->call('PUT', "/folders/$f1/pinned", ['pinned' => true]));
		$this->rec('PUT /folders/{id}/favorite', $a->call('PUT', "/folders/$f1/favorite", ['favorite' => true]));
		$this->rec('POST /folders/{id}/tags', $a->call('POST', "/folders/$f2/tags", ['tags' => ['tc'], 'workspace' => $ws2]));
		$this->rec('DELETE /folders/{id}/tags', $a->call('DELETE', "/folders/$f2/tags", ['tags' => ['tc'], 'workspace' => $ws2]));
		$this->rec('POST /folders/move-files', $a->call('POST', '/folders/move-files', ['source_folder_id' => $f2, 'target_folder_id' => $f1, 'workspace' => $ws2]));
		self::assertSame((int)$f1, (int)self::ok($a->call('GET', "/notes/$n1$q"), 'n1')['note']['folder_id'], 'move-files');
		$this->rec('POST /folders/{id}/move', $a->call('POST', "/folders/$f2/move", ['new_parent_folder_id' => $f1, 'workspace' => $ws2]));
		self::assertStringContainsString('Eins neu', (string)self::ok($a->call('GET', "/folders/$f2/path$q"), 'path')['path'], 'Ordner verschoben');
		$this->rec('POST /folders/reorder', $a->call('POST', '/folders/reorder', ['folder_id' => $f3, 'target_folder_id' => $f1, 'position' => 'before', 'workspace' => $ws2]));

		// Tags setzen/umbenennen/löschen
		$tags = $this->rec('GET /tags', $a->call('GET', '/tags' . $q))['tags'];
		self::assertContains('ta', $tags);
		$this->rec('PATCH /tags/{tag}', $a->call('PATCH', '/tags/ta', ['new_name' => 'tz', 'workspace' => $ws2]));
		$this->rec('DELETE /tags/{tag}', $a->call('DELETE', '/tags/tb' . $q));
		$tags = self::ok($a->call('GET', '/tags' . $q), 'tags')['tags'];
		self::assertContains('tz', $tags);
		self::assertNotContains('ta', $tags);
		self::assertNotContains('tb', $tags);

		$this->rec('POST /notes/favorites/clear', $a->call('POST', '/notes/favorites/clear', ['workspace' => $ws2]));

		// Papierkorb / wiederherstellen / endgültig löschen
		$this->rec('DELETE /notes/{id}', $a->call('DELETE', "/notes/$n2$q"));
		$trash = $this->rec('GET /trash', $a->call('GET', '/trash' . $q));
		self::assertContains((int)$n2, array_map(static fn ($n) => (int)$n['id'], $trash['notes']));
		$this->rec('POST /notes/{id}/restore', $a->call('POST', "/notes/$n2/restore$q"));
		self::assertSame(200, $a->call('GET', "/notes/$n2$q")->status);
		self::ok($a->call('DELETE', "/notes/$n2$q"), 'n2 erneut in Papierkorb');
		$this->rec('DELETE /trash/{id}', $a->call('DELETE', "/trash/$n2$q"));
		self::assertSame(404, $a->call('GET', "/notes/$n2$q")->status, 'endgültig gelöschte Notiz');
		$this->rec('POST /folders/{id}/empty', $a->call('POST', "/folders/$f1/empty$q"));
		$this->rec('DELETE /trash', $a->call('DELETE', '/trash' . $q));
		self::assertSame([], self::ok($a->call('GET', '/trash' . $q), 'trash')['notes']);

		// Ordner löschen und wiederherstellen
		$snap = $this->rec('DELETE /folders/{id}', $a->call('DELETE', "/folders/$f3$q"));
		self::assertSame(404, $a->call('GET', "/folders/$f3$q")->status, 'Ordner gelöscht');
		self::assertArrayHasKey('restore_snapshot', $snap);
		$this->rec('POST /folders/restore', $a->call('POST', '/folders/restore', $snap['restore_snapshot']));

		$m1 = $this->m2bSteps($a, $ws2, (int)$f1);
		$this->m2cSteps($a, $b, $ws2, $m1);
		// M4f: Kalender/Tagebuch (A #186–190); Fixtures aus dem Upstream-Quelltext abgeleitet (api/v1/calendar|diary/*.php)
		$this->rec('POST /diary/diaries.php', $a->call('POST', '/diary/diaries.php', ['name' => 'Fixture-Tagebuch', 'workspace' => $ws2]));
		$this->rec('GET /diary/diaries.php', $a->call('GET', '/diary/diaries.php' . $q));
		$de = $this->rec('GET /calendar/diary-entry.php', $a->call('GET', '/calendar/diary-entry.php' . self::q(['date' => '2026-02-03', 'workspace' => $ws2])));
		$entry = (int)self::ok($a->call('POST', '/notes', ['heading' => $de['title'], 'folder_name' => $de['folder'], 'workspace' => $ws2, 'created_date' => '2026-02-03']), 'Tagebuch-Eintrag')['note']['id'];
		$this->rec('GET /calendar/notes-on-date.php', $a->call('GET', '/calendar/notes-on-date.php' . self::q(['date' => '2026-02-03', 'workspace' => $ws2])));
		$this->rec('GET /diary/entries.php', $a->call('GET', '/diary/entries.php' . self::q(['ids' => $entry, 'workspace' => $ws2])));
		$empty = $ws . ' leer';
		self::ok($a->call('POST', '/workspaces', ['name' => $empty]), 'leerer Workspace');
		$this->rec('GET /calendar/notes-by-date.php', $a->call('GET', '/calendar/notes-by-date.php' . self::q(['workspace' => $empty])));

		$n4 = self::ok($a->call('POST', '/notes', ['heading' => 'Archiv', 'content' => '<p>a</p>', 'workspace' => $ws2, 'folder_id' => $f1]), 'n4')['note']['id'];
		$this->rec('POST /notes/{id}/archive', $a->call('POST', "/notes/$n4/archive"));
		$this->rec('POST /folders/{id}/archive', $a->call('POST', "/folders/$f2/archive"));
		$this->rec('DELETE /notes/{id}?permanent', $a->call('DELETE', "/notes/$n3?permanent=true$q"));
		self::assertSame(404, $a->call('GET', "/notes/$n3$q")->status);
		$this->rec('DELETE /workspaces/{name}', $a->call('DELETE', '/workspaces/' . self::enc($ws2)));
		self::assertNotContains($ws2, self::workspaceNames($a));

		// A hat nie B's Daten gesehen, B ist unverändert
		foreach ($this->recorded as $route => $r) {
			self::assertStringNotContainsString($bMark, $r->body, "$route zeigt B's Daten");
		}
		self::assertSame($bBefore, self::dump($b->uid), 'B durch A verändert');

		$this->compareFixtures();
	}

	// M2b: Suche, Links, Anhänge, Sperre, Snapshots, Duplizieren (gleiche Schritte wie dev/upstream/capture.php)
	private function m2bSteps(ApiClient $a, string $ws2, int $f1): int {
		$q = self::q(['workspace' => $ws2]);
		$m1 = self::ok($a->call('POST', '/notes', ['heading' => 'Mehr eins', 'content' => '<p>Suchwort Kaffee Écoute</p>', 'workspace' => $ws2, 'folder_id' => $f1]), 'm1')['note']['id'];
		$m2 = self::ok($a->call('POST', '/notes', ['heading' => 'Mehr zwei', 'content' => '<p><a href="index.php?note=' . $m1 . '">eins</a></p>', 'workspace' => $ws2]), 'm2')['note']['id'];
		self::assertSame((int)$m1, (int)$this->rec('GET /notes/resolve', $a->call('GET', '/notes/resolve' . self::q(['reference' => 'Mehr eins', 'workspace' => $ws2])))['id']);
		$this->rec('GET /notes/search', $a->call('GET', '/notes/search' . self::q(['q' => 'Suchwort', 'workspace' => $ws2])));
		// Akzente und Groß/Klein egal
		$hits = self::ok($a->call('GET', '/notes/search' . self::q(['q' => 'ECOUTE', 'workspace' => $ws2])), 'search akzent')['results'];
		self::assertSame([(int)$m1], array_map(static fn ($r) => (int)$r['id'], $hits));
		self::assertSame([(int)$m1], $this->rec('GET /notes/search/ids', $a->call('GET', '/notes/search/ids' . self::q(['q' => 'suchwort', 'workspace' => $ws2])))['ids']);
		$back = $this->rec('GET /notes/{id}/backlinks', $a->call('GET', "/notes/$m1/backlinks$q"))['backlinks'];
		self::assertSame([(int)$m2], array_map(static fn ($r) => (int)$r['id'], $back));
		$this->rec('GET /graph', $a->call('GET', '/graph' . $q));

		// Anhang hoch/runter: Bytes gleich, Kopfzeilen
		$gif = "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff!\xf9\x04\x01\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;";
		$att = $this->rec('POST /notes/{noteId}/attachments', $a->upload("/notes/$m1/attachments$q", 'bild.gif', $gif, [], 'image/gif'))['attachment_id'];
		$this->rec('GET /notes/{noteId}/attachments', $a->call('GET', "/notes/$m1/attachments$q"));
		$dl = $a->call('GET', "/notes/$m1/attachments/$att$q");
		$this->rec('GET /notes/{noteId}/attachments/{attachmentId}', $dl);
		self::assertSame($gif, $dl->body, 'Anhang-Bytes');
		self::assertSame('image/gif', $dl->header('content-type'));
		self::assertStringStartsWith('inline;', (string)$dl->header('content-disposition'));
		self::assertSame('nosniff', $dl->header('x-content-type-options'));
		$svg = self::ok($a->upload("/notes/$m1/attachments$q", 'x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', [], 'image/svg+xml'), 'svg')['attachment_id'];
		$sv = $a->call('GET', "/notes/$m1/attachments/$svg$q");
		self::assertStringStartsWith('attachment;', (string)$sv->header('content-disposition'), 'SVG nie inline');
		self::assertStringContainsString('sandbox', (string)$sv->header('content-security-policy'));
		self::ok($a->call('DELETE', "/notes/$m1/attachments/$svg$q"), 'svg löschen');
		$this->rec('GET /notes/with-attachments', $a->call('GET', '/notes/with-attachments' . $q));
		$moved = $this->rec('POST /notes/{noteId}/attachments/{attachmentId}/move', $a->call('POST', "/notes/$m1/attachments/$att/move", ['target_note_id' => $m2]))['attachment_id'];
		$this->rec('DELETE /notes/{noteId}/attachments/{attachmentId}', $a->call('DELETE', "/notes/$m2/attachments/$moved$q"));
		$this->rec('GET /changes', $a->call('GET', '/changes' . self::q(['workspace' => $ws2, 'note_ids' => "$m1,$m2"])));

		// Sperre: Sitzung s1 hält, s2 → 409 beim Erwerb, 423 beim Speichern
		$s1 = ['X-Editor-Session-ID: fx-s1'];
		$this->rec('POST /notes/{id}/lock', $a->call('POST', "/notes/$m1/lock", ['editor_session_id' => 'fx-s1'], $s1));
		$this->rec('GET /notes/{id}/lock', $a->call('GET', "/notes/$m1/lock", null, $s1));
		self::assertSame(409, $a->call('POST', "/notes/$m1/lock", ['editor_session_id' => 'fx-s2'])->status, 'fremde Sitzung erwirbt');
		self::assertSame(423, $a->call('PATCH', "/notes/$m1", ['content' => '<p>x</p>'], ['X-Editor-Session-ID: fx-s2'])->status, 'fremde Sitzung speichert');
		$this->rec('POST /notes/{id}/lock/heartbeat', $a->call('POST', "/notes/$m1/lock/heartbeat", ['editor_session_id' => 'fx-s1'], $s1));
		$this->rec('POST /notes/{id}/lock/release', $a->call('POST', "/notes/$m1/lock/release", ['editor_session_id' => 'fx-s1'], $s1));

		// Snapshot anlegen, ändern, wiederherstellen
		$key = $this->rec('POST /notes/{id}/snapshot', $a->call('POST', "/notes/$m1/snapshot?manual=1"))['snapshot_key'];
		self::ok($a->call('PATCH', "/notes/$m1", ['content' => '<p>geändert</p>']), 'm1 ändern');
		$keys = array_column($this->rec('GET /notes/{id}/snapshots', $a->call('GET', "/notes/$m1/snapshots"))['snapshots'], 'snapshot_key');
		self::assertContains($key, $keys);
		$this->rec('GET /notes/{id}/snapshot', $a->call('GET', "/notes/$m1/snapshot" . self::q(['snapshot_key' => $key])));
		$this->rec('POST /notes/{id}/snapshot/restore', $a->call('POST', "/notes/$m1/snapshot/restore" . self::q(['snapshot_key' => $key])));
		self::assertStringContainsString('Suchwort', self::ok($a->call('GET', "/notes/$m1$q"), 'm1')['note']['content'], 'wiederhergestellt');
		$this->rec('DELETE /notes/{id}/snapshot', $a->call('DELETE', "/notes/$m1/snapshot" . self::q(['snapshot_key' => $key])));
		$this->rec('POST /notes/{id}/duplicate', $a->call('POST', "/notes/$m1/duplicate", ['workspace' => $ws2]));
		$this->rec('POST /folders/{id}/duplicate', $a->call('POST', "/folders/$f1/duplicate$q", []));
		return (int)$m1;
	}

	// Antwort aufzeichnen, ohne 2xx zu verlangen (erwartete Fehlerstatus)
	private function recAny(string $route, Response $r, int $status): array {
		$this->recorded[$route] ??= $r;
		self::assertSame($status, $r->status, "$route: " . $r->describe());
		$j = $r->json();
		return is_array($j) ? $j : [];
	}

	// M2c: gleiche Schritte wie dev/upstream/capture.php
	private function m2cSteps(ApiClient $a, ApiClient $b, string $ws2, int $m1): void {
		$q = self::q(['workspace' => $ws2]);
		$tl = self::ok($a->call('POST', '/notes', ['heading' => 'Aufgaben', 'type' => 'tasklist', 'content' => '[]', 'workspace' => $ws2]), 'tl')['note']['id'];
		self::ok($a->call('POST', '/notes', ['heading' => 'Check', 'type' => 'markdown', 'content' => "- [ ] eins\n- [x] zwei", 'workspace' => $ws2]), 'check');
		$t1 = $this->rec('POST /notes/{id}/tasks', $a->call('POST', "/notes/$tl/tasks", ['text' => 'Eins', 'due_at' => '2030-01-02', 'reminder' => true]))['task']['id'];
		$t1s = json_encode($t1);
		self::ok($a->call('POST', "/notes/$tl/tasks", ['text' => 'Zwei']), 'task zwei');
		$upd = $this->rec('PATCH /notes/{id}/tasks/{taskId}', $a->call('PATCH', "/notes/$tl/tasks/$t1s", ['text' => 'Eins b', 'important' => true]));
		self::assertSame('Eins b', $upd['task']['text']);
		$s1 = $this->rec('POST /notes/{id}/tasks/{taskId}/subtasks', $a->call('POST', "/notes/$tl/tasks/$t1s/subtasks", ['text' => 'Unter']))['subtask']['id'];
		$s1s = json_encode($s1);
		$this->rec('PATCH /notes/{id}/tasks/{taskId}/subtasks/{subtaskId}', $a->call('PATCH', "/notes/$tl/tasks/$t1s/subtasks/$s1s", ['completed' => true]));
		self::ok($a->call('POST', "/notes/$tl/tasks/$t1s/subtasks", ['text' => 'Unter zwei']), 'sub zwei');
		$tasks = $this->rec('GET /notes/{id}/tasks', $a->call('GET', "/notes/$tl/tasks"))['tasks'];
		self::assertSame(['Eins b', 'Zwei'], array_column($tasks, 'text'), 'wichtige Aufgabe zuerst');
		self::assertCount(2, $tasks[0]['subtasks']);
		$all = $this->rec('GET /tasks', $a->call('GET', '/tasks' . $q));
		self::assertSame(['eins', 'zwei'], array_column($all['checklists'][0]['tasks'], 'text'));
		$this->rec('DELETE /notes/{id}/tasks/{taskId}/subtasks/{subtaskId}', $a->call('DELETE', "/notes/$tl/tasks/$t1s/subtasks/$s1s"));
		$this->rec('DELETE /notes/{id}/tasks/{taskId}', $a->call('DELETE', "/notes/$tl/tasks/$t1s"));
		self::assertSame(['Zwei'], array_column(self::ok($a->call('GET', "/notes/$tl/tasks"), 'tasks')['tasks'], 'text'));

		$this->rec('POST /notes/{id}/reminder', $a->call('POST', "/notes/$m1/reminder", ['reminder_at' => '2020-01-01T08:00:00Z']));
		self::assertSame('2020-01-01 08:00:00', $this->rec('GET /notes/{id}/reminder', $a->call('GET', "/notes/$m1/reminder"))['reminder_at']);
		$this->rec('GET /reminders', $a->call('GET', '/reminders' . $q));
		$this->rec('GET /reminders/count', $a->call('GET', '/reminders/count' . $q));
		$this->rec('POST /reminders/{id}/read', $a->call('POST', '/reminders/1/read'));
		$this->rec('POST /reminders/{id}/dismiss', $a->call('POST', '/reminders/1/dismiss'));
		$this->rec('POST /reminders/dismiss-all', $a->call('POST', '/reminders/dismiss-all'));
		self::ok($a->call('POST', "/notes/$m1/reminder", ['reminder_at' => '2030-01-01T08:00:00Z']), 'reminder neu');
		$this->rec('DELETE /notes/{id}/reminder', $a->call('DELETE', "/notes/$m1/reminder"));
		self::assertNull(self::ok($a->call('GET', "/notes/$m1/reminder"), 'reminder weg')['reminder_at']);
		$t2 = TaskService::idString(self::ok($a->call('GET', "/notes/$tl/tasks"), 'tasks')['tasks'][0]['id']);
		$this->rec('POST /notes/{id}/task-reminder', $a->call('POST', "/notes/$tl/task-reminder", ['task_id' => $t2, 'reminder_at' => '2030-01-01T08:00:00Z']));
		// Port-eigen (M6e2): eigene Erinnerung lesen, Gegenstück zu POST
		self::assertSame('2030-01-01 08:00:00', $this->rec('GET /notes/{id}/task-reminder', $a->call('GET', "/notes/$tl/task-reminder" . self::q(['task_id' => $t2])))['reminder_at']);
		$this->rec('DELETE /notes/{id}/task-reminder', $a->call('DELETE', "/notes/$tl/task-reminder" . self::q(['task_id' => $t2])));

		$this->rec('GET /mentions', $a->call('GET', '/mentions'));
		$this->rec('GET /mentions/members', $a->call('GET', '/mentions/members' . $q));
		$this->recAny('POST /mentions/{ownerId}/{noteId}/handled', $a->call('POST', "/mentions/1/$m1/handled"), 404);
		$this->recAny('DELETE /mentions/{ownerId}/{noteId}/handled', $a->call('DELETE', "/mentions/1/$m1/handled"), 404);

		$this->rec('GET /settings', $a->call('GET', '/settings'));
		$this->rec('PUT /settings/{key}', $a->call('PUT', '/settings/note_list_sort', ['value' => 'heading_asc']));
		self::assertSame('heading_asc', $this->rec('GET /settings/{key}', $a->call('GET', '/settings/note_list_sort'))['value']);
		self::assertSame(400, $a->call('PUT', '/settings/note_list_sort', ['value' => '<script>'])->status, 'ungültiger Wert');
		$this->rec('GET /system/version', $a->call('GET', '/system/version'));
		self::assertFalse($this->rec('GET /system/whats-new', $a->call('GET', '/system/whats-new'))['show']);
		$this->rec('POST /system/whats-new/seen', $a->call('POST', '/system/whats-new/seen'));
		$this->rec('GET /system/i18n', $a->call('GET', '/system/i18n?lang=de'));
		$this->rec('GET /shared/with-me', $a->call('GET', '/shared/with-me'));
		$this->rec('GET /shared', $a->call('GET', '/shared' . $q));
		self::assertSame($a->uid, $this->rec('GET /users/me', $a->call('GET', '/users/me'))['username']);
		self::assertSame($b->uid, $this->rec('GET /users/lookup/{username}', $a->call('GET', '/users/lookup/' . self::enc($b->uid)))['username']);
		$this->recAny('POST /notes/{id}/convert', $a->call('POST', "/notes/$m1/convert", ['target' => 'markdown']), 200);
		$this->recAny('POST /convert-html', $a->call('POST', '/convert-html', ['html' => '<p><b>x</b></p>']), 200);
		$this->recAny('POST /convert-markdown', $a->call('POST', '/convert-markdown', ['markdown' => '**x**']), 200);
	}

	private function compareFixtures(): void {
		$allowed = json_decode((string)file_get_contents(self::fixtureDir() . '/allowed-diff.json'), true, flags: JSON_THROW_ON_ERROR);
		$allow = [];
		foreach ($allowed['entries'] as $e) {
			self::assertNotSame('', trim((string)($e['reason'] ?? '')), 'allowed-diff ohne Grund: ' . json_encode($e));
			$allow[$e['route'] . '|' . $e['kind'] . '|' . $e['path']] = false;
		}
		$errors = [];
		$fixtures = glob(self::fixtureDir() . '/upstream/*.json');
		self::assertNotEmpty($fixtures);
		foreach ($fixtures as $file) {
			$fx = json_decode((string)file_get_contents($file), false, flags: JSON_THROW_ON_ERROR);
			$route = $fx->route;
			if (!isset($this->recorded[$route])) {
				$errors[] = "$route: im Szenario nicht abgespielt";
				continue;
			}
			$r = $this->recorded[$route];
			$diff = [];
			self::keyDiff($fx->body, $r->object(), '', $diff);
			foreach ($diff as $d) {
				$k = $route . '|' . $d['kind'] . '|' . $d['path'];
				if (array_key_exists($k, $allow)) {
					$allow[$k] = true;
				} else {
					$errors[] = "$route: Schlüssel {$d['path']} " . ($d['kind'] === 'missing' ? 'fehlt' : 'zusätzlich');
				}
			}
			$sk = $route . '|status|' . $fx->status;
			if ($r->status !== $fx->status) {
				if (array_key_exists($sk, $allow)) {
					$allow[$sk] = true;
				} else {
					$errors[] = "$route: Status {$r->status} statt {$fx->status}";
				}
			}
		}
		foreach ($allow as $k => $used) {
			if (!$used) {
				$errors[] = "allowed-diff ungenutzt: $k";
			}
		}
		self::assertSame([], $errors);

		// Fixtures decken alle API-Routen ab
		$file = Server::get(IAppManager::class)->getAppPath('nextnotes') . '/appinfo/routes.php';
		$routes = (static fn () => require $file)();
		$fxRoutes = array_map(static fn ($f) => json_decode((string)file_get_contents($f))->route, $fixtures);
		$norm = static fn (string $r) => preg_replace(['/\{[^}]+\}/', '/\?.*$/'], ['{}', ''], $r);
		$fxRoutes = array_unique(array_map($norm, $fxRoutes));
		$missing = [];
		foreach ($routes['routes'] as $r) {
			if (str_starts_with($r['url'], '/api/v1/')) {
				$k = $norm(strtoupper($r['verb']) . ' ' . substr($r['url'], 7));
				if (!in_array($k, $fxRoutes, true)) {
					$missing[] = $k;
				}
			}
		}
		// Port-eigene Routen ohne Upstream-Gegenstück (RouteDiffTest::PORT_ONLY)
		self::assertSame([], array_values(array_diff($missing, ['GET /teams', 'POST /workspaces/{}/make-private', 'GET /users/search', 'PUT /folders/{}/material', 'GET /assignments', 'POST /assignments', 'GET /assignments/{}', 'PATCH /assignments/{}', 'DELETE /assignments/{}', 'GET /assignments/{}/submissions', 'GET /submissions/{}', 'POST /assignments/{}/distribute', 'POST /assignments/{}/close', 'POST /submissions/{}/submit', 'POST /submissions/{}/return', 'POST /submissions/{}/reopen', 'PUT /submissions/{}/feedback', 'GET /assignments/{}/export', 'GET /submissions/{}/export', 'GET /notes/{}/task-reminder'])), 'Routen ohne Upstream-Fixture');
	}

	// Detektor-Selbsttest: fehlende/zusätzliche Schlüssel werden erkannt, Zahlen-Schlüssel ignoriert
	public function testKeyDiffDetector(): void {
		$want = json_decode('{"a":1,"b":{"c":1,"7":1},"l":[{"x":1}]}');
		$got = json_decode('{"a":1,"b":{"d":1,"9":1},"l":[{"x":1,"y":2}]}');
		$out = [];
		self::keyDiff($want, $got, '', $out);
		self::assertSame([
			['path' => 'b.c', 'kind' => 'missing'],
			['path' => 'b.d', 'kind' => 'extra'],
			['path' => 'l[].y', 'kind' => 'extra'],
		], $out);
	}

	public function testFirstAccessCreatesExactlyOneDefaultWorkspace(): void {
		foreach ([1, 4] as $parallel) {
			$c = $this->createUser('first');
			Server::get(IUserConfig::class)->setValueString($c->uid, 'core', 'lang', 'de');
			$requests = array_fill(0, $parallel, ['GET', '/workspaces']);
			foreach ($c->parallel($requests) as $r) {
				self::assertSame(200, $r->status, $r->describe());
			}
			$ws = self::dump($c->uid)['workspaces'];
			self::assertCount(1, $ws, "$parallel Erstzugriffe → " . count($ws) . ' Workspaces');
			self::assertSame('Notizen', $ws[0]['name']);
			self::assertSame([], self::dump($c->uid)['notes'], 'keine Willkommensnotiz');
			self::assertSame(['Notizen'], self::workspaceNames($c));
		}
	}
}
