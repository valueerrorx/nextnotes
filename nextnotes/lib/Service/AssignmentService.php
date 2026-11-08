<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service;

use OCA\NextNotes\Db\Assignment;
use OCA\NextNotes\Db\AssignmentMapper;
use OCA\NextNotes\Db\Note;
use OCA\NextNotes\Db\Submission;
use OCA\NextNotes\Db\SubmissionMapper;
use OCA\NextNotes\Db\WorkspaceMapper;
use OCA\NextNotes\Service\Exception\BadRequestException;
use OCA\NextNotes\Service\Exception\ConflictException;
use OCA\NextNotes\Service\Exception\ForbiddenException;
use OCA\NextNotes\Service\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;

// Aufgaben anlegen, ändern, austeilen, schließen, löschen, lesen (SPEC §9.1, §9.5, Plan M6 §2.3/§2.4)
class AssignmentService {
	private const MAX_POINTS = 100000;

	public function __construct(
		private AssignmentMapper $assignments,
		private SubmissionMapper $submissions,
		private AccessService $access,
		private WorkspaceService $workspaces,
		private TeamService $teams,
		private TeamStateService $teamState,
		private DuplicateService $duplicates,
		private AttachmentService $attachments,
		private QuotaService $quota,
		private AssignmentDeletion $deletion,
		private Transaction $tx,
		private ITimeFactory $time,
		private IUserManager $users,
		private DistributionService $distribution,
		private SubmissionStateService $states,
		private WorkspaceMapper $workspaceRows,
		private AssignmentNotifications $notify,
	) {
	}

	/** @return array{assignments: list<array<string, mixed>>} GET /assignments (§9.6) */
	public function index(string $uid): array {
		$this->reconcileOwn($uid);
		$list = $this->assignments->findByIds($this->access->visibleAssignmentIds($uid));
		$ids = array_map(static fn (Assignment $a) => (int)$a->getId(), $list);
		$own = $this->submissions->findOwn($uid, $ids);
		foreach ($own as $aid => $s) {
			$own[$aid] = $this->states->materialize($s, $this->byId($list, $aid));
		}
		$reviewable = array_values(array_filter($ids, fn (int $id) => $this->access->assignmentAllows($uid, $this->byId($list, $id), 'review')));
		$counts = $reviewable === [] ? [] : $this->submissions->counts($reviewable);
		$out = [];
		foreach ($list as $a) {
			$id = (int)$a->getId();
			$out[] = $this->view($uid, $a, $own[$id] ?? null, in_array($id, $reviewable, true) ? ($counts[$id] ?? ['total' => 0, 'submitted' => 0, 'by_status' => []]) : null);
		}
		return ['assignments' => $out];
	}

	/**
	 * Nachzügler (§9.4) beim Öffnen der Aufgabenliste durch den Schüler: fehlende eigene Abgaben anlegen, Austrittsmarke nach Wiedereintritt löschen (H5);
	 * Quote-Mangel bleibt ohne Fehler nach außen (Plan M6 §2.3a (3))
	 */
	private function reconcileOwn(string $uid): void {
		$teamIds = $this->teams->isAvailable() ? array_map('strval', array_keys($this->teams->memberTeams($uid))) : [];
		if ($teamIds === []) {
			return;
		}
		$now = $this->time->getTime();
		$own = array_flip($this->submissions->assignmentIdsOf($uid));
		$todo = [];
		foreach ($this->assignments->findOpenDistributed($teamIds, $now) as $a) {
			// nur Schüler (Level 1); Lehrkräfte ohne Zusatzabfrage je Aufgabe
			if (!isset($own[(int)$a->getId()]) && $this->teams->getLevel((string)$a->getTeamId(), $uid) === 1) {
				$todo[(int)$a->getId()] = $a;
			}
		}
		$left = array_filter($this->submissions->findOwn($uid), static fn (Submission $s) => $s->getLeftTeamAt() !== null);
		foreach ($left === [] ? [] : $this->assignments->findByIds(array_keys($left)) as $a) {
			$todo[(int)$a->getId()] ??= $a;
		}
		$deadline = $now + DistributionService::BUDGET_SECONDS;
		foreach ($todo as $a) {
			$this->distribution->reconcile($a, $uid, $deadline, notify: false);
		}
	}

