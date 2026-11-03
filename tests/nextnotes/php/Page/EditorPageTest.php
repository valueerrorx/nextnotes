<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Page;

use OCA\NextNotes\Service\WorkspaceService;
use OCA\NextNotes\Tests\Support\ApiTestCase;
use OCP\Server;

// M3b1 / SPEC §4.3, §4.4, §4.6: Fragment-Modus (X-Poznote-Fragment) mit gleicher Rechteprüfung wie die Seite,
// Lesefreigabe → Editor nur lesbar + PATCH 403, Notizinhalt in #right_col nie ungesäubert
final class EditorPageTest extends ApiTestCase {
	private const PAGE = '/index.php/apps/nextnotes/index.php';
	private const FRAG = ['X-Poznote-Fragment: right_col'];

	public function testFragmentOwnAndForeign(): void {
		$a = $this->createUser('fa');
		$b = $this->createUser('fb');
		$mark = 'FRAG' . bin2hex(random_bytes(4));
		[$ws] = self::newWorkspace($a, 'Frag');
		$nA = self::newNote($a, $ws, "Titel $mark", "<p>$mark</p>");

		$r = $a->withHeaders(self::FRAG)->absolute('GET', self::PAGE . "?note=$nA");
		self::assertSame(200, $r->status, 'A Fragment: ' . $r->describe());
		self::assertStringContainsString("id=\"entry$nA\"", $r->body, 'A Fragment: Editor fehlt');
		self::assertStringContainsString($mark, $r->body, 'A Fragment: Inhalt fehlt');
		self::assertStringNotContainsString('nextnotes-root', $r->body, 'A Fragment: Seitenrahmen im Fragment');

		$page = $b->absolute('GET', self::PAGE . "?note=$nA");
		foreach (["?note=$nA", '?workspace=' . self::enc($ws) . "&note=$nA"] as $q) {
			$r = $b->withHeaders(self::FRAG)->absolute('GET', self::PAGE . $q);
			self::assertSame($page->status, $r->status, "B Fragment $q: Status anders als Seite");
			self::assertSame(404, $r->status, "B Fragment $q: " . $r->describe());
			self::assertStringNotContainsString($mark, $r->body, "B Fragment $q: A-Marker im Body");
		}
		foreach (['abc', '0', '99999999'] as $bad) {
			self::assertSame(404, $a->withHeaders(self::FRAG)->absolute('GET', self::PAGE . "?note=$bad")->status, "A Fragment ?note=$bad");
		}
	}

	// M3-fix2 / SPEC §4.3 Nr. 10: Fragment left_col (In-place-Neuaufbau des Baums) mit derselben Rechteprüfung wie die Seite
	public function testLeftColFragmentOwnAndForeign(): void {
		$left = ['X-Poznote-Fragment: left_col'];
		$a = $this->createUser('la');
		$b = $this->createUser('lb');
		$markA = 'LCA' . bin2hex(random_bytes(4));
		$markB = 'LCB' . bin2hex(random_bytes(4));
		[$wsA] = self::newWorkspace($a, 'LcA');
		[$wsB] = self::newWorkspace($b, 'LcB');
		$fA = (int)self::ok($a->call('POST', '/folders', ['folder_name' => "Ordner $markA", 'workspace' => $wsA]), 'A Ordner')['folder_id'];
		$fB = (int)self::ok($b->call('POST', '/folders', ['folder_name' => "Ordner $markB", 'workspace' => $wsB]), 'B Ordner')['folder_id'];
		$nA = self::newNote($a, $wsA, "Titel $markA", "<p>$markA</p>", $fA);
		$nB = self::newNote($b, $wsB, "Titel $markB", "<p>$markB</p>", $fB);

		$r = $a->withHeaders($left)->absolute('GET', self::PAGE . '?workspace=' . self::enc($wsA) . "&note=$nA");
		self::assertSame(200, $r->status, 'A left_col: ' . $r->describe());
		self::assertStringContainsString('id="left_col"', $r->body, 'A left_col: linke Spalte fehlt');
		self::assertStringNotContainsString('id="right_col"', $r->body, 'A left_col: rechte Spalte im Fragment');
		self::assertStringNotContainsString('nextnotes-root', $r->body, 'A left_col: Seitenrahmen im Fragment');
		self::assertStringContainsString("Titel $markA", $r->body, 'A left_col: eigener Notiztitel fehlt im Baum');
		self::assertStringContainsString("Ordner $markA", $r->body, 'A left_col: eigener Ordner fehlt im Baum');

		$assertNoB = static function (string $body, string $what) use ($markB): void {
			self::assertStringNotContainsString($markB, $body, "$what: Titel/Ordnername von B im Body");
		};
		// fremde Notiz (mit und ohne fremden Workspace), unbekannte und ungültige IDs → 404 wie die Seite
		foreach (["?note=$nB", '?workspace=' . self::enc($wsB) . "&note=$nB", '?note=abc', '?note=999999'] as $q) {
			$page = $a->absolute('GET', self::PAGE . $q);
			$r = $a->withHeaders($left)->absolute('GET', self::PAGE . $q);
			self::assertSame(404, $r->status, "A left_col $q: " . $r->describe());
			self::assertSame($page->status, $r->status, "A left_col $q: Status anders als Seite");
			$assertNoB($r->body, "A left_col $q");
			self::assertStringNotContainsString('id="left_col"', $r->body, "A left_col $q: Baum trotz 404");
		}
		// fremder Workspace ohne Notiz: kein Inhalt von B (404 oder eigener Baum)
		$r = $a->withHeaders($left)->absolute('GET', self::PAGE . '?workspace=' . self::enc($wsB));
		self::assertContains($r->status, [200, 403, 404], 'A left_col fremder Workspace: ' . $r->describe());
		$assertNoB($r->body, 'A left_col fremder Workspace');
		self::assertStringNotContainsString('id="right_col"', $r->body, 'A left_col fremder Workspace: rechte Spalte im Fragment');
	}

