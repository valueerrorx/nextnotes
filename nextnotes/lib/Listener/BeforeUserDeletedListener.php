<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Listener;

use OCA\NextNotes\Service\TeamService;
use OCA\NextNotes\Service\UserDataService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\BeforeUserDeletedEvent;
use Psr\Log\LoggerInterface;

// Teams merken, solange die Mitgliedschaft noch besteht (Kontolöschung räumt Hintergründe nur dort, R15); wirft nie
/** @template-implements IEventListener<BeforeUserDeletedEvent> */
class BeforeUserDeletedListener implements IEventListener {
	public function __construct(
		private TeamService $teams,
		private UserDataService $userData,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof BeforeUserDeletedEvent) {
			return;
		}
		try {
			$uid = $event->getUser()->getUID();
			$this->teams->resetCache();
			$this->userData->rememberTeams($uid, array_map('strval', array_keys($this->teams->memberTeams($uid))));
		} catch (\Throwable $e) {
			$this->logger->error('nextnotes: reading teams of deleted user failed', ['exception' => $e]);
		}
	}
}
