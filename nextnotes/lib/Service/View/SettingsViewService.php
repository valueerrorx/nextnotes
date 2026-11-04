<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service\View;

use OCA\NextNotes\Db\FolderMapper;
use OCA\NextNotes\Db\NoteMapper;
use OCA\NextNotes\Db\Workspace;
use OCA\NextNotes\Service\AttachmentService;
use OCA\NextNotes\Service\InputValidator;
use OCA\NextNotes\Service\NotePalette;
use OCA\NextNotes\Service\SettingsService;
use OCA\NextNotes\Service\WorkspaceService;

/** Daten der Einstellungen (Upstream settings.php, workspaces_section.php, storage-stats-user.php) */
class SettingsViewService {
	// Upstream $settingsPageUserKeys ohne gestrichene Funktionen (Offline, What's new, Workspace-E-Mails)
	private const PAGE_KEYS = ['emoji_icons_enabled', 'language', 'show_note_created', 'show_note_icons', NotePalette::SETTING,
		'hide_folder_actions', 'highlight_current_folder_tree', 'folder_tree_dim_level', 'notes_without_folders_after_folders',
		'folder_icon_opens_kanban', 'folder_count_parentheses', 'markdown_split_card_view', 'markdown_split_preview_left',
		'markdown_heading_underline', 'markdown_editor_word_wrap', 'markdown_default_view_mode', 'markdown_new_note_view_mode',
		'markdown_view_cycle', 'markdown_colored', 'markdown_colored_custom', 'code_block_word_wrap', 'code_block_line_numbers',
		'attachment_previews_in_note', 'attachments_at_bottom', 'backlinks_at_bottom', 'note_virtual_space',
		'default_image_border_no_padding', 'center_note_content', 'note_age_filter_days', 'snapshots_keep_count',
		'snapshots_safety_keep_count', 'tasklist_insert_order', 'diary_default_note_type', 'diary_date_format', 'diary_templates',
		'toolbar_mode', 'timezone', 'default_workspace', 'date_time_format', 'hidden_ui_elements', 'settings_pinned_cards',
		'spellcheck_html_notes', 'slash_menu_trigger', 'slash_menu_trigger_mobile', 'format_toolbar_mode'];

	public function __construct(
		private WorkspaceService $workspaces,
		private NoteMapper $notes,
		private FolderMapper $folders,
		private AttachmentService $attachments,
		private SettingsService $settings,
		private InputValidator $validator,
		private NotePalette $palette,
	) {
	}

	/**
	 * Workspace-Liste (nur eigene, wie Upstream), Standard-Workspace-Auswahl (alle sichtbaren), Seiten-Einstellungen
	 * @return array{rows: list<array<string, mixed>>, names: list<string>, defaultOptions: list<string>, settings: array<string, string>, palette: list<array<string, string>>, defaultPalette: list<array<string, string>>, localizedNames: array<string, string>, knownNames: array<string, list<string>>}
	 */
	public function build(string $uid): array {
		$keys = $this->workspaces->keys($uid);
		$own = array_values(array_filter($this->workspaces->listVisible($uid), static fn (Workspace $ws) => $ws->getOwnerType() === 'user'
			&& $ws->getOwnerId() === $uid && $ws->getKind() === 'normal'));
		$ids = array_map(static fn (Workspace $ws) => (int)$ws->getId(), $own);
		$noteCounts = $ids === [] ? [] : $this->notes->countByWorkspace($ids);
		$folderCounts = $ids === [] ? [] : $this->folders->countByWorkspace($ids);
		$rows = [];
		foreach ($own as $ws) {
			$id = (int)$ws->getId();
			$color = (string)($ws->getColor() ?? '');
			$rows[] = [
				'name' => $keys[$id] ?? (string)$ws->getName(),
				'tags' => $this->validator->workspaceTags((string)($ws->getTags() ?? '')),
				'color' => $color,
				'colorHex' => $this->palette->hex($color, $uid),
				'notes' => $noteCounts[$id]['active'] ?? 0,
				'folders' => $folderCounts[$id] ?? 0,
			];
		}
		$settings = [];
		foreach (self::PAGE_KEYS as $key) {
			$settings[$key] = $this->settings->read($uid, $key);
		}
		return [
			'rows' => $rows,
			'names' => array_column($rows, 'name'),
			'defaultOptions' => array_values($keys),
			'settings' => $settings,
			'palette' => $this->palette->entries($uid),
			'defaultPalette' => $this->palette->defaults(),
			'localizedNames' => $this->palette->localizedNames(),
			'knownNames' => $this->palette->knownNames(),
		];
	}

	/**
	 * Speicher des Benutzers (Upstream storage-stats-user.php): Notizen und Anhänge der eigenen Workspaces, Freigaben zählen beim Besitzer
	 * @return array{notesActive: int, notesTrash: int, attachmentBytes: int, quotaBytes: int}
	 */
	public function storage(string $uid): array {
		$ids = array_map(static fn (Workspace $ws) => (int)$ws->getId(), array_filter($this->workspaces->listVisible($uid),
			static fn (Workspace $ws) => $ws->getOwnerType() === 'user' && $ws->getOwnerId() === $uid));
		$active = 0;
		$trash = 0;
		foreach ($ids === [] ? [] : $this->notes->countByWorkspace($ids) as $count) {
			$active += $count['active'];
			$trash += $count['trash'];
		}
		$usage = $this->attachments->usage($uid);
		return ['notesActive' => $active, 'notesTrash' => $trash, 'attachmentBytes' => $usage['used'], 'quotaBytes' => $usage['limit']];
	}
}
