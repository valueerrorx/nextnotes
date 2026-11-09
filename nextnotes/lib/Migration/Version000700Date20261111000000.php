<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Schema\ITable;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** M9a: OneNote-Import (Plan M9 §3, ADR 0020): Import-Zeilen und Idempotenz-Einträge */
class Version000700Date20261111000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		if (!$schema->hasTable('nextnotes_onenote_imports')) {
			$t = $schema->createTable('nextnotes_onenote_imports');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			self::str($t, 'uid', 64, false);
			self::str($t, 'status', 16, false);
			self::str($t, 'authority', 64, false);
			self::str($t, 'client_id', 64, false);
			self::small($t, 'team_scopes');
			self::str($t, 'ms_account', 64);
			self::str($t, 'state_hash', 64);
			self::text($t, 'pkce_enc');
			self::str($t, 'redirect_uri', 1000);
			self::big($t, 'auth_expires_at');
			self::text($t, 'access_enc');
			self::text($t, 'refresh_enc');
			self::big($t, 'access_expires_at');
			self::text($t, 'selection');
			self::text($t, 'cursor');
			foreach (['pages_total', 'pages_done', 'pages_skipped', 'resources_done', 'resources_failed', 'rate_version'] as $c) {
				$t->addColumn($c, Types::INTEGER, ['notnull' => true, 'default' => 0]);
			}
			self::text($t, 'report');
			self::text($t, 'rate_buckets');
			self::small($t, 'backoff_level');
			self::small($t, 'not_found_streak');
			self::big($t, 'next_run_at');
			self::big($t, 'lease_until');
			self::str($t, 'lease_token', 32);
			self::str($t, 'error_code', 64);
			self::big($t, 'created_at', false);
			self::big($t, 'updated_at', false);
			self::big($t, 'finished_at');
			$t->setPrimaryKey(['id']);
			$t->addIndex(['uid', 'status'], 'nn_oni_uid');
			$t->addIndex(['status', 'next_run_at'], 'nn_oni_run');
			$t->addIndex(['state_hash'], 'nn_oni_state');
			$changed = true;
		}

		if (!$schema->hasTable('nextnotes_onenote_items')) {
			$t = $schema->createTable('nextnotes_onenote_items');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			self::str($t, 'uid', 64, false);
			self::str($t, 'source_hash', 64, false);
			self::str($t, 'kind', 16, false);
			self::big($t, 'target_id', false);
			self::big($t, 'source_modified_at');
			self::big($t, 'import_id', false);
			self::big($t, 'created_at', false);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['uid', 'source_hash'], 'nn_oni_src');
			$t->addIndex(['kind', 'target_id'], 'nn_oni_tgt');
			$changed = true;
		}
		return $changed ? $schema : null;
	}

	private static function big(ITable $t, string $name, bool $nullable = true): void {
		$t->addColumn($name, Types::BIGINT, ['notnull' => !$nullable]);
	}

	private static function small(ITable $t, string $name): void {
		$t->addColumn($name, Types::SMALLINT, ['notnull' => true, 'default' => 0]);
	}

	private static function text(ITable $t, string $name): void {
		$t->addColumn($name, Types::TEXT, ['notnull' => false]);
	}

	private static function str(ITable $t, string $name, int $length, bool $nullable = true): void {
		$t->addColumn($name, Types::STRING, ['notnull' => !$nullable, 'length' => $length]);
	}
}
