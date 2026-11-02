<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Schema\ColumnType;

// Zeile aus nextnotes_locks (Primärschlüssel note_id, ADR 0005)
/**
 * @method ?int getNoteId()
 * @method void setNoteId(?int $v)
 * @method ?string getHolderUid()
 * @method void setHolderUid(?string $v)
 * @method ?string getHolderSession()
 * @method void setHolderSession(?string $v)
 * @method ?string getHolderKind()
 * @method void setHolderKind(?string $v)
 * @method ?int getCreatedAt()
 * @method void setCreatedAt(?int $v)
 * @method ?int getLastSeenAt()
 * @method void setLastSeenAt(?int $v)
 * @method ?int getExpiresAt()
 * @method void setExpiresAt(?int $v)
 */
class Lock extends Entity {
	protected $noteId;
	protected $holderUid;
	protected $holderSession;
	protected $holderKind;
	protected $createdAt;
	protected $lastSeenAt;
	protected $expiresAt;

	public function __construct() {
		$this->addType('noteId', ColumnType::Integer);
		$this->addType('holderUid', ColumnType::String);
		$this->addType('holderSession', ColumnType::String);
		$this->addType('holderKind', ColumnType::String);
		$this->addType('createdAt', ColumnType::Integer);
		$this->addType('lastSeenAt', ColumnType::Integer);
		$this->addType('expiresAt', ColumnType::Integer);
	}
}
