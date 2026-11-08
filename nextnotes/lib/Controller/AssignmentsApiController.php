<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Controller;

use OCA\NextNotes\Service\AssignmentService;
use OCA\NextNotes\Service\SubmissionService;
use OCA\NextNotes\Service\WorkspaceService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

// Aufgaben und Abgaben (SPEC §9.5); Rechte nur über AccessService (§9.7)
class AssignmentsApiController extends ApiController {
	public function __construct(IRequest $request, IUserSession $userSession, WorkspaceService $workspaces,
		private AssignmentService $assignments, private SubmissionService $submissionActions) {
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

	// bis zu 20 s je Aufruf (Zeitbudget): Lehrkraft höchstens 10/min
	#[NoAdminRequired]
	#[UserRateLimit(limit: 10, period: 60)]
	public function distribute(int $id): JSONResponse {
		return $this->ok($this->assignments->distribute($this->uid(), $id));
	}

	#[NoAdminRequired]
	public function close(int $id): JSONResponse {
		return $this->ok($this->assignments->close($this->uid(), $id));
	}

	#[NoAdminRequired]
	public function submit(int $id): JSONResponse {
		return $this->ok($this->submissionActions->submit($this->uid(), $id));
	}

	#[NoAdminRequired]
	public function giveBack(int $id): JSONResponse {
		return $this->ok($this->submissionActions->giveBack($this->uid(), $id, $this->input()));
	}

	#[NoAdminRequired]
	public function reopen(int $id): JSONResponse {
		return $this->ok($this->submissionActions->reopen($this->uid(), $id, $this->input()));
	}

	#[NoAdminRequired]
	public function feedback(int $id): JSONResponse {
		return $this->ok($this->submissionActions->feedback($this->uid(), $id, $this->input()));
	}
}
