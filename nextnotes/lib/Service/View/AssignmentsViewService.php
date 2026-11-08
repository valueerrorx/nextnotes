<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service\View;

use OCA\NextNotes\Db\AssignmentMapper;
use OCA\NextNotes\Db\Note;
use OCA\NextNotes\Db\SubmissionMapper;
use OCA\NextNotes\Db\Workspace;
use OCA\NextNotes\Service\AccessService;
use OCA\NextNotes\Service\Exception\ApiException;
use OCA\NextNotes\Service\QuotaService;
use OCA\NextNotes\Service\SubmissionStateService;
use OCA\NextNotes\Service\TeamService;
use OCA\NextNotes\Service\TeamStateService;
use OCA\NextNotes\Service\WorkspaceService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDateTimeFormatter;
use OCP\IL10N;

/** Seite „Aufgaben“ (assignments.php, SPEC §9.6): folgt dem Team-Workspace (M6i), Team zum Anlegen mit Belegung, Texte für nextnotes-assignments.js; Liste lädt die API */
class AssignmentsViewService {
	private const TEACHER_LEVEL = 4;
	private const STUDENT_LEVEL = 1;
	// Hinweis im Erstellen-Dialog ab dieser Anhang-Größe der Vorlage (Plan M6 §5 R4)
	public const HINT_BYTES = 10485760;

	public function __construct(
		private TeamService $teams,
		private TeamStateService $teamState,
		private AssignmentMapper $assignments,
		private SubmissionMapper $submissions,
		private QuotaService $quota,
		private IL10N $l,
		private AccessService $access,
		private SubmissionStateService $states,
		private IDateTimeFormatter $dates,
		private WorkspaceService $workspaces,
	) {
	}

	// Team-Workspace kind=normal: Bereich der Aufgaben-Seite (M6i), sonst null
	public static function teamOf(Workspace $ws): ?string {
		return $ws->getOwnerType() === 'team' && $ws->getKind() === 'normal' ? (string)$ws->getOwnerId() : null;
	}

	// Eintrag der Icon-Leiste nur in Team-Workspaces (M6i, Thomas 2026-11-08); keine Abfrage
	public function railLabel(Workspace $ws): ?string {
		return self::teamOf($ws) !== null ? $this->l->t('Assignments') : null;
	}

	/**
	 * Daten der Seite (M6i): Team des Frame-Workspace oder kein Bereich (leerer Zustand, nur Aufgaben ohne lesbaren Team-Workspace)
	 * @return array{team: ?string, teacher: ?array{id: string, name: string, students: int, used: int, limit: int}, teamWorkspaces: list<string>, homeTeams: list<string>, createSource: ?int}
	 */
	public function page(string $uid, Workspace $ws, mixed $create): array {
		$team = self::teamOf($ws);
		$keys = $this->workspaces->teamWorkspaceKeys($uid);
		$teacher = $team !== null ? $this->teacherTeam($uid, $team) : null;
		return [
			'team' => $team,
			'teacher' => $teacher,
			'teamWorkspaces' => $team !== null ? ($keys[$team] ?? []) : [],
			'homeTeams' => $team === null ? array_map('strval', array_keys($keys)) : [],
			'createSource' => $teacher !== null ? $this->createSource($uid, $create, $team) : null,
		];
	}

	/**
	 * Team zum Anlegen (aktiv, Level ≥ 4) mit Schülerzahl und Team-Belegung (Erstellen-Dialog, Plan M6 §2.3a), sonst null
	 * @return ?array{id: string, name: string, students: int, used: int, limit: int}
	 */
	public function teacherTeam(string $uid, string $teamId): ?array {
		$name = $this->teams->memberTeams($uid)[$teamId] ?? null;
		if ($name === null || $this->teamState->stateOf($teamId) !== TeamStateService::ACTIVE || $this->teams->getLevel($teamId, $uid) < self::TEACHER_LEVEL) {
			return null;
		}
		return ['id' => $teamId, 'name' => (string)$name, 'students' => count($this->teams->members($teamId, self::STUDENT_LEVEL)),
			'used' => $this->quota->used('team', $teamId), 'limit' => $this->quota->limit()];
	}

