<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Import-Zeilen (Plan M9 §3); jeder Zugriff mit uid, Schreibvorgänge bedingt (Zeilenzahl = Ergebnis)
 * @extends QBMapper<OneNoteImport>
 */
class OneNoteImportMapper extends QBMapper {
	public const TABLE = 'nextnotes_onenote_imports';
	// offen = höchstens einer je uid (Plan M9 §3)
	public const OPEN = ['auth', 'select', 'queued', 'running', 'reauth'];
	// Spalten mit Geheimnissen oder Anmeldedaten; NULL außerhalb der jeweiligen Phase
	public const SECRET_COLUMNS = ['state_hash', 'pkce_enc', 'auth_expires_at', 'access_enc', 'refresh_enc', 'access_expires_at', 'lease_token', 'lease_until'];

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE, OneNoteImport::class);
	}

	/** @throws DoesNotExistException fremde oder fehlende Zeile */
	public function findOwn(int $id, string $uid): OneNoteImport {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));
		return $this->findEntity($qb);
	}

	// Callback: nur eigene Zeile mit gültigem state (Plan M9 §4.2.1)
	public function findByState(string $stateHash, string $uid, int $now): ?OneNoteImport {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('state_hash', $qb->createNamedParameter($stateHash)))
			->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->gt('auth_expires_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** @return OneNoteImport[] offene Zeilen; uid null = alle Benutzer */
	public function findOpen(?string $uid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->in('status', $qb->createNamedParameter(self::OPEN, IQueryBuilder::PARAM_STR_ARRAY)))
			->orderBy('id', 'ASC');
		if ($uid !== null) {
			$qb->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));
		}
		return $this->findEntities($qb);
	}

	/** @return OneNoteImport[] eigene Zeilen ab $since oder noch offen, neueste zuerst */
	public function findRecent(string $uid, int $since): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->gte('created_at', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)),
				$qb->expr()->in('status', $qb->createNamedParameter(self::OPEN, IQueryBuilder::PARAM_STR_ARRAY)),
			))
			->orderBy('id', 'DESC');
		return $this->findEntities($qb);
	}

	/** @return OneNoteImport[] alle Zeilen einer uid (Auskunft) */
	public function findAllOf(string $uid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	// Budget der jüngsten Zeile derselben uid (Plan M9 §3 „Budget je uid“)
	public function latestRateBuckets(string $uid): ?string {
		$qb = $this->db->getQueryBuilder();
		$qb->select('rate_buckets')->from(self::TABLE)
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->orderBy('id', 'DESC')
			->setMaxResults(1);
		$v = $qb->executeQuery()->fetchOne();
		return is_string($v) && $v !== '' ? $v : null;
	}

	/**
	 * Bedingtes UPDATE; $where: Spalte → Wert, Liste (IN) oder null (IS NULL)
	 * @param array<string, int|string|null> $set
	 * @param array<string, int|string|list<string>|null> $where
	 * @return int betroffene Zeilen
	 */
	public function updateWhere(int $id, string $uid, array $set, array $where = []): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE);
		foreach ($set as $col => $value) {
			$qb->set($col, $qb->createNamedParameter($value, self::type($value)));
		}
		$qb->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));
		self::conditions($qb, $where);
		return $qb->executeStatement();
	}

	/**
	 * Bedingtes DELETE
	 * @param array<string, int|string|list<string>|null> $where
	 */
	public function deleteWhere(int $id, string $uid, array $where = []): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));
		self::conditions($qb, $where);
		return $qb->executeStatement();
	}

	public function deleteByUid(string $uid): int {
		$qb = $this->db->getQueryBuilder();
		return $qb->delete(self::TABLE)->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))->executeStatement();
	}

	/** @param array<string, int|string|list<string>|null> $where */
	private static function conditions(IQueryBuilder $qb, array $where): void {
		foreach ($where as $col => $value) {
			if ($value === null) {
				$qb->andWhere($qb->expr()->isNull($col));
			} elseif (is_array($value)) {
				$qb->andWhere($qb->expr()->in($col, $qb->createNamedParameter($value, IQueryBuilder::PARAM_STR_ARRAY)));
			} else {
				$qb->andWhere($qb->expr()->eq($col, $qb->createNamedParameter($value, self::type($value))));
			}
		}
	}

	private static function type(int|string|null $value): int {
		return match (true) {
			$value === null => IQueryBuilder::PARAM_NULL,
			is_int($value) => IQueryBuilder::PARAM_INT,
			default => IQueryBuilder::PARAM_STR,
		};
	}
}
