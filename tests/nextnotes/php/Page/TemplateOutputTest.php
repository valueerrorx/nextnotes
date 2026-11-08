<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Page;

use OCP\App\IAppManager;
use OCP\Server;
use PHPUnit\Framework\TestCase;

// Done M3a2 4 / SPEC §4.6 Ausgaberegel: Grep-Allowlist print_unescaped, JSON-Flags, keine Inline-Skripte/Handler in Templates
final class TemplateOutputTest extends TestCase {
	// Erlaubte print_unescaped-Stellen: Datei => Liste der exakten Argumente (Reihenfolge egal)
	private const ALLOW = [
		'index.php' => ['json_encode($islandData, $jsonFlags)'],
		// M3c1: JSON-Inseln der Unterseiten (Flags in testJsonIslandFlags geprüft)
		'revisions.php' => ['json_encode($islandData, $jsonFlags)'],
		'attachments.php' => ['json_encode($islandData, $jsonFlags)'],
		// M3c2: Excalidraw-Editor (Konfiguration inkl. Diagramm-JSON als Insel)
		'excalidraw_editor.php' => ['json_encode($islandData, $jsonFlags)'],
		// M4c: Druckansicht; $body = ExportService::htmlBody (cleanContent → HtmlSanitizer::sanitizeHtml)
		'export_print.php' => ['json_encode($islandData, $jsonFlags)', '$body'],
		// Notizinhalt, beim Speichern durch HtmlSanitizer::sanitizeForType geprüft (Lead M3b1); Tasklist-JSON wird nicht ausgegeben
		// (Gegenprobe: EditorPageTest::testTasklistContentNeverRenderedUnsanitized)
		'partials/note_display.php' => ['$note[\'display\']'],
		// M4e: Listen-Seiten; Papierkorb-Vorschau = TrashViewService::preview (renderSnapshot/toClient bzw. eigener escapender Tasklisten-Renderer)
		'trash.php' => ['json_encode($islandData, $jsonFlags)'],
		// M4e-fix: Karten als Partial (Seite und Fragment „Mehr laden“)
		'partials/trash_cards.php' => ['$card[\'html\']'],
		'list_tags.php' => ['json_encode($islandData, $jsonFlags)'],
		'list_folders.php' => ['json_encode($islandData, $jsonFlags)'],
		'attachments_list.php' => ['json_encode($islandData, $jsonFlags)'],
		'notes_manager.php' => ['json_encode($islandData, $jsonFlags)'],
		// M4f: Tagebuch und Aufgaben (Karten/Journal per JS aus JSON-Insel bzw. entries.php)
		'diary.php' => ['json_encode($islandData, $jsonFlags)'],
		'tasks.php' => ['json_encode($islandData, $jsonFlags)'],
		// M4g: Dashboard, Graph, Speed Dial, Erwähnungen (nur JSON-Inseln)
		'dashboard.php' => ['json_encode($islandData, $jsonFlags)'],
		'graph.php' => ['json_encode($island, $jsonFlags)'],
		'speed_dial.php' => ['json_encode($island, $jsonFlags)'],
		'mentions.php' => ['json_encode($island, $jsonFlags)'],
		// M4h: Einstellungen (Inseln: Body-Attribute, Paletten-Globals, page-config-data)
		'settings.php' => ['json_encode($islandData, $jsonFlags)'],
	];

