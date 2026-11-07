<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Schema\ColumnType;

// Zeile aus nextnotes_teams (SPEC §2.1); Schlüssel team_id, nur über TeamMapper geschrieben
/**
 * @method ?string getTeamId()
 * @method void setTeamId(?string $v)
 * @method ?string getState()
 * @method void setState(?string $v)
 * @method ?int getOrphanedAt()
 * @method void setOrphanedAt(?int $v)
 * @method ?int getMissingCount()
 * @method void setMissingCount(?int $v)
 * @method ?string getTeamNameSnapshot()
 * @method void setTeamNameSnapshot(?string $v)
 * @method ?int getDeletionNotifiedAt()
 * @method void setDeletionNotifiedAt(?int $v)
 * @method ?int getCreatedAt()
 * @method void setCreatedAt(?int $v)
 * @method ?int getUpdatedAt()
 * @method void setUpdatedAt(?int $v)
 */
class Team extends Entity {
	protected $teamId;
	protected $state;
	protected $orphanedAt;
	protected $missingCount;
	protected $teamNameSnapshot;
	protected $deletionNotifiedAt;
	protected $createdAt;
	protected $updatedAt;

	public function __construct() {
		$this->addType('teamId', ColumnType::String);
		$this->addType('state', ColumnType::String);
		$this->addType('orphanedAt', ColumnType::Integer);
		$this->addType('missingCount', ColumnType::Integer);
		$this->addType('teamNameSnapshot', ColumnType::String);
		$this->addType('deletionNotifiedAt', ColumnType::Integer);
		$this->addType('createdAt', ColumnType::Integer);
		$this->addType('updatedAt', ColumnType::Integer);
	}
}
