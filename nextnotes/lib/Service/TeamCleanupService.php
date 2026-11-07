<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service;

use OCA\NextNotes\AppInfo\Application;
use OCA\NextNotes\Db\Team;
use OCA\NextNotes\Db\TeamMapper;
use OCA\NextNotes\Db\WorkspaceMapper;
use OCA\NextNotes\Service\Exception\TeamUnavailableException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

// CleanupJob und orphan-check (SPEC §5, Plan M5 §2.5): Existenz nur über TeamService::exists(), im Zweifel nichts löschen
class TeamCleanupService {
	public const DAY = 86400;
	public const ORPHAN_DAYS = 30;
	public const NOTICE_DAYS = 23;
	// Mindestabstand Hinweis → Löschung (Cron war aus: Löschung frühestens 7 Tage nach dem Hinweis)
	public const NOTICE_LEAD_DAYS = 7;
	// Schritt 1 zählt ein fehlendes Team erst beim zweiten Lauf als gelöscht
	private const CONFIRMATIONS = 2;
	// Massenschutz (a): mehr als 10 geprüfte Teams und mehr als die Hälfte fehlt
	private const MASS_MIN_TEAMS = 10;
	private const CURSOR = 'cleanup_team_cursor';
	// teamId → Läufe in Folge mit Fehler (H1); Admin-Meldung einmal ab ERROR_RUNS
	private const ERRORS = 'cleanup_team_errors';
	private const ERROR_RUNS = 3;
	// Mindestanteil des Budgets für Purge und Dateien, unabhängig von Schritt 1 (H3)
	private const RESERVE_DIVISOR = 5;

	public const PRESENT = 'present';
	public const MISSING = 'missing';
	public const ERROR = 'error';

