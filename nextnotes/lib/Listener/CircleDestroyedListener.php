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

// DestroyingCircleEvent (manage, auch synchron in CLI) und CircleDestroyedEvent (Loopback); wirft nie, Rückfall CleanupJob (M5c)
/** @template-implements IEventListener<Event> */
class CircleDestroyedListener implements IEventListener {
	public function __construct(
		private TeamService $teams,
		private TeamLifecycleService $lifecycle,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		try {
			$team = $this->teams->destroyedTeam($event);
			if ($team !== null) {
				$this->lifecycle->teamDeleted($team['id'], $team['name']);
			}
		} catch (\Throwable $e) {
			$this->logger->error('nextnotes: handling team deletion failed', ['exception' => $e]);
		}
	}
}
