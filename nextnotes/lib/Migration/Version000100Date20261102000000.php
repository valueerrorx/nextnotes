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

/** M1: Grundschema nach SPEC §2.1 (ohne assignments/submissions, die folgen in M6). */
class Version000100Date20261102000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$t = $schema->createTable('nextnotes_workspaces');
		$this->id($t);
		$this->str($t, 'owner_type', 8, false, 'user');
		$this->str($t, 'owner_id', 64, false);
		$this->str($t, 'kind', 16, false, 'normal');
		$this->str($t, 'linked_team_id', 64);
		$this->str($t, 'name', 255, false);
		$this->str($t, 'color', 32);
		$this->str($t, 'tags', 4000);
		$this->int($t, 'display_order');
		$this->str($t, 'created_by', 64);
		$this->big($t, 'created_at', false);
		$this->big($t, 'updated_at', false);
		$t->setPrimaryKey(['id']);
		$t->addIndex(['owner_type', 'owner_id'], 'nn_ws_owner');
		$t->addUniqueIndex(['owner_id', 'linked_team_id'], 'nn_ws_asg');

		$t = $schema->createTable('nextnotes_teams');
		$this->str($t, 'team_id', 64, false);
		$this->str($t, 'state', 16, false, 'active');
		$this->big($t, 'orphaned_at');
		$this->flag($t, 'missing_count');
		$this->str($t, 'team_name_snapshot', 255);
		$this->big($t, 'deletion_notified_at');
		$this->big($t, 'created_at', false);
		$this->big($t, 'updated_at', false);
		$t->setPrimaryKey(['team_id']);
		$t->addIndex(['state', 'orphaned_at'], 'nn_team_state');

		$t = $schema->createTable('nextnotes_ws_shares');
		$this->id($t);
		$this->big($t, 'workspace_id', false);
		$this->str($t, 'share_type', 8, false, 'user');
		$this->str($t, 'share_with', 64, false);
		$this->str($t, 'permission', 8, false, 'write');
		$this->str($t, 'created_by', 64, false);
		$this->big($t, 'created_at', false);
		$t->setPrimaryKey(['id']);
		$t->addUniqueIndex(['workspace_id', 'share_type', 'share_with'], 'nn_wss_uniq');
		$t->addIndex(['share_type', 'share_with'], 'nn_wss_with');

		$t = $schema->createTable('nextnotes_folders');
		$this->id($t);
		$this->big($t, 'workspace_id', false);
		$this->big($t, 'parent_id');
		$this->str($t, 'name', 255, false);
		$this->int($t, 'display_order');
		foreach (['pinned', 'favorite', 'kanban_enabled', 'is_diary'] as $c) {
			$this->flag($t, $c);
		}
		$this->str($t, 'icon', 64);
		$this->str($t, 'icon_color', 32);
		$this->str($t, 'color', 32);
		$this->str($t, 'sort_setting', 32);
		$this->big($t, 'assignment_id');
		$this->big($t, 'trashed_at');
		$this->big($t, 'created_at', false);
		$this->big($t, 'updated_at', false);
		$t->setPrimaryKey(['id']);
		$t->addIndex(['workspace_id', 'parent_id'], 'nn_fold_ws_parent');
		$t->addIndex(['assignment_id'], 'nn_fold_asg');

		$t = $schema->createTable('nextnotes_notes');
		$this->id($t);
		$this->big($t, 'workspace_id');
		$this->big($t, 'folder_id');
		$this->str($t, 'heading', 1000);
		$this->text($t, 'content');
		$this->text($t, 'search_text');
		$this->str($t, 'type', 16, false, 'note');
		$this->str($t, 'role', 16, false, 'note');
		$this->big($t, 'assignment_id');
		$this->big($t, 'submission_id');
		$this->big($t, 'linked_note_id');
		$this->flag($t, 'favorite');
		$this->flag($t, 'pinned');
		$this->str($t, 'icon', 64);
		foreach (['icon_color', 'color', 'title_color'] as $c) {
			$this->str($t, $c, 32);
		}
		$this->int($t, 'title_size', true);
		$this->int($t, 'content_width', true);
		foreach (['display_order', 'dashboard_order', 'speed_dial_order', 'kanban_order'] as $c) {
			$this->int($t, $c);
		}
		$this->big($t, 'kanban_completed');
		$this->big($t, 'trashed_at');
		$this->str($t, 'client_state_hash', 64);
		$this->str($t, 'client_state_version', 64);
		$this->str($t, 'created_by', 64);
		$this->str($t, 'updated_by', 64);
		$this->big($t, 'created_at', false);
		$this->big($t, 'updated_at', false);
		$t->setPrimaryKey(['id']);
		$t->addIndex(['workspace_id', 'trashed_at'], 'nn_note_ws');
		$t->addIndex(['folder_id'], 'nn_note_folder');
		$t->addIndex(['updated_at'], 'nn_note_upd');
		$t->addIndex(['assignment_id'], 'nn_note_asg');
		$t->addUniqueIndex(['submission_id'], 'nn_note_sub');
		$t->addIndex(['linked_note_id'], 'nn_note_link');

		$t = $schema->createTable('nextnotes_note_tags');
		$this->id($t);
		$this->big($t, 'note_id', false);
		$this->str($t, 'tag', 255, false);
		$t->setPrimaryKey(['id']);
		$t->addUniqueIndex(['note_id', 'tag'], 'nn_tag_uniq');
		$t->addIndex(['tag'], 'nn_tag_tag');

		$t = $schema->createTable('nextnotes_attachments');
		$this->id($t);
		$this->big($t, 'note_id', false);
		$this->str($t, 'original_name', 255, false);
		$this->str($t, 'mime', 255, false);
		$this->big($t, 'size', false);
		$this->str($t, 'created_by', 64);
		$this->big($t, 'created_at', false);
		$t->setPrimaryKey(['id']);
		$t->addIndex(['note_id'], 'nn_att_note');

		$t = $schema->createTable('nextnotes_snapshots');
		$this->id($t);
		$this->big($t, 'note_id', false);
		$this->str($t, 'heading', 1000);
		$this->text($t, 'content');
		$this->str($t, 'type', 16, false, 'note');
		$this->str($t, 'kind', 16, false, 'auto');
		$this->str($t, 'created_by', 64);
		$this->big($t, 'created_at', false);
		$t->setPrimaryKey(['id']);
		$t->addIndex(['note_id', 'created_at'], 'nn_snap_note');

		$t = $schema->createTable('nextnotes_reminders');
		$this->id($t);
		$this->big($t, 'note_id', false);
		$this->str($t, 'user_id', 64, false);
		$this->str($t, 'task_id', 64);
		$this->str($t, 'type', 16, false, 'reminder');
		$this->str($t, 'message', 1000);
		$this->big($t, 'trigger_at', false);
		$this->str($t, 'recurrence', 32);
		$this->big($t, 'sent_at');
		$this->big($t, 'created_at', false);
		$t->setPrimaryKey(['id']);
		$t->addIndex(['sent_at', 'trigger_at'], 'nn_rem_due');
		$t->addIndex(['note_id'], 'nn_rem_note');
		$t->addIndex(['user_id'], 'nn_rem_user');

		$t = $schema->createTable('nextnotes_locks');
		$this->big($t, 'note_id', false);
		$this->str($t, 'holder_uid', 64, false);
		$this->str($t, 'holder_session', 64, false);
		$this->str($t, 'holder_kind', 16, false, 'user');
		foreach (['created_at', 'last_seen_at', 'expires_at'] as $c) {
			$this->big($t, $c, false);
		}
		$t->setPrimaryKey(['note_id']);
		$t->addIndex(['expires_at'], 'nn_lock_exp');

		$t = $schema->createTable('nextnotes_mentions_handled');
		$this->id($t);
		$this->big($t, 'note_id', false);
		$this->str($t, 'user_id', 64, false);
		$this->int($t, 'handled_count');
		$this->big($t, 'updated_at', false);
		$t->setPrimaryKey(['id']);
		$t->addUniqueIndex(['note_id', 'user_id'], 'nn_men_uniq');

		return $schema;
	}

	private function id(ITable $t): void {
		$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
	}

	private function big(ITable $t, string $name, bool $nullable = true): void {
		$t->addColumn($name, Types::BIGINT, ['notnull' => !$nullable]);
	}

	// NOT NULL mit Default 0, außer $nullable
	private function int(ITable $t, string $name, bool $nullable = false): void {
		$t->addColumn($name, Types::INTEGER, $nullable ? ['notnull' => false] : ['notnull' => true, 'default' => 0]);
	}

	private function flag(ITable $t, string $name): void {
		$t->addColumn($name, Types::SMALLINT, ['notnull' => true, 'default' => 0]);
	}

	private function str(ITable $t, string $name, int $length, bool $nullable = true, ?string $default = null): void {
		$options = ['notnull' => !$nullable, 'length' => $length];
		if ($default !== null) {
			$options['default'] = $default;
		}
		$t->addColumn($name, Types::STRING, $options);
	}

	private function text(ITable $t, string $name): void {
		$t->addColumn($name, Types::TEXT, ['notnull' => false]);
	}
}
