<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Support;

use OCP\DB\QueryBuilder\IQueryBuilder;

// Basis M5: Teams-Helfer, Team-Workspaces und Rohdaten (per DB) mit Aufräumen
abstract class TeamTestCase extends ApiTestCase {
	protected Teams $teams;
	/** @var int[] per DB angelegte Workspaces */
	private array $rawWs = [];

	protected function setUp(): void {
		parent::setUp();
		$this->teams = new Teams();
	}

	protected function tearDown(): void {
		$this->teams->cleanup();
		$db = self::db();
		foreach ($this->rawWs as $id) {
			foreach (['nextnotes_notes' => 'workspace_id', 'nextnotes_ws_shares' => 'workspace_id', 'nextnotes_workspaces' => 'id'] as $t => $col) {
				$qb = $db->getQueryBuilder();
				$qb->delete($t)->where($qb->expr()->eq($col, $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeStatement();
			}
		}
		$this->rawWs = [];
		parent::tearDown();
	}

	/** Team-Workspace per HTTP; liefert [Schlüssel, ID] */
	protected static function teamWs(ApiClient $c, string $teamId, string $prefix = 'TW'): array {
		$name = $prefix . ' ' . bin2hex(random_bytes(3));
		$key = (string)self::ok($c->call('POST', '/workspaces', ['name' => $name, 'team' => $teamId]), "Team-Workspace $name")['name'];
		$qb = self::db()->getQueryBuilder();
		$qb->select('id')->from('nextnotes_workspaces')
			->where($qb->expr()->eq('owner_type', $qb->createNamedParameter('team')))
			->andWhere($qb->expr()->eq('owner_id', $qb->createNamedParameter($teamId)))
			->andWhere($qb->expr()->eq('name', $qb->createNamedParameter($name)));
		$id = (int)$qb->executeQuery()->fetchOne();
		self::assertGreaterThan(0, $id, "Team-Workspace $name fehlt in der DB");
		return [$key, $id];
	}

	protected function rawWorkspace(string $ownerType, string $ownerId, ?string $createdBy = null, string $kind = 'normal'): int {
		$qb = self::db()->getQueryBuilder();
		$qb->insert('nextnotes_workspaces')->values([
			'owner_type' => $qb->createNamedParameter($ownerType),
			'owner_id' => $qb->createNamedParameter($ownerId),
			'kind' => $qb->createNamedParameter($kind),
			'name' => $qb->createNamedParameter('Raw ' . bin2hex(random_bytes(3))),
			'display_order' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
			'created_by' => $qb->createNamedParameter($createdBy),
			'created_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
			'updated_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
		])->executeStatement();
		$id = $qb->getLastInsertId();
		$this->rawWs[] = $id;
		return $id;
	}

	protected static function rawNote(int $wsId, string $heading, ?string $createdBy, string $role = 'note', ?int $folderId = null): int {
		$qb = self::db()->getQueryBuilder();
		$qb->insert('nextnotes_notes')->values([
			'workspace_id' => $qb->createNamedParameter($wsId, IQueryBuilder::PARAM_INT),
			'folder_id' => $qb->createNamedParameter($folderId, $folderId === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT),
			'heading' => $qb->createNamedParameter($heading),
			'content' => $qb->createNamedParameter('<p>' . $heading . '</p>'),
			'search_text' => $qb->createNamedParameter(mb_strtolower($heading)),
			'type' => $qb->createNamedParameter('note'),
			'role' => $qb->createNamedParameter($role),
			'created_by' => $qb->createNamedParameter($createdBy),
			'updated_by' => $qb->createNamedParameter($createdBy),
			'created_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
			'updated_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
		])->executeStatement();
		return $qb->getLastInsertId();
	}

	protected static function rawShare(int $wsId, string $uid, string $perm = 'write'): void {
		$qb = self::db()->getQueryBuilder();
		$qb->insert('nextnotes_ws_shares')->values([
			'workspace_id' => $qb->createNamedParameter($wsId, IQueryBuilder::PARAM_INT),
			'share_type' => $qb->createNamedParameter('user'),
			'share_with' => $qb->createNamedParameter($uid),
			'permission' => $qb->createNamedParameter($perm),
			'created_by' => $qb->createNamedParameter('test'),
			'created_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
		])->executeStatement();
	}

	protected static function setTeamState(string $teamId, string $state): void {
		$qb = self::db()->getQueryBuilder();
		$qb->update('nextnotes_teams')->set('state', $qb->createNamedParameter($state))
			->set('orphaned_at', $qb->createNamedParameter($state === 'orphaned' ? time() : null, $state === 'orphaned' ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_NULL))
			->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))->executeStatement();
		Teams::reset();
	}

	protected static function teamState(string $teamId): ?string {
		$qb = self::db()->getQueryBuilder();
		$qb->select('state')->from('nextnotes_teams')->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)));
		$v = $qb->executeQuery()->fetchOne();
		return $v === false ? null : (string)$v;
	}

	/** @return array<string, mixed> Notizzeile (Workspace, Ordner, Autor) */
	protected static function noteRow(int $id): array {
		$qb = self::db()->getQueryBuilder();
		$qb->select('workspace_id', 'folder_id', 'created_by', 'heading')->from('nextnotes_notes')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetchAssociative();
		self::assertIsArray($row, "Notiz $id fehlt");
		return array_map(static fn ($v) => $v === null ? null : (string)$v, $row);
	}
}
