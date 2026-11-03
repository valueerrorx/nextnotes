<?php
/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// abgeleitet von src/modals.php @ 7e2e0b6aa501ba95ac1e9789fd2bb477c281a001
// (dazu src/task_list_picker_modal.php, src/task_due_modal.php, src/modals/{folder_icon_modal,icon_color_options,tag_folder_notes_modal}.php)
// Nur Dialoge der Hauptansicht und des Editors. Weggelassen: Update/What's new, eigenes CSS/Schriften, Admin-Limits,
// Konten/Passwort, Workspace-Einstellungen und Workspace anlegen (M4), Export (M4), Umwandeln (501 bis M4),
// Anhang-Auswahl (M3b2), Offline, Spracheingabe, Tagebuch (M4), Hintergrundbild,
// Farbpalette/Markdown-Farben/Revisionen/Datumsformat (Einstellungen M4), NC-Glocke statt Benachrichtigungsdialog,
// E-Mail-Erinnerung (nur NC-Benachrichtigung).
/** @var \OCA\NextNotes\Service\View\PageTranslator $tr */
// Titel mit Untertitel in Klammern (Upstream modals.php:770-780)
$createTitle = static function (string $title): void {
	$pos = strpos($title, ' (');
	if ($pos === false) {
		p($title);
		return;
	}
	p(substr($title, 0, $pos));
	?><span class="create-note-subtitle"><?php p(substr($title, $pos)); ?></span><?php
};
?>
<!-- Notification popup -->
<div id="notificationOverlay" class="notification-overlay"></div>
<div id="poznoteToast"></div>

<!-- Reminder Modal -->
<div
    id="reminderModal"
    class="modal"
    data-reminder-email-available="0"
>
    <div class="modal-content">
        <h3><?php p($tr->t('reminder.modal.title', [], 'Reminder')); ?></h3>
        <div class="reminder-form">
            <div class="reminder-input-row">
                <input type="datetime-local" id="reminderDateInput" class="reminder-datetime-input" />
                <button type="button" id="reminderDateBtn" class="reminder-datetime-part initially-hidden">
                    <i class="lucide lucide-calendar-alt"></i>
                    <span id="reminderDateBtnLabel"><?php p($tr->t('reminder.modal.date_placeholder', [], 'Date')); ?></span>
                </button>
                <button type="button" id="reminderTimeBtn" class="reminder-datetime-part initially-hidden">
                    <i class="lucide lucide-clock"></i>
                    <span id="reminderTimeBtnLabel"><?php p($tr->t('reminder.modal.time_placeholder', [], 'Time')); ?></span>
                </button>
            </div>
            <div class="reminder-repeat-row">
                <label class="reminder-repeat-label" for="reminderRepeatSelect">
                    <i class="lucide lucide-repeat"></i>
                    <?php p($tr->t('reminder.modal.repeat_label', [], 'Repeat')); ?>
                </label>
                <select id="reminderRepeatSelect" class="reminder-repeat-select">
                    <option value=""><?php p($tr->t('reminder.modal.repeat_none', [], 'Does not repeat')); ?></option>
                    <option value="1h"><?php p($tr->t('reminder.modal.repeat_hourly', [], 'Hourly')); ?></option>
                    <option value="1d"><?php p($tr->t('reminder.modal.repeat_daily', [], 'Daily')); ?></option>
                    <option value="1w"><?php p($tr->t('reminder.modal.repeat_weekly', [], 'Weekly')); ?></option>
                    <option value="1m"><?php p($tr->t('reminder.modal.repeat_monthly', [], 'Monthly')); ?></option>
                    <option value="1y"><?php p($tr->t('reminder.modal.repeat_yearly', [], 'Yearly')); ?></option>
                    <option value="custom"><?php p($tr->t('reminder.modal.repeat_custom', [], 'Custom...')); ?></option>
                </select>
            </div>
            <div class="reminder-repeat-custom initially-hidden" id="reminderRepeatCustom">
                <span class="reminder-repeat-every"><?php p($tr->t('reminder.modal.repeat_every', [], 'Every')); ?></span>
                <input type="number" id="reminderRepeatInterval" class="reminder-repeat-interval" min="1" max="999" value="1">
                <select id="reminderRepeatUnit" class="reminder-repeat-unit">
                    <option value="i"><?php p($tr->t('reminder.modal.repeat_minutes', [], 'minute(s)')); ?></option>
                    <option value="h"><?php p($tr->t('reminder.modal.repeat_hours', [], 'hour(s)')); ?></option>
                    <option value="d"><?php p($tr->t('reminder.modal.repeat_days', [], 'day(s)')); ?></option>
                    <option value="w"><?php p($tr->t('reminder.modal.repeat_weeks', [], 'week(s)')); ?></option>
                    <option value="m"><?php p($tr->t('reminder.modal.repeat_months', [], 'month(s)')); ?></option>
                    <option value="y"><?php p($tr->t('reminder.modal.repeat_years', [], 'year(s)')); ?></option>
                </select>
            </div>
            <div class="reminder-repeat-hint initially-hidden" id="reminderRepeatHint">
                <?php p($tr->t('reminder.modal.repeat_hint', [], 'The next reminder is scheduled when you dismiss the notification.')); ?>
            </div>
            <div class="reminder-email-option initially-hidden" id="reminderEmailOption">
                <label class="reminder-email-label" for="reminderEmailInput">
                    <span class="reminder-email-copy">
                        <span class="reminder-email-title">
                            <i class="lucide lucide-mail"></i>
                            <?php p($tr->t('reminder.modal.email_toggle', [], 'Send me an email')); ?>
                        </span>
                        <span class="reminder-email-hint">
                            
                        </span>
                    </span>
                    <span class="toggle-switch reminder-email-switch">
                        <input type="checkbox" id="reminderEmailInput">
                        <span class="toggle-slider"></span>
                    </span>
                </label>
            </div>
            <div class="reminder-current-info initially-hidden" id="reminderCurrentInfo">
                <i class="lucide lucide-bell"></i>
                <span id="reminderCurrentDate"></span>
            </div>
        </div>
        <div class="modal-buttons reminder-modal-actions">
            <button type="button" class="btn-danger initially-hidden reminder-modal-action" id="reminderRemoveBtn" data-action="remove-reminder"><?php p($tr->t('common.delete', [], 'Delete')); ?></button>
            <button type="button" class="btn-cancel reminder-modal-action" data-action="close-reminder-modal"><?php p($tr->t('common.cancel')); ?></button>
            <button type="button" class="btn-primary reminder-modal-action" id="reminderSaveBtn" data-action="save-reminder"><?php p($tr->t('common.save')); ?></button>
        </div>
    </div>
</div>

