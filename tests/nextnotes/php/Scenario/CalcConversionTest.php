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

// M8c Done 7 (M8-Plan §4): Rechenblock HTML → Markdown als Codeblock `Eingabe  # = Ergebnis`; Suche findet Eingabe und Ergebnis
final class CalcConversionTest extends TestCase {
	private static function s(): HtmlSanitizer {
		return new HtmlSanitizer(new class extends EmbedDomainService {
			public function __construct() {
			}

			public function domains(): array {
				return [];
			}
		});
	}

	/** @param list<array{string, string, bool}> $lines Eingabe, Ausgabe, Fehler */
	private static function calc(array $lines, string $graph = ''): string {
		$src = implode("\n", array_map(static fn (array $l): string => $l[0], $lines));
		$html = '<div class="nn-calc" data-nn-calc="' . htmlspecialchars(json_encode(['v' => 1, 'src' => $src, 'graph' => 1], JSON_UNESCAPED_UNICODE)) . '" contenteditable="false">';
		foreach ($lines as [$in, $out, $err]) {
			$html .= '<p class="nn-calc-line"><span class="nn-calc-in">' . htmlspecialchars($in) . '</span> <span class="nn-calc-out' . ($err ? ' nn-err' : '') . '">' . htmlspecialchars($out) . '</span></p>';
		}
		return $html . ($graph !== '' ? '<p class="nn-calc-graph">' . $graph . '</p>' : '') . '</div>';
	}

	private static function example(): string {
		return self::calc([['a = 3', '3', false], ['b = a^2 + 1', '10', false], ['', '', false], ['# Strecke', '', false], ['5 km / 20 min to km/h', '15 km/h', false], ['1/0 +', 'Fehler: Eingabe nicht lesbar', true], ['f(x) = x^2 - 2', '', false]], 'Graph: f(x)');
	}

	public function testTopLevelCalcBecomesCodeBlock(): void {
		$html = self::s()->sanitizeHtml('<p>Vorher</p>' . self::example() . '<p>Danach</p>');
		$md = ConversionService::noteToMarkdown($html);
		self::assertSame("Vorher\n\n```\na = 3  # = 3\nb = a^2 + 1  # = 10\n\n# Strecke\n5 km / 20 min to km/h  # = 15 km/h\n1/0 +  # Fehler: Eingabe nicht lesbar\nf(x) = x^2 - 2\n```\n\nDanach", $md);
		self::assertStringNotContainsString('data-nn-calc', $md);
		self::assertStringNotContainsString('Graph:', $md);
		MarkdownRenderer::setTranslator(null);
		$back = self::s()->sanitizeHtml(MarkdownRenderer::parseMarkdownForRichText($md));
		self::assertStringNotContainsString('data-nn-calc', $back);
		self::assertSame(1, preg_match_all('#<pre\b#', $back));
		self::assertStringContainsString('5 km / 20 min to km/h  # = 15 km/h', html_entity_decode($back));
		self::assertMatchesRegularExpression('#</pre>\s*(?:<p class="blank-line">[^<]*</p>\s*)?<p>Danach</p>#', $back, 'Absatz danach bleibt Absatz');
	}

	// Backticks im Quelltext: Zaun bleibt aus drei Backticks (Port-Renderer), keine Zeile besteht nur aus ```
	public function testBackticksStayInsideCodeBlock(): void {
		$html = self::s()->sanitizeHtml(self::calc([['```', '', false], ['x = `1`', 'Fehler: Eingabe nicht lesbar', true], ['  ```  ', '', false], ['````', 'Fehler: Eingabe nicht lesbar', true]]) . '<p>Danach</p>');
		$md = ConversionService::noteToMarkdown($html);
		self::assertSame("```\n``` #\nx = `1`  # Fehler: Eingabe nicht lesbar\n  ``` #\n````  # Fehler: Eingabe nicht lesbar\n```\n\nDanach", $md);
		MarkdownRenderer::setTranslator(null);
		$back = MarkdownRenderer::parseMarkdownForRichText($md);
		self::assertSame(1, preg_match_all('#<pre\b#', $back));
		self::assertMatchesRegularExpression('#</pre>\s*(?:<p class="blank-line">[^<]*</p>\s*)?<p[^>]*>Danach</p>#', $back);
		self::assertStringContainsString('x = `1`', html_entity_decode($back));
	}

	public function testNestedOrBrokenCalcIsUnwrapped(): void {
		[$pre, $map] = NextBlocks::toMarkdownPre('<ul><li>a' . self::calc([['1+1', '2', false]]) . '</li></ul>');
		self::assertSame([], $map);
		self::assertStringNotContainsString('data-nn-calc', $pre);
		self::assertStringContainsString('<span class="nn-calc-in">1+1</span>', $pre);
		self::assertSame('', NextBlocks::codeBlock(['', '  ']));
	}

	public function testSearchFindsInputAndResult(): void {
		$text = (new SearchTextNormalizer($this->createStub(InputValidator::class)))->plainText(self::s()->sanitizeHtml(self::example()), 'note');
		self::assertStringContainsString('5 km / 20 min to km/h', $text);
		self::assertStringContainsString('15 km/h', $text);
		self::assertStringNotContainsString('"src"', $text);
	}
}
