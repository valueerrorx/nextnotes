<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Security;

use OCA\NextNotes\Service\BlockMarkup;
use OCA\NextNotes\Service\EmbedDomainService;
use OCA\NextNotes\Service\HtmlSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

// M8b (SPEC §4.7, M8-Plan §3 Done 7): Tabellenblock-Vertrag im Sanitizer, Schema v1, Verschachtelung, isDangerous-Ausnahme
final class SheetMarkupTest extends TestCase {
	private const TABLE = '<table class="nn-sheet-table"><tbody><tr><td>Menge</td><td class="nn-num">2</td></tr></tbody></table>';

	private static function s(): HtmlSanitizer {
		return new HtmlSanitizer(new class extends EmbedDomainService {
			public function __construct() {
			}

			public function domains(): array {
				return [];
			}
		});
	}

	/** @param array<string, mixed> $over */
	private static function sheet(array $over = []): array {
		return array_replace(['v' => 1, 'lang' => 'deDE', 'rows' => 1, 'cols' => 2, 'cells' => [['Menge', 2]], 'fmt' => (object)[]], $over);
	}

	private static function json(mixed $data): string {
		return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
	}

	private static function host(string $json, string $attrs = 'class="nn-sheet"', string $inner = self::TABLE): string {
		return '<div ' . $attrs . ' data-nn-sheet="' . htmlspecialchars($json, ENT_QUOTES | ENT_HTML5) . '" contenteditable="false">' . $inner . '</div>';
	}

	/** @return array<string, array{string}> gültige Werte */
	public static function validCases(): array {
		$full = ['v' => 1, 'lang' => 'deDE', 'rows' => 50, 'cols' => 26, 'cells' => array_fill(0, 50, array_fill(0, 26, null)), 'fmt' => ['Z50' => ['b' => 1, 'd' => 4, 'p' => 1], 'A1' => ['d' => 0]]];
		return [
			'Beispiel aus dem Plan' => [self::json(['v' => 1, 'lang' => 'deDE', 'rows' => 1, 'cols' => 3, 'cells' => [['Menge', 2, '=SUMME(B1:B3)']], 'fmt' => ['C1' => ['b' => 1, 'd' => 2, 'p' => 0]]])],
			'alle Typen' => [self::json(self::sheet(['cols' => 6, 'cells' => [[null, 1, -2.5, true, false, 'Text']]]))],
			'ohne fmt' => [self::json(['v' => 1, 'lang' => 'deDE', 'rows' => 1, 'cols' => 1, 'cells' => [[null]]])],
			'50 × 26 mit Format am Rand' => [self::json($full)],
			'Zelltext 1000 Zeichen (mehrbyte)' => [self::json(self::sheet(['cols' => 1, 'cells' => [[str_repeat('ä', 1000)]]]))],
			'Zelltext javascript:/data: bleibt' => [self::json(self::sheet(['cells' => [['javascript:alert(1)', 'data:text/html,<b>x</b>']]]))],
			'Markup im Zelltext ist nur Text' => [self::json(self::sheet(['cells' => [['<img src=x onerror=alert(1)>', '"><b onmouseover=alert(1)>x</b>']]]))],
		];
	}

	#[DataProvider('validCases')]
	public function testValid(string $json): void {
		self::assertTrue(BlockMarkup::validSheet($json));
		$s = self::s();
		$out = $s->sanitizeHtml(self::host($json));
		self::assertStringStartsWith('<div class="nn-sheet" data-nn-sheet=', $out);
		self::assertStringContainsString('contenteditable="false">', $out);
		// Attribut unverändert (DOM-Escaping), Fallback durchläuft den normalen Sanitizer
		$dom = new \DOMDocument();
		@$dom->loadHTML('<?xml encoding="utf-8"?>' . $out);
		self::assertSame($json, $dom->getElementsByTagName('div')->item(0)->getAttribute('data-nn-sheet'));
		self::assertSame($out, $s->sanitizeHtml($out), 'idempotent');
		self::assertStringNotContainsString('<img', $out);
		self::assertStringNotContainsString('<script', $out);
	}

