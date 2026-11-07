<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Controller;

use OCA\NextNotes\Service\Exception\BadRequestException;
use OCA\NextNotes\Service\TeamLifecycleService;
use OCA\NextNotes\Service\WorkspaceDeletion;
use OCA\NextNotes\Service\WorkspaceService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

class WorkspacesApiController extends ApiController {
	public function __construct(IRequest $request, IUserSession $userSession, WorkspaceService $workspaces, private WorkspaceDeletion $deletion, private TeamLifecycleService $lifecycle) {
		parent::__construct($request, $userSession, $workspaces);
	}

	#[NoAdminRequired]
	public function index(): JSONResponse {
		$uid = $this->uid();
		return $this->ok(['workspaces' => $this->workspaces->index($uid), 'username' => $uid, 'acting_as' => null]);
	}

	#[NoAdminRequired]
	public function store(): JSONResponse {
		$in = $this->input();
		if (trim((string)($in['name'] ?? '')) === '') {
			throw new BadRequestException('name is required');
		}
		$uid = $this->uid();
		[$ws, $tags] = $this->workspaces->create($uid, $in['name'], $in['tags'] ?? [], array_key_exists('color', $in), $in['color'] ?? null, $in['team'] ?? null);
		// name = Workspace-Schlüssel (Team-Workspace bei Namensgleichheit „Name (Team)“, SPEC §4.4)
		return $this->ok(['name' => $this->workspaces->keyFor($uid, (int)$ws->getId()) ?? $ws->getName(), 'tags' => $tags, 'color' => $ws->getColor()], 201);
	}

	#[NoAdminRequired]
	public function update(string $name): JSONResponse {
		$in = $this->input();
		unset($in['name']);
		return $this->ok($this->workspaces->update($this->uid(), $name, $in));
	}

	#[NoAdminRequired]
	public function destroy(string $name): JSONResponse {
		$in = $this->input();
		$this->deletion->delete($this->uid(), $name, $in['target'] ?? null, in_array($in['confirm'] ?? null, [true, 1, '1', 'true'], true));
		return $this->ok();
	}

	// Verwaisten Team-Workspace in privaten umwandeln (nur created_by, Plan M5 §2.4)
	#[NoAdminRequired]
	public function makePrivate(string $name): JSONResponse {
		return $this->ok(['name' => $this->lifecycle->makePrivate($this->uid(), $name)]);
	}
}
