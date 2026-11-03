<?php
/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// abgeleitet von src/folders_display.php @ 7e2e0b6aa501ba95ac1e9789fd2bb477c281a001
// Nur Markup (generateFolderActions, renderFolderActionsMenu, generateNoteActions, renderNoteActionsMenu); Baumdaten liefert IndexViewService.
// Weggelassen: Ordner herunterladen (Export, M4), Teilen/öffentliche Links und Offline (gestrichen).
/** @var array $_ */
/** @var \OCA\NextNotes\Service\View\PageTranslator $tr */

// Drei-Punkte-Schalter je Ordner (generateFolderActions)
$renderFolderActions = static function (array $folder) use ($tr): void {
	?><div class="folder-actions-toggle" data-action="toggle-folder-actions-menu" data-folder-id="<?php p((string)$folder['id']); ?>" data-folder-name="<?php p($folder['name']); ?>" data-note-count="<?php p((string)$folder['count']); ?>" data-shared="0" data-favorite="<?php p($folder['favorite'] ? '1' : '0'); ?>" data-offline="0" title="<?php p($tr->t('notes_list.folder_actions.menu', [], 'Folder actions')); ?>"><i class="lucide lucide-more-vertical"></i></div><?php
};

// Drei-Punkte-Schalter je Notiz (generateNoteActions)
$renderNoteActions = static function (array $note) use ($tr): void {
	$label = $tr->t('notes_list.note_actions.menu', [], 'Note actions');
	?><div class="note-actions"><button type="button" class="note-actions-toggle" data-action="toggle-note-actions-menu" data-note-id="<?php p((string)$note['id']); ?>" data-note-title="<?php p($note['title']); ?>" data-note-type="<?php p($note['type']); ?>" data-folder-id="<?php p((string)$note['folderId']); ?>" data-folder="<?php p($note['folderName']); ?>" data-favorite="<?php p($note['favorite'] ? '1' : '0'); ?>" data-offline="0" data-shared="0" data-speed-dial="<?php p($note['speedDial'] ? '1' : '0'); ?>" title="<?php p($label); ?>" aria-label="<?php p($label); ?>"><i class="lucide lucide-more-vertical"></i></button></div><?php
};

// Ein Menüeintrag; $extra: zusätzliche feste Klassen
$menuItem = static function (string $base, string $action, string $icon, string $label, string $extra = '', string $shortcut = ''): void {
	?><div class="<?php p(trim($base . ' ' . $extra)); ?>" data-action="<?php p($action); ?>"><i class="lucide <?php p($icon); ?>"></i><span><?php p($label); ?></span><?php if ($shortcut !== ''): ?><span class="actions-menu-shortcut"><?php p($shortcut); ?></span><?php endif; ?></div><?php
};

// Gemeinsames Ordner-Menü (renderFolderActionsMenu)
$renderFolderActionsMenu = static function (string $workspace, bool $iconOpensKanban) use ($tr, $menuItem): void {
	$i = 'folder-actions-menu-item';
	$sep = static function (): void {
		?><div class="folder-actions-menu-separator"></div><?php
	};
	?><div class="folder-actions-menu" id="folder-actions-menu"><?php
	$menuItem($i, 'create-note-in-folder', 'lucide-plus-circle', $tr->t('notes_list.folder_actions.create', [], 'Create here'));
	$menuItem($i, 'open-kanban-view', 'lucide-columns-2', $tr->t('notes_list.folder_actions.kanban_view', [], 'Kanban view'), 'kanban-view-action');
	$menuItem($i, 'open-all-notes-in-tabs', 'lucide-layers', $tr->t('notes_list.folder_actions.open_all_in_tabs', [], 'Open all notes'), 'open-all-tabs-action requires-notes');
	$menuItem($i, 'show-only-folder', 'lucide-filter', $tr->t('notes_list.folder_actions.show_only', [], 'Show only this folder'));
	$sep();
	$menuItem($i, 'move-folder-files', 'lucide-folder-open', $tr->t('notes_list.folder_actions.move_all_files', [], 'Move all files'), 'requires-notes');
	$menuItem($i, 'move-entire-folder', 'lucide-folder-output', $tr->t('notes_list.folder_actions.move_folder', [], 'Move to subfolder'));
	$menuItem($i, 'duplicate-folder', 'lucide-copy', $tr->t('notes_list.folder_actions.duplicate_folder', [], 'Duplicate folder'));
	$menuItem($i, 'copy-folder', 'lucide-clipboard-copy', $tr->t('notes_list.folder_actions.copy_folder', [], 'Copy'), '', 'Ctrl+C');
	$menuItem($i, 'cut-folder', 'lucide-scissors', $tr->t('notes_list.folder_actions.cut_folder', [], 'Cut'), '', 'Ctrl+X');
	$menuItem($i, 'paste-into-folder', 'lucide-clipboard-paste', $tr->t('notes_list.folder_actions.paste_into_folder', [], 'Paste into folder'), '', 'Ctrl+V');
	$sep();
	$menuItem($i, 'tag-folder-notes', 'lucide-tag', $tr->t('modals.tag_folder_notes.title', [], 'Tag all notes'), 'requires-notes');
	$menuItem($i, 'toggle-folder-speed-dial', 'lucide-zap', $tr->t('speed_dial.add', [], 'Add to speed dial'), 'requires-notes speed-dial-state-off');
	$menuItem($i, 'toggle-folder-speed-dial', 'lucide-zap', $tr->t('speed_dial.remove', [], 'Remove from speed dial'), 'requires-notes speed-dial-state-on active-state');
	$sep();
	$menuItem($i, 'favorite-folder', 'lucide-star', $tr->t('notes_list.folder_actions.remove_favorite', [], 'Remove from favorites'), 'favorite-state-favorite active-state');
	$menuItem($i, 'favorite-folder', 'lucide-star', $tr->t('notes_list.folder_actions.add_favorite', [], 'Add to favorites'), 'favorite-state-not-favorite');
	if ($iconOpensKanban) {
		$menuItem($i, 'change-folder-icon', 'lucide-palette', $tr->t('notes_list.folder_actions.change_icon', [], 'Change icon'));
	}
	$menuItem($i, 'rename-folder', 'lucide-pencil', $tr->t('notes_list.folder_actions.rename_folder', [], 'Rename'));
	$sep();
	if ($workspace !== \OCA\NextNotes\Service\WorkspaceService::ARCHIVE_NAME) {
		$menuItem($i, 'archive-folder', 'lucide-archive', $tr->t('archive.folder_menu_item', [], 'Archive folder'));
	}
	$menuItem($i, 'delete-folder', 'lucide-trash-2', $tr->t('notes_list.folder_actions.delete_folder', [], 'Delete'), 'danger');
	?></div><?php
};

