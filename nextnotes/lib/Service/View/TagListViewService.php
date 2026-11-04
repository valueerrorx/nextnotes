<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service\View;

use OCA\NextNotes\Service\NotePalette;
use OCA\NextNotes\Db\NoteTagMapper;
use OCA\NextNotes\Service\SettingsService;
use OCA\NextNotes\Service\TagService;

/** Daten der Tag-Seite (Upstream list_tags.php): Tags des Workspace mit Anzahl und Farbe */
class TagListViewService {
	public function __construct(
		private NoteTagMapper $tags,
		private SettingsService $settings,
		private PageTranslator $tr,
		private NotePalette $palette,
	) {
	}

	/**
	 * @param array{wsId: int} $frame
	 * @return array{tags: list<array{name: string, count: int, color: string}>, colors: list<string>, hasUncolored: bool, globals: array<string, mixed>}
	 */
	public function build(string $uid, array $frame): array {
		$counts = $this->tags->countsInWorkspace($frame['wsId']);
		uksort($counts, 'strnatcasecmp');
		$map = json_decode($this->settings->get($uid, 'tag_colors', ''), true);
		$map = is_array($map) ? $map : [];
		$out = [];
		$used = [];
		$uncolored = false;
		foreach ($counts as $tag => $count) {
			$tag = (string)$tag;
			// interner Speed-Dial-Tag nicht in der Liste (B51)
			if (trim($tag) === '' || TagService::isInternal($tag)) {
				continue;
			}
			$stored = $map[mb_strtolower(trim($tag))] ?? null;
			$hex = strtolower($this->palette->hex(is_string($stored) ? $stored : null));
			if ($hex === '') {
				$uncolored = true;
			} else {
				$used[$hex] = true;
			}
			$out[] = ['name' => $tag, 'count' => $count, 'color' => $hex];
		}
		$colors = array_keys($used);
		sort($colors);
		$palette = $this->palette->entries($uid);
		return [
			'tags' => $out,
			'colors' => $colors,
			'hasUncolored' => $uncolored,
			'globals' => ['TAG_COLORS' => (object)$map, 'NOTE_COLOR_PALETTE' => $palette],
		];
	}
}
