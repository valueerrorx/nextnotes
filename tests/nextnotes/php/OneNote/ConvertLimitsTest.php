<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\OneNote;

use OCA\NextNotes\OneNote\Convert\ConvertResource;
use OCA\NextNotes\OneNote\Convert\PageConverter;
use OCA\NextNotes\Service\InputValidator;
use OCP\L10N\IFactory;
use OCP\Server;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/fixtures/onenote/golden.php';

// M9c Done 6, 8, 9: Titel und Zeiten, Grenzen je Seite, Platzhalter für M9d, Übersetzung
final class ConvertLimitsTest extends TestCase {
	private const RES = 'https://graph.microsoft.com/v1.0/me/onenote/resources/';

	private static function img(int $i): string {
		return '<img src="' . self::RES . '0-r!1-X!' . $i . '/$value">';
	}

	// Done 6: Unterseiten-Präfix je Ebene, leerer Titel, Graph-Zeiten (UTC) → Unix
	public function testTitleAndTimestamp(): void {
		$l = new GoldenL10N();
		self::assertSame('Seite', PageConverter::title('  Seite ', 0, $l));
		self::assertSame('› Unterseite', PageConverter::title('Unterseite', 1, $l));
		self::assertSame('› › Unter seite', PageConverter::title("Unter\nseite", 2, $l));
		self::assertSame('Untitled note', PageConverter::title(null, 0, $l));
		self::assertSame('› Untitled note', PageConverter::title(" \t", 1, $l));
		self::assertSame(1000, mb_strlen(PageConverter::title(str_repeat('ä', 2000), 1, $l)));
		self::assertSame(1476873420, PageConverter::timestamp('2016-10-19T10:37:00Z'));
		self::assertSame(1476873420, PageConverter::timestamp('2016-10-19T10:37:00.1234567Z'));
		self::assertSame(1476873420, PageConverter::timestamp('2016-10-19T12:37:00+02:00'));
		self::assertNull(PageConverter::timestamp('gestern'));
		self::assertNull(PageConverter::timestamp(null));
	}

	// Done 8: Seite über 16 MiB (Eingabe oder Ausgabe) → Hinweisnotiz
	public function testTooLargePage(): void {
		$c = ConvertGoldenTest::converter();
		$hint = '<p>This OneNote page was too large or too complex to import.</p>';
		$r = $c->convert(str_repeat('a', InputValidator::MAX_CONTENT_BYTES + 1), new GoldenL10N());
		self::assertTrue($r->tooLarge);
		self::assertSame($hint, $r->html);
		self::assertSame([], $r->resources);
		// Eingabe knapp unter der Grenze, Ausgabe durch Entities darüber
		$r = $c->convert('<body><div><p>' . str_repeat('x', InputValidator::MAX_CONTENT_BYTES - 60) . str_repeat('&amp;', 10) . '</p></div></body>', new GoldenL10N());
		self::assertTrue($r->tooLarge);
		$r = $c->convert('<body><div><p>klein</p></div></body>', new GoldenL10N());
		self::assertFalse($r->tooLarge);
		self::assertSame('<p>klein</p>', $r->html);
		self::assertSame('', $c->convert('', new GoldenL10N())->html);
	}

	// Done 9: Ressourcen in Dokumentreihenfolge, gedeckelt auf 100; ab der 101. Platzhalter; doppelte IDs einmal
	public function testResourceCap(): void {
		$html = '<body><div><p>Start</p>';
		for ($i = 0; $i < 101; $i++) {
			$html .= self::img($i);
		}
		$html .= self::img(5) . '<object data="' . self::RES . '0-f!1-Y!1/$value" data-attachment="spät.pdf" type="application/pdf"></object></div></body>';
		$r = ConvertGoldenTest::converter()->convert($html, new GoldenL10N());
		self::assertCount(PageConverter::MAX_RESOURCES, $r->resources);
		self::assertSame(range(0, 99), array_map(static fn (ConvertResource $x): int => $x->index, $r->resources));
		self::assertSame('0-r!1-X!0', $r->resources[0]->id);
		self::assertSame('0-r!1-X!99', $r->resources[99]->id);
		self::assertSame(ConvertResource::KIND_IMAGE, $r->resources[99]->kind);
		self::assertSame(2, $r->skippedResources);
		self::assertSame(1, substr_count($r->html, '[Image not imported]'));
		self::assertStringContainsString('[File not imported: spät.pdf]', $r->html);
		// Wiederholung derselben ID nutzt den vorhandenen Index, auch nach der Grenze
		self::assertSame(2, substr_count($r->html, 'src="' . PageConverter::PLACEHOLDER . '5"'));
		self::assertStringNotContainsString(PageConverter::PLACEHOLDER . '100"', $r->html);
	}

