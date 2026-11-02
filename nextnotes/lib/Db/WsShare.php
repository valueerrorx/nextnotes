<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Schema\ColumnType;

// Zeile aus nextnotes_ws_shares (ADR 0014)
/**
 * @method ?int getWorkspaceId()
 * @method void setWorkspaceId(?int $v)
 * @method ?string getShareType()
 * @method void setShareType(?string $v)
 * @method ?string getShareWith()
 * @method void setShareWith(?string $v)
 * @method ?string getPermission()
 * @method void setPermission(?string $v)
 * @method ?string getCreatedBy()
 * @method void setCreatedBy(?string $v)
 * @method ?int getCreatedAt()
 * @method void setCreatedAt(?int $v)
 */
class WsShare extends Entity {
	protected $workspaceId;
	protected $shareType;
	protected $shareWith;
	protected $permission;
	protected $createdBy;
	protected $createdAt;

	public function __construct() {
		$this->addType('workspaceId', ColumnType::Integer);
		$this->addType('shareType', ColumnType::String);
		$this->addType('shareWith', ColumnType::String);
		$this->addType('permission', ColumnType::String);
		$this->addType('createdBy', ColumnType::String);
		$this->addType('createdAt', ColumnType::Integer);
	}
}
