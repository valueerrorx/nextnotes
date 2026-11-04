<?php
/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// abgeleitet von src/modals.php @ 7e2e0b6aa501ba95ac1e9789fd2bb477c281a001 (Workspace-, Farb-, Markdown-, Versionen-, Datumsdialoge),
// src/workspaces_section.php (Info-Dialog) und src/modals/background_image_modal.php. Nur die Dialoge der Einstellungen.
/** @var array $_ */
/** @var \OCP\IL10N $l */
/** @var \OCA\NextNotes\Service\View\PageTranslator $tr */
// Upstream renderDateFormatTokenLegend (src/lib/diary.php): Muster bleiben wörtlich, nur die Bedeutung ist übersetzt
$legend = static function (string $group) use ($tr): void {
	$tokens = $tr->strings('modals.' . $group . '.tokens');
	if ($tokens === []) {
		return;
	}
	?><dl class="date-format-tokens"><?php foreach ($tokens as $token => $meaning): ?><dt><code><?php p((string)$token); ?></code></dt><dd><?php p($meaning); ?></dd><?php endforeach; ?></dl><?php
};
?>
<!-- Move notes modal (Settings > Workspaces, workspaces_section.php) -->
<div id="moveNotesModal" class="modal initially-hidden">
	<div class="modal-content">
		<h3><?php p($t('modals.workspaces.move_notes_title', 'Move notes from')); ?> "<span id="moveSourceName"></span>"</h3>
		<div class="form-group">
			<label for="moveTargetSelect"><?php p($t('modals.workspaces.select_target_workspace', 'Select target workspace')); ?></label>
			<select id="moveTargetSelect">
			</select>
		</div>
		<div class="buttons-with-margin" style="display: flex; justify-content: flex-end; gap: 8px;">
			<button data-action="close-move-modal" class="btn btn-secondary"><?php p($t('common.cancel')); ?></button>
			<button id="confirmMoveBtn" class="btn btn-primary"><?php p($t('workspaces.actions.move_notes', 'Move notes')); ?></button>
		</div>
	</div>
</div>

<!-- Rename modal (Settings > Workspaces) -->
<div id="renameModal" class="modal initially-hidden">
	<div class="modal-content">
		<h3><?php p($t('modals.workspaces.rename_title', 'Rename workspace')); ?> <span id="renameSource"></span></h3>
		<div class="form-group">
			<label for="renameNewName"><?php p($t('modals.workspaces.new_name', 'New name')); ?></label>
			<input id="renameNewName" type="text" />
		</div>
		<div class="buttons-with-margin">
			<button id="confirmRenameBtn" class="btn btn-primary"><?php p($t('common.rename', 'Rename')); ?></button>
			<button data-action="close-rename-modal" class="btn btn-secondary"><?php p($t('common.cancel')); ?></button>
		</div>
	</div>
</div>

<!-- Workspace tags modal (Settings > Workspaces): same list as the note
	 "Manage tags" modal (css/notes/tags.css), saved as a whole on Save -->
<div id="workspaceTagsModal" class="modal initially-hidden">
	<div class="modal-content">
		<h3><?php p($t('workspaces.tags.modal_title', 'Workspace tags')); ?> <span id="workspaceTagsSource"></span></h3>
		<p class="ws-tags-help"><?php p($t('workspaces.tags.help', 'Use tags to group workspaces on the dashboard.')); ?></p>
		<div class="modal-body">
			<div id="workspaceTagsList" class="tags-modal-list"></div>
			<div class="tags-modal-input-wrapper">
				<input id="workspaceTagsInput" type="text" autocomplete="off" placeholder="<?php p($t('tags.add_single', 'Add tag...')); ?>" />
			</div>
		</div>
		<div class="modal-buttons">
			<button type="button" class="btn-cancel" data-action="close-workspace-tags-modal"><?php p($t('common.cancel')); ?></button>
			<button type="button" class="btn-primary" id="confirmWorkspaceTagsBtn"><?php p($t('common.save', 'Save')); ?></button>
		</div>
	</div>
</div>

<!-- Workspace color modal (Settings > Workspaces): one swatch per entry of the
	 note color palette (Settings > Note colors) plus a custom color. The dot
	 marks the workspace's notes and folders on multi-workspace dashboard
	 views (css/workspaces.css, handleWorkspaceColorButtonClick in
	 js/workspaces-actions.js) -->
