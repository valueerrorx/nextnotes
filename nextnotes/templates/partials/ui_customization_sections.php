<?php
/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// abgeleitet von src/modals/ui_customization_sections.php @ 7e2e0b6aa501ba95ac1e9789fd2bb477c281a001
// Elementsichtbarkeit (Einstellungen): nur Elemente des Ports. Weggelassen: Einstellungskarten gestrichener Funktionen,
// Teilen/öffentliche Links, Offline, Git, Theme-Schalter, Abmelden, andere Konten, Benachrichtigungen (NC-Glocke),
// Kontext-Anpassungspanel (edge*), Backup/Restore-Abschnitte, Erstellen-Karten (Seite entfernt, UI-rail).
/** @var callable(string, ?string=, array=): string $t */
?>
<div class="ui-custom-section" data-ui-pages="settings">
<h4 class="ui-custom-section-title"><span><?php p($t('modals.ui_customization.sections.settings_cards', 'Settings Cards')); ?></span><button type="button" class="ui-custom-toggle-all" data-label-check="<?php p($t('modals.ui_customization.check_all', 'Check all')); ?>" data-label-uncheck="<?php p($t('modals.ui_customization.uncheck_all', 'Uncheck all')); ?>"></button></h4>
<div class="ui-custom-items">
		<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:default-workspace-card" checked><span><?php p($t('workspaces.default.title', 'Default Workspace')); ?></span></label>
		<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:storage-stats-user-card" checked><span><?php p($t('settings.cards.storage_stats_user', 'User Storage statistics')); ?></span></label>
		<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:structured-export-card" checked><span><?php p($t('backup_export.sections.structured_export.title', 'Structured Export')); ?></span></label>
		<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:attachments-export-card" checked><span><?php p($t('backup_export.sections.attachments_export.title', 'Attachments Export')); ?></span></label>
		<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:spellcheck-html-notes-card" checked><span><?php p($t('display.cards.spellcheck_html_notes', 'Spell check')); ?></span></label>
		<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:slash-menu-trigger-card" checked><span><?php p($t('display.cards.slash_menu_trigger', 'Command menu shortcut (computer)')); ?></span></label>
		<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:slash-menu-trigger-mobile-card" checked><span><?php p($t('display.cards.slash_menu_trigger_mobile', 'Command menu shortcut (mobile)')); ?></span></label>
		<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:format-toolbar-mode-card" checked><span><?php p($t('display.cards.format_toolbar_mode', 'Formatting toolbar')); ?></span></label>
		<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:markdown-default-view-mode-card" checked><span><?php p($t('display.cards.markdown_default_view_mode', 'Default view mode')); ?></span></label>
		<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:markdown-new-note-view-mode-card" checked><span><?php p($t('display.cards.markdown_new_note_view_mode', 'New note view mode')); ?></span></label>
		<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:markdown-view-cycle-card" checked><span><?php p($t('display.cards.markdown_view_cycle', 'View menu')); ?></span></label>
		<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:font-size-card" checked><span><?php p($t('display.cards.note_font_size', 'Font size')); ?></span></label>
		<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:index-icon-scale-card" checked><span><?php p($t('display.cards.index_icon_scale', 'Index icon scaling')); ?></span></label>
		<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:date-time-format-card" checked><span><?php p($t('display.cards.date_time_format', 'Date & time format')); ?></span></label>
		<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:note-age-filter-card" checked><span><?php p($t('display.cards.note_age_filter', 'Note age filter')); ?></span></label>
		<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:snapshots-card" checked><span><?php p($t('display.cards.snapshots', 'Revisions')); ?></span></label>
		<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:tasklist-insert-order-card" checked><span><?php p($t('display.cards.tasklist_insert_order', 'Task list insert order')); ?></span></label>
		<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:diary-note-type-card" checked><span><?php p($t('display.cards.diary_default_note_type', 'Diary entry format')); ?></span></label>
		<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:diary-template-card" checked><span><?php p($t('display.cards.diary_template', 'Diary entry template')); ?></span></label>
		<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:diary-date-format-card" checked><span><?php p($t('display.cards.diary_date_format', 'Diary entry date format')); ?></span></label>
		<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:folder-tree-highlight-card" checked><span><?php p($t('display.cards.highlight_current_folder_tree', 'Highlight current folder tree')); ?></span></label>
		<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:note-color-palette-card" checked><span><?php p($t('display.cards.note_color_palette', 'Note colors')); ?></span></label>
		<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:notes-without-folders-card" checked><span><?php p($t('display.cards.notes_without_folders_after', 'Notes without folders')); ?></span></label>
		<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:folder-icon-kanban-card" checked><span><?php p($t('display.cards.folder_icon_opens_kanban', 'Folder icon opens Kanban')); ?></span></label>
		<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:folder-count-parentheses-card" checked><span><?php p($t('display.cards.folder_count_parentheses', 'Folder counts in parentheses')); ?></span></label>
		<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:note-width-card" checked><span><?php p($t('display.cards.note_content_width', 'Note content width')); ?></span></label>
		<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:markdown-split-card-view-card" checked><span><?php p($t('display.cards.markdown_split_card_view', 'Framed markdown')); ?></span></label>
		<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:markdown-split-preview-left-card" checked><span><?php p($t('display.cards.markdown_split_preview_left', 'Preview on the left')); ?></span></label>
		<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:markdown-heading-underline-card" checked><span><?php p($t('display.cards.markdown_heading_underline', 'Heading underline')); ?></span></label>
		<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:markdown-editor-wrap-card" checked><span><?php p($t('display.cards.markdown_editor_word_wrap', 'Editor word wrap')); ?></span></label>
		<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:markdown-colored-card" checked><span><?php p($t('display.cards.markdown_colored', 'Colored markdown')); ?></span></label>
		<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:code-wrap-card" checked><span><?php p($t('display.cards.code_block_word_wrap', 'Code block word wrap')); ?></span></label>
		<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:code-line-numbers-card" checked><span><?php p($t('display.cards.code_block_line_numbers', 'Code block line numbers')); ?></span></label>
		<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:attachment-previews-card" checked><span><?php p($t('display.cards.attachment_previews_in_note', 'Attachment previews')); ?></span></label>
		<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:attachments-at-bottom-card" checked><span><?php p($t('display.cards.attachments_at_bottom', 'Attachments at bottom')); ?></span></label>
		<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:backlinks-at-bottom-card" checked><span><?php p($t('display.cards.backlinks_at_bottom', 'Backlinks at bottom')); ?></span></label>
		<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:note-virtual-space-card" checked><span><?php p($t('display.cards.note_virtual_space', 'Space below the note')); ?></span></label>
		<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:default-image-border-card" checked><span><?php p($t('display.cards.default_image_border_no_padding', 'Default image border (no padding)')); ?></span></label>
		<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:check-updates-card" checked><span><?php p($t('settings.cards.version', 'Version')); ?></span></label>
		<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:nextnotes-website-card" checked><span><?php p($l->t('Next Notes Website')); ?></span></label>
