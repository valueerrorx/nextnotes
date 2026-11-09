<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Security;

use OCA\NextNotes\Service\Convert\MarkdownRenderer;
use OCA\NextNotes\Service\EmbedDomainService;
use OCA\NextNotes\Service\Exception\TooLargeException;
use OCA\NextNotes\Service\HtmlSanitizer;
use OCA\NextNotes\Service\ImportService;
use OCP\Server;
use PHPUnit\Framework\TestCase;

// M9c-fix (B37): Sanitizer, Import und Rendern kürzen nie still; Textknoten > 10 MB vollständig, Tiefe > 2048 in jeder libxml-Version abgelehnt
final class SanitizerHugeTest extends TestCase {
	private static function s(): HtmlSanitizer {
		return new HtmlSanitizer(new class extends EmbedDomainService {
			public function __construct() {
			}

			public function domains(): array {
				return [];
			}
		});
	}

	private static function text(): string {
		return str_repeat('a b ', 3 * 1024 * 1024);
	}

	public function testSanitizerHugeTextAndDepth(): void {
		$text = self::text();
		self::assertSame('<p>' . $text . '</p><p>AFTER</p>', self::s()->sanitizeHtml('<p>' . $text . '</p><p>AFTER</p>'));
		// html + body + 2046 = 2048 offene Elemente: libxml 2.15 noch ohne Abbruch, eigene Grenze ebenso
		$ok = self::s()->sanitizeHtml(str_repeat('<div>', 2046) . 'x' . str_repeat('</div>', 2046) . '<p>AFTER</p>');
		self::assertStringContainsString('<p>AFTER</p>', $ok);
		foreach ([2047, 300000] as $n) {
			try {
				self::s()->sanitizeHtml(str_repeat('<div>', $n) . 'x' . str_repeat('</div>', $n) . '<p>AFTER</p>');
				self::fail("Tiefe $n angenommen");
			} catch (TooLargeException) {
				self::assertTrue(true);
			}
		}
	}

	public function testImportTasklistHugeTextAndDepth(): void {
		$import = Server::get(ImportService::class);
		$text = self::text();
		$html = '<div class="task-item" data-task-id="1"><span class="task-text">' . $text . '</span></div>'
			. '<div class="task-item" data-task-id="2"><span class="task-text">AFTER</span></div>';
		$note = $import->noteFromFile($html, 'liste.html', 'html');
		self::assertSame('tasklist', $note['type']);
		$tasks = json_decode($note['content'], true, 512, JSON_THROW_ON_ERROR);
		self::assertSame([trim($text), 'AFTER'], array_column($tasks, 'text'));
		$this->expectException(TooLargeException::class);
		$import->noteFromFile('<div class="task-item" data-task-id="1">' . str_repeat('<span>', 3000) . '<span class="task-text">x</span></div>', 'tief.html', 'html');
	}

	public function testRenderExcalidrawHugeTextAndDepth(): void {
		$text = self::text();
		$out = MarkdownRenderer::sanitizeMarkdownExcalidrawContainer('<div class="excalidraw-container"><div class="excalidraw-data">' . $text . '</div><p>AFTER</p></div>');
		self::assertSame('<div class="excalidraw-container"><div class="excalidraw-data">' . $text . '</div><p>AFTER</p></div>', $out);
		$this->expectException(TooLargeException::class);
		MarkdownRenderer::sanitizeMarkdownExcalidrawContainer('<div class="excalidraw-container">' . str_repeat('<span>', 3000) . 'x</div>');
	}
}