	// ?create=<Notiz>: nur eine lesbare Notiz aus einem Workspace dieses Teams (inkl. Material), sonst null (kein Hinweis auf fremde Notizen)
	public function createSource(string $uid, mixed $noteId, string $teamId): ?int {
		if (!is_string($noteId) || !ctype_digit($noteId)) {
			return null;
		}
		try {
			$note = $this->access->requireNote($uid, (int)$noteId, 'read');
			$ws = $this->access->requireWorkspace($uid, (int)$note->getWorkspaceId(), 'read');
		} catch (ApiException) {
			return null;
		}
		return self::teamOf($ws) === $teamId ? (int)$note->getId() : null;
	}

	// Link auf die Aufgabe im Team-Workspace (Banner; Benachrichtigungen bauen ihn absolut im Notifier)
	public function assignmentUrl(string $uid, string $teamId, int $assignmentId): string {
		$key = $this->workspaces->assignmentsKey($uid, $teamId);
		return 'assignments.php?' . http_build_query(($key !== null ? ['workspace' => $key] : []) + ['assignment' => $assignmentId]);
	}

	/**
	 * Editor-Banner (M6g2, SPEC §9.5): eigene Abgabe-Kopie (Status, Frist, Abgeben/Zurückziehen, Grund der Sperre) oder Vorlage; sonst null.
	 * Aufruf erst nach requireNote(read).
	 * @return array{kind: string, tone: string, title: string, statusLabel: string, text: string, due: string, submissionId: ?int, canSubmit: bool, canUnsubmit: bool, url: string, linkLabel: string}|null
	 */
	public function noteBanner(string $uid, Note $note): ?array {
		$role = $note->getRole();
		if (($role !== 'submission' && $role !== 'template') || $note->getAssignmentId() === null) {
			return null;
		}
		try {
			$a = $this->assignments->find((int)$note->getAssignmentId());
		} catch (DoesNotExistException) {
			return null;
		}
		$l = $this->l;
		$base = ['title' => (string)$a->getTitle(), 'url' => $this->assignmentUrl($uid, (string)$a->getTeamId(), (int)$a->getId()),
			'submissionId' => null, 'canSubmit' => false, 'canUnsubmit' => false, 'linkLabel' => $l->t('Open assignment')];
		if ($role === 'template') {
			if ((int)$a->getTemplateNoteId() !== (int)$note->getId()) {
				return null;
			}
			$draft = $a->getStatus() === 'draft' && $this->teamState->stateOf((string)$a->getTeamId()) === TeamStateService::ACTIVE;
			return $base + ['kind' => 'template', 'tone' => $draft ? 'info' : 'neutral',
				'statusLabel' => $draft ? $l->t('Template – draft') : $l->t('Template – read only'),
				'text' => $draft ? $l->t('Students get a copy of this template when you hand out the assignment.') : $l->t('The assignment was handed out; the template can no longer be changed.'),
				'due' => $this->dueText((int)$a->getDueAt())];
		}
		try {
			$s = $note->getSubmissionId() === null ? null : $this->submissions->find((int)$note->getSubmissionId());
		} catch (DoesNotExistException) {
			$s = null;
		}
		// nur die eigene Kopie; Lehrkräfte sehen Abgaben auf der Aufgaben-Seite
		if ($s === null || (int)$s->getNoteId() !== (int)$note->getId() || $s->getStudentUid() === null || $s->getStudentUid() !== $uid) {
			return null;
		}
		$s = $this->states->materialize($s, $a);
		$lock = $this->states->studentLock($uid, $s, $a);
		$canUnsubmit = $this->states->studentCanUnsubmit($uid, $s, $a);
		$text = match ($lock) {
			null => $l->t('Work on this copy and hand it in before the deadline. After handing in, it is read-only.'),
			'submitted' => $l->t('Handed in on %s – read only.', [$this->date($s->getSubmittedAt())])
				. ($canUnsubmit ? ' ' . $l->t('Until the deadline you can withdraw it to keep working.') : ''),
			'returned' => $l->t('Returned by %s – read only. The feedback is shown with your assignment.', [(string)($s->getFeedbackByName() ?? '')]),
			'missed' => $l->t('The deadline has passed – read only.'),
			'closed' => $l->t('The assignment was closed – read only.'),
			'team' => $l->t('The team is no longer active – read only.'),
			default => $l->t('You are no longer a student of this team – read only.'),
		};
		return array_merge($base, ['kind' => 'submission',
			'tone' => $lock === null ? 'info' : (in_array($lock, ['submitted', 'returned'], true) ? 'success' : 'warning'),
			'statusLabel' => $this->statusLabel((string)$s->getStatus()), 'text' => $text,
			'due' => $this->dueText(SubmissionStateService::effectiveDue($s, $a)),
			'submissionId' => (int)$s->getId(), 'canSubmit' => $lock === null, 'canUnsubmit' => $canUnsubmit,
			'linkLabel' => $lock === 'returned' ? $l->t('Show feedback') : $l->t('Open assignment')]);
	}