	/** @param Assignment[] $list */
	private function byId(array $list, int $id): Assignment {
		foreach ($list as $a) {
			if ((int)$a->getId() === $id) {
				return $a;
			}
		}
		throw new NotFoundException('Assignment not found');
	}

	/** @return array{assignment: array<string, mixed>} GET /assignments/{id}: Aufgabendaten, eigener Status; fremde Abgaben nie (nur review) */
	public function show(string $uid, int $id): array {
		$a = $this->access->requireAssignment($uid, $id, 'read');
		return ['assignment' => $this->viewOne($uid, $a)];
	}

	/** @return array<string, mixed> */
	private function viewOne(string $uid, Assignment $a): array {
		$id = (int)$a->getId();
		$review = $this->access->assignmentAllows($uid, $a, 'review');
		$counts = $review ? ($this->submissions->counts([$id])[$id] ?? ['total' => 0, 'submitted' => 0, 'by_status' => []]) : null;
		$own = $this->submissions->findOwn($uid, [$id])[$id] ?? null;
		return $this->view($uid, $a, $own === null ? null : $this->states->materialize($own, $a), $counts);
	}

	/**
	 * @param array{total: int, submitted: int, by_status: array<string, int>}|null $counts nur mit review
	 * @return array<string, mixed>
	 */
	private function view(string $uid, Assignment $a, ?Submission $own, ?array $counts): array {
		$teamId = (string)$a->getTeamId();
		$out = [
			'id' => (int)$a->getId(),
			'team' => $teamId,
			'team_name' => $this->teams->memberTeams($uid)[$teamId] ?? $this->teamState->snapshotName($teamId) ?? '',
			'team_state' => $this->teamState->stateOf($teamId),
			'title' => (string)$a->getTitle(),
			'due_at' => (int)$a->getDueAt(),
			'status' => (string)$a->getStatus(),
			'max_points' => $a->getMaxPoints(),
			'created_by_name' => $a->getCreatedByName(),
			'is_owner' => $a->getOwnerUid() !== null && $a->getOwnerUid() === $uid,
			'distributed_at' => $a->getDistributedAt(),
			'created_at' => (int)$a->getCreatedAt(),
			'updated_at' => (int)$a->getUpdatedAt(),
			'submission' => $own === null ? null : $this->ownView($uid, $own, $a),
		];
		if ($counts !== null) {
			$out['template_note_id'] = (int)$a->getTemplateNoteId();
			$out['source_note_id'] = $a->getSourceNoteId();
			$out['submissions_total'] = $counts['total'];
			$out['submissions_submitted'] = $counts['submitted'];
			$out['submissions_by_status'] = (object)$counts['by_status'];
			$out += $this->distribution->pending($a);
		}
		// verwaistes Team: Löschtermin (Restfrist, §9.6)
		$out['deletion_at'] = $out['team_state'] === TeamStateService::ORPHANED ? $this->teamState->deletionDate($teamId) : null;
		$out['can'] = [];
		foreach (['review', 'edit', 'distribute', 'close', 'export', 'delete'] as $action) {
			$out['can'][$action] = $this->access->assignmentAllows($uid, $a, $action);
		}
		return $out;
	}

	/**
	 * Abgabe als JSON; $teacher = Sicht mit review (alle Felder), sonst eigene Abgabe (Feedback erst nach Rückgabe)
	 * @return array<string, mixed>
	 */
	public function submissionView(Submission $s, bool $teacher): array {
		$returned = $s->getStatus() === 'returned';
		$out = [
			'id' => (int)$s->getId(),
			'assignment_id' => (int)$s->getAssignmentId(),
			'status' => (string)$s->getStatus(),
			'note_id' => $s->getNoteId(),
			'due_override_at' => $s->getDueOverrideAt(),
			'submitted_at' => $s->getSubmittedAt(),
			'returned_at' => $s->getReturnedAt(),
			'points' => $teacher || $returned ? $s->getPoints() : null,
			'feedback' => $teacher || $returned ? $s->getFeedback() : null,
			'feedback_by_name' => $teacher || $returned ? $s->getFeedbackByName() : null,
			'feedback_at' => $teacher || $returned ? $s->getFeedbackAt() : null,
		];
		if ($teacher) {
			$out += [
				'student_uid' => $s->getStudentUid(),
				'student_name' => (string)$s->getStudentName(),
				'student_uid_former' => $s->getStudentUidFormer(),
				'student_deleted' => $s->getStudentDeletedAt() !== null,
				'left_team_at' => $s->getLeftTeamAt(),
			];
		}
		return $out;
	}

