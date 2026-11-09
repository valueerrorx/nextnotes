<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Schema\ColumnType;

// Zeile aus nextnotes_onenote_imports (Plan M9 §3); Token und Verifier nur verschlüsselt
/**
 * @method ?string getUid()
 * @method void setUid(?string $v)
 * @method ?string getStatus()
 * @method void setStatus(?string $v)
 * @method ?string getAuthority()
 * @method void setAuthority(?string $v)
 * @method ?string getClientId()
 * @method void setClientId(?string $v)
 * @method ?int getTeamScopes()
 * @method void setTeamScopes(?int $v)
 * @method ?string getMsAccount()
 * @method void setMsAccount(?string $v)
 * @method ?string getStateHash()
 * @method void setStateHash(?string $v)
 * @method ?string getPkceEnc()
 * @method void setPkceEnc(?string $v)
 * @method ?string getRedirectUri()
 * @method void setRedirectUri(?string $v)
 * @method ?int getAuthExpiresAt()
 * @method void setAuthExpiresAt(?int $v)
 * @method ?string getAccessEnc()
 * @method void setAccessEnc(?string $v)
 * @method ?string getRefreshEnc()
 * @method void setRefreshEnc(?string $v)
 * @method ?int getAccessExpiresAt()
 * @method void setAccessExpiresAt(?int $v)
 * @method ?string getSelection()
 * @method void setSelection(?string $v)
 * @method ?string getCursor()
 * @method void setCursor(?string $v)
 * @method ?int getPagesTotal()
 * @method void setPagesTotal(?int $v)
 * @method ?int getPagesDone()
 * @method void setPagesDone(?int $v)
 * @method ?int getPagesSkipped()
 * @method void setPagesSkipped(?int $v)
 * @method ?int getResourcesDone()
 * @method void setResourcesDone(?int $v)
 * @method ?int getResourcesFailed()
 * @method void setResourcesFailed(?int $v)
 * @method ?string getReport()
 * @method void setReport(?string $v)
 * @method ?string getRateBuckets()
 * @method void setRateBuckets(?string $v)
 * @method ?int getRateVersion()
 * @method void setRateVersion(?int $v)
 * @method ?int getBackoffLevel()
 * @method void setBackoffLevel(?int $v)
 * @method ?int getNotFoundStreak()
 * @method void setNotFoundStreak(?int $v)
 * @method ?int getNextRunAt()
 * @method void setNextRunAt(?int $v)
 * @method ?int getLeaseUntil()
 * @method void setLeaseUntil(?int $v)
 * @method ?string getLeaseToken()
 * @method void setLeaseToken(?string $v)
 * @method ?string getErrorCode()
 * @method void setErrorCode(?string $v)
 * @method ?int getCreatedAt()
 * @method void setCreatedAt(?int $v)
 * @method ?int getUpdatedAt()
 * @method void setUpdatedAt(?int $v)
 * @method ?int getFinishedAt()
 * @method void setFinishedAt(?int $v)
 */
class OneNoteImport extends Entity {
	protected $uid;
	protected $status;
	protected $authority;
	protected $clientId;
	protected $teamScopes;
	protected $msAccount;
	protected $stateHash;
	protected $pkceEnc;
	protected $redirectUri;
	protected $authExpiresAt;
	protected $accessEnc;
	protected $refreshEnc;
	protected $accessExpiresAt;
	protected $selection;
	protected $cursor;
	protected $pagesTotal;
	protected $pagesDone;
	protected $pagesSkipped;
	protected $resourcesDone;
	protected $resourcesFailed;
	protected $report;
	protected $rateBuckets;
	protected $rateVersion;
	protected $backoffLevel;
	protected $notFoundStreak;
	protected $nextRunAt;
	protected $leaseUntil;
	protected $leaseToken;
	protected $errorCode;
	protected $createdAt;
	protected $updatedAt;
	protected $finishedAt;

	public function __construct() {
		$this->addType('uid', ColumnType::String);
		$this->addType('status', ColumnType::String);
		$this->addType('authority', ColumnType::String);
		$this->addType('clientId', ColumnType::String);
		$this->addType('teamScopes', ColumnType::Integer);
		$this->addType('msAccount', ColumnType::String);
		$this->addType('stateHash', ColumnType::String);
		$this->addType('pkceEnc', ColumnType::String);
		$this->addType('redirectUri', ColumnType::String);
		$this->addType('authExpiresAt', ColumnType::Integer);
		$this->addType('accessEnc', ColumnType::String);
		$this->addType('refreshEnc', ColumnType::String);
		$this->addType('accessExpiresAt', ColumnType::Integer);
		$this->addType('selection', ColumnType::String);
		$this->addType('cursor', ColumnType::String);
		$this->addType('pagesTotal', ColumnType::Integer);
		$this->addType('pagesDone', ColumnType::Integer);
		$this->addType('pagesSkipped', ColumnType::Integer);
		$this->addType('resourcesDone', ColumnType::Integer);
		$this->addType('resourcesFailed', ColumnType::Integer);
		$this->addType('report', ColumnType::String);
		$this->addType('rateBuckets', ColumnType::String);
		$this->addType('rateVersion', ColumnType::Integer);
		$this->addType('backoffLevel', ColumnType::Integer);
		$this->addType('notFoundStreak', ColumnType::Integer);
		$this->addType('nextRunAt', ColumnType::Integer);
		$this->addType('leaseUntil', ColumnType::Integer);
		$this->addType('leaseToken', ColumnType::String);
		$this->addType('errorCode', ColumnType::String);
		$this->addType('createdAt', ColumnType::Integer);
		$this->addType('updatedAt', ColumnType::Integer);
		$this->addType('finishedAt', ColumnType::Integer);
	}
}
