<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service\View;

use OCA\NextNotes\Db\Note;
use OCA\NextNotes\Db\NoteMapper;
use OCA\NextNotes\Db\NoteTagMapper;
use OCA\NextNotes\Service\BackgroundService;
use OCA\NextNotes\Service\ContentUrlRewriter;
use OCA\NextNotes\Service\ConversionService;
use OCA\NextNotes\Service\Exception\ApiException;
use OCA\NextNotes\Service\NoteService;
use OCA\NextNotes\Service\Pcre;
use OCA\NextNotes\Service\SettingsService;
use OCA\NextNotes\Service\TagService;

/**
 * Speed Dial (Upstream speed_dial.php, src/lib/speed-dial.php): Notizen mit dem internen Tag speed-dial als Kacheln.
 * Eine Notiz mit höchstens einem Weblink ist eine Kachel, mit mehreren eine Gruppe (Zeilen an den Trennlinien).
 * Abweichung (B62): keine Favicons/Bilder fremder Hosts, nur Anhänge und kleine data:-Bilder, sonst der Anfangsbuchstabe.
 */
class SpeedDialViewService {
	public const TILE_MIN = 80;
	public const TILE_MAX = 260;
	public const TILE_DEFAULT = 140;
	private const MAX_DATA_URI = 65536;

	public function __construct(
		private NoteMapper $notes,
		private NoteTagMapper $tags,
		private SettingsService $settings,
		private BackgroundService $backgrounds,
		private ContentUrlRewriter $urls,
		private PageTranslator $tr,
	) {
	}

	/**
	 * @param array{workspace: \OCA\NextNotes\Db\Workspace, workspaceKey: string, canWrite: bool} $frame
	 * @return array<string, mixed>
	 */
	public function build(string $uid, array $frame): array {
		$wsId = (int)$frame['workspace']->getId();
		$key = $frame['workspaceKey'];
		$ids = array_flip($this->tags->findInWorkspaces([$wsId], TagService::SPEED_DIAL));
		$rows = array_values(array_filter($this->notes->findList([$wsId]), static fn (Note $n) => isset($ids[(int)$n->getId()])));
		usort($rows, static fn (Note $a, Note $b) => NoteService::comparePlaced((int)$a->getSpeedDialOrder(), (int)$b->getSpeedDialOrder(), $a, $b));
		// Inhalte nur bis zum Render-Budget (wie Papierkorb/Tagebuch); darüber Kachel ohne Links
		$bytes = $this->notes->contentBytes(array_map(static fn (Note $n) => (int)$n->getId(), $rows));
		$budget = TrashViewService::RENDER_BUDGET;
		$load = [];
		foreach ($rows as $n) {
			$size = min($bytes[(int)$n->getId()] ?? 0, ConversionService::MAX_RENDER_INPUT);
			if ($n->getType() !== 'tasklist' && $size <= $budget) {
				$budget -= $size;
				$load[] = (int)$n->getId();
			}
		}
		$contents = $this->notes->contentPrefixes($load, ConversionService::MAX_RENDER_INPUT);
		$items = [];
		foreach ($rows as $n) {
			$items[] = $this->item($n, $key, $contents[(int)$n->getId()] ?? '');
		}
		$colors = json_decode($this->settings->get($uid, 'speed_dial_label_colors', '{}'), true);
		$url = $this->backgrounds->urlFor($uid, $frame['workspace'], 'speed_dial');
		$opacity = $this->backgrounds->readOpacity($uid, 'speed_dial_background_opacity_' . $key);
		return [
			'items' => $items,
			'tileSize' => self::tileSize($this->settings->get($uid, 'speed_dial_tile_size', (string)self::TILE_DEFAULT)),
			'labelColors' => self::labelColors(is_array($colors) ? $colors : []),
			'backgroundUrl' => $url,
			'backgroundOpacity' => max(5, min(100, is_numeric($opacity) ? (int)$opacity : 100)),
			'canWrite' => $frame['canWrite'],
		];
	}

