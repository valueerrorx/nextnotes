<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Page;

use OCA\NextNotes\Tests\Support\ApiClient;
use OCA\NextNotes\Tests\Support\ApiTestCase;
use OCA\NextNotes\Tests\Support\Response;

// SPEC §4.3.13 (M3c2), §11.2 B35 (data-excalidraw übersteht Notiz-Speichern), B36 (mehrfaches Speichern ohne Wachstum)
final class ExcalidrawSaveTest extends ApiTestCase {
	private const APP = '/index.php/apps/nextnotes/';
	private const SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect x="0" y="0" width="10" height="10" fill="#ffffff"/></svg>';

	private static function save(ApiClient $c, int $note, string $text, array $extra = []): Response {
		$data = json_encode(['type' => 'excalidraw', 'elements' => [['id' => 'e1', 'type' => 'text', 'text' => $text]], 'appState' => new \stdClass(), 'files' => new \stdClass()], JSON_THROW_ON_ERROR);
		return $c->form(self::APP . 'api_save_excalidraw.php', ['action' => 'save_embedded_diagram', 'note_id' => $note, 'diagram_id' => 'excalidraw-1',
			'diagram_data' => $data, 'preview_svg' => self::SVG] + $extra, true);
	}

	private static function content(ApiClient $c, int $note): string {
		return (string)(self::ok($c->call('GET', "/notes/$note"), 'GET Notiz')['note']['content'] ?? '');
	}

	/** Anhang-IDs der Vorschau wechseln je Speichern → für Vergleich neutralisieren */
	private static function norm(string $c): string {
		return (string)preg_replace('#/attachments/\d+#', '/attachments/N', $c);
	}

	/** Text des ersten Elements aus data-excalidraw (Attribut in " oder ') */
	private static function diagramText(string $c): ?string {
		if (!preg_match('/\sdata-excalidraw=(?:"([^"]*)"|\'([^\']*)\')/', $c, $m)) {
			return null;
		}
		$json = json_decode(html_entity_decode(($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? ''), ENT_QUOTES | ENT_HTML5), true);
		return is_array($json) ? ($json['elements'][0]['text'] ?? null) : null;
	}

	public function testB35DataTextSurvivesNoteSave(): void {
		$a = $this->createUser('a');
		[$ws] = self::newWorkspace($a, 'Exc');
		$n = self::newNote($a, $ws, 'Zeichnung', '<p>Vorher</p>');
		$text = 'Metadata: data:text/html,x javascript:void(0) Ende';
		self::ok(self::save($a, $n, $text, ['workspace' => $ws, 'cursor_position' => 6]), 'Diagramm speichern');
		$c1 = self::content($a, $n);
		self::assertMatchesRegularExpression('/\sdata-excalidraw=["\']\{/', $c1, 'nach Diagramm-Speichern: data-excalidraw fehlt');
		self::assertSame($text, self::diagramText($c1), 'Diagramm-Text nach Diagramm-Speichern');
		// Editor speichert die Notiz unverändert zurück (Notiz-Speichern läuft durch den HtmlSanitizer)
		self::ok($a->call('PATCH', "/notes/$n", ['content' => $c1, 'workspace' => $ws]), 'Notiz speichern');
		$c2 = self::content($a, $n);
		self::assertMatchesRegularExpression('/\sdata-excalidraw=["\']\{/', $c2, 'B35: data-excalidraw nach Notiz-Speichern entfernt');
		self::assertSame($text, self::diagramText($c2), 'B35: Diagramm-Text nach Notiz-Speichern verändert');
		$r = $a->absolute('GET', self::APP . "excalidraw_editor.php?note_id=$n&diagram_id=excalidraw-1");
		self::assertSame(200, $r->status);
		self::assertMatchesRegularExpression('#<script type="application/json" id="excalidraw-config">(.*?)</script>#s', $r->body);
		preg_match('#<script type="application/json" id="excalidraw-config">(.*?)</script>#s', $r->body, $m);
		$existing = json_decode((string)(json_decode($m[1], true)['existingData'] ?? ''), true);
		self::assertSame($text, $existing['elements'][0]['text'] ?? null, 'Editor lädt Diagramm nach Notiz-Speichern');
		// Gegenprobe: gefährliche Attribute außerhalb data-excalidraw filtert der Sanitizer weiter
		self::ok($a->call('PATCH', "/notes/$n", ['content' => $c2 . '<a href="javascript:alert(1)" data-x="data:text/html,y">l</a>', 'workspace' => $ws]), 'Notiz mit Link');
		$c3 = self::content($a, $n);
		self::assertStringNotContainsString('javascript:alert', $c3, 'Sanitizer: javascript:-href durchgelassen');
		self::assertMatchesRegularExpression('/\sdata-excalidraw=["\']\{/', $c3);
	}

