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

	// Zeilensperre bis zum Commit; Reihenfolge überall: Team-Zeile vor Workspace-Zeilen
	public function lockRow(string $teamId): int {
		$qb = $this->db->getQueryBuilder();
		return $qb->update(self::TABLE)->set('team_id', 'team_id')
			->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
			->executeStatement();
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

	// Nur active → orphaned (bedingt, idempotent); Rückgabe: Zeile geändert
	public function markOrphaned(string $teamId, int $now): bool {
		$qb = $this->db->getQueryBuilder();
		return $qb->update(self::TABLE)
			->set('state', $qb->createNamedParameter('orphaned'))
			->set('orphaned_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->set('missing_count', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			->set('deletion_notified_at', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
			->andWhere($qb->expr()->eq('state', $qb->createNamedParameter('active')))
			->executeStatement() > 0;
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

	/** @return Team[] alle Zeilen nach team_id (CleanupJob Schritt 1, orphan-check) */
	public function findAllOrdered(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)->orderBy('team_id', 'ASC');
		return $this->findEntities($qb);
	}

	/** @return Team[] verwaiste Zeilen mit orphaned_at <= $before */
	public function findOrphanedSince(int $before): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('state', $qb->createNamedParameter('orphaned')))
			->andWhere($qb->expr()->lte('orphaned_at', $qb->createNamedParameter($before, IQueryBuilder::PARAM_INT)))
			->orderBy('orphaned_at', 'ASC')->addOrderBy('team_id', 'ASC');
		return $this->findEntities($qb);
	}

	// „Team fehlt“ zählen: nur active und nur vom gelesenen Stand aus (zwei Läufe überschneiden sich nicht doppelt)
	public function countMissing(string $teamId, int $from, int $now): bool {
		$qb = $this->db->getQueryBuilder();
		return $qb->update(self::TABLE)
			->set('missing_count', $qb->createNamedParameter($from + 1, IQueryBuilder::PARAM_INT))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
			->andWhere($qb->expr()->eq('state', $qb->createNamedParameter('active')))
			->andWhere($qb->expr()->eq('missing_count', $qb->createNamedParameter($from, IQueryBuilder::PARAM_INT)))
			->executeStatement() > 0;
	}

	// „Team vorhanden“: Zähler auf 0
	public function resetMissing(string $teamId, int $now): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('missing_count', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
			->andWhere($qb->expr()->neq('missing_count', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	// Rückweg orphaned → active (Team existiert doch); Rückgabe: Zeile geändert
	public function reactivate(string $teamId, int $now): bool {
		$qb = $this->db->getQueryBuilder();
		return $qb->update(self::TABLE)
			->set('state', $qb->createNamedParameter('active'))
			->set('orphaned_at', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('missing_count', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			->set('deletion_notified_at', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
			->andWhere($qb->expr()->eq('state', $qb->createNamedParameter('orphaned')))
			->executeStatement() > 0;
	}

	// Hinweis vor der Löschung genau einmal; Rückgabe: dieser Aufruf hat ihn gesetzt
	public function markDeletionNotified(string $teamId, int $now): bool {
		$qb = $this->db->getQueryBuilder();
		return $qb->update(self::TABLE)
			->set('deletion_notified_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
			->andWhere($qb->expr()->eq('state', $qb->createNamedParameter('orphaned')))
			->andWhere($qb->expr()->isNull('deletion_notified_at'))
			->executeStatement() > 0;
	}

	// Team-Zeile zuletzt löschen, nur solange verwaist
	public function deleteOrphaned(string $teamId): bool {
		$qb = $this->db->getQueryBuilder();
		return $qb->delete(self::TABLE)
			->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)))
			->andWhere($qb->expr()->eq('state', $qb->createNamedParameter('orphaned')))
			->executeStatement() > 0;
	}
}
