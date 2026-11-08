<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service;

use OCA\NextNotes\Db\Assignment;
use OCA\NextNotes\Db\AssignmentMapper;
use OCA\NextNotes\Service\Exception\TeamUnavailableException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

// Übergang owner_uid (SPEC §9.7, ADR 0008 c): Systempfad ohne Sitzung; ändert nur bei bestätigtem Team und bestätigter Ziel-Lehrkraft, NULL nur bei Kontolöschung
class AssignmentOwnership {
	public const TEACHER_LEVEL = 4;
	public const DELETED = 'deleted';
	public const LEFT = 'left';
	public const DEMOTED = 'demoted';
	public const ADMIN = 'admin';

	public function __construct(
		private AssignmentMapper $assignments,
		private TeamService $teams,
		private TeamStateService $teamState,
		private AssignmentNotifications $notify,
		private IUserManager $users,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Ziel-Lehrkraft: Team fehlerfrei vorhanden, höchstes Level, dann kleinste UID, Level einzeln bestätigt; null = kein Ziel
	 * @throws TeamUnavailableException Circles-Fehler (nie „kein Ziel“ vortäuschen)
	 */
	public function target(string $teamId, string $exclude): ?string {
		if ($this->teams->exists($teamId) === null) {
			return null;
		}
		$candidates = $this->teams->teachers($teamId, self::TEACHER_LEVEL);
		unset($candidates[$exclude]);
		uksort($candidates, static fn ($a, $b) => ($candidates[$b] <=> $candidates[$a]) ?: strcmp((string)$a, (string)$b));
		foreach (array_keys($candidates) as $uid) {
			if ($this->teams->currentLevel($teamId, (string)$uid) >= self::TEACHER_LEVEL) {
				return (string)$uid;
			}
		}
		return null;
	}

	/** Systempfad transferOwnership (§9.7): ohne Ziel bleibt owner_uid, außer bei Kontolöschung (NULL); wirft nie */
	public function transferOwnership(int $assignmentId, string $reason): void {
		try {
			$a = $this->assignments->find($assignmentId);
		} catch (DoesNotExistException) {
			return;
		}
		$this->transferAll([$a], (string)$a->getOwnerUid(), $reason);
	}

	/** Kontolöschung (synchron im Listener): alle Aufgaben der UID an eine Lehrkraft, sonst NULL; wirft nie */
	public function releaseDeleted(string $uid): void {
		try {
			$this->transferAll($this->assignments->findOwned($uid), $uid, self::DELETED);
		} catch (\Throwable $e) {
			// Aufrufer setzt verbliebene owner_uid in seiner Transaktion auf NULL
			$this->logger->error('nextnotes: assignment transfer on account deletion failed', ['exception' => $e]);
		}
	}

	// Austritt (Level 0 vom Aufrufer bestätigt): nur Aufgaben dieses Teams
	public function memberLeft(string $teamId, string $uid): void {
		$this->transferAll($this->assignments->findOwned($uid, $teamId), $uid, self::LEFT);
	}

	/** CleanupJob: owner_uid mit Level < 4 in aktiven Teams (Herabstufung, verpasster Austritt); mindestens ein Besitzer je Lauf */
	public function checkDemoted(int $deadline): void {
		if (!$this->teams->isAvailable()) {
			return;
		}
		foreach ($this->assignments->ownersInActiveTeams() as $i => $row) {
			if ($i > 0 && $this->time->getTime() >= $deadline) {
				return;
			}
			try {
				if ($this->teams->currentLevel($row['team'], $row['owner']) >= self::TEACHER_LEVEL) {
					continue;
				}
			} catch (TeamUnavailableException $e) {
				$this->logger->warning('nextnotes: owner level check skipped', ['team' => $row['team'], 'exception' => $e]);
				continue;
			}
			$this->transferAll($this->assignments->findOwned($row['owner'], $row['team']), $row['owner'], self::DEMOTED);
		}
	}

	/**
	 * occ nextnotes:assignment:transfer: aktives Team → Ziel muss Lehrkraft sein (auch mit --force); verwaist, eingefroren, unbekannt → nur mit --force
	 * @return array{from: ?string, to: string, state: string}
	 * @throws \InvalidArgumentException
	 */
	public function adminTransfer(Assignment $a, string $to, bool $force): array {
		if (!$this->users->userExists($to)) {
			throw new \InvalidArgumentException('User "' . $to . '" does not exist');
		}
		$teamId = (string)$a->getTeamId();
		$state = $this->teamState->stateOf($teamId);
		if ($state === TeamStateService::ACTIVE) {
			try {
				$state = $this->teams->exists($teamId) === null ? 'unknown' : $state;
			} catch (TeamUnavailableException) {
				$state = 'unknown';
			}
		}
		if ($state === TeamStateService::ACTIVE) {
			try {
				$level = $this->teams->currentLevel($teamId, $to);
			} catch (TeamUnavailableException $e) {
				throw new \InvalidArgumentException('The team level of "' . $to . '" could not be checked', 0, $e);
			}
			if ($level < self::TEACHER_LEVEL) {
				throw new \InvalidArgumentException('"' . $to . '" is not a teacher (moderator or higher) of the active team');
			}
		} elseif (!$force) {
			throw new \InvalidArgumentException('The team is ' . $state . '; use --force to transfer to any existing user');
		}
		$from = $a->getOwnerUid();
		if (!$this->assignments->setOwner((int)$a->getId(), $from, $to, $this->time->getTime())) {
			throw new \InvalidArgumentException('The owner changed in the meantime; try again');
		}
		$this->logger->warning('nextnotes: assignment transferred by admin', ['assignment' => (int)$a->getId(), 'from' => $from, 'to' => $to, 'teamState' => $state]);
		$this->notify->transferred((int)$a->getId(), $to, self::ADMIN);
		return ['from' => $from, 'to' => $to, 'state' => $state];
	}

	/**
	 * Gemeinsamer Weg: Ziel je Team einmal ermitteln, bedingtes UPDATE auf den alten Besitzer, Meldung an das Ziel
	 * @param Assignment[] $list
	 */
	private function transferAll(array $list, string $from, string $reason): void {
		if ($from === '') {
			return;
		}
		$targets = [];
		foreach ($list as $a) {
			$teamId = (string)$a->getTeamId();
			if (!array_key_exists($teamId, $targets)) {
				$targets[$teamId] = $this->safeTarget($teamId, $from, $reason);
			}
			$to = $targets[$teamId];
			// ohne bestätigtes Ziel bleibt owner_uid; nur die gelöschte UID darf nicht stehen bleiben (§2 UID-Regel)
			if ($to === null && $reason !== self::DELETED) {
				continue;
			}
			if ($this->assignments->setOwner((int)$a->getId(), $from, $to, $this->time->getTime())) {
				$this->logger->info('nextnotes: assignment owner changed', ['assignment' => (int)$a->getId(), 'reason' => $reason, 'to' => $to]);
				if ($to !== null) {
					$this->notify->transferred((int)$a->getId(), $to, $reason);
				}
			}
		}
	}

	// Verwaist/eingefroren: kein Übergang (Export in der Frist bleibt beim Besitzer); Fehler → kein Ziel
	private function safeTarget(string $teamId, string $from, string $reason): ?string {
		if ($reason !== self::DELETED && $this->teamState->stateOf($teamId) !== TeamStateService::ACTIVE) {
			return null;
		}
		try {
			return $this->target($teamId, $from);
		} catch (\Throwable $e) {
			$this->logger->warning('nextnotes: assignment transfer target not confirmed', ['team' => $teamId, 'reason' => $reason, 'exception' => $e]);
			return null;
		}
	}
}
