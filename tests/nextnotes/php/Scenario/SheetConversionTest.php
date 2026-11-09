<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Scenario;

use OCA\NextNotes\Service\ConversionService;
use OCA\NextNotes\Service\Convert\MarkdownRenderer;
use OCA\NextNotes\Service\Convert\NextBlocks;
use OCA\NextNotes\Service\EmbedDomainService;
use OCA\NextNotes\Service\HtmlSanitizer;
use OCA\NextNotes\Service\InputValidator;
use OCA\NextNotes\Service\SearchTextNormalizer;
use PHPUnit\Framework\TestCase;

// M8b Done 9 (M8-Plan §3): Tabellenblock HTML → Markdown als Tabelle mit Ergebnissen (keine Formeln); Suche ohne Formeltext
final class SheetConversionTest extends TestCase {
	private static function s(): HtmlSanitizer {
		return new HtmlSanitizer(new class extends EmbedDomainService {
			public function __construct() {
			}

			public function domains(): array {
				return [];
			}
		});
	}

	private static function sheet(): string {
		$json = json_encode(['v' => 1, 'lang' => 'deDE', 'rows' => 3, 'cols' => 2, 'cells' => [['Menge', 2], ['Preis', '3,5'], ['Summe', '=SUMME(B1:B2)']], 'fmt' => ['B3' => ['b' => 1, 'd' => 2]]]);
		return '<div class="nn-sheet" data-nn-sheet="' . htmlspecialchars($json) . '" contenteditable="false"><table class="nn-sheet-table"><tbody>'
			. '<tr><td>Menge</td><td class="nn-num">2</td></tr>'
			. '<tr><td>Preis | netto</td><td class="nn-num">3,5</td></tr>'
			. '<tr><td>Summe</td><td class="nn-num"><strong>5,50</strong></td></tr>'
			. '</tbody></table></div>';
	}

	public function testTopLevelSheetBecomesResultTable(): void {
		$html = self::s()->sanitizeHtml('<p>Vorher</p>' . self::sheet() . '<p>Danach <span data-math="x^2">x</span></p>');
		$md = ConversionService::noteToMarkdown($html);
		self::assertSame("Vorher\n\n| Menge | 2 |\n| --- | --- |\n| Preis \\| netto | 3,5 |\n| Summe | 5,50 |\n\nDanach \$x^2\$", $md);
		self::assertStringNotContainsString('SUMME', $md);
		self::assertStringNotContainsString('data-nn-sheet', $md);
		// Rückweg: normale Tabelle, kein Block, Absatz bleibt Absatz
		MarkdownRenderer::setTranslator(null);
		$back = self::s()->sanitizeHtml(MarkdownRenderer::parseMarkdownForRichText($md));
		self::assertStringNotContainsString('data-nn-sheet', $back);
		self::assertStringContainsString('<table', $back);
		self::assertStringContainsString('5,50', $back);
		self::assertMatchesRegularExpression('/<p>Danach <span class="math-inline" data-math="x\^2"/', $back);
	}

	public function testNestedSheetIsUnwrappedForTableConverter(): void {
		[$pre, $map] = NextBlocks::toMarkdownPre('<ul><li>a' . self::sheet() . '</li></ul>');
		self::assertSame([], $map);
		self::assertStringNotContainsString('data-nn-sheet', $pre);
		self::assertStringContainsString('<table class="nn-sheet-table">', $pre);
	}

	public function testTableHelper(): void {
		self::assertSame("\n\n| a | b |\n| --- | --- |\n| c |  |\n\n", NextBlocks::table([['a', 'b'], ['c']]));
		self::assertSame('', NextBlocks::table([]));
	}

	// Review M8b H4: Zelltext mit Markdown-Zeichen kommt beim Rückweg als derselbe Text an (kein Markup, keine Spaltenverschiebung)
	public function testTableCellsRoundTripAsText(): void {
		$cells = ['a\\|b', '<b>x</b>', '*k*', '2 * 3', '$x$', '[l](http://a)', '==h==', '~~s~~', '_i_', '`c`', '\\', '=sum(col)', 'a|b', '&amp;', '5,5', 'x\\\\y', 'a`b\\'];
		$md = NextBlocks::table([array_map(static fn (int $i): string => 'h' . $i, array_keys($cells)), $cells]);
		self::assertStringContainsString('| \\<b\\>x\\</b\\> | \\*k\\* |', $md);
		MarkdownRenderer::setTranslator(null);
		$html = MarkdownRenderer::parseMarkdownForRichText(trim($md));
		self::assertSame(1, preg_match_all('#<table>#', $html));
		self::assertSame(count($cells), preg_match_all('#<td>(.*?)</td>#s', $html, $m));
		self::assertStringNotContainsString('<b>', $html);
		self::assertStringNotContainsString('<em>', $html);
		self::assertStringNotContainsString('<mark>', $html);
		self::assertStringNotContainsString('data-math', $html);
		foreach ($cells as $i => $text) {
			self::assertSame($text, html_entity_decode(strip_tags($m[1][$i]), ENT_QUOTES | ENT_HTML5), 'Zelle ' . $i);
		}
	}

	public function testSearchFindsResultsNotFormulas(): void {
		$text = (new SearchTextNormalizer($this->createStub(InputValidator::class)))->plainText(self::s()->sanitizeHtml(self::sheet()), 'note');
		self::assertStringContainsString('Menge', $text);
		self::assertStringContainsString('5,50', $text);
		self::assertStringNotContainsString('SUMME', $text);
		self::assertStringNotContainsString('"cells"', $text);
	}
}
