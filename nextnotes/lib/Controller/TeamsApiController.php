<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Controller;

use OCA\NextNotes\Service\TeamService;
use OCA\NextNotes\Service\TeamStateService;
use OCA\NextNotes\Service\WorkspaceService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

// GET /api/v1/teams (Plan M5 §2.3): Teams für „Neuer Workspace“ (aktiv, Level ≥ 4); ?all=1 alle Mitgliedschaften; nur Teams nach Probe-Regel
class TeamsApiController extends ApiController {
	private const MANAGE_LEVEL = 4;

	public function __construct(IRequest $request, IUserSession $userSession, WorkspaceService $workspaces,
		private TeamService $teams, private TeamStateService $teamState) {
		parent::__construct($request, $userSession, $workspaces);
	}

	#[NoAdminRequired]
	public function index(): JSONResponse {
		$uid = $this->uid();
		$all = $this->param('all') === '1';
		$out = [];
		foreach ($this->teams->memberTeams($uid) as $id => $name) {
			$id = (string)$id;
			if (!$all && ($this->teamState->stateOf($id) !== TeamStateService::ACTIVE || $this->teams->getLevel($id, $uid) < self::MANAGE_LEVEL)) {
				continue;
			}
			$out[] = ['id' => $id, 'name' => $name];
		}
		usort($out, static fn (array $a, array $b) => strnatcasecmp($a['name'], $b['name']) ?: strcmp($a['id'], $b['id']));
		return $this->ok(['teams' => $out]);
	}
}
