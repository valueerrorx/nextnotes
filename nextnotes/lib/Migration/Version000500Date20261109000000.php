<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** M6a: Material-Ordner (folders.material) und Papierkorb-Markierung (notes.trash_material) */
class Version000500Date20261109000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;
		$folders = $schema->getTable('nextnotes_folders');
		if (!$folders->hasColumn('material')) {
			$folders->addColumn('material', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$changed = true;
		}
		$notes = $schema->getTable('nextnotes_notes');
		if (!$notes->hasColumn('trash_material')) {
			$notes->addColumn('trash_material', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$changed = true;
		}
		return $changed ? $schema : null;
	}
}
