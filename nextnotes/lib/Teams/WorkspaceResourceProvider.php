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

// Team-Workspaces und „Aufgaben“ als Ressourcen des Teams (Plan M5 §2.3, M6i); Mitgliedschaft prüft der Kern, die Probe-Regel prüfen wir
class WorkspaceResourceProvider implements ITeamResourceProvider {
	// Ressource „Aufgaben“ je Team (M6i): ID-Präfix + Team-ID, Symbol lucide graduation-cap (ISC) in Textfarbe
	private const ASSIGNMENTS = 'assignments:';
	private const ASSIGNMENTS_ICON = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.42 10.922a1 1 0 0 0-.019-1.838L12.83 5.18a2 2 0 0 0-1.66 0L2.6 9.08a1 1 0 0 0 0 1.832l8.57 3.908a2 2 0 0 0 1.66 0z"/><path d="M22 10v6"/><path d="M6 12.5V16a6 3 0 0 0 12 0v-3.5"/></svg>';
	private ?string $icon = null;

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

	// App-Icon in Textfarbe (hell/dunkel); dasselbe Icon an jedem Workspace statt des allgemeinen Dokument-Icons
	public function getIconSvg(): string {
		if ($this->icon === null) {
			$svg = @file_get_contents($this->appManager->getAppPath(Application::APP_ID) . '/img/app-dark.svg');
			$this->icon = is_string($svg) ? str_replace('fill="#000"', 'fill="currentColor"', $svg) : '';
		}
		return $this->icon;
	}

	/** @return TeamResource[] */
	public function getSharedWith(string $teamId): array {
		if (!$this->teams->isNormalTeam($teamId) || $this->teamState->stateOf($teamId) !== TeamStateService::ACTIVE) {
			return [];
		}
		$list = $this->workspaces->findForTeam($teamId);
		if ($list === []) {
			return [];
		}
		$team = $this->teamState->snapshotName($teamId) ?? $teamId;
		$urls = fn (string $route, Workspace $ws) => $this->urls->linkToRouteAbsolute($route) . '?'
			// Langform „Name (Team, #id)“ löst resolveKey für jedes Mitglied auf (SPEC §4.4)
			. http_build_query(['workspace' => $ws->getName() . ' (' . $team . ', #' . $ws->getId() . ')']);
		$icon = $this->getIconSvg();
		$out = array_map(fn (Workspace $ws) => new TeamResource(
			$this,
			(string)$ws->getId(),
			(string)$ws->getName(),
			$urls('nextnotes.page.indexphp', $ws),
			$icon !== '' ? $icon : null,
		), $list);
		// M6i: Aufgaben des Teams im ersten Team-Workspace (gleiche Regel wie WorkspaceService::assignmentsKey)
		$out[] = new TeamResource($this, self::ASSIGNMENTS . $teamId, $this->l->t('Assignments'), $urls('nextnotes.page.assignments', $list[0]), self::ASSIGNMENTS_ICON);
		return $out;
	}

	public function isSharedWithTeam(string $teamId, string $resourceId): bool {
		return in_array($teamId, $this->getTeamsForResource($resourceId), true);
	}

	/** @return string[] */
	public function getTeamsForResource(string $resourceId): array {
		if (str_starts_with($resourceId, self::ASSIGNMENTS)) {
			$teamId = substr($resourceId, strlen(self::ASSIGNMENTS));
			return $teamId !== '' && $this->workspaces->findForTeam($teamId) !== [] ? [$teamId] : [];
		}
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
