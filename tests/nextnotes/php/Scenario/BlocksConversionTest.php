<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Scenario;

use OCA\NextNotes\Service\ConversionService;
use OCA\NextNotes\Service\Convert\HtmlToMarkdown;
use OCA\NextNotes\Service\Convert\MarkdownRenderer;
use OCA\NextNotes\Service\Convert\NextBlocks;
use OCA\NextNotes\Service\EmbedDomainService;
use OCA\NextNotes\Service\HtmlSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

// M8a Done 10 (M8-Plan §1.1a): Formeln HTML → Markdown → HTML; $-Behandlung, Leerzeilen, Rückfall auf Code
final class BlocksConversionTest extends TestCase {
	private static function s(): HtmlSanitizer {
		return new HtmlSanitizer(new class extends EmbedDomainService {
			public function __construct() {
			}

			public function domains(): array {
				return [];
			}
		});
	}

	private static function back(string $md): string {
		MarkdownRenderer::setTranslator(null);
		return self::s()->sanitizeHtml(MarkdownRenderer::parseMarkdownForRichText($md));
	}

	/** @return list<array{string, string}> [data-math, Klasse] aller Hosts in Dokumentreihenfolge */
	private static function hosts(string $html): array {
		preg_match_all('/<(?:span|div) class="(math-inline|math-block)" data-math="([^"]*)" contenteditable="false">/', $html, $m, PREG_SET_ORDER);
		return array_map(static fn (array $x): array => [html_entity_decode($x[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'), $x[1]], $m);
	}

	/** [LaTeX, Block?, erwartetes Markdown-Stück, erwartetes data-math nach dem Rückweg] */
	public static function cases(): array {
		return [
			'inline einfach' => ['x^2', false, '$x^2$', 'x^2'],
			'inline a$b' => ['a$b', false, '$a\char36{}b$', 'a\char36{}b'],
			'inline \$5' => ['\$5', false, '$\char36{}5$', '\char36{}5'],
			'inline Leerzeile' => ["a\n\nb", false, '$a b$', 'a b'],
			'block einfach' => ['\frac{1}{2}', true, "\$\$\n\\frac{1}{2}\n\$\$", '\frac{1}{2}'],
			'block a$b' => ['a$b', true, "\$\$\na\\\$b\n\$\$", 'a\$b'],
			'block \$5' => ['\$5', true, "\$\$\n\\\$5\n\$\$", '\$5'],
			'block Leerzeile' => ["a \\\\\n\n\nb", true, "\$\$\na \\\\\nb\n\$\$", "a \\\\\nb"],
		];
	}

	#[DataProvider('cases')]
	public function testRoundTrip(string $latex, bool $block, string $mdPart, string $after): void {
		$attr = htmlspecialchars($latex, ENT_QUOTES, 'UTF-8');
		$host = $block ? '<div class="math-block" data-math="' . $attr . '" contenteditable="false">x</div>'
			: '<p>Vor <span class="math-inline" data-math="' . $attr . '" contenteditable="false">x</span> nach</p>';
		$html = self::s()->sanitizeHtml('<p>Erster</p>' . $host . '<p>Folgeabsatz</p>');
		$md = ConversionService::noteToMarkdown($html);
		self::assertStringContainsString($mdPart, $md, 'Markdown');
		self::assertStringEndsWith("\n\nFolgeabsatz", $md, 'Folgeabsatz bleibt Absatz');
		$back = self::back($md);
		self::assertSame([[$after, $block ? 'math-block' : 'math-inline']], self::hosts($back), 'Rückweg: Vertrag');
		self::assertStringContainsString('<p>Folgeabsatz</p>', $back);
		self::assertStringContainsString('<p>Erster</p>', $back);
	}

	public function testFallbackToCode(): void {
		// Backtick im Inhalt: Renderer läse vorher Inline-Code → Code-Span mit längerem Zaun
		self::assertSame('``a`b``', NextBlocks::inlineMath('a`b'));
		self::assertSame('`` `a ``', NextBlocks::codeSpan('`a'));
		// Ziffer direkt dahinter, \ oder $ davor: Inline-Muster greift nicht
		self::assertSame('`x`', NextBlocks::inlineMath('x', ' ', '5'));
		self::assertSame('`x`', NextBlocks::inlineMath('x', '\\', ' '));
		// Block mit Backtick: Codeblock latex (Renderer kennt nur Zäune aus drei Backticks)
		self::assertSame("\n\n```latex\na`b\n```\n\n", NextBlocks::blockMath('a`b'));
		self::assertSame('```` ```x ````', NextBlocks::blockMath('```x'));

		$html = self::s()->sanitizeHtml('<p>Preis <span data-math="x">x</span>5 und <span data-math="a`b">q</span></p><div data-math="c`d">q</div><p>Danach</p>');
		$md = ConversionService::noteToMarkdown($html);
		self::assertSame("Preis `x`5 und ``a`b``\n\n```latex\nc`d\n```\n\nDanach", $md);
		$back = self::back($md);
		self::assertSame([], self::hosts($back));
		self::assertStringContainsString('<p>Danach</p>', $back);
		self::assertStringContainsString('<code>a`b</code>', $back);
	}

	public function testKatexOutputAndNoMathUntouched(): void {
		// Editor-HTML mit KaTeX-Ausgabe (convert-html, B69): nur LaTeX im Markdown
		$editor = '<p>a <span class="math-inline" data-math="x^2" contenteditable="false"><span class="katex"><math><mi>x</mi></math><span>x2</span></span></span></p>';
		[$pre, $map] = NextBlocks::toMarkdownPre($editor);
		self::assertCount(1, $map['blocks']);
		self::assertStringNotContainsString('katex', $pre);
		self::assertSame('a $x^2$', NextBlocks::restore(HtmlToMarkdown::poznoteHtmlToMarkdown($pre), $map));
		// ohne Formel: Eingabe unverändert (keine DOM-Serialisierung)
		$plain = '<p>ohne <b>Formel</b> &amp; $ 5</p>';
		self::assertSame([$plain, []], NextBlocks::toMarkdownPre($plain));
	}
}
