<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Controller;

use OCA\NextNotes\Service\MentionService;
use OCA\NextNotes\Service\WorkspaceService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

// Upstream MentionsController; {ownerId} wird angenommen und ignoriert, Zugriff nur über die Notiz
class MentionsApiController extends ApiController {
	public function __construct(IRequest $request, IUserSession $userSession, WorkspaceService $workspaces, private MentionService $mentions) {
		parent::__construct($request, $userSession, $workspaces);
	}

	#[NoAdminRequired]
	public function index(): JSONResponse {
		return $this->ok($this->mentions->index($this->uid(), $this->param('include_handled') === '1'));
	}

	#[NoAdminRequired]
	public function members(): JSONResponse {
		$ws = $this->param('workspace');
		return $this->ok(['workspace' => is_string($ws) ? trim($ws) : '', 'members' => $this->mentions->members($this->uid(), $ws)]);
	}

	#[NoAdminRequired]
	public function markHandled(string $ownerId, int $noteId): JSONResponse {
		return $this->ok($this->mentions->setHandled($this->uid(), $noteId, true));
	}

	#[NoAdminRequired]
	public function unmarkHandled(string $ownerId, int $noteId): JSONResponse {
		return $this->ok($this->mentions->setHandled($this->uid(), $noteId, false));
	}
}
