<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Listener;

use OCA\NextNotes\Service\UserDataService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\BeforeUserDeletedEvent;

// Teams merken, solange die Mitgliedschaft noch besteht (Kontolöschung räumt Hintergründe nur dort, R15); wirft nie
/** @template-implements IEventListener<BeforeUserDeletedEvent> */
class BeforeUserDeletedListener implements IEventListener {
	public function __construct(
		private UserDataService $userData,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof BeforeUserDeletedEvent) {
			return;
		}
		$this->userData->rememberMemberships($event->getUser()->getUID());
	}
}
