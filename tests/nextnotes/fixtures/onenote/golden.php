<?php
// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// M9c: gemeinsame Golden-Logik für gen-expected.php (Host) und ConvertGoldenTest (Container)
declare(strict_types=1);

namespace OCA\NextNotes\Tests\OneNote;

use OCA\NextNotes\OneNote\Convert\ConvertResource;
use OCA\NextNotes\OneNote\Convert\PageConverter;
use OCA\NextNotes\Service\HtmlSanitizer;
use OCP\IL10N;

// Englische Texte ohne Übersetzung, damit die Erwartung sprachunabhängig ist
final class GoldenL10N implements IL10N {
	public function t(string $text, $parameters = []): string {
		return vsprintf($text, (array)$parameters);
	}

	public function n(string $text_singular, string $text_plural, int $count, array $parameters = []): string {
		return vsprintf($count === 1 ? $text_singular : $text_plural, $parameters);
	}

	public function l(string $type, $data, array $options = []) {
		return false;
	}

	public function getLanguageCode(): string {
		return 'en';
	}

	public function getLocaleCode(): string {
		return 'en';
	}
}

final class Golden {
	/** @return array<string, array{html: string, resources: list<array<string, int|string>>, skipped: int, ink: int, tooLarge: bool}> */
	public static function all(PageConverter $converter, IL10N $l, string $dir): array {
		$out = [];
		$files = glob($dir . '/*.html') ?: [];
		sort($files);
		foreach ($files as $file) {
			$r = $converter->convert((string)file_get_contents($file), $l);
			$out[basename($file, '.html')] = [
				'html' => self::canonical($r->html),
				'resources' => array_map(static fn (ConvertResource $x): array => ['index' => $x->index, 'id' => $x->id, 'kind' => $x->kind, 'name' => $x->name, 'type' => $x->type], $r->resources),
				'skipped' => $r->skippedResources,
				'ink' => $r->ink,
				'tooLarge' => $r->tooLarge,
			];
		}
		return $out;
	}

	// Eigene Serialisierung: saveHTML quotet/maskiert je libxml-Version anders (2.9: alt="x&quot;", 2.15: alt='x"')
	public static function canonical(string $html): string {
		if ($html === '') {
			return '';
		}
		$dom = new \DOMDocument();
		HtmlSanitizer::loadHtmlQuiet($dom, '<html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body>' . $html,
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_PARSEHUGE);
		$body = $dom->getElementsByTagName('body')->item(0);
		return $body === null ? '' : self::children($body);
	}

	private static function children(\DOMNode $node): string {
		$out = '';
		foreach ($node->childNodes as $c) {
			if ($c instanceof \DOMText) {
				$out .= str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $c->data);
			} elseif ($c instanceof \DOMElement) {
				$tag = strtolower($c->tagName);
				$out .= '<' . $tag;
				foreach ($c->attributes as $a) {
					$out .= ' ' . $a->name . '="' . str_replace(['&', '"', '<', '>'], ['&amp;', '&quot;', '&lt;', '&gt;'], $a->value) . '"';
				}
				$out .= '>';
				if (!in_array($tag, ['br', 'hr', 'img', 'input', 'wbr', 'col'], true)) {
					$out .= self::children($c) . '</' . $tag . '>';
				}
			}
		}
		return $out;
	}
}
