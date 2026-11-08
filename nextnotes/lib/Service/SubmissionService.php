<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service;

use OCA\NextNotes\Db\Assignment;
use OCA\NextNotes\Db\AssignmentMapper;
use OCA\NextNotes\Db\NoteMapper;
use OCA\NextNotes\Db\Submission;
use OCA\NextNotes\Db\SubmissionMapper;
use OCA\NextNotes\Service\Exception\BadRequestException;
use OCA\NextNotes\Service\Exception\ConflictException;
use OCA\NextNotes\Service\Exception\ForbiddenException;
use OCA\NextNotes\Service\Exception\NotFoundException;
use OCP\IUserManager;

// Abgabe-Aktionen (SPEC §9.2/§9.5): submit (Schüler), return/reopen/feedback (Lehrkraft); Sperren Notiz → Abgabe (SubmissionStateService)
class SubmissionService {
	private const MAX_FEEDBACK = 20000;
	private const MAX_POINTS = 100000;

	public function __construct(
		private AccessService $access,
		private SubmissionStateService $states,
		private SubmissionMapper $submissions,
		private AssignmentMapper $assignments,
		private NoteMapper $notes,
		private AssignmentService $assignmentService,
		private Transaction $tx,
		private IUserManager $users,
		private AssignmentNotifications $notify,
	) {
	}

	/** @return array{submission: array<string, mixed>} POST /submissions/{id}/submit: nur eigene Abgabe mit studentCanEdit */
	public function submit(string $uid, int $id): array {
		[$s, $a] = $this->access->requireSubmission($uid, $id, 'read');
		if ($s->getStudentUid() !== $uid) {
			throw new NotFoundException('Submission not found');
		}
		$this->states->materialize($s, $a);
		$s = $this->locked($s, function (Submission $s, Assignment $a) use ($uid): Submission {
			if (!isset(SubmissionStateService::TRANSITIONS['submit'][(string)$s->getStatus()])) {
				throw new ConflictException('This submission cannot be handed in in its current state', ['code' => 'invalid_transition', 'status' => $s->getStatus()]);
			}
			if (!$this->states->studentCanEdit($uid, $s, $a)) {
				throw new ForbiddenException('This submission can no longer be edited', ['code' => 'submission_locked']);
			}
			return $this->states->transition($s, 'submit', ['submitted_at' => $this->states->now()]);
		});
		return ['submission' => $this->assignmentService->submissionView($s, false)];
	}

	/** @return array{submission: array<string, mixed>} POST /submissions/{id}/return {feedback?, points?} */
	public function giveBack(string $uid, int $id, array $in): array {
		[$s, $a] = $this->teacherAction($uid, $id, 'return');
		$values = $this->feedbackValues($uid, $a, $in, false) + ['returned_at' => $this->states->now()];
		$s = $this->locked($s, fn (Submission $s) => $this->states->transition($s, 'return', $values), true);
		if ($s->getStudentUid() !== null) {
			$this->notify->returned((int)$s->getId(), (string)$s->getStudentUid(), (string)$values['feedback_by_name']);
		}
		return ['submission' => $this->assignmentService->submissionView($s, true)];
	}

	/** @return array{submission: array<string, mixed>} POST /submissions/{id}/reopen {due_override_at?}: ohne Frist = offen bis zur Abgabe */
	public function reopen(string $uid, int $id, array $in): array {
		[$s] = $this->teacherAction($uid, $id, 'reopen');
		if ($s->getStudentUid() === null) {
			throw new ConflictException('The account of this student was deleted', ['code' => 'student_deleted']);
		}
		$raw = $in['due_override_at'] ?? null;
		$due = $raw === null || $raw === '' ? null : $this->assignmentService->dueAt($raw);
		$s = $this->locked($s, fn (Submission $s) => $this->states->transition($s, 'reopen', ['due_override_at' => $due]));
		if ($s->getStudentUid() !== null) {
			$this->notify->reopened((int)$s->getId(), (string)$s->getStudentUid(), $due);
		}
		return ['submission' => $this->assignmentService->submissionView($s, true)];
	}

