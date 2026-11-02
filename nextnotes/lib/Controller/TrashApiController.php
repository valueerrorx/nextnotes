<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Controller;

use OCA\NextNotes\Service\TrashService;
use OCA\NextNotes\Service\WorkspaceService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

class TrashApiController extends ApiController {
	public function __construct(IRequest $request, IUserSession $userSession, WorkspaceService $workspaces, private TrashService $trash) {
		parent::__construct($request, $userSession, $workspaces);
	}

	#[NoAdminRequired]
	public function index(): JSONResponse {
		return $this->ok($this->trash->index($this->uid(), $this->param('workspace'), $this->param('search')));
	}

	#[NoAdminRequired]
	public function empty(): JSONResponse {
		$count = $this->trash->empty($this->uid(), $this->param('workspace'));
		return $this->ok(['message' => 'Trash emptied successfully', 'deleted_count' => $count]);
	}

	#[NoAdminRequired]
	public function destroy(int $id): JSONResponse {
		$this->trash->destroy($this->uid(), $id, $this->param('workspace'));
		return $this->ok(['message' => 'Note permanently deleted']);
	}
}
