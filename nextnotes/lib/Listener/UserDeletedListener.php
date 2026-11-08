<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Listener;

use OCA\NextNotes\AppInfo\Application;
use OCA\NextNotes\Service\UserDataService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Notification\IManager as INotificationManager;
use OCP\User\Events\UserDeletedEvent;
use Psr\Log\LoggerInterface;

/** @template-implements IEventListener<UserDeletedEvent> */
class UserDeletedListener implements IEventListener {
	public function __construct(
		private UserDataService $userData,
		private LoggerInterface $logger,
		private INotificationManager $notifications,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof UserDeletedEvent) {
			return;
		}
		try {
			$this->userData->neutralize($event->getUid(), $event->getUser()->getDisplayName());
		} catch (\Throwable $e) {
			$this->logger->error('nextnotes: neutralizing data of deleted user failed', ['uid' => $event->getUid(), 'exception' => $e]);
			throw $e;
		}
		// Eigene Benachrichtigungen der App (Empfänger = gelöschtes Konto) entfernen; Fehler stoppen die Löschung nicht
		try {
			$n = $this->notifications->createNotification();
			$n->setApp(Application::APP_ID)->setUser($event->getUid());
			$this->notifications->markProcessed($n);
		} catch (\Throwable $e) {
			$this->logger->error('nextnotes: removing notifications of deleted user failed', ['uid' => $event->getUid(), 'exception' => $e]);
		}
	}
}
