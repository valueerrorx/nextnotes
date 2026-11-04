<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Controller;

use OCA\NextNotes\Service\Exception\BadRequestException;
use OCA\NextNotes\Service\NoteService;
use OCA\NextNotes\Service\SettingsService;
use OCA\NextNotes\Service\BackgroundService;
use OCA\NextNotes\Service\WorkspaceService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

// Upstream SettingsController (nur Benutzereinstellungen); „zuletzt geöffnet“ gefiltert über NoteService (Plan H3)
class SettingsApiController extends ApiController {
	public function __construct(IRequest $request, IUserSession $userSession, WorkspaceService $workspaces, private SettingsService $settings, private NoteService $notes, private BackgroundService $backgrounds) {
		parent::__construct($request, $userSession, $workspaces);
	}

	// Deckkraft der Hintergrundbilder je Workspace-Name → intern je Workspace-ID (BackgroundService)
	private function value(string $uid, string $key): string {
		return $key === SettingsService::OPENED_KEY ? $this->notes->openedSetting($uid) : ($this->backgrounds->readOpacity($uid, $key) ?? $this->settings->read($uid, $key));
	}

	#[NoAdminRequired]
	public function index(): JSONResponse {
		$uid = $this->uid();
		$raw = $this->param('keys');
		if ($raw === null || $raw === '') {
			$all = $this->settings->all($uid);
			$all[SettingsService::OPENED_KEY] = $this->notes->openedSetting($uid);
			return $this->ok(['settings' => $all]);
		}
		$keys = [];
		foreach (is_array($raw) ? $raw : explode(',', (string)$raw) as $k) {
			$k = is_scalar($k) ? trim((string)$k) : '';
			if ($k !== '') {
				$keys[$k] = true;
			}
		}
		if (count($keys) > 200) {
			throw new BadRequestException('too many setting keys requested');
		}
		$out = [];
		foreach (array_keys($keys) as $k) {
			$out[$k] = $this->value($uid, (string)$k);
		}
		return $this->ok(['settings' => $out === [] ? new \stdClass() : $out]);
	}

	#[NoAdminRequired]
	public function show(string $key): JSONResponse {
		return $this->ok(['key' => $key, 'value' => $this->value($this->uid(), $key)]);
	}

	#[NoAdminRequired]
	public function update(string $key): JSONResponse {
		$value = $this->backgrounds->writeOpacity($this->uid(), $key, $this->param('value') ?? '') ?? $this->settings->write($this->uid(), $key, $this->param('value') ?? '');
		return $this->ok(['key' => $key, 'value' => $value]);
	}
}