<div id="workspaceColorModal" class="modal initially-hidden">
	<div class="modal-content">
		<h3><?php p($t('workspaces.color.modal_title', 'Workspace color')); ?> <span id="workspaceColorSource"></span></h3>
		<p class="ws-tags-help"><?php p($t('workspaces.color.help', 'The color marks the notes and folders of this workspace on the dashboard when several workspaces are shown together.')); ?></p>
		<div class="modal-body">
			<div id="workspaceColorGrid" class="ws-color-grid">
				<?php foreach ($_['settingsPage']['palette'] as $paletteEntry): ?>
				<button type="button" class="ws-color-option" data-color="<?php p($paletteEntry['id']); ?>" data-hex="<?php p($paletteEntry['hex']); ?>" title="<?php p($paletteEntry['name']); ?>" aria-label="<?php p($paletteEntry['name']); ?>" aria-pressed="false">
					<span class="ws-color-swatch" style="background-color: <?php p($paletteEntry['hex']); ?>"></span>
				</button>
				<?php endforeach; ?>
				<label class="ws-color-option ws-color-option-custom" title="<?php p($t('workspaces.color.custom', 'Custom color')); ?>">
					<input type="color" id="workspaceColorCustom" aria-label="<?php p($t('workspaces.color.custom', 'Custom color')); ?>">
					<span class="ws-color-swatch ws-color-swatch-custom"></span>
				</label>
			</div>
		</div>
		<div class="modal-buttons">
			<button type="button" class="btn-danger" id="workspaceColorClearBtn"><?php p($t('note_color.remove', 'Remove color')); ?></button>
			<button type="button" class="btn-cancel" data-action="close-workspace-color-modal"><?php p($t('common.cancel')); ?></button>
			<button type="button" class="btn-primary" id="confirmWorkspaceColorBtn"><?php p($t('common.save', 'Save')); ?></button>
		</div>
	</div>
</div>

<!-- Delete confirmation modal (Settings > Workspaces) -->
<div id="deleteModal" class="modal initially-hidden">
	<div class="modal-content">
		<h3><?php p($t('modals.workspaces.delete_title', 'Confirm delete workspace')); ?> "<span id="deleteWorkspaceName"></span>"</h3>
		<?php // eigener Text: Inhalte wandern in einen eigenen Workspace (B18), nichts wird endgültig gelöscht ?>
		<p class="text-danger"><?php p($l->t('Enter the workspace name to confirm. Its notes and folders are moved to another of your workspaces.')); ?></p>
		<div class="form-group">
			<input id="confirmDeleteInput" type="text" placeholder="<?php p($t('modals.workspaces.delete_placeholder', 'Type workspace name to confirm')); ?>" />
		</div>
		<div class="buttons-with-margin workspace-delete-modal-actions">
			<button data-action="close-delete-modal" class="btn btn-secondary"><?php p($t('common.cancel')); ?></button>
			<button id="confirmDeleteBtn" class="btn btn-danger" disabled><?php p($t('modals.workspaces.delete_button', 'Delete workspace')); ?></button>
		</div>
	</div>
</div>

