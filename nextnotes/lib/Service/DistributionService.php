<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service;

use OCA\NextNotes\AppInfo\Application;
use OCA\NextNotes\Db\Assignment;
use OCA\NextNotes\Db\AssignmentMapper;
use OCA\NextNotes\Db\Folder;
use OCA\NextNotes\Db\FolderMapper;
use OCA\NextNotes\Db\NoteMapper;
use OCA\NextNotes\Db\Submission;
use OCA\NextNotes\Db\SubmissionMapper;
use OCA\NextNotes\Db\TeamMapper;
use OCA\NextNotes\Db\WorkspaceMapper;
use OCA\NextNotes\Service\Exception\ConflictException;
use OCA\NextNotes\Service\Exception\NotFoundException;
use OCA\NextNotes\Service\Exception\TooLargeException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbException;
use OCP\IUserManager;
use OCP\L10N\IFactory;

// Austeilen und Nachzügler (SPEC §9.1/§9.4, Plan M6 §2.3a/§2.4): je Schüler eine Transaktion, nie ein halber Zustand; Rechte prüft der Aufrufer
class DistributionService {
	// Zeitbudget je Request; Rest über reconcile (Zugriff des Schülers, erneutes Austeilen, Job M6e2)
	public const BUDGET_SECONDS = 20;
	private const STUDENT_LEVEL = 1;
	private const CREATED = 'created';
	private const EXISTS = 'exists';
	private const STOP = 'stop';
	private const QUOTA = 'quota';

	public function __construct(
		private AssignmentMapper $assignments,
		private SubmissionMapper $submissions,
		private NoteMapper $notes,
		private FolderMapper $folders,
		private WorkspaceMapper $workspaces,
		private TeamMapper $teamRows,
		private TeamService $teams,
		private TeamStateService $teamState,
		private DuplicateService $duplicates,
		private AttachmentService $attachments,
		private QuotaService $quota,
		private Transaction $tx,
		private ITimeFactory $time,
		private IUserManager $users,
		private IFactory $l10nFactory,
		private AssignmentNotifications $notify,
		private IAppManager $apps,
	) {
	}

	/**
	 * draft → distributed (§9.2): Vorprüfung Quote ohne Sperre (413 vorab), dann Team → Aufgabe → Vorlage gesperrt (Review M6-Plan r2 Hinweis 1)
	 */
	public function start(Assignment $a): Assignment {
		$id = (int)$a->getId();
		$this->checkDeadline($a);
		if ($a->getStatus() === 'draft') {
			$this->precheckQuota($a);
		}
		$teamId = (string)$a->getTeamId();
		// erste Anweisung schreibt (SQLite: Schreibsperre vor jedem Lesen)
		return $this->tx->run(function () use ($id, $teamId): Assignment {
			if ($this->teamRows->lockRow($teamId) === 0 || $this->assignments->lockRow($id) === 0) {
				throw new NotFoundException('Assignment not found');
			}
			$a = $this->assignments->find($id);
			$this->checkDeadline($a);
			if ($a->getStatus() === 'draft') {
				$this->notes->lockRow((int)$a->getTemplateNoteId());
				$now = $this->time->getTime();
				$a->setStatus('distributed');
				$a->setDistributedAt($now);
				$a->setUpdatedAt($now);
				$a = $this->assignments->update($a);
			} elseif ($a->getStatus() !== 'distributed') {
				throw new ConflictException('This assignment is closed', ['code' => 'invalid_state']);
			}
			return $a;
		});
	}

	private function checkDeadline(Assignment $a): void {
		if ($a->getStatus() === 'closed') {
			throw new ConflictException('This assignment is closed', ['code' => 'invalid_state']);
		}
		if ($this->time->getTime() > (int)$a->getDueAt()) {
			throw new ConflictException('The deadline has passed', ['code' => 'deadline_passed']);
		}
	}

	/**
	 * Hinweis „N noch nicht ausgeteilt“ (Plan M6 §2.3a (4)): Mitglieder mit Level 1 ohne Abgabe, Grund volle Team-Quote; nur ausgeteilt, Frist offen, Team aktiv
	 * @return array{not_distributed: int, quota_full: bool}
	 */
	public function pending(Assignment $a): array {
		$teamId = (string)$a->getTeamId();
		if ($a->getStatus() !== 'distributed' || $this->time->getTime() > (int)$a->getDueAt() || $this->teamState->stateOf($teamId) !== TeamStateService::ACTIVE) {
			return ['not_distributed' => 0, 'quota_full' => false];
		}
		$missing = count($this->missingStudents($a));
		$bytes = $missing > 0 ? $this->attachments->bytesOf([(int)$a->getTemplateNoteId()]) : 0;
		return ['not_distributed' => $missing, 'quota_full' => $bytes > 0 && $bytes > $this->quota->limit() - $this->quota->used('team', $teamId)];
	}

