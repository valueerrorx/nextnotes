<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Schema\ColumnType;

// Zeile aus nextnotes_reminders; user_id = Empfänger, task_id NULL = Notiz-Erinnerung
/**
 * @method ?int getNoteId()
 * @method void setNoteId(?int $v)
 * @method ?string getUserId()
 * @method void setUserId(?string $v)
 * @method ?string getTaskId()
 * @method void setTaskId(?string $v)
 * @method ?string getType()
 * @method void setType(?string $v)
 * @method ?string getMessage()
 * @method void setMessage(?string $v)
 * @method ?int getTriggerAt()
 * @method void setTriggerAt(?int $v)
 * @method ?string getRecurrence()
 * @method void setRecurrence(?string $v)
 * @method ?int getSentAt()
 * @method void setSentAt(?int $v)
 * @method ?int getCreatedAt()
 * @method void setCreatedAt(?int $v)
 */
class Reminder extends Entity {
	protected $noteId;
	protected $userId;
	protected $taskId;
	protected $type;
	protected $message;
	protected $triggerAt;
	protected $recurrence;
	protected $sentAt;
	protected $createdAt;

	public function __construct() {
		$this->addType('noteId', ColumnType::Integer);
		$this->addType('userId', ColumnType::String);
		$this->addType('taskId', ColumnType::String);
		$this->addType('type', ColumnType::String);
		$this->addType('message', ColumnType::String);
		$this->addType('triggerAt', ColumnType::Integer);
		$this->addType('recurrence', ColumnType::String);
		$this->addType('sentAt', ColumnType::Integer);
		$this->addType('createdAt', ColumnType::Integer);
	}
}