<!-- Colored markdown modal -->
<?php $modalsMdcResetLabel = $t('modals.markdown_colored.reset_element', 'Use the theme color'); ?>
<div id="markdownColoredModal" class="modal">
	<div class="modal-content">
		<h3><?php p($t('modals.markdown_colored.title', 'Colored markdown')); ?></h3>
		<div class="modal-body">
			<div class="radio-options">
				<label><input type="radio" name="markdownColoredTheme" value="0"> <?php p($t('modals.markdown_colored.options.off', 'Off')); ?></label>
				<label><input type="radio" name="markdownColoredTheme" value="custom"> <?php p($t('modals.markdown_colored.options.custom', 'Custom')); ?></label>
			</div>
			<div id="markdownColoredCustomRow" class="mdc-custom-row">
				<p class="modal-hint mdc-theme-hint"><?php p($t('modals.markdown_colored.theme_hint', 'Each color follows the theme until you change it. The arrow next to a changed color brings it back to the theme.')); ?></p>
				<label><span><?php p($t('modals.markdown_colored.elements.h1', 'Heading 1')); ?></span> <input type="color" id="markdownColoredH1Input" data-mdc-element="h1"><button type="button" class="mdc-reset" data-mdc-reset="h1" title="<?php p($modalsMdcResetLabel); ?>" aria-label="<?php p($modalsMdcResetLabel); ?>"><i class="lucide lucide-undo-2"></i></button></label>
				<label><span><?php p($t('modals.markdown_colored.elements.h2', 'Heading 2')); ?></span> <input type="color" id="markdownColoredH2Input" data-mdc-element="h2"><button type="button" class="mdc-reset" data-mdc-reset="h2" title="<?php p($modalsMdcResetLabel); ?>" aria-label="<?php p($modalsMdcResetLabel); ?>"><i class="lucide lucide-undo-2"></i></button></label>
				<label><span><?php p($t('modals.markdown_colored.elements.h3', 'Heading 3')); ?></span> <input type="color" id="markdownColoredH3Input" data-mdc-element="h3"><button type="button" class="mdc-reset" data-mdc-reset="h3" title="<?php p($modalsMdcResetLabel); ?>" aria-label="<?php p($modalsMdcResetLabel); ?>"><i class="lucide lucide-undo-2"></i></button></label>
				<label><span><?php p($t('modals.markdown_colored.elements.h4', 'Heading 4')); ?></span> <input type="color" id="markdownColoredH4Input" data-mdc-element="h4"><button type="button" class="mdc-reset" data-mdc-reset="h4" title="<?php p($modalsMdcResetLabel); ?>" aria-label="<?php p($modalsMdcResetLabel); ?>"><i class="lucide lucide-undo-2"></i></button></label>
				<label><span><?php p($t('modals.markdown_colored.elements.h5', 'Heading 5')); ?></span> <input type="color" id="markdownColoredH5Input" data-mdc-element="h5"><button type="button" class="mdc-reset" data-mdc-reset="h5" title="<?php p($modalsMdcResetLabel); ?>" aria-label="<?php p($modalsMdcResetLabel); ?>"><i class="lucide lucide-undo-2"></i></button></label>
				<label><span><?php p($t('modals.markdown_colored.elements.h6', 'Heading 6')); ?></span> <input type="color" id="markdownColoredH6Input" data-mdc-element="h6"><button type="button" class="mdc-reset" data-mdc-reset="h6" title="<?php p($modalsMdcResetLabel); ?>" aria-label="<?php p($modalsMdcResetLabel); ?>"><i class="lucide lucide-undo-2"></i></button></label>
				<label><span><?php p($t('modals.markdown_colored.elements.code', 'Inline code')); ?></span> <input type="color" id="markdownColoredCodeInput" data-mdc-element="code"><button type="button" class="mdc-reset" data-mdc-reset="code" title="<?php p($modalsMdcResetLabel); ?>" aria-label="<?php p($modalsMdcResetLabel); ?>"><i class="lucide lucide-undo-2"></i></button></label>
				<label><span><?php p($t('modals.markdown_colored.elements.codeblock', 'Code block background')); ?></span> <input type="color" id="markdownColoredCodeblockInput" data-mdc-element="codeblock"><button type="button" class="mdc-reset" data-mdc-reset="codeblock" title="<?php p($modalsMdcResetLabel); ?>" aria-label="<?php p($modalsMdcResetLabel); ?>"><i class="lucide lucide-undo-2"></i></button></label>
				<label><span><?php p($t('modals.markdown_colored.elements.quote', 'Quotes')); ?></span> <input type="color" id="markdownColoredQuoteInput" data-mdc-element="quote"><button type="button" class="mdc-reset" data-mdc-reset="quote" title="<?php p($modalsMdcResetLabel); ?>" aria-label="<?php p($modalsMdcResetLabel); ?>"><i class="lucide lucide-undo-2"></i></button></label>
				<label><span><?php p($t('modals.markdown_colored.elements.table', 'Table headers')); ?></span> <input type="color" id="markdownColoredTableInput" data-mdc-element="table"><button type="button" class="mdc-reset" data-mdc-reset="table" title="<?php p($modalsMdcResetLabel); ?>" aria-label="<?php p($modalsMdcResetLabel); ?>"><i class="lucide lucide-undo-2"></i></button></label>
				<label><span><?php p($t('modals.markdown_colored.elements.hr', 'Separators')); ?></span> <input type="color" id="markdownColoredHrInput" data-mdc-element="hr"><button type="button" class="mdc-reset" data-mdc-reset="hr" title="<?php p($modalsMdcResetLabel); ?>" aria-label="<?php p($modalsMdcResetLabel); ?>"><i class="lucide lucide-undo-2"></i></button></label>
			</div>
		</div>
		<div class="modal-buttons">
			<button type="button" class="btn-cancel" data-action="close-modal" data-modal="markdownColoredModal"><?php p($t('common.cancel')); ?></button>
			<button type="button" class="btn-primary" id="saveMarkdownColoredBtn"><?php p($t('common.save')); ?></button>
		</div>
	</div>