	/** @return array<string, mixed> Upstream speedDialBuildItem */
	private function item(Note $n, string $key, string $raw): array {
		$id = (int)$n->getId();
		$type = (string)($n->getType() ?: 'note');
		$heading = trim((string)($n->getHeading() ?? ''));
		if ($heading === '') {
			$heading = $this->tr->t('common.untitled', [], 'Untitled');
		}
		$noteUrl = 'index.php?' . http_build_query(['note' => $id, 'newtab' => 1, 'workspace' => $key]);
		try {
			$rows = match ($type) {
				'markdown' => self::parseMarkdown($raw),
				'tasklist' => [],
				default => self::parseHtml($raw),
			};
		} catch (ApiException) {
			// Regex-Grenze: Kachel ohne Links statt Fehler
			$rows = [];
		}
		$links = array_merge(...($rows ?: [[]]));
		if (count($links) > 1) {
			return [
				'id' => $id,
				'heading' => $heading,
				'noteUrl' => $noteUrl,
				'rows' => array_map(fn (array $row) => array_map(fn (array $l) => $this->linkTile($l), $row), $rows),
			];
		}
		$link = $links[0]['url'] ?? null;
		$image = $links[0]['image'] ?? null;
		if ($image === null && $raw !== '') {
			$image = $this->firstImage($raw, $type);
		}
		return [
			'id' => $id,
			'noteUrl' => $noteUrl,
			'tile' => ['label' => $heading, 'letter' => self::letter($heading), 'link' => $link, 'image' => $image],
		];
	}

	/** @param array{url: string, label: string, image: ?string} $link */
	private function linkTile(array $link): array {
		$label = $link['label'] !== '' ? $link['label'] : (string)parse_url($link['url'], PHP_URL_HOST);
		return ['label' => $label, 'letter' => self::letter($label), 'link' => $link['url'], 'image' => $link['image']];
	}

	private static function letter(string $text): string {
		return mb_strtoupper(mb_substr($text, 0, 1));
	}

