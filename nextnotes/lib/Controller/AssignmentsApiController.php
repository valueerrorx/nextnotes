<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Controller;

use OCA\NextNotes\Service\AssignmentService;
use OCA\NextNotes\Service\WorkspaceService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

// Aufgaben und Abgaben (SPEC §9.5, M6d: ohne Austeilen); Rechte nur über AccessService (§9.7)
class AssignmentsApiController extends ApiController {
	public function __construct(IRequest $request, IUserSession $userSession, WorkspaceService $workspaces,
		private AssignmentService $assignments) {
		parent::__construct($request, $userSession, $workspaces);
	}

	#[NoAdminRequired]
	public function index(): JSONResponse {
		return $this->ok($this->assignments->index($this->uid()));
	}

	#[NoAdminRequired]
	public function store(): JSONResponse {
		return $this->ok($this->assignments->create($this->uid(), $this->input()), 201);
	}

	#[NoAdminRequired]
	public function show(int $id): JSONResponse {
		return $this->ok($this->assignments->show($this->uid(), $id));
	}

	#[NoAdminRequired]
	public function update(int $id): JSONResponse {
		$in = $this->input();
		unset($in['id']);
		return $this->ok($this->assignments->update($this->uid(), $id, $in));
	}

	#[NoAdminRequired]
	public function destroy(int $id): JSONResponse {
		$this->assignments->delete($this->uid(), $id, $this->param('confirm'));
		return $this->ok(['message' => 'Assignment deleted']);
	}

	#[NoAdminRequired]
	public function submissions(int $id): JSONResponse {
		return $this->ok($this->assignments->submissions($this->uid(), $id));
	}

	#[NoAdminRequired]
	public function submission(int $id): JSONResponse {
		return $this->ok($this->assignments->submission($this->uid(), $id));
	}
}
