<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Schema\ColumnType;

// Zeile aus nextnotes_workspaces (SPEC §2.1)
/**
 * @method ?string getOwnerType()
 * @method void setOwnerType(?string $v)
 * @method ?string getOwnerId()
 * @method void setOwnerId(?string $v)
 * @method ?string getKind()
 * @method void setKind(?string $v)
 * @method ?string getLinkedTeamId()
 * @method void setLinkedTeamId(?string $v)
 * @method ?string getName()
 * @method void setName(?string $v)
 * @method ?string getColor()
 * @method void setColor(?string $v)
 * @method ?string getTags()
 * @method void setTags(?string $v)
 * @method ?int getDisplayOrder()
 * @method void setDisplayOrder(?int $v)
 * @method ?string getCreatedBy()
 * @method void setCreatedBy(?string $v)
 * @method ?int getCreatedAt()
 * @method void setCreatedAt(?int $v)
 * @method ?int getUpdatedAt()
 * @method void setUpdatedAt(?int $v)
 */
class Workspace extends Entity {
	protected $ownerType;
	protected $ownerId;
	protected $kind;
	protected $linkedTeamId;
	protected $name;
	protected $color;
	protected $tags;
	protected $displayOrder;
	protected $createdBy;
	protected $createdAt;
	protected $updatedAt;

	public function __construct() {
		$this->addType('ownerType', ColumnType::String);
		$this->addType('ownerId', ColumnType::String);
		$this->addType('kind', ColumnType::String);
		$this->addType('linkedTeamId', ColumnType::String);
		$this->addType('name', ColumnType::String);
		$this->addType('color', ColumnType::String);
		$this->addType('tags', ColumnType::String);
		$this->addType('displayOrder', ColumnType::Integer);
		$this->addType('createdBy', ColumnType::String);
		$this->addType('createdAt', ColumnType::Integer);
		$this->addType('updatedAt', ColumnType::Integer);
	}
}