</div>

<!-- Note color palette editor -->
<div id="noteColorPaletteModal" class="modal">
	<div class="modal-content">
		<h3><?php p($t('modals.note_color_palette.title', 'Note colors')); ?></h3>
		<div class="modal-body">
			<p><?php p($t('modals.note_color_palette.description', 'Customize the colors you can assign to notes. Renaming or recoloring an entry updates every note using it.')); ?></p>
			<div id="noteColorPaletteList" class="note-palette-list"></div>
			<button type="button" class="note-palette-add" id="noteColorPaletteAddBtn">
				<i class="lucide lucide-plus"></i> <?php p($t('modals.note_color_palette.add', 'Add color')); ?>
			</button>
		</div>
		<div class="modal-buttons">
			<a class="note-palette-back" href="<?php p($_['baseUrl'] . 'dashboard.php?' . http_build_query(['workspace' => $_['frame']['workspaceKey']])); ?>"><?php p($t('common.back_to_home', 'Dashboard')); ?></a>
			<button type="button" class="btn-secondary" id="noteColorPaletteResetBtn"><?php p($t('modals.note_color_palette.reset', 'Reset to defaults')); ?></button>
			<button type="button" class="btn-cancel" data-action="close-modal" data-modal="noteColorPaletteModal"><?php p($t('common.cancel')); ?></button>
			<button type="button" class="btn-primary" id="saveNoteColorPaletteBtn"><?php p($t('common.save')); ?></button>
		</div>
	</div>
</div>

<!-- Snapshots settings modal: ohne Sicherheitsversionen (kein KI/MCP) und ohne Anhang-Hinweis (Anhänge hängen nicht an Versionen) -->
<div id="snapshotsSettingsModal" class="modal">
	<div class="modal-content">
		<h3><?php p($t('modals.snapshots.title', 'Revisions')); ?></h3>
		<div class="modal-body">
			<p><?php p($t('modals.snapshots.description', 'An automatic revision is saved when a note changes, at most one every {{minutes}} minutes, and all of them are kept for {{hours}} hours. After that, one per day is kept, for this number of days. Revisions you save yourself are not counted. Every revision expires after {{days}} days:', ['days' => \OCA\NextNotes\Service\SnapshotService::MAX_AGE_DAYS, 'minutes' => (int)round(\OCA\NextNotes\Service\SnapshotService::AUTO_INTERVAL / 60), 'hours' => \OCA\NextNotes\Service\SnapshotService::DENSE_HOURS])); ?></p>
			<div class="radio-options">
				<label><input type="number" id="snapshotsKeepCountInput" min="1" max="<?php p((string)\OCA\NextNotes\Service\SnapshotService::MAX_AGE_DAYS); ?>" step="1" value="<?php p((string)\OCA\NextNotes\Service\SnapshotService::DEFAULT_KEEP_DAYS); ?>" style="width:80px; margin:0; padding:4px 8px;"> <?php p($t('modals.snapshots.options.unit', 'days, one automatic revision per day')); ?></label>
			</div>
			<div class="delete-warning-box">
				<p class="delete-warning"><?php p($t('modals.snapshots.warning_title', 'Revisions only store the note text. Attachments and images are never copied.')); ?></p>
			</div>
		</div>
		<div class="modal-buttons">
			<button type="button" class="btn-cancel" data-action="close-modal" data-modal="snapshotsSettingsModal"><?php p($t('common.cancel')); ?></button>
			<button type="button" class="btn-primary" id="saveSnapshotsSettingsModalBtn"><?php p($t('common.save')); ?></button>
		</div>
	</div>
</div>

