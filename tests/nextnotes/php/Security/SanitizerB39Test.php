<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Security;

use OCA\NextNotes\Tests\Support\ApiClient;
use OCA\NextNotes\Tests\Support\ApiTestCase;
use OCA\NextNotes\Tests\Support\Response;

// M3c2 r3–r6 (SPEC B39/B40): Tag-/Attribut-Budget → 413 statt 500, ungültiges UTF-8 in allen Eingaben → 400,
// Markdown-Fließtext mit „a<b“ und Excalidraw-Text „a onclick=x“ bleiben byte-gleich.
final class SanitizerB39Test extends ApiTestCase {
	private const APP = '/index.php/apps/nextnotes/';
	private const SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect x="0" y="0" width="10" height="10" fill="#ffffff"/></svg>';

	private static function content(ApiClient $c, int $note): string {
		return (string)(self::ok($c->call('GET', "/notes/$note"), 'GET Notiz')['note']['content'] ?? '');
	}

	private static function brief(Response $r): string {
		return $r->status . ' ' . substr($r->body, 0, 200);
	}

	private static function assertApp413(Response $r, string $what): void {
		self::assertSame(413, $r->status, "$what: " . self::brief($r));
		self::assertIsArray(json_decode($r->body, true), "$what: 413 ohne JSON-Body: " . self::brief($r));
	}

	private static function mdNote(ApiClient $a, string $ws, string $content): int {
		return (int)self::ok($a->call('POST', '/notes', ['heading' => 'MD', 'type' => 'markdown', 'content' => $content, 'workspace' => $ws]), 'MD-Notiz')['note']['id'];
	}

	// (a) 16 MiB − 1 KiB ungeschlossene <b> (> 500.000 Tags) → App-413, nie 500, Inhalt unverändert
	public function testManyBTagsJustBelowLimitIs413(): void {
		$a = $this->createUser('a');
		[$ws] = self::newWorkspace($a, 'B');
		$n = self::newNote($a, $ws, 'B', '<p>bleibt</p>');
		$html = str_repeat('<b>', intdiv(16 * 1024 * 1024 - 1024, 3));
		$t = microtime(true);
		$r = $a->call('PATCH', "/notes/$n", ['content' => $html, 'workspace' => $ws]);
		fwrite(STDERR, sprintf("\n[B39] 16 MiB-1 KiB <b>: HTTP %d in %.2f s\n", $r->status, microtime(true) - $t));
		self::assertApp413($r, '16 MiB-1 KiB <b>');
		self::assertSame('<p>bleibt</p>', self::content($a, $n), '<b>: Inhalt verändert');
	}

	// (b) ungültiges UTF-8 in Ordner/Tag/Task/Reminder/Setting → 400 ohne Änderung (B40)
	public function testInvalidUtf8InOtherInputsIs400(): void {
		$a = $this->createUser('a');
		[$ws] = self::newWorkspace($a, 'U8');
		$n = self::newNote($a, $ws, 'Gut', '<p>gut</p>');
		self::ok($a->call('PUT', "/notes/$n/tags", ['tags' => 'gut', 'workspace' => $ws]), 'Tag setzen');
		$f = (int)self::ok($a->call('POST', '/folders', ['folder_name' => 'Ordner', 'workspace' => $ws]), 'Ordner')['folder_id'];
		$tl = (int)self::ok($a->call('POST', '/notes', ['heading' => 'Aufgaben', 'type' => 'tasklist', 'content' => '[]', 'workspace' => $ws]), 'Aufgabenliste')['note']['id'];
		self::ok($a->call('PUT', '/settings/my_free', ['value' => 'alt']), 'Setting');
		$bad = "kaputt\xC3\x28\xFF";
		$before = self::dump($a->uid);
		foreach ([
			'Ordner POST' => ['POST', '/folders', ['folder_name' => $bad, 'workspace' => $ws]],
			'Ordner PATCH' => ['PATCH', "/folders/$f", ['name' => $bad, 'workspace' => $ws]],
			'Tag umbenennen' => ['PATCH', '/tags/gut', ['new_name' => $bad, 'workspace' => $ws]],
			'Tag im Pfad' => ['PATCH', '/tags/' . rawurlencode($bad), ['new_name' => 'neu', 'workspace' => $ws]],
			'Task POST' => ['POST', "/notes/$tl/tasks", ['text' => $bad]],
			'Reminder POST' => ['POST', "/notes/$n/reminder", ['reminder_at' => '2030-01-01T08:00:00Z', 'note' => $bad, 'x' . $bad => '1']],
			'Setting Wert' => ['PUT', '/settings/my_free', ['value' => $bad]],
			'Setting Schlüssel' => ['PUT', '/settings/' . rawurlencode($bad), ['value' => 'x']],
		] as $label => [$m, $path, $fields]) {
			$r = $m === 'POST' ? $a->form(ApiClient::PREFIX . $path, $fields) : $a->formMethod($m, ApiClient::PREFIX . $path, $fields);
			$changed = self::dump($a->uid) !== $before;
			self::assertSame([400, false], [$r->status, $changed], "$label ungültiges UTF-8 (Status, Daten verändert): " . self::brief($r));
		}
		self::assertSame('alt', self::ok($a->call('GET', '/settings/my_free'), 'GET Setting')['value'] ?? null);
	}

