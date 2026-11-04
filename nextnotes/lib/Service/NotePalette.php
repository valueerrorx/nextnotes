<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service;

use OCA\NextNotes\Service\View\PageTranslator;
use OCP\IUserSession;

/** Farbpalette je Benutzer (Upstream src/lib/note-colors.php): Einstellung note_color_palette, sonst Standard */
class NotePalette {
	public const SETTING = 'note_color_palette';
	private const MAX_ENTRIES = 24;
	private const ENGLISH = ['blue' => 'Blue', 'green' => 'Green', 'yellow' => 'Yellow', 'orange' => 'Orange',
		'red' => 'Red', 'purple' => 'Purple', 'pink' => 'Pink', 'gray' => 'Gray'];

	/** @var array<string, list<array{id: string, name: string, hex: string}>> */
	private array $entries = [];

	public function __construct(
		private IUserSession $userSession,
		private SettingsService $settings,
		private PageTranslator $tr,
	) {
	}

	/**
	 * Upstream sanitizeNoteColorPalette: gültige Einträge, eindeutige IDs, höchstens 24
	 * @return list<array{id: string, name: string, hex: string}>
	 */
	public static function sanitize(mixed $palette): array {
		if (is_string($palette)) {
			$palette = json_decode($palette, true);
		}
		if (!is_array($palette)) {
			return [];
		}
		$clean = [];
		foreach ($palette as $entry) {
			if (!is_array($entry)) {
				continue;
			}
			$hex = self::normalizeHex($entry['hex'] ?? '');
			$id = (string)preg_replace('/[^a-z0-9_-]/', '', strtolower(trim(is_scalar($entry['id'] ?? null) ? (string)$entry['id'] : '')));
			$id = substr($id, 0, 32);
			if ($hex === '' || $id === '' || isset($clean[$id])) {
				continue;
			}
			$name = trim(is_scalar($entry['name'] ?? null) ? (string)$entry['name'] : '');
			$clean[$id] = ['id' => $id, 'name' => mb_substr($name === '' ? ucfirst($id) : $name, 0, 40), 'hex' => $hex];
			if (count($clean) >= self::MAX_ENTRIES) {
				break;
			}
		}
		return array_values($clean);
	}

	// #rgb/#rrggbb → #rrggbb (klein), sonst ''
	public static function normalizeHex(mixed $value): string {
		$value = strtolower(trim(is_scalar($value) ? (string)$value : ''));
		if (!preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $value)) {
			return '';
		}
		return strlen($value) === 4 ? '#' . $value[1] . $value[1] . $value[2] . $value[2] . $value[3] . $value[3] : $value;
	}

	/** @return list<array{id: string, name: string, hex: string}> Standardpalette mit übersetzten Namen */
	public function defaults(): array {
		$out = [];
		foreach (InputValidator::PALETTE as $id => $hex) {
			$out[] = ['id' => $id, 'name' => $this->tr->t('note_color.names.' . $id, [], self::ENGLISH[$id] ?? ucfirst($id)), 'hex' => $hex];
		}
		return $out;
	}

	/**
	 * Wirksame Palette (Upstream getNoteColorPalette); nie umbenannte Standardnamen werden übersetzt
	 * @return list<array{id: string, name: string, hex: string}>
	 */
	public function entries(string $uid): array {
		if (isset($this->entries[$uid])) {
			return $this->entries[$uid];
		}
		$stored = $uid === '' ? [] : self::sanitize($this->settings->get($uid, self::SETTING, ''));
		if ($stored === []) {
			return $this->entries[$uid] = $this->defaults();
		}
		foreach ($stored as &$entry) {
			$english = self::ENGLISH[$entry['id']] ?? null;
			if ($english !== null && in_array(mb_strtolower($entry['name']), [strtolower($english), mb_strtolower($this->tr->t('note_color.names.' . $entry['id'], [], $english))], true)) {
				$entry['name'] = $this->tr->t('note_color.names.' . $entry['id'], [], $english);
			}
		}
		unset($entry);
		return $this->entries[$uid] = $stored;
	}

	/** @return array<string, list<string>> Upstream getKnownNoteColorNames (nur aktive Sprache und Englisch) */
	public function knownNames(): array {
		$out = [];
		foreach (self::ENGLISH as $id => $english) {
			$out[$id] = array_values(array_unique([strtolower($english), mb_strtolower($this->tr->t('note_color.names.' . $id, [], $english))]));
		}
		return $out;
	}

	/** @return array<string, string> id → übersetzter Name der Standardfarben */
	public function localizedNames(): array {
		$out = [];
		foreach (self::ENGLISH as $id => $english) {
			$out[$id] = $this->tr->t('note_color.names.' . $id, [], $english);
		}
		return $out;
	}

	// Upstream resolveNoteColorHex: Hex direkt, Paletten-ID über die Palette von $uid (Standard: angemeldeter Benutzer), unbekannt → ''
	public function hex(?string $stored, ?string $uid = null): string {
		$stored = trim((string)$stored);
		if ($stored === '') {
			return '';
		}
		if ($stored[0] === '#') {
			return self::normalizeHex($stored);
		}
		$uid ??= $this->userSession->getUser()?->getUID() ?? '';
		foreach ($this->entries($uid) as $entry) {
			if ($entry['id'] === strtolower($stored)) {
				return $entry['hex'];
			}
		}
		return '';
	}
}