<!-- Date and time format modal -->
<div id="dateTimeFormatModal" class="modal">
	<div class="modal-content">
		<h3><?php p($t('modals.date_time_format.title', 'Date & time format')); ?></h3>
		<div class="modal-body">
			<p><?php p($t('modals.date_time_format.description', 'Choose how dates and times are displayed:')); ?></p>
			<div class="radio-options">
				<label><input type="radio" name="dateTimeFormat" value="default"> <?php p($t('modals.date_time_format.options.default', 'YYYY-MM-DD HH:mm')); ?></label>
				<label><input type="radio" name="dateTimeFormat" value="ymd_his"> <?php p($t('modals.date_time_format.options.ymd_his', 'YYYY-MM-DD HH:mm:ss')); ?></label>
				<label><input type="radio" name="dateTimeFormat" value="dmy_hi"> <?php p($t('modals.date_time_format.options.dmy_hi', 'DD/MM/YYYY HH:mm')); ?></label>
				<label><input type="radio" name="dateTimeFormat" value="mdy_hia"> <?php p($t('modals.date_time_format.options.mdy_hia', 'MM/DD/YYYY hh:mm AM/PM')); ?></label>
				<label><input type="radio" name="dateTimeFormat" value="long"> <?php p($t('modals.date_time_format.options.long', 'Saturday, September 12, 2026 14:05')); ?></label>
				<label><input type="radio" name="dateTimeFormat" value="custom"> <?php p($t('modals.date_time_format.options.custom', 'Custom')); ?></label>
				<input type="text" id="dateTimeFormatCustomInput" maxlength="80" placeholder="<?php p($t('modals.date_time_format.custom_placeholder', 'YYYY-MM-DD HH:mm:ss')); ?>" style="width: 100%; box-sizing: border-box; margin: 4px 0 0 24px;">
				<div class="date-format-tokens-wrap" style="margin: 4px 0 0 24px;">
					<small style="display: block; color: #6b7280;"><?php p($t('modals.date_time_format.tokens_title', 'Tokens:')); ?></small>
					<?php $legend('date_time_format'); ?>
				</div>
			</div>
		</div>
		<div class="modal-buttons">
			<button type="button" class="btn-cancel" data-action="close-modal" data-modal="dateTimeFormatModal"><?php p($t('common.cancel')); ?></button>
			<button type="button" class="btn-primary" id="saveDateTimeFormatModalBtn"><?php p($t('common.save')); ?></button>
		</div>
	</div>
</div>

<!-- Diary entry date format modal -->
<div id="diaryDateFormatModal" class="modal">
	<div class="modal-content">
		<h3><?php p($t('modals.diary_date_format.title', 'Diary entry date format')); ?></h3>
		<div class="modal-body">
			<p><?php p($t('modals.diary_date_format.description', 'Choose the date format used to title new diary entries:')); ?></p>
			<div class="radio-options">
				<label><input type="radio" name="diaryDateFormat" value="ymd"> <?php p($t('modals.diary_date_format.options.ymd', 'YYYY-MM-DD')); ?></label>
				<label><input type="radio" name="diaryDateFormat" value="dmy_slash"> <?php p($t('modals.diary_date_format.options.dmy_slash', 'DD/MM/YYYY')); ?></label>
				<label><input type="radio" name="diaryDateFormat" value="mdy_slash"> <?php p($t('modals.diary_date_format.options.mdy_slash', 'MM/DD/YYYY')); ?></label>
				<label><input type="radio" name="diaryDateFormat" value="dmy_dot"> <?php p($t('modals.diary_date_format.options.dmy_dot', 'DD.MM.YYYY')); ?></label>
				<label><input type="radio" name="diaryDateFormat" value="ymd_slash"> <?php p($t('modals.diary_date_format.options.ymd_slash', 'YYYY/MM/DD')); ?></label>
				<label><input type="radio" name="diaryDateFormat" value="long"> <?php p($t('modals.diary_date_format.options.long', 'Saturday, September 12, 2026')); ?></label>
				<label><input type="radio" name="diaryDateFormat" value="custom"> <?php p($t('modals.diary_date_format.options.custom', 'Custom')); ?></label>
				<input type="text" id="diaryDateFormatCustomInput" maxlength="80" placeholder="<?php p($t('modals.diary_date_format.custom_placeholder', 'DD MMMM YYYY')); ?>" style="width: 100%; box-sizing: border-box; margin: 4px 0 0 24px;">
				<div class="date-format-tokens-wrap" style="margin: 4px 0 0 24px;">
					<small style="display: block; color: #6b7280;"><?php p($t('modals.diary_date_format.tokens_title', 'Tokens:')); ?></small>
					<?php $legend('diary_date_format'); ?>
					<small style="display: block; color: #6b7280;"><?php p($t('modals.diary_date_format.tokens_required', 'A year, a month and a day are required.')); ?></small>
				</div>
			</div>
			<small style="display: block; margin: 8px 0 16px 0; color: #6b7280;"><?php p($t('modals.diary_date_format.hint', 'Existing entries keep their title and stay linked to their day.')); ?></small>
		</div>
		<div class="modal-buttons">
			<button type="button" class="btn-cancel" data-action="close-modal" data-modal="diaryDateFormatModal"><?php p($t('common.cancel')); ?></button>
			<button type="button" class="btn-primary" id="saveDiaryDateFormatModalBtn"><?php p($t('common.save')); ?></button>
		</div>
	</div>
