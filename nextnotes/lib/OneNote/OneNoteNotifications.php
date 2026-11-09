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

// Benachrichtigungen des OneNote-Imports (Plan M9 §2, Objekttyp onenote_import): done (mit Zählern), reauth, failed
class OneNoteNotifications {
	public const TYPE = 'onenote_import';
	public const FAILED = 'onenote_failed';
	public const REAUTH = 'onenote_reauth';
	public const DONE = 'onenote_done';

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

	/** @param array<string, int> $counts nur bei done */
	public function send(string $uid, int $importId, string $subject, string $errorCode, array $counts = []): void {
		try {
			$n = $this->notifications->createNotification();
			$n->setApp(Application::APP_ID)
				->setUser($uid)
				->setDateTime((new \DateTime())->setTimestamp($this->time->getTime()))
				->setObject(self::TYPE, (string)$importId)
				->setSubject($subject, ['reason' => $errorCode] + $counts);
			$this->notifications->notify($n);
		} catch (\Throwable $e) {
			$this->logger->error('nextnotes: notification ' . $subject . ' not sent', ['exception' => $e, 'app' => Application::APP_ID]);
		}
	}

	public function handles(INotification $n): bool {
		return $n->getApp() === Application::APP_ID && $n->getObjectType() === self::TYPE
			&& in_array($n->getSubject(), [self::FAILED, self::REAUTH, self::DONE], true);
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
		$p = $n->getSubjectParameters();
		$reason = (string)($p['reason'] ?? '');
		$subject = match ($n->getSubject()) {
			self::REAUTH => $l->t('OneNote import paused: sign in to Microsoft again'),
			self::DONE => $l->t('OneNote import finished'),
			default => $l->t('OneNote import stopped'),
		};
		$n->setParsedSubject($subject)
			->setLink($this->urls->linkToRouteAbsolute('nextnotes.one_note_api.page') . '?' . http_build_query(['import' => (int)$n->getObjectId()]))
			->setIcon($this->urls->getAbsoluteURL($this->urls->imagePath(Application::APP_ID, 'app-dark.svg')));
		$message = $n->getSubject() === self::DONE
			? $l->t('%1$d pages imported, %2$d skipped, %3$d not imported (see report).', [(int)($p['done'] ?? 0), (int)($p['skipped'] ?? 0), (int)($p['reported'] ?? 0)])
			: OneNoteMessages::forError($reason, $l);
		if ($message !== '') {
			$n->setParsedMessage($message);
		}
		return $n;
	}
}
