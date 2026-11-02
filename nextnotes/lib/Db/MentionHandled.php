<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Schema\ColumnType;

// Zeile aus nextnotes_mentions_handled: so oft nannte die Notiz den Benutzer, als er sie erledigte
/**
 * @method ?int getNoteId()
 * @method void setNoteId(?int $v)
 * @method ?string getUserId()
 * @method void setUserId(?string $v)
 * @method ?int getHandledCount()
 * @method void setHandledCount(?int $v)
 * @method ?int getUpdatedAt()
 * @method void setUpdatedAt(?int $v)
 */
class MentionHandled extends Entity {
	protected $noteId;
	protected $userId;
	protected $handledCount;
	protected $updatedAt;

	public function __construct() {
		$this->addType('noteId', ColumnType::Integer);
		$this->addType('userId', ColumnType::String);
		$this->addType('handledCount', ColumnType::Integer);
		$this->addType('updatedAt', ColumnType::Integer);
	}
}
