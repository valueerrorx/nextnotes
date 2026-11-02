<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Schema\ColumnType;

// Zeile aus nextnotes_note_tags
/**
 * @method ?int getNoteId()
 * @method void setNoteId(?int $v)
 * @method ?string getTag()
 * @method void setTag(?string $v)
 */
class NoteTag extends Entity {
	protected $noteId;
	protected $tag;

	public function __construct() {
		$this->addType('noteId', ColumnType::Integer);
		$this->addType('tag', ColumnType::String);
	}
}
