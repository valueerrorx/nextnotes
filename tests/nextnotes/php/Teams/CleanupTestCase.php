<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Teams;

use OCA\NextNotes\Db\AssignmentMapper;
use OCA\NextNotes\Db\AttachmentMapper;
use OCA\NextNotes\Db\FolderMapper;
use OCA\NextNotes\Db\LockMapper;
use OCA\NextNotes\Db\MentionHandledMapper;
use OCA\NextNotes\Db\NoteMapper;
use OCA\NextNotes\Db\ReminderMapper;
use OCA\NextNotes\Db\SnapshotMapper;
use OCA\NextNotes\Db\SubmissionMapper;
use OCA\NextNotes\Db\TeamMapper;
use OCA\NextNotes\Db\WorkspaceMapper;
use OCA\NextNotes\Db\WsShareMapper;
use OCA\NextNotes\Service\AccessService;
use OCA\NextNotes\Service\AssignmentDeletion;
use OCA\NextNotes\Service\AssignmentNotifications;
use OCA\NextNotes\Service\AssignmentOwnership;
use OCA\NextNotes\Service\AttachmentService;
use OCA\NextNotes\Service\BackgroundFiles;
use OCA\NextNotes\Service\OrphanFileCleanup;
use OCA\NextNotes\Service\TeamCleanupService;
use OCA\NextNotes\Service\TeamLifecycleService;
use OCA\NextNotes\Service\TeamService;
use OCA\NextNotes\Service\TeamStateService;
use OCA\NextNotes\Service\Transaction;
use OCA\NextNotes\Service\WorkspaceDeletion;
use OCA\NextNotes\Service\WorkspaceService;
use OCA\NextNotes\Tests\Support\TeamTestCase;
use OCA\NextNotes\Tests\Support\Teams;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use OCP\Server;
use Psr\Log\LoggerInterface;

// Basis M5c: CleanupJob-Dienste mit gemockter Zeit und optional gestubbter Existenzprüfung
abstract class CleanupTestCase extends TeamTestCase {
	protected const DAY = 86400;
	protected int $now = 0;
	/** @var string[] per DB angelegte Team-Zeilen */
	private array $rawTeams = [];

	protected function setUp(): void {
		parent::setUp();
		// wie cron.php: ohne geladene notifications-App verwirft IManager::notify still
		Server::get(IAppManager::class)->loadApp('notifications');
		$this->now = time();
		// Reste früherer Läufe verfälschen Massenschutz und Zählung
		$qb = self::db()->getQueryBuilder();
		$qb->select('team_id')->from('nextnotes_teams');
		foreach ($qb->executeQuery()->fetchFirstColumn() as $id) {
			Teams::purgeTeamData((string)$id);
		}
		self::resetCursors();
		self::dropAdminNotices();
	}

	protected function tearDown(): void {
		foreach ($this->rawTeams as $id) {
			Teams::purgeTeamData($id);
		}
		$this->rawTeams = [];
		self::resetCursors();
		self::dropAdminNotices();
		parent::tearDown();
	}

	protected static function resetCursors(): void {
		foreach (['cleanup_team_cursor', 'cleanup_files_cursor', 'cleanup_preview_cursor', 'cleanup_team_errors'] as $k) {
			Server::get(IAppConfig::class)->deleteKey('nextnotes', $k);
		}
	}

	protected static function dropAdminNotices(): void {
		$qb = self::db()->getQueryBuilder();
		$qb->delete('notifications')->where($qb->expr()->eq('app', $qb->createNamedParameter('nextnotes')))
			->andWhere($qb->expr()->eq('object_type', $qb->createNamedParameter('cleanup')))->executeStatement();
	}

