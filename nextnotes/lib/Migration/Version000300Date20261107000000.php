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

/** M5b: Index auf workspaces.created_by (Kontolöschung, Team-Workspaces eingefroren/verwaist) */
class Version000300Date20261107000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$table = $schema->getTable('nextnotes_workspaces');
		if ($table->hasIndex('nn_ws_cby')) {
			return null;
		}
		$table->addIndex(['created_by'], 'nn_ws_cby');
		return $schema;
	}
}