</div>
</div>

<div class="ui-custom-section" data-ui-pages="notes" data-ui-section-id="sidebar">
<h4 class="ui-custom-section-title"><span><?php p($t('modals.ui_customization.sections.sidebar', 'Sidebar')); ?></span><button type="button" class="ui-custom-toggle-all" data-label-check="<?php p($t('modals.ui_customization.check_all', 'Check all')); ?>" data-label-uncheck="<?php p($t('modals.ui_customization.uncheck_all', 'Uncheck all')); ?>"></button></h4>
<p class="ui-custom-section-hint"><?php p($t('modals.ui_customization.sidebar_hint', 'Column of the notes tree, between the icon rail and the note.')); ?></p>
<div class="ui-custom-items">
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:sidebarCreateBtn" checked><span><?php p($t('sidebar.create', 'Create')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:search-bar-container" checked><span><?php p($t('modals.ui_customization.index_search_bar', 'Search bar')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:sidebarSortBtn" checked><span><?php p($t('sort.header', 'Sort by')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:sidebarExpandFoldersBtn" checked><span><?php p($t('sidebar.expand_all_folders', 'Expand all folders')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="panel:favorites-folder" checked><span><?php p($t('notes_list.system_folders.favorites', 'Favorites')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="panel:folder-note-count" checked><span><?php p($t('display.cards.show_folder_counts', 'Show folder counts')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="panel:mini-calendar" checked><span><?php p($t('common.calendar', 'Calendar')); ?></span></label>
</div>
</div>

<div class="ui-custom-section" data-ui-pages="notes dashboard settings speed-dial" data-ui-section-id="icon-rail">
<h4 class="ui-custom-section-title"><span><?php p($t('modals.ui_customization.sections.icon_sidebar', 'Icon rail')); ?></span><button type="button" class="ui-custom-toggle-all" data-label-check="<?php p($t('modals.ui_customization.check_all', 'Check all')); ?>" data-label-uncheck="<?php p($t('modals.ui_customization.uncheck_all', 'Uncheck all')); ?>"></button></h4>
<p class="ui-custom-section-hint"><?php p($t('modals.ui_customization.icon_rail_hint', 'Narrow column of icons on the far left of every page.')); ?></p>
<div class="ui-custom-items">
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:iconSidebarDashboardBtn" checked><span><?php p($t('common.back_to_home', 'Dashboard')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:iconSidebarTasksBtn" checked><span><?php p($t('tasks_page.title', 'Tasks')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:iconSidebarDiaryBtn" checked><span><?php p($t('diary.title', 'Diary')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:iconSidebarGraphBtn" checked><span><?php p($t('home.graph', 'Graph')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:iconSidebarNotesBtn" checked><span><?php p($t('notes_manager.age_all', 'All notes')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:iconSidebarMentionsBtn" checked><span><?php p($t('mentions.title', 'Mentions')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:iconSidebarFoldersBtn" checked><span><?php p($t('home.folders', 'Folders')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:iconSidebarTagsBtn" checked><span><?php p($t('notes_list.system_folders.tags', 'Tags')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:iconSidebarAttachmentsBtn" checked><span><?php p($t('notes_list.system_folders.attachments', 'Attachments')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:iconSidebarTrashBtn" checked><span><?php p($t('notes_list.system_folders.trash', 'Trash')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:iconSidebarAboutBtn" checked><span><?php p($t('settings.categories.documentation', 'About')); ?></span></label>
</div>
</div>

<div class="ui-custom-section" data-ui-pages="notes">
<h4 class="ui-custom-section-title"><span><?php p($t('modals.ui_customization.sections.toolbar', 'Toolbar')); ?></span><button type="button" class="ui-custom-toggle-all" data-label-check="<?php p($t('modals.ui_customization.check_all', 'Check all')); ?>" data-label-uncheck="<?php p($t('modals.ui_customization.uncheck_all', 'Uncheck all')); ?>"></button></h4>
<p class="ui-custom-section-hint"><?php p($t('modals.ui_customization.toolbar_hint', 'Formatting buttons (bold, lists, code…) only appear while text is selected in a note. Other actions are grouped in the ⋮ menu.')); ?></p>
<div class="ui-custom-items">
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="toolbar:btn-bold" checked><span><?php p($t('editor.toolbar.bold', 'Bold')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="toolbar:btn-italic" checked><span><?php p($t('editor.toolbar.italic', 'Italic')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="toolbar:btn-underline" checked><span><?php p($t('editor.toolbar.underline', 'Underline')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="toolbar:btn-strikethrough" checked><span><?php p($t('editor.toolbar.strikethrough', 'Strikethrough')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="toolbar:btn-link" checked><span><?php p($t('editor.toolbar.link', 'Link')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="toolbar:btn-color" checked><span><?php p($t('editor.toolbar.text_color', 'Text color')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="toolbar:btn-highlight" checked><span><?php p($t('editor.toolbar.highlight', 'Highlight')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="toolbar:btn-list-ul" checked><span><?php p($t('editor.toolbar.bullet_list', 'Bullet list')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="toolbar:btn-list-ol" checked><span><?php p($t('editor.toolbar.numbered_list', 'Numbered list')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="toolbar:btn-task-list" checked><span><?php p($t('editor.toolbar.toggle_checklist', 'Toggle checklist')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="toolbar:btn-task-remove" checked><span><?php p($t('editor.toolbar.remove_checklist', 'Remove checkboxes')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="toolbar:btn-text-height" checked><span><?php p($t('slash_menu.title', 'Title')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="toolbar:btn-align" checked><span><?php p($t('slash_menu.align', 'Align')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="toolbar:btn-code" checked><span><?php p($t('editor.toolbar.code_block', 'Code block')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="toolbar:btn-inline-code" checked><span><?php p($t('editor.toolbar.inline_code', 'Inline code')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="toolbar:btn-eraser" checked><span><?php p($t('editor.toolbar.clear_formatting', 'Clear formatting')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="toolbar:btn-search-replace" checked><span><?php p($t('editor.toolbar.search_replace', 'Search and Replace')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="toolbar:btn-checklist" checked><span><?php p($t('editor.toolbar.insert_checklist', 'Checklist')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="toolbar:btn-tasklist-actions" checked><span><?php p($t('tasklist.actions', 'Task list actions')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="toolbar:btn-save" checked><span><?php p($t('editor.toolbar.save_now', 'Save now')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="toolbar:btn-favorite" checked><span><?php p($t('index.toolbar.favorite_add', 'Favorite')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="toolbar:btn-attachment" checked><span><?php p($t('modals.attachment.title', 'Attachments')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="toolbar:btn-reminder" checked><span><?php p($t('modals.ui_customization.reminder_bell', 'Bell icon (reminder)')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="toolbar:btn-open-new-tab" checked><span><?php p($t('editor.toolbar.open_in_new_tab', 'Open in a tab')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="toolbar:btn-duplicate" checked><span><?php p($t('common.duplicate', 'Duplicate')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="toolbar:btn-move" checked><span><?php p($t('common.move', 'Move')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="toolbar:btn-archive" checked><span><?php p($t('archive.menu_item', 'Archive note')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="toolbar:btn-create-linked-note" checked><span><?php p($t('editor.toolbar.create_linked_note', 'Create linked note')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="toolbar:btn-download" checked><span><?php p($t('common.download', 'Download')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="toolbar:btn-print" checked><span><?php p($t('common.print', 'Print')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="toolbar:btn-convert" checked><span><?php p($t('modals.convert.title', 'Convert')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="toolbar:btn-audio" checked><span><?php p($t('slash_menu.audio', 'Audio')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="toolbar:btn-clear-completed" checked><span><?php p($t('tasklist.clear_completed', 'Clear completed tasks')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="toolbar:btn-uncheck-all" checked><span><?php p($t('tasklist.uncheck_all', 'Uncheck all tasks')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="toolbar:btn-open-tasks-page" checked><span><?php p($t('tasklist.open_tasks_page', 'View all tasks')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="toolbar:btn-snapshot" checked><span><?php p($t('snapshot.menu_item', 'Revisions')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="toolbar:btn-trash" checked><span><?php p($t('common.delete', 'Delete')); ?></span></label>
</div>
</div>

<div class="ui-custom-section" data-ui-pages="notes">
<h4 class="ui-custom-section-title"><span><?php p($t('modals.ui_customization.sections.slash_menu', 'Slash Menu')); ?></span><button type="button" class="ui-custom-toggle-all" data-label-check="<?php p($t('modals.ui_customization.check_all', 'Check all')); ?>" data-label-uncheck="<?php p($t('modals.ui_customization.uncheck_all', 'Uncheck all')); ?>"></button></h4>
<div class="ui-custom-items">
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="slash:normal" checked><span><?php p($t('slash_menu.back_to_normal', 'Back to normal text')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="slash:title" checked><span><?php p($t('slash_menu.title', 'Title')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="slash:format" checked><span><?php p($t('slash_menu.format_text', 'Format text')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="slash:align" checked><span><?php p($t('slash_menu.align', 'Align')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="slash:color" checked><span><?php p($t('slash_menu.color', 'Color')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="slash:highlight" checked><span><?php p($t('slash_menu.highlight', 'Highlight')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="slash:code" checked><span><?php p($t('slash_menu.code', 'Code')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="slash:list" checked><span><?php p($t('slash_menu.list', 'List')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="slash:tasklist-embed" checked><span><?php p($t('slash_menu.tasklist_embed', 'Task list')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="slash:quote" checked><span><?php p($t('slash_menu.quote', 'Quote')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="slash:media" checked><span><?php p($t('slash_menu.media', 'Media')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="slash:toggle" checked><span><?php p($t('slash_menu.toggle', 'Toggle')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="slash:emoji" checked><span><?php p($t('slash_menu.emoji', 'Emoji')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="slash:date" checked><span><?php p($t('slash_menu.date_section', 'Calendar and time')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="slash:now" checked><span><?php p($t('slash_menu.date_section', 'Calendar and time') . ' › ' . $t('slash_menu.now', 'Now')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="slash:relative-date" checked><span><?php p($t('slash_menu.date_section', 'Calendar and time') . ' › ' . $t('slash_menu.relative_date', 'Relative')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="slash:weekday" checked><span><?php p($t('slash_menu.date_section', 'Calendar and time') . ' › ' . $t('slash_menu.weekday', 'Day of the week')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="slash:insert-date" checked><span><?php p($t('slash_menu.date_section', 'Calendar and time') . ' › ' . $t('slash_menu.date', 'Date')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="slash:insert-time" checked><span><?php p($t('slash_menu.date_section', 'Calendar and time') . ' › ' . $t('slash_menu.time', 'Time')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="slash:insert-datetime" checked><span><?php p($t('slash_menu.date_section', 'Calendar and time') . ' › ' . $t('slash_menu.date_time', 'Date and time')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="slash:journal-link" checked><span><?php p($t('slash_menu.date_section', 'Calendar and time') . ' › ' . $t('slash_menu.journal_link', 'Link to diary entry')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="slash:excalidraw" checked><span><?php p($t('slash_menu.excalidraw', 'Excalidraw')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="slash:mermaid" checked><span><?php p($t('slash_menu.mermaid', 'Mermaid')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="slash:table" checked><span><?php p($t('slash_menu.table', 'Table')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="slash:separator" checked><span><?php p($t('slash_menu.separator', 'Separator')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="slash:paste-markdown" checked><span><?php p($t('modals.paste_markdown.menu_item', 'Insert rich text')); ?> / <?php p($t('modals.insert_markdown.menu_item', 'Insert Markdown')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="slash:note-reference" checked><span><?php p($t('slash_menu.link_to_note', 'Link to note')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="slash:template" checked><span><?php p($t('slash_menu.template', 'Template')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="slash:link-to-attachment" checked><span><?php p($t('slash_menu.link_to_attachment', 'Link to attachment')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="slash:link" checked><span><?php p($t('slash_menu.link', 'Link')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="slash:image" checked><span><?php p($t('slash_menu.image', 'Image')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="slash:take-photo" checked><span><?php p($t('slash_menu.take_photo', 'Take a photo')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="slash:audio-file" checked><span><?php p($t('slash_menu.audio', 'Audio')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="slash:cancel" checked><span><?php p($t('slash_menu.cancel', 'Cancel')); ?></span></label>
</div>
</div>

<div class="ui-custom-section" data-ui-pages="notes" data-ui-section-id="mobile-editor-bar" data-ui-viewport="mobile">
<h4 class="ui-custom-section-title"><span><?php p($t('modals.ui_customization.sections.mobile_editor_bar', 'Mobile editor bar')); ?></span><button type="button" class="ui-custom-toggle-all" data-label-check="<?php p($t('modals.ui_customization.check_all', 'Check all')); ?>" data-label-uncheck="<?php p($t('modals.ui_customization.uncheck_all', 'Uncheck all')); ?>"></button></h4>
<p class="ui-custom-section-hint"><?php p($t('modals.ui_customization.mobile_editor_bar_hint', 'Bar shown above the on-screen keyboard while a note is being edited on a phone or tablet.')); ?></p>
<div class="ui-custom-items">
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:mobileEditorBar" checked><span><?php p($t('modals.ui_customization.mobile_editor_bar_show', 'Show the bar')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:mobileBarInsert" checked><span><?php p($t('mobile_editor_bar.insert', 'Insert (slash menu)')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:mobileBarRecordAudio" checked><span><?php p($t('slash_menu.record_audio', 'Record audio')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:mobileBarUndo" checked><span><?php p($t('mobile_editor_bar.undo', 'Undo')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:mobileBarRedo" checked><span><?php p($t('mobile_editor_bar.redo', 'Redo')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:mobileBarBold" checked><span><?php p($t('editor.toolbar.bold', 'Bold')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:mobileBarItalic" checked><span><?php p($t('editor.toolbar.italic', 'Italic')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:mobileBarUnderline" checked><span><?php p($t('editor.toolbar.underline', 'Underline')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:mobileBarStrikethrough" checked><span><?php p($t('editor.toolbar.strikethrough', 'Strikethrough')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:mobileBarLink" checked><span><?php p($t('editor.toolbar.link', 'Link')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:mobileBarColor" checked><span><?php p($t('editor.toolbar.text_color', 'Text color')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:mobileBarHighlight" checked><span><?php p($t('editor.toolbar.highlight', 'Highlight')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:mobileBarTitle" checked><span><?php p($t('slash_menu.title', 'Title')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:mobileBarAlign" checked><span><?php p($t('slash_menu.align', 'Align')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:mobileBarListUl" checked><span><?php p($t('editor.toolbar.bullet_list', 'Bullet list')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:mobileBarListOl" checked><span><?php p($t('editor.toolbar.numbered_list', 'Numbered list')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:mobileBarChecklist" checked><span><?php p($t('editor.toolbar.toggle_checklist', 'Toggle checklist')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:mobileBarOutdent" checked><span><?php p($t('mobile_editor_bar.outdent', 'Outdent')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:mobileBarIndent" checked><span><?php p($t('mobile_editor_bar.indent', 'Indent')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:mobileBarCodeBlock" checked><span><?php p($t('editor.toolbar.code_block', 'Code block')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:mobileBarInlineCode" checked><span><?php p($t('editor.toolbar.inline_code', 'Inline code')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:mobileBarClearFormat" checked><span><?php p($t('editor.toolbar.clear_formatting', 'Clear formatting')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:mobileBarSearchReplace" checked><span><?php p($t('editor.toolbar.search_replace', 'Search and replace')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:mobileBarHideKeyboard" checked><span><?php p($t('mobile_editor_bar.hide_keyboard', 'Hide keyboard')); ?></span></label>
</div>
</div>

<div class="ui-custom-section" data-ui-pages="notes">
<h4 class="ui-custom-section-title"><span><?php p($t('modals.ui_customization.sections.panels', 'Other')); ?></span><button type="button" class="ui-custom-toggle-all" data-label-check="<?php p($t('modals.ui_customization.check_all', 'Check all')); ?>" data-label-uncheck="<?php p($t('modals.ui_customization.uncheck_all', 'Uncheck all')); ?>"></button></h4>
<div class="ui-custom-items">
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="panel:outline-panel" checked><span><?php p($t('common.outline.title', 'Outline')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="panel:note-scrollbar" checked><span><?php p($t('modals.ui_customization.note_scrollbar', 'Note scrollbar')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="panel:note-scroll-arrows" checked><span><?php p($t('modals.ui_customization.note_scroll_arrows', 'Scroll to top and bottom arrows')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="panel:tasklist-progress" checked><span><?php p($t('modals.ui_customization.tasklist_progress_bar', 'Task list progress bar')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="panel:preview-code-block-delete" checked><span><?php p($t('modals.ui_customization.preview_code_block_delete', 'Delete button on code blocks, in markdown preview')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="panel:note-created-date" checked><span><?php p($t('display.cards.show_note_created', 'Show creation date')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="panel:note-updated-date" checked><span><?php p($t('modals.ui_customization.note_updated_date', 'Show modification date')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="panel:note-stats-characters" checked><span><?php p($t('modals.ui_customization.note_stats_characters', 'Show character count')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="panel:note-stats-words" checked><span><?php p($t('modals.ui_customization.note_stats_words', 'Show word count')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="panel:note-stats-lines" checked><span><?php p($t('modals.ui_customization.note_stats_lines', 'Show line count')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="panel:note-info-icon" checked><span><?php p($t('modals.ui_customization.note_info_icon', 'Show information icon under the note title')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="panel:note-icons" checked><span><?php p($t('display.cards.show_note_icons', 'Show note icons')); ?></span></label>
</div>
</div>

<div class="ui-custom-section" data-ui-pages="notes">
<h4 class="ui-custom-section-title"><span><?php p($t('modals.ui_customization.sections.workspace_menu', 'Workspace Menu')); ?></span><button type="button" class="ui-custom-toggle-all" data-label-check="<?php p($t('modals.ui_customization.check_all', 'Check all')); ?>" data-label-uncheck="<?php p($t('modals.ui_customization.uncheck_all', 'Uncheck all')); ?>"></button></h4>
<div class="ui-custom-items">
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="wsmenu:new-workspace" checked><span><?php p($t('workspaces.menu.new_workspace', 'New workspace')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="wsmenu:edit-workspaces" checked><span><?php p($t('workspaces.menu.edit_workspaces', 'Edit workspaces')); ?></span></label>
</div>
</div>


<div class="ui-custom-section" data-ui-pages="dashboard">
<h4 class="ui-custom-section-title"><span><?php p($t('modals.ui_customization.sections.dashboard_toolbar', 'Dashboard')); ?></span><button type="button" class="ui-custom-toggle-all" data-label-check="<?php p($t('modals.ui_customization.check_all', 'Check all')); ?>" data-label-uncheck="<?php p($t('modals.ui_customization.uncheck_all', 'Uncheck all')); ?>"></button></h4>
<div class="ui-custom-items">
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:dashboardTopbarFilter" checked><span><?php p($t('modals.ui_customization.dashboard_filter_bar', 'Filter bar')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:dashboardViewLayoutBtn" checked><span><?php p($t('modals.ui_customization.view_layout_toggle', 'View toggle (grid / list, card size)')); ?></span></label>
	<label class="ui-custom-item"><input type="checkbox" data-ui-key="card:dashboardFilterBtn" checked><span><?php p($t('dashboard.filters.button', 'Filters')); ?></span></label>
</div>
</div>

<div class="ui-custom-section" data-ui-pages="notes">
<h4 class="ui-custom-section-title"><span><?php p($t('modals.ui_customization.sections.folder_actions', 'Folder Actions')); ?></span><button type="button" class="ui-custom-toggle-all" data-label-check="<?php p($t('modals.ui_customization.check_all', 'Check all')); ?>" data-label-uncheck="<?php p($t('modals.ui_customization.uncheck_all', 'Uncheck all')); ?>"></button></h4>
<div class="ui-custom-items">
		<label class="ui-custom-item"><input type="checkbox" data-ui-key="panel:folder-actions-toggle" checked><span><?php p($t('modals.ui_customization.folder_actions_toggle', 'Menu button (⋮) on folders')); ?></span></label>
		<label class="ui-custom-item"><input type="checkbox" data-ui-key="folder:create-note-in-folder" checked><span><?php p($t('notes_list.folder_actions.create', 'Create here')); ?></span></label>
		<label class="ui-custom-item"><input type="checkbox" data-ui-key="folder:open-kanban-view" checked><span><?php p($t('notes_list.folder_actions.kanban_view', 'Kanban view')); ?></span></label>
		<label class="ui-custom-item"><input type="checkbox" data-ui-key="folder:open-all-notes-in-tabs" checked><span><?php p($t('notes_list.folder_actions.open_all_in_tabs', 'Open all notes in tabs')); ?></span></label>
		<label class="ui-custom-item"><input type="checkbox" data-ui-key="folder:move-folder-files" checked><span><?php p($t('notes_list.folder_actions.move_all_files', 'Move all files')); ?></span></label>
		<label class="ui-custom-item"><input type="checkbox" data-ui-key="folder:move-entire-folder" checked><span><?php p($t('notes_list.folder_actions.move_folder', 'Move to subfolder')); ?></span></label>
		<label class="ui-custom-item"><input type="checkbox" data-ui-key="folder:duplicate-folder" checked><span><?php p($t('notes_list.folder_actions.duplicate_folder', 'Duplicate folder')); ?></span></label>
		<label class="ui-custom-item"><input type="checkbox" data-ui-key="folder:copy-folder" checked><span><?php p($t('notes_list.folder_actions.copy_folder', 'Copy')); ?></span></label>
		<label class="ui-custom-item"><input type="checkbox" data-ui-key="folder:cut-folder" checked><span><?php p($t('notes_list.folder_actions.cut_folder', 'Cut')); ?></span></label>
		<label class="ui-custom-item"><input type="checkbox" data-ui-key="folder:paste-into-folder" checked><span><?php p($t('notes_list.folder_actions.paste_into_folder', 'Paste into folder')); ?></span></label>
		<label class="ui-custom-item"><input type="checkbox" data-ui-key="folder:download-folder" checked><span><?php p($t('notes_list.folder_actions.download_folder', 'Download folder')); ?></span></label>
		<label class="ui-custom-item"><input type="checkbox" data-ui-key="folder:rename-folder" checked><span><?php p($t('notes_list.folder_actions.rename_folder', 'Rename')); ?></span></label>
		<label class="ui-custom-item"><input type="checkbox" data-ui-key="folder:archive-folder" checked><span><?php p($t('archive.folder_menu_item', 'Archive folder')); ?></span></label>
		<label class="ui-custom-item"><input type="checkbox" data-ui-key="folder:delete-folder" checked><span><?php p($t('notes_list.folder_actions.delete_folder', 'Delete')); ?></span></label>
</div>
</div>

<div class="ui-custom-section" data-ui-pages="notes">
<h4 class="ui-custom-section-title"><span><?php p($t('modals.ui_customization.sections.note_actions', 'Note Actions')); ?></span><button type="button" class="ui-custom-toggle-all" data-label-check="<?php p($t('modals.ui_customization.check_all', 'Check all')); ?>" data-label-uncheck="<?php p($t('modals.ui_customization.uncheck_all', 'Uncheck all')); ?>"></button></h4>
<p class="ui-custom-section-hint"><?php p($t('modals.ui_customization.note_actions_hint', 'Items of the ⋮ menu on each note in the sidebar. Unchecking them all hides the menu button.')); ?></p>
<div class="ui-custom-items">
		<label class="ui-custom-item"><input type="checkbox" data-ui-key="panel:note-actions-toggle" checked><span><?php p($t('modals.ui_customization.note_actions_toggle', 'Menu button (⋮) on notes')); ?></span></label>
		<label class="ui-custom-item"><input type="checkbox" data-ui-key="note:rename-note" checked><span><?php p($t('notes_list.note_actions.rename_note', 'Rename note')); ?></span></label>
		<label class="ui-custom-item"><input type="checkbox" data-ui-key="note:toggle-favorite" checked><span><?php p($t('notes_list.folder_actions.add_favorite', 'Add to favorites')); ?></span></label>
		<label class="ui-custom-item"><input type="checkbox" data-ui-key="note:duplicate-note" checked><span><?php p($t('common.duplicate', 'Duplicate')); ?></span></label>
		<label class="ui-custom-item"><input type="checkbox" data-ui-key="note:create-note-shortcut" checked><span><?php p($t('editor.toolbar.create_linked_note', 'Create shortcut')); ?></span></label>
		<label class="ui-custom-item"><input type="checkbox" data-ui-key="note:show-move-folder-dialog" checked><span><?php p($t('notes_list.note_actions.move_note', 'Move note')); ?></span></label>
		<label class="ui-custom-item"><input type="checkbox" data-ui-key="note:copy-note" checked><span><?php p($t('notes_list.note_actions.copy_note', 'Copy')); ?></span></label>
		<label class="ui-custom-item"><input type="checkbox" data-ui-key="note:cut-note" checked><span><?php p($t('notes_list.note_actions.cut_note', 'Cut')); ?></span></label>
		<label class="ui-custom-item"><input type="checkbox" data-ui-key="note:paste-into-note-folder" checked><span><?php p($t('notes_list.note_actions.paste_note', 'Paste here')); ?></span></label>
		<label class="ui-custom-item"><input type="checkbox" data-ui-key="note:archive-note" checked><span><?php p($t('archive.menu_item', 'Archive note')); ?></span></label>
		<label class="ui-custom-item"><input type="checkbox" data-ui-key="note:delete-note" checked><span><?php p($t('notes_list.note_actions.delete_note', 'Delete note')); ?></span></label>
</div>
</div>
