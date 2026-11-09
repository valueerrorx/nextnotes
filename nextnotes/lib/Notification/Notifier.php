<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Notification;

use OCA\NextNotes\AppInfo\Application;
use OCA\NextNotes\Db\Assignment;
use OCA\NextNotes\Db\AssignmentMapper;
use OCA\NextNotes\Db\NoteMapper;
use OCA\NextNotes\Db\SubmissionMapper;
use OCA\NextNotes\Db\TeamMapper;
use OCA\NextNotes\Db\Workspace;
use OCA\NextNotes\Db\WorkspaceMapper;
use OCA\NextNotes\OneNote\OneNoteNotifications;
use OCA\NextNotes\Service\AccessService;
use OCA\NextNotes\Service\AssignmentNotifications;
use OCA\NextNotes\Service\TeamCleanupService;
use OCA\NextNotes\Service\WorkspaceService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDateTimeZone;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Notification\AlreadyProcessedException;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

// Erinnerungen, Erwähnungen, Aufgaben und Team-Lebenszyklus in der NC-Glocke (SPEC §6); ohne Leserecht oder Objekt verschwindet die Benachrichtigung
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
		private AssignmentMapper $assignments,
		private SubmissionMapper $submissions,
		private IDateTimeZone $timeZone,
		private ITimeFactory $time,
		private OneNoteNotifications $oneNote,
	) {
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return $this->l10nFactory->get(Application::APP_ID)->t('Next Notes');
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($this->oneNote->handles($notification)) {
			return $this->oneNote->prepare($notification, $languageCode);
		}
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
		if ($notification->getApp() === Application::APP_ID && in_array($notification->getObjectType(), ['assignment', 'submission'], true)) {
			return $this->prepareAssignment($notification, $languageCode);
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

	// Nur solange das Team verwaist ist und der Empfänger noch created_by eines Team-Workspace oder owner_uid einer Aufgabe des Teams ist
	private function prepareTeam(INotification $notification, string $languageCode): INotification {
		$uid = $notification->getUser();
		$teamId = $notification->getObjectId();
		$team = $this->teams->findByTeamId($teamId);
		$own = array_values(array_filter($this->workspaceRows->findForTeam($teamId), static fn (Workspace $w) => $w->getCreatedBy() === $uid));
		$owned = $this->assignments->findOwned($uid, $teamId);
		if ($team === null || $team->getState() !== 'orphaned' || $team->getOrphanedAt() === null || ($own === [] && $owned === [])) {
			throw new AlreadyProcessedException();
		}
		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		$name = trim((string)($notification->getSubjectParameters()['team'] ?? '')) ?: trim((string)$team->getTeamNameSnapshot());
		$date = (string)$l->l('date', TeamCleanupService::deletionDate((int)$team->getOrphanedAt(), $team->getDeletionNotifiedAt()));
		$soon = $notification->getSubject() !== 'team_orphaned';
		if ($own !== []) {
			$subject = $soon
				? $l->t('Workspaces of the deleted team {team} will be deleted on {date}.')
				: $l->t('The team {team} was deleted. Its workspaces will be deleted on {date}.');
			$message = $l->t('Export them or convert them into a private workspace to keep the notes.');
		} else {
			$subject = $soon
				? $l->t('Assignments of the deleted team {team} will be deleted on {date}.')
				: $l->t('The team {team} was deleted. Its assignments will be deleted on {date}.');
			$message = '';
		}
		// eigene Aufgaben mit Abgaben (live): Export-Hinweis, Abgaben gehen mit dem Team
		$withSubmissions = $owned === [] ? 0 : $this->assignments->countOwnedWithSubmissions($teamId, $uid);
		if ($withSubmissions > 0) {
			$message = trim($message . ' ' . $l->n('%n of your assignments has submissions; export them before that date.', '%n of your assignments have submissions; export them before that date.', $withSubmissions));
		}
		$key = $own === [] ? null : $this->workspaces->keyFor($uid, (int)$own[0]->getId());
		// nur Aufgaben: Aufgaben-Seite (Export in der Frist, M6g); ohne lesbaren Team-Workspace im eigenen unter „ohne Team-Workspace“ (M6i)
		$asgKey = $this->workspaces->assignmentsKey($uid, $teamId);
		$link = $key !== null ? $this->urls->linkToRouteAbsolute('nextnotes.page.indexphp') . '?' . http_build_query(['workspace' => $key])
			: $this->urls->linkToRouteAbsolute('nextnotes.page.assignments') . ($asgKey !== null ? '?' . http_build_query(['workspace' => $asgKey]) : '');
		$teamParam = ['type' => 'highlight', 'id' => $teamId, 'name' => $name !== '' ? $name : $l->t('Team')];
		$dateParam = ['type' => 'highlight', 'id' => 'deletion-date', 'name' => $date];
		$notification->setParsedSubject(str_replace(['{team}', '{date}'], [$teamParam['name'], $date], $subject))
			->setRichSubject($subject, ['team' => $teamParam, 'date' => $dateParam])
			->setLink($link)
			->setIcon($this->urls->getAbsoluteURL($this->urls->imagePath(Application::APP_ID, 'app-dark.svg')));
		if ($message !== '') {
			$notification->setParsedMessage($message);
		}
		return $notification;
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

	// Aufgaben (SPEC §6, Plan M6 §2.4): Schüler nur zur eigenen Abgabe, Sammelmeldung nur mit review; Objekt gelöscht oder kein Recht → weg
	private function prepareAssignment(INotification $notification, string $languageCode): INotification {
		$uid = $notification->getUser();
		$subject = $notification->getSubject();
		$id = (int)$notification->getObjectId();
		$this->access->resetCache();
		$student = [AssignmentNotifications::ASSIGNED => 'assignment', AssignmentNotifications::RETURNED => 'submission', AssignmentNotifications::REOPENED => 'submission'];
		if (isset($student[$subject]) && $student[$subject] === $notification->getObjectType()) {
			if ($notification->getObjectType() === 'assignment') {
				$sub = $this->submissions->findOwn($uid, [$id])[$id] ?? null;
			} else {
				try {
					$sub = $this->submissions->find($id);
				} catch (DoesNotExistException) {
					$sub = null;
				}
			}
			$a = $sub === null ? null : $this->assignmentOrNull((int)$sub->getAssignmentId());
			// nur eigene, erreichbare Abgabe: kein Weg über review oder die Aufgabe (Invariante SPEC §3); nach Austritt weg (M7h)
			if ($sub === null || $a === null || $a->getStatus() === 'draft' || !$this->access->studentAccess($uid, $sub, $a)) {
				throw new AlreadyProcessedException();
			}
			return $this->studentNotice($notification, $languageCode, $a, $sub->getNoteId() === null ? null : (int)$sub->getNoteId(), (int)$sub->getId());
		}
		if ($subject === AssignmentNotifications::DEADLINE_PASSED && $notification->getObjectType() === 'assignment') {
			$a = $this->assignmentOrNull($id);
			// Frist inzwischen verlängert (Rennen Job ↔ Verlängerung): alte Sammelmeldung gilt nicht mehr
			if ($a === null || !$this->access->assignmentAllows($uid, $a, 'review')
				|| (int)$a->getDueAt() > $this->time->getTime() || $a->getDeadlineNotifiedAt() === null) {
				throw new AlreadyProcessedException();
			}
			return $this->deadlineNotice($notification, $languageCode, $a);
		}
		if ($subject === AssignmentNotifications::TRANSFERRED && $notification->getObjectType() === 'assignment') {
			$a = $this->assignmentOrNull($id);
			// nur solange der Empfänger verantwortlich ist
			if ($a === null || $a->getOwnerUid() !== $uid) {
				throw new AlreadyProcessedException();
			}
			return $this->transferNotice($notification, $languageCode, $a);
		}
		throw new UnknownNotificationException();
	}

	private function studentNotice(INotification $notification, string $languageCode, Assignment $a, ?int $noteId, int $submissionId): INotification {
		$uid = $notification->getUser();
		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		$params = $notification->getSubjectParameters();
		$link = $this->urls->linkToRouteAbsolute('nextnotes.page.indexphp');
		if ($noteId !== null) {
			try {
				$note = $this->notes->find($noteId);
				$key = $note->getWorkspaceId() === null ? null : $this->workspaces->keyFor($uid, (int)$note->getWorkspaceId());
				$link .= '?' . http_build_query(($key !== null ? ['workspace' => $key] : []) + ['note' => $noteId]);
			} catch (DoesNotExistException) {
			}
		}
		$title = $this->title($a, $l);
		$rich = ['assignment' => ['type' => 'highlight', 'id' => (string)$a->getId(), 'name' => $title, 'link' => $link]];
		$replace = ['{assignment}' => $title];
		$message = null;
		switch ($notification->getSubject()) {
			case AssignmentNotifications::ASSIGNED:
				$text = $l->t('New assignment: {assignment}');
				$message = $l->t('Due on %s', [$this->dateTime($l, (int)$a->getDueAt(), $uid)]);
				break;
			case AssignmentNotifications::RETURNED:
				// Q5: Name der Lehrkraft als Snapshot beim Senden, nie die UID
				$by = trim((string)($params['byName'] ?? ''));
				if ($by !== '') {
					$text = $l->t('{user} returned your submission for {assignment}');
					$rich['user'] = ['type' => 'highlight', 'id' => 'teacher-' . $submissionId, 'name' => $by];
					$replace['{user}'] = $by;
				} else {
					$text = $l->t('Your submission for {assignment} was returned');
				}
				break;
			default:
				$text = $l->t('Your submission for {assignment} was reopened');
				$due = $params['due'] ?? null;
				$message = $due === null ? $l->t('No deadline') : $l->t('New deadline: %s', [$this->dateTime($l, (int)$due, $uid)]);
		}
		$notification->setParsedSubject(strtr($text, $replace))
			->setRichSubject($text, $rich)
			->setLink($link)
			->setIcon($this->urls->getAbsoluteURL($this->urls->imagePath(Application::APP_ID, 'app-dark.svg')));
		if ($message !== null) {
			$notification->setParsedMessage($message);
		}
		return $notification;
	}

	// Q4: einzige Meldung an Lehrkräfte zu Abgaben; Zahlen vom Fristende
	private function deadlineNotice(INotification $notification, string $languageCode, Assignment $a): INotification {
		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		$params = $notification->getSubjectParameters();
		$title = $this->title($a, $l);
		$link = $this->assignmentLink($notification->getUser(), $a);
		$text = $l->t('The deadline of {assignment} has passed');
		$message = $l->t('%1$s submitted, %2$s missed.', [(string)(int)($params['submitted'] ?? 0), (string)(int)($params['missed'] ?? 0)]);
		$missing = (int)($params['not_distributed'] ?? 0);
		if ($missing > 0) {
			$message .= ' ' . $l->n('%n team member did not receive the assignment.', '%n team members did not receive the assignment.', $missing);
		}
		return $notification->setParsedSubject(str_replace('{assignment}', $title, $text))
			->setRichSubject($text, ['assignment' => ['type' => 'highlight', 'id' => (string)$a->getId(), 'name' => $title, 'link' => $link]])
			->setParsedMessage($message)
			->setLink($link)
			->setIcon($this->urls->getAbsoluteURL($this->urls->imagePath(Application::APP_ID, 'app-dark.svg')));
	}

	// neue verantwortliche Lehrkraft (§9.7)
	private function transferNotice(INotification $notification, string $languageCode, Assignment $a): INotification {
		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		$title = $this->title($a, $l);
		$link = $this->assignmentLink($notification->getUser(), $a);
		$text = $l->t('You are now responsible for the assignment {assignment}');
		$reason = (string)($notification->getSubjectParameters()['reason'] ?? '');
		$message = match ($reason) {
			'deleted' => $l->t('The account of the previous teacher was deleted.'),
			'left' => $l->t('The previous teacher left the team.'),
			'demoted' => $l->t('The previous teacher is no longer a teacher of the team.'),
			default => $l->t('An administrator transferred the assignment to you.'),
		};
		return $notification->setParsedSubject(str_replace('{assignment}', $title, $text))
			->setRichSubject($text, ['assignment' => ['type' => 'highlight', 'id' => (string)$a->getId(), 'name' => $title, 'link' => $link]])
			->setParsedMessage($message)
			->setLink($link)
			->setIcon($this->urls->getAbsoluteURL($this->urls->imagePath(Application::APP_ID, 'app-dark.svg')));
	}

	// Lehrkraft-Meldungen öffnen die Aufgabe auf der Aufgaben-Seite (M6g)
	// Aufgaben-Seite im Workspace der Aufgabe, sonst im eigenen Workspace (ADR 0021, WorkspaceService::assignmentKey)
	private function assignmentLink(string $uid, Assignment $a): string {
		$key = $this->workspaces->assignmentKey($uid, $a);
		return $this->urls->linkToRouteAbsolute('nextnotes.page.assignments') . '?' . http_build_query(($key !== null ? ['workspace' => $key] : []) + ['assignment' => (int)$a->getId()]);
	}

	private function assignmentOrNull(int $id): ?Assignment {
		try {
			return $this->assignments->find($id);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	private function title(Assignment $a, IL10N $l): string {
		$title = trim((string)$a->getTitle());
		return $title !== '' ? $title : $l->t('Assignment');
	}

	// Datum und Uhrzeit in der Zeitzone des Empfängers
	private function dateTime(IL10N $l, int $ts, string $uid): string {
		$dt = (new \DateTime('@' . $ts))->setTimezone($this->timeZone->getTimeZone(false, $uid));
		return $l->l('date', $dt, ['width' => 'medium']) . ', ' . $l->l('time', $dt, ['width' => 'short']);
	}
}