	public function testB36RepeatedSaveDoesNotGrowHtml(): void {
		$a = $this->createUser('a');
		[$ws] = self::newWorkspace($a, 'Exc');
		$n = self::newNote($a, $ws, 'Zeichnung', '<p>Text davor</p><p>Text danach</p>');
		self::ok(self::save($a, $n, 'v1', ['workspace' => $ws, 'cursor_position' => 10]), 'Speichern 1');
		$c1 = self::content($a, $n);
		self::assertSame(1, substr_count($c1, 'max-width: 100%'), "Grundstil einmal: $c1");
		// Benutzer ändert die Bildbreite im Editor (Stil am <img>)
		$c1b = (string)preg_replace('/(<img\b[^>]*\bstyle=")/', '$1width: 40%; ', $c1, 1);
		self::assertNotSame($c1, $c1b, 'Vorschaubild ohne style-Attribut');
		self::ok($a->call('PATCH', "/notes/$n", ['content' => $c1b, 'workspace' => $ws]), 'Breite setzen');
		self::ok(self::save($a, $n, 'v1'), 'Speichern 2');
		$c2 = self::content($a, $n);
		self::ok(self::save($a, $n, 'v1'), 'Speichern 3');
		$c3 = self::content($a, $n);
		self::assertSame(self::norm($c2), self::norm($c3), 'B36: Inhalt wächst/ändert sich bei gleichem Diagramm');
		self::assertSame(1, substr_count($c3, 'max-width: 100%'), "B36: Grundstil verdoppelt: $c3");
		self::assertSame(1, substr_count($c3, 'width: 40%'), "Benutzerbreite verloren/verdoppelt: $c3");
		self::assertSame(1, substr_count($c3, 'excalidraw-container'), 'genau ein Container');
		self::assertStringContainsString('Text davor', $c3);
		self::assertStringContainsString('Text danach', $c3);
		$atts = self::ok($a->call('GET', "/notes/$n/attachments"), 'Anhänge')['attachments'] ?? [];
		self::assertCount(1, $atts, 'ersetzte Vorschauen entfernt');
	}

	public function testB36MarkdownReplacedInPlace(): void {
		$a = $this->createUser('a');
		[$ws] = self::newWorkspace($a, 'Exc');
		$n = (int)self::ok($a->call('POST', '/notes', ['heading' => 'MD', 'type' => 'markdown', 'content' => "Zeile 1\nZeile 2", 'workspace' => $ws]), 'MD-Notiz')['note']['id'];
		self::ok(self::save($a, $n, 'md', ['workspace' => $ws, 'cursor_position' => 7]), 'Speichern 1');
		$c1 = self::content($a, $n);
		self::ok(self::save($a, $n, 'md'), 'Speichern 2');
		$c2 = self::content($a, $n);
		self::ok(self::save($a, $n, 'md'), 'Speichern 3');
		$c3 = self::content($a, $n);
		self::assertStringStartsWith('Zeile 1', $c3);
		self::assertStringEndsWith('Zeile 2', rtrim($c3));
		self::assertSame(self::norm($c1), self::norm($c2), 'B36 Markdown: Leerzeilen wachsen (1→2)');
		self::assertSame(self::norm($c2), self::norm($c3), 'B36 Markdown: Leerzeilen wachsen (2→3)');
		self::assertSame(1, substr_count($c3, 'excalidraw-container'));
	}

	// ---- Große Inhalte (Lead M3c2, Speccritic-Befund: Markdown ≥ ~1 MB wurde leer gespeichert, preg-Backtrack-Limit im Sanitizer) ----

