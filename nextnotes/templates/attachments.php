<?php
/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// abgeleitet von src/public/attachments.php @ 7e2e0b6aa501ba95ac1e9789fd2bb477c281a001
// Anhänge einer Notiz (attachments-page.js, Anhang-API). Daten: PageController::attachments, NotePageViewService.
// Abweichungen: ohne Icon-Leiste (NC-Rahmen), Git-Sync/Offline/Spracheingabe gestrichen, <body>-Texte über die
// JSON-Insel nextnotes-body (Adapter), Inline-<style> in nextnotes-layout.css, ohne Schreibrecht kein Hochladen.
/** @var array $_ */
/** @var \OCA\NextNotes\Service\View\PageTranslator $tr */
$tr = $_['tr'];
$page = $_['page'];
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR;
$txt = [
	'uploading' => ['attachments.upload.button_uploading', [], 'Uploading...'],
	'select-file' => ['attachments.errors.select_file', [], 'Please select a file to upload.'],
	'file-too-large' => ['attachments.errors.file_too_large', ['maxSize' => '200MB'], 'The file is too large (max: {{maxSize}}).'],
	'upload-success' => ['attachments.messages.upload_success', [], 'File uploaded successfully!'],
	'upload-failed-prefix' => ['attachments.errors.upload_failed', ['error' => '{{error}}'], 'Upload failed: {{error}}'],
	'upload-failed-generic' => ['attachments.errors.upload_failed_generic', [], 'Upload failed. Please try again.'],
	'upload-failed-connection' => ['attachments.errors.upload_failed_connection', [], 'Upload failed. Please check your connection.'],
	'loading-failed' => ['attachments.errors.loading_failed', [], 'Failed to load attachments'],
	'loading-error' => ['attachments.errors.loading_error', [], 'Error loading attachments'],
	'no-attachments' => ['attachments.empty', [], 'No attachments.'],
	'preview-alt' => ['attachments.page.preview_alt', [], 'Preview'],
	'uploaded-prefix' => ['attachments.page.uploaded_prefix', [], 'Uploaded: '],
	'view' => ['attachments.actions.view', [], 'View'],
	'delete' => ['attachments.actions.delete', [], 'Delete'],
	'open-new-tab' => ['attachments.page.open_in_new_tab', [], 'Open in new tab'],
	'download' => ['common.download', [], 'Download'],
	'move' => ['attachments.actions.move', [], 'Move to another note'],
	'move-title' => ['attachments.move.title', [], 'Move attachment'],
	'move-description' => ['attachments.move.description', ['filename' => '{{filename}}'], 'Choose the note that receives "{{filename}}".'],
	'move-description-unnamed' => ['attachments.move.description_unnamed', [], 'Choose the note that receives this attachment.'],
	'move-search' => ['attachments.move.search_placeholder', [], 'Search for a note...'],
	'move-empty' => ['attachments.move.empty', [], 'No other note found'],
	'move-moving' => ['attachments.move.moving', [], 'Moving...'],
	'move-kept' => ['attachments.move.kept_notice', [], 'A copy stays in this note, whose content uses the file.'],
	'move-success' => ['attachments.messages.moved_success', ['heading' => '{{heading}}'], 'Attachment moved to "{{heading}}"'],
	'move-failed-prefix' => ['attachments.errors.move_failed', ['error' => '{{error}}'], 'Move failed: {{error}}'],
	'move-failed-generic' => ['attachments.errors.move_failed_generic', [], 'Move failed.'],
	'notes-loading-error' => ['note_reference.error.loading_notes', [], 'Error loading notes'],
	'loading' => ['common.loading', [], 'Loading...'],
	'untitled' => ['note_reference.untitled', [], 'Untitled'],
	'pdf-label' => ['attachments.page.pdf_label', [], 'PDF'],
	'deleted-success' => ['attachments.messages.deleted_success', [], 'Attachment deleted successfully'],
	'delete-failed-prefix' => ['attachments.errors.deletion_failed', ['error' => '{{error}}'], 'Deletion failed: {{error}}'],
	'delete-failed-generic' => ['attachments.errors.deletion_failed_generic', [], 'Deletion failed.'],
	'confirm-action' => ['common.confirm_action', [], 'Confirm Action'],
	'delete-button' => ['common.delete', [], 'Delete'],
	'cancel' => ['common.cancel', [], 'Cancel'],
];
$data = ['note-id' => (string)$page['note']['id'], 'note-type' => $page['note']['type'], 'workspace' => $page['workspace'],
	'filesize-units' => json_encode([$tr->t('attachments.size.units.bytes', [], 'bytes'), $tr->t('attachments.size.units.kb', [], 'KB'),
		$tr->t('attachments.size.units.mb', [], 'MB'), $tr->t('attachments.size.units.gb', [], 'GB')], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)];
