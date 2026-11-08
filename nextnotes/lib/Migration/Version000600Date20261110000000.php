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

/** M6d: Aufgaben und Abgaben (SPEC §2.1 nextnotes_assignments, nextnotes_submissions) */
class Version000600Date20261110000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		if (!$schema->hasTable('nextnotes_assignments')) {
			$t = $schema->createTable('nextnotes_assignments');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			self::str($t, 'team_id', 64, false);
			self::str($t, 'title', 255, false);
			self::big($t, 'source_note_id');
			self::big($t, 'template_note_id', false);
			self::big($t, 'due_at', false);
			self::str($t, 'status', 16, false, 'draft');
			$t->addColumn('max_points', Types::INTEGER, ['notnull' => false]);
			self::str($t, 'created_by', 64);
			self::str($t, 'created_by_name', 255);
			self::str($t, 'owner_uid', 64);
			self::big($t, 'distributed_at');
			self::big($t, 'deadline_notified_at');
			self::big($t, 'created_at', false);
			self::big($t, 'updated_at', false);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['team_id', 'status'], 'nn_asg_team');
			$t->addIndex(['status', 'due_at'], 'nn_asg_due');
			$t->addIndex(['owner_uid'], 'nn_asg_owner');
			$changed = true;
		}

		if (!$schema->hasTable('nextnotes_submissions')) {
			$t = $schema->createTable('nextnotes_submissions');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			self::big($t, 'assignment_id', false);
			self::str($t, 'student_uid', 64);
			self::str($t, 'student_name', 255, false);
			self::str($t, 'student_uid_former', 64);
			self::big($t, 'student_deleted_at');
			self::big($t, 'note_id');
			self::str($t, 'status', 16, false, 'assigned');
			self::big($t, 'due_override_at');
			self::big($t, 'submitted_at');
			self::big($t, 'returned_at');
			$t->addColumn('feedback', Types::TEXT, ['notnull' => false]);
			$t->addColumn('points', Types::INTEGER, ['notnull' => false]);
			self::str($t, 'feedback_by', 64);
			self::str($t, 'feedback_by_name', 255);
			self::big($t, 'feedback_at');
			self::big($t, 'left_team_at');
			self::big($t, 'created_at', false);
			self::big($t, 'updated_at', false);
			$t->setPrimaryKey(['id']);
			// mehrere NULL (gelöschte Konten) erlaubt: SQLite, MariaDB, PostgreSQL
			$t->addUniqueIndex(['assignment_id', 'student_uid'], 'nn_sub_uniq');
			$t->addIndex(['student_uid', 'status'], 'nn_sub_student');
			$t->addIndex(['status', 'due_override_at'], 'nn_sub_due');
			$changed = true;
		}
		return $changed ? $schema : null;
	}

	private static function big(ITable $t, string $name, bool $nullable = true): void {
		$t->addColumn($name, Types::BIGINT, ['notnull' => !$nullable]);
	}

	private static function str(ITable $t, string $name, int $length, bool $nullable = true, ?string $default = null): void {
		$options = ['notnull' => !$nullable, 'length' => $length];
		if ($default !== null) {
			$options['default'] = $default;
		}
		$t->addColumn($name, Types::STRING, $options);
	}
}