	private function statusLabel(string $status): string {
		return match ($status) {
			'submitted' => $this->l->t('Handed in'),
			'returned' => $this->l->t('Returned'),
			'missed' => $this->l->t('Missed'),
			'reopened' => $this->l->t('Reopened'),
			default => $this->l->t('Assigned'),
		};
	}

	private function dueText(?int $due): string {
		return $due === null ? $this->l->t('No deadline') : $this->l->t('Due %s', [$this->date($due)]);
	}

	private function date(?int $ts): string {
		return $ts === null ? '' : $this->dates->formatDateTime($ts, 'medium', 'short');
	}

	/** @return array<string, string> Texte der Seite (NC-l10n) */
	public function texts(): array {
		$l = $this->l;
		return [
			'title' => $l->t('Assignments'),
			'newAssignment' => $l->t('New assignment'),
			'empty' => $l->t('No assignments yet.'),
			'noTeamWorkspace' => $l->t('Assignments are available in team workspaces.'),
			'withoutWorkspace' => $l->t('Assignments of teams without a team workspace you can open'),
			'emptyTeacher' => $l->t('No assignments yet. Create one from a note: "Hand out as assignment…" in the note menu, or with "New assignment".'),
			'loadFailed' => $l->t('The assignments could not be loaded.'),
			'actionFailed' => $l->t('The action failed.'),
			'draft' => $l->t('Draft'),
			'distributed' => $l->t('Assigned'),
			'closed' => $l->t('Closed'),
			'stAssigned' => $l->t('Assigned'),
			'stSubmitted' => $l->t('Handed in'),
			'stReturned' => $l->t('Returned'),
			'stMissed' => $l->t('Missed'),
			'stReopened' => $l->t('Reopened'),
			'due' => $l->t('Due {date}'),
			'dueLabel' => $l->t('Due date'),
			'countSubmitted' => $l->t('{n} handed in'),
			'countOpen' => $l->t('{n} open'),
			'countMissed' => $l->t('{n} missed'),
			'notDistributed' => $l->t('{n} not handed out yet'),
			'notDistributedQuota' => $l->t('{n} not handed out yet – the storage of the team is full'),
			'orphaned' => $l->t('Team deleted – deleted on {date}'),
			'frozen' => $l->t('Teams app unavailable – read only'),
			'owner' => $l->t('Responsible: {name}'),
			'titleLabel' => $l->t('Title'),
			'points' => $l->t('Points'),
			'maxPoints' => $l->t('Maximum points (optional)'),
			'source' => $l->t('Note'),
			'sourceSearch' => $l->t('Search notes of this team'),
			'sourceNone' => $l->t('No matching notes'),
			'sourceHint' => $l->t('The assignment gets a frozen copy of the note, including attachments.'),
			'storage' => $l->t('Team storage: {used} of {limit} used'),
			'storageNeed' => $l->t('Handing out needs about {need} ({size} × {students} students).'),
			'storageLarge' => $l->t('The note has large attachments; every student gets a copy.'),
			'storageTooSmall' => $l->t('The storage of the team is not sufficient for all copies.'),
			'noTeams' => $l->t('You are not a teacher (admin or owner) of this team.'),
			'create' => $l->t('Create'),
			'cancel' => $l->t('Cancel'),
			'close' => $l->t('Close'),
			'save' => $l->t('Save'),
			'back' => $l->t('All assignments'),
			'editTemplate' => $l->t('Edit template'),
			'viewTemplate' => $l->t('View template'),
			'distribute' => $l->t('Hand out'),
			'distributeConfirm' => $l->t('Hand out "{title}" to all students of {team}? Every student gets a copy.'),
			'distributed_ok' => $l->t('Handed out to {n} students.'),
			'distributePending' => $l->t('{n} students do not have the assignment yet; they get it when they open their assignments.'),
			'quotaExceeded' => $l->t('The storage of the team is full: {need} needed, {available} available. Remove attachments or ask the administrator for more storage.'),
			'deadlinePassed' => $l->t('The deadline has passed. Extend it first.'),
			'closeAssignment' => $l->t('Close assignment'),
			'closeConfirm' => $l->t('Close "{title}"? Open submissions become missed, students can no longer edit.'),
			'extend' => $l->t('Change deadline'),
			'export' => $l->t('Download all (ZIP)'),
			'submissions' => $l->t('Submissions'),
			'student' => $l->t('Student'),
			'status' => $l->t('Status'),
			'handedInAt' => $l->t('Handed in'),
			'feedback' => $l->t('Feedback'),
			'left' => $l->t('left the team'),
			'deleted' => $l->t('account deleted'),
			'noSubmissions' => $l->t('No submissions yet.'),
			'review' => $l->t('Review'),
			'readOnlyCopy' => $l->t('Read-only view of the submission'),
			'noCopy' => $l->t('This submission has no note.'),
			'feedbackPlaceholder' => $l->t('Feedback for the student'),
			'pointsOf' => $l->t('of {max}'),
			'saveFeedback' => $l->t('Save feedback'),
			'giveBack' => $l->t('Return'),
			'reopen' => $l->t('Reopen'),
			'reopenDue' => $l->t('New deadline (optional)'),
			'reopenConfirm' => $l->t('Reopen'),
			'saved' => $l->t('Saved'),
			'returnedBy' => $l->t('Returned by {name}'),
			'previous' => $l->t('Previous'),
			'next' => $l->t('Next'),
			'invalidPoints' => $l->t('Points must be a whole number between 0 and {max}.'),
			'invalidDue' => $l->t('The deadline must be in the future.'),
			'titleRequired' => $l->t('Enter a title and choose a note.'),
			'sourceNotAllowed' => $l->t('Only your own notes or notes of this team can become an assignment.'),
			'studentDeleted' => $l->t('The account of this student was deleted; the submission can no longer be reopened.'),
			'invalidTransition' => $l->t('This is not possible in the current state.'),
			// Schüler-Sicht, Löschen, verwaiste Teams (M6g2)
			'noDeadline' => $l->t('No deadline'),
			'from' => $l->t('From {name}'),
			'editCopy' => $l->t('Edit'),
			'viewCopy' => $l->t('View'),
			'handIn' => $l->t('Hand in'),
			'handInConfirm' => $l->t('Hand in "{title}"? Afterwards your copy is read-only.'),
			'handedIn' => $l->t('Handed in.'),
			'withdraw' => $l->t('Withdraw'),
			'withdrawConfirm' => $l->t('Withdraw your submission? Until the deadline you can keep working and hand it in again. If you do not hand it in again before the deadline, the assignment counts as not handed in.'),
			'withdrawn' => $l->t('Submission withdrawn. You can keep working.'),
			'downloadOwn' => $l->t('Download'),
			'submissionLocked' => $l->t('This submission can no longer be changed. Reload the page.'),
			'lockOpen' => $l->t('Work on your copy and hand it in before the deadline. After handing in, it is read-only.'),
			'lockSubmitted' => $l->t('Handed in on {date}. Your teacher sees your work.'),
			'lockReturned' => $l->t('Your teacher returned your work.'),
			'lockMissed' => $l->t('The deadline has passed. Your copy is read-only.'),
			'lockClosed' => $l->t('The assignment was closed. Your copy is read-only.'),
			'lockTeam' => $l->t('The team is no longer active. Your copy is read-only.'),
			'lockLeft' => $l->t('You are no longer a student of this team. Your copy is read-only.'),
			'noFeedbackText' => $l->t('No written feedback.'),
			'deleteAssignment' => $l->t('Delete assignment'),
			'deleteConfirm' => $l->t('Delete "{title}"? {n} submissions with the copies and attachments of the students are deleted permanently.'),
			'deleteNone' => $l->t('Delete "{title}"? It has no submissions.'),
			'exportFirst' => $l->t('Export first (ZIP)'),
			'assignmentDeleted' => $l->t('"{title}" was deleted.'),
			'orphanedHint' => $l->t('The team was deleted. The assignment and all submissions are deleted on {date}. Download them before.'),
			'orphanedHintStudent' => $l->t('The team was deleted. Your submission is deleted on {date}. Download it before.'),
			'frozenHint' => $l->t('The Teams app is not available. The assignment is read-only.'),
		];
	}
}
