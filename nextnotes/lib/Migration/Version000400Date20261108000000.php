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

/** M5c: Herkunft erzeugter Anhänge (Excalidraw-Vorschau), damit der CleanupJob nur sicher erkannte Vorschauen entfernt */
class Version000400Date20261108000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$table = $schema->getTable('nextnotes_attachments');
		if ($table->hasColumn('origin')) {
			return null;
		}
		$table->addColumn('origin', Types::STRING, ['notnull' => false, 'length' => 16]);
		$table->addIndex(['origin', 'id'], 'nn_att_origin');
		return $schema;
	}
}
