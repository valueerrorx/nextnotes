<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Schema\ColumnType;

// Zeile aus nextnotes_snapshots; kind = auto | manual
/**
 * @method ?int getNoteId()
 * @method void setNoteId(?int $v)
 * @method ?string getHeading()
 * @method void setHeading(?string $v)
 * @method ?string getContent()
 * @method void setContent(?string $v)
 * @method ?string getType()
 * @method void setType(?string $v)
 * @method ?string getKind()
 * @method void setKind(?string $v)
 * @method ?string getCreatedBy()
 * @method void setCreatedBy(?string $v)
 * @method ?int getCreatedAt()
 * @method void setCreatedAt(?int $v)
 */
class Snapshot extends Entity {
	protected $noteId;
	protected $heading;
	protected $content;
	protected $type;
	protected $kind;
	protected $createdBy;
	protected $createdAt;

	public function __construct() {
		$this->addType('noteId', ColumnType::Integer);
		$this->addType('heading', ColumnType::String);
		$this->addType('content', ColumnType::String);
		$this->addType('type', ColumnType::String);
		$this->addType('kind', ColumnType::String);
		$this->addType('createdBy', ColumnType::String);
		$this->addType('createdAt', ColumnType::Integer);
	}
}
