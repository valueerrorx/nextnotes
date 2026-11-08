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

// M8a (SPEC §4.7, M8-Plan §1.1a/§2): Formel-Vertrag im Sanitizer, B69 (KaTeX-Ausgabe im gespeicherten Inhalt)
final class BlockMarkupTest extends TestCase {
	private static function s(): HtmlSanitizer {
		return new HtmlSanitizer(new class extends EmbedDomainService {
			public function __construct() {
			}

			public function domains(): array {
				return [];
			}
		});
	}

	/** [Eingabe, erwartet exakt] */
	public static function contractCases(): array {
		$katex = '<span class="katex"><span class="katex-mathml"><math xmlns="http://www.w3.org/1998/Math/MathML"><semantics><mrow><msup><mi>x</mi><mn>2</mn></msup></mrow>'
			. '<annotation encoding="application/x-tex">x^2</annotation></semantics></math></span><span class="katex-html" aria-hidden="true"><span class="base">'
			. '<span class="strut" style="height:0.8141em;"></span><span class="mord mathnormal">x</span></span></span></span>';
		return [
			'B69 inline KaTeX-Ausgabe' => ['<p>a <span class="math-inline" data-math="x^2">' . $katex . '</span> b</p>',
				'<p>a <span class="math-inline" data-math="x^2" contenteditable="false">x^2</span> b</p>'],
			'B69 Block (Markdown-Altbestand span.math-block)' => ['<span class="math-block" data-math="\frac{1}{2}">' . $katex . '</span>',
				'<span class="math-block" data-math="\frac{1}{2}" contenteditable="false">\frac{1}{2}</span>'],
			'leerer Span aus Markdown-Umwandlung wird gefüllt' => ['<span class="math-inline" data-math="a+b"></span>',
				'<span class="math-inline" data-math="a+b" contenteditable="false">a+b</span>'],
			'div Block nach Vertrag' => ['<div class="math-block" data-math="\sqrt{2}" contenteditable="false">\sqrt{2}</div>',
				'<div class="math-block" data-math="\sqrt{2}" contenteditable="false">\sqrt{2}</div>'],
			'div ohne Klasse erkannt' => ['<div data-math="y">alt</div>', '<div class="math-block" data-math="y" contenteditable="false">y</div>'],
			'span ohne Klasse erkannt' => ['<span data-math="y">alt</span>', '<span class="math-inline" data-math="y" contenteditable="false">y</span>'],
			'span mit fremder Klasse erkannt' => ['<span class="foo" data-math="y">alt</span>', '<span class="foo math-inline" data-math="y" contenteditable="false">y</span>'],
			'data: und javascript: als Text bleiben' => ['<span class="math-inline" data-math="data:text/html,javascript:alert(1)">x</span>',
				'<span class="math-inline" data-math="data:text/html,javascript:alert(1)" contenteditable="false">data:text/html,javascript:alert(1)</span>'],
			'contenteditable=true wird false' => ['<span class="math-inline" data-math="z" contenteditable="true">z</span>',
				'<span class="math-inline" data-math="z" contenteditable="false">z</span>'],
			'Länge 10000 erlaubt' => ['<span data-math="' . str_repeat('a', BlockMarkup::MATH_MAX) . '">q</span>',
				'<span class="math-inline" data-math="' . str_repeat('a', BlockMarkup::MATH_MAX) . '" contenteditable="false">' . str_repeat('a', BlockMarkup::MATH_MAX) . '</span>'],
			'Länge 10001 → normaler Text' => ['<span class="math-inline" data-math="' . str_repeat('a', BlockMarkup::MATH_MAX + 1) . '">q</span>', '<span class="math-inline">q</span>'],
			'leeres data-math → normaler Text' => ['<span class="math-inline" data-math=" ">q</span>', '<span class="math-inline">q</span>'],
			'data-math an p nicht erlaubt' => ['<p data-math="x">t</p>', '<p>t</p>'],
			'Host in Host verschwindet mit dem Fallback' => ['<div data-math="a"><span data-math="b">b</span><div data-math="c">c</div></div>',
				'<div class="math-block" data-math="a" contenteditable="false">a</div>'],
			'Markup im Wert bleibt Text' => ['<span data-math="&quot;&gt;&lt;img src=x onerror=alert(1)&gt;">q</span>',
				'<span class="math-inline" data-math=\'"&gt;&lt;img src=x onerror=alert(1)&gt;\' contenteditable="false">"&gt;&lt;img src=x onerror=alert(1)&gt;</span>'],
			'Ereignis-Attribut am Host entfernt' => ['<span class="math-inline" data-math="x" onclick="alert(1)">x</span>',
				'<span class="math-inline" data-math="x" contenteditable="false">x</span>'],
			'ohne data-math normales Element' => ['<span class="math-inline">frei</span>', '<span class="math-inline">frei</span>'],
		];
	}

	#[DataProvider('contractCases')]
	public function testContract(string $input, string $expected): void {
		$s = self::s();
		$out = $s->sanitizeHtml($input);
		self::assertSame($expected, $out);
		self::assertSame($out, $s->sanitizeHtml($out), 'idempotent');
		self::assertStringNotContainsString('<math', $out);
		self::assertStringNotContainsString('katex', $out);
	}

	public function testHostInsideUnknownElementAndList(): void {
		$out = self::s()->sanitizeHtml('<ul><li><custom-x><span data-math="a">k</span></custom-x></li></ul>');
		self::assertSame('<ul><li><span class="math-inline" data-math="a" contenteditable="false">a</span></li></ul>', $out);
	}

	public function testMarkdownNotesUntouched(): void {
		$md = "Formel <span class=\"math-inline\" data-math=\"x\"></span>\n\n\$y\$";
		self::assertSame($md, self::s()->sanitizeForType('markdown', $md));
	}

	public function testMultipleHostsAndOrder(): void {
		$in = '<p><span data-math="a">1</span> und <span data-math="b">2</span></p><div data-math="c">3</div>';
		$out = self::s()->sanitizeHtml($in);
		self::assertSame(3, substr_count($out, 'contenteditable="false"'));
		self::assertMatchesRegularExpression('/data-math="a"[^>]*>a<.*data-math="b"[^>]*>b<.*data-math="c"[^>]*>c</s', $out);
	}
}
