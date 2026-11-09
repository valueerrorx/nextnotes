<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\OneNote\Convert;

use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use OCA\NextNotes\Service\Exception\TooLargeException;
use OCA\NextNotes\Service\HtmlSanitizer;
use OCA\NextNotes\Service\InputValidator;
use OCA\NextNotes\Service\Pcre;
use OCP\IL10N;

// OneNote-Seiten-HTML (Graph) → Next-Notes-Richtext, rein: kein Netz, keine DB (Plan M9 §5, M9c)
final class PageConverter {
	// Grenze je Seite (Plan M9 §6), ab der 101. Ressource Platzhalter
	public const MAX_RESOURCES = 100;
	// Platzhalter für M9d: img[src] bzw. a[href] = PLACEHOLDER . Index
	public const PLACEHOLDER = '#nn-onenote-res-';
	private const MAX_LEVEL = 5;
	// Elementtiefe unter body; begrenzt die Rekursion des Konverters (span-Verstärkung bleibt unter HtmlSanitizer::MAX_DEPTH)
	private const MAX_DEPTH = 240;
	private const MAX_TITLE = 1000;

	public function __construct(
		private HtmlSanitizer $sanitizer,
	) {
	}

	public function convert(string $html, IL10N $l): ConvertResult {
		if (strlen($html) > InputValidator::MAX_CONTENT_BYTES
			|| substr_count($html, '<') - substr_count($html, '</') > HtmlSanitizer::MAX_TAGS) {
			return $this->tooLarge($l);
		}
		$dom = new DOMDocument();
		// Zeichensatz fest UTF-8 (Graph liefert UTF-8); PARSEHUGE und Fehlerprüfung: nie still gekürzt (B37)
		try {
			HtmlSanitizer::loadHtmlComplete($dom, '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . $html, LIBXML_HTML_NODEFDTD);
		} catch (TooLargeException) {
			return $this->tooLarge($l);
		}
		$body = $dom->getElementsByTagName('body')->item(0);
		if (!$body instanceof DOMElement) {
			return new ConvertResult('', [], 0, 0, false);
		}
		if (HtmlSanitizer::elementDepth($body) >= self::MAX_DEPTH) {
			return $this->tooLarge($l);
		}
		[$root, $resources, $skipped, $ink] = (new PageWalker($l))->walk($body);
		$out = '';
		for ($c = $root->firstChild; $c !== null; $c = $c->nextSibling) {
			$out .= $root->ownerDocument->saveHTML($c);
		}
		try {
			$clean = $this->sanitizer->sanitizeHtml($out);
		} catch (TooLargeException) {
			return $this->tooLarge($l);
		}
		if (strlen($clean) > InputValidator::MAX_CONTENT_BYTES) {
			return $this->tooLarge($l);
		}
		return new ConvertResult($clean, $resources, $skipped, $ink, false);
	}

	/**
	 * Platzhalter ersetzen (M9d): Index → kanonische Anhang-URL, fehlend/null → Text-Platzhalter
	 * @param array<int, ?string> $urls
	 * @throws TooLargeException
	 */
	public function applyResources(string $html, array $urls, IL10N $l): string {
		if ($html === '' || !str_contains($html, self::PLACEHOLDER)) {
			return $html;
		}
		$dom = new DOMDocument();
		$dom->encoding = 'UTF-8';
		HtmlSanitizer::loadHtmlComplete($dom, '<html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body>' . $html,
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
		$body = $dom->getElementsByTagName('body')->item(0);
		if (!$body instanceof DOMElement) {
			return '';
		}
		foreach ([['img', 'src'], ['a', 'href']] as [$tag, $attr]) {
			foreach (iterator_to_array($body->getElementsByTagName($tag)) as $el) {
				$value = $el->getAttribute($attr);
				if (!str_starts_with($value, self::PLACEHOLDER)) {
					continue;
				}
				$index = substr($value, strlen(self::PLACEHOLDER));
				$url = ctype_digit($index) ? ($urls[(int)$index] ?? null) : null;
				if ($url !== null && Pcre::match('#^/api/v1/notes/\d{1,19}/attachments/\d{1,19}\z#', $url)) {
					$el->setAttribute($attr, $url);
					continue;
				}
				$text = $tag === 'img' ? $l->t('[Image not imported]') : $l->t('[File not imported: %s]', [trim($el->textContent)]);
				$el->parentNode->replaceChild($dom->createTextNode($text), $el);
			}
		}
		$out = '';
		for ($c = $body->firstChild; $c !== null; $c = $c->nextSibling) {
			$out .= $dom->saveHTML($c);
		}
		return $this->sanitizer->sanitizeHtml($out);
	}

	// Notiztitel: Unterseiten mit „› “ je Ebene, leer → „Unbenannte Notiz“ (Plan M9 §5)
	public static function title(?string $title, int $level, IL10N $l): string {
		$title = trim(Pcre::replace('/[\x00-\x1F\x7F]++/u', ' ', (string)$title));
		if ($title === '') {
			$title = $l->t('Untitled note');
		}
		$prefix = str_repeat('› ', max(0, min($level, self::MAX_LEVEL)));
		return mb_substr($prefix . $title, 0, self::MAX_TITLE);
	}

	// Graph-Zeitstempel (ISO 8601, UTC) → Unix; ungültig → null
	public static function timestamp(?string $value): ?int {
		$value = trim((string)$value);
		if ($value === '' || strlen($value) > 40 || !Pcre::match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?(?:Z|[+-]\d{2}:\d{2})$/', $value)) {
			return null;
		}
		// Bruchteile über Mikrosekunden kürzen (DateTime kennt nur 6 Stellen)
		$value = Pcre::replace('/(\.\d{6})\d++/', '$1', $value);
		$date = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.uP', $value) ?: DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sP', $value);
		return $date === false ? null : $date->getTimestamp();
	}

	// Hinweis statt Inhalt für zu große oder zu komplexe Seiten; schon ein Fixpunkt des Sanitizers
	public static function tooLargeHint(IL10N $l): string {
		return '<p>' . htmlspecialchars($l->t('This OneNote page was too large or too complex to import.'), ENT_QUOTES, 'UTF-8') . '</p>';
	}

	private function tooLarge(IL10N $l): ConvertResult {
		return new ConvertResult($this->sanitizer->sanitizeHtml(self::tooLargeHint($l)), [], 0, 0, true);
	}
}
