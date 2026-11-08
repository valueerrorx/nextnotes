<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service\View;

use OCA\NextNotes\Db\AssignmentMapper;
use OCA\NextNotes\Db\SubmissionMapper;
use OCA\NextNotes\Service\AccessService;
use OCA\NextNotes\Service\Exception\ApiException;
use OCA\NextNotes\Service\QuotaService;
use OCA\NextNotes\Service\TeamService;
use OCA\NextNotes\Service\TeamStateService;
use OCP\IL10N;

/** Seite „Aufgaben“ (assignments.php, SPEC §9.6): Icon-Leiste, Teams zum Anlegen mit Belegung, Texte für nextnotes-assignments.js; Liste lädt die API */
class AssignmentsViewService {
	private const TEACHER_LEVEL = 4;
	private const STUDENT_LEVEL = 1;
	// Hinweis im Erstellen-Dialog ab dieser Anhang-Größe der Vorlage (Plan M6 §5 R4)
	public const HINT_BYTES = 10485760;

	/** @var array<string, bool> */
	private array $rail = [];
	/** @var array<string, bool> */
	private array $teaches = [];

	public function __construct(
		private TeamService $teams,
		private TeamStateService $teamState,
		private AssignmentMapper $assignments,
		private SubmissionMapper $submissions,
		private QuotaService $quota,
		private IL10N $l,
		private AccessService $access,
	) {
	}

	// Eintrag der Icon-Leiste (Text) oder null
	public function railLabel(string $uid): ?string {
		return $this->railVisible($uid) ? $this->l->t('Assignments') : null;
	}

	// Icon-Leiste: Mitglied eines Teams (ohne Level-Abfrage je Seite) oder eigene Abgabe bzw. Aufgabe eines verwaisten/eingefrorenen Teams
	public function railVisible(string $uid): bool {
		return $this->rail[$uid] ??= $this->teams->memberTeams($uid) !== []
			|| $this->submissions->assignmentIdsOf($uid) !== []
			|| $this->assignments->idsOwnedInactive($uid, !$this->teams->isAvailable()) !== [];
	}

	// Notiz-Menü „Als Aufgabe austeilen…“: Lehrkraft in mindestens einem aktiven Team (Abbruch beim ersten Treffer)
	public function teachesAny(string $uid): bool {
		return $this->teaches[$uid] ??= (function () use ($uid): bool {
			foreach (array_keys($this->teams->memberTeams($uid)) as $id) {
				$id = (string)$id;
				if ($this->teams->getLevel($id, $uid) >= self::TEACHER_LEVEL && $this->teamState->stateOf($id) === TeamStateService::ACTIVE) {
					return true;
				}
			}
			return false;
		})();
	}

	/**
	 * Teams, in denen uid Aufgaben anlegen darf (aktiv, Level ≥ 4), mit Schülerzahl und Team-Belegung (Erstellen-Dialog, Plan M6 §2.3a)
	 * @return list<array{id: string, name: string, students: int, used: int, limit: int}>
	 */
	public function teacherTeams(string $uid): array {
		$out = [];
		foreach ($this->teams->memberTeams($uid) as $id => $name) {
			$id = (string)$id;
			if ($this->teamState->stateOf($id) !== TeamStateService::ACTIVE || $this->teams->getLevel($id, $uid) < self::TEACHER_LEVEL) {
				continue;
			}
			$out[] = ['id' => $id, 'name' => (string)$name, 'students' => count($this->teams->members($id, self::STUDENT_LEVEL)),
				'used' => $this->quota->used('team', $id), 'limit' => $this->quota->limit()];
		}
		usort($out, static fn (array $a, array $b) => strnatcasecmp($a['name'], $b['name']) ?: strcmp($a['id'], $b['id']));
		return $out;
	}

	// ?create=<Notiz>: Team des Notiz-Workspace vorauswählen, wenn uid dort Aufgaben anlegen darf (sonst null, kein Hinweis auf fremde Notizen)
	/** @param list<array{id: string}> $teacherTeams */
	public function createTeam(string $uid, mixed $noteId, array $teacherTeams): ?string {
		if (!is_string($noteId) || !ctype_digit($noteId)) {
			return null;
		}
		try {
			$ws = $this->access->requireWorkspace($uid, (int)$this->access->requireNote($uid, (int)$noteId, 'read')->getWorkspaceId(), 'read');
		} catch (ApiException) {
			return null;
		}
		$team = $ws->getOwnerType() === 'team' ? (string)$ws->getOwnerId() : null;
		return $team !== null && in_array($team, array_column($teacherTeams, 'id'), true) ? $team : null;
	}

	/** @return array<string, string> Texte der Seite (NC-l10n) */
	public function texts(): array {
		$l = $this->l;
		return [
			'title' => $l->t('Assignments'),
			'allTeams' => $l->t('All teams'),
			'newAssignment' => $l->t('New assignment'),
			'empty' => $l->t('No assignments yet.'),
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
			'team' => $l->t('Team'),
			'titleLabel' => $l->t('Title'),
			'points' => $l->t('Points'),
			'maxPoints' => $l->t('Maximum points (optional)'),
			'source' => $l->t('Note'),
			'sourceSearch' => $l->t('Search notes of this team or your own notes'),
			'sourceNone' => $l->t('No matching notes'),
			'sourceHint' => $l->t('The assignment gets a frozen copy of the note, including attachments.'),
			'storage' => $l->t('Team storage: {used} of {limit} used'),
			'storageNeed' => $l->t('Handing out needs about {need} ({size} × {students} students).'),
			'storageLarge' => $l->t('The note has large attachments; every student gets a copy.'),
			'storageTooSmall' => $l->t('The storage of the team is not sufficient for all copies.'),
			'noTeams' => $l->t('You are not a teacher (admin or owner) of any team.'),
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
		];
	}
}