	private static function bigText(int $bytes, string $mark): string {
		$line = str_repeat('Lorem ipsum dolor sit amet, ', 3) . "\n";
		return str_repeat($line, intdiv($bytes, strlen($line)) + 1) . "ENDE $mark";
	}

	private static function bigDiagram(int $bytes, string $mark): string {
		$els = [];
		for ($i = 0, $size = 0; $size < $bytes; $i++) {
			$t = "Element $i " . str_repeat('x', 200);
			$els[] = ['id' => "e$i", 'type' => 'text', 'text' => $t];
			$size += strlen($t) + 40;
		}
		$els[] = ['id' => 'end', 'type' => 'text', 'text' => "ENDE $mark"];
		return json_encode(['type' => 'excalidraw', 'elements' => $els, 'appState' => new \stdClass(), 'files' => new \stdClass()], JSON_THROW_ON_ERROR);
	}

	private static function lastText(string $c): ?string {
		if (!preg_match('/\sdata-excalidraw=(?:"([^"]*)"|\'([^\']*)\')/', $c, $m)) {
			return null;
		}
		$json = json_decode(html_entity_decode(($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? ''), ENT_QUOTES | ENT_HTML5), true);
		$els = is_array($json) ? ($json['elements'] ?? []) : [];
		return $els === [] ? null : ($els[count($els) - 1]['text'] ?? null);
	}

	public function testLargeMarkdownNoteKeptViaPatch(): void {
		$a = $this->createUser('a');
		[$ws] = self::newWorkspace($a, 'Big');
		$mark = bin2hex(random_bytes(4));
		$n = (int)self::ok($a->call('POST', '/notes', ['heading' => 'MD groß', 'type' => 'markdown', 'content' => 'x', 'workspace' => $ws]), 'MD-Notiz')['note']['id'];
		$text = self::bigText(2 * 1024 * 1024, $mark);
		self::ok($a->call('PATCH', "/notes/$n", ['content' => $text, 'workspace' => $ws]), 'PATCH 2 MB Markdown');
		$c = self::content($a, $n);
		self::assertSame(strlen($text), strlen($c), 'Markdown 2 MB: Länge geändert (leer gespeichert?)');
		self::assertSame($text, $c, 'Markdown 2 MB: Inhalt verändert');
	}

	public function testLargeMarkdownWithLongExcalidrawTagKeptViaPatch(): void {
		$a = $this->createUser('a');
		[$ws] = self::newWorkspace($a, 'Big');
		$mark = bin2hex(random_bytes(4));
		$n = (int)self::ok($a->call('POST', '/notes', ['heading' => 'MD Tag', 'type' => 'markdown', 'content' => 'x', 'workspace' => $ws]), 'MD-Notiz')['note']['id'];
		$json = self::bigDiagram(2 * 1024 * 1024, $mark);
		$md = "Zeile 1\n\n<div class=\"excalidraw-container\" id=\"excalidraw-1\" data-diagram-id=\"excalidraw-1\" data-excalidraw=\""
			. htmlspecialchars($json, ENT_QUOTES | ENT_HTML5) . "\"></div>\n\nZeile 2 $mark";
		self::ok($a->call('PATCH', "/notes/$n", ['content' => $md, 'workspace' => $ws]), 'PATCH Markdown mit 2-MB-Tag');
		$c = self::content($a, $n);
		self::assertGreaterThan(2000000, strlen($c), 'Markdown mit langem Tag: Inhalt gekürzt/leer (' . strlen($c) . ' Bytes)');
		self::assertStringStartsWith('Zeile 1', $c);
		self::assertStringEndsWith("Zeile 2 $mark", rtrim($c));
		self::assertSame("ENDE $mark", self::lastText($c), 'Markdown mit langem Tag: Diagramm-JSON verändert');
	}