<!-- Font Size Settings Modal -->
<div id="fontSizeModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2><?php p($tr->t('modals.font_size.title')); ?></h2>
        </div>
        <div class="modal-body">
            <div class="font-size-controls">
                <div class="font-size-row">
                    <label for="fontSizeInput"><?php p($tr->t('modals.font_size.note_label')); ?></label>
                    <input type="number" id="fontSizeInput" min="10" max="32" step="1" value="15">
                </div>
                <div class="font-size-row">
                    <label for="sidebarFontSizeInput"><?php p($tr->t('modals.font_size.sidebar_label')); ?></label>
                    <input type="number" id="sidebarFontSizeInput" min="10" max="32" step="1" value="13">
                </div>
                <div class="font-size-row">
                    <label for="codeBlockFontSizeInput"><?php p($tr->t('modals.font_size.code_block_label')); ?></label>
                    <input type="number" id="codeBlockFontSizeInput" min="10" max="32" step="1" value="15">
                </div>
                <div class="font-size-row">
                    <label for="settingsFontSizeInput"><?php p($tr->t('modals.font_size.settings_label', [], 'Settings page (px):')); ?></label>
                    <input type="number" id="settingsFontSizeInput" min="10" max="32" step="1" value="15">
                </div>
            </div>
        </div>
        <div class="modal-buttons">
            <button type="button" id="cancelFontSizeBtn" class="btn-cancel"><?php p($tr->t('common.cancel')); ?></button>
            <button type="button" id="saveFontSizeBtn" class="btn-primary"><?php p($tr->t('common.save')); ?></button>
        </div>
    </div>
</div>


<!-- Confirmation Modal -->
<div id="confirmModal" class="modal">
    <div class="modal-content">
        <h3 id="confirmTitle"><?php p($tr->t('modals.confirm.title')); ?></h3>
        <p id="confirmMessage"><?php p($tr->t('modals.confirm.message')); ?></p>
        <div class="modal-buttons">
            <button type="button" class="btn-cancel" data-action="close-confirm-modal"><?php p($tr->t('common.cancel')); ?></button>
            <button type="button" class="btn-secondary initially-hidden" id="saveAndExitButton" data-action="execute-save-and-exit"><?php p($tr->t('modals.confirm.save_and_exit')); ?></button>
            <button type="button" class="btn-primary" id="confirmButton" data-action="execute-confirmed-action"><?php p($tr->t('modals.confirm.exit_without_saving')); ?></button>
        </div>
    </div>
</div>

<!-- Modal for creating new folder -->
<div id="newFolderModal" class="modal">
    <div class="modal-content">
        <h3><?php p($tr->t('modals.folder.new_title')); ?></h3>
        <div class="modal-body modal-body-spaced">
            <input type="text" id="newFolderName" placeholder="<?php p($tr->t('modals.folder.new_placeholder')); ?>" maxlength="255" class="new-folder-input" data-enter-action="create-folder">
        </div>
        <div class="modal-buttons">
            <button type="button" class="btn-cancel" data-action="close-modal" data-modal="newFolderModal"><?php p($tr->t('common.cancel')); ?></button>
            <button type="button" class="btn-primary" data-action="create-folder"><?php p($tr->t('common.create')); ?></button>
        </div>
    </div>
</div>

<!-- Modal for moving note to folder -->
<div id="moveNoteModal" class="modal">
    <div class="modal-content">
        <h3><?php p($tr->t('modals.folder.change_folder')); ?></h3>
        <p><span><?php p($tr->t('modals.folder.move_prefix')); ?></span> "<span id="moveNoteTitle"></span>" <span><?php p($tr->t('modals.folder.move_suffix')); ?></span></p>
        <select id="moveNoteFolder">
            <option value=""><?php p($tr->t('modals.folder.no_folder')); ?></option>
        </select>
        <div class="modal-buttons">
            <button data-action="move-note-to-folder"><?php p($tr->t('common.move')); ?></button>
            <button data-action="close-modal" data-modal="moveNoteModal"><?php p($tr->t('common.cancel')); ?></button>
        </div>
    </div>
</div>

<!-- Modal for moving note to folder from toolbar -->
<div id="moveNoteFolderModal" class="modal">
    <div class="modal-content">
        <h3><?php p($tr->t('modals.move_note_folder.title')); ?></h3>
        
        <!-- Workspace selection -->
        <div class="form-group">
            <div class="move-note-label-row">
                <label for="workspaceSelect"><?php p($tr->t('modals.move_note_folder.workspace_destination')); ?></label>
            </div>
            <select id="workspaceSelect" class="workspace-select" data-action="on-workspace-change">
                <!-- Workspaces will be loaded here -->
            </select>
        </div>

        <div class="form-group">
            <div class="move-note-label-row">
                <label for="moveNoteTargetSelect"><?php p($tr->t('modals.move_note_folder.target_folder')); ?></label>
                <button type="button" class="move-note-inline-create-toggle" data-action="toggle-move-create-folder">
                    <i class="lucide lucide-plus"></i><?php p($tr->t('modals.move_note_folder.new_folder', [], 'New folder')); ?>
                </button>
            </div>
            <div id="moveNoteRecentFolders" class="move-note-recent-folders initially-hidden">
                <div class="move-note-recent-label">
                    <i class="lucide lucide-history"></i>
                    <?php p($tr->t('modals.move_note_folder.recent_folders', [], 'Recent folders')); ?>
                </div>
                <div id="moveNoteRecentFoldersList" class="move-note-recent-list"></div>
            </div>
            <select id="moveNoteTargetSelect" class="workspace-select">
                <!-- Options will be populated dynamically -->
            </select>
            <div id="moveCreateFolderRow" class="move-note-inline-create initially-hidden">
                <input type="text" id="moveCreateFolderName" maxlength="255" placeholder="<?php p($tr->t('modals.move_note_folder.folder_name_placeholder', [], 'Folder name (use / for subfolders)')); ?>" data-enter-action="create-move-modal-folder">
                <button type="button" class="btn-primary" data-action="create-move-modal-folder"><?php p($tr->t('common.create')); ?></button>
                <button type="button" class="btn-cancel" data-action="toggle-move-create-folder"><?php p($tr->t('common.cancel')); ?></button>
            </div>
        </div>
        
        <!-- Action buttons -->
        <div class="modal-buttons">
            <button type="button" class="btn-cancel" data-action="close-modal" data-modal="moveNoteFolderModal"><?php p($tr->t('common.cancel')); ?></button>
            <button type="button" id="moveActionButton" class="btn-primary" data-action="move-note-to-folder"><?php p($tr->t('common.move')); ?></button>
        </div>
        
        <!-- Error message display -->
        <div id="moveFolderErrorMessage" class="modal-error-message">
            <?php p($tr->t('modals.move_note_folder.enter_folder_name')); ?>
        </div>
    </div>
</div>

<!-- Modal for editing folder name -->
<div id="editFolderModal" class="modal">
    <div class="modal-content">
        <h3><?php p($tr->t('modals.folder.rename_title')); ?></h3>
        <input type="text" id="editFolderName" placeholder="<?php p($tr->t('modals.folder.rename_placeholder')); ?>" maxlength="255">
        <div class="modal-buttons">
            <button data-action="save-folder-name"><?php p($tr->t('common.save')); ?></button>
            <button data-action="close-modal" data-modal="editFolderModal"><?php p($tr->t('common.cancel')); ?></button>
        </div>
    </div>
</div>

