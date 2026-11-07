<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service;

use OCA\NextNotes\AppInfo\Application;
use OCA\NextNotes\Service\Exception\BadRequestException;
use OCP\Config\IUserConfig;
use OCP\IDateTimeZone;
use OCP\IUserManager;
use OCP\L10N\IFactory;

// Benutzereinstellungen in IUserConfig; API-Regeln nach Upstream SettingsController (Plan M2c)
class SettingsService {
	public const OPENED_KEY = 'recently_opened_notes';
	private const KEY_PATTERN = '/^[a-z][a-z0-9_]{0,62}$/';
	private const MAX_VALUE = 65536;
	private const MAX_KEYS = 300;
	// Upstream-Global-, Geheim- und Funktionsschlüssel gestrichener Funktionen
	private const BLOCKED = ['hidden_ui_elements_global', 'login_display_name', 'discussions_url', 'discord_url', 'custom_css_path',
		'theme_list', 'allow_executable_attachments', 'login_disabled', 'two_factor_required', 'outbound_allowed_hosts',
		'whats_new_seen_version', 'schema_version', 'user_profile_username', 'language_source', 'snapshots_expiry_sweep_at',
		WorkspaceService::FOREIGN_ORDER_KEY];
	private const BLOCKED_PREFIXES = ['git_', 'github_', 'ai_', 'stt_', 'smtp_', 'mcp_', 'admin_', 'offline_', 'tenant_',
		'user_max_', 'import_max_', 'login_', 'two_factor', 'webhook', 'push_', 'backup', ...BackgroundFiles::OPACITY_PREFIXES];
	// Werte aus Nextcloud bzw. vom Server geführt, über die API nur lesbar
	private const READ_ONLY = ['language', 'timezone', self::OPENED_KEY];
	private const BOOLS = ['whats_new_popup', 'attachment_previews_in_note', 'emoji_icons_enabled', 'show_note_icons',
		'markdown_heading_underline', 'markdown_live_editing', 'markdown_live_show_syntax', 'folder_icon_opens_kanban',
		'highlight_current_folder_tree', 'slash_menu_require_alt'];
	private const ENUMS = [
		'markdown_default_view_mode' => ['preview', 'edit', 'split', 'last'],
		'markdown_new_note_view_mode' => ['split', 'edit', 'live', 'rich'],
		'new_note_shortcut_type' => ['markdown', 'note', 'tasklist'],
		'slash_menu_trigger' => ['slash', 'alt-slash', 'disabled'],
		'slash_menu_trigger_mobile' => ['slash', 'alt-slash', 'disabled'],
		'format_toolbar_mode' => ['toolbar', 'floating'],
		'welcome_setup' => ['pending', 'done'],
		'note_list_sort' => ['heading_asc', 'updated_desc', 'created_desc', 'type_asc', 'manual'],
	];
	// [min, max]
	private const INTS = [
		'snapshots_keep_count' => [1, 30],
		'snapshots_safety_keep_count' => [1, 200],
		'folder_tree_dim_level' => [10, 90],
		'note_age_filter_days' => [0, 36500],
	];
	private const JSON_LISTS = ['hidden_ui_elements', 'settings_pinned_cards', 'icon_sidebar_order', 'tasks_page_hidden_notes'];
	private const JSON_OBJECTS = ['tag_colors', 'icon_sidebar_colors', 'toolbar_icon_colors', 'speed_dial_label_colors',
		'markdown_colored_custom', 'diary_templates'];

	public function __construct(
		private IUserConfig $config,
		private IUserManager $users,
		private IFactory $l10nFactory,
		private IDateTimeZone $timeZone,
	) {
	}

	// lazy: Werte werden nicht bei jedem Konfigurationszugriff auf den Benutzer mitgeladen
	public function get(string $uid, string $key, string $default = ''): string {
		return $this->config->getValueString($uid, Application::APP_ID, $key, $default, true);
	}

	public function set(string $uid, string $key, string $value): void {
		$this->config->setValueString($uid, Application::APP_ID, $key, $value, true);
	}

	// Upstream poznoteNormalizeNoteSort
	public function noteListSort(string $uid): string {
		$value = $this->get($uid, 'note_list_sort', 'updated_desc');
		return in_array($value, self::ENUMS['note_list_sort'], true) ? $value : 'updated_desc';
	}

	// ---------- /settings ----------

	public function isAllowedKey(string $key): bool {
		if (!preg_match(self::KEY_PATTERN, $key) || in_array($key, self::BLOCKED, true)) {
			return false;
		}
		foreach (self::BLOCKED_PREFIXES as $prefix) {
			if (str_starts_with($key, $prefix)) {
				return false;
			}
		}
		return true;
	}

