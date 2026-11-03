<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Schema\ColumnType;

// Zeile aus nextnotes_notes
/**
 * @method ?int getWorkspaceId()
 * @method void setWorkspaceId(?int $v)
 * @method ?int getFolderId()
 * @method void setFolderId(?int $v)
 * @method ?string getHeading()
 * @method void setHeading(?string $v)
 * @method ?string getContent()
 * @method void setContent(?string $v)
 * @method ?string getSearchText()
 * @method void setSearchText(?string $v)
 * @method ?string getType()
 * @method void setType(?string $v)
 * @method ?string getRole()
 * @method void setRole(?string $v)
 * @method ?int getAssignmentId()
 * @method void setAssignmentId(?int $v)
 * @method ?int getSubmissionId()
 * @method void setSubmissionId(?int $v)
 * @method ?int getLinkedNoteId()
 * @method void setLinkedNoteId(?int $v)
 * @method ?int getFavorite()
 * @method void setFavorite(?int $v)
 * @method ?int getPinned()
 * @method void setPinned(?int $v)
 * @method ?string getIcon()
 * @method void setIcon(?string $v)
 * @method ?string getIconColor()
 * @method void setIconColor(?string $v)
 * @method ?string getColor()
 * @method void setColor(?string $v)
 * @method ?string getTitleColor()
 * @method void setTitleColor(?string $v)
 * @method ?int getTitleSize()
 * @method void setTitleSize(?int $v)
 * @method ?int getContentWidth()
 * @method void setContentWidth(?int $v)
 * @method ?int getDisplayOrder()
 * @method void setDisplayOrder(?int $v)
 * @method ?int getDashboardOrder()
 * @method void setDashboardOrder(?int $v)
 * @method ?int getSpeedDialOrder()
 * @method void setSpeedDialOrder(?int $v)
 * @method ?int getKanbanOrder()
 * @method void setKanbanOrder(?int $v)
 * @method ?int getKanbanCompleted()
 * @method void setKanbanCompleted(?int $v)
 * @method ?int getTrashedAt()
 * @method void setTrashedAt(?int $v)
 * @method ?string getClientStateHash()
 * @method void setClientStateHash(?string $v)
 * @method ?string getClientStateVersion()
 * @method void setClientStateVersion(?string $v)
 * @method ?string getCreatedBy()
 * @method void setCreatedBy(?string $v)
 * @method ?string getUpdatedBy()
 * @method void setUpdatedBy(?string $v)
 * @method ?int getCreatedAt()
 * @method void setCreatedAt(?int $v)
 * @method ?int getUpdatedAt()
 * @method void setUpdatedAt(?int $v)
 */
class Note extends Entity {
	protected $workspaceId;
	protected $folderId;
	protected $heading;
	protected $content;
	protected $searchText;
	protected $type;
	protected $role;
	protected $assignmentId;
	protected $submissionId;
	protected $linkedNoteId;
	protected $favorite;
	protected $pinned;
	protected $icon;
	protected $iconColor;
	protected $color;
	protected $titleColor;
	protected $titleSize;
	protected $contentWidth;
	protected $displayOrder;
	protected $dashboardOrder;
	protected $speedDialOrder;
	protected $kanbanOrder;
	protected $kanbanCompleted;
	protected $trashedAt;
	protected $clientStateHash;
	protected $clientStateVersion;
	protected $createdBy;
	protected $updatedBy;
	protected $createdAt;
	protected $updatedAt;

	public function __construct() {
		$this->addType('workspaceId', ColumnType::Integer);
		$this->addType('folderId', ColumnType::Integer);
		$this->addType('heading', ColumnType::String);
		$this->addType('content', ColumnType::String);
		$this->addType('searchText', ColumnType::String);
		$this->addType('type', ColumnType::String);
		$this->addType('role', ColumnType::String);
		$this->addType('assignmentId', ColumnType::Integer);
		$this->addType('submissionId', ColumnType::Integer);
		$this->addType('linkedNoteId', ColumnType::Integer);
		$this->addType('favorite', ColumnType::Integer);
		$this->addType('pinned', ColumnType::Integer);
		$this->addType('icon', ColumnType::String);
		$this->addType('iconColor', ColumnType::String);
		$this->addType('color', ColumnType::String);
		$this->addType('titleColor', ColumnType::String);
		$this->addType('titleSize', ColumnType::Integer);
		$this->addType('contentWidth', ColumnType::Integer);
		$this->addType('displayOrder', ColumnType::Integer);
		$this->addType('dashboardOrder', ColumnType::Integer);
		$this->addType('speedDialOrder', ColumnType::Integer);
		$this->addType('kanbanOrder', ColumnType::Integer);
		$this->addType('kanbanCompleted', ColumnType::Integer);
		$this->addType('trashedAt', ColumnType::Integer);
		$this->addType('clientStateHash', ColumnType::String);
		$this->addType('clientStateVersion', ColumnType::String);
		$this->addType('createdBy', ColumnType::String);
		$this->addType('updatedBy', ColumnType::String);
		$this->addType('createdAt', ColumnType::Integer);
		$this->addType('updatedAt', ColumnType::Integer);
	}

	// search_text aus den geänderten Feldern nehmen (NoteMapper schreibt ihn getrennt, B37)
	public function takeSearchTextUpdate(): bool {
		$fields = $this->getUpdatedFields();
		if (!isset($fields['searchText'], $fields['content'])) {
			return false;
		}
		$this->resetUpdatedFields();
		foreach (array_keys($fields) as $field) {
			if ($field !== 'searchText') {
				$this->markFieldUpdated($field);
			}
		}
		return true;
	}
}