</div>

<!-- Information entry of a row's menu (workspaces-share.js fills it) -->
<div id="workspaceInfoModal" class="modal initially-hidden">
	<div class="modal-content workspace-info-modal-content">
		<h3><i class="lucide lucide-info"></i> <span id="workspaceInfoTitle"></span></h3>
		<dl class="workspace-info-list">
			<div><dt><?php p($t('workspaces.info.notes', 'Notes')); ?></dt><dd id="workspaceInfoNotes"></dd></div>
			<div><dt><?php p($t('workspaces.info.folders', 'Folders')); ?></dt><dd id="workspaceInfoFolders"></dd></div>
			<div><dt><?php p($t('workspaces.info.tags', 'Tags')); ?></dt><dd id="workspaceInfoTags"></dd></div>
			<div><dt><?php p($t('workspaces.info.shared', 'Shared')); ?></dt><dd id="workspaceInfoShared"></dd></div>
			<div><dt><?php p($t('workspaces.info.shared_with', 'Shared with')); ?></dt><dd id="workspaceInfoSharedWith"></dd></div>
		</dl>
		<div class="modal-buttons">
			<button type="button" class="btn-cancel" data-action="close-workspace-info-modal"><?php p($t('common.close', 'Close')); ?></button>
		</div>
	</div>
</div>

<!-- Background Image Settings Modal -->
<div id="backgroundImageModal" class="modal">
	<div class="modal-content">
		<div class="modal-header">
			<h3><?php p($t('modals.background_image.title', 'Background Image Settings')); ?></h3>
		</div>
		<div class="modal-body">
			<p><?php p($t('modals.background_image.description', 'Upload a background image for your workspace. You can adjust the opacity to make it subtle.')); ?></p>

			<div class="background-preview-container" id="backgroundPreviewContainer">
				<div class="background-preview" id="backgroundPreview">
					<p class="no-background-text"><?php p($t('modals.background_image.no_image', 'No background image set')); ?></p>
				</div>
			</div>

			<div class="background-upload-controls">
				<input type="file" id="backgroundImageInput" accept="image/jpeg,image/jpg,image/png,image/gif,image/webp" hidden>
				<button type="button" class="btn-secondary" id="uploadBackgroundBtn">
					<?php p($t('modals.background_image.upload', 'Upload Image')); ?>
				</button>
				<button type="button" class="btn-danger" id="removeBackgroundBtn" style="display: none;">
					<?php p($t('modals.background_image.remove', 'Remove')); ?>
				</button>
			</div>

			<div class="background-opacity-control">
				<label for="backgroundOpacityInput">
					<?php p($t('modals.background_image.opacity', 'Background Opacity')); ?>:
					<span id="backgroundOpacityValue">30</span>%
				</label>
				<input type="range" id="backgroundOpacityInput" min="5" max="25" step="1" value="25">
			</div>
		</div>
		<div class="modal-buttons">
			<button type="button" class="btn-cancel" id="cancelBackgroundBtn"><?php p($t('common.cancel')); ?></button>
			<button type="button" class="btn-primary" id="saveBackgroundBtn"><?php p($t('common.save')); ?></button>
		</div>
	</div>
</div>