	/** @return array<string, string> relativer Pfad => Inhalt */
	private static function templates(): array {
		$dir = Server::get(IAppManager::class)->getAppPath('nextnotes') . '/templates';
		$out = [];
		$it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
		foreach ($it as $f) {
			if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) {
				$out[substr($f->getPathname(), strlen($dir) + 1)] = (string)file_get_contents($f->getPathname());
			}
		}
		ksort($out);
		self::assertArrayHasKey('index.php', $out, 'templates/index.php fehlt');
		return $out;
	}

	public function testPrintUnescapedOnlyAllowlisted(): void {
		$found = [];
		foreach (self::templates() as $file => $src) {
			preg_match_all('/print_unescaped\s*\((.*?)\)\s*;\s*\?>/s', $src, $m);
			$total = substr_count($src, 'print_unescaped');
			self::assertSame($total, count($m[1]), "$file: print_unescaped in unerwarteter Form");
			foreach ($m[1] as $arg) {
				$found[$file][] = trim($arg);
			}
		}
		foreach ($found as $file => $args) {
			$allowed = self::ALLOW[$file] ?? [];
			sort($args);
			sort($allowed);
			self::assertSame($allowed, $args, "$file: print_unescaped außerhalb der Allowlist");
		}
		self::assertSame(1, count($found['index.php'] ?? []), 'templates/index.php: genau ein print_unescaped (JSON-Inseln)');
	}

	/** @return array<string, array{string}> Templates mit JSON-Inseln */
	public static function islandTemplates(): array {
		return ['index.php' => ['index.php'], 'revisions.php' => ['revisions.php'], 'attachments.php' => ['attachments.php'], 'excalidraw_editor.php' => ['excalidraw_editor.php'], 'export_print.php' => ['export_print.php'],
			'trash.php' => ['trash.php'], 'list_tags.php' => ['list_tags.php'], 'list_folders.php' => ['list_folders.php'], 'attachments_list.php' => ['attachments_list.php'],
			'notes_manager.php' => ['notes_manager.php'], 'settings.php' => ['settings.php']];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('islandTemplates')]
	public function testJsonIslandFlags(string $file): void {
		$all = self::templates();
		self::assertArrayHasKey($file, $all, "templates/$file fehlt");
		$src = $all[$file];
		// jede ungeschützte JSON-Ausgabe nutzt $jsonFlags (json_encode innerhalb der Inseldaten bzw. in p() ist ohnehin geschützt)
		self::assertSame(substr_count($src, 'print_unescaped(json_encode('), substr_count($src, 'print_unescaped(json_encode($islandData, $jsonFlags))'), "$file: print_unescaped(json_encode) ohne \$jsonFlags");
		self::assertMatchesRegularExpression('/\$jsonFlags\s*=\s*([^;]+);/', $src);
		preg_match('/\$jsonFlags\s*=\s*([^;]+);/', $src, $m);
		foreach (['JSON_HEX_TAG', 'JSON_HEX_AMP', 'JSON_HEX_APOS', 'JSON_HEX_QUOT'] as $flag) {
			self::assertStringContainsString($flag, $m[1], "JSON-Inseln ohne $flag");
		}
		self::assertStringNotContainsString('JSON_UNESCAPED_SLASHES', $m[1], 'JSON_UNESCAPED_SLASHES würde </script> zulassen');
		// jede JSON-Insel als type="application/json"
		preg_match_all('/<script\b([^>]*)>/i', $src, $s);
		foreach ($s[1] as $attrs) {
			self::assertMatchesRegularExpression('/type="application\/json"/', $attrs, 'Skript-Tag ohne type="application/json"');
		}
	}

	public function testNoRawOutputOrInlineCode(): void {
		foreach (self::templates() as $file => $src) {
			self::assertStringNotContainsString('<?=', $src, "$file: Kurz-Echo ohne Escaping");
			// Zeichenketten in PHP-Blöcken ausblenden (M4c: Schlüssel 'common.print', Aktion 'print-note')
			$code = preg_replace_callback('/<\?php.*?(\?>|$)/s', static fn (array $m): string => (string)preg_replace('/\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"/s', "''", $m[0]), $src);
			self::assertDoesNotMatchRegularExpression('/<\?php[^?]*\b(echo|print)\b(?!_unescaped)/', (string)$code, "$file: echo/print ohne Escaping");
			self::assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=\s*["\']/i', $src, "$file: Inline-Handler on…=");
			self::assertStringNotContainsStringIgnoringCase('javascript:', $src, "$file: javascript:-URL");
			preg_match_all('/<script\b([^>]*)>/i', $src, $s);
			foreach ($s[1] as $attrs) {
				self::assertMatchesRegularExpression('/type="application\/json"/', $attrs, "$file: ausführbares Inline-Skript");
			}
			// data-action nur mit festen Werten (Literal oder Menü-Hilfe p($action) mit Literal-Aufrufen)
			preg_match_all('/data-action="<\?php\s+p\((.*?)\);\s*\?>"/', $src, $d);
			foreach ($d[1] as $expr) {
				self::assertSame('$action', trim($expr), "$file: data-action aus Ausdruck $expr");
			}
			preg_match_all('/\$menuItem\(\s*\$\w+\s*,\s*([^,]+),/', $src, $mi);
			foreach ($mi[1] as $a) {
				self::assertMatchesRegularExpression("/^'[a-z-]+'$/", trim($a), "$file: Menü-data-action nicht literal: $a");
			}
			// style nur mit p() serverseitig geprüfter Werte (SPEC §4.6 Ausnahme)
			preg_match_all('/style="([^"]*<\?php.*?)"/', $src, $st);
			foreach ($st[1] as $style) {
				self::assertMatchesRegularExpression('/^[^<]*(<\?php\s+p\([^;]+\);\s*\?>[^<]*)+$/', $style, "$file: style ohne p(): $style");
			}
		}
	}
}
