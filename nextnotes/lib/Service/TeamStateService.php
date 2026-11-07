<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service;

use OCA\NextNotes\Db\TeamMapper;
use OCP\AppFramework\Utility\ITimeFactory;

// Team-Zustand (SPEC §3): frozen berechnet, active/orphaned aus nextnotes_teams (fehlt die Zeile: active)
class TeamStateService {
	public const ACTIVE = 'active';
	public const ORPHANED = 'orphaned';
	public const FROZEN = 'frozen';

	/** @var array<string, string> teamId → gespeicherter Zustand für diesen Request */
	private array $stored = [];

	public function __construct(
		private TeamMapper $teams,
		private TeamService $teamService,
		private ITimeFactory $time,
	) {
	}

	public function stateOf(string $teamId): string {
		if (!$this->teamService->isAvailable()) {
			return self::FROZEN;
		}
		return $this->stored[$teamId] ??= $this->teams->findByTeamId($teamId)?->getState() === self::ORPHANED ? self::ORPHANED : self::ACTIVE;
	}

	// Erste Nutzung des Teams (Team-Workspace anlegen): Zeile anlegen, Name-Snapshot setzen
	public function ensure(string $teamId, ?string $name): void {
		$now = $this->time->getTime();
		$this->teams->insertIgnore($teamId, $name, $now);
		if ($name !== null && $name !== '') {
			$this->teams->updateSnapshot($teamId, $name, $now);
		}
	}

	// Snapshot nur beim Anlegen, Umbenennen und im CleanupJob, nie bei GET (SPEC §2.1)
	public function snapshotName(string $teamId): ?string {
		return $this->teams->findByTeamId($teamId)?->getTeamNameSnapshot();
	}

	public function resetCache(): void {
		$this->stored = [];
	}
}