	/** @return array<string, array{string}> ungültige Werte → nur das Attribut fällt weg */
	public static function invalidCases(): array {
		$big = self::sheet(['cols' => 1, 'cells' => [[str_repeat('x', 1000)]]]);
		$huge = ['v' => 1, 'lang' => 'deDE', 'rows' => 50, 'cols' => 26, 'cells' => array_fill(0, 50, array_fill(0, 26, str_repeat('y', 240)))];
		return [
			'kein JSON' => ['{v:1}'],
			'leer' => [''],
			'Array statt Objekt' => ['[1,2]'],
			'v = 2' => [self::json(self::sheet(['v' => 2]))],
			'v als Text' => [self::json(self::sheet(['v' => '1']))],
			'lang enGB' => [self::json(self::sheet(['lang' => 'enGB']))],
			'rows 0' => [self::json(self::sheet(['rows' => 0, 'cells' => []]))],
			'51 Zeilen' => [self::json(['v' => 1, 'lang' => 'deDE', 'rows' => 51, 'cols' => 1, 'cells' => array_fill(0, 51, [null])])],
			'27 Spalten' => [self::json(['v' => 1, 'lang' => 'deDE', 'rows' => 1, 'cols' => 27, 'cells' => [array_fill(0, 27, null)]])],
			'rows passt nicht zu cells' => [self::json(self::sheet(['rows' => 2]))],
			'Zeile zu kurz' => [self::json(self::sheet(['cells' => [['a']]]))],
			'falscher Zelltyp (Objekt)' => [self::json(self::sheet(['cells' => [['a', ['x' => 1]]]]))],
			'falscher Zelltyp (Array)' => [self::json(self::sheet(['cells' => [['a', [1]]]]))],
			'Zahl 1e999 (INF)' => ['{"v":1,"lang":"deDE","rows":1,"cols":2,"cells":[["a",1e999]],"fmt":{}}'],
			'Zusatzschlüssel' => [self::json(self::sheet(['x' => 1]))],
			'__proto__' => ['{"v":1,"lang":"deDE","rows":1,"cols":2,"cells":[["a",2]],"fmt":{},"__proto__":{"polluted":1}}'],
			'__proto__ in fmt' => ['{"v":1,"lang":"deDE","rows":1,"cols":2,"cells":[["a",2]],"fmt":{"__proto__":{"b":1}}}'],
			'Zelltext 1001 Zeichen' => [self::json(self::sheet(['cols' => 1, 'cells' => [[str_repeat('x', 1001)]]]))],
			'fmt außerhalb des Rasters (Spalte)' => [self::json(self::sheet(['fmt' => ['C1' => ['b' => 1]]]))],
			'fmt außerhalb des Rasters (Zeile)' => [self::json(self::sheet(['fmt' => ['A2' => ['b' => 1]]]))],
			'fmt Adresse A0' => [self::json(self::sheet(['fmt' => ['A0' => ['b' => 1]]]))],
			'fmt d = 5' => [self::json(self::sheet(['fmt' => ['A1' => ['d' => 5]]]))],
			'fmt b = 2' => [self::json(self::sheet(['fmt' => ['A1' => ['b' => 2]]]))],
			'fmt unbekannt' => [self::json(self::sheet(['fmt' => ['A1' => ['color' => 'red']]]))],
			'fmt als Liste' => [self::json(self::sheet(['fmt' => []]))],
			'zu tief' => [self::json(self::sheet(['cells' => [['a', [[[1]]]]]]))],
			'über 256 KiB' => [self::json($huge)],
			'kaputtes UTF-8' => ["{\"v\":1,\"lang\":\"deDE\",\"rows\":1,\"cols\":1,\"cells\":[[\"\xff\"]]}"],
			'abgeschnittenes JSON' => [substr(self::json($big), 0, -1)],
		];
	}

	#[DataProvider('invalidCases')]
	public function testInvalidRemovesOnlyAttribute(string $json): void {
		self::assertFalse(BlockMarkup::validSheet($json));
		$out = self::s()->sanitizeHtml(self::host($json));
		self::assertStringNotContainsString('data-nn-sheet', $out);
		// Tabelle bleibt als statischer Inhalt
		self::assertStringContainsString('<td>Menge</td>', $out);
	}