	public function testReadShareEditorReadOnly(): void {
		$a = $this->createUser('ra');
		$b = $this->createUser('rb');
		$mark = 'RO' . bin2hex(random_bytes(4));
		[$ws, $wsId] = self::newWorkspace($a, 'Lese');
		$nA = self::newNote($a, $ws, "Lesen $mark", "<p>$mark</p>");
		Server::get(WorkspaceService::class)->share($a->uid, $wsId, $b->uid, 'read');

		$r = $b->call('PATCH', "/notes/$nA", ['content' => '<p>fremd</p>', 'workspace' => $ws]);
		self::assertSame(403, $r->status, 'B PATCH mit Lesefreigabe: ' . $r->describe());
		self::assertStringContainsString($mark, (string)(self::ok($a->call('GET', "/notes/$nA"), 'A liest')['note']['content'] ?? ''), 'A-Inhalt verändert');
		foreach (['Seite' => $b, 'Fragment' => $b->withHeaders(self::FRAG)] as $what => $c) {
			$r = $c->absolute('GET', self::PAGE . "?note=$nA");
			self::assertSame(200, $r->status, "B $what mit Lesefreigabe: " . $r->describe());
			self::assertStringContainsString($mark, $r->body, "B $what: Inhalt fehlt");
			self::assertMatchesRegularExpression('/<div class="noteentry"[^>]*id="entry' . $nA . '"[^>]*contenteditable="false"/', $r->body, "B $what: Editor nicht nur lesbar");
		}
	}

	// Tasklist-Inhalt, den HtmlSanitizer::isStructuredJson (trim) als JSON durchlässt, den die Ansicht aber nicht als JSON erkennt
	public function testTasklistContentNeverRenderedUnsanitized(): void {
		$a = $this->createUser('ta');
		[$ws] = self::newWorkspace($a, 'Task');
		foreach (["\x0B" => 'VT', "\x0C" => 'FF', "\0" => 'NUL', "\xC2\xA0" => 'NBSP', ' ' => 'SP'] as $prefix => $label) {
			$payload = $prefix . '[{"id":1,"text":"<img src=x onerror=alert(1) id=inj' . $label . '>"}]';
			$r = $a->call('POST', '/notes', ['heading' => "TL $label", 'type' => 'tasklist', 'workspace' => $ws, 'content' => $payload]);
			if ($r->status >= 300) {
				continue; // abgelehnt ist in Ordnung
			}
			$id = (int)$r->json()['note']['id'];
			foreach (['Seite' => $a, 'Fragment' => $a->withHeaders(self::FRAG)] as $what => $c) {
				$page = $c->absolute('GET', self::PAGE . "?note=$id");
				self::assertSame(200, $page->status, "$label $what: " . $page->describe());
				self::assertStringNotContainsString("<img src=x onerror=alert(1) id=inj$label>", $page->body, "$label $what: Tasklist-Inhalt ungesäubert als HTML ausgegeben");
			}
		}
	}
	// gültige Tasklist mit führendem JSON-Leerraum bleibt Tasklist: Inhalt im data-tasklist-json, Editor-Körper leer (rendert der Client)
	public function testTasklistWithLeadingWhitespaceStaysTasklist(): void {
		$a = $this->createUser('tw');
		[$ws] = self::newWorkspace($a, 'TaskWs');
		$mark = 'Aufgabe' . bin2hex(random_bytes(4));
		$payload = "\n " . '[{"id":1,"text":"' . $mark . '","completed":false}]';
		$r = $a->call('POST', '/notes', ['heading' => 'TL ws', 'type' => 'tasklist', 'workspace' => $ws, 'content' => $payload]);
		self::assertLessThan(300, $r->status, 'POST: ' . $r->describe());
		$id = (int)$r->json()['note']['id'];
		foreach (['Seite' => $a, 'Fragment' => $a->withHeaders(self::FRAG)] as $what => $c) {
			$page = $c->absolute('GET', self::PAGE . "?note=$id");
			self::assertSame(200, $page->status, "$what: " . $page->describe());
			self::assertMatchesRegularExpression('/<div class="noteentry"[^>]*id="entry' . $id . '"[^>]*data-note-type="tasklist"[^>]*data-tasklist-json="[^"]*' . $mark . '[^"]*"[^>]*>\s*<\/div>/', $page->body, "$what: gültige Tasklist nicht als Tasklist (JSON im Attribut, leerer Körper)");
		}
	}
}
