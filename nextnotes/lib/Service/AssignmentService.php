<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service;

use OCA\NextNotes\Db\Assignment;
use OCA\NextNotes\Db\AssignmentMapper;
use OCA\NextNotes\Db\Submission;
use OCA\NextNotes\Db\SubmissionMapper;
use OCA\NextNotes\Service\Exception\BadRequestException;
use OCA\NextNotes\Service\Exception\ConflictException;
use OCA\NextNotes\Service\Exception\NotFoundException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;

// Aufgaben anlegen, ändern, löschen, lesen (SPEC §9.1, §9.5, Plan M6 §2.3); Austeilen und Zustände folgen in M6e1
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
	) {
	}

	/** @return array{assignments: list<array<string, mixed>>} GET /assignments (§9.6) */
	public function index(string $uid): array {
		$list = $this->assignments->findByIds($this->access->visibleAssignmentIds($uid));
		$ids = array_map(static fn (Assignment $a) => (int)$a->getId(), $list);
		$own = $this->submissions->findOwn($uid, $ids);
		$reviewable = array_values(array_filter($ids, fn (int $id) => $this->access->assignmentAllows($uid, $this->byId($list, $id), 'review')));
		$counts = $reviewable === [] ? [] : $this->submissions->counts($reviewable);
		$out = [];
		foreach ($list as $a) {
			$id = (int)$a->getId();
			$out[] = $this->view($uid, $a, $own[$id] ?? null, in_array($id, $reviewable, true) ? ($counts[$id] ?? ['total' => 0, 'submitted' => 0]) : null);
		}
		return ['assignments' => $out];
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
		$counts = $review ? ($this->submissions->counts([$id])[$id] ?? ['total' => 0, 'submitted' => 0]) : null;
		return $this->view($uid, $a, $this->submissions->findOwn($uid, [$id])[$id] ?? null, $counts);
	}

	/**
	 * @param array{total: int, submitted: int}|null $counts nur mit review
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
			'submission' => $own === null ? null : $this->submissionView($own, false),
		];
		if ($counts !== null) {
			$out['template_note_id'] = (int)$a->getTemplateNoteId();
			$out['source_note_id'] = $a->getSourceNoteId();
			$out['submissions_total'] = $counts['total'];
			$out['submissions_submitted'] = $counts['submitted'];
		}
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
	private function submissionView(Submission $s, bool $teacher): array {
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

	/** @return array{submissions: list<array<string, mixed>>} GET /assignments/{id}/submissions (review) */
	public function submissions(string $uid, int $id): array {
		$a = $this->access->requireAssignment($uid, $id, 'review');
		return ['submissions' => array_map(fn (Submission $s) => $this->submissionView($s, true), $this->submissions->findByAssignment((int)$a->getId()))];
	}

	/** @return array{submission: array<string, mixed>} GET /submissions/{id}: eigene oder review */
	public function submission(string $uid, int $id): array {
		[$s, $a] = $this->access->requireSubmission($uid, $id, 'read');
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
	 * PATCH /assignments/{id}: Titel, Punkte; Frist nur in draft (beliebig in der Zukunft) und distributed (nur verlängern, §9.5)
	 * @return array{assignment: array<string, mixed>}
	 */
	public function update(string $uid, int $id, array $in): array {
		$this->access->requireAssignment($uid, $id, 'edit');
		$title = array_key_exists('title', $in) ? self::title($in['title']) : null;
		$hasPoints = array_key_exists('max_points', $in);
		$maxPoints = $hasPoints ? self::maxPoints($in['max_points']) : null;
		$due = array_key_exists('due_at', $in) ? $this->dueAt($in['due_at']) : null;
		$a = $this->tx->run(function () use ($uid, $id, $title, $hasPoints, $maxPoints, $due): Assignment {
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
	private function dueAt(mixed $raw): int {
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
