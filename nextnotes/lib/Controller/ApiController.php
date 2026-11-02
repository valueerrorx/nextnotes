<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Controller;

use OCA\NextNotes\AppInfo\Application;
use OCA\NextNotes\Service\Exception\ForbiddenException;
use OCA\NextNotes\Service\WorkspaceService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

// Gemeinsame Basis der /api/v1-Controller: UID aus der Sitzung, Parameter, Upstream-Antwort
abstract class ApiController extends Controller {
	private ?string $uid = null;

	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		protected WorkspaceService $workspaces,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	// Angemeldete UID; legt beim ersten Zugriff den Standard-Workspace an
	protected function uid(): string {
		if ($this->uid === null) {
			$user = $this->userSession->getUser();
			if ($user === null) {
				throw new ForbiddenException('Not logged in');
			}
			$this->uid = $user->getUID();
			$this->workspaces->ensureDefault($this->uid);
		}
		return $this->uid;
	}

	// Query, Formular- und JSON-Body (NC dekodiert JSON in die Parameter)
	/** @return array<string, mixed> */
	protected function input(): array {
		$params = $this->request->getParams();
		unset($params['_route']);
		return $params;
	}

	protected function param(string $name): mixed {
		return $this->request->getParam($name);
	}

	/** @param array<string, mixed> $data */
	protected function ok(array $data = [], int $status = 200): JSONResponse {
		return new JSONResponse(['success' => true] + $data, $status);
	}
}