	/** @return array{submission: array<string, mixed>} PUT /submissions/{id}/feedback {feedback?, points?}: ohne Statuswechsel */
	public function feedback(string $uid, int $id, array $in): array {
		[$s, $a] = $this->teacherAction($uid, $id, 'feedback');
		$values = $this->feedbackValues($uid, $a, $in, true);
		$s = $this->locked($s, fn (Submission $s) => $this->states->transition($s, 'feedback', $values), true);
		return ['submission' => $this->assignmentService->submissionView($s, true)];
	}

	/** @return array{0: Submission, 1: Assignment} Lehrkraft-Aktion; nach close nur noch Feedback und Rückgabe (§9.2, Thomas 2026-11-08) */
	private function teacherAction(string $uid, int $id, string $action): array {
		[$s, $a] = $this->access->requireSubmission($uid, $id, $action);
		$allowed = $action === 'reopen' ? ['distributed'] : ['distributed', 'closed'];
		if (!in_array($a->getStatus(), $allowed, true)) {
			throw new ConflictException('This assignment is closed', ['code' => 'invalid_transition', 'status' => $s->getStatus()]);
		}
		return [$this->states->materialize($s, $a), $a];
	}

	/**
	 * Notiz-Zeile sperren (serialisiert mit Speichern), Abgabe und Aufgabe frisch lesen, materialisieren, dann $fn (bedingtes UPDATE der Abgabe)
	 * @param callable(Submission, Assignment): Submission $fn
	 * @param bool $closedOk Feedback/Rückgabe der Lehrkraft auch nach close
	 */
	private function locked(Submission $s, callable $fn, bool $closedOk = false): Submission {
		$id = (int)$s->getId();
		$noteId = $s->getNoteId();
		return $this->tx->run(function () use ($id, $noteId, $fn, $closedOk): Submission {
			if ($noteId !== null) {
				$this->notes->lockRow((int)$noteId);
			}
			$s = $this->submissions->find($id);
			$a = $this->assignments->find((int)$s->getAssignmentId());
			if ($a->getStatus() === 'closed' && !$closedOk) {
				throw new ConflictException('This assignment is closed', ['code' => 'invalid_transition', 'status' => $s->getStatus()]);
			}
			return $fn($this->states->materialize($s, $a), $a);
		});
	}

	/** @return array<string, int|string|null> Feedback-Spalten mit Name der Lehrkraft (Q5, Snapshot) */
	private function feedbackValues(string $uid, Assignment $a, array $in, bool $required): array {
		$values = [];
		if (array_key_exists('feedback', $in)) {
			$text = $in['feedback'];
			if ($text !== null && !is_string($text)) {
				throw new BadRequestException('feedback must be text');
			}
			if ($text !== null && mb_strlen($text) > self::MAX_FEEDBACK) {
				throw new BadRequestException('Feedback too long (max ' . self::MAX_FEEDBACK . ' characters)');
			}
			$values['feedback'] = $text === null || trim($text) === '' ? null : $text;
		}
		if (array_key_exists('points', $in)) {
			$values['points'] = $this->points($in['points'], $a);
		}
		if ($required && $values === []) {
			throw new BadRequestException('feedback or points is required');
		}
		return $values + [
			'feedback_by' => $uid,
			'feedback_by_name' => mb_substr($this->users->getDisplayName($uid) ?? $uid, 0, 255),
			'feedback_at' => $this->states->now(),
		];
	}

	private function points(mixed $raw, Assignment $a): ?int {
		if ($raw === null || $raw === '') {
			return null;
		}
		$v = filter_var($raw, FILTER_VALIDATE_INT);
		$max = $a->getMaxPoints() ?? self::MAX_POINTS;
		if ($v === false || $v < 0 || $v > $max) {
			throw new BadRequestException('points must be between 0 and ' . $max);
		}
		return $v;
	}
}
