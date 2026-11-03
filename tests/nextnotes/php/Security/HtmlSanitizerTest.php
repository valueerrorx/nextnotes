<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Security;

use OCA\NextNotes\Service\EmbedDomainService;
use OCA\NextNotes\Service\HtmlSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

// Done M2a 8: Fälle aus Upstream tests/html-sanitize.test.php als Datensatz (Port, Upstream-Datei unverändert)
final class HtmlSanitizerTest extends TestCase {
	// Embed-Liste fest www.youtube.com (ab Werk leer, M4a); Admin-Liste selbst prüft EmbedDomainTest
	private static function s(): HtmlSanitizer {
		return new HtmlSanitizer(new class extends EmbedDomainService {
			public function __construct() {
			}

			public function domains(): array {
				return ['www.youtube.com'];
			}
		});
	}

	/** [Eingabe, erwartet gleich | null, enthält[], enthält nicht[]] */
	public static function htmlCases(): array {
		return [
			'drops script tags' => ['<p>ok</p><script>alert(1)</script>', '<p>ok</p>', [], []],
			'drops onerror' => ['<img src=x onerror=alert(1)>', null, [], ['onerror']],
			'drops onload' => ['<svg onload=alert(1)></svg>', null, [], ['onload']],
			'drops javascript: URLs' => ['<a href="javascript:alert(1)">x</a>', null, [], ['javascript:']],
			'keeps trusted embed' => ['<iframe src="https://www.youtube.com/embed/a"></iframe>', null, ['youtube.com/embed/a'], []],
			'removes other embed' => ['<iframe src="https://evil.com/x"></iframe>', null, [], ['evil.com']],
			'section content kept' => ['<section><p>hello</p></section>', '<div><p>hello</p></div>', [], []],
			'article content kept' => ['<article><h2>T</h2><p>body</p></article>', '<div><h2>T</h2><p>body</p></div>', [], []],
			'font unwrapped' => ['<font color="red"><b>bold</b></font>', '<b>bold</b>', [], []],
			'container script dropped' => ['<section><script>alert(1)</script><p>text</p></section>', '<div><p>text</p></div>', [], []],
			'container style dropped' => ['<div><style>.a{color:red}</style>visible</div>', '<div>visible</div>', [], []],
			'container iframe dropped' => ['<section><iframe src="https://evil.test/x"></iframe><p>keep</p></section>', '<div><p>keep</p></div>', [], []],
			'container onclick dropped' => ['<section onclick="alert(1)"><p>t</p></section>', null, [], ['onclick']],
			'svg labels dropped' => ['<p>a</p><svg><title>Icon name</title><path d="M0 0"/></svg>', null, ['<path d="M0 0">'], ['Icon name']],
			'list start kept' => ['<ol start="3"><li>three</li></ol>', null, ['start="3"', 'counter-reset: item 2'], []],
			'counter follows start' => ['<ol start="7" style="counter-reset: item 41"><li>x</li></ol>', null, ['counter-reset: item 6;'], []],
			'no start no counter' => ['<ol style="counter-reset: item 41"><li>x</li></ol>', null, [], ['counter-reset']],
			'start 1 needs nothing' => ['<ol start="1"><li>x</li></ol>', '<ol><li>x</li></ol>', [], []],
			'list style kept' => ['<ol start="3" style="margin-left: 0.375in; font-weight: bold;"><li>x</li></ol>', null, ['margin-left: 0.375in; font-weight: bold; counter-reset: item 2;'], []],
			'start abc dropped' => ['<ol start="abc"><li>x</li></ol>', null, [], ['start=', 'counter-reset']],
			'start css dropped' => ['<ol start="3;color:red"><li>x</li></ol>', null, [], ['start=', 'counter-reset']],
			'start 1e3 dropped' => ['<ol start="1e3"><li>x</li></ol>', null, [], ['start=', 'counter-reset']],
			'start huge dropped' => ['<ol start="12345678"><li>x</li></ol>', null, [], ['start=', 'counter-reset']],
			'start empty dropped' => ['<ol start=""><li>x</li></ol>', null, [], ['start=', 'counter-reset']],
			'start zero' => ['<ol start="0"><li>x</li></ol>', null, ['counter-reset: item -1'], []],
			// SPEC §11.2 B19 (Port-Fix, nicht Upstream)
			'B19 tab in scheme' => ['<a href="java&#9;script:alert(1)">x</a>', null, ['>x</a>'], ['script:']],
			'B19 newline in scheme' => ['<a href="java&#10;script:alert(1)">x</a>', null, [], ['script:']],
			'B19 CR hex in scheme' => ['<a href="java&#x0D;script:alert(1)">x</a>', null, [], ['script:']],
			'B19 leading space' => ['<a href=" javascript:alert(1)">x</a>', null, [], ['script:']],
			'B19 img src' => ['<img src="java&#9;script:alert(1)">', null, [], ['script:']],
			'B19 normal URL kept' => ['<a href="https://example.org/x">x</a>', null, ['href="https://example.org/x"'], []],
		];
	}

	#[DataProvider('htmlCases')]
	public function testSanitizeHtml(string $in, ?string $same, array $contains, array $notContains): void {
		$out = self::s()->sanitizeHtml($in);
		if ($same !== null) {
			self::assertSame($same, $out);
		}
		foreach ($contains as $c) {
			self::assertStringContainsString($c, $out);
		}
		foreach ($notContains as $c) {
			self::assertStringNotContainsString($c, $out);
		}
	}

	public function testTrustedDomainMatchedOnHost(): void {
		self::assertFalse(self::s()->iframeSrcIsTrusted('https://youtube.com.evil.test/x'));
		self::assertTrue(self::s()->iframeSrcIsTrusted('https://www.youtube.com/embed/a'));
		// B41: exakter Host, keine Subdomain/Elterndomain
		self::assertTrue(self::s()->iframeSrcIsTrusted('https://WWW.YouTube.com/embed/a'));
		self::assertFalse(self::s()->iframeSrcIsTrusted('https://youtube.com/embed/a'));
		self::assertFalse(self::s()->iframeSrcIsTrusted('https://m.www.youtube.com/embed/a'));
	}

	public function testCodeSegmentsRoundTrip(): void {
		$source = "before\n\n```php\n<script>alert(1)</script>\n```\n\nafter `inline <b>` end";
		$segments = [];
		$masked = HtmlSanitizer::maskCode($source, $segments);
		self::assertStringNotContainsString('alert(1)', $masked);
		self::assertSame($source, HtmlSanitizer::restoreCode($masked, $segments));
	}

	public function testMarkdownLeavesQuotedMarkupAlone(): void {
		$out = self::s()->sanitizeMarkdown("Voici du code :\n\n```html\n<script>alert(1)</script>\n```\n");
		self::assertStringContainsString('<script>alert(1)</script>', $out);
	}
}
