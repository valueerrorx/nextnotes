<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Scenario;

use OCA\NextNotes\Tests\Support\ApiTestCase;

// M8a Done 2 (B69): Markdown → HTML umgewandelte Notiz mit $x^2$, Editor speichert KaTeX-Ausgabe → gespeichert nur der Vertrag
final class FormulaB69Test extends ApiTestCase {
	private const KATEX = '<span class="katex"><span class="katex-mathml"><math xmlns="http://www.w3.org/1998/Math/MathML"><semantics><mrow><msup><mi>x</mi><mn>2</mn></msup></mrow>'
		. '<annotation encoding="application/x-tex">x^2</annotation></semantics></math></span><span class="katex-html" aria-hidden="true">'
		. '<span class="base"><span class="mord"><span class="mord mathnormal">x</span><span class="msupsub"><span class="vlist-t"><span class="vlist-r">'
		. '<span class="vlist" style="height:0.8141em;"><span style="top:-3.063em;margin-right:0.05em;"><span class="pstrut" style="height:2.7em;"></span>'
		. '<span class="sizing reset-size6 size3 mtight"><span class="mord mtight">2</span></span></span></span></span></span></span></span></span></span></span>';

	private static function content(\OCA\NextNotes\Tests\Support\ApiClient $c, int $id): string {
		return (string)self::ok($c->call('GET', "/notes/$id"), "GET /notes/$id")['note']['content'];
	}

	public function testConvertedNoteSavesContractOnly(): void {
		$a = $this->createUser('b69');
		[$ws] = self::newWorkspace($a, 'B69');
		$id = (int)self::ok($a->call('POST', '/notes', ['heading' => 'Formel', 'type' => 'markdown', 'content' => "Wert \$x^2\$ hier\n\n\$\$\n\\frac{1}{2}\n\$\$\n", 'workspace' => $ws]), 'MD-Notiz')['note']['id'];
		self::ok($a->call('POST', "/notes/$id/convert", ['target' => 'html']), 'umwandeln');
		$stored = self::content($a, $id);
		self::assertStringContainsString('<span class="math-inline" data-math="x^2" contenteditable="false">x^2</span>', $stored, 'Umwandlung: Vertrag');
		self::assertStringContainsString('data-math="\frac{1}{2}" contenteditable="false">\frac{1}{2}</span>', $stored, 'Block: Vertrag');

		// Editor: math-renderer.js füllt die Spans mit KaTeX, Autosave schickt das Editor-HTML
		$editor = str_replace('>x^2</span>', '>' . self::KATEX . '</span>', $stored);
		self::assertStringContainsString('katex-mathml', $editor);
		self::ok($a->call('PATCH', "/notes/$id", ['content' => $editor]), 'Speichern mit KaTeX-Ausgabe');
		$saved = self::content($a, $id);
		self::assertSame($stored, $saved, 'gespeichert = Vertrag, ohne KaTeX');
		foreach (['katex', '<math', 'mathnormal', 'style='] as $bad) {
			self::assertStringNotContainsString($bad, $saved, $bad);
		}
		// Suche/Text: LaTeX genau einmal, kein Doppeltext aus MathML und HTML
		self::assertSame(1, substr_count(strip_tags($saved), 'x^2'));

		// Rückweg nach Markdown: Formeln wieder als $…$
		self::ok($a->call('POST', "/notes/$id/convert", ['target' => 'markdown']), 'zurück nach Markdown');
		$md = self::content($a, $id);
		self::assertStringContainsString('$x^2$', $md);
		self::assertStringContainsString("\$\$\n\\frac{1}{2}\n\$\$", $md);
		self::assertStringNotContainsString('katex', $md);
	}

	public function testConvertHtmlEndpointWithEditorMarkup(): void {
		$a = $this->createUser('b69h');
		$html = '<p>a <span class="math-inline" data-math="x^2" contenteditable="false">' . self::KATEX . '</span> b</p><div class="math-block" data-math="a$b" contenteditable="false">' . self::KATEX . '</div><p>c</p>';
		$md = self::ok($a->call('POST', '/convert-html', ['html' => $html]), 'convert-html')['markdown'];
		self::assertSame("a \$x^2\$ b\n\n\$\$\na\\\$b\n\$\$\n\nc", $md);
		$back = self::ok($a->call('POST', '/convert-markdown', ['markdown' => $md]), 'convert-markdown')['html'];
		self::assertStringContainsString('<span class="math-inline" data-math="x^2" contenteditable="false">x^2</span>', $back);
		self::assertStringContainsString('data-math="a\$b" contenteditable="false">a\$b</span>', $back);
	}
}
