<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service;

use OCA\NextNotes\Service\Exception\BadRequestException;

// Serverseitige Prüfung von Eingaben (Regeln wie Upstream, Verstoß → 400)
class InputValidator {
	// Upstream getDefaultNoteColorPalette (src/lib/note-colors.php)
	public const PALETTE = [
		'blue' => '#3b82f6', 'green' => '#22c55e', 'yellow' => '#eab308', 'orange' => '#f97316',
		'red' => '#ef4444', 'purple' => '#a855f7', 'pink' => '#ec4899', 'gray' => '#6b7280',
	];
	public const RESERVED_FOLDER_NAMES = ['Favorites', 'Tags', 'Trash', 'Public'];

	// Notiz-/Ordner-/Workspace-Farbe: Paletten-ID oder #rgb/#rrggbb; '' = keine
	public function color(mixed $raw): ?string {
		$value = trim((string)($raw ?? ''));
		if ($value === '') {
			return null;
		}
		if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value)) {
			$value = strtolower($value);
			if (strlen($value) === 4) {
				$value = '#' . $value[1] . $value[1] . $value[2] . $value[2] . $value[3] . $value[3];
			}
			return $value;
		}
		// Paletten-IDs wie sanitizeNoteColorPalette (eigene Paletten ab Settings M2c)
		$value = strtolower($value);
		if (preg_match('/^[a-z0-9_-]{1,32}$/', $value)) {
			return $value;
		}
		throw new BadRequestException('Invalid color: expected a palette id or a #rrggbb value');
	}

	public function colorHex(?string $stored): ?string {
		if ($stored === null || $stored === '') {
			return null;
		}
		if ($stored[0] === '#') {
			return $stored;
		}
		return self::PALETTE[$stored] ?? null;
	}

	// Icon-Farbe wie poznoteIsSafeCssColor; '' = keine
	public function iconColor(mixed $raw): ?string {
		$value = trim((string)($raw ?? ''));
		if ($value === '') {
			return null;
		}
		if (!preg_match('/^(#[0-9a-fA-F]{3,8}|[a-zA-Z]{3,30})$/', $value)) {
			throw new BadRequestException('Invalid icon color');
		}
		return $value;
	}

	public function titleColor(mixed $raw): ?string {
		$value = $raw === null ? '' : trim((string)$raw);
		if ($value === '') {
			return null;
		}
		if (!preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
			throw new BadRequestException('Invalid value for "title_color": expected a #rrggbb colour, or null');
		}
		return strtolower($value);
	}

	// Icon = CSS-Klassenliste wie normalizeFolderIcon; anderes → 400 statt stillem Entfernen
	public function icon(mixed $raw): ?string {
		$value = trim((string)($raw ?? ''));
		if ($value === '') {
			return null;
		}
		if (!preg_match('/^[A-Za-z0-9 _-]{1,64}$/', $value)) {
			throw new BadRequestException('Invalid icon');
		}
		return preg_replace('/\s+/', ' ', $value);
	}

	public function noteType(mixed $raw): string {
		$type = is_string($raw) ? trim($raw) : '';
		if ($type === '') {
			return 'note';
		}
		if (!preg_match('/^[A-Za-z0-9_-]{1,16}$/', $type)) {
			throw new BadRequestException('Invalid note type');
		}
		return $type;
	}

	public function heading(string $heading): string {
		$heading = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $heading) ?? '');
		if (mb_strlen($heading) > 1000) {
			throw new BadRequestException('Title too long (max 1000 characters)');
		}
		return $heading;
	}

	// Ordnersegment wie FoldersController::validateFolderSegment
	public function folderName(mixed $raw): string {
		$name = trim((string)($raw ?? ''));
		if ($name === '') {
			throw new BadRequestException('Folder name is required');
		}
		if (mb_strlen($name) > 255) {
			throw new BadRequestException('Folder name too long (max 255 characters)');
		}
		foreach (['/', '\\', ':', '*', '?', '"', '<', '>', '|'] as $char) {
			if (str_contains($name, $char)) {
				throw new BadRequestException('Folder name contains forbidden character: ' . $char);
			}
		}
		if (preg_match('/[\x00-\x1F\x7F]/', $name)) {
			throw new BadRequestException('Folder name contains a control character');
		}
		if (in_array($name, self::RESERVED_FOLDER_NAMES, true)) {
			throw new BadRequestException('Cannot create folder with reserved name: ' . $name);
		}
		if ($name === '.' || $name === '..') {
			throw new BadRequestException('Invalid folder name');
		}
		return $name;
	}

	// Workspace-Name wie WorkspacesController (:119); Klammern kommen nie vor (Schlüssel §4.4)
	public function workspaceName(mixed $raw): string {
		$name = trim((string)($raw ?? ''));
		if ($name === '') {
			throw new BadRequestException('name is required');
		}
		if (mb_strlen($name) > 255 || !preg_match('/^[\p{L}0-9 _-]+$/u', $name)) {
			throw new BadRequestException('Invalid name: use letters (including accented), numbers, spaces, dash or underscore only');
		}
		return $name;
	}

	/**
	 * Workspace-Labels wie poznoteParseWorkspaceTags (max. 20 × 50 Zeichen)
	 * @return string[]
	 */
	public function workspaceTags(mixed $raw): array {
		$parts = is_array($raw) ? $raw : explode(',', (string)($raw ?? ''));
		$tags = [];
		$seen = [];
		foreach ($parts as $part) {
			if (!is_scalar($part)) {
				throw new BadRequestException('Invalid workspace tag');
			}
			$tag = trim(preg_replace('/\s+/u', ' ', (string)$part) ?? '');
			if ($tag === '') {
				continue;
			}
			if (preg_match('/[<>"`\x00-\x1F]/', $tag)) {
				throw new BadRequestException('Invalid workspace tag: ' . $tag);
			}
			$tag = mb_substr($tag, 0, 50);
			$key = mb_strtolower($tag);
			if (isset($seen[$key])) {
				continue;
			}
			$seen[$key] = true;
			$tags[] = $tag;
			if (count($tags) >= 20) {
				break;
			}
		}
		return $tags;
	}

	/**
	 * Notiz-Tags wie NotesController::sanitizeTags (String: Leerzeichen trennen; Array: Leerzeichen → _)
	 * @return string[]
	 */
	public function noteTags(mixed $raw): array {
		if ($raw === null || $raw === '' || $raw === []) {
			return [];
		}
		if (is_array($raw)) {
			$items = $raw;
		} elseif (is_scalar($raw)) {
			$items = explode(',', str_replace(' ', ',', (string)$raw));
		} else {
			throw new BadRequestException('Invalid tags');
		}
		$out = [];
		foreach ($items as $item) {
			$tag = is_string($item) ? trim($item) : '';
			if ($tag === '') {
				continue;
			}
			$out[] = $this->tagName($tag);
		}
		return array_values(array_unique($out));
	}

	// Einzelner Tag: Leerzeichen/Kommas → _, keine Markup-Zeichen
	public function tagName(string $tag): string {
		$tag = preg_replace('/[\s,]+/u', '_', trim($tag)) ?? '';
		if ($tag === '' || mb_strlen($tag) > 255 || preg_match('/[<>"`\x00-\x1F\x7F]/', $tag)) {
			throw new BadRequestException('Invalid tag: ' . mb_substr($tag, 0, 50));
		}
		return $tag;
	}

	public function bool(mixed $raw, string $field): bool {
		$value = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
		if ($value === null) {
			throw new BadRequestException('Invalid value for "' . $field . '": expected a boolean');
		}
		return $value;
	}

	// Kalenderdatum und Uhrzeit (Stunde 0–23, Minute/Sekunde 0–59) am Anfang von $raw gültig; ohne Treffer true (Format prüft der Aufrufer)
	public static function validDateTime(string $raw): bool {
		if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2}))?)?/', trim($raw), $m)) {
			return true;
		}
		if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
			return false;
		}
		return (int)($m[4] ?? 0) <= 23 && (int)($m[5] ?? 0) <= 59 && (int)($m[6] ?? 0) <= 59;
	}
}