	// Ressourcen-URLs: nur die geprüfte ID von graph.microsoft.com zählt (GraphClient::resourceIdFromUrl, Plan M9 §6)
	public function testResourceUrls(): void {
		$ok = [
			self::RES . '0-a!1-B!1/$value' => '0-a!1-B!1',
			'https://GRAPH.microsoft.com/v1.0/groups/0f2c4e1a-1111-2222-3333-444455556666/onenote/resources/1-x_y.z,w/$value' => '1-x_y.z,w',
		];
		$bad = [
			'https://www.onenote.com/api/v1.0/me/notes/resources/0-a!1-B!1/$value',
			'https://graph.microsoft.com.evil.example/v1.0/me/onenote/resources/x/$value',
			'https://graph.microsoft.com/v1.0/me/onenote/resources/../x/$value',
			'https://graph.microsoft.com:8443/v1.0/me/onenote/resources/x/$value',
			'https://user@graph.microsoft.com/v1.0/me/onenote/resources/x/$value',
			'http://graph.microsoft.com/v1.0/me/onenote/resources/x/$value',
			'https://graph.microsoft.com/v1.0/me/onenote/resources/a%2Fb/$value',
			'https://graph.microsoft.com/v1.0/me/onenote/resources/' . str_repeat('a', 513) . '/$value',
			'name:part',
			'',
		];
		foreach ($ok as $url => $id) {
			$r = ConvertGoldenTest::converter()->convert('<body><div><img src="' . htmlspecialchars($url) . '"></div></body>', new GoldenL10N());
			self::assertSame($id, $r->resources[0]->id ?? null, $url);
		}
		foreach ($bad as $url) {
			$r = ConvertGoldenTest::converter()->convert('<body><div><img src="' . htmlspecialchars($url) . '"></div></body>', new GoldenL10N());
			self::assertSame([], $r->resources, $url);
			self::assertSame('<p>[Image not imported]</p>', $r->html, $url);
		}
	}

	// Tiefe Verschachtelung und riesige Attribute: kein Absturz, Hinweis oder vollständiger Inhalt, nie still gekürzt (B37)
	public function testNestingAndHugeAttributes(): void {
		$c = ConvertGoldenTest::converter();
		$r = $c->convert('<body><div>' . str_repeat('<span><b>', 100) . 'tief' . str_repeat('</b></span>', 100) . '</div></body>', new GoldenL10N());
		self::assertFalse($r->tooLarge);
		self::assertStringContainsString('tief', $r->html);
		// ab 240 Ebenen Hinweis (eigene Grenze; libxml 2.15 bricht zusätzlich ab 2048 ab, 2.9 nie)
		foreach ([150, 5000, 200000] as $depth) {
			$r = $c->convert('<body><div>' . str_repeat('<span><b>', $depth) . 'x</div></body>', new GoldenL10N());
			self::assertTrue($r->tooLarge, (string)$depth);
		}
		// Block-Verschachtelung (libxml-abhängig geschlossen oder abgebrochen): kein Absturz, Inhalt oder Hinweis
		$r = $c->convert('<body><div>' . str_repeat('<div><ul><li>', 5000) . 'Inhalt</div></body>', new GoldenL10N());
		self::assertTrue($r->tooLarge || str_contains($r->html, 'Inhalt'));
		$big = str_repeat('A', 2 * 1024 * 1024);
		$r = $c->convert('<body><div><p title="' . $big . '" style="color:' . $big . '">t</p><img alt="' . $big . '" width="' . $big . '" src="' . self::RES . 'a/$value"></div></body>', new GoldenL10N());
		self::assertFalse($r->tooLarge);
		self::assertSame('<p>t</p><p><img src="' . PageConverter::PLACEHOLDER . '0" alt="' . str_repeat('A', 1000) . '"></p>', $r->html);
		// Attribute über 10 MB (libxml 2.15 kürzte ohne PARSEHUGE ab hier): Element und Folgeinhalt bleiben
		$huge = str_repeat('A', 11 * 1024 * 1024);
		$r = $c->convert('<body><div><p title="' . $huge . '">t</p><p>AFTER</p></div></body>', new GoldenL10N());
		self::assertFalse($r->tooLarge);
		self::assertSame('<p>t</p><p>AFTER</p>', $r->html);
		$r = $c->convert('<body><div><p><img src="' . self::RES . 'a/$value" alt="' . $huge . '">x</p><p>AFTER</p></div></body>', new GoldenL10N());
		self::assertFalse($r->tooLarge);
		self::assertSame('<p><img src="' . PageConverter::PLACEHOLDER . '0" alt="' . str_repeat('A', 1000) . '">x</p><p>AFTER</p>', $r->html);
		self::assertCount(1, $r->resources);
	}