	/**
	 * Schüler (Level 1) ohne Abgabe mit aktivem Konto und App-Freigabe; andere erhalten nichts und zählen nicht als ausstehend (SPEC §8, M7b, M7h)
	 * @param array<string, string>|null $students uid → Name, null = alle Schüler des Teams
	 * @return array<string, string>
	 */
	public function missingStudents(Assignment $a, ?array $students = null): array {
		$students ??= $this->teams->members((string)$a->getTeamId(), self::STUDENT_LEVEL);
		if ($students === []) {
			return [];
		}
		$missing = array_diff_key($students, $this->submissions->studentsOf((int)$a->getId()));
		return array_filter($missing, fn ($uid): bool => $this->canReceive((string)$uid), ARRAY_FILTER_USE_KEY);
	}

	// Konto aktiv und Next Notes für das Konto freigegeben (Gruppenbeschränkung, M7h); später freigeschaltet → Nachzügler
	private function canReceive(string $uid): bool {
		$user = $this->users->get($uid);
		return $user !== null && $user->isEnabled() && $this->apps->isEnabledForUser(Application::APP_ID, $user);
	}

	// §2.3a (1): Anhänge der Vorlage × Schüler ohne Abgabe gegen den Rest der Team-Quote
	private function precheckQuota(Assignment $a): void {
		$bytes = $this->attachments->bytesOf([(int)$a->getTemplateNoteId()]);
		if ($bytes <= 0) {
			return;
		}
		$missing = count($this->missingStudents($a));
		$needed = $bytes * $missing;
		$available = max(0, $this->quota->limit() - $this->quota->used('team', (string)$a->getTeamId()));
		if ($needed > $available) {
			throw new TooLargeException('Storage quota of the team exceeded', ['code' => 'quota_exceeded', 'needed' => $needed, 'available' => $available]);
		}
	}

	/**
	 * Abgleich (§2.4): (a) Austrittsmarke löschen, wenn der Schüler wieder im aktiven Team ist (unabhängig von Frist/Status, H5);
	 * (b) nur ausgeteilt, Frist offen, Team aktiv: fehlende Abgaben anlegen; $onlyUid = nur dieser Schüler; $notify = false beim eigenen Zugriff des Schülers (sieht die Aufgabe ohnehin)
	 * @return array{created: int, pending: int, error: ?string}
	 */
	public function reconcile(Assignment $a, ?string $onlyUid = null, ?int $deadline = null, bool $notify = true): array {
		$teamId = (string)$a->getTeamId();
		$active = $this->teamState->stateOf($teamId) === TeamStateService::ACTIVE;
		$now = $this->time->getTime();
		foreach ($active ? $this->submissions->findLeft((int)$a->getId()) : [] as $s) {
			if (($onlyUid === null || $s->getStudentUid() === $onlyUid) && $this->teams->getLevel($teamId, (string)$s->getStudentUid()) >= self::STUDENT_LEVEL) {
				$this->submissions->clearLeft((int)$s->getId(), $now);
			}
		}
		$out = ['created' => 0, 'pending' => 0, 'error' => null];
		if (!$active || $a->getStatus() !== 'distributed' || $now > (int)$a->getDueAt()) {
			return $out;
		}
		if ($onlyUid !== null) {
			$students = $this->teams->getLevel($teamId, $onlyUid) === self::STUDENT_LEVEL ? [$onlyUid => $this->users->getDisplayName($onlyUid) ?? $onlyUid] : [];
		} else {
			$students = $this->teams->members($teamId, self::STUDENT_LEVEL);
		}
		$missing = $this->missingStudents($a, $students);
		ksort($missing, SORT_STRING);
		$bytes = $missing === [] ? 0 : $this->attachments->bytesOf([(int)$a->getTemplateNoteId()]);
		$left = count($missing);
		foreach ($missing as $uid => $name) {
			if ($deadline !== null && $this->time->getTime() >= $deadline) {
				break;
			}
			try {
				// billige Vorprüfung ohne Sperre: bei bekannter Überschreitung kein Kopierversuch (Review M6-Plan r2 Hinweis 3)
				$quota = $this->quota->quotaForTeam($teamId, $bytes);
			} catch (TooLargeException) {
				$out['error'] = 'quota_exceeded';
				break;
			}
			$result = $this->createFor((int)$a->getId(), $teamId, (string)$uid, (string)$name, $quota);
			if ($result === self::QUOTA) {
				$out['error'] = 'quota_exceeded';
				break;
			}
			if ($result === self::STOP) {
				break;
			}
			$left--;
			if ($result === self::CREATED) {
				$out['created']++;
				if ($notify) {
					$this->notify->assigned((int)$a->getId(), (string)$uid);
				}
			}
		}
		$out['pending'] = $left;
		return $out;
	}

