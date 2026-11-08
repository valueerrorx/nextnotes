<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service;

use OCA\NextNotes\Db\Assignment;
use OCA\NextNotes\Db\Submission;
use OCA\NextNotes\Db\SubmissionMapper;
use OCA\NextNotes\Service\Exception\ConflictException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Zustandsautomat submissions.status (SPEC §9.2), Frist ohne Cron (§9.3), studentCanEdit.
 *
 * Sperr-Reihenfolge (Plan M6 §2.4, SPEC §8), global: Team-Zeile → Aufgabe → Workspace(s) → Notiz → Abgabe; jeder Pfad nimmt eine Teilfolge:
 * - distribute/reconcile je Schüler: Team (Quote) → Aufgabe → Schüler-Workspace → Notiz (neu) → Abgabe (neu)
 * - Übergang draft → distributed: Team → Aufgabe → Vorlagen-Notiz
 * - Schreiben in Kopie/Vorlage: [Team, Quote] → Notiz (AccessService::lockForWrite liest Abgabe/Aufgabe ohne Sperre neu) → [Abgabe, materialize]
 * - submit/return/reopen/feedback: Notiz → Abgabe (bedingtes UPDATE); close: Aufgabe → Abgaben (Massen-UPDATE)
 * - materialize: nur Abgaben (bedingt), immer als letzte Sperre
 * - Aufgabe löschen: Team → Aufgabe → Schüler-Workspaces → Notizen → Abgaben; Kontolöschung: Workspaces → Notizen → Abgaben
 */
class SubmissionStateService {
	// Übergänge der Aktionen (von → nach); alles andere 409 invalid_transition
	public const TRANSITIONS = [
		'submit' => ['assigned' => 'submitted', 'reopened' => 'submitted'],
		'return' => ['submitted' => 'returned', 'missed' => 'returned'],
		'reopen' => ['submitted' => 'reopened', 'missed' => 'reopened', 'returned' => 'reopened'],
		// Feedback ändern ohne Statuswechsel
		'feedback' => ['submitted' => 'submitted', 'missed' => 'missed', 'returned' => 'returned'],
	];

	private const STUDENT_LEVEL = 1;

	public function __construct(
		private SubmissionMapper $submissions,
		private TeamService $teams,
		private TeamStateService $teamState,
		private ITimeFactory $time,
	) {
	}

	public function now(): int {
		return $this->time->getTime();
	}

	// Effektive Frist: reopened → eigene Frist (ohne = keine Frist), sonst Aufgabenfrist
	public static function effectiveDue(Submission $s, Assignment $a): ?int {
		if ($s->getStatus() === 'reopened') {
			return $s->getDueOverrideAt();
		}
		return (int)$a->getDueAt();
	}

	// Vor jedem Lesen/Schreiben einer Abgabe (§9.3): offene Abgabe mit abgelaufener Frist → missed (bedingt, idempotent)
	public function materialize(Submission $s, Assignment $a): Submission {
		$due = self::effectiveDue($s, $a);
		if (!in_array($s->getStatus(), ['assigned', 'reopened'], true) || $due === null || $this->now() <= $due) {
			return $s;
		}
		$this->submissions->materialize((int)$a->getId(), (int)$a->getDueAt(), $this->now(), [(int)$s->getId()]);
		try {
			return $this->submissions->find((int)$s->getId());
		} catch (DoesNotExistException) {
			return $s;
		}
	}

	// Listen: alle Abgaben einer Aufgabe auf einmal
	public function materializeAssignment(Assignment $a): void {
		$this->submissions->materialize((int)$a->getId(), (int)$a->getDueAt(), $this->now());
	}

	/** §9.3: eigene Abgabe, offen, Frist läuft, Aufgabe ausgeteilt, Team aktiv, Schüler aktuell Level 1 */
	public function studentCanEdit(string $uid, Submission $s, Assignment $a): bool {
		if ($s->getStudentUid() === null || $s->getStudentUid() !== $uid || !in_array($s->getStatus(), ['assigned', 'reopened'], true)) {
			return false;
		}
		$due = self::effectiveDue($s, $a);
		if (($due !== null && $this->now() > $due) || $a->getStatus() !== 'distributed') {
			return false;
		}
		$teamId = (string)$a->getTeamId();
		return $this->teamState->stateOf($teamId) === TeamStateService::ACTIVE && $this->teams->getLevel($teamId, $uid) === self::STUDENT_LEVEL;
	}

	/**
	 * Übergang als bedingtes UPDATE (WHERE status = alt); 0 Zeilen oder nicht erlaubt → 409 invalid_transition
	 * @param array<string, int|string|null> $values zusätzliche Spalten
	 */
	public function transition(Submission $s, string $action, array $values = []): Submission {
		$from = (string)$s->getStatus();
		$to = self::TRANSITIONS[$action][$from] ?? null;
		if ($to === null) {
			throw new ConflictException('This change is not possible in the current state', ['code' => 'invalid_transition', 'status' => $from]);
		}
		$values = ['status' => $to, 'updated_at' => $this->now()] + $values;
		if (!$this->submissions->updateIfStatus((int)$s->getId(), $from, $values)) {
			throw new ConflictException('The submission was changed in the meantime', ['code' => 'invalid_transition']);
		}
		return $this->submissions->find((int)$s->getId());
	}
}