	/**
	 * Eigene Abgabe mit Schüler-Aktionen (M6g2): effektive Frist, Abgeben, Zurückziehen, Grund der Sperre
	 * @return array<string, mixed>
	 */
	public function ownView(string $uid, Submission $s, Assignment $a): array {
		return $this->submissionView($s, false) + [
			'effective_due' => SubmissionStateService::effectiveDue($s, $a),
			'can_submit' => $this->states->studentCanEdit($uid, $s, $a),
			'can_unsubmit' => $this->states->studentCanUnsubmit($uid, $s, $a),
			'lock' => $this->states->studentLock($uid, $s, $a),
		];
	}

	/** @return array{submissions: list<array<string, mixed>>, not_distributed: int, quota_full: bool} GET /assignments/{id}/submissions (review) */
	public function submissions(string $uid, int $id): array {
		$a = $this->access->requireAssignment($uid, $id, 'review');
		$this->states->materializeAssignment($a);
		return ['submissions' => array_map(fn (Submission $s) => $this->submissionView($s, true), $this->submissions->findByAssignment((int)$a->getId()))]
			+ $this->distribution->pending($a);
	}

	/** @return array{submission: array<string, mixed>} GET /submissions/{id}: eigene oder review */
	public function submission(string $uid, int $id): array {
		[$s, $a] = $this->access->requireSubmission($uid, $id, 'read');
		$s = $this->states->materialize($s, $a);
		if ($s->getStudentUid() === $uid && !$this->access->assignmentAllows($uid, $a, 'review')) {
			return ['submission' => $this->ownView($uid, $s, $a)];
		}
		return ['submission' => $this->submissionView($s, $this->access->assignmentAllows($uid, $a, 'review'))];
	}

	/**
	 * POST /assignments {team, title, due_at, source_note_id, max_points?}: Lehrkraft (aktives Team, Level ≥ 4), Quelle lesbar (auch Material, Q2);
	 * Vorlage = eingefrorene Kopie ohne Workspace, Anhänge gegen die Team-Quote (Plan M6 §2.3a)
	 * @return array{assignment: array<string, mixed>}
	 */
	public function create(string $uid, array $in): array {
		$teamId = $this->workspaces->requireTeamForCreate($uid, $in['team'] ?? null);
		$title = self::title($in['title'] ?? null);
		$due = $this->dueAt($in['due_at'] ?? null);
		$maxPoints = self::maxPoints($in['max_points'] ?? null);
		$sourceId = filter_var($in['source_note_id'] ?? null, FILTER_VALIDATE_INT);
		if ($sourceId === false || $sourceId <= 0) {
			throw new BadRequestException('source_note_id is required');
		}
		$source = $this->access->resolveLinked($uid, $this->access->requireNote($uid, $sourceId, 'read'));
		if ($source->getRole() !== 'note') {
			throw new BadRequestException('Only regular notes can be used for an assignment');
		}
		$this->requireSourceAllowed($uid, $teamId, $source);
		$this->teamState->ensure($teamId, $this->teams->memberTeams($uid)[$teamId] ?? null);
		$quota = $this->quota->quotaForTeam($teamId, $this->attachments->bytesOf([(int)$source->getId()]));
		$now = $this->time->getTime();
		$name = $this->users->getDisplayName($uid) ?? $uid;
		$created = [];
		try {
			$a = $this->tx->run(function () use ($uid, $teamId, $title, $due, $maxPoints, $source, $quota, $now, $name, &$created): Assignment {
				// Team-Zeile zuerst (Sperr-Reihenfolge Plan M6 §2.4), dann Aufgabe und Vorlage
				$this->attachments->enforceQuota($quota);
				$a = new Assignment();
				$a->setTeamId($teamId);
				$a->setTitle($title);
				$a->setSourceNoteId((int)$source->getId());
				$a->setTemplateNoteId(0);
				$a->setDueAt($due);
				$a->setStatus('draft');
				$a->setMaxPoints($maxPoints);
				$a->setCreatedBy($uid);
				$a->setCreatedByName(mb_substr($name, 0, 255));
				$a->setOwnerUid($uid);
				$a->setCreatedAt($now);
				$a->setUpdatedAt($now);
				$a = $this->assignments->insert($a);
				$template = $this->duplicates->copyAsTemplate($source, (int)$a->getId(), $uid, $created);
				$a->setTemplateNoteId((int)$template->getId());
				return $this->assignments->update($a);
			});
		} catch (\Throwable $e) {
			foreach ($created as $noteId) {
				$this->attachments->discardFiles($noteId);
			}
			throw $e;
		}
		return ['assignment' => $this->viewOne($uid, $a)];
	}

