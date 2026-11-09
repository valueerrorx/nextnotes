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

// M8c (SPEC §4.7.10, M8-Plan §4 Done 6): Rechenblock-Vertrag im Sanitizer, Schema v1, is_finite, Klasse/Verschachtelung/Doppel-Attribut
final class CalcMarkupTest extends TestCase {
	private const LINES = '<p class="nn-calc-line"><span class="nn-calc-in">a = 3</span> <span class="nn-calc-out">3</span></p>';

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
	private static function calc(array $over = []): array {
		return array_replace(['v' => 1, 'src' => 'a = 3', 'graph' => 1], $over);
	}

	private static function json(mixed $data): string {
		return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}

	private static function host(string $json, string $attrs = 'class="nn-calc"', string $inner = self::LINES): string {
		return '<div ' . $attrs . ' data-nn-calc="' . htmlspecialchars($json, ENT_QUOTES | ENT_HTML5) . '" contenteditable="false">' . $inner . '</div>';
	}

	private static function attr(string $html): ?string {
		$dom = new \DOMDocument();
		@$dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
		$div = $dom->getElementsByTagName('div')->item(0);
		return $div !== null && $div->hasAttribute('data-nn-calc') ? $div->getAttribute('data-nn-calc') : null;
	}

	/** @return array<string, array{string}> */
	public static function validCases(): array {
		return [
			'Beispiel aus dem Plan' => [self::json(self::calc(['src' => "a = 3\nb = a^2 + 1\n5 km / 20 min to km/h"]))],
			'graph 0' => [self::json(self::calc(['graph' => 0]))],
			'leer' => [self::json(self::calc(['src' => '']))],
			'200 Zeilen' => [self::json(self::calc(['src' => implode("\n", array_fill(0, 200, 'x')) ]))],
			'Zeile 500 Zeichen (mehrbyte)' => [self::json(self::calc(['src' => str_repeat('ä', 500)]))],
			'20 000 Zeichen' => [self::json(self::calc(['src' => implode("\n", array_fill(0, 40, str_repeat('ü', 499)))]))],
			'Markup im Quelltext ist nur Text' => [self::json(self::calc(['src' => "<img src=x onerror=alert(1)>\n\"><b onmouseover=alert(1)>x</b>\njavascript:alert(1)\ndata:text/html,x"]))],
			'Schlüssel in anderer Reihenfolge' => ['{"graph":0,"src":"1","v":1}'],
		];
	}

	#[DataProvider('validCases')]
	public function testValid(string $json): void {
		self::assertTrue(BlockMarkup::validCalc($json));
		$s = self::s();
		$out = $s->sanitizeHtml(self::host($json));
		self::assertStringStartsWith('<div class="nn-calc" data-nn-calc=', $out);
		self::assertStringContainsString('contenteditable="false"><p class="nn-calc-line">', $out);
		self::assertSame($json, self::attr($out));
		self::assertSame($out, $s->sanitizeHtml($out), 'idempotent');
		self::assertStringNotContainsString('<img', $out);
		self::assertStringNotContainsString('<b ', $out);
	}

	/** @return array<string, array{string}> ungültig → nur das Attribut fällt weg */
	public static function invalidCases(): array {
		return [
			'kein JSON' => ['{v:1}'],
			'leer' => [''],
			'Array' => ['[1]'],
			'Zusatzschlüssel' => [self::json(self::calc(['x' => 1]))],
			'ohne graph' => ['{"v":1,"src":"1"}'],
			'ohne src' => ['{"v":1,"graph":1}'],
			'graph 2' => [self::json(self::calc(['graph' => 2]))],
			'graph true' => [self::json(self::calc(['graph' => true]))],
			'graph 1.0' => ['{"v":1,"src":"1","graph":1.0}'],
			'v 2' => [self::json(self::calc(['v' => 2]))],
			'v 1e999 (INF)' => ['{"v":1e999,"src":"1","graph":1}'],
			'graph 1e999 (INF)' => ['{"v":1,"src":"1","graph":1e999}'],
			'v als Text' => [self::json(self::calc(['v' => '1']))],
			'src als Zahl' => [self::json(self::calc(['src' => 1]))],
			'src als Array' => [self::json(self::calc(['src' => ['a']]))],
			'201 Zeilen' => [self::json(self::calc(['src' => implode("\n", array_fill(0, 201, 'x'))]))],
			'Zeile 501 Zeichen' => [self::json(self::calc(['src' => str_repeat('x', 501)]))],
			'20 001 Zeichen' => [self::json(self::calc(['src' => implode("\n", array_fill(0, 40, str_repeat('x', 499))) . "\nx"]))],
			'__proto__' => ['{"v":1,"src":"1","graph":1,"__proto__":{"x":1}}'],
			'zu tief' => ['{"v":1,"src":{"a":"b"},"graph":1}'],
			'kaputtes UTF-8' => ["{\"v\":1,\"src\":\"\xff\",\"graph\":1}"],
			'über 64 KiB' => [str_pad(self::json(self::calc()), BlockMarkup::CALC_MAX + 1, ' ')],
		];
	}

	#[DataProvider('invalidCases')]
	public function testInvalidRemovesOnlyAttribute(string $json): void {
		self::assertFalse(BlockMarkup::validCalc($json));
		$out = self::s()->sanitizeHtml(self::host($json));
		self::assertStringNotContainsString('data-nn-calc', $out);
		self::assertStringContainsString('<span class="nn-calc-in">a = 3</span>', $out, 'Fallback bleibt');
	}

