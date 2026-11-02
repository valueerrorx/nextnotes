<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Schema\ColumnType;

// Zeile aus nextnotes_attachments; Datei in IAppData attachments/<note_id>/<id>
/**
 * @method ?int getNoteId()
 * @method void setNoteId(?int $v)
 * @method ?string getOriginalName()
 * @method void setOriginalName(?string $v)
 * @method ?string getMime()
 * @method void setMime(?string $v)
 * @method ?int getSize()
 * @method void setSize(?int $v)
 * @method ?string getCreatedBy()
 * @method void setCreatedBy(?string $v)
 * @method ?int getCreatedAt()
 * @method void setCreatedAt(?int $v)
 */
class Attachment extends Entity {
	protected $noteId;
	protected $originalName;
	protected $mime;
	protected $size;
	protected $createdBy;
	protected $createdAt;

	public function __construct() {
		$this->addType('noteId', ColumnType::Integer);
		$this->addType('originalName', ColumnType::String);
		$this->addType('mime', ColumnType::String);
		$this->addType('size', ColumnType::Integer);
		$this->addType('createdBy', ColumnType::String);
		$this->addType('createdAt', ColumnType::Integer);
	}
}
