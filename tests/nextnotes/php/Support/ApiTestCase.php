<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Support;

use OC\Authentication\Token\IProvider;
use OCP\Authentication\Token\IToken;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use OCP\Server;
use PHPUnit\Framework\TestCase;

// Basis: eigene Benutzer je Test (Plan §3.2), Aufräumen der App-Daten im tearDown
abstract class ApiTestCase extends TestCase {
	public const PASSWORD = 'NN-test-Pass-2026!x';

	/** @var string[] */
	private array $createdUids = [];

	protected function createUser(string $role, ?string $displayName = null): ApiClient {
		$uid = 'nn-' . $role . '-' . bin2hex(random_bytes(4));
		$um = Server::get(IUserManager::class);
		$user = $um->createUser($uid, self::PASSWORD);
		self::assertNotFalse($user, "Benutzer $uid nicht angelegt");
		if ($displayName !== null) {
			$user->setDisplayName($displayName);
		}
		$this->createdUids[] = $uid;
		$token = Server::get(ISecureRandom::class)->generate(72, ISecureRandom::CHAR_ALPHANUMERIC);
		Server::get(IProvider::class)->generateToken($token, $uid, $uid, null, 'nn-test', IToken::PERMANENT_TOKEN);
		return new ApiClient($uid, $token);
	}

	protected function tearDown(): void {
		foreach ($this->createdUids as $uid) {
			self::purgeAppData($uid);
			Server::get(IUserManager::class)->get($uid)?->delete();
		}
		$this->createdUids = [];
		parent::tearDown();
	}

	public static function db(): IDBConnection {
		return Server::get(IDBConnection::class);
	}

	/** @return int[] */
	public static function ownedWorkspaceIds(string $uid): array {
		$qb = self::db()->getQueryBuilder();
		$qb->select('id')->from('nextnotes_workspaces')
			->where($qb->expr()->eq('owner_type', $qb->createNamedParameter('user')))
			->andWhere($qb->expr()->eq('owner_id', $qb->createNamedParameter($uid)))
			->orderBy('id');
		return array_map('intval', $qb->executeQuery()->fetchFirstColumn());
	}

	/** @return list<array<string, mixed>> */
	private static function rows(string $table, string $col, array $ids): array {
		if ($ids === []) {
			return [];
		}
		$qb = self::db()->getQueryBuilder();
		$qb->select('*')->from($table)
			->where($qb->expr()->in($col, $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
			->orderBy('id');
		$rows = $qb->executeQuery()->fetchAllAssociative();
		// DB-Treiber liefern Zahlen teils als String → einheitlich
		return array_map(static fn (array $r) => array_map(static fn ($v) => $v === null ? null : (string)$v, $r), $rows);
	}

	/** Vollständiger Zustand eines Benutzers (eigene Workspaces samt Inhalt, Freigaben, App-Einstellungen) */
	public static function dump(string $uid): array {
		$ws = self::ownedWorkspaceIds($uid);
		$wsRows = self::rows('nextnotes_workspaces', 'id', $ws);
		$folders = self::rows('nextnotes_folders', 'workspace_id', $ws);
		$notes = self::rows('nextnotes_notes', 'workspace_id', $ws);
		$noteIds = array_map(static fn ($r) => (int)$r['id'], $notes);
		$qb = self::db()->getQueryBuilder();
		$qb->select('configkey', 'configvalue')->from('preferences')
			->where($qb->expr()->eq('userid', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('appid', $qb->createNamedParameter('nextnotes')))
			->orderBy('configkey');
		return [
			'workspaces' => $wsRows,
			'folders' => $folders,
			'notes' => $notes,
			'tags' => self::rows('nextnotes_note_tags', 'note_id', $noteIds),
			'shares' => self::rows('nextnotes_ws_shares', 'workspace_id', $ws),
			'prefs' => $qb->executeQuery()->fetchAllAssociative(),
		];
	}

	public static function purgeAppData(string $uid): void {
		$db = self::db();
		$ws = self::ownedWorkspaceIds($uid);
		if ($ws !== []) {
			$qb = $db->getQueryBuilder();
			$qb->select('id')->from('nextnotes_notes')
				->where($qb->expr()->in('workspace_id', $qb->createNamedParameter($ws, IQueryBuilder::PARAM_INT_ARRAY)));
			$noteIds = array_map('intval', $qb->executeQuery()->fetchFirstColumn());
			if ($noteIds !== []) {
				$qb = $db->getQueryBuilder();
				$qb->delete('nextnotes_note_tags')->where($qb->expr()->in('note_id', $qb->createNamedParameter($noteIds, IQueryBuilder::PARAM_INT_ARRAY)))->executeStatement();
			}
			foreach (['nextnotes_notes', 'nextnotes_folders', 'nextnotes_ws_shares'] as $t) {
				$qb = $db->getQueryBuilder();
				$qb->delete($t)->where($qb->expr()->in('workspace_id', $qb->createNamedParameter($ws, IQueryBuilder::PARAM_INT_ARRAY)))->executeStatement();
			}
			$qb = $db->getQueryBuilder();
			$qb->delete('nextnotes_workspaces')->where($qb->expr()->in('id', $qb->createNamedParameter($ws, IQueryBuilder::PARAM_INT_ARRAY)))->executeStatement();
		}
		$qb = $db->getQueryBuilder();
		$qb->delete('nextnotes_ws_shares')->where($qb->expr()->eq('share_with', $qb->createNamedParameter($uid)))->executeStatement();
	}

	protected static function q(array $params): string {
		return $params === [] ? '' : '?' . http_build_query($params);
	}

	protected static function enc(string $s): string {
		return rawurlencode($s);
	}

	/** Antwort muss 2xx sein, sonst Testabbruch mit Antworttext */
	protected static function ok(Response $r, string $what): array {
		self::assertTrue($r->ok(), "$what: erwartet 2xx, erhalten " . $r->describe());
		$j = $r->json();
		return is_array($j) ? $j : [];
	}

	/** Workspace-Namen, die der Benutzer per GET /workspaces sieht */
	protected static function workspaceNames(ApiClient $c): array {
		$j = self::ok($c->call('GET', '/workspaces'), 'GET /workspaces');
		return array_map(static fn ($w) => $w['name'], $j['workspaces'] ?? []);
	}
}