	// Bestehender Ausgabefilter des Sanitizers entfernt „<script“ überall, auch im Attribut; JSON bleibt gültig
	public function testScriptTextKeepsValidJson(): void {
		$out = self::s()->sanitizeHtml(self::host(self::json(self::sheet(['cells' => [['<script>alert(1)</script>', 2]]]))));
		self::assertStringNotContainsString('script', $out);
		self::assertMatchesRegularExpression('/data-nn-sheet=/', $out);
		$dom = new \DOMDocument();
		@$dom->loadHTML('<?xml encoding="utf-8"?>' . $out);
		self::assertTrue(BlockMarkup::validSheet($dom->getElementsByTagName('div')->item(0)->getAttribute('data-nn-sheet')));
	}

	public function testSizeLimitBoundary(): void {
		self::assertFalse(BlockMarkup::validSheet(str_repeat(' ', BlockMarkup::SHEET_MAX + 1)));
		$json = self::json(['v' => 1, 'lang' => 'deDE', 'rows' => 1, 'cols' => 1, 'cells' => [['a']]]);
		self::assertTrue(BlockMarkup::validSheet(str_pad($json, BlockMarkup::SHEET_MAX, ' ')));
		self::assertFalse(BlockMarkup::validSheet(str_pad($json, BlockMarkup::SHEET_MAX + 1, ' ')));
	}

	public function testDetectedWithoutClassAndOrder(): void {
		$json = self::json(self::sheet());
		$out = self::s()->sanitizeHtml('<div data-nn-sheet="' . htmlspecialchars($json) . '" contenteditable="true">' . self::TABLE . '</div>');
		self::assertStringStartsWith('<div class="nn-sheet" data-nn-sheet=', $out);
		self::assertMatchesRegularExpression('/["\x27] contenteditable="false"><table class="nn-sheet-table">/', $out);
		$other = self::s()->sanitizeHtml('<div class="foo" data-nn-sheet="' . htmlspecialchars($json) . '">' . self::TABLE . '</div>');
		self::assertStringStartsWith('<div class="foo nn-sheet" data-nn-sheet=', $other);
	}

	public function testOnlyDiv(): void {
		$json = htmlspecialchars(self::json(self::sheet()));
		self::assertSame('<span>x</span>', self::s()->sanitizeHtml('<span data-nn-sheet="' . $json . '">x</span>'));
		self::assertSame('<p>x</p>', self::s()->sanitizeHtml('<p data-nn-sheet="' . $json . '">x</p>'));
	}

	public function testNestedHostsRemoved(): void {
		$json = self::json(self::sheet());
		$inner = '<table class="nn-sheet-table"><tbody><tr><td>a<span data-math="x">x</span></td><td><div data-nn-sheet="' . htmlspecialchars($json) . '">' . self::TABLE . '</div>b</td></tr></tbody></table>';
		$out = self::s()->sanitizeHtml(self::host($json, '', $inner));
		self::assertSame(1, substr_count($out, 'data-nn-sheet'));
		self::assertStringNotContainsString('data-math', $out);
		self::assertStringContainsString('<td>a</td><td>b</td>', $out);
	}

	public function testDoubleAttributeRemovesBoth(): void {
		$json = htmlspecialchars(self::json(self::sheet()));
		$out = self::s()->sanitizeHtml('<div data-math="x" data-nn-sheet="' . $json . '">' . self::TABLE . '</div>');
		self::assertStringNotContainsString('data-nn-sheet', $out);
		self::assertStringNotContainsString('data-math', $out);
	}

	public function testFallbackSanitizedAndEventAttributes(): void {
		$json = self::json(self::sheet());
		$inner = '<table class="nn-sheet-table" onclick="x()"><tbody><tr><td data-formula="sum-col">1</td><td><img src="x" onerror="alert(1)"><script>alert(1)</script>2</td></tr></tbody></table>';
		$out = self::s()->sanitizeHtml(self::host($json, 'class="nn-sheet" onmouseover="x()"', $inner));
		self::assertStringNotContainsString('onclick', $out);
		self::assertStringNotContainsString('onmouseover', $out);
		self::assertStringNotContainsString('onerror', $out);
		self::assertStringNotContainsString('<script', $out);
		self::assertStringContainsString('data-nn-sheet=', $out);
	}
}
