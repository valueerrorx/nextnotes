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
use OCA\NextNotes\Db\SubmissionMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

// AssignmentDeadlineJob (Plan M6 §2.4): Frist materialisieren, Sammelmeldung an Lehrkräfte (Q4), Nachzügler/Austrittsmarke; je Schritt mindestens eine Aufgabe, dann Budget
class AssignmentDeadlineService {
	private const BATCH = 100;
	private const CURSOR = 'deadline_job_cursor';
	private const STUDENT_LEVEL = 1;
	private const TEACHER_LEVEL = 4;

	public function __construct(
		private AssignmentMapper $assignments,
		private SubmissionMapper $submissions,
		private SubmissionStateService $states,
		private DistributionService $distribution,
		private TeamService $teams,
		private TeamStateService $teamState,
		private AccessService $access,
		private AssignmentNotifications $notify,
		private IAppConfig $config,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	public function run(int $budget): void {
		$deadline = $this->time->getTime() + max(0, $budget);
		$this->access->resetCache();
		$this->materializeDue($deadline);
		$this->notifyDeadlines($deadline);
		$this->reconcileAll($deadline);
	}

	// offene Abgaben nach Frist → missed, ohne dass jemand zugreift
	private function materializeDue(int $deadline): void {
		foreach ($this->submissions->assignmentIdsOverdue($this->time->getTime()) as $i => $id) {
			if ($i > 0 && $this->time->getTime() >= $deadline) {
				return;
			}
			$a = $this->find($id);
			if ($a !== null) {
				$this->states->materializeAssignment($a);
			}
		}
	}

	// einmal je Frist (bedingtes UPDATE): abgegeben, verpasst, nicht ausgeteilt
	private function notifyDeadlines(int $deadline): void {
		foreach ($this->assignments->findDeadlinePassed($this->time->getTime(), self::BATCH) as $i => $a) {
			if ($i > 0 && $this->time->getTime() >= $deadline) {
				return;
			}
			$id = (int)$a->getId();
			$this->states->materializeAssignment($a);
			if (!$this->assignments->markDeadlineNotified($id, $this->time->getTime())) {
				continue;
			}
			try {
				$counts = $this->counts($a);
				foreach ($this->teachers($a) as $uid) {
					$this->notify->deadlinePassed($id, $uid, $counts);
				}
			} catch (\Throwable $e) {
				$this->logger->error('nextnotes: deadline summary for assignment ' . $id . ' failed', ['exception' => $e, 'app' => Application::APP_ID]);
			}
		}
	}

	/** @return array{submitted: int, missed: int, not_distributed: int} */
	private function counts(Assignment $a): array {
		$byStatus = $this->submissions->statusCounts((int)$a->getId());
		$teamId = (string)$a->getTeamId();
		$missing = 0;
		if ($this->teamState->stateOf($teamId) === TeamStateService::ACTIVE) {
			$missing = count(array_diff_key($this->teams->members($teamId, self::STUDENT_LEVEL), $this->submissions->studentsOf((int)$a->getId())));
		}
		return [
			'submitted' => ($byStatus['submitted'] ?? 0) + ($byStatus['returned'] ?? 0),
			'missed' => $byStatus['missed'] ?? 0,
			'not_distributed' => $missing,
		];
	}

	/** @return string[] aktuelle Lehrkräfte (aktives Team, Level ≥ 4), sonst owner_uid (§9.7) */
	private function teachers(Assignment $a): array {
		$teamId = (string)$a->getTeamId();
		if ($this->teamState->stateOf($teamId) !== TeamStateService::ACTIVE) {
			return $a->getOwnerUid() !== null ? [(string)$a->getOwnerUid()] : [];
		}
		$out = [];
		foreach (array_keys($this->teams->members($teamId)) as $uid) {
			if ($this->teams->getLevel($teamId, (string)$uid) >= self::TEACHER_LEVEL) {
				$out[] = (string)$uid;
			}
		}
		return $out;
	}

	// Nachzügler (Frist offen) und Austrittsmarke (unabhängig von der Frist, H5); Cursor setzt nach Budget-Abbruch fort
	private function reconcileAll(int $deadline): void {
		$ids = array_values(array_unique(array_merge($this->assignments->idsOpenDistributed($this->time->getTime()), $this->submissions->assignmentIdsWithLeft())));
		sort($ids);
		$cursor = $this->config->getValueInt(Application::APP_ID, self::CURSOR, 0, true);
		$todo = array_values(array_filter($ids, static fn (int $id) => $id > $cursor));
		if ($todo === [] && $cursor > 0) {
			$todo = $ids;
		}
		$last = 0;
		foreach ($todo as $i => $id) {
			if ($i > 0 && $this->time->getTime() >= $deadline) {
				$this->config->setValueInt(Application::APP_ID, self::CURSOR, $last, true);
				return;
			}
			$a = $this->find($id);
			if ($a !== null) {
				$this->teams->resetCache();
				try {
					// mindestens ein Schüler je Aufgabe, auch bei erschöpftem Budget
					$this->distribution->reconcile($a, null, max($deadline, $this->time->getTime() + 1));
				} catch (\Throwable $e) {
					$this->logger->error('nextnotes: reconcile of assignment ' . $id . ' failed', ['exception' => $e, 'app' => Application::APP_ID]);
				}
			}
			$last = $id;
		}
		$this->config->setValueInt(Application::APP_ID, self::CURSOR, 0, true);
	}

	private function find(int $id): ?Assignment {
		try {
			return $this->assignments->find($id);
		} catch (DoesNotExistException) {
			return null;
		}
	}
}
