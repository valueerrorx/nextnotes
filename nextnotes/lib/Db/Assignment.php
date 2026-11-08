<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Schema\ColumnType;

// Zeile aus nextnotes_assignments (SPEC §2.1, §9)
/**
 * @method ?string getTeamId()
 * @method void setTeamId(?string $v)
 * @method ?string getTitle()
 * @method void setTitle(?string $v)
 * @method ?int getSourceNoteId()
 * @method void setSourceNoteId(?int $v)
 * @method ?int getTemplateNoteId()
 * @method void setTemplateNoteId(?int $v)
 * @method ?int getDueAt()
 * @method void setDueAt(?int $v)
 * @method ?string getStatus()
 * @method void setStatus(?string $v)
 * @method ?int getMaxPoints()
 * @method void setMaxPoints(?int $v)
 * @method ?string getCreatedBy()
 * @method void setCreatedBy(?string $v)
 * @method ?string getCreatedByName()
 * @method void setCreatedByName(?string $v)
 * @method ?string getOwnerUid()
 * @method void setOwnerUid(?string $v)
 * @method ?int getDistributedAt()
 * @method void setDistributedAt(?int $v)
 * @method ?int getDeadlineNotifiedAt()
 * @method void setDeadlineNotifiedAt(?int $v)
 * @method ?int getCreatedAt()
 * @method void setCreatedAt(?int $v)
 * @method ?int getUpdatedAt()
 * @method void setUpdatedAt(?int $v)
 */
class Assignment extends Entity {
	protected $teamId;
	protected $title;
	protected $sourceNoteId;
	protected $templateNoteId;
	protected $dueAt;
	protected $status;
	protected $maxPoints;
	protected $createdBy;
	protected $createdByName;
	protected $ownerUid;
	protected $distributedAt;
	protected $deadlineNotifiedAt;
	protected $createdAt;
	protected $updatedAt;

	public function __construct() {
		$this->addType('teamId', ColumnType::String);
		$this->addType('title', ColumnType::String);
		$this->addType('sourceNoteId', ColumnType::Integer);
		$this->addType('templateNoteId', ColumnType::Integer);
		$this->addType('dueAt', ColumnType::Integer);
		$this->addType('status', ColumnType::String);
		$this->addType('maxPoints', ColumnType::Integer);
		$this->addType('createdBy', ColumnType::String);
		$this->addType('createdByName', ColumnType::String);
		$this->addType('ownerUid', ColumnType::String);
		$this->addType('distributedAt', ColumnType::Integer);
		$this->addType('deadlineNotifiedAt', ColumnType::Integer);
		$this->addType('createdAt', ColumnType::Integer);
		$this->addType('updatedAt', ColumnType::Integer);
	}
}
