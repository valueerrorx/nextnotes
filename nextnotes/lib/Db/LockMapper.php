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

// Tabelle ohne id-Spalte: nur eigene Abfragen, kein QBMapper::insert/update
/** @extends QBMapper<Lock> */
class LockMapper extends QBMapper {
	public const TABLE = 'nextnotes_locks';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE, Lock::class);
	}

	// Gültige Sperre (expires_at > now) oder null
	public function findValid(int $noteId, int $now): ?Lock {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('note_id', $qb->createNamedParameter($noteId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gt('expires_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)));
		$list = $this->findEntities($qb);
		return $list[0] ?? null;
	}

	// Bedingte Übernahme: abgelaufen oder dieselbe Sitzung desselben Kontos
	public function takeOver(int $noteId, string $uid, string $session, int $now, int $expires): int {
		$qb = $this->db->getQueryBuilder();
		$expr = $qb->expr();
		return $qb->update(self::TABLE)
			->set('holder_uid', $qb->createNamedParameter($uid))
			->set('holder_session', $qb->createNamedParameter($session))
			->set('holder_kind', $qb->createNamedParameter('user'))
			->set('last_seen_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->set('expires_at', $qb->createNamedParameter($expires, IQueryBuilder::PARAM_INT))
			->where($expr->eq('note_id', $qb->createNamedParameter($noteId, IQueryBuilder::PARAM_INT)))
			->andWhere($expr->orX(
				$expr->lte('expires_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)),
				$expr->andX(
					$expr->eq('holder_session', $qb->createNamedParameter($session)),
					$expr->eq('holder_uid', $qb->createNamedParameter($uid)),
				),
			))
			->executeStatement();
	}

	/** @throws \OCP\DB\Exception bei Unique-Verletzung (Sperre existiert) */
	public function create(int $noteId, string $uid, string $session, int $now, int $expires): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABLE)->values([
			'note_id' => $qb->createNamedParameter($noteId, IQueryBuilder::PARAM_INT),
			'holder_uid' => $qb->createNamedParameter($uid),
			'holder_session' => $qb->createNamedParameter($session),
			'holder_kind' => $qb->createNamedParameter('user'),
			'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
			'last_seen_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
			'expires_at' => $qb->createNamedParameter($expires, IQueryBuilder::PARAM_INT),
		])->executeStatement();
	}

	// Freigabe nur durch die haltende Sitzung desselben Kontos
	public function release(int $noteId, string $uid, string $session): int {
		$qb = $this->db->getQueryBuilder();
		return $qb->delete(self::TABLE)
			->where($qb->expr()->eq('note_id', $qb->createNamedParameter($noteId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('holder_session', $qb->createNamedParameter($session)))
			->andWhere($qb->expr()->eq('holder_uid', $qb->createNamedParameter($uid)))
			->executeStatement();
	}

	/** @param int[] $noteIds */
	public function deleteForNotes(array $noteIds): void {
		foreach (array_chunk(array_values(array_unique($noteIds)), 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete(self::TABLE)
				->where($qb->expr()->in('note_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->executeStatement();
		}
	}

	public function deleteExpired(int $now): int {
		$qb = $this->db->getQueryBuilder();
		return $qb->delete(self::TABLE)
			->where($qb->expr()->lte('expires_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}
}
