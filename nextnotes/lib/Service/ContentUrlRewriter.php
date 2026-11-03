<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service;

use OCP\IURLGenerator;

// SPEC §10: gespeichert wird die Upstream-Form (/api/v1/…), ausgeliefert mit App-Basis
class ContentUrlRewriter {
	private const PATH_STOP = "\"'()<> \t\n\r\f\v";
	private const DELIMITERS = "\"'(= \t\n\r\f\v";
	private ?string $base = null;

	public function __construct(
		private IURLGenerator $urlGenerator,
	) {
	}

	// App-Basis mit Schrägstrich am Ende, z. B. /index.php/apps/nextnotes/
	public function base(): string {
		if ($this->base === null) {
			$this->base = rtrim($this->urlGenerator->linkToRoute('nextnotes.page.index'), '/') . '/';
		}
		return $this->base;
	}

	public function toClient(string $content): string {
		if ($content === '' || (!str_contains($content, '/api/v1/') && !str_contains($content, '/audio_player.php'))) {
			return $content;
		}
		return preg_replace('#(^|["\'(\s=])/(api/v1/|audio_player\.php)#', '$1' . $this->base() . '$2', $content) ?? $content;
	}

	// Linear und fehlersicher (B37): ein Regex-Fehler wirft, statt URLs still unverändert zu lassen
	public function toServer(string $content): string {
		if ($content === '') {
			return $content;
		}
		if (stripos($content, 'api_attachments.php') !== false) {
			$content = self::canonicalAttachmentUrls($content);
		}
		return str_contains($content, '/apps/nextnotes/') ? self::stripAppPrefix($content) : $content;
	}

	// Alte Anhangs-URLs → /api/v1/…; ein direkt davorstehender Pfad (bis Tokenanfang) fällt weg
	private static function canonicalAttachmentUrls(string $content): string {
		Pcre::matchAll('#api_attachments\.php\?action=download&(?:amp;)?note_id=(\d++)&(?:amp;)?attachment_id=(\d++)#i', $content, $hits, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
		$out = '';
		$pos = 0;
		foreach ($hits as $hit) {
			$at = $hit[0][1];
			$start = $at;
			if ($at > $pos && $content[$at - 1] === '/') {
				$start = self::tokenStart($content, $at - 1, $pos);
			}
			$out .= substr($content, $pos, $start - $pos) . '/api/v1/notes/' . $hit[1][0] . '/attachments/' . $hit[2][0];
			$pos = $at + strlen($hit[0][0]);
		}
		return $out . substr($content, $pos);
	}

	// erste Position des Tokens vor $at (keine Anführungszeichen, Klammern, <>, Leerraum), nicht vor $min
	private static function tokenStart(string $content, int $at, int $min): int {
		$segment = substr($content, $min, $at - $min);
		$start = $min;
		foreach (str_split(self::PATH_STOP) as $stop) {
			$found = strrpos($segment, $stop);
			$start = $found !== false ? max($start, $min + $found + 1) : $start;
		}
		return $start;
	}

	/**
	 * [https://host][/pfad]/apps/nextnotes/(api/v1/|audio_player.php) → /…, Start nach Trenner (["'(\s=]) oder am Anfang.
	 * Ohne Regex über das Token: je Treffer Rückwärtssuche bis zum Ende des vorigen Treffers.
	 */
	private static function stripAppPrefix(string $content): string {
		Pcre::matchAll('#/apps/nextnotes/(?=api/v1/|audio_player\.php)#i', $content, $hits, PREG_OFFSET_CAPTURE);
		$out = '';
		$pos = 0;
		foreach ($hits[0] as [$hit, $at]) {
			if ($at < $pos) {
				continue;
			}
			$from = self::tokenStart($content, $at, $pos);
			$start = null;
			// Kandidaten: Zeichen vor dem Token, danach nur „=“ (übrige Trenner beenden das Token)
			for ($i = $from - 1; $i !== false && $i < $at; $i = strpos($content, '=', $i + 1)) {
				$delimiter = $i < 0 ? $pos === 0 : ($i >= $pos && strpbrk($content[$i], self::DELIMITERS) !== false);
				if ($delimiter && self::prefixOk($content, $i + 1, $at)) {
					$start = $i + 1;
					break;
				}
			}
			if ($start === null) {
				continue;
			}
			$out .= substr($content, $pos, $start - $pos) . '/';
			$pos = $at + strlen($hit);
		}
		return $out . substr($content, $pos);
	}

	// Abschnitt [begin, end): leer, Pfad ab „/“ oder https?://Host[/Pfad] (ohne Kopie des Abschnitts)
	private static function prefixOk(string $content, int $begin, int $end): bool {
		return $begin === $end || $content[$begin] === '/'
			|| (Pcre::match('#\Ghttps?://[^/]#i', $content, $m, 0, $begin) && $begin + strlen($m[0]) <= $end);
	}
}
