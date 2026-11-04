<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Controller;

use OCA\NextNotes\Service\DiaryService;
use OCA\NextNotes\Service\WorkspaceService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

// Upstream api/v1/calendar/*.php und api/v1/diary/*.php (Inventur A #186–190); Antworten ohne success-Hülle wie Upstream
class DiaryApiController extends ApiController {
	public function __construct(IRequest $request, IUserSession $userSession, WorkspaceService $workspaces, private DiaryService $diary) {
		parent::__construct($request, $userSession, $workspaces);
	}

	#[NoAdminRequired]
	public function diaryEntry(): JSONResponse {
		return new JSONResponse($this->diary->lookup($this->uid(), $this->param('date'), $this->param('workspace'), $this->param('diary')));
	}

	#[NoAdminRequired]
	public function notesByDate(): JSONResponse {
		return new JSONResponse((object)$this->diary->countsByDate($this->uid(), $this->param('workspace'), $this->param('mode')));
	}

	#[NoAdminRequired]
	public function notesOnDate(): JSONResponse {
		return new JSONResponse($this->diary->notesOnDate($this->uid(), $this->param('date'), $this->param('workspace'), $this->param('mode')));
	}

	#[NoAdminRequired]
	public function diaries(): JSONResponse {
		return new JSONResponse($this->diary->diaries($this->uid(), $this->param('workspace')));
	}

	#[NoAdminRequired]
	public function createDiary(): JSONResponse {
		return new JSONResponse($this->diary->createDiary($this->uid(), $this->input()));
	}

	#[NoAdminRequired]
	public function entries(): JSONResponse {
		return new JSONResponse($this->diary->entries($this->uid(), $this->param('ids'), $this->param('workspace')));
	}
}
