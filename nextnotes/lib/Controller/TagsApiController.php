<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Controller;

use OCA\NextNotes\Service\TagService;
use OCA\NextNotes\Service\WorkspaceService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

class TagsApiController extends ApiController {
	public function __construct(IRequest $request, IUserSession $userSession, WorkspaceService $workspaces, private TagService $tags) {
		parent::__construct($request, $userSession, $workspaces);
	}

	#[NoAdminRequired]
	public function index(): JSONResponse {
		return $this->ok(['tags' => $this->tags->index($this->uid(), $this->param('workspace'))]);
	}

	#[NoAdminRequired]
	public function rename(string $tag): JSONResponse {
		$in = $this->input();
		return $this->ok(['updated' => $this->tags->rename($this->uid(), $tag, $in['new_name'] ?? null, $in['workspace'] ?? null)]);
	}

	#[NoAdminRequired]
	public function destroy(string $tag): JSONResponse {
		return $this->ok(['updated' => $this->tags->delete($this->uid(), $tag, $this->param('workspace'))]);
	}
}
