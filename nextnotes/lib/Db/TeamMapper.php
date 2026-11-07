<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

// Team-Zustand (ADR 0003); Tabelle ohne id-Spalte, daher eigene Schreibwege statt insert()/update()
/** @extends QBMapper<Team> */
class TeamMapper extends QBMapper {
	public const TABLE = 'nextnotes_teams';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE, Team::class);
	}

	public function findByTeamId(string $teamId): ?Team {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)));
		return $this->findEntities($qb)[0] ?? null;
	}

	// Idempotent dank Primärschlüssel team_id
	public function insertIgnore(string $teamId, ?string $name, int $now): void {
		$this->db->insertIgnoreConflict(self::TABLE, [
			'team_id' => $teamId,
			'state' => 'active',
			'missing_count' => 0,
			'team_name_snapshot' => $name === null ? null : mb_substr($name, 0, 255),
			'created_at' => $now,
			'updated_at' => $now,
		]);
	}

	public function updateSnapshot(string $teamId, string $name, int $now): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('team_name_snapshot', $qb->createNamedParameter(mb_substr($name, 0, 255)))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('team_name_snapshot'),
				$qb->expr()->neq('team_name_snapshot', $qb->createNamedParameter(mb_substr($name, 0, 255))),
			))
			->executeStatement();
	}
}