	public function testLargeDiagramViaSaveEmbedded(): void {
		$a = $this->createUser('a');
		[$ws] = self::newWorkspace($a, 'Big');
		$mark = bin2hex(random_bytes(4));
		$md = (int)self::ok($a->call('POST', '/notes', ['heading' => 'MD', 'type' => 'markdown', 'content' => "Zeile 1\nZeile 2 $mark", 'workspace' => $ws]), 'MD-Notiz')['note']['id'];
		$html = self::newNote($a, $ws, 'HTML', "<p>Vorher $mark</p>");
		$json = self::bigDiagram(2 * 1024 * 1024, $mark);
		foreach (['html' => $html, 'markdown' => $md] as $label => $n) {
			$r = $a->form(self::APP . 'api_save_excalidraw.php', ['action' => 'save_embedded_diagram', 'note_id' => $n, 'diagram_id' => 'excalidraw-1',
				'diagram_data' => $json, 'preview_svg' => self::SVG, 'workspace' => $ws, 'cursor_position' => 7], true);
			self::assertSame(200, $r->status, "$label 2-MB-Diagramm: " . substr($r->describe(), 0, 300));
			$c = self::content($a, $n);
			self::assertGreaterThan(2000000, strlen($c), "$label 2-MB-Diagramm: Inhalt gekürzt/leer (" . strlen($c) . ' Bytes)');
			self::assertSame("ENDE $mark", self::lastText($c), "$label 2-MB-Diagramm: JSON verändert");
			self::assertStringContainsString($mark, (string)preg_replace('/\sdata-excalidraw=("[^"]*"|\'[^\']*\')/', '', $c), "$label: Notiztext verloren");
			// zweites Speichern (Ersetzen) und Notiz-Speichern danach
			self::ok($a->form(self::APP . 'api_save_excalidraw.php', ['action' => 'save_embedded_diagram', 'note_id' => $n, 'diagram_id' => 'excalidraw-1',
				'diagram_data' => $json, 'preview_svg' => self::SVG], true), "$label Ersetzen");
			$c2 = self::content($a, $n);
			self::assertSame("ENDE $mark", self::lastText($c2), "$label Ersetzen: JSON verändert");
			self::ok($a->call('PATCH', "/notes/$n", ['content' => $c2, 'workspace' => $ws]), "$label Notiz-Speichern");
			self::assertSame("ENDE $mark", self::lastText(self::content($a, $n)), "$label nach Notiz-Speichern: Diagramm verloren");
		}
		// > 7 MiB → 413, Inhalt unverändert
		$before = self::content($a, $md);
		$huge = self::bigDiagram(8 * 1024 * 1024, $mark);
		self::assertGreaterThan(7 * 1024 * 1024, strlen($huge), 'Testdaten: Diagramm nicht größer als 7 MiB');
		$r = $a->form(self::APP . 'api_save_excalidraw.php', ['action' => 'save_embedded_diagram', 'note_id' => $md, 'diagram_id' => 'excalidraw-9',
			'diagram_data' => $huge, 'preview_svg' => self::SVG], true);
		self::assertSame(413, $r->status, '> 7 MiB: ' . substr($r->describe(), 0, 300));
		// M4a: Meldung übersetzt (Upstream-Schlüssel attachments.errors.file_too_large) mit Grenze
		self::assertSame('The file is too large (max: 7 MB).', $r->json()['message'] ?? null, '> 7 MiB: Meldung');
		self::assertSame($before, self::content($a, $md), '> 7 MiB: Inhalt verändert');
	}

	public function testLargeHtmlNoteKeptViaPatch(): void {
		$a = $this->createUser('a');
		[$ws] = self::newWorkspace($a, 'Big');
		$mark = bin2hex(random_bytes(4));
		$n = self::newNote($a, $ws, 'HTML groß', '<p>x</p>');
		$p = '<p>' . str_repeat('Lorem ipsum dolor sit amet, ', 3) . '</p>';
		$html = str_repeat($p, intdiv(2 * 1024 * 1024, strlen($p)) + 1) . "<p>ENDE $mark</p>";
		self::ok($a->call('PATCH', "/notes/$n", ['content' => $html, 'workspace' => $ws]), 'PATCH 2 MB HTML');
		$c = self::content($a, $n);
		self::assertSame(strlen($html), strlen($c), 'HTML 2 MB: Länge geändert (' . strlen($c) . ' Bytes)');
		self::assertStringEndsWith("<p>ENDE $mark</p>", $c);
	}

}