// Gemeinsames Notiz-Menü (renderNoteActionsMenu)
$renderNoteActionsMenu = static function (string $workspace) use ($tr, $menuItem): void {
	$i = 'note-actions-menu-item';
	?><div class="note-actions-menu" id="note-actions-menu"><?php
	$menuItem($i, 'open-note-new-tab', 'lucide-external-link', $tr->t('notes_list.note_actions.open_in_new_tab', [], 'Open in a tab'));
	$menuItem($i, 'close-note-tab', 'lucide-x', $tr->t('notes_list.note_actions.close_tab', [], 'Close this tab'));
	$menuItem($i, 'rename-note', 'lucide-pencil', $tr->t('notes_list.note_actions.rename_note', [], 'Rename note'));
	$menuItem($i, 'toggle-favorite', 'lucide-star', $tr->t('notes_list.folder_actions.remove_favorite', [], 'Remove from favorites'), 'favorite-state-favorite active-state');
	$menuItem($i, 'toggle-favorite', 'lucide-star', $tr->t('notes_list.folder_actions.add_favorite', [], 'Add to favorites'), 'favorite-state-not-favorite');
	$menuItem($i, 'toggle-speed-dial', 'lucide-zap', $tr->t('speed_dial.remove', [], 'Remove from speed dial'), 'note-real-only speed-dial-state-on active-state');
	$menuItem($i, 'toggle-speed-dial', 'lucide-zap', $tr->t('speed_dial.add', [], 'Add to speed dial'), 'note-real-only speed-dial-state-off');
	?><div class="note-actions-menu-separator"></div><?php
	$menuItem($i, 'duplicate-note', 'lucide-copy', $tr->t('common.duplicate', [], 'Duplicate'), 'note-real-only');
	$menuItem($i, 'create-note-shortcut', 'lucide-link', $tr->t('editor.toolbar.create_linked_note', [], 'Create shortcut'), 'note-real-only');
	$menuItem($i, 'show-move-folder-dialog', 'lucide-folder-output', $tr->t('notes_list.note_actions.move_note', [], 'Move note'));
	$menuItem($i, 'copy-note', 'lucide-clipboard-copy', $tr->t('notes_list.note_actions.copy_note', [], 'Copy'), 'note-real-only', 'Ctrl+C');
	$menuItem($i, 'cut-note', 'lucide-scissors', $tr->t('notes_list.note_actions.cut_note', [], 'Cut'), '', 'Ctrl+X');
	$menuItem($i, 'paste-into-note-folder', 'lucide-clipboard-paste', $tr->t('notes_list.note_actions.paste_note', [], 'Paste here'), '', 'Ctrl+V');
	?><div class="note-actions-menu-separator"></div><?php
	if ($workspace !== \OCA\NextNotes\Service\WorkspaceService::ARCHIVE_NAME) {
		$menuItem($i, 'archive-note', 'lucide-archive', $tr->t('archive.menu_item', [], 'Archive note'));
	}
	$menuItem($i, 'delete-note', 'lucide-trash-2', $tr->t('notes_list.note_actions.delete_note', [], 'Delete note'), 'danger');
	?></div><?php
};