	/**
	 * Eine Transaktion je Schüler: Team-Zeile (Quote) → Aufgabe neu lesen → Schüler-Workspace → Ordner → Abgabe → Kopie mit Anhängen.
	 * Paralleles Löschen/Schließen/Team-Purge gewinnt (STOP); Dateien erst nach enforce, bei Rollback entfernt
	 * @param array{type: string, id: string, ws: int, bytes: int}|null $quota
	 */
	private function createFor(int $id, string $teamId, string $uid, string $name, ?array $quota): string {
		$created = [];
		try {
			return $this->tx->run(function () use ($id, $teamId, $uid, $name, $quota, &$created): string {
				if ($quota !== null) {
					$this->quota->enforce($quota);
				} elseif ($this->teamRows->lockRow($teamId) === 0) {
					return self::STOP;
				}
				if ($this->assignments->lockRow($id) === 0) {
					return self::STOP;
				}
				$a = $this->assignments->find($id);
				$now = $this->time->getTime();
				$team = $this->teamRows->findByTeamId($teamId);
				if ($a->getStatus() !== 'distributed' || $now > (int)$a->getDueAt() || $team === null || $team->getState() !== TeamStateService::ACTIVE) {
					return self::STOP;
				}
				// Konto gelöscht (Rennen mit UserDeletedListener), deaktiviert (occ delete-user-data, M7b) oder ohne App-Freigabe (M7h): nichts anlegen (SPEC §8)
				if ($this->submissions->existsFor($id, $uid) || !$this->canReceive($uid)) {
					return self::EXISTS;
				}
				$ws = $this->workspaces->ensureAssignmentWorkspace($uid, $teamId, $this->workspaceName($uid, (string)$team->getTeamNameSnapshot()), $now);
				$wsId = (int)$ws->getId();
				$this->workspaces->lockRows([$wsId]);
				$folder = $this->insertFolder($wsId, $id, (string)$a->getTitle(), $now);
				$sub = new Submission();
				$sub->setAssignmentId($id);
				$sub->setStudentUid($uid);
				$sub->setStudentName(mb_substr($name, 0, 255));
				$sub->setStatus('assigned');
				$sub->setCreatedAt($now);
				$sub->setUpdatedAt($now);
				$sub = $this->submissions->insert($sub);
				$copy = $this->duplicates->copyAsSubmission($this->notes->find((int)$a->getTemplateNoteId()), $wsId, (int)$folder->getId(), $id, (int)$sub->getId(), $uid, $created);
				$sub->setNoteId((int)$copy->getId());
				$this->submissions->update($sub);
				return self::CREATED;
			});
		} catch (\Throwable $e) {
			foreach ($created as $noteId) {
				$this->attachments->discardFiles($noteId);
			}
			return match (true) {
				$e instanceof TooLargeException => self::QUOTA,
				$e instanceof NotFoundException, $e instanceof DoesNotExistException => self::STOP,
				// gleichzeitiges Austeilen: UNIQUE (assignment_id, student_uid) = schon vorhanden (§9.4)
				$e instanceof DbException && $e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION => self::EXISTS,
				default => throw $e,
			};
		}
	}

	// Aufgaben-Ordner im Schüler-Workspace (Name = Titel, bei Gleichnamigkeit nummeriert)
	private function insertFolder(int $wsId, int $assignmentId, string $title, int $now): Folder {
		$name = $title;
		for ($i = 1; $this->folders->findSibling($wsId, null, $name) !== null; $i++) {
			$name = mb_substr($title, 0, 240) . ' (' . $i . ')';
		}
		$f = new Folder();
		$f->setWorkspaceId($wsId);
		$f->setParentId(null);
		$f->setName($name);
		$f->setDisplayOrder(0);
		foreach (['Pinned', 'Favorite', 'KanbanEnabled', 'IsDiary', 'Material'] as $flag) {
			$f->{'set' . $flag}(0);
		}
		$f->setAssignmentId($assignmentId);
		$f->setCreatedAt($now);
		$f->setUpdatedAt($now);
		return $this->folders->insert($f);
	}

	// „Aufgaben – <Team>“ in der Sprache des Schülers
	private function workspaceName(string $uid, string $teamName): string {
		$l = $this->l10nFactory->get(Application::APP_ID, $this->l10nFactory->getUserLanguage($this->users->get($uid)));
		return $l->t('Assignments – %s', [$teamName]);
	}
}
