<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\OneNote;

use OCA\NextNotes\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IURLGenerator;

// Admin-Werte des OneNote-Imports (Plan M9 §2 Admin, ADR 0020); Secret sensibel und lazy, nie an den Browser
class OneNoteConfig {
	public const KEY_CLIENT_ID = 'onenote_client_id';
	public const KEY_SECRET = 'onenote_secret';
	public const KEY_AUTHORITY = 'onenote_authority';
	public const KEY_TEAMS = 'onenote_teams';
	public const COMMON = 'common';
	public const ORGANIZATIONS = 'organizations';
	public const BASE_SCOPES = ['openid', 'offline_access', 'Notes.Read'];
	public const TEAM_SCOPES = ['Notes.Read.All', 'Team.ReadBasic.All'];
	public const CALLBACK_ROUTE = 'nextnotes.one_note_api.callback';
	private const GUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
	// Hostname mit mindestens einem Punkt (z. B. schule.onmicrosoft.com), nur ASCII, ≤ 64 (Spaltenbreite)
	private const DOMAIN = '/^(?=.{4,64}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/';

	public function __construct(
		private IAppConfig $appConfig,
		private IURLGenerator $urls,
	) {
	}

	public function clientId(): string {
		return $this->appConfig->getValueString(Application::APP_ID, self::KEY_CLIENT_ID);
	}

	public function secret(): string {
		return $this->appConfig->getValueString(Application::APP_ID, self::KEY_SECRET, '', true);
	}

	public function hasSecret(): bool {
		return $this->secret() !== '';
	}

	// Standard organizations: nimmt nur Organisationskonten an (Plan M9 §11.1)
	public function authority(): string {
		$v = $this->appConfig->getValueString(Application::APP_ID, self::KEY_AUTHORITY, self::ORGANIZATIONS);
		return $v === '' ? self::ORGANIZATIONS : $v;
	}

	public function teamsSwitch(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, self::KEY_TEAMS);
	}

	// B7: Team-Schalter wirkt bei common nicht
	public function teamsEffective(): bool {
		return $this->teamsSwitch() && $this->authority() !== self::COMMON;
	}

	// ohne Client-ID oder Secret ist die Funktion aus (Importe ruhen, §4.7)
	public function enabled(): bool {
		return $this->clientId() !== '' && $this->hasSecret();
	}

	/** @return list<string> */
	public static function scopes(bool $teams): array {
		return $teams ? array_merge(self::BASE_SCOPES, self::TEAM_SCOPES) : self::BASE_SCOPES;
	}

	public function redirectUri(): string {
		return $this->urls->linkToRouteAbsolute(self::CALLBACK_ROUTE);
	}

	// Tenant-ID (GUID), Domain, organizations oder common; sonst null
	public static function normalizeAuthority(string $raw): ?string {
		$v = strtolower(trim($raw));
		if ($v === self::COMMON || $v === self::ORGANIZATIONS) {
			return $v;
		}
		if (preg_match(self::GUID, $v) === 1 || preg_match(self::DOMAIN, $v) === 1) {
			return $v;
		}
		return null;
	}

	public static function normalizeClientId(string $raw): ?string {
		$v = strtolower(trim($raw));
		return $v === '' || preg_match(self::GUID, $v) === 1 ? $v : null;
	}

	/** Speichert nur übergebene Werte (null = unverändert); Secret '' löscht es */
	public function save(?string $authority, ?string $clientId, ?string $secret, ?bool $teams): void {
		if ($authority !== null) {
			$this->appConfig->setValueString(Application::APP_ID, self::KEY_AUTHORITY, $authority);
		}
		if ($clientId !== null) {
			$this->appConfig->setValueString(Application::APP_ID, self::KEY_CLIENT_ID, $clientId);
		}
		if ($secret !== null) {
			if ($secret === '') {
				$this->appConfig->deleteKey(Application::APP_ID, self::KEY_SECRET);
			} else {
				$this->appConfig->setValueString(Application::APP_ID, self::KEY_SECRET, $secret, lazy: true, sensitive: true);
			}
		}
		if ($teams !== null) {
			$this->appConfig->setValueBool(Application::APP_ID, self::KEY_TEAMS, $teams);
		}
	}
}