<!-- Modal for renaming a note from the tree's note actions menu -->
<div id="renameNoteModal" class="modal">
    <div class="modal-content">
        <h3><?php p($tr->t('modals.note.rename_title', [], 'Rename note')); ?></h3>
        <input type="text" id="renameNoteName" placeholder="<?php p($tr->t('modals.note.rename_placeholder', [], 'Note title')); ?>" maxlength="255">
        <div class="modal-buttons">
            <button data-action="save-note-name"><?php p($tr->t('common.save')); ?></button>
            <button data-action="close-modal" data-modal="renameNoteModal"><?php p($tr->t('common.cancel')); ?></button>
        </div>
    </div>
</div>

<!-- Modal for deleting folder -->
<div id="deleteFolderModal" class="modal">
    <div class="modal-content">
        <h3><?php p($tr->t('modals.folder.delete_title')); ?></h3>
        <div id="deleteFolderMessage" class="delete-folder-message">
            <p id="deleteFolderMainMessage" class="delete-folder-main-message"></p>
            <ul id="deleteFolderDetails" class="delete-folder-details">
            </ul>
            <p id="deleteFolderNote" class="delete-folder-note"></p>
        </div>
        <div class="modal-buttons">
            <button type="button" class="btn-cancel" data-action="close-modal" data-modal="deleteFolderModal"><?php p($tr->t('common.cancel')); ?></button>
            <button type="button" class="btn-danger" data-action="execute-delete-folder"><?php p($tr->t('modals.folder.delete_folder')); ?></button>
        </div>
    </div>
</div>

