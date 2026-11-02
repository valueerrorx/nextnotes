<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Controller;

use OCA\NextNotes\Service\LinkService;
use OCA\NextNotes\Service\WorkspaceService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

// Graph und Backlinks (Upstream GraphController, BacklinksController)
class GraphApiController extends ApiController {
	public function __construct(
		IRequest $request,
		IUserSession $userSession,
		WorkspaceService $workspaces,
		private LinkService $links,
	) {
		parent::__construct($request, $userSession, $workspaces);
	}

	#[NoAdminRequired]
	public function backlinks(int $id): JSONResponse {
		$all = (string)($this->param('all_workspaces') ?? '') === '1';
		return $this->ok($this->links->backlinks($this->uid(), $id, $this->param('workspace'), $all));
	}

	#[NoAdminRequired]
	public function index(): JSONResponse {
		return $this->ok($this->links->graph($this->uid(), $this->param('workspace'), $this->param('limit')));
	}
}
