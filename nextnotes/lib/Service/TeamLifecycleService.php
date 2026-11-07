<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service;

use OCA\NextNotes\AppInfo\Application;
use OCA\NextNotes\Db\LockMapper;
use OCA\NextNotes\Db\MentionHandledMapper;
use OCA\NextNotes\Db\NoteMapper;
use OCA\NextNotes\Db\ReminderMapper;
use OCA\NextNotes\Db\TeamMapper;
use OCA\NextNotes\Db\Workspace;
use OCA\NextNotes\Db\WorkspaceMapper;
use OCA\NextNotes\Db\WsShareMapper;
use OCA\NextNotes\Service\Exception\BadRequestException;
use OCA\NextNotes\Service\Exception\ForbiddenException;
use OCA\NextNotes\Service\Exception\NotFoundException;
use OCA\NextNotes\Service\Exception\TeamUnavailableException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

// Team-Lebenszyklus (SPEC §8, Plan M5 §2.4): Löschung → verwaist, Austritt räumt auf, Umwandeln in privat
class TeamLifecycleService {
	public function __construct(
		private TeamService $teams,
		private TeamStateService $teamState,
		private TeamMapper $teamRows,
		private WorkspaceMapper $workspaces,
		private NoteMapper $notes,
		private ReminderMapper $reminders,
		private MentionHandledMapper $mentions,
		private LockMapper $locks,
		private WsShareMapper $shares,
		private BackgroundFiles $backgrounds,
		private WorkspaceService $workspaceService,
		private Transaction $tx,
		private INotificationManager $notifications,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	// Team gelöscht (Event): nur Teams mit Team-Workspaces; Benachrichtigung nur beim ersten Übergang active → orphaned
	public function teamDeleted(string $teamId, ?string $name): void {
		$wsList = $this->workspaces->findForTeam($teamId);
		if ($wsList === []) {
			return;
		}
		if (!$this->teamState->markOrphaned($teamId, $name)) {
			return;
		}
		$this->workspaceService->reset();
		$this->logger->info('nextnotes: team deleted, team workspaces orphaned', ['team' => $teamId]);
		$this->notifyCreators($teamId, $wsList, 'team_orphaned', $name);
	}

	// Hinweis vor der Löschung (CleanupJob Schritt 2) an created_by der Team-Workspaces
	public function deletionSoon(string $teamId): void {
		$this->notifyCreators($teamId, $this->workspaces->findForTeam($teamId), 'team_deletion_soon', null);
	}

	/** @param Workspace[] $wsList */
	private function notifyCreators(string $teamId, array $wsList, string $subject, ?string $name): void {
		$label = $name ?? $this->teamState->snapshotName($teamId) ?? '';
		$recipients = array_unique(array_filter(array_map(static fn (Workspace $w) => $w->getCreatedBy(), $wsList), static fn ($u) => $u !== null && $u !== ''));
		foreach ($recipients as $uid) {
			// ein fehlerhafter Empfänger hält die anderen nicht auf
			try {
				$n = $this->notifications->createNotification();
				$n->setApp(Application::APP_ID)
					->setUser((string)$uid)
					->setDateTime((new \DateTime())->setTimestamp($this->time->getTime()))
					->setObject('team', $teamId)
					->setSubject($subject, ['team' => $label, 'assignments' => 0]);
				$this->notifications->notify($n);
			} catch (\Throwable $e) {
				$this->logger->error('nextnotes: ' . $subject . ' notification failed', ['team' => $teamId, 'exception' => $e]);
			}
		}
	}

	public function hasTeamWorkspaces(string $teamId): bool {
		return $this->workspaces->findForTeam($teamId) !== [];
	}

	// Austritt (direkt oder geerbt): nur bei bestehendem Team und sicher Level 0; Rechte hängen nicht daran (Live-Abfrage)
	public function memberLeft(string $teamId, string $uid): void {
		$wsList = $this->workspaces->findForTeam($teamId);
		if ($wsList === []) {
			return;
		}
		$this->workspaceService->reset();
		try {
			// gelöschtes Team: kein Austritt (Lösch-Pfad, Umwandeln bzw. Purge räumen auf)
			if ($this->teams->exists($teamId) === null || $this->teams->currentLevel($teamId, $uid) > 0) {
				return;
			}
		} catch (TeamUnavailableException $e) {
			$this->logger->warning('nextnotes: team leave not processed', ['team' => $teamId, 'exception' => $e]);
			return;
		}
		$wsIds = array_map(static fn (Workspace $w) => (int)$w->getId(), $wsList);
		$this->tx->run(function () use ($wsIds, $uid): void {
			$noteIds = $this->noteIds($wsIds);
			$this->reminders->deleteForNotes($noteIds, $uid);
			$this->mentions->deleteForNotes($noteIds, $uid);
			$this->backgrounds->queueUser($wsIds, $uid);
		});
		$this->workspaceService->reset();
	}

	/**
	 * Verwaisten Team-Workspace an created_by übergeben – mit allen Notizen aller früheren Mitglieder (ADR 0003, Thomas 2026-11-07); keine Quotenprüfung (sonst Datenverlust nach 30 Tagen)
	 * @return string neuer Workspace-Schlüssel
	 */
	public function makePrivate(string $uid, string $key): string {
		$ws = $this->workspaceService->requireByKey($uid, $key, 'read');
		if ($ws->getOwnerType() !== 'team' || $ws->getKind() !== 'normal') {
			throw new BadRequestException('Only team workspaces can be made private');
		}
		$teamId = (string)$ws->getOwnerId();
		if ($this->teamState->stateOf($teamId) !== TeamStateService::ORPHANED || $ws->getCreatedBy() !== $uid) {
			throw new ForbiddenException('Only workspaces of a deleted team can be made private');
		}
		// Zustand allein genügt nicht (DestroyingCircleEvent kommt vor dem Löschen): nur bei eindeutig fehlendem Team, Fehler → 403
		try {
			$gone = $this->teams->exists($teamId) === null;
		} catch (TeamUnavailableException) {
			$gone = false;
		}
		if (!$gone) {
			throw new ForbiddenException('The team still exists');
		}
		$id = (int)$ws->getId();
		$name = $this->tx->run(function () use ($uid, $id, $teamId): string {
			// Sperrreihenfolge: Team-Zeile, Workspace-Zeilen (wie Purge), dann Benutzer (wie QuotaService::enforce); erste Anweisung schreibt (SQLite)
			$this->teamRows->lockRow($teamId);
			if ($this->workspaces->lockRows([$id]) < 1) {
				throw new NotFoundException('Workspace not found');
			}
			$this->workspaces->lockOwner('user', $uid);
			$ws = $this->workspaces->find($id);
			if ($ws->getOwnerType() !== 'team' || $ws->getOwnerId() !== $teamId || $ws->getCreatedBy() !== $uid
				|| $this->teamRows->findByTeamId($teamId)?->getState() !== TeamStateService::ORPHANED) {
				throw new ForbiddenException('Only workspaces of a deleted team can be made private');
			}
			$own = $this->workspaces->findOwned($uid);
			$name = $this->freeName($uid, (string)$ws->getName());
			$ws->setOwnerType('user');
			$ws->setOwnerId($uid);
			$ws->setName($name);
			$ws->setDisplayOrder(max(array_map(static fn (Workspace $w) => (int)$w->getDisplayOrder(), $own) ?: [0]) + 1);
			$ws->setUpdatedAt($this->time->getTime());
			$this->workspaces->update($ws);
			// Altbestand-Freigaben würden jetzt wirken; Spuren anderer Konten gehören nicht in einen privaten Workspace
			$this->shares->deleteForWorkspace($id);
			$noteIds = $this->notes->idsInWorkspace($id);
			$this->reminders->deleteForNotesExcept($noteIds, $uid);
			$this->mentions->deleteForNotesExcept($noteIds, $uid);
			$this->locks->deleteForNotesExcept($noteIds, $uid);
			// Hintergründe aller Konten (Ordner einzelner Konten sind nicht auflistbar); eigener Hintergrund wird zurückgesetzt
			$this->backgrounds->queueWorkspace($id);
			return $name;
		});
		$this->workspaceService->reset();
		$this->logger->info('nextnotes: orphaned team workspace made private', ['team' => $teamId, 'workspace' => $id]);
		return $this->workspaceService->keyFor($uid, $id) ?? $name;
	}

	// Namensprüfung über die DB wie beim Anlegen (Kollation entscheidet über Gleichheit)
	private function freeName(string $uid, string $name): string {
		for ($i = 0; ; $i++) {
			$suffix = match ($i) { 0 => '', 1 => ' Team', default => ' Team ' . $i };
			$candidate = mb_substr($name, 0, 255 - mb_strlen($suffix)) . $suffix;
			if ($this->workspaces->findOwned($uid, null, $candidate) === []) {
				return $candidate;
			}
		}
	}

	/**
	 * @param int[] $wsIds
	 * @return int[]
	 */
	private function noteIds(array $wsIds): array {
		$ids = [];
		foreach ($wsIds as $wsId) {
			array_push($ids, ...$this->notes->idsInWorkspace($wsId));
		}
		return $ids;
	}
}
