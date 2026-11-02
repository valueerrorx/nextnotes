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
use OCA\NextNotes\Service\WorkspaceService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Collaboration\Collaborators\ISearch;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Share\IShare;

// Upstream UsersController: nur /users/me und /users/lookup; Konten verwaltet Nextcloud
class UsersApiController extends ApiController {
	public function __construct(
		IRequest $request,
		IUserSession $userSession,
		WorkspaceService $workspaces,
		private IUserManager $users,
		private ISearch $search,
		private SettingsService $settings,
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
}