<!-- Modal for moving all files from one folder to another -->
<div id="moveFolderFilesModal" class="modal">
    <div class="modal-content">
        <h3><?php p($tr->t('modals.move_folder_files.title', [], 'Move All Files')); ?></h3>
        <p><?php p($tr->t('modals.move_folder_files.prompt_prefix', [], 'Move all files from')); ?> "<span id="sourceFolderName"></span>" <?php p($tr->t('modals.move_folder_files.prompt_suffix', [], 'to:')); ?></p>

        <div class="form-group" style="margin-bottom: 15px;">
            <label for="moveFolderFilesWorkspaceSelect" style="display: block; margin-bottom: 5px; font-weight: 500; font-size: 0.9em;"><?php p($tr->t('modals.move_folder.workspace', [], 'Target Workspace')); ?></label>
            <select id="moveFolderFilesWorkspaceSelect" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; background-color: var(--card-bg, #fff); color: var(--text-color, #333); display: block; -webkit-appearance: menulist; -moz-appearance: menulist; appearance: menulist;">
            </select>
        </div>

        <div class="form-group" style="margin-bottom: 15px;">
            <label for="moveFolderFilesTargetSelect" style="display: block; margin-bottom: 5px; font-weight: 500; font-size: 0.9em;"><?php p($tr->t('modals.move_folder_files.target_folder', [], 'Target Folder')); ?></label>
            <select id="moveFolderFilesTargetSelect" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; background-color: var(--card-bg, #fff); color: var(--text-color, #333); display: block; -webkit-appearance: menulist; -moz-appearance: menulist; appearance: menulist;">
                <option value=""><?php p($tr->t('modals.move_folder_files.select_target', [], 'Select target folder...')); ?></option>
            </select>
        </div>
        <div id="folderFilesCount" class="modal-info-message">
            <span id="filesCountText"></span>
        </div>
        <div class="modal-buttons">
            <button type="button" class="btn-cancel" data-action="close-modal" data-modal="moveFolderFilesModal"><?php p($tr->t('common.cancel')); ?></button>
            <button type="button" class="btn-primary" data-action="execute-move-all-files"><?php p($tr->t('modals.move_folder_files.move_all', [], 'Move All Files')); ?></button>
        </div>
        <div id="moveFilesErrorMessage" class="modal-error-message"></div>
    </div>
</div>

<!-- Move Folder to Subfolder Modal -->
<div id="moveFolderModal" class="modal">
    <div class="modal-content">
        <h3><?php p($tr->t('modals.move_folder.title', [], 'Move Folder')); ?></h3>
        <p><?php p($tr->t('modals.move_folder.prompt_prefix', [], 'Move folder')); ?> "<span id="moveFolderSourceName"></span>" <?php p($tr->t('modals.move_folder.prompt_suffix', [], 'into:')); ?></p>
        
        <div class="form-group" style="margin-bottom: 15px;">
            <label for="moveFolderWorkspaceSelect" style="display: block; margin-bottom: 5px; font-weight: 500; font-size: 0.9em;"><?php p($tr->t('modals.move_folder.workspace', [], 'Target Workspace')); ?></label>
            <select id="moveFolderWorkspaceSelect" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; background-color: var(--card-bg, #fff); color: var(--text-color, #333); display: block; -webkit-appearance: menulist; -moz-appearance: menulist; appearance: menulist;">
                <!-- Populated by JS -->
            </select>
        </div>

        <div class="form-group" style="margin-bottom: 15px;">
            <label for="moveFolderTargetSelect" style="display: block; margin-bottom: 5px; font-weight: 500; font-size: 0.9em;"><?php p($tr->t('modals.move_folder.parent', [], 'Target Parent Folder')); ?></label>
            <select id="moveFolderTargetSelect" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; background-color: var(--card-bg, #fff); color: var(--text-color, #333); display: block; -webkit-appearance: menulist; -moz-appearance: menulist; appearance: menulist;">
                <option value=""><?php p($tr->t('modals.move_folder.select_target', [], 'Select parent folder...')); ?></option>
            </select>
        </div>

        <div class="modal-buttons">
            <button type="button" class="btn-cancel" data-action="close-modal" data-modal="moveFolderModal"><?php p($tr->t('common.cancel')); ?></button>
            <button type="button" class="btn-primary" data-action="execute-move-folder-to-subfolder"><?php p($tr->t('modals.move_folder.move', [], 'Move Folder')); ?></button>
        </div>
    </div>
</div>

<!-- Create workspace modal, opened from the sidebar workspace menu and from
     the create (+) menu on index.php: a name and, optionally, its tags
     (js/workspaces-create.js). Settings > Workspaces is the place to rename,
     move, share and delete. -->
<div id="createWorkspaceModal" class="modal initially-hidden">
    <div class="modal-content">
        <h3><?php p($tr->t('workspaces.sections.create.title', [], 'Create a new workspace')); ?></h3>
        <div class="form-group">
            <input id="createWorkspaceInput" type="text" autocomplete="off" placeholder="<?php p($tr->t('workspaces.sections.create.placeholder', [], 'Enter workspace name')); ?>" />
        </div>
        <div class="form-group create-workspace-tags">
            <input id="createWorkspaceTagsInput" type="text" autocomplete="off" placeholder="<?php p($tr->t('workspaces.tags.placeholder', [], 'Tags, comma separated (optional, e.g. school, psycho)')); ?>" aria-describedby="createWorkspaceTagsHelp" />
            <small id="createWorkspaceTagsHelp" class="create-workspace-tags-help"><?php p($tr->t('workspaces.tags.help', [], 'Use tags to group workspaces on the dashboard.')); ?></small>
        </div>
        <div class="modal-buttons">
            <button type="button" class="btn-cancel" data-action="close-modal" data-modal="createWorkspaceModal"><?php p($tr->t('common.cancel')); ?></button>
            <button type="button" class="btn-primary" id="confirmCreateWorkspaceBtn"><?php p($tr->t('common.create', [], 'Create')); ?></button>
        </div>
        <div id="createWorkspaceError" class="modal-error-message"></div>
    </div>
</div>

<!-- Create dropdown, shared by the sidebar + button and the folder actions
     "Create here" entry. Styled and placed like the folder/note actions menus
     (css/folders/actions-menu.css, adjustMenuPosition in js/utils-menus.js): a
     single menu anchored to whatever opened it, not a modal.
     The .create-note-option class and the data-type values are what the UI
     Customization keys match on, so hiding an entry keeps working. -->
<div id="create-menu" class="create-menu" role="menu">
    <!-- Notes: the only group offered when creating inside a folder -->
    <div class="create-section" id="notesSection">
        <div class="create-menu-item create-note-option" data-type="html" data-action="select-create-type" role="menuitem">
            <i class="lucide lucide-file-text"></i>
            <span><?php $createTitle($tr->t('modals.create.note.title', [], 'Note')); ?></span>
        </div>
        <div class="create-menu-item create-note-option" data-type="markdown" data-action="select-create-type" role="menuitem">
            <i class="lucide lucide-file-code"></i>
            <span><?php $createTitle($tr->t('modals.create.markdown.title', [], 'Markdown Note')); ?></span>
        </div>
        <div class="create-menu-item create-note-option" data-type="list" data-action="select-create-type" role="menuitem">
            <i class="lucide lucide-list"></i>
            <span><?php p($tr->t('modals.create.task_list.title', [], 'Task List')); ?></span>
        </div>
        <div class="create-menu-item create-note-option initially-hidden" data-type="subfolder" data-action="select-create-type" id="subfolderOption" role="menuitem">
            <i class="lucide lucide-folder-plus"></i>
            <span><?php p($tr->t('modals.create.subfolder.title', [], 'Subfolder')); ?></span>
        </div>
    </div>

    <!-- Folder: dropped when the menu is opened on a folder -->
    <div class="create-section" id="otherSection">
        <div class="create-menu-item create-note-option" data-type="folder" data-action="select-create-type" role="menuitem">
            <i class="lucide lucide-folder"></i>
            <span><?php p($tr->t('modals.create.folder.title', [], 'Folder')); ?></span>
        </div>
    </div>

    <!-- The notes column itself: only when the menu is opened by a right-click
         on the empty part of that column (js/notes-list-events.js), same as the
         chevron on its edge (js/resize-column.js) -->
    <div class="create-section initially-hidden" id="columnSection">
        <div class="create-menu-item" data-action="hide-left-column" role="menuitem">
            <i class="lucide lucide-panel-left"></i>
            <span><?php p($tr->t('sidebar.hide_left_column', [], 'Hide left column')); ?></span>
        </div>
    </div>
</div>

<!-- Paste as Markdown Modal -->
<div id="pasteMarkdownModal" class="modal">
    <div class="modal-content paste-markdown-modal-content">
        <h3><?php p($tr->t('modals.paste_markdown.title', [], 'Insert rich text')); ?></h3>
        <div class="modal-body">
            <p class="paste-markdown-hint"><?php p($tr->t('modals.paste_markdown.hint', [], 'Paste formatted content (a web page, Word, an email) into the box below. Its formatting, headings, links, lists and tables, is converted to Markdown that you can review, edit, then insert into your note.')); ?></p>
            <!-- contenteditable, not a textarea: only a rich-text target receives
                 the clipboard's text/html flavour that carries the formatting. -->
            <div id="pasteMarkdownDropzone" class="paste-markdown-dropzone" contenteditable="true" role="textbox" aria-multiline="true" aria-label="<?php p($tr->t('modals.paste_markdown.title', [], 'Insert rich text')); ?>" data-placeholder="<?php p($tr->t('modals.paste_markdown.placeholder', [], 'Paste here (Ctrl+V)')); ?>"></div>
            <div id="pasteMarkdownPreviewWrapper" class="paste-markdown-preview-wrapper is-hidden">
                <label class="paste-markdown-preview-label" for="pasteMarkdownPreview"><?php p($tr->t('modals.paste_markdown.preview_label', [], 'Markdown preview')); ?></label>
                <textarea id="pasteMarkdownPreview" class="paste-markdown-preview" spellcheck="false" aria-label="<?php p($tr->t('modals.paste_markdown.preview_label', [], 'Markdown preview')); ?>"></textarea>
            </div>
            <p id="pasteMarkdownError" class="paste-markdown-error" role="alert"></p>
        </div>
        <div class="modal-buttons">
            <button type="button" class="btn-cancel" data-action="close-modal" data-modal="pasteMarkdownModal"><?php p($tr->t('common.cancel')); ?></button>
            <button type="button" class="btn-primary" id="pasteMarkdownInsertBtn" disabled><?php p($tr->t('modals.paste_markdown.insert', [], 'Insert')); ?></button>
        </div>
    </div>
</div>

<!-- Insert Markdown Modal (HTML notes): the mirror of the modal above -->
<div id="insertMarkdownModal" class="modal">
    <div class="modal-content paste-markdown-modal-content">
        <h3><?php p($tr->t('modals.insert_markdown.title', [], 'Insert Markdown')); ?></h3>
        <div class="modal-body">
            <p class="paste-markdown-hint"><?php p($tr->t('modals.insert_markdown.hint', [], 'Paste or type Markdown in the box below. It is converted to rich text that you can preview, then insert into your note.')); ?></p>
            <!-- Markdown is plain text, so a textarea is enough to capture it. -->
            <textarea id="insertMarkdownSource" class="paste-markdown-preview" spellcheck="false" aria-label="<?php p($tr->t('modals.insert_markdown.title', [], 'Insert Markdown')); ?>" placeholder="<?php p($tr->t('modals.insert_markdown.placeholder', [], 'Paste or type Markdown here')); ?>"></textarea>
            <div id="insertMarkdownPreviewWrapper" class="paste-markdown-preview-wrapper is-hidden">
                <div class="paste-markdown-preview-label"><?php p($tr->t('modals.insert_markdown.preview_label', [], 'Preview')); ?></div>
                <div id="insertMarkdownPreview" class="insert-markdown-preview"></div>
            </div>
            <p id="insertMarkdownError" class="paste-markdown-error" role="alert"></p>
        </div>
        <div class="modal-buttons">
            <button type="button" class="btn-cancel" data-action="close-modal" data-modal="insertMarkdownModal"><?php p($tr->t('common.cancel')); ?></button>
            <button type="button" class="btn-primary" id="insertMarkdownInsertBtn" disabled><?php p($tr->t('modals.insert_markdown.insert', [], 'Insert')); ?></button>
        </div>
    </div>
</div>

<!-- Edit Task Modal -->
<div id="taskEditModal" class="modal">
    <div class="modal-content task-edit-modal-content">
        <h3><?php p($tr->t('tasklist.edit_task', [], 'Edit task')); ?></h3>
        <div class="modal-body task-edit-modal-body">
            <textarea id="taskEditTextarea" class="task-edit-textarea" maxlength="4000" aria-label="<?php p($tr->t('tasklist.edit_text_label', [], 'Task text')); ?>" aria-describedby="taskEditHint taskEditError"></textarea>
            <p id="taskEditHint" class="task-edit-hint"><?php p($tr->t('tasklist.edit_hint', [], 'Enter adds a new line. Ctrl+Enter saves.')); ?></p>
            <p id="taskEditError" class="task-edit-error" role="alert"></p>
        </div>
        <div class="modal-buttons">
            <button type="button" class="btn-cancel" id="cancelTaskEditBtn"><?php p($tr->t('common.cancel')); ?></button>
            <button type="button" class="btn-primary" id="saveTaskEditBtn"><?php p($tr->t('common.save')); ?></button>
        </div>
    </div>
</div>

<!-- Move Task Modal -->
<div id="moveTaskModal" class="modal">
    <div class="modal-content">
        <h3><?php p($tr->t('modals.task_move.title', [], 'Move task')); ?></h3>
        <div class="modal-body">
            <p><?php p($tr->t('modals.task_move.description', [], 'Choose a task list to move this task to.')); ?></p>
            <input type="text" id="moveTaskSearchInput" placeholder="<?php p($tr->t('modals.task_move.search_placeholder', [], 'Search task lists...')); ?>">
            <div id="moveTaskList" class="move-task-list"></div>
        </div>
        <div class="modal-buttons">
            <button type="button" class="btn-cancel" data-action="close-modal" data-modal="moveTaskModal"><?php p($tr->t('common.cancel')); ?></button>
            <button type="button" class="btn-primary" id="confirmMoveTaskBtn"><?php p($tr->t('modals.task_move.confirm', [], 'Move task')); ?></button>
        </div>
    </div>
</div>

<div id="taskListPickerModal" class="modal">
    <div class="modal-content">
        <h3><?php p($tr->t('modals.tasklist_picker.title', [], 'Insert task list')); ?></h3>
        <div class="modal-body">
            <p><?php p($tr->t('modals.tasklist_picker.description', [], 'Choose the task list to display in this note.')); ?></p>
            <input type="text" id="taskListPickerSearchInput" placeholder="<?php p($tr->t('modals.task_move.search_placeholder', [], 'Search task lists...')); ?>">
            <div id="taskListPickerList" class="move-task-list"></div>
        </div>
        <div class="modal-buttons">
            <button type="button" class="btn-cancel" data-action="close-modal" data-modal="taskListPickerModal"><?php p($tr->t('common.cancel')); ?></button>
            <button type="button" class="btn-primary" id="confirmTaskListPickerBtn" disabled><?php p($tr->t('modals.tasklist_picker.confirm', [], 'Insert')); ?></button>
        </div>
    </div>
</div>

<div
    id="taskDueModal"
    class="modal"
    data-email-available="0"
    data-txt-date="<?php p($tr->t('reminder.modal.date_placeholder', [], 'Date')); ?>"
    data-txt-time="<?php p($tr->t('reminder.modal.time_placeholder', [], 'Time')); ?>"
    data-txt-no-date="<?php p($tr->t('reminder.modal.no_date_configured', [], 'No date configured')); ?>"
    data-txt-remove-time="<?php p($tr->t('tasklist.due_remove_time', [], 'Remove time')); ?>"
>
    <div class="modal-content">
        <h3><?php p($tr->t('tasklist.due_date', [], 'Due date')); ?></h3>
        <div class="reminder-form">
            <div class="reminder-input-row">
                <button type="button" id="taskDueDateBtn" class="reminder-datetime-part">
                    <i class="lucide lucide-calendar-alt"></i>
                    <span id="taskDueDateBtnLabel"><?php p($tr->t('reminder.modal.date_placeholder', [], 'Date')); ?></span>
                </button>
                <button type="button" id="taskDueTimeBtn" class="reminder-datetime-part">
                    <i class="lucide lucide-clock"></i>
                    <span id="taskDueTimeBtnLabel"><?php p($tr->t('reminder.modal.time_placeholder', [], 'Time')); ?></span>
                </button>
            </div>
            <div class="reminder-repeat-row" id="taskDueRepeatRow">
                <label class="reminder-repeat-label" for="taskDueRepeatSelect">
                    <i class="lucide lucide-repeat"></i>
                    <?php p($tr->t('reminder.modal.repeat_label', [], 'Repeat')); ?>
                </label>
                <select id="taskDueRepeatSelect" class="reminder-repeat-select">
                    <option value=""><?php p($tr->t('reminder.modal.repeat_none', [], 'Does not repeat')); ?></option>
                    <option value="1h"><?php p($tr->t('reminder.modal.repeat_hourly', [], 'Hourly')); ?></option>
                    <option value="1d"><?php p($tr->t('reminder.modal.repeat_daily', [], 'Daily')); ?></option>
                    <option value="1w"><?php p($tr->t('reminder.modal.repeat_weekly', [], 'Weekly')); ?></option>
                    <option value="1m"><?php p($tr->t('reminder.modal.repeat_monthly', [], 'Monthly')); ?></option>
                    <option value="1y"><?php p($tr->t('reminder.modal.repeat_yearly', [], 'Yearly')); ?></option>
                    <option value="custom"><?php p($tr->t('reminder.modal.repeat_custom', [], 'Custom...')); ?></option>
                </select>
            </div>
            <div class="reminder-repeat-custom initially-hidden" id="taskDueRepeatCustom">
                <span class="reminder-repeat-every"><?php p($tr->t('reminder.modal.repeat_every', [], 'Every')); ?></span>
                <input type="number" id="taskDueRepeatInterval" class="reminder-repeat-interval" min="1" max="999" value="1">
                <select id="taskDueRepeatUnit" class="reminder-repeat-unit">
                    <option value="i"><?php p($tr->t('reminder.modal.repeat_minutes', [], 'minute(s)')); ?></option>
                    <option value="h"><?php p($tr->t('reminder.modal.repeat_hours', [], 'hour(s)')); ?></option>
                    <option value="d"><?php p($tr->t('reminder.modal.repeat_days', [], 'day(s)')); ?></option>
                    <option value="w"><?php p($tr->t('reminder.modal.repeat_weeks', [], 'week(s)')); ?></option>
                    <option value="m"><?php p($tr->t('reminder.modal.repeat_months', [], 'month(s)')); ?></option>
                    <option value="y"><?php p($tr->t('reminder.modal.repeat_years', [], 'year(s)')); ?></option>
                </select>
            </div>
            <div class="reminder-repeat-hint initially-hidden" id="taskDueRepeatHint">
                <?php p($tr->t('reminder.modal.task_repeat_hint', [], 'The next occurrence is added to the list when you complete the task. Dismissing the notification moves the task to its next date.')); ?>
            </div>
            <div class="reminder-email-option" id="taskDueRemindOption">
                <label class="reminder-email-label" for="taskDueRemindInput">
                    <span class="reminder-email-copy">
                        <span class="reminder-email-title">
                            <i class="lucide lucide-bell"></i>
                            <?php p($tr->t('tasklist.remind_me', [], 'Remind me')); ?>
                        </span>
                        <span class="reminder-email-hint">
                            <?php p($tr->t('tasklist.remind_me_hint', [], 'A notification fires at the due date and time.')); ?>
                        </span>
                    </span>
                    <span class="toggle-switch reminder-email-switch">
                        <input type="checkbox" id="taskDueRemindInput">
                        <span class="toggle-slider"></span>
                    </span>
                </label>
            </div>
            <div class="reminder-email-option initially-hidden" id="taskDueEmailOption">
                <label class="reminder-email-label" for="taskDueEmailInput">
                    <span class="reminder-email-copy">
                        <span class="reminder-email-title">
                            <i class="lucide lucide-mail"></i>
                            <?php p($tr->t('reminder.modal.email_toggle', [], 'Send me an email')); ?>
                        </span>
                        <span class="reminder-email-hint">
                            
                        </span>
                    </span>
                    <span class="toggle-switch reminder-email-switch">
                        <input type="checkbox" id="taskDueEmailInput">
                        <span class="toggle-slider"></span>
                    </span>
                </label>
            </div>
            <div class="reminder-current-info is-empty" id="taskDueCurrentInfo">
                <i class="lucide lucide-calendar-alt"></i>
                <span id="taskDueCurrentDate"></span>
            </div>
        </div>
        <div class="modal-buttons reminder-modal-actions">
            <button type="button" class="btn-danger initially-hidden reminder-modal-action" id="taskDueRemoveBtn"><?php p($tr->t('tasklist.due_remove', [], 'Remove due date')); ?></button>
            <button type="button" class="btn-cancel reminder-modal-action" data-action="close-modal" data-modal="taskDueModal"><?php p($tr->t('common.cancel')); ?></button>
            <button type="button" class="btn-primary reminder-modal-action" id="taskDueSaveBtn"><?php p($tr->t('common.save')); ?></button>
        </div>
    </div>
</div>

<!-- Highlight current folder tree: how far the rest of the notes list fades -->
<div id="folderTreeHighlightModal" class="modal">
    <div class="modal-content">
        <h3><?php p($tr->t('modals.folder_tree_highlight.title', [], 'Highlight current folder tree')); ?></h3>
        <div class="modal-body">
            <p><?php p($tr->t('modals.folder_tree_highlight.description', [], 'Notes and folders outside the hierarchy you are working in fade out. Choose how far they fade:')); ?></p>
            <div class="folder-tree-dim-control">
                <label for="folderTreeDimInput"><?php p($tr->t('modals.folder_tree_highlight.dim_strength', [], 'Dimming')); ?>: <span id="folderTreeDimValue">35</span>%</label>
                <input type="range" id="folderTreeDimInput" min="10" max="90" step="5" value="35">
            </div>
            <!-- Stand-in for the notes list: the settings page has no tree of
                 its own, so the slider needs something to preview on. -->
            <div class="folder-tree-dim-preview" id="folderTreeDimPreview" aria-hidden="true">
                <div class="ftd-row"><i class="lucide lucide-folder-open"></i><span><?php p($tr->t('modals.folder_tree_highlight.preview_current_folder', [], 'Current folder')); ?></span></div>
                <div class="ftd-row ftd-note"><i class="lucide lucide-file-text"></i><span><?php p($tr->t('modals.folder_tree_highlight.preview_current_note', [], 'A note you are working on')); ?></span></div>
                <div class="ftd-row ftd-dimmed"><i class="lucide lucide-folder"></i><span><?php p($tr->t('modals.folder_tree_highlight.preview_other_folder', [], 'Another folder')); ?></span></div>
                <div class="ftd-row ftd-note ftd-dimmed"><i class="lucide lucide-file-text"></i><span><?php p($tr->t('modals.folder_tree_highlight.preview_other_note', [], 'A note somewhere else')); ?></span></div>
            </div>
        </div>
        <div class="modal-buttons">
            <button type="button" class="btn-cancel" data-action="close-modal" data-modal="folderTreeHighlightModal"><?php p($tr->t('common.cancel')); ?></button>
            <button type="button" class="btn-secondary" id="disableFolderTreeHighlightBtn"><?php p($tr->t('modals.folder_tree_highlight.turn_off', [], 'Turn off')); ?></button>
            <button type="button" class="btn-primary" id="saveFolderTreeHighlightBtn"><?php p($tr->t('common.save')); ?></button>
        </div>
    </div>
</div>

<!-- Note age filter modal -->
<div id="noteAgeFilterModal" class="modal">
    <div class="modal-content">
        <h3><?php p($tr->t('modals.note_age_filter.title', [], 'Note age filter')); ?></h3>
        <div class="modal-body">
            <p><?php p($tr->t('modals.note_age_filter.description', [], 'Choose how far back modified notes should appear in the notes list:')); ?></p>
            <div class="radio-options">
                <label><input type="radio" name="noteAgeFilter" value="0"> <?php p($tr->t('modals.note_age_filter.options.all', [], 'All notes')); ?></label>
                <label><input type="radio" name="noteAgeFilter" value="30"> <?php p($tr->t('modals.note_age_filter.options.last_30_days', [], 'Last 30 days')); ?></label>
                <label><input type="radio" name="noteAgeFilter" value="90"> <?php p($tr->t('modals.note_age_filter.options.last_3_months', [], 'Last 3 months')); ?></label>
                <label><input type="radio" name="noteAgeFilter" value="180"> <?php p($tr->t('modals.note_age_filter.options.last_6_months', [], 'Last 6 months')); ?></label>
                <label><input type="radio" name="noteAgeFilter" value="365"> <?php p($tr->t('modals.note_age_filter.options.last_12_months', [], 'Last 12 months')); ?></label>
                <label><input type="radio" name="noteAgeFilter" value="730"> <?php p($tr->t('modals.note_age_filter.options.last_2_years', [], 'Last 2 years')); ?></label>
                <label><input type="radio" name="noteAgeFilter" value="custom"> <?php p($tr->t('modals.note_age_filter.options.custom_label', [], 'Custom')); ?>: <input type="number" id="noteAgeFilterCustomDays" min="1" max="36500" step="1" style="width:80px; margin:0; padding:4px 8px;"> <?php p($tr->t('modals.note_age_filter.options.days', [], 'days')); ?></label>
            </div>
        </div>
        <div class="modal-buttons">
            <button type="button" class="btn-cancel" data-action="close-modal" data-modal="noteAgeFilterModal"><?php p($tr->t('common.cancel')); ?></button>
            <button type="button" class="btn-primary" id="saveNoteAgeFilterModalBtn"><?php p($tr->t('common.save')); ?></button>
        </div>
    </div>
</div>

<div id="noteReferenceModal" class="modal">
    <div class="modal-content note-reference-modal-content">
        <h3><i class="lucide lucide-link"></i> <?php p($tr->t('editor.toolbar.insert_note_reference', [], 'Insert note reference')); ?></h3>
        <div class="note-reference-search">
            <input type="text" id="noteReferenceSearch" placeholder="<?php p($tr->t('note_reference.modal.search_placeholder', [], 'Search for a note...')); ?>" autocomplete="off">
        </div>
        <label class="note-reference-all-workspaces">
            <input type="checkbox" id="noteReferenceAllWorkspaces">
            <span><?php p($tr->t('note_reference.modal.all_workspaces', [], 'Search all workspaces')); ?></span>
        </label>
        <div class="note-reference-recent-label"><?php p($tr->t('note_reference.modal.recent_notes', [], 'Recent notes')); ?></div>
        <div id="noteReferenceList" class="note-reference-list">
            <!-- Notes will be populated here -->
        </div>
        <div class="modal-buttons">
            <button type="button" class="btn-cancel" data-action="close-note-reference-modal"><?php p($tr->t('common.cancel')); ?></button>
            <button type="button" class="btn-primary" id="noteReferenceCreateBtn"><?php p($tr->t('note_reference.modal.create_button', [], 'Create & link')); ?></button>
        </div>
    </div>
</div>
<!-- Folder Icon Modal -->
<div id="folderIconModal" class="modal">
    <div class="modal-content folder-icon-modal-content">
        <div class="folder-icon-search-wrapper">
            <input type="text" id="folderIconSearchInput" placeholder="<?php p($tr->t('modals.folder_icon.search_placeholder', [], 'Search icons...')); ?>" autocomplete="off">
        </div>
        <div class="folder-icon-recent-section" id="folderIconRecentSection" style="display: none;">
            <div class="folder-icon-grid folder-icon-recent-grid" id="folderIconRecentGrid"></div>
        </div>
        <div class="folder-icon-grid" id="folderIconGrid">
            <!-- Icons will be populated here -->
        </div>
        <div class="folder-color-section">
            <div class="folder-color-picker">
                <div class="folder-color-option" data-color="" title="<?php p($tr->t('modals.folder_icon.default_color', [], 'Default')); ?>">
                    <div class="folder-color-swatch folder-color-default"></div>
                </div>
<?php foreach ($view['iconPalette'] as $swatch): ?>
                <div class="folder-color-option" data-color="<?php p($swatch['hex']); ?>" title="<?php p($tr->t('colors.' . $swatch['id'], [], ucfirst($swatch['id']))); ?>">
                    <div class="folder-color-swatch" style="background-color: <?php p($swatch['css']); ?>;"></div>
                </div>
<?php endforeach; ?>
            </div>

        </div>
        <div class="modal-buttons">
            <button type="button" class="btn-secondary" id="resetFolderIconBtn"><?php p($tr->t('modals.folder_icon.default_icon', [], 'Set default icon')); ?></button>
            <button type="button" class="btn-cancel" data-action="close-folder-icon-modal"><?php p($tr->t('common.cancel')); ?></button>
            <button type="button" class="btn-primary" id="applyFolderIconBtn"><?php p($tr->t('common.apply', [], 'Apply')); ?></button>
        </div>
    </div>
</div>

<!-- Tag all notes of a folder. Shared by index.php (via modals.php) and
     list_folders.php; opened by showTagFolderNotesDialog() in js/utils-menus.js.
     The messages the script needs at runtime are carried as data attributes,
     so the page does not have to load the client-side i18n bundle. -->
<div id="tagFolderNotesModal" class="modal"
	data-msg-count-one="<?php p($tr->t('modals.tag_folder_notes.count_one', [], '1 note will be tagged')); ?>"
	data-msg-count-other="<?php p($tr->t('modals.tag_folder_notes.count_other', [], '{{count}} notes will be tagged')); ?>"
	data-msg-empty="<?php p($tr->t('modals.tag_folder_notes.empty_folder', [], 'This folder is empty')); ?>"
	data-msg-no-tags="<?php p($tr->t('modals.tag_folder_notes.no_tags', [], 'Enter at least one tag')); ?>"
	data-msg-applying="<?php p($tr->t('modals.tag_folder_notes.applying', [], 'Tagging...')); ?>"
	data-msg-success-one="<?php p($tr->t('modals.tag_folder_notes.success_one', [], '1 note tagged')); ?>"
	data-msg-success-other="<?php p($tr->t('modals.tag_folder_notes.success_other', [], '{{count}} notes tagged')); ?>"
	data-msg-success-none="<?php p($tr->t('modals.tag_folder_notes.success_none', [], 'All notes already had these tags')); ?>"
	data-msg-error="<?php p($tr->t('modals.tag_folder_notes.error', [], 'Could not tag the notes')); ?>">
	<div class="modal-content">
		<h3><?php p($tr->t('modals.tag_folder_notes.title', [], 'Tag all notes')); ?></h3>
		<p><?php p($tr->t('modals.tag_folder_notes.prompt_prefix', [], 'Add tags to every note in')); ?> "<span id="tagFolderNotesSourceName"></span>"<?php p($tr->t('modals.tag_folder_notes.prompt_suffix', [], ':')); ?></p>
		<input type="text" id="tagFolderNotesInput" autocomplete="off" autocapitalize="off" spellcheck="false"
			placeholder="<?php p($tr->t('modals.tag_folder_notes.placeholder', [], 'Tags, separated by commas')); ?>">
		<div id="tagFolderNotesSuggestions" class="tag-folder-notes-suggestions" style="display: none;">
			<span class="tag-folder-notes-suggestions-label"><?php p($tr->t('modals.tag_folder_notes.existing_tags', [], 'Existing tags')); ?></span>
			<div id="tagFolderNotesSuggestionList" class="tag-folder-notes-suggestion-list"></div>
		</div>
		<div class="modal-info-message">
			<span id="tagFolderNotesCountText"></span>
		</div>
		<div class="modal-buttons">
			<button type="button" class="btn-cancel" data-action="close-modal" data-modal="tagFolderNotesModal"><?php p($tr->t('common.cancel')); ?></button>
			<button type="button" class="btn-primary" data-action="execute-tag-folder-notes"><?php p($tr->t('modals.tag_folder_notes.apply', [], 'Add tags')); ?></button>
		</div>
		<div id="tagFolderNotesErrorMessage" class="modal-error-message"></div>
	</div>
</div>

<!-- Linked Note Selector Modal -->
<div id="linkedNoteSelectorModal" class="modal">
    <div class="modal-content">
        <div class="note-reference-modal-header">
            <h3 class="modal-title"><?php p($tr->t('modals.linked_selector.title', [], 'Create a Shortcut from a Note')); ?></h3>
        </div>
        <p class="note-reference-description shortcut-selector-description"><?php p($tr->t('modals.linked_selector.description', [], 'Create a shortcut from a note so you can reference it from another folder.')); ?></p>
        <div class="note-reference-search-container">
            <input type="text" id="linkedNoteSearch" placeholder="<?php p($tr->t('modals.linked_selector.search_placeholder', [], 'Search notes...')); ?>" class="note-reference-search-input">
        </div>
        <div class="note-reference-list-wrapper">
            <div class="note-reference-recent-label"><?php p($tr->t('modals.linked_selector.recent_label', [], 'Recent notes')); ?></div>
            <div id="linkedNoteList" class="note-reference-list">
                <div class="note-reference-loading">
                    <i class="lucide lucide-loader-2 lucide-spin"></i> <?php p($tr->t('common.loading', [], 'Loading...')); ?>
                </div>
            </div>
        </div>
        <div class="modal-buttons">
            <button type="button" class="btn-cancel" data-action="close-linked-selector-modal"><?php p($tr->t('common.cancel')); ?></button>
        </div>
    </div>
</div>

<!-- Linked Note Folder Selector Modal -->
<div id="linkedNoteFolderSelectorModal" class="modal">
    <div class="modal-content">
        <div class="note-reference-modal-header">
            <h3 class="modal-title"><?php p($tr->t('modals.linked_folder_selector.title', [], 'Choose Folder for Shortcut')); ?></h3>
        </div>
        <div class="note-reference-search-container">
            <input type="text" id="linkedNoteFolderSearch" placeholder="<?php p($tr->t('modals.linked_folder_selector.search_placeholder', [], 'Search folders...')); ?>" class="note-reference-search-input">
        </div>
        <div class="note-reference-list-wrapper">
            <div id="linkedFolderList" class="note-reference-list">
                <div class="note-reference-loading">
                    <i class="lucide lucide-loader-2 lucide-spin"></i> <?php p($tr->t('common.loading', [], 'Loading...')); ?>
                </div>
            </div>
        </div>
        <div class="modal-buttons">
            <button type="button" class="btn-cancel" data-action="close-linked-folder-selector-modal"><?php p($tr->t('common.cancel')); ?></button>
        </div>
    </div>
</div>

<!-- Delete Linked Note Modal -->
<div id="deleteLinkedNoteModal" class="modal">
    <div class="modal-content">
        <h3><?php p($tr->t('modals.delete_linked_note.title', [], 'Delete Shortcut')); ?></h3>
        <div class="modal-body">
            <p><?php p($tr->t('modals.delete_linked_note.message', [], 'What do you want to delete?')); ?></p>
        </div>
        <div class="modal-buttons">
            <button type="button" class="btn-primary" id="deleteLinkedNoteOnlyBtn"><?php p($tr->t('modals.delete_linked_note.delete_link_only', [], 'Delete this shortcut only')); ?></button>
            <button type="button" class="btn-danger" id="deleteLinkedNoteAndTargetBtn"><?php p($tr->t('modals.delete_linked_note.delete_link_and_target', [], 'Delete the note and all its shortcuts')); ?></button>
            <button type="button" class="btn-cancel" data-action="close-modal" data-modal="deleteLinkedNoteModal"><?php p($tr->t('common.cancel')); ?></button>
        </div>
    </div>
</div>

<!-- Move Linked Note Modal -->
<div id="moveLinkedNoteModal" class="modal">
    <div class="modal-content">
        <h3><?php p($tr->t('modals.move_linked_note.title', [], 'Move Shortcut')); ?></h3>
        <div class="modal-body">
            <p><?php p($tr->t('modals.move_linked_note.message', [], 'What do you want to move?')); ?></p>
        </div>
        <div class="modal-buttons">
            <button type="button" class="btn-primary" data-action="move-linked-note-shortcut"><?php p($tr->t('modals.move_linked_note.move_link_only', [], 'Move this shortcut only')); ?></button>
            <button type="button" class="btn-secondary" data-action="move-linked-note-target"><?php p($tr->t('modals.move_linked_note.move_target_note', [], 'Move the original note')); ?></button>
            <button type="button" class="btn-cancel" data-action="close-modal" data-modal="moveLinkedNoteModal"><?php p($tr->t('common.cancel')); ?></button>
        </div>
    </div>
</div>

<!-- Note Information Modal (note-info-modal.js, api_note_info.php); ohne Zeile „Vollständiger Pfad“ (keine Datei auf dem Server) -->
<?php
$noteInfoRows = [
	'title' => ['info.labels.note_title', 'Note title:'], 'workspace' => ['info.labels.workspace', 'Workspace:'],
	'folder' => ['info.labels.folder', 'Folder:'], 'created' => ['info.labels.created', 'Created:'],
	'created_by' => ['info.labels.created_by', 'Created by:'], 'updated' => ['info.labels.last_modified', 'Last Modified:'],
	'updated_by' => ['info.labels.last_modified_by', 'Last modified by:'], 'tags' => ['info.labels.tags', 'Tags:'],
	'favorite' => ['info.labels.favorite', 'Favorite:'], 'attachments' => ['info.labels.attachments', 'Attachments:'],
	'characters' => ['info.labels.characters', 'Characters:'], 'words' => ['info.labels.words', 'Words:'],
	'lines' => ['info.labels.lines', 'Lines:'], 'note_id' => ['info.labels.note_id', 'Note ID:'],
];
?>
<div id="noteInfoModal" class="modal">
    <div class="modal-content note-info-modal-content">
        <h3><i class="lucide lucide-info"></i> <?php p($tr->t('info.page_title', [], 'Note Information')); ?></h3>
        <div class="modal-body">
            <div class="note-info-rows">
                <?php foreach ($noteInfoRows as $infoKey => [$infoLabel, $infoDefault]): ?>
                <div class="note-info-row">
                    <div class="note-info-label"><?php p($tr->t($infoLabel, [], $infoDefault)); ?></div>
                    <div class="note-info-value" data-note-info="<?php p($infoKey); ?>"></div>
                </div>
                <?php endforeach; ?>
            </div>
            <p id="noteInfoError" class="note-info-error" role="alert" hidden></p>
        </div>
        <div class="modal-buttons">
            <button type="button" class="btn-cancel" data-action="close-modal" data-modal="noteInfoModal"><?php p($tr->t('common.close')); ?></button>
        </div>
    </div>
</div>

<!-- Info Modal -->
<div id="infoModal" class="modal">
    <div class="modal-content">
        <h3 id="infoModalTitle"></h3>
        <p id="infoModalMessage"></p>
        <div class="modal-buttons">
            <button type="button" class="btn-primary" data-action="close-info-modal"><?php p($tr->t('common.ok', [], 'OK')); ?></button>
        </div>
    </div>
</div>

<!-- Note Tags Modal -->
<div id="tagsModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><?php p($tr->t('tags.modal_title', [], 'Manage tags')); ?></h3>
        </div>
        <div class="modal-body">
            <input type="hidden" id="tagsModalNoteId" />
            <div id="tagsModalTagsList" class="tags-modal-list">
                <!-- Tags will be dynamically loaded here as a list -->
            </div>
            <div class="tags-modal-input-wrapper">
                <input type="text" id="tagsModalInput" placeholder="<?php p($tr->t('tags.add_single')); ?>" autocomplete="off" />
            </div>
        </div>
        <div class="modal-buttons">
            <button type="button" class="btn-cancel" data-action="close-tags-modal"><?php p($tr->t('common.close')); ?></button>
        </div>
    </div>
</div>

<!-- Code Block Language Modal: edit or remove the language tag of a code block -->
<div id="codeBlockLanguageModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><?php p($tr->t('editor.code_block_language.title', [], 'Code block language')); ?></h3>
        </div>
        <div class="modal-body">
            <input type="text" id="codeBlockLanguageFilter" class="code-block-language-filter" autocomplete="off" placeholder="<?php p($tr->t('editor.code_block_language.filter', [], 'Filter languages...')); ?>" />
            <div id="codeBlockLanguageList" class="code-block-language-list">
                <!-- Languages are rendered here by js/code-block-language.js -->
            </div>
        </div>
        <div class="modal-buttons">
            <button type="button" class="btn-cancel" data-action="close-modal" data-modal="codeBlockLanguageModal"><?php p($tr->t('common.cancel')); ?></button>
            <button type="button" class="btn-danger" data-action="remove-code-block-language"><?php p($tr->t('editor.code_block_language.remove_tag', [], 'Remove tag')); ?></button>
        </div>
    </div>
</div>