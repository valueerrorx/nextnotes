<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Idempotenz-Einträge (Plan M9 §3): uid + Hash der Graph-ID → Ziel
 * @extends QBMapper<OneNoteItem>
 */
class OneNoteItemMapper extends QBMapper {
	public const TABLE = 'nextnotes_onenote_items';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE, OneNoteItem::class);
	}

	// sha256(Typ + ':' + Graph-ID)
	public static function hash(string $kind, string $graphId): string {
		return hash('sha256', $kind . ':' . $graphId);
	}

	public function findBySource(string $uid, string $sourceHash): ?OneNoteItem {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('source_hash', $qb->createNamedParameter($sourceHash)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function add(string $uid, string $sourceHash, string $kind, int $targetId, ?int $modifiedAt, int $importId, int $now): OneNoteItem {
		$e = new OneNoteItem();
		$e->setUid($uid);
		$e->setSourceHash($sourceHash);
		$e->setKind($kind);
		$e->setTargetId($targetId);
		$e->setSourceModifiedAt($modifiedAt);
		$e->setImportId($importId);
		$e->setCreatedAt($now);
		return $this->insert($e);
	}

	// Ziel bedingt ersetzen (Plan M9 §3): Zeilenzahl 0 → Aufrufer rollt zurück
	public function replaceTarget(string $uid, string $sourceHash, int $oldTarget, int $newTarget, int $importId): int {
		$qb = $this->db->getQueryBuilder();
		return $qb->update(self::TABLE)
			->set('target_id', $qb->createNamedParameter($newTarget, IQueryBuilder::PARAM_INT))
			->set('import_id', $qb->createNamedParameter($importId, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('source_hash', $qb->createNamedParameter($sourceHash)))
			->andWhere($qb->expr()->eq('target_id', $qb->createNamedParameter($oldTarget, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	public function deleteByUid(string $uid): int {
		$qb = $this->db->getQueryBuilder();
		return $qb->delete(self::TABLE)->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))->executeStatement();
	}
}