	// (c) Markdown-Fließtext „wenn a<b gilt, dann“ + 5.000 Wörter → 200, byte-gleich
	public function testMarkdownProseWithLessThanKeptByteEqual(): void {
		$a = $this->createUser('a');
		[$ws] = self::newWorkspace($a, 'MD');
		$n = self::mdNote($a, $ws, 'x');
		$words = [];
		for ($i = 0; $i < 5000; $i++) {
			$words[] = 'wort' . $i;
		}
		$md = "Satz: wenn a<b gilt, dann " . implode(' ', $words) . "\n\nEnde.";
		$r = $a->call('PATCH', "/notes/$n", ['content' => $md, 'workspace' => $ws]);
		self::assertSame(200, $r->status, 'a<b + 5.000 Wörter: ' . self::brief($r));
		self::assertSame($md, self::content($a, $n), 'a<b + 5.000 Wörter: Inhalt nicht byte-gleich');
	}

	// (d) Markdown-Excalidraw mit Diagrammtext „a onclick=x“ → byte-gleich (Pass C schont quotierte Werte)
	public function testMarkdownExcalidrawTextWithOnclickKept(): void {
		$a = $this->createUser('a');
		[$ws] = self::newWorkspace($a, 'Exc');
		$n = self::mdNote($a, $ws, "Zeile 1\nZeile 2");
		$text = 'a onclick=x';
		$data = json_encode(['type' => 'excalidraw', 'elements' => [['id' => 'e1', 'type' => 'text', 'text' => $text]], 'appState' => new \stdClass(), 'files' => new \stdClass()], JSON_THROW_ON_ERROR);
		$r = $a->form(self::APP . 'api_save_excalidraw.php', ['action' => 'save_embedded_diagram', 'note_id' => $n, 'diagram_id' => 'excalidraw-1',
			'diagram_data' => $data, 'preview_svg' => self::SVG, 'workspace' => $ws, 'cursor_position' => 7], true);
		self::assertSame(200, $r->status, 'Diagramm speichern: ' . self::brief($r));
		$c1 = self::content($a, $n);
		self::assertSame(1, preg_match('/\sdata-excalidraw=(?:"([^"]*)"|\'([^\']*)\')/', $c1, $m), "data-excalidraw fehlt: $c1");
		$json = json_decode(html_entity_decode(($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? ''), ENT_QUOTES | ENT_HTML5));
		self::assertSame($data, json_encode($json, JSON_THROW_ON_ERROR), 'Diagramm-JSON nicht byte-gleich');
		self::assertSame($text, $json->elements[0]->text ?? null);
		// Notiz-Speichern (Markdown-Sanitizer) ändert nichts
		self::ok($a->call('PATCH', "/notes/$n", ['content' => $c1, 'workspace' => $ws]), 'Notiz speichern');
		self::assertSame($c1, self::content($a, $n), 'Markdown-Excalidraw nach Notiz-Speichern nicht byte-gleich');
	}

	// (e) HTML-Tag mit 300 Attributen (> 256 je Tag) → 413, Inhalt unverändert
	public function testHtmlTagWith300AttributesIs413(): void {
		$a = $this->createUser('a');
		[$ws] = self::newWorkspace($a, 'Attr');
		$n = self::newNote($a, $ws, 'Attr', '<p>bleibt</p>');
		$attrs = '';
		for ($i = 0; $i < 300; $i++) {
			$attrs .= " data-a$i=\"v\"";
		}
		$r = $a->call('PATCH', "/notes/$n", ['content' => "<p$attrs>x</p>", 'workspace' => $ws]);
		self::assertApp413($r, '300 Attribute');
		self::assertSame('<p>bleibt</p>', self::content($a, $n), '300 Attribute: Inhalt verändert');
		// Gegenprobe: 200 Attribute → 200
		$attrs = '';
		for ($i = 0; $i < 200; $i++) {
			$attrs .= " data-a$i=\"v\"";
		}
		self::assertSame(200, $a->call('PATCH', "/notes/$n", ['content' => "<p$attrs>x</p>", 'workspace' => $ws])->status, '200 Attribute');
	}
}
