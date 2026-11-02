<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Controller;

use OCA\NextNotes\AppInfo\Application;
use OCA\NextNotes\Service\SettingsService;
use OCA\NextNotes\Service\WorkspaceService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

// Upstream SystemController ohne GitHub-Abrufe; Updates liefert der NC-App-Store
class SystemApiController extends ApiController {
	public function __construct(
		IRequest $request,
		IUserSession $userSession,
		WorkspaceService $workspaces,
		private IAppManager $appManager,
		private SettingsService $settings,
	) {
		parent::__construct($request, $userSession, $workspaces);
	}

	private function current(): string {
		return $this->appManager->getAppVersion(Application::APP_ID);
	}

	#[NoAdminRequired]
	public function version(): JSONResponse {
		$this->uid();
		$v = $this->current();
		return $this->ok(['current_version' => $v, 'latest_version' => $v, 'is_up_to_date' => true, 'has_update' => false]);
	}

	// B12: kein Seiteneffekt, keine Release-Notizen
	#[NoAdminRequired]
	public function whatsNew(): JSONResponse {
		$this->uid();
		return $this->ok(['show' => false, 'current_version' => $this->current(), 'releases' => [], 'more' => 0]);
	}

	#[NoAdminRequired]
	public function whatsNewSeen(): JSONResponse {
		$this->uid();
		return $this->ok(['seen_version' => $this->current()]);
	}

	// Plan O4: Wörterbücher aus nextnotes/i18n (Build M3), Fallback en, sonst leer
	#[NoAdminRequired]
	public function i18n(): JSONResponse {
		$uid = $this->uid();
		$lang = $this->settings->read($uid, 'language');
		$req = $this->param('lang');
		if (is_string($req) && preg_match('/^[a-z]{2}(-[a-z]{2})?$/', strtolower(trim($req)))) {
			$lang = strtolower(trim($req));
		}
		$en = $this->dictionary('en');
		$active = $lang === 'en' ? $en : $this->dictionary($lang);
		if ($active === [] && str_contains($lang, '-')) {
			$active = $this->dictionary(explode('-', $lang)[0]);
		}
		$strings = array_replace_recursive($en, $active);
		return $this->ok(['lang' => $lang, 'strings' => $strings === [] ? new \stdClass() : $strings]);
	}

	/** @return array<string, mixed> */
	private function dictionary(string $lang): array {
		if (!preg_match('/^[a-z]{2}(-[a-z]{2})?$/', $lang)) {
			return [];
		}
		$file = $this->appManager->getAppPath(Application::APP_ID) . '/i18n/' . $lang . '.json';
		if (!is_file($file)) {
			return [];
		}
		$data = json_decode((string)file_get_contents($file), true);
		return is_array($data) ? $data : [];
	}

	// ADR 0011: keine öffentlichen Links; leere Listen im Upstream-Format
	#[NoAdminRequired]
	public function sharedWithMe(): JSONResponse {
		$this->uid();
		return $this->ok(['items' => [], 'shared_workspaces' => []]);
	}

	#[NoAdminRequired]
	public function shared(): JSONResponse {
		$this->uid();
		return $this->ok(['shared_notes' => [], 'shared_folders' => []]);
	}
}
