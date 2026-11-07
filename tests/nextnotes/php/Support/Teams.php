<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Support;

use OCA\Circles\CirclesManager;
use OCA\Circles\Model\Circle;
use OCA\Circles\Model\Member;
use OCA\NextNotes\Service\AccessService;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Server;

// Test-Helfer M5: Teams über CirclesManager im Testprozess (DB geteilt mit dem Webserver); Caches nach jeder Änderung zurücksetzen
final class Teams {
	/** @var array<string, string> angelegte Teams → Owner (Aufräumen) */
	private array $teams = [];
	/** @var string[] angelegte Gruppen */
	private array $groups = [];

	public static function cm(): CirclesManager {
		return Server::get(CirclesManager::class);
	}

	// Team mit $ownerUid als Owner; optional Konfiguration (z. B. Circle::CFG_INVITE)
	public function create(string $ownerUid, string $name, int $config = 0): string {
		$cm = self::cm();
		try {
			$owner = $cm->getLocalFederatedUser($ownerUid);
			$cm->startSession($owner);
			$circle = $cm->createCircle($name, $owner, false, false, false);
			$id = $circle->getSingleId();
		} finally {
			$cm->stopSession();
		}
		$this->teams[$id] = $ownerUid;
		if ($config !== 0) {
			$this->setConfig($id, $config);
		}
		self::reset();
		return $id;
	}

	// Konfiguration direkt in der Circles-Tabelle (versteckt/Backend sind über die API nicht setzbar)
	public function setConfig(string $teamId, int $add): void {
		$db = Server::get(IDBConnection::class);
		$qb = $db->getQueryBuilder();
		$qb->select('config')->from('circles_circle')->where($qb->expr()->eq('unique_id', $qb->createNamedParameter($teamId)));
		$config = (int)$qb->executeQuery()->fetchOne();
		$qb = $db->getQueryBuilder();
		$qb->update('circles_circle')->set('config', $qb->createNamedParameter($config | $add))
			->where($qb->expr()->eq('unique_id', $qb->createNamedParameter($teamId)))->executeStatement();
		self::reset();
	}

	// Mitglied (Benutzer) mit Level; liefert Member-ID; bei CFG_INVITE bleibt Status Invited
	public function addUser(string $teamId, string $ownerUid, string $uid, int $level = Member::LEVEL_MEMBER): string {
		return $this->add($teamId, $ownerUid, self::cm()->getLocalFederatedUser($uid), $level);
	}

	// Gruppe als Mitglied (Mitglieder erben Level 1); legt die Gruppe an und fügt $uids hinzu
	public function addGroup(string $teamId, string $ownerUid, array $uids): string {
		$gid = 'nn-grp-' . bin2hex(random_bytes(4));
		$group = Server::get(IGroupManager::class)->createGroup($gid);
		foreach ($uids as $uid) {
			$group->addUser(Server::get(IUserManager::class)->get($uid));
		}
		$this->groups[] = $gid;
		return $this->add($teamId, $ownerUid, self::cm()->getFederatedUser($gid, Member::TYPE_GROUP), Member::LEVEL_MEMBER);
	}

	private function add(string $teamId, string $ownerUid, $federated, int $level): string {
		$cm = self::cm();
		try {
			$cm->startSession($cm->getLocalFederatedUser($ownerUid));
			$member = $cm->addMember($teamId, $federated);
			if ($level !== Member::LEVEL_MEMBER && $member->getStatus() === Member::STATUS_MEMBER) {
				$cm->levelMember($member->getId(), $level);
			}
			$id = $member->getId();
		} finally {
			$cm->stopSession();
		}
		self::reset();
		return $id;
	}

	public function remove(string $ownerUid, string $memberId): void {
		$cm = self::cm();
		try {
			$cm->startSession($cm->getLocalFederatedUser($ownerUid));
			$cm->removeMember($memberId);
		} finally {
			$cm->stopSession();
		}
		self::reset();
	}

	public function destroy(string $ownerUid, string $teamId): void {
		$cm = self::cm();
		try {
			$cm->startSession($cm->getLocalFederatedUser($ownerUid));
			$cm->destroyCircle($teamId);
		} finally {
			$cm->stopSession();
		}
		unset($this->teams[$teamId]);
		self::purgeTeamData($teamId);
		self::reset();
	}

	public static function reset(): void {
		Server::get(AccessService::class)->resetCache();
	}

	// Teams als Owner zerstören (vor dem Löschen der Benutzer), Gruppen löschen, App-Daten der Teams entfernen
	public function cleanup(): void {
		$cm = self::cm();
		foreach ($this->teams as $id => $owner) {
			try {
				$cm->startSession($cm->getLocalFederatedUser($owner));
				$cm->destroyCircle($id);
			} catch (\Throwable) {
			} finally {
				$cm->stopSession();
			}
			self::purgeTeamData($id);
		}
		foreach ($this->groups as $gid) {
			Server::get(IGroupManager::class)->get($gid)?->delete();
		}
		$this->teams = [];
		$this->groups = [];
		self::reset();
	}

	public static function purgeTeamData(string $teamId): void {
		$db = Server::get(IDBConnection::class);
		$qb = $db->getQueryBuilder();
		$qb->select('id')->from('nextnotes_workspaces')
			->where($qb->expr()->eq('owner_type', $qb->createNamedParameter('team')))
			->andWhere($qb->expr()->eq('owner_id', $qb->createNamedParameter($teamId)));
		$ws = array_map('intval', $qb->executeQuery()->fetchFirstColumn());
		if ($ws !== []) {
			$qb = $db->getQueryBuilder();
			$qb->select('id')->from('nextnotes_notes')
				->where($qb->expr()->in('workspace_id', $qb->createNamedParameter($ws, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
			$noteIds = array_map('intval', $qb->executeQuery()->fetchFirstColumn());
			foreach ($noteIds === [] ? [] : ['nextnotes_note_tags', 'nextnotes_attachments', 'nextnotes_snapshots', 'nextnotes_locks', 'nextnotes_reminders', 'nextnotes_mentions_handled'] as $t) {
				$qb = $db->getQueryBuilder();
				$qb->delete($t)->where($qb->expr()->in('note_id', $qb->createNamedParameter($noteIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))->executeStatement();
			}
			foreach (['nextnotes_notes', 'nextnotes_folders', 'nextnotes_ws_shares'] as $t) {
				$qb = $db->getQueryBuilder();
				$qb->delete($t)->where($qb->expr()->in('workspace_id', $qb->createNamedParameter($ws, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))->executeStatement();
			}
			$qb = $db->getQueryBuilder();
			$qb->delete('nextnotes_workspaces')->where($qb->expr()->in('id', $qb->createNamedParameter($ws, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))->executeStatement();
		}
		$qb = $db->getQueryBuilder();
		$qb->delete('nextnotes_teams')->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))->executeStatement();
	}

	// Konstanten für Tests ohne Circles-Import
	public const HIDDEN = Circle::CFG_HIDDEN;
	public const BACKEND = Circle::CFG_BACKEND;
	public const INVITE = Circle::CFG_INVITE;
	public const MODERATOR = Member::LEVEL_MODERATOR;
	public const ADMIN = Member::LEVEL_ADMIN;
}