	// Erstes Bild der Notiz (Upstream buildNoteCardPreview), gleiche Regel wie die Link-Bilder
	private function firstImage(string $raw, string $type): ?string {
		$found = $type === 'markdown'
			? Pcre::match('/!\[[^\]]*+\]\(\s*([^)\s]++)/', $raw, $m)
			: Pcre::match('/<img\b[^>]*?\bsrc=["\']([^"\']++)["\']/i', $raw, $m);
		return $found ? $this->image(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')) : null;
	}

	// Nur Anhänge der App (Leserecht prüft der Abruf) und kleine data:-Bilder; fremde Hosts nicht (CSP, Datenschutz, B62)
	private function image(?string $src): ?string {
		if ($src === null || $src === '') {
			return null;
		}
		if (Pcre::match('#^/api/v1/notes/\d+/attachments/\d+$#', $src)) {
			return $this->urls->toClient($src);
		}
		return strlen($src) < self::MAX_DATA_URI && Pcre::match('#^data:image/(?:png|jpe?g|gif|webp);base64,[A-Za-z0-9+/=]+$#i', $src) ? $src : null;
	}

	private static function isWebUrl(string $url): bool {
		return Pcre::match('#^https?://[^\s]+$#i', $url);
	}

	/** @return array{url: string, label: string, image: ?string} */
	private function link(string $url, string $label, ?string $image = null): array {
		return ['url' => $url, 'label' => trim($label), 'image' => $this->image($image)];
	}

	/**
	 * Upstream poznoteSpeedDialParseMarkdown: Weblinks in Zeilen, getrennt an Trennlinien
	 * @return list<list<array{url: string, label: string, image: ?string}>>
	 */
	private function parseMarkdown(string $raw): array {
		$text = Pcre::replace('/\A---\R.*?\R---\R/s', '', str_replace("\r\n", "\n", $raw));
		$text = Pcre::replace('/^ {0,3}(```|~~~).*?^ {0,3}\1[^\n]*$/ms', '', $text);
		$text = Pcre::replace('/`[^`\n]*`/', '', $text);
		$text = Pcre::replace('/<(?!https?:\/\/)[a-z\/!][^>]*>/i', '', $text);
		$target = '\(\s*<?((?:[^()\s<>]|\([^()\s]*\))+)>?(?:\s+"([^"]*)")?\s*\)';
		$pattern = '/\[\s*!\[([^\]]*)\]' . $target . '\s*\]' . $target
			. '|!\[[^\]]*\]\((?:[^()]|\([^()]*\))*\)'
			. '|\[([^\]]*)\]' . $target
			. '|^(?: {0,3})(?:-{3,}|\*{3,}|_{3,})[ \t]*$'
			. '|https?:\/\/[^\s<>"\'\]]+/mi';
		if (Pcre::matchAll($pattern, $text, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL) === 0) {
			return [];
		}
		$rows = [[]];
		foreach ($matches as $m) {
			if (($m[4] ?? null) !== null) {
				if (!self::isWebUrl($m[4])) {
					continue;
				}
				$label = $m[5] ?? $m[3] ?? '';
				$rows[count($rows) - 1][] = $this->link($m[4], $label !== '' ? $label : (string)$m[1], $m[2]);
			} elseif (($m[7] ?? null) !== null) {
				if (!self::isWebUrl($m[7])) {
					continue;
				}
				$rows[count($rows) - 1][] = $this->link($m[7], $m[8] ?? (string)$m[6]);
			} elseif (stripos($m[0], 'http') === 0) {
				$rows[count($rows) - 1][] = $this->link(rtrim($m[0], '.,;:!?)…'), '');
			} elseif ($m[0][0] !== '!') {
				$rows[] = [];
			}
		}
		return array_filter($rows) ? $rows : [];
	}

	/**
	 * Upstream poznoteSpeedDialParseHtml: <a href> und Adressen im Text, Zeilen an <hr>
	 * @return list<list<array{url: string, label: string, image: ?string}>>
	 */
	private function parseHtml(string $raw): array {
		$pattern = '/<a\b([^>]*)>(.*?)<\/a>'
			. '|<(?:pre|code|script|style)\b.*?<\/(?:pre|code|script|style)>'
			. '|<[^>]+>'
			. '|https?:\/\/[^\s<>"\']+/is';
		if (Pcre::matchAll($pattern, $raw, $matches, PREG_SET_ORDER) === 0) {
			return [];
		}
		$attribute = static function (string $attributes, string $name): string {
			return Pcre::match('/\b' . $name . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $attributes, $m)
				? html_entity_decode($m[1] !== '' ? $m[1] : ($m[2] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')
				: '';
		};
		$rows = [[]];
		foreach ($matches as $m) {
			if (!isset($m[2])) {
				if (stripos($m[0], 'http') === 0) {
					$url = Pcre::split('/[\s\x{00A0}]/u', html_entity_decode($m[0], ENT_QUOTES | ENT_HTML5, 'UTF-8'))[0];
					$rows[count($rows) - 1][] = $this->link(rtrim($url, '.,;:!?)…'), '');
				} elseif (Pcre::match('/^<hr\b/i', $m[0])) {
					$rows[] = [];
				}
				continue;
			}
			$url = $attribute($m[1], 'href');
			if (!self::isWebUrl($url)) {
				continue;
			}
			$image = Pcre::match('/<img\b([^>]*)>/i', $m[2], $im) ? $attribute($im[1], 'src') : '';
			$label = $attribute($m[1], 'title');
			if ($label === '') {
				$label = html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
			}
			$rows[count($rows) - 1][] = $this->link($url, $label, $image !== '' ? $image : null);
		}
		return array_filter($rows) ? $rows : [];
	}

	/**
	 * Upstream poznoteSpeedDialNormalizeLabelColors: Notiz-ID → #rrggbb
	 * @return array<int|string, string>
	 */
	private static function labelColors(array $value): array {
		$out = [];
		foreach ($value as $noteId => $color) {
			if (ctype_digit((string)$noteId) && is_string($color) && Pcre::match('/^#[0-9a-fA-F]{6}$/', $color)) {
				$out[$noteId] = strtolower($color);
			}
		}
		return $out;
	}

	// Upstream poznoteSpeedDialTileSize
	private static function tileSize(string $size): int {
		if (!is_numeric($size)) {
			return self::TILE_DEFAULT;
		}
		return (int)(round(max(self::TILE_MIN, min(self::TILE_MAX, (float)$size)) / 10) * 10);
	}
}