	public function __construct(
		private TeamService $teams,
		private TeamStateService $teamState,
		private TeamMapper $rows,
		private TeamLifecycleService $lifecycle,
		private WorkspaceMapper $workspaces,
		private WorkspaceDeletion $deletion,
		private WorkspaceService $workspaceService,
		private AccessService $access,
		private Transaction $tx,
		private OrphanFileCleanup $files,
		private INotificationManager $notifications,
		private IGroupManager $groups,
		private IAppConfig $config,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	// Löschtermin: 30 Tage nach dem Verwaisen, frühestens 7 Tage nach dem Hinweis
	public static function deletionDate(int $orphanedAt, ?int $notifiedAt): int {
		return max($orphanedAt + self::ORPHAN_DAYS * self::DAY, $notifiedAt === null ? 0 : $notifiedAt + self::NOTICE_LEAD_DAYS * self::DAY);
	}

	/** Täglicher Lauf; Abbruch nur zwischen Teams bzw. Workspaces */
	public function run(int $budget): void {
		$start = $this->time->getTime();
		$reserve = intdiv(max(0, $budget), self::RESERVE_DIVISOR);
		$this->access->resetCache();
		$scan = $this->scan($start + $budget - 2 * $reserve);
		$this->trackErrors($scan['results']);
		if ($scan['error']) {
			$this->logger->warning('nextnotes: team check skipped, teams not available or all lookups failed');
		} else {
			$plan = $this->plan($scan['results'], false, false);
			if ($plan['suspicious']) {
				$this->logger->error('nextnotes: team check suspicious, nothing orphaned', ['checked' => $plan['checked'], 'missing' => $plan['missing']]);
				$this->notifyAdmins($plan['checked'], $plan['missing']);
			}
			$this->apply($plan['rows']);
		}
		$this->notifySoon();
		// Purge und Dateien bekommen je mindestens ein Fünftel des Budgets, auch wenn Schritt 1 seines ausschöpft
		$this->purgeDue(max($this->time->getTime() + $reserve, $start + $budget - $reserve));
		$this->files->run(max($this->time->getTime() + $reserve, $start + $budget));
	}

	/**
	 * orphan-check: alle Teams ohne Budget; ohne $dryRun gelten fehlende Teams sofort als gelöscht (der Admin bestätigt)
	 * @return array{error: bool, suspicious: bool, checked: int, missing: int, rows: list<array{team: string, name: string, state: string, result: string, action: string}>}
	 */
	public function check(bool $dryRun, bool $force): array {
		$this->access->resetCache();
		$scan = $this->scan(null);
		$plan = $this->plan($scan['results'], true, $force);
		if (!$dryRun && !$scan['error']) {
			$this->apply($plan['rows']);
		}
		$rows = array_map(static fn (array $r) => [
			'team' => (string)$r['team']->getTeamId(),
			'name' => (string)($r['name'] ?? $r['team']->getTeamNameSnapshot() ?? ''),
			'state' => (string)$r['team']->getState(),
			'result' => $r['result'],
			'action' => $r['action'],
		], $plan['rows']);
		return ['error' => $scan['error'], 'suspicious' => $plan['suspicious'], 'checked' => $plan['checked'], 'missing' => $plan['missing'], 'rows' => $rows];
	}

	/**
	 * Schritt 1 lesend: jede Team-Zeile über exists(); Fehler bei einem Team → nur dieses übersprungen (nie „fehlt“), Abbruch ohne Circles oder wenn alle scheitern
	 * @return array{error: bool, results: list<array{team: Team, result: string, name: ?string}>}
	 */
	private function scan(?int $deadline): array {
		if (!$this->teams->isAvailable()) {
			return ['error' => true, 'results' => []];
		}
		$all = $this->rows->findAllOrdered();
		// Reihenfolge und Cursor-Vergleich beide in PHP (DB-Kollation weicht von strcmp ab, H5)
		usort($all, static fn (Team $a, Team $b) => strcmp((string)$a->getTeamId(), (string)$b->getTeamId()));
		if ($deadline !== null) {
			// rotierend ab dem Team nach dem letzten Abbruch
			$cursor = $this->config->getValueString(Application::APP_ID, self::CURSOR, '', true);
			$split = 0;
			foreach ($all as $i => $t) {
				if (strcmp((string)$t->getTeamId(), $cursor) > 0) {
					$split = $i;
					break;
				}
				$split = $i + 1;
			}
			$all = array_merge(array_slice($all, $split), array_slice($all, 0, $split));
		}
		$results = [];
		foreach ($all as $t) {
			if ($deadline !== null && $results !== [] && $this->time->getTime() >= $deadline) {
				break;
			}
			try {
				$found = $this->teams->exists((string)$t->getTeamId());
				$results[] = ['team' => $t, 'result' => $found === null ? self::MISSING : self::PRESENT, 'name' => $found['name'] ?? null];
			} catch (TeamUnavailableException $e) {
				$this->logger->warning('nextnotes: team check skipped for team', ['team' => $t->getTeamId(), 'exception' => $e]);
				$results[] = ['team' => $t, 'result' => self::ERROR, 'name' => null];
			}
		}
		if ($deadline !== null) {
			$done = count($results) === count($all);
			$this->config->setValueString(Application::APP_ID, self::CURSOR, $done || $results === [] ? '' : (string)end($results)['team']->getTeamId(), true);
		}
		// alle Abfragen gescheitert: Circles gestört, Schritt 1 entfällt (Fehler je Team werden trotzdem gezählt)
		$failed = $results !== [] && array_filter($results, static fn (array $r) => $r['result'] !== self::ERROR) === [];
		return ['error' => $failed, 'results' => $results];
	}

	/**
	 * Aktionen festlegen; Massenschutz verwirft nur Zählen/Verwaisen, Rückweg und Zurücksetzen bleiben
	 * @param list<array{team: Team, result: string, name: ?string}> $results
	 * @return array{suspicious: bool, checked: int, missing: int, rows: list<array{team: Team, result: string, name: ?string, action: string}>}
	 */
	private function plan(array $results, bool $immediate, bool $force): array {
		$checked = 0;
		$missing = 0;
		foreach ($results as $r) {
			if ($r['team']->getState() === TeamStateService::ACTIVE && $r['result'] !== self::ERROR) {
				$checked++;
				$missing += $r['result'] === self::MISSING ? 1 : 0;
			}
		}
		$suspicious = ($checked > self::MASS_MIN_TEAMS && $missing * 2 > $checked) || ($checked >= 2 && $missing === $checked);
		$rows = [];
		foreach ($results as $r) {
			$t = $r['team'];
			$active = $t->getState() === TeamStateService::ACTIVE;
			if ($r['result'] === self::ERROR) {
				$action = 'none';
			} elseif ($r['result'] === self::PRESENT) {
				$action = $active ? ((int)$t->getMissingCount() > 0 ? 'reset' : 'none') : 'reactivate';
			} elseif (!$active) {
				$action = 'none';
			} elseif ($suspicious && !$force) {
				$action = 'suspicious';
			} else {
				$action = $immediate || (int)$t->getMissingCount() + 1 >= self::CONFIRMATIONS ? 'orphan' : 'count';
			}
			$rows[] = $r + ['action' => $action];
		}
		return ['suspicious' => $suspicious, 'checked' => $checked, 'missing' => $missing, 'rows' => $rows];
	}

	/** @param list<array{team: Team, result: string, name: ?string, action: string}> $rows */
	private function apply(array $rows): void {
		$now = $this->time->getTime();
		foreach ($rows as $r) {
			$id = (string)$r['team']->getTeamId();
			try {
				if ($r['result'] === self::PRESENT && $r['name'] !== null && $r['name'] !== '') {
					$this->rows->updateSnapshot($id, $r['name'], $now);
				}
				match ($r['action']) {
					'reset' => $this->rows->resetMissing($id, $now),
					'reactivate' => $this->reactivate($id),
					'count' => $this->rows->countMissing($id, (int)$r['team']->getMissingCount(), $now),
					'orphan' => $this->orphan($id),
					default => null,
				};
			} catch (\Throwable $e) {
				// ein Team hält die anderen nicht auf
				$this->logger->error('nextnotes: team check failed for team', ['team' => $id, 'exception' => $e]);
			}
		}
		$this->teamState->resetCache();
	}

	// wie das Lösch-Event; Teams ohne Team-Workspace nur verwaisen (Löschung der Zeile nach der Frist)
	private function orphan(string $teamId): void {
		$this->logger->warning('nextnotes: team missing, marked orphaned', ['team' => $teamId]);
		if ($this->lifecycle->hasTeamWorkspaces($teamId)) {
			$this->lifecycle->teamDeleted($teamId, null);
			return;
		}
		$this->teamState->markOrphaned($teamId, null);
	}

	private function reactivate(string $teamId): void {
		if ($this->rows->reactivate($teamId, $this->time->getTime())) {
			$this->logger->warning('nextnotes: orphaned team exists again, reactivated', ['team' => $teamId]);
			$this->dismiss($teamId);
			$this->teamState->resetCache();
			$this->workspaceService->reset();
		}
	}

	// Schritt 2: Hinweis nach 23 Tagen, genau einmal; eingefroren (Teams-App aus) läuft keine Frist
	private function notifySoon(): void {
		if (!$this->teams->isAvailable()) {
			return;
		}
		$now = $this->time->getTime();
		foreach ($this->rows->findOrphanedSince($now - self::NOTICE_DAYS * self::DAY) as $t) {
			if ($t->getDeletionNotifiedAt() !== null) {
				continue;
			}
			try {
				if ($this->rows->markDeletionNotified((string)$t->getTeamId(), $now)) {
					$this->lifecycle->deletionSoon((string)$t->getTeamId());
				}
			} catch (\Throwable $e) {
				$this->logger->error('nextnotes: team_deletion_soon failed', ['team' => $t->getTeamId(), 'exception' => $e]);
			}
		}
	}

	// Schritt 3: fällige Teams unmittelbar vor dem Löschen erneut prüfen; vorhanden → Rückweg, Fehler → überspringen
	private function purgeDue(int $deadline): void {
		$now = $this->time->getTime();
		$progress = false;
		foreach ($this->rows->findOrphanedSince($now - self::ORPHAN_DAYS * self::DAY) as $t) {
			$id = (string)$t->getTeamId();
			if ($t->getDeletionNotifiedAt() === null || self::deletionDate((int)$t->getOrphanedAt(), $t->getDeletionNotifiedAt()) > $now) {
				continue;
			}
			if ($progress && $this->time->getTime() >= $deadline) {
				return;
			}
			if (!$this->teams->isAvailable()) {
				return;
			}
			try {
				if ($this->teams->exists($id) !== null) {
					$this->reactivate($id);
					continue;
				}
			} catch (TeamUnavailableException $e) {
				$this->logger->warning('nextnotes: team purge skipped, lookup failed', ['team' => $id, 'exception' => $e]);
				continue;
			}
			try {
				$this->purge($id, $deadline);
			} catch (\Throwable $e) {
				$this->logger->error('nextnotes: team purge failed', ['team' => $id, 'exception' => $e]);
			}
			$progress = true;
		}
	}

	/**
	 * Team-Workspaces einzeln (je eine Transaktion), Team-Zeile erst ohne Workspace; setzt im nächsten Lauf fort
	 * @return bool Team vollständig gelöscht
	 */
	public function purge(string $teamId, int $deadline): bool {
		$this->beforePurge($teamId);
		$done = 0;
		foreach ($this->workspaces->findByOwner('team', $teamId) as $ws) {
			if ($done > 0 && $this->time->getTime() >= $deadline) {
				return false;
			}
			$id = (int)$ws->getId();
			$this->tx->run(function () use ($id, $teamId): void {
				// gleichzeitiges Umwandeln/Rückweg: Team-Zeile, dann Workspace-Zeile sperren (Reihenfolge wie makePrivate), danach neu lesen
				$this->rows->lockRow($teamId);
				if ($this->rows->findByTeamId($teamId)?->getState() !== TeamStateService::ORPHANED || $this->workspaces->lockRows([$id]) < 1) {
					return;
				}
				$row = $this->workspaces->find($id);
				if ($row->getOwnerType() !== 'team' || $row->getOwnerId() !== $teamId) {
					return;
				}
				$this->deletion->purge($id);
			});
			$done++;
			$this->logger->info('nextnotes: orphaned team workspace deleted', ['team' => $teamId, 'workspace' => $id]);
		}
		if ($this->workspaces->findByOwner('team', $teamId) !== [] || !$this->rows->deleteOrphaned($teamId)) {
			return false;
		}
		$this->dismiss($teamId);
		$this->teamState->resetCache();
		$this->workspaceService->reset();
		$this->logger->info('nextnotes: orphaned team deleted', ['team' => $teamId]);
		return true;
	}

	// M6: Aufgaben, Vorlagen, Abgaben und Schüler-Workspaces des Teams vor den Team-Workspaces
	private function beforePurge(string $teamId): void {
	}

	// Team-Benachrichtigungen ausblenden (Team gelöscht oder doch vorhanden)
	private function dismiss(string $teamId): void {
		$n = $this->notifications->createNotification();
		$n->setApp(Application::APP_ID)->setObject('team', $teamId);
		$this->notifications->markProcessed($n);
	}

	/**
	 * Fehler je Team über Läufe zählen (nur Job); ab ERROR_RUNS einmal Admins informieren, Erfolg oder Verschwinden setzt zurück
	 * @param list<array{team: Team, result: string, name: ?string}> $results
	 */
	private function trackErrors(array $results): void {
		if ($results === []) {
			return;
		}
		$counts = json_decode($this->config->getValueString(Application::APP_ID, self::ERRORS, '{}', true), true);
		$counts = is_array($counts) ? $counts : [];
		$known = array_flip(array_map(static fn (Team $t) => (string)$t->getTeamId(), $this->rows->findAllOrdered()));
		foreach ($results as $r) {
			$id = (string)$r['team']->getTeamId();
			if ($r['result'] !== self::ERROR) {
				if (isset($counts[$id])) {
					unset($counts[$id]);
					$this->dismissError($id);
				}
				continue;
			}
			$counts[$id] = (int)($counts[$id] ?? 0) + 1;
			if ($counts[$id] === self::ERROR_RUNS) {
				$this->logger->error('nextnotes: team lookup keeps failing', ['team' => $id, 'runs' => $counts[$id]]);
				$this->notifyAdminsTeamError($id, (string)($r['team']->getTeamNameSnapshot() ?? ''));
			}
		}
		foreach (array_keys($counts) as $id) {
			if (!isset($known[(string)$id])) {
				unset($counts[$id]);
				$this->dismissError((string)$id);
			}
		}
		$this->config->setValueString(Application::APP_ID, self::ERRORS, json_encode((object)$counts), true);
	}

	private function notifyAdminsTeamError(string $teamId, string $name): void {
		try {
			foreach ($this->groups->get('admin')?->getUsers() ?? [] as $admin) {
				$n = $this->notifications->createNotification();
				$n->setApp(Application::APP_ID)
					->setUser($admin->getUID())
					->setDateTime((new \DateTime())->setTimestamp($this->time->getTime()))
					->setObject('cleanup', 'team:' . $teamId)
					->setSubject('cleanup_team_error', ['team' => $teamId, 'name' => $name, 'runs' => self::ERROR_RUNS]);
				$this->notifications->notify($n);
			}
		} catch (\Throwable $e) {
			$this->logger->error('nextnotes: cleanup_team_error notification failed', ['exception' => $e]);
		}
	}

	private function dismissError(string $teamId): void {
		$n = $this->notifications->createNotification();
		$n->setApp(Application::APP_ID)->setObject('cleanup', 'team:' . $teamId);
		$this->notifications->markProcessed($n);
	}

	// Massenschutz: Admins einmal je Lauf informieren, alte Meldung ersetzen
	private function notifyAdmins(int $checked, int $missing): void {
		try {
			$old = $this->notifications->createNotification();
			$old->setApp(Application::APP_ID)->setObject('cleanup', 'teams');
			$this->notifications->markProcessed($old);
			foreach ($this->groups->get('admin')?->getUsers() ?? [] as $admin) {
				$n = $this->notifications->createNotification();
				$n->setApp(Application::APP_ID)
					->setUser($admin->getUID())
					->setDateTime((new \DateTime())->setTimestamp($this->time->getTime()))
					->setObject('cleanup', 'teams')
					->setSubject('cleanup_suspicious', ['checked' => $checked, 'missing' => $missing]);
				$this->notifications->notify($n);
			}
		} catch (\Throwable $e) {
			$this->logger->error('nextnotes: cleanup_suspicious notification failed', ['exception' => $e]);
		}
	}
}
