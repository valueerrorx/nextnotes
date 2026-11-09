<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Migration;

use Closure;
use OCA\NextNotes\Db\AssignmentMapper;
use OCA\NextNotes\Service\AssignmentDeletion;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * M6-wsbind (ADR 0021): Aufgabe gehört genau einem Team-Workspace, workspace_id NOT NULL.
 * Vorhandene Aufgaben löscht der normale Löschpfad (Lead: Dev/Demo-Daten); keine Befüllungs- oder NULL-Logik.
 * Achtung: purge() läuft mit aktuellem Code gegen das Schema vor 0.9.3; spätere Migrationen, die Mapper/Services von purge ändern, müssen das berücksichtigen.
 */
class Version000800Date20261112000000 extends SimpleMigrationStep {
	public function __construct(
		private AssignmentDeletion $deletion,
		private IDBConnection $db,
	) {
	}

	// nur wenn die Spalte fehlt (Upgrade); Neuinstallation: Tabelle leer, nichts zu tun
	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable(AssignmentMapper::TABLE) || $schema->getTable(AssignmentMapper::TABLE)->hasColumn('workspace_id')) {
			return;
		}
		$ids = $this->assignmentIds();
		foreach ($ids as $id) {
			$this->deletion->purge($id);
		}
		if ($this->assignmentIds() !== []) {
			throw new \RuntimeException('nextnotes: existing assignments could not be removed before binding them to team workspaces; upgrade aborted, schema unchanged');
		}
		if ($ids !== []) {
			$output->info('nextnotes: removed ' . count($ids) . ' assignments (assignments are now bound to a team workspace)');
		}
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$t = $schema->getTable(AssignmentMapper::TABLE);
		if ($t->hasColumn('workspace_id')) {
			return null;
		}
		// Tabelle ist leer: NOT NULL ohne Default geht auf SQLite, MariaDB, PostgreSQL; keine FK (Kaskade im Service)
		$t->addColumn('workspace_id', Types::BIGINT, ['notnull' => true]);
		$t->addIndex(['workspace_id'], 'nn_asg_ws');
		return $schema;
	}

	/** @return int[] */
	private function assignmentIds(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from(AssignmentMapper::TABLE)->orderBy('id', 'ASC');
		return array_map('intval', $qb->executeQuery()->fetchFirstColumn());
	}
}