	public function testSizeLimitBoundary(): void {
		self::assertTrue(BlockMarkup::validCalc(str_pad(self::json(self::calc()), BlockMarkup::CALC_MAX, ' ')));
		// 20 000 Zeichen mit Escapes (\u0001 = 6 Bytes) über 64 KiB
		$ctrl = self::json(self::calc(['src' => implode("\n", array_fill(0, 40, str_repeat("\u{1}", 499)))]));
		self::assertGreaterThan(BlockMarkup::CALC_MAX, strlen($ctrl));
		self::assertFalse(BlockMarkup::validCalc($ctrl));
	}

	public function testScriptInSourceKeepsValidJson(): void {
		$out = self::s()->sanitizeHtml(self::host(self::json(self::calc(['src' => "<script>alert(1)</script>\n1"]))));
		self::assertStringNotContainsString('<script', $out);
		$attr = self::attr($out);
		self::assertNotNull($attr);
		self::assertTrue(BlockMarkup::validCalc($attr));
	}

	public function testDetectedWithoutClass(): void {
		$json = self::json(self::calc());
		$out = self::s()->sanitizeHtml('<div data-nn-calc="' . htmlspecialchars($json) . '" contenteditable="true">' . self::LINES . '</div>');
		self::assertStringStartsWith('<div class="nn-calc" data-nn-calc=', $out);
		self::assertStringContainsString('contenteditable="false">', $out);
		$other = self::s()->sanitizeHtml('<div class="foo" data-nn-calc="' . htmlspecialchars($json) . '">' . self::LINES . '</div>');
		self::assertStringStartsWith('<div class="foo nn-calc" data-nn-calc=', $other);
	}

	public function testOnlyDiv(): void {
		$json = htmlspecialchars(self::json(self::calc()));
		self::assertSame('<span>x</span>', self::s()->sanitizeHtml('<span data-nn-calc="' . $json . '">x</span>'));
		self::assertSame('<p>x</p>', self::s()->sanitizeHtml('<p data-nn-calc="' . $json . '">x</p>'));
	}

	public function testNestedHostsRemoved(): void {
		$calc = self::json(self::calc());
		$sheet = json_encode(['v' => 1, 'lang' => 'deDE', 'rows' => 1, 'cols' => 1, 'cells' => [['x']]]);
		// Tabelle im Rechenblock
		$inner = self::LINES . '<p class="nn-calc-line">a<div data-nn-sheet="' . htmlspecialchars($sheet) . '"><table><tbody><tr><td>x</td></tr></tbody></table></div>b</p>';
		$out = self::s()->sanitizeHtml(self::host($calc, '', $inner));
		self::assertSame(1, substr_count($out, 'data-nn-calc'));
		self::assertStringNotContainsString('data-nn-sheet', $out);
		self::assertStringNotContainsString('<table', $out);
		// Rechenblock im Fallback einer Tabelle und im Fallback eines Rechenblocks
		$inSheet = '<div class="nn-sheet" data-nn-sheet="' . htmlspecialchars($sheet) . '"><table class="nn-sheet-table"><tbody><tr><td>a' . self::host($calc) . 'b</td></tr></tbody></table></div>';
		$out = self::s()->sanitizeHtml($inSheet);
		self::assertSame(1, substr_count($out, 'data-nn-sheet'));
		self::assertStringNotContainsString('data-nn-calc', $out);
		self::assertStringNotContainsString('a = 3', $out);
		$out = self::s()->sanitizeHtml(self::host($calc, '', self::LINES . self::host($calc)));
		self::assertSame(1, substr_count($out, 'data-nn-calc'));
		self::assertSame(2, substr_count($out, 'a = 3'), 'Attribut + eine Zeile');
	}

	public function testDoubleAttributeRemovesBoth(): void {
		$calc = htmlspecialchars(self::json(self::calc()));
		$sheet = htmlspecialchars(json_encode(['v' => 1, 'lang' => 'deDE', 'rows' => 1, 'cols' => 1, 'cells' => [['x']]]));
		$out = self::s()->sanitizeHtml('<div data-nn-sheet="' . $sheet . '" data-nn-calc="' . $calc . '">' . self::LINES . '</div>');
		self::assertStringNotContainsString('data-nn-sheet', $out);
		self::assertStringNotContainsString('data-nn-calc', $out);
		$out = self::s()->sanitizeHtml('<div data-math="x" data-nn-calc="' . $calc . '">' . self::LINES . '</div>');
		self::assertStringNotContainsString('data-math', $out);
		self::assertStringNotContainsString('data-nn-calc', $out);
	}

	public function testFallbackSanitized(): void {
		$inner = '<p class="nn-calc-line" onclick="x()"><span class="nn-calc-in">1</span> <span class="nn-calc-out nn-err">Fehler: <img src=x onerror="alert(1)"><script>alert(1)</script></span></p>';
		$out = self::s()->sanitizeHtml(self::host(self::json(self::calc()), 'class="nn-calc" onmouseover="x()"', $inner));
		self::assertStringNotContainsString('onclick', $out);
		self::assertStringNotContainsString('onmouseover', $out);
		self::assertStringNotContainsString('onerror', $out);
		self::assertStringNotContainsString('<script', $out);
		self::assertStringContainsString('<span class="nn-calc-out nn-err">Fehler: ', $out);
	}
}