	/**
	 * Quelle nur aus eigenem Workspace oder einem Workspace desselben Teams (inkl. Material); fremde Freigaben und andere Teams → 403
	 * (Verlassen-Regel SPEC §3, Lead-Entscheidung 2026-11-08)
	 */
	private function requireSourceAllowed(string $uid, string $teamId, Note $source): void {
		try {
			$ws = $source->getWorkspaceId() === null ? null : $this->workspaceRows->find((int)$source->getWorkspaceId());
		} catch (DoesNotExistException) {
			$ws = null;
		}
		$allowed = $ws !== null && $ws->getKind() === 'normal' && (
			($ws->getOwnerType() === 'user' && $ws->getOwnerId() === $uid)
			|| ($ws->getOwnerType() === 'team' && $ws->getOwnerId() === $teamId));
		if (!$allowed) {
			throw new ForbiddenException('Only your own notes or notes of this team can become an assignment', ['code' => 'source_not_allowed']);
		}
	}

	/**
	 * POST /assignments/{id}/distribute (§9.1): draft → distributed, dann Abgaben je Schüler im Zeitbudget; erneuter Aufruf teilt Nachzügler aus.
	 * 413 quota_exceeded vorab (nichts angelegt); knapp gewordene Quote → 200 mit pending und error (Plan M6 §2.3a)
	 * @return array<string, mixed>
	 */
	public function distribute(string $uid, int $id): array {
		$a = $this->distribution->start($this->access->requireAssignment($uid, $id, 'distribute'));
		$result = $this->distribution->reconcile($a, null, $this->time->getTime() + DistributionService::BUDGET_SECONDS);
		$out = ['assignment' => $this->viewOne($uid, $a), 'created' => $result['created'], 'pending' => $result['pending']];
		if ($result['error'] !== null) {
			$out['error'] = $result['error'];
		}
		return $out;
	}

	/**
	 * POST /assignments/{id}/close: distributed → closed, offene Abgaben → missed; friert alle Übergänge ein (§9.2). Sperren: Aufgabe → Abgaben
	 * @return array{assignment: array<string, mixed>}
	 */
	public function close(string $uid, int $id): array {
		$this->access->requireAssignment($uid, $id, 'close');
		$a = $this->tx->run(function () use ($uid, $id): Assignment {
			if ($this->assignments->lockRow($id) === 0) {
				throw new NotFoundException('Assignment not found');
			}
			$a = $this->access->requireAssignment($uid, $id, 'close');
			if ($a->getStatus() !== 'distributed') {
				throw new ConflictException('Only a distributed assignment can be closed', ['code' => 'invalid_state']);
			}
			$now = $this->time->getTime();
			$a->setStatus('closed');
			$a->setUpdatedAt($now);
			$a = $this->assignments->update($a);
			$this->submissions->closeOpen($id, $now);
			return $a;
		});
		return ['assignment' => $this->viewOne($uid, $a)];
	}