foreach ($txt as $name => [$key, $vars, $default]) {
	$data['txt-' . $name] = $tr->t($key, $vars, $default);
}
$islands = [
	'poznote-config' => $_['poznoteConfig'],
	'nextnotes-body' => ['classes' => $page['canWrite'] ? ['nextnotes-attachments-page'] : ['nextnotes-attachments-page', 'nextnotes-readonly'], 'data' => $data],
];
?>
<div id="app-content">
<div id="nextnotes-root" class="nextnotes-subpage">
	<?php foreach ($islands as $islandId => $islandData): ?>
	<script type="application/json" id="<?php p($islandId); ?>"><?php print_unescaped(json_encode($islandData, $jsonFlags)); ?></script>
	<?php endforeach; ?>
	<div class="poznote-back-to-note-bar attachments-back-to-note-bar">
		<a href="<?php p($page['backUrl']); ?>" class="poznote-back-to-note-btn"><i class="lucide lucide-arrow-left"></i> <?php p($tr->t('info.actions.back_to_note', [], 'Go back to the note')); ?></a>
	</div>

	<div class="settings-container">
		<?php if ($page['canWrite']): ?>
		<div class="settings-section">
			<h3><?php p($tr->t('attachments.page.upload_section_title')); ?></h3>
			<div class="attachment-upload-section">
				<div class="drag-drop-info"><?php p($tr->t('attachments.page.drag_drop_info')); ?></div><br>
				<div class="form-group">
					<input type="file" id="attachmentFile" class="file-input">
					<div class="accepted-types"><?php p($tr->t('attachments.page.all_types_accepted')); ?></div>
					<br>
					<div class="selected-filename" id="selectedFileName"></div>
				</div>
				<button type="button" class="btn btn-primary" id="uploadBtn" disabled><?php p($tr->t('attachments.page.upload_button')); ?></button>
			</div>
			<div id="uploadProgress" class="upload-progress initially-hidden">
				<div class="progress-bar"><div class="progress-fill" id="progressFill"></div></div>
				<div class="progress-text" id="progressText"><?php p($tr->t('attachments.upload.button_uploading', [], 'Uploading...')); ?></div>
			</div>
		</div>
		<?php endif; ?>

		<div class="settings-section">
			<h3><?php p($tr->t('attachments.page.current_attachments', [], 'Current Attachments')); ?></h3>
			<div id="attachmentsList" class="attachments-display">
				<div class="loading-attachments"><?php p($tr->t('attachments.page.loading_attachments', [], 'Loading attachments...')); ?></div>
			</div>
		</div>
		<div class="section-bottom-spacer"></div>
	</div>

	<div id="deleteAttachmentConfirmModal" class="modal">
		<div class="modal-content">
			<h3><?php p($tr->t('attachments.modals.delete.title', [], 'Delete Attachment')); ?></h3>
			<p><?php p($tr->t('attachments.modals.delete.message', [], 'Do you want to delete this attachment? This action cannot be undone.')); ?></p>
			<div class="modal-buttons">
				<button type="button" class="btn-cancel"><?php p($tr->t('common.cancel')); ?></button>
				<button type="button" class="btn-danger"><?php p($tr->t('attachments.actions.delete', [], 'Delete')); ?></button>
			</div>
		</div>
	</div>
</div>
</div>
