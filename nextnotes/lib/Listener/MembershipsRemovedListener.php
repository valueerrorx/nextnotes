<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Listener;

use OCA\NextNotes\Service\TeamLifecycleService;
use OCA\NextNotes\Service\TeamService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

// MembershipsRemovedEvent (direkt und geerbt, synchron in Circles' manage); wirft nie, je Mitgliedschaft getrennt
/** @template-implements IEventListener<Event> */
class MembershipsRemovedListener implements IEventListener {
	public function __construct(
		private TeamService $teams,
		private TeamLifecycleService $lifecycle,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		try {
			$seen = [];
			$relevant = [];
			foreach ($this->teams->removedMemberships($event) as $m) {
				if (isset($seen[$m['team'] . '|' . $m['single']])) {
					continue;
				}
				$seen[$m['team'] . '|' . $m['single']] = true;
				try {
					// nur Teams mit Team-Workspaces oder Aufgaben: keine Circles-Abfrage je fremder Mitgliedschaft
					if (!($relevant[$m['team']] ??= $this->lifecycle->isRelevant($m['team']))) {
						continue;
					}
					$uid = $this->teams->uidForSingleId($m['single']);
					if ($uid !== null) {
						$this->onLeft($m['team'], $uid);
					}
				} catch (\Throwable $e) {
					$this->logger->error('nextnotes: handling team leave failed', ['team' => $m['team'], 'exception' => $e]);
				}
			}
		} catch (\Throwable $e) {
			$this->logger->error('nextnotes: handling team leave failed', ['exception' => $e]);
		}
	}

	// Austritt: Team-Workspaces aufräumen, Austrittsmarke der Abgaben, Übergang owner_uid (M6f)
	public function onLeft(string $teamId, string $uid): void {
		$this->lifecycle->memberLeft($teamId, $uid);
	}
}