	/**
	 * PATCH /assignments/{id}: Titel, Punkte; Frist nur in draft (beliebig in der Zukunft) und distributed (nur verlängern, §9.5)
	 * @return array{assignment: array<string, mixed>}
	 */
	public function update(string $uid, int $id, array $in): array {
		$this->access->requireAssignment($uid, $id, 'edit');
		$title = array_key_exists('title', $in) ? self::title($in['title']) : null;
		$hasPoints = array_key_exists('max_points', $in);
		$maxPoints = $hasPoints ? self::maxPoints($in['max_points']) : null;
		$due = array_key_exists('due_at', $in) ? $this->dueAt($in['due_at']) : null;
		$extended = false;
		$a = $this->tx->run(function () use ($uid, $id, $title, $hasPoints, $maxPoints, $due, &$extended): Assignment {
			if ($this->assignments->lockRow($id) === 0) {
				throw new NotFoundException('Assignment not found');
			}
			$a = $this->access->requireAssignment($uid, $id, 'edit');
			$now = $this->time->getTime();
			if ($due !== null && $due !== (int)$a->getDueAt()) {
				$status = (string)$a->getStatus();
				if ($status !== 'draft' && $status !== 'distributed') {
					throw new ConflictException('The deadline of a closed assignment cannot be changed', ['code' => 'invalid_state']);
				}
				if ($status === 'distributed' && $due < (int)$a->getDueAt()) {
					throw new BadRequestException('The deadline can only be extended', ['code' => 'deadline_not_extended']);
				}
				if ($status === 'distributed') {
					$this->submissions->resetMissed($id, $now);
					// neue Frist → neue Sammelmeldung (M6e2)
					$a->setDeadlineNotifiedAt(null);
					$extended = true;
				}
				$a->setDueAt($due);
			}
			if ($title !== null) {
				$a->setTitle($title);
			}
			if ($hasPoints) {
				$a->setMaxPoints($maxPoints);
			}
			$a->setUpdatedAt($now);
			return $this->assignments->update($a);
		});
		// Verlängerung: Nachzügler gleich austeilen (§9.2), Rest beim nächsten Zugriff
		if ($due !== null && $a->getStatus() === 'distributed') {
			if ($extended) {
				$this->notify->clearDeadline($id);
			}
			$this->distribution->reconcile($a, null, $this->time->getTime() + DistributionService::BUDGET_SECONDS);
		}
		return ['assignment' => $this->viewOne($uid, $a)];
	}

	/** DELETE /assignments/{id} mit confirm: true (§9.5); Lehrkraft (aktiv) bzw. owner_uid (verwaist) */
	public function delete(string $uid, int $id, mixed $confirm): void {
		$this->access->requireAssignment($uid, $id, 'delete');
		if ($confirm !== true && $confirm !== 'true' && $confirm !== '1' && $confirm !== 1) {
			throw new BadRequestException('Deleting an assignment removes all submissions; confirmation required', ['code' => 'confirmation_required']);
		}
		if (!$this->deletion->purge($id)) {
			throw new NotFoundException('Assignment not found');
		}
	}

	private static function title(mixed $raw): string {
		$title = is_string($raw) ? trim(Pcre::replace('/[\x00-\x1F\x7F]/u', ' ', $raw)) : '';
		if ($title === '') {
			throw new BadRequestException('Title is required');
		}
		if (mb_strlen($title) > 255) {
			throw new BadRequestException('Title too long (max 255 characters)');
		}
		return $title;
	}

	// Unix-Sekunden (Zahl oder Ziffernfolge) oder ISO 8601 mit Zeitzone; muss in der Zukunft liegen
	public function dueAt(mixed $raw): int {
		$ts = null;
		if (is_int($raw) || (is_string($raw) && preg_match('/^\d{1,12}$/', $raw))) {
			$ts = (int)$raw;
		} elseif (is_string($raw) && $raw !== '') {
			$parsed = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $raw)
				?: \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.vP', $raw);
			$ts = $parsed === false ? null : $parsed->getTimestamp();
		}
		if ($ts === null) {
			throw new BadRequestException('due_at must be a Unix timestamp or ISO 8601 date');
		}
		if ($ts <= $this->time->getTime()) {
			throw new BadRequestException('due_at must be in the future');
		}
		return $ts;
	}

	private static function maxPoints(mixed $raw): ?int {
		if ($raw === null || $raw === '') {
			return null;
		}
		$v = filter_var($raw, FILTER_VALIDATE_INT);
		if ($v === false || $v < 0 || $v > self::MAX_POINTS) {
			throw new BadRequestException('max_points must be between 0 and ' . self::MAX_POINTS);
		}
		return $v;
	}
}
