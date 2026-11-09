<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Schema\ColumnType;

// Zeile aus nextnotes_onenote_items (Plan M9 §3): Hash der Graph-ID → Ziel, Idempotenz
/**
 * @method ?string getUid()
 * @method void setUid(?string $v)
 * @method ?string getSourceHash()
 * @method void setSourceHash(?string $v)
 * @method ?string getKind()
 * @method void setKind(?string $v)
 * @method ?int getTargetId()
 * @method void setTargetId(?int $v)
 * @method ?int getSourceModifiedAt()
 * @method void setSourceModifiedAt(?int $v)
 * @method ?int getImportId()
 * @method void setImportId(?int $v)
 * @method ?int getCreatedAt()
 * @method void setCreatedAt(?int $v)
 */
class OneNoteItem extends Entity {
	protected $uid;
	protected $sourceHash;
	protected $kind;
	protected $targetId;
	protected $sourceModifiedAt;
	protected $importId;
	protected $createdAt;

	public function __construct() {
		$this->addType('uid', ColumnType::String);
		$this->addType('sourceHash', ColumnType::String);
		$this->addType('kind', ColumnType::String);
		$this->addType('targetId', ColumnType::Integer);
		$this->addType('sourceModifiedAt', ColumnType::Integer);
		$this->addType('importId', ColumnType::Integer);
		$this->addType('createdAt', ColumnType::Integer);
	}
}
