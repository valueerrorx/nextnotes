<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Controller;

use OCA\NextNotes\AppInfo\Application;
use OCA\NextNotes\Service\Exception\BadRequestException;
use OCA\NextNotes\Service\Exception\NotFoundException;
use OCA\NextNotes\Service\SettingsService;
use OCA\NextNotes\Service\ShareTargetPolicy;
use OCA\NextNotes\Service\WorkspaceService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Collaboration\Collaborators\ISearch;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Share\IShare;

// Upstream UsersController: nur /users/me und /users/lookup; Konten verwaltet Nextcloud; /users/search für den Freigabe-Dialog (M5d)
class UsersApiController extends ApiController {
	public function __construct(
		IRequest $request,
		IUserSession $userSession,
		WorkspaceService $workspaces,
		private IUserManager $users,
		private ISearch $search,
		private SettingsService $settings,
		private ShareTargetPolicy $policy,
	) {
		parent::__construct($request, $userSession, $workspaces);
	}

	#[NoAdminRequired]
	public function me(): JSONResponse {
		$uid = $this->uid();
		$user = $this->users->get($uid) ?? throw new NotFoundException('User profile not found');
		return new JSONResponse([
			'id' => $uid,
			'username' => $uid,
			'email' => $user->getEMailAddress(),
			'first_name' => null,
			'last_name' => null,
			'display_name' => $user->getDisplayName(),
			'language' => $this->settings->read($uid, 'language'),
			'is_admin' => false,
			'active' => $user->isEnabled(),
		]);
	}

	// Sichtbarkeit nach NC-Freigaberegeln (filteredSearch prüft Gruppen-/Aufzählungsgrenzen)
	#[NoAdminRequired]
	public function lookup(string $username): JSONResponse {
		$this->uid();
		$username = trim($username);
		if ($username === '') {
			throw new BadRequestException('Username is required');
		}
		[$result] = $this->search->filteredSearch($username, [IShare::TYPE_USER], false, Application::APP_ID, null, 25, 0);
		foreach (array_merge($result['exact']['users'] ?? [], $result['users'] ?? []) as $hit) {
			$uid = (string)($hit['value']['shareWith'] ?? '');
			if ($uid !== '' && strcasecmp($uid, $username) === 0) {
				return new JSONResponse(['id' => $uid, 'username' => $uid]);
			}
		}
		throw new NotFoundException('User not found');
	}

	// Freigabe-Dialog (Plan M5 §2.7): NC-Suche (Gruppen-/Aufzählungsregeln), zusätzlich Freigaberegeln; nur UID und Anzeigename
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	public function search(): JSONResponse {
		$uid = $this->uid();
		$q = is_string($this->param('q')) ? trim($this->param('q')) : '';
		if (mb_strlen($q) > 100) {
			throw new BadRequestException('Search term too long');
		}
		if (mb_strlen($q) < 2 || !$this->policy->actorMayShare($uid)) {
			return $this->ok(['users' => []]);
		}
		[$result] = $this->search->filteredSearch($q, [IShare::TYPE_USER], false, Application::APP_ID, null, 25, 0);
		$out = [];
		foreach (array_merge($result['exact']['users'] ?? [], $result['users'] ?? []) as $hit) {
			$target = (string)($hit['value']['shareWith'] ?? '');
			if ($target === '' || isset($out[$target]) || !$this->policy->allowed($uid, $target)) {
				continue;
			}
			$out[$target] = ['uid' => $target, 'displayName' => $this->users->getDisplayName($target) ?? $target];
			if (count($out) >= 20) {
				break;
			}
		}
		return $this->ok(['users' => array_values($out)]);
	}
}