	protected function time(): ITimeFactory {
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn () => $this->now);
		return $time;
	}

	/** Existenzprüfung als Stub: $missing → null, $failing → Fehler, sonst vorhanden; $onCall z. B. Zeit vorstellen */
	protected function stubTeams(array $missing, array $failing = [], ?\Closure $onCall = null, bool $available = true): TeamService {
		$teams = $this->createStub(TeamService::class);
		$teams->method('isAvailable')->willReturn($available);
		$teams->method('exists')->willReturnCallback(function (string $id) use ($missing, $failing, $onCall) {
			if ($onCall !== null) {
				$onCall($id);
			}
			if (in_array($id, $failing, true)) {
				throw new \OCA\NextNotes\Service\Exception\TeamUnavailableException('stub');
			}
			return in_array($id, $missing, true) ? null : ['id' => $id, 'name' => 'Stub ' . substr($id, 0, 6)];
		});
		return $teams;
	}

	protected function service(?TeamService $teams = null): TeamCleanupService {
		$time = $this->time();
		$teams ??= Server::get(TeamService::class);
		$teams->resetCache();
		$state = new TeamStateService(Server::get(TeamMapper::class), $teams, $time);
		$tx = Server::get(Transaction::class);
		$ownership = new AssignmentOwnership(
			Server::get(AssignmentMapper::class), $teams, $state, Server::get(AssignmentNotifications::class), Server::get(IUserManager::class), $time, Server::get(LoggerInterface::class),
		);
		$lifecycle = new TeamLifecycleService(
			$teams, $state, Server::get(TeamMapper::class), Server::get(WorkspaceMapper::class), Server::get(NoteMapper::class),
			Server::get(FolderMapper::class), Server::get(ReminderMapper::class), Server::get(MentionHandledMapper::class), Server::get(LockMapper::class), Server::get(WsShareMapper::class),
			Server::get(BackgroundFiles::class), Server::get(WorkspaceService::class), $tx, Server::get(INotificationManager::class), $time,
			Server::get(LoggerInterface::class), Server::get(AssignmentMapper::class), Server::get(SubmissionMapper::class), $ownership,
		);
		return new TeamCleanupService(
			$teams, $state, Server::get(TeamMapper::class), $lifecycle, Server::get(WorkspaceMapper::class), Server::get(WorkspaceDeletion::class),
			Server::get(WorkspaceService::class), Server::get(AccessService::class), $tx, $this->files(), Server::get(INotificationManager::class),
			Server::get(IGroupManager::class), Server::get(IAppConfig::class), $time, Server::get(LoggerInterface::class),
			Server::get(AssignmentMapper::class), Server::get(AssignmentDeletion::class), $ownership,
		);
	}

	protected function files(): OrphanFileCleanup {
		return new OrphanFileCleanup(
			Server::get(IRootFolder::class), Server::get(AttachmentMapper::class), Server::get(NoteMapper::class), Server::get(SnapshotMapper::class),
			Server::get(AttachmentService::class), Server::get(Transaction::class), Server::get(IAppConfig::class), $this->time(), Server::get(LoggerInterface::class),
		);
	}

	/** Team-Zeile per DB (ohne Circles) */
	protected function rawTeam(string $prefix = 'raw', string $state = 'active', ?int $orphanedAt = null, ?int $notifiedAt = null, int $missing = 0): string {
		$id = $prefix . bin2hex(random_bytes(10));
		$qb = self::db()->getQueryBuilder();
		$qb->insert('nextnotes_teams')->values([
			'team_id' => $qb->createNamedParameter($id),
			'state' => $qb->createNamedParameter($state),
			'orphaned_at' => $qb->createNamedParameter($orphanedAt, $orphanedAt === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT),
			'missing_count' => $qb->createNamedParameter($missing, IQueryBuilder::PARAM_INT),
			'team_name_snapshot' => $qb->createNamedParameter('Stub ' . substr($id, 0, 6)),
			'deletion_notified_at' => $qb->createNamedParameter($notifiedAt, $notifiedAt === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT),
			'created_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
			'updated_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
		])->executeStatement();
		$this->rawTeams[] = $id;
		return $id;
	}

	/** @return array<string, mixed>|null */
	protected static function teamRowOrNull(string $teamId): ?array {
		$qb = self::db()->getQueryBuilder();
		$qb->select('*')->from('nextnotes_teams')->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)));
		$row = $qb->executeQuery()->fetchAssociative();
		return $row === false ? null : $row;
	}

	protected static function setTeamRow(string $teamId, array $values): void {
		$qb = self::db()->getQueryBuilder();
		$qb->update('nextnotes_teams');
		foreach ($values as $col => $v) {
			$qb->set($col, $qb->createNamedParameter($v, $v === null ? IQueryBuilder::PARAM_NULL : (is_int($v) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR)));
		}
		$qb->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))->executeStatement();
		Teams::reset();
	}

	protected static function rowCount(string $table, array $where): int {
		$qb = self::db()->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from($table);
		foreach ($where as $col => $v) {
			$qb->andWhere(is_int($v)
				? $qb->expr()->eq($col, $qb->createNamedParameter($v, IQueryBuilder::PARAM_INT))
				: $qb->expr()->eq($col, $qb->createNamedParameter($v)));
		}
		return (int)$qb->executeQuery()->fetchOne();
	}

	protected static function teamNotices(string $uid, string $teamId, string $subject): int {
		return self::rowCount('notifications', ['app' => 'nextnotes', 'user' => $uid, 'object_type' => 'team', 'object_id' => $teamId, 'subject' => $subject]);
	}

	protected static function occ(string ...$args): array {
		exec('php /var/www/html/occ ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $out, $rc);
		return [$rc, implode("\n", $out)];
	}
}
