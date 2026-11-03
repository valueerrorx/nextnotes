<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-FileCopyrightText: Timothé Poznanski (Poznote, MIT)
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service;

// search_text = Titel + Klartext, klein, ohne Akzente (Port von search_clean_entry/poznoteFoldAccents)
class SearchTextNormalizer {
	private const ACCENTS = [
		'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a', 'ā' => 'a',
		'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e', 'ė' => 'e', 'ę' => 'e',
		'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ī' => 'i', 'į' => 'i',
		'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ø' => 'o', 'ō' => 'o',
		'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ū' => 'u', 'ų' => 'u',
		'ý' => 'y', 'ÿ' => 'y',
		'ñ' => 'n', 'ń' => 'n',
		'ç' => 'c', 'ć' => 'c', 'č' => 'c',
		'ş' => 's', 'š' => 's', 'ś' => 's',
		'ž' => 'z', 'ź' => 'z', 'ż' => 'z',
		'ł' => 'l',
		'æ' => 'ae', 'œ' => 'oe',
	];

	public function __construct(
		private InputValidator $validator,
	) {
	}

	public function fold(string $text): string {
		return strtr(mb_strtolower($text, 'UTF-8'), self::ACCENTS);
	}

	// Kleinschreibung kann Byte vermehren (U+0130 2 → 3): auf speicherbare Länge kürzen statt 500 (B37)
	public function build(?string $heading, ?string $content, string $type): string {
		return $this->validator->fitStorable($this->fold(trim(($heading ?? '') . ' ' . $this->plainText($content ?? '', $type))));
	}

	public function plainText(string $content, string $type): string {
		if ($content === '') {
			return '';
		}
		if ($type === 'markdown') {
			$content = $this->markdownVisibleText($content);
		} else {
			$content = preg_replace('/class="[^"]*language-([^"\s>]+)[^"]*"/i', '', $content) ?? $content;
			$content = preg_replace('/data-language="[^"]*"/i', '', $content) ?? $content;
		}
		$content = preg_replace('/<div\b(?=[^>]*?\bclass="excalidraw-container")[^>]*+>(?:[^<]++|<(?!\/div>))*+<\/div>/', '[Excalidraw diagram]', $content) ?? $content;
		$content = preg_replace('/data:image\/[^;]+;base64,[A-Za-z0-9+\/=]+/', '[image]', $content) ?? $content;
		$text = strip_tags($content);
		return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
	}

	private function markdownVisibleText(string $markdown): string {
		$text = preg_replace('/^```[a-zA-Z0-9+#*-]+\s*$/m', '```', $markdown) ?? $markdown;
		$text = preg_replace('/<!--.*?-->/s', ' ', $text) ?? $text;
		$destination = '\((?:[^()]|\([^()]*\))*\)';
		$text = preg_replace('/!\[[^\]]*\]' . $destination . '/', ' ', $text) ?? $text;
		$text = preg_replace('/\[([^\]]*)\]' . $destination . '/', '$1', $text) ?? $text;
		$text = preg_replace('/^[ \t]{0,3}\[[^\]]+\]:[ \t]*\S.*$/m', ' ', $text) ?? $text;
		return preg_replace('/<((?:https?|mailto):[^>\s]+)>/i', '$1', $text) ?? $text;
	}
}
