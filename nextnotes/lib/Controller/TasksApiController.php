<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Controller;

use OCA\NextNotes\Service\TaskService;
use OCA\NextNotes\Service\WorkspaceService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

// Upstream TasksController
class TasksApiController extends ApiController {
	public function __construct(IRequest $request, IUserSession $userSession, WorkspaceService $workspaces, private TaskService $tasks) {
		parent::__construct($request, $userSession, $workspaces);
	}

	#[NoAdminRequired]
	public function index(): JSONResponse {
		return $this->ok($this->tasks->index($this->uid(), $this->param('workspace')));
	}

	#[NoAdminRequired]
	public function listForNote(int $id): JSONResponse {
		return $this->ok($this->tasks->listForNote($this->uid(), $id));
	}

	#[NoAdminRequired]
	public function create(int $id): JSONResponse {
		return $this->ok($this->tasks->create($this->uid(), $id, $this->input()));
	}

	#[NoAdminRequired]
	public function update(int $id, string $taskId): JSONResponse {
		return $this->ok($this->tasks->update($this->uid(), $id, $taskId, $this->input()));
	}

	#[NoAdminRequired]
	public function destroy(int $id, string $taskId): JSONResponse {
		return $this->ok($this->tasks->delete($this->uid(), $id, $taskId));
	}

	#[NoAdminRequired]
	public function createSubtask(int $id, string $taskId): JSONResponse {
		return $this->ok($this->tasks->createSubtask($this->uid(), $id, $taskId, $this->input()));
	}

	#[NoAdminRequired]
	public function updateSubtask(int $id, string $taskId, string $subtaskId): JSONResponse {
		return $this->ok($this->tasks->updateSubtask($this->uid(), $id, $taskId, $subtaskId, $this->input()));
	}

	#[NoAdminRequired]
	public function destroySubtask(int $id, string $taskId, string $subtaskId): JSONResponse {
		return $this->ok($this->tasks->deleteSubtask($this->uid(), $id, $taskId, $subtaskId));
	}
}
