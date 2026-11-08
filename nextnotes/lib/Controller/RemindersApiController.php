<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Controller;

use OCA\NextNotes\Service\ReminderService;
use OCA\NextNotes\Service\WorkspaceService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

// Upstream RemindersController; /reminders* nur Kompatibilität, Anzeige über die NC-Glocke (SPEC §4.2, §6)
class RemindersApiController extends ApiController {
	public function __construct(IRequest $request, IUserSession $userSession, WorkspaceService $workspaces, private ReminderService $reminders) {
		parent::__construct($request, $userSession, $workspaces);
	}

	#[NoAdminRequired]
	public function show(int $id): JSONResponse {
		return $this->ok($this->reminders->get($this->uid(), $id));
	}

	#[NoAdminRequired]
	public function store(int $id): JSONResponse {
		return $this->ok($this->reminders->set($this->uid(), $id, $this->input(), false));
	}

	#[NoAdminRequired]
	public function showTask(int $id): JSONResponse {
		return $this->ok($this->reminders->getTask($this->uid(), $id, $this->param('task_id')));
	}

	#[NoAdminRequired]
	public function storeTask(int $id): JSONResponse {
		return $this->ok($this->reminders->set($this->uid(), $id, $this->input(), true));
	}

	#[NoAdminRequired]
	public function destroyTask(int $id): JSONResponse {
		$taskId = $this->param('task_id');
		return $this->ok($this->reminders->remove($this->uid(), $id, is_scalar($taskId) ? (string)$taskId : ''));
	}

	#[NoAdminRequired]
	public function destroy(int $id): JSONResponse {
		return $this->ok($this->reminders->remove($this->uid(), $id, null));
	}

	#[NoAdminRequired]
	public function index(): JSONResponse {
		$this->uid();
		return $this->ok(['notifications' => [], 'unread_count' => 0, 'total_count' => 0]);
	}

	#[NoAdminRequired]
	public function count(): JSONResponse {
		$this->uid();
		return $this->ok(['unread_count' => 0, 'total_count' => 0]);
	}

	#[NoAdminRequired]
	public function dismissAll(): JSONResponse {
		$this->uid();
		return $this->ok();
	}

	#[NoAdminRequired]
	public function markRead(int $id): JSONResponse {
		$this->uid();
		return $this->ok(['id' => $id]);
	}

	#[NoAdminRequired]
	public function dismiss(int $id): JSONResponse {
		$this->uid();
		return $this->ok(['id' => $id, 'next_reminder_at' => null]);
	}
}
