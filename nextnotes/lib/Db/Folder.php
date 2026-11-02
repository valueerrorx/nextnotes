<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Schema\ColumnType;

// Zeile aus nextnotes_folders
/**
 * @method ?int getWorkspaceId()
 * @method void setWorkspaceId(?int $v)
 * @method ?int getParentId()
 * @method void setParentId(?int $v)
 * @method ?string getName()
 * @method void setName(?string $v)
 * @method ?int getDisplayOrder()
 * @method void setDisplayOrder(?int $v)
 * @method ?int getPinned()
 * @method void setPinned(?int $v)
 * @method ?int getFavorite()
 * @method void setFavorite(?int $v)
 * @method ?int getKanbanEnabled()
 * @method void setKanbanEnabled(?int $v)
 * @method ?int getIsDiary()
 * @method void setIsDiary(?int $v)
 * @method ?string getIcon()
 * @method void setIcon(?string $v)
 * @method ?string getIconColor()
 * @method void setIconColor(?string $v)
 * @method ?string getColor()
 * @method void setColor(?string $v)
 * @method ?string getSortSetting()
 * @method void setSortSetting(?string $v)
 * @method ?int getAssignmentId()
 * @method void setAssignmentId(?int $v)
 * @method ?int getTrashedAt()
 * @method void setTrashedAt(?int $v)
 * @method ?int getCreatedAt()
 * @method void setCreatedAt(?int $v)
 * @method ?int getUpdatedAt()
 * @method void setUpdatedAt(?int $v)
 */
class Folder extends Entity {
	protected $workspaceId;
	protected $parentId;
	protected $name;
	protected $displayOrder;
	protected $pinned;
	protected $favorite;
	protected $kanbanEnabled;
	protected $isDiary;
	protected $icon;
	protected $iconColor;
	protected $color;
	protected $sortSetting;
	protected $assignmentId;
	protected $trashedAt;
	protected $createdAt;
	protected $updatedAt;

	public function __construct() {
		$this->addType('workspaceId', ColumnType::Integer);
		$this->addType('parentId', ColumnType::Integer);
		$this->addType('name', ColumnType::String);
		$this->addType('displayOrder', ColumnType::Integer);
		$this->addType('pinned', ColumnType::Integer);
		$this->addType('favorite', ColumnType::Integer);
		$this->addType('kanbanEnabled', ColumnType::Integer);
		$this->addType('isDiary', ColumnType::Integer);
		$this->addType('icon', ColumnType::String);
		$this->addType('iconColor', ColumnType::String);
		$this->addType('color', ColumnType::String);
		$this->addType('sortSetting', ColumnType::String);
		$this->addType('assignmentId', ColumnType::Integer);
		$this->addType('trashedAt', ColumnType::Integer);
		$this->addType('createdAt', ColumnType::Integer);
		$this->addType('updatedAt', ColumnType::Integer);
	}
}
