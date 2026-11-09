<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\OneNote;

use OCA\NextNotes\AppInfo\Application;
use OCA\NextNotes\Db\OneNoteImportMapper;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Notification\AlreadyProcessedException;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use Psr\Log\LoggerInterface;

// Benachrichtigungen des OneNote-Imports (Plan M9 §2, Objekttyp onenote_import); onenote_done folgt in M9b
class OneNoteNotifications {
	public const TYPE = 'onenote_import';
	public const FAILED = 'onenote_failed';
	public const REAUTH = 'onenote_reauth';

	public function __construct(
		private INotificationManager $notifications,
		private ITimeFactory $time,
		private LoggerInterface $logger,
		private OneNoteImportMapper $imports,
		private IUserManager $users,
		private IAppManager $apps,
		private IFactory $l10nFactory,
		private IURLGenerator $urls,
	) {
	}

	public function send(string $uid, int $importId, string $subject, string $errorCode): void {
		try {
			$n = $this->notifications->createNotification();
			$n->setApp(Application::APP_ID)
				->setUser($uid)
				->setDateTime((new \DateTime())->setTimestamp($this->time->getTime()))
				->setObject(self::TYPE, (string)$importId)
				->setSubject($subject, ['reason' => $errorCode]);
			$this->notifications->notify($n);
		} catch (\Throwable $e) {
			$this->logger->error('nextnotes: notification ' . $subject . ' not sent', ['exception' => $e, 'app' => Application::APP_ID]);
		}
	}

	public function handles(INotification $n): bool {
		return $n->getApp() === Application::APP_ID && $n->getObjectType() === self::TYPE
			&& in_array($n->getSubject(), [self::FAILED, self::REAUTH], true);
	}

	// verwirft die Meldung, wenn der Import fehlt oder der Benutzer keinen App-Zugriff hat
	public function prepare(INotification $n, string $languageCode): INotification {
		$uid = $n->getUser();
		$user = $this->users->get($uid);
		if ($user === null || !$this->apps->isEnabledForUser(Application::APP_ID, $user)) {
			throw new AlreadyProcessedException();
		}
		try {
			$this->imports->findOwn((int)$n->getObjectId(), $uid);
		} catch (DoesNotExistException) {
			throw new AlreadyProcessedException();
		}
		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		$reason = (string)($n->getSubjectParameters()['reason'] ?? '');
		$subject = $n->getSubject() === self::REAUTH
			? $l->t('OneNote import paused: sign in to Microsoft again')
			: $l->t('OneNote import stopped');
		$n->setParsedSubject($subject)
			->setLink($this->urls->linkToRouteAbsolute('nextnotes.one_note_api.page') . '?' . http_build_query(['import' => (int)$n->getObjectId()]))
			->setIcon($this->urls->getAbsoluteURL($this->urls->imagePath(Application::APP_ID, 'app-dark.svg')));
		$message = OneNoteMessages::forError($reason, $l);
		if ($message !== '') {
			$n->setParsedMessage($message);
		}
		return $n;
	}
}
