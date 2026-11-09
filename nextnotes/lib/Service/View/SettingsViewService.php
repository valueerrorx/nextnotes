<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service\View;

use OCA\NextNotes\Db\AssignmentMapper;
use OCA\NextNotes\Db\FolderMapper;
use OCA\NextNotes\Db\NoteMapper;
use OCA\NextNotes\Db\Workspace;
use OCA\NextNotes\Service\AccessService;
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
		private AccessService $access,
		private AssignmentMapper $assignments,
	) {
	}

	/**
	 * Workspace-Liste (eigene wie Upstream, dazu Team-Workspaces mit Kennzeichen, M5d), Standard-Workspace-Auswahl (alle sichtbaren), Seiten-Einstellungen
	 * @return array{rows: list<array<string, mixed>>, names: list<string>, ownCount: int, defaultOptions: list<string>, settings: array<string, string>, palette: list<array<string, string>>, defaultPalette: list<array<string, string>>, localizedNames: array<string, string>, knownNames: array<string, list<string>>}
	 */
	public function build(string $uid): array {
		$keys = $this->workspaces->keys($uid);
		$listed = array_values(array_filter($this->workspaces->listVisible($uid), static fn (Workspace $ws) => $ws->getKind() === 'normal'
			&& (($ws->getOwnerType() === 'user' && $ws->getOwnerId() === $uid) || $ws->getOwnerType() === 'team')));
		$ids = array_map(static fn (Workspace $ws) => (int)$ws->getId(), $listed);
		$noteCounts = $ids === [] ? [] : $this->notes->countByWorkspace($ids);
		$folderCounts = $ids === [] ? [] : $this->folders->countByWorkspace($ids);
		$own = array_values(array_filter($listed, static fn (Workspace $ws) => $ws->getOwnerType() === 'user'));
		$shares = $this->workspaces->shareStates(array_map(static fn (Workspace $ws) => (int)$ws->getId(), $own));
		// Löschziele je Team: andere Workspaces desselben Teams, kleinste ID zuerst (Standard des Servers, Q2)
		$byTeam = [];
		$teamIds = [];
		foreach ($listed as $ws) {
			if ($ws->getOwnerType() === 'team') {
				$byTeam[(string)$ws->getOwnerId()][(int)$ws->getId()] = $keys[(int)$ws->getId()] ?? (string)$ws->getName();
				$teamIds[] = (int)$ws->getId();
			}
		}
		// Aufgaben je Team-Workspace: Lösch-Dialog nennt sie (ADR 0021)
		$assignmentCounts = $teamIds === [] ? [] : $this->assignments->countByWorkspace($teamIds);
		$rows = [];
		foreach ($listed as $ws) {
			$id = (int)$ws->getId();
			$color = (string)($ws->getColor() ?? '');
			$team = $ws->getOwnerType() === 'team';
			$targets = [];
			if ($team) {
				$targets = $byTeam[(string)$ws->getOwnerId()];
				ksort($targets);
				unset($targets[$id]);
			}
			$rows[] = [
				'name' => $keys[$id] ?? (string)$ws->getName(),
				'tags' => $this->validator->workspaceTags((string)($ws->getTags() ?? '')),
				'color' => $color,
				'colorHex' => $this->palette->hex($color, NotePalette::ownerOf($ws)),
				'notes' => $noteCounts[$id]['active'] ?? 0,
				'folders' => $folderCounts[$id] ?? 0,
				'team' => $team ? $this->workspaces->teamName($uid, $ws) : null,
				'manage' => !$team || $this->access->canManageTeam($uid, $ws),
				'share' => $shares[$id] ?? null,
				'teamTargets' => array_values($targets),
				'teamAssignments' => $assignmentCounts[$id] ?? 0,
			];
		}
		$settings = [];
		foreach (self::PAGE_KEYS as $key) {
			$settings[$key] = $this->settings->read($uid, $key);
		}
		return [
			'rows' => $rows,
			'names' => array_column($rows, 'name'),
			'ownCount' => count($own),
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