	/** @return array<string, string> gespeicherte erlaubte Werte plus Sprache/Zeitzone (ohne zuletzt geöffnet) */
	public function all(string $uid): array {
		$out = [];
		foreach ($this->config->getValues($uid, Application::APP_ID) as $key => $value) {
			if ($this->isAllowedKey((string)$key) && $key !== self::OPENED_KEY) {
				$out[(string)$key] = is_scalar($value) ? (string)$value : '';
			}
		}
		$out['language'] = $this->language($uid);
		$out['timezone'] = $this->timeZone->getTimeZone(false, $uid)->getName();
		return $out;
	}

	// Unbekannter oder gesperrter Schlüssel liest sich wie Upstream als ''
	public function read(string $uid, string $key): string {
		return match (true) {
			$key === 'language' => $this->language($uid),
			$key === 'timezone' => $this->timeZone->getTimeZone(false, $uid)->getName(),
			!$this->isAllowedKey($key) || $key === self::OPENED_KEY => '',
			default => $this->get($uid, $key),
		};
	}

	public function write(string $uid, string $key, mixed $value): string {
		if ($key === '') {
			throw new BadRequestException('key is required');
		}
		if (!$this->isAllowedKey($key)) {
			throw new BadRequestException('unknown setting');
		}
		if (in_array($key, self::READ_ONLY, true)) {
			throw new BadRequestException($key . ' is managed by Nextcloud');
		}
		$normalized = $this->normalize($key, $value);
		if (strlen($normalized) > self::MAX_VALUE) {
			throw new BadRequestException('value too long');
		}
		if (!$this->config->hasKey($uid, Application::APP_ID, $key, null) && count($this->config->getKeys($uid, Application::APP_ID)) >= self::MAX_KEYS) {
			throw new BadRequestException('too many settings');
		}
		$this->set($uid, $key, $normalized);
		return $normalized;
	}

	private function language(string $uid): string {
		$lang = $this->l10nFactory->getUserLanguage($this->users->get($uid));
		return strtolower(str_replace('_', '-', $lang === '' ? 'en' : $lang));
	}

	private function normalize(string $key, mixed $value): string {
		if (is_array($value)) {
			$value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		}
		$raw = is_scalar($value) || $value === null ? trim((string)$value) : '';
		if (in_array($key, self::BOOLS, true)) {
			return filter_var($raw, FILTER_VALIDATE_BOOL) ? '1' : '0';
		}
		if (isset(self::ENUMS[$key])) {
			if (!in_array($raw, self::ENUMS[$key], true)) {
				throw new BadRequestException('invalid ' . str_replace('_', ' ', $key));
			}
			return $raw;
		}
		if (isset(self::INTS[$key])) {
			[$min, $max] = self::INTS[$key];
			$int = filter_var($raw, FILTER_VALIDATE_INT);
			if ($int === false || $int < $min || $int > $max) {
				throw new BadRequestException("value must be between $min and $max");
			}
			return (string)$int;
		}
		if (in_array($key, self::JSON_LISTS, true)) {
			$decoded = $raw === '' ? [] : json_decode($raw, true);
			if (!is_array($decoded) || !array_is_list($decoded)) {
				throw new BadRequestException('value must be a JSON array');
			}
			foreach ($decoded as $entry) {
				if (!is_int($entry) && !(is_string($entry) && strlen($entry) <= 200)) {
					throw new BadRequestException('value must be a JSON array');
				}
			}
			return (string)json_encode(array_values($decoded), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		}
		if (in_array($key, self::JSON_OBJECTS, true)) {
			if ($raw === '' || $raw === '{}' || $raw === '[]') {
				return '';
			}
			$decoded = json_decode($raw, true);
			if (!is_array($decoded) || array_is_list($decoded)) {
				throw new BadRequestException('value must be a JSON object');
			}
			return (string)json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		}
		if ($key === 'markdown_colored') {
			if (in_array($raw, ['', '0', 'false'], true)) {
				return '0';
			}
			if ($raw !== 'custom') {
				throw new BadRequestException('invalid markdown colored value');
			}
			return 'custom';
		}
		if ($key === NotePalette::SETTING) {
			// Upstream sanitizeNoteColorPalette; leer = Standardpalette
			$clean = $raw === '' ? [] : NotePalette::sanitize($raw);
			if ($raw !== '' && $clean === []) {
				throw new BadRequestException('invalid note color palette');
			}
			return $clean === [] ? '' : (string)json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		}
		if ($key === 'favorites_icon_color') {
			$color = strtolower($raw);
			if ($color !== '' && !preg_match('/^#[0-9a-f]{6}$/', $color)) {
				throw new BadRequestException('favorites_icon_color must be #rrggbb or empty');
			}
			return $color;
		}
		return $raw;
	}
}
