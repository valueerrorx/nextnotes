<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Notification;

use OCA\NextNotes\AppInfo\Application;
use OCA\NextNotes\Db\NoteMapper;
use OCA\NextNotes\Service\AccessService;
use OCA\NextNotes\Service\WorkspaceService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Notification\AlreadyProcessedException;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

// Erinnerungen und Erwähnungen in der NC-Glocke (SPEC §6); ohne Leserecht oder Notiz verschwindet die Benachrichtigung
class Notifier implements INotifier {
	public function __construct(
		private IFactory $l10nFactory,
		private IURLGenerator $urls,
		private AccessService $access,
		private NoteMapper $notes,
		private WorkspaceService $workspaces,
		private IUserManager $users,
	) {
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return $this->l10nFactory->get(Application::APP_ID)->t('Next Notes');
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID || $notification->getObjectType() !== 'note'
			|| !in_array($notification->getSubject(), ['reminder', 'task_reminder', 'mention'], true)) {
			throw new UnknownNotificationException();
		}
		$uid = $notification->getUser();
		$noteId = (int)$notification->getObjectId();
		$this->access->resetCache();
		if (!$this->access->canRead($uid, $noteId)) {
			throw new AlreadyProcessedException();
		}
		try {
			$note = $this->notes->find($noteId);
		} catch (DoesNotExistException) {
			throw new AlreadyProcessedException();
		}
		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		$heading = trim((string)$note->getHeading()) !== '' ? (string)$note->getHeading() : $l->t('Untitled note');
		$params = ['note' => (int)$noteId];
		$key = $this->workspaces->keyFor($uid, (int)$note->getWorkspaceId());
		if ($key !== null) {
			$params['workspace'] = $key;
		}
		$link = $this->urls->linkToRouteAbsolute('nextnotes.page.indexphp') . '?' . http_build_query($params);
		$noteParam = ['type' => 'highlight', 'id' => (string)$noteId, 'name' => $heading, 'link' => $link];
		$icon = $this->urls->getAbsoluteURL($this->urls->imagePath(Application::APP_ID, 'app-dark.svg'));
		if ($notification->getSubject() === 'mention') {
			$by = (string)($notification->getSubjectParameters()['by'] ?? '');
			$byName = trim((string)($notification->getSubjectParameters()['byName'] ?? ''));
			// Konto gelöscht: beim Senden gespeicherter Anzeigename (Thomas, M4i), nie die UID (SPEC §2 UID-Regel)
			if (($by === '' || !$this->users->userExists($by)) && $byName !== '') {
				$subject = $l->t('{user} mentioned you in {note}');
				return $notification->setParsedSubject(str_replace(['{user}', '{note}'], [$byName, $heading], $subject))
					->setRichSubject($subject, ['user' => ['type' => 'highlight', 'id' => 'mention-author', 'name' => $byName], 'note' => $noteParam])
					->setLink($link)
					->setIcon($icon);
			}
			// Altbestand ohne gespeicherten Namen: neutraler Text
			if ($by === '' || !$this->users->userExists($by)) {
				$subject = $l->t('Someone mentioned you in {note}');
				return $notification->setParsedSubject(str_replace('{note}', $heading, $subject))
					->setRichSubject($subject, ['note' => $noteParam])
					->setLink($link)
					->setIcon($icon);
			}
			$name = $this->users->getDisplayName($by) ?? $by;
			$subject = $l->t('{user} mentioned you in {note}');
			return $notification->setParsedSubject(str_replace(['{user}', '{note}'], [$name, $heading], $subject))
				->setRichSubject($subject, ['user' => ['type' => 'user', 'id' => $by, 'name' => $name], 'note' => $noteParam])
				->setLink($link)
				->setIcon($icon);
		}
		$task = $notification->getSubject() === 'task_reminder';
		$subject = $task ? $l->t('Task reminder in {note}') : $l->t('Reminder: {note}');
		$notification->setParsedSubject(str_replace('{note}', $heading, $subject))
			->setRichSubject($subject, ['note' => $noteParam])
			->setLink($link)
			->setIcon($icon);
		$message = trim((string)($notification->getSubjectParameters()['message'] ?? ''));
		if ($message !== '' && $message !== $heading) {
			$notification->setParsedMessage($message);
		}
		return $notification;
	}
}
