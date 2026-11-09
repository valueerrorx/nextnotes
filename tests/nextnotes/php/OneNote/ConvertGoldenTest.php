<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\OneNote;

use OCA\NextNotes\OneNote\Convert\PageConverter;
use OCA\NextNotes\Service\EmbedDomainService;
use OCA\NextNotes\Service\HtmlSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/fixtures/onenote/golden.php';

// M9c Done 1–5, 7: Fixture-Paare (fixtures/onenote, Erwartung aus gen-expected.php) und Einzelaussagen dazu
final class ConvertGoldenTest extends TestCase {
	private static function dir(): string {
		return dirname(__DIR__, 2) . '/fixtures/onenote';
	}

	public static function sanitizer(): HtmlSanitizer {
		return new HtmlSanitizer(new class extends EmbedDomainService {
			public function __construct() {
			}

			public function domains(): array {
				return [];
			}
		});
	}

	public static function converter(): PageConverter {
		return new PageConverter(self::sanitizer());
	}

	private static function html(string $name): string {
		return self::converter()->convert((string)file_get_contents(self::dir() . '/' . $name . '.html'), new GoldenL10N())->html;
	}

	/** @return array<string, array{string}> */
	public static function fixtures(): array {
		$out = [];
		foreach (glob(self::dir() . '/*.html') ?: [] as $file) {
			$out[basename($file, '.html')] = [basename($file, '.html')];
		}
		return $out;
	}

	#[DataProvider('fixtures')]
	public function testGolden(string $name): void {
		$expected = json_decode((string)file_get_contents(self::dir() . '/expected.json'), true, 512, JSON_THROW_ON_ERROR);
		$actual = Golden::all(self::converter(), new GoldenL10N(), self::dir());
		self::assertArrayHasKey($name, $expected, 'expected.json neu erzeugen (gen-expected.php)');
		self::assertSame($expected[$name], $actual[$name], $name);
	}

	// Done 7: Ausgabe ist Fixpunkt des Sanitizers, auch nach dem Einsetzen der Anhang-URLs
	#[DataProvider('fixtures')]
	public function testSanitizerFixpoint(string $name): void {
		$c = self::converter();
		$r = $c->convert((string)file_get_contents(self::dir() . '/' . $name . '.html'), new GoldenL10N());
		self::assertSame($r->html, self::sanitizer()->sanitizeHtml($r->html));
		$urls = [];
		foreach ($r->resources as $res) {
			$urls[$res->index] = $res->index % 2 === 0 ? '/api/v1/notes/7/attachments/' . (100 + $res->index) : null;
		}
		$applied = $c->applyResources($r->html, $urls, new GoldenL10N());
		self::assertSame($applied, self::sanitizer()->sanitizeHtml($applied));
		self::assertStringNotContainsString(PageConverter::PLACEHOLDER, $applied);
	}

	// Done 1: Lesereihenfolge (top, left) bei Blöcken über Kreuz; ohne Angabe gilt 120/48
	public function testReadingOrder(): void {
		$html = self::html('crosswise-blocks');
		$pos = array_map(static fn (string $s): int|false => strpos($html, $s), ['<img', 'Eins', 'Zwei', 'Zweieinhalb', 'Drei']);
		self::assertNotContains(false, $pos);
		$sorted = $pos;
		sort($sorted);
		self::assertSame($sorted, $pos, $html);
		self::assertLessThan(strpos(self::html('learn-abs-pos'), 'absolute positioned div'), strpos(self::html('learn-abs-pos'), 'also appear'));
	}

	// Done 2: To-do offen/erledigt → Upstream-Checkliste (checklist.js), aufeinanderfolgende in einer Liste
	public function testTodoBecomesChecklist(): void {
		$html = self::html('learn-note-tags');
		self::assertStringContainsString('<ul class="checklist"><li class="checklist-item checklist-item-checked"><input type="checkbox" class="checklist-checkbox" checked> <span class="checklist-text">Till garden bed</span></li><li class="checklist-item"><input type="checkbox" class="checklist-checkbox"> <span class="checklist-text">Plant peas and spinach</span></li>', $html);
		// To-dos mitten in einer nummerierten Liste: Checkliste dazwischen, Zählung läuft weiter
		self::assertStringContainsString('<ol start="4"', $html);
		self::assertStringContainsString('<span class="checklist-text">Schon erledigt</span>', $html);
		// Kennzeichen-Präfixe, übrige Kennzeichen entfallen
		self::assertStringContainsString('<p>⭐ Next week', $html);
		self::assertStringContainsString('<li>❗ Design handouts</li>', $html);
		self::assertStringContainsString('<p>Around the room updates.</p>', $html);
		self::assertStringNotContainsString('data-tag', $html);
	}

