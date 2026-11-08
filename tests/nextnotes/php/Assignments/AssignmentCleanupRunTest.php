<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Assignments;

use OCA\NextNotes\Service\TeamCleanupService;
use OCA\NextNotes\Tests\Support\Teams;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Server;

// M6f-r1 Hinweis 2: Done 3 (Herabstufung) und Done 4 (Tag 30) über TeamCleanupService::run mit gemockter Zeit statt direkter Aufrufe
final class AssignmentCleanupRunTest extends AssignmentTestCase {
	private const BUDGET = 600;

	public function testRunPurgesOrphanedTeamAfterThirtyDays(): void {
		$fx = $this->distributedFixture();
		['l' => $l, 't' => $t, 'aid' => $aid] = $fx;
		$this->teams->destroy($l->uid, $t, true);
		self::assertSame('orphaned', self::teamState($t));
		$now = time();

		// Tag 22: nichts; Tag 23: Hinweis; Tag 29: noch da; Tag 31: gelöscht
		$this->runAt($now + 22 * TeamCleanupService::DAY);
		self::assertNull(self::teamRow($t)['deletion_notified_at'], 'Tag 22: kein Hinweis');
		$this->runAt($now + 23 * TeamCleanupService::DAY + 60);
		self::assertNotNull(self::teamRow($t)['deletion_notified_at'], 'Tag 23: Hinweis');
		$this->runAt($now + 29 * TeamCleanupService::DAY);
		self::assertNotNull(self::row('nextnotes_assignments', $aid), 'Tag 29: Aufgabe bleibt');
		self::assertSame('orphaned', self::teamState($t));
		$this->runAt($now + 31 * TeamCleanupService::DAY);
		self::assertNull(self::row('nextnotes_assignments', $aid), 'Tag 31: Aufgabe gelöscht');
		self::assertSame(0, self::countRows('nextnotes_submissions', 'assignment_id', $aid), 'Abgaben gelöscht');
		self::assertNull(self::teamState($t), 'Team-Zeile gelöscht');
	}

	public function testRunTransfersOnDemotion(): void {
		$fx = $this->fixture(false);
		['l' => $l, 't' => $t, 'src' => $src] = $fx;
		$d = $this->createUser('rd');
		$memberD = $this->teams->addUser($t, $l->uid, $d->uid, Teams::MODERATOR);
		$aid = (int)$this->createAssignment($d, $t, $src, 'Von D')['id'];
		$cm = Teams::cm();
		try {
			$cm->startSession($cm->getLocalFederatedUser($l->uid));
			$cm->levelMember($memberD, 1);
		} finally {
			$cm->stopSession();
		}
		Teams::reset();
		self::assertSame($d->uid, self::row('nextnotes_assignments', $aid)['owner_uid'], 'ohne Lauf: bleibt');
		$this->runAt(time());
		self::assertSame($l->uid, self::row('nextnotes_assignments', $aid)['owner_uid'], 'Lauf: Übergang an Lehrkraft');
	}

	// Kopie des Dienstes mit fester Zeit; alle anderen Abhängigkeiten aus dem Container
	private function runAt(int $ts): void {
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn($ts);
		$svc = clone Server::get(TeamCleanupService::class);
		(new \ReflectionProperty(TeamCleanupService::class, 'time'))->setValue($svc, $time);
		$svc->run(self::BUDGET);
		Teams::reset();
	}

	/** @return array<string, mixed> */
	private static function teamRow(string $teamId): array {
		$qb = self::db()->getQueryBuilder();
		$qb->select('*')->from('nextnotes_teams')->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)));
		$row = $qb->executeQuery()->fetchAssociative();
		self::assertIsArray($row, 'Team-Zeile');
		return $row;
	}
}
