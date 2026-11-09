<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * Idempotenz-Einträge (Plan M9 §3); Schreiben folgt in M9b
 * @extends QBMapper<OneNoteItem>
 */
class OneNoteItemMapper extends QBMapper {
	public const TABLE = 'nextnotes_onenote_items';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE, OneNoteItem::class);
	}

	public function deleteByUid(string $uid): int {
		$qb = $this->db->getQueryBuilder();
		return $qb->delete(self::TABLE)->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))->executeStatement();
	}
}