	// Textknoten über 10 MB (libxml 2.9 kürzte ohne PARSEHUGE ab hier): vollständig samt Folgeinhalt
	public function testHugeTextNode(): void {
		$text = str_repeat('a b ', 3 * 1024 * 1024);
		$r = ConvertGoldenTest::converter()->convert('<body><div><p>' . $text . '</p><p>AFTER</p></div></body>', new GoldenL10N());
		self::assertFalse($r->tooLarge);
		self::assertSame('<p>' . $text . '</p><p>AFTER</p>', $r->html);
	}

	// Platzhalter → kanonische Anhang-URL; fehlend, null oder fremde Form → Text
	public function testApplyResources(): void {
		$c = ConvertGoldenTest::converter();
		$r = $c->convert('<body><div>' . self::img(0) . self::img(1) . '<p>Datei: <object data="' . self::RES . 'f/$value" data-attachment="a.pdf"></object></p>' . self::img(2) . '<p><a href="https://example.org/#nn-onenote-res-0">fremd</a></p></div></body>', new GoldenL10N());
		// Indizes: Bild 0, Bild 1, Datei 2, Bild 3
		$out = $c->applyResources($r->html, [0 => '/api/v1/notes/3/attachments/9', 1 => null, 2 => 'https://evil.example/x.pdf', 3 => '/api/v1/notes/3/attachments/10'], new GoldenL10N());
		self::assertSame('<p><img src="/api/v1/notes/3/attachments/9"></p><p>[Image not imported]</p><p>Datei: [File not imported: a.pdf]</p><p><img src="/api/v1/notes/3/attachments/10"></p><p><a href="https://example.org/#nn-onenote-res-0">fremd</a></p>', $out);
		self::assertSame('<p>ohne</p>', $c->applyResources('<p>ohne</p>', [], new GoldenL10N()));
	}

	// Platzhalter und Hinweise in der Sprache des Benutzers
	public function testGermanTexts(): void {
		$l = Server::get(IFactory::class)->get('nextnotes', 'de');
		$c = ConvertGoldenTest::converter();
		$r = $c->convert('<body><div><img src="https://evil.example/x.png"><object data="x" data-attachment="b.pdf"></object><iframe data-original-src="https://v.example/1"></iframe><ink></ink></div></body>', $l);
		self::assertSame('<p>[Bild nicht übernommen]</p><p>[Datei nicht übernommen: b.pdf]</p><p><a href="https://v.example/1">Video: https://v.example/1</a></p><p>[Freihand nicht übernommen]</p>', $r->html);
		self::assertSame('<p>Diese OneNote-Seite war zu groß oder zu komplex für den Import.</p>', $c->convert(str_repeat('a', InputValidator::MAX_CONTENT_BYTES + 1), $l)->html);
		self::assertSame('› Unbenannte Notiz', PageConverter::title('', 1, $l));
	}
}
