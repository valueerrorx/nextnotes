<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Teams;

use OCA\NextNotes\AppInfo\Application;
use OCA\NextNotes\Db\Workspace;
use OCA\NextNotes\Db\WorkspaceMapper;
use OCA\NextNotes\Service\TeamService;
use OCA\NextNotes\Service\TeamStateService;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Teams\ITeamResourceProvider;
use OCP\Teams\TeamResource;

// Team-Workspaces als Ressourcen des Teams (Plan M5 §2.3); Mitgliedschaft prüft der Kern, die Probe-Regel prüfen wir
class WorkspaceResourceProvider implements ITeamResourceProvider {
	public function __construct(
		private WorkspaceMapper $workspaces,
		private TeamService $teams,
		private TeamStateService $teamState,
		private IURLGenerator $urls,
		private IAppManager $appManager,
		private IL10N $l,
	) {
	}

	public function getId(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return $this->l->t('Next Notes');
	}

	public function getIconSvg(): string {
		$svg = @file_get_contents($this->appManager->getAppPath(Application::APP_ID) . '/img/app-dark.svg');
		return is_string($svg) ? $svg : '';
	}

	/** @return TeamResource[] */
	public function getSharedWith(string $teamId): array {
		if (!$this->teams->isNormalTeam($teamId) || $this->teamState->stateOf($teamId) !== TeamStateService::ACTIVE) {
			return [];
		}
		$team = $this->teamState->snapshotName($teamId) ?? $teamId;
		$base = $this->urls->linkToRouteAbsolute('nextnotes.page.indexphp');
		return array_map(fn (Workspace $ws) => new TeamResource(
			$this,
			(string)$ws->getId(),
			(string)$ws->getName(),
			// Langform „Name (Team, #id)“ löst resolveKey für jedes Mitglied auf (SPEC §4.4)
			$base . '?' . http_build_query(['workspace' => $ws->getName() . ' (' . $team . ', #' . $ws->getId() . ')']),
		), $this->workspaces->findForTeam($teamId));
	}

	public function isSharedWithTeam(string $teamId, string $resourceId): bool {
		$ws = $this->find($resourceId);
		return $ws !== null && $ws->getOwnerId() === $teamId;
	}

	/** @return string[] */
	public function getTeamsForResource(string $resourceId): array {
		$ws = $this->find($resourceId);
		return $ws === null ? [] : [(string)$ws->getOwnerId()];
	}

	private function find(string $resourceId): ?Workspace {
		if (!ctype_digit($resourceId)) {
			return null;
		}
		try {
			$ws = $this->workspaces->find((int)$resourceId);
		} catch (DoesNotExistException) {
			return null;
		}
		return $ws->getOwnerType() === 'team' && $ws->getKind() === 'normal' ? $ws : null;
	}
}