	// Done 3: Tabelle mit colspan/rowspan bleibt, ungültige Spannen entfallen
	public function testTableSpans(): void {
		$html = self::html('learn-tables');
		self::assertStringContainsString('<td colspan="2">', $html);
		self::assertStringContainsString('<td rowspan="2">Rechts</td>', $html);
		self::assertStringContainsString('<table><tr><td>innen</td></tr></table>', $html);
		self::assertStringNotContainsString('99999', $html);
		self::assertStringNotContainsString('border', $html);
		self::assertStringNotContainsString('width', $html);
	}

	// Done 4: Stile reduziert, keine Position, keine Schrift
	public function testStylesReduced(): void {
		foreach (self::fixtures() as [$name]) {
			preg_match_all('/\sstyle="([^"]*+)"/', self::html($name), $m);
			$styles = implode(' | ', $m[1]);
			foreach (['position', 'font-family', 'font-size', 'margin', 'top', 'left:', 'width', 'z-index', 'url(', 'expression', 'behavior', 'binding', 'border'] as $bad) {
				self::assertStringNotContainsString($bad, $styles, $name . ': ' . $bad);
			}
			// nur die erlaubten Eigenschaften (Plan M9 §5)
			preg_match_all('/(?:^|; |\| )([a-z-]++):/', $styles, $props);
			self::assertSame([], array_diff(array_unique($props[1]), ['color', 'background-color', 'font-weight', 'font-style', 'text-decoration', 'text-align', 'list-style-type', 'counter-reset']), $name);
		}
		$html = self::html('learn-styles-lists');
		self::assertStringContainsString('<h1>Heading <em>One</em> text</h1>', $html);
		self::assertStringContainsString('<p style="text-align: center;"><span style="color: green;">Some text</span></p>', $html);
		self::assertStringContainsString('<li style="list-style-type: upper-roman;"><span style="color: blue;"><strong>Jacksonville</strong></span></li>', $html);
		self::assertStringContainsString(' and default colours</p>', $html);
	}

	// Done 5: Video → Link, onenote:-Link → Text; Ressourcen nur von graph.microsoft.com
	public function testLinksAndVideo(): void {
		$html = self::html('learn-images-files');
		self::assertStringContainsString('<a href="https://www.youtube.com/watch?v=3Ztr44aKmQ8">Video: https://www.youtube.com/watch?v=3Ztr44aKmQ8</a>', $html);
		self::assertStringNotContainsString('iframe', $html);
		self::assertStringContainsString('<p>[Image not imported]</p><p>[File not imported: fileName.pdf]</p>', $html);
		$evil = self::html('malicious');
		self::assertStringContainsString(' OneNote-Link ', $evil);
		self::assertStringNotContainsString('onenote:', $evil);
	}

	// Bösartige Eingaben: nichts Ausführbares, keine fremden Ressourcen
	public function testMaliciousInput(): void {
		$c = self::converter();
		$r = $c->convert((string)file_get_contents(self::dir() . '/malicious.html'), new GoldenL10N());
		$html = $r->html;
		self::assertSame([], $r->resources);
		self::assertSame(9, $r->skippedResources);
		foreach (['<script', '<style', '<iframe', '<object', '<embed', '<svg', '<form', '<input type="password"', '<textarea', '<template', '<noscript', '<!--', '<meta', '<base', 'xlink', 'target='] as $bad) {
			self::assertStringNotContainsString($bad, $html, $bad);
		}
		self::assertDoesNotMatchRegularExpression('/\son[a-z]++\s*+=/i', $html);
		self::assertDoesNotMatchRegularExpression('/(?:href|src)="(?!https:\/\/|mailto:)/i', $html);
		self::assertStringContainsString('<a href="mailto:lehrer@example.org">Mail</a>', $html);
		self::assertStringContainsString('<a href="https://example.org/">Ziel</a>', $html);
		// span mit Upstream-Klasse und To-do-Kennzeichen wird kein Kästchen
		self::assertStringNotContainsString('checklist', $html);
	}

	public function testMathAndInk(): void {
		$html = self::html('math');
		self::assertStringContainsString('<span class="math-inline" data-math="a^{2} + b^{2} = c^{2}" contenteditable="false">a^{2} + b^{2} = c^{2}</span>', $html);
		self::assertStringContainsString('<div class="math-block" data-math="x = \frac{- b \pm \sqrt{b^{2} - 4ac}}{2a}" contenteditable="false">', $html);
		self::assertStringContainsString('data-math="E = mc^2"', $html);
		self::assertStringContainsString('<p>Unbekannt: kRest</p>', $html);
		$r = self::converter()->convert((string)file_get_contents(self::dir() . '/ink.html'), new GoldenL10N());
		self::assertSame(3, $r->ink);
		self::assertSame([], $r->resources);
		self::assertSame(3, substr_count($r->html, '[Ink drawing not imported]'));
	}
}
