<?php
/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// abgeleitet von src/modals.php @ 7e2e0b6aa501ba95ac1e9789fd2bb477c281a001
// Dialoge der Hauptansicht und der Einstellungen (M4h): Schriftgröße, Ordnerbaum hervorheben, Altersfilter, Workspace anlegen.
/** @var \OCA\NextNotes\Service\View\PageTranslator $tr */
?>
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
