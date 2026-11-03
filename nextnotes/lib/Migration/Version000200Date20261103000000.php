<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** M2d: Indizes auf UID-Spalten großer Tabellen für die Kontolöschung (SPEC §8) */
class Version000200Date20261103000000 extends SimpleMigrationStep {
	private const INDEXES = [
		'nextnotes_notes' => ['nn_note_cby' => 'created_by', 'nn_note_uby' => 'updated_by'],
		'nextnotes_attachments' => ['nn_att_cby' => 'created_by'],
		'nextnotes_snapshots' => ['nn_snap_cby' => 'created_by'],
		'nextnotes_mentions_handled' => ['nn_men_user' => 'user_id'],
	];

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;
		foreach (self::INDEXES as $tableName => $indexes) {
			$table = $schema->getTable($tableName);
			foreach ($indexes as $name => $column) {
				if (!$table->hasIndex($name)) {
					$table->addIndex([$column], $name);
					$changed = true;
				}
			}
		}
		return $changed ? $schema : null;
	}
}
