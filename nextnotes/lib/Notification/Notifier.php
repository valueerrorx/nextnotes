<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Notification;

use OCA\NextNotes\AppInfo\Application;
use OCA\NextNotes\Db\NoteMapper;
use OCA\NextNotes\Db\TeamMapper;
use OCA\NextNotes\Db\Workspace;
use OCA\NextNotes\Db\WorkspaceMapper;
use OCA\NextNotes\Service\AccessService;
use OCA\NextNotes\Service\TeamCleanupService;
use OCA\NextNotes\Service\WorkspaceService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Notification\AlreadyProcessedException;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

// Erinnerungen, Erwähnungen und Team-Lebenszyklus in der NC-Glocke (SPEC §6); ohne Leserecht oder Objekt verschwindet die Benachrichtigung
class Notifier implements INotifier {
	public function __construct(
		private IFactory $l10nFactory,
		private IURLGenerator $urls,
		private AccessService $access,
		private NoteMapper $notes,
		private WorkspaceService $workspaces,
		private IUserManager $users,
		private TeamMapper $teams,
		private WorkspaceMapper $workspaceRows,
		private IGroupManager $groups,
	) {
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return $this->l10nFactory->get(Application::APP_ID)->t('Next Notes');
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() === Application::APP_ID && $notification->getObjectType() === 'cleanup' && $notification->getSubject() === 'cleanup_suspicious') {
			return $this->prepareSuspicious($notification, $languageCode);
		}
		if ($notification->getApp() === Application::APP_ID && $notification->getObjectType() === 'cleanup' && $notification->getSubject() === 'cleanup_team_error') {
			return $this->prepareTeamError($notification, $languageCode);
		}
		if ($notification->getApp() === Application::APP_ID && $notification->getObjectType() === 'team'
			&& in_array($notification->getSubject(), ['team_orphaned', 'team_deletion_soon'], true)) {
			return $this->prepareTeam($notification, $languageCode);
		}
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

	// Nur solange das Team verwaist ist und der Empfänger noch created_by eines Team-Workspace ist (umgewandelt/gelöscht → weg)
	private function prepareTeam(INotification $notification, string $languageCode): INotification {
		$uid = $notification->getUser();
		$teamId = $notification->getObjectId();
		$team = $this->teams->findByTeamId($teamId);
		$own = array_values(array_filter($this->workspaceRows->findForTeam($teamId), static fn (Workspace $w) => $w->getCreatedBy() === $uid));
		if ($team === null || $team->getState() !== 'orphaned' || $team->getOrphanedAt() === null || $own === []) {
			throw new AlreadyProcessedException();
		}
		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		$name = trim((string)($notification->getSubjectParameters()['team'] ?? '')) ?: trim((string)$team->getTeamNameSnapshot());
		$date = (string)$l->l('date', TeamCleanupService::deletionDate((int)$team->getOrphanedAt(), $team->getDeletionNotifiedAt()));
		$subject = $notification->getSubject() === 'team_orphaned'
			? $l->t('The team {team} was deleted. Its workspaces will be deleted on {date}.')
			: $l->t('Workspaces of the deleted team {team} will be deleted on {date}.');
		$message = $l->t('Export them or convert them into a private workspace to keep the notes.');
		$key = $this->workspaces->keyFor($uid, (int)$own[0]->getId());
		$link = $this->urls->linkToRouteAbsolute('nextnotes.page.indexphp') . ($key !== null ? '?' . http_build_query(['workspace' => $key]) : '');
		$teamParam = ['type' => 'highlight', 'id' => $teamId, 'name' => $name !== '' ? $name : $l->t('Team')];
		$dateParam = ['type' => 'highlight', 'id' => 'deletion-date', 'name' => $date];
		return $notification->setParsedSubject(str_replace(['{team}', '{date}'], [$teamParam['name'], $date], $subject))
			->setRichSubject($subject, ['team' => $teamParam, 'date' => $dateParam])
			->setParsedMessage($message)
			->setLink($link)
			->setIcon($this->urls->getAbsoluteURL($this->urls->imagePath(Application::APP_ID, 'app-dark.svg')));
	}

	// Massenschutz des CleanupJob (Plan M5 §2.5): nur für Admins
	private function prepareSuspicious(INotification $notification, string $languageCode): INotification {
		if (!$this->groups->isAdmin($notification->getUser())) {
			throw new AlreadyProcessedException();
		}
		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		$params = $notification->getSubjectParameters();
		$missing = (string)(int)($params['missing'] ?? 0);
		$checked = (string)(int)($params['checked'] ?? 0);
		$subject = $l->t('{missing} of {checked} teams were not found. No team was marked as deleted.');
		return $notification->setParsedSubject(str_replace(['{missing}', '{checked}'], [$missing, $checked], $subject))
			->setRichSubject($subject, [
				'missing' => ['type' => 'highlight', 'id' => 'missing', 'name' => $missing],
				'checked' => ['type' => 'highlight', 'id' => 'checked', 'name' => $checked],
			])
			->setParsedMessage($l->t('Check the Teams app with "occ circles:check" and "occ nextnotes:team:orphan-check --dry-run". If the teams really were deleted, run "occ nextnotes:team:orphan-check --force".'))
			->setIcon($this->urls->getAbsoluteURL($this->urls->imagePath(Application::APP_ID, 'app-dark.svg')));
	}

	// Team wiederholt nicht prüfbar (H1): nur für Admins
	private function prepareTeamError(INotification $notification, string $languageCode): INotification {
		if (!$this->groups->isAdmin($notification->getUser())) {
			throw new AlreadyProcessedException();
		}
		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		$params = $notification->getSubjectParameters();
		$id = (string)($params['team'] ?? '');
		$name = trim((string)($params['name'] ?? ''));
		$label = $name !== '' ? $name . ' (' . $id . ')' : $id;
		$runs = (string)(int)($params['runs'] ?? 0);
		$subject = $l->t('The team {team} could not be checked in {runs} cleanup runs in a row.');
		return $notification->setParsedSubject(str_replace(['{team}', '{runs}'], [$label, $runs], $subject))
			->setRichSubject($subject, [
				'team' => ['type' => 'highlight', 'id' => $id !== '' ? $id : 'team', 'name' => $label],
				'runs' => ['type' => 'highlight', 'id' => 'runs', 'name' => $runs],
			])
			->setParsedMessage($l->t('Next Notes skips this team in the automatic team cleanup. Check "occ circles:check" and "occ nextnotes:team:orphan-check --dry-run".'))
			->setIcon($this->urls->getAbsoluteURL($this->urls->imagePath(Application::APP_ID, 'app-dark.svg')));
	}
}
