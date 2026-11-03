<?php
/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// abgeleitet von src/note_display.php @ 7e2e0b6aa501ba95ac1e9789fd2bb477c281a001
// Geöffnete Notiz in #right_col. Daten: $note (IndexViewService::noteView), Inhalt beim Speichern durch HtmlSanitizer geprüft.
// Weggelassen: Teilen/öffentlicher Link (gestrichen), Download/Drucken (Export M4), Umwandeln (501 bis M4),
// Revisionen und Notiz-Info (revisions.php/api_note_info.php M3c), Aufgabenseite (M4), Audio-Aufnahme,
// Excalidraw-Platzhalter-Bereinigung (kein Altbestand). Anhang-Vorschauen rendert attachments.js beim Laden.
/** @var \OCA\NextNotes\Service\View\PageTranslator $tr */
/** @var array $note */
$id = (string)$note['id'];
$type = $note['type'];
// Lesefreigabe: nur lesbar, ohne Umweg über Sperre-403/Banner
$editable = $type !== 'markdown' && $note['canWrite'] ? 'true' : 'false';
$prose = $type === 'note' || $type === 'markdown';
$btn = static function (string $class, string $action, string $icon, string $title, bool $withId = true) use ($id): void {
	?><button type="button" class="toolbar-btn <?php p($class); ?>" title="<?php p($title); ?>" data-action="<?php p($action); ?>"<?php if ($withId): ?> data-note-id="<?php p($id); ?>"<?php endif; ?>><i class="lucide <?php p($icon); ?>"></i></button><?php
};
// Anhangzeile (Upstream $attachment_links_html), nur ohne Vorschauen; Klick lädt herunter (index-events.js)
$attachmentsRow = static function () use ($note, $tr, $id): void {
	if ($note['attachmentLinks'] === []) {
		return;
	}
	$shown = count(array_filter($note['attachmentLinks'], static fn (array $a) => !$a['inline']));
	$open = $tr->t('attachments.actions.open_attachments', [], 'Open attachments');
	?><div class="note-attachments-row"<?php if ($shown === 0): ?> style="display: none;"<?php endif; ?>><button type="button" class="icon-attachment-btn" title="<?php p($open); ?>" data-action="show-attachment-dialog" data-note-id="<?php p($id); ?>" aria-label="<?php p($open); ?>"><span class="lucide lucide-paperclip icon_attachment"></span></button><span class="note-attachments-list"><?php foreach ($note['attachmentLinks'] as $i => $a): ?><?php if ($i > 0): ?> <?php endif; ?><a href="#" class="attachment-link"<?php if ($a['inline']): ?> data-is-inline-image="true" style="display: none;"<?php endif; ?> data-action="download-attachment" data-attachment-id="<?php p((string)$a['id']); ?>" data-note-id="<?php p($id); ?>" title="<?php p($tr->t('attachments.actions.download', ['filename' => $a['name']], 'Download {{filename}}')); ?>"><?php p($a['name']); ?></a><?php endforeach; ?></span></div><?php
};
$item = static function (string $action, string $icon, string $label, array $data = []) use ($id): void {
	?><button type="button" class="dropdown-item mobile-toolbar-item" role="menuitem" data-action="<?php p($action); ?>"<?php foreach ($data as $k => $v): ?> data-<?php p($k); ?>="<?php p($v); ?>"<?php endforeach; ?>><i class="lucide <?php p($icon); ?>"></i> <?php p($label); ?></button><?php
};
?>
<div id="note<?php p($id); ?>" class="notecard"<?php if ($note['contentWidth'] !== null): ?> data-content-width="<?php p((string)$note['contentWidth']); ?>" style="--note-content-width: <?php p((string)$note['contentWidth']); ?>%"<?php endif; ?>>
<div class="innernote"<?php if ($type === 'markdown'): ?> data-markdown-note="true"<?php endif; ?><?php if ($type === 'tasklist'): ?> data-tasklist-note="true"<?php endif; ?>>
<div class="note-header">
<div class="note-edit-toolbar">
	<button type="button" id="note-history-back" class="toolbar-btn btn-history-nav btn-history-back history-disabled" title="<?php p($tr->t('editor.toolbar.go_back', [], 'Go back')); ?>" disabled><i class="lucide lucide-circle-chevron-left"></i></button>
	<?php $btn('btn-home mobile-home-btn', 'scroll-to-left-column', 'lucide-home', $tr->t('editor.toolbar.back_to_notes'), false); ?>
	<?php $btn('btn-bold text-format-btn', 'exec-bold', 'lucide-bold', $tr->t('editor.toolbar.bold'), false); ?>
	<?php $btn('btn-italic text-format-btn', 'exec-italic', 'lucide-italic', $tr->t('editor.toolbar.italic'), false); ?>
	<?php $btn('btn-underline text-format-btn', 'exec-underline', 'lucide-underline', $tr->t('editor.toolbar.underline'), false); ?>
	<?php $btn('btn-strikethrough text-format-btn', 'exec-strikethrough', 'lucide-strikethrough', $tr->t('editor.toolbar.strikethrough'), false); ?>
	<?php $btn('btn-link text-format-btn', 'add-link', 'lucide-link', $tr->t('editor.toolbar.link'), false); ?>
	<?php $btn('btn-color text-format-btn', 'toggle-red-color', 'lucide-palette', $tr->t('editor.toolbar.text_color'), false); ?>
	<?php $btn('btn-highlight text-format-btn', 'toggle-yellow-highlight', 'lucide-paintbrush', $tr->t('editor.toolbar.highlight'), false); ?>
	<?php $btn('btn-list-ul text-format-btn', 'exec-unordered-list', 'lucide-list-ul', $tr->t('editor.toolbar.bullet_list'), false); ?>
	<?php $btn('btn-list-ol text-format-btn', 'exec-ordered-list', 'lucide-list-ol', $tr->t('editor.toolbar.numbered_list'), false); ?>
	<?php if ($prose): ?><?php $btn('btn-task-list text-format-btn', 'exec-task-list', 'lucide-list-check', $tr->t('editor.toolbar.toggle_checklist', [], 'Toggle checklist'), false); ?><?php endif; ?>
	<?php if ($type === 'markdown'): ?><?php $btn('btn-task-remove text-format-btn', 'exec-task-remove', 'lucide-minus-square', $tr->t('editor.toolbar.remove_checklist', [], 'Remove checkboxes'), false); ?><?php endif; ?>
	<?php $btn('btn-text-height text-format-btn', 'change-font-size', 'lucide-type-height', $tr->t('slash_menu.title', [], 'Title'), false); ?>
	<?php if ($prose): ?><?php $btn('btn-align text-format-btn', 'change-alignment', 'lucide-align-center', $tr->t('slash_menu.align', [], 'Align'), false); ?><?php endif; ?>
	<?php $btn('btn-code text-format-btn', 'toggle-code-block', 'lucide-code', $tr->t('editor.toolbar.code_block'), false); ?>
	<?php $btn('btn-inline-code text-format-btn', 'toggle-inline-code', 'lucide-terminal', $tr->t('editor.toolbar.inline_code'), false); ?>
	<?php $btn('btn-eraser text-format-btn', 'exec-remove-format', 'lucide-eraser', $tr->t('editor.toolbar.clear_formatting'), false); ?>
	<?php if ($prose): ?><?php $btn('btn-search-replace-format text-format-btn', 'open-search-replace-modal', 'lucide-search', $tr->t('editor.toolbar.search_replace', [], 'Search and replace')); ?><?php endif; ?>
	<?php if ($type === 'tasklist'): ?>
	<div class="tasklist-actions-dropdown">
		<button type="button" class="toolbar-btn btn-tasklist-actions note-action-btn" title="<?php p($tr->t('tasklist.actions', [], 'Task list actions')); ?>" data-action="toggle-tasklist-actions" data-note-id="<?php p($id); ?>" aria-haspopup="true" aria-expanded="false"><i class="lucide lucide-check-square"></i></button>
		<div id="tasklist-actions-menu-<?php p($id); ?>" class="dropdown-menu tasklist-actions-menu" hidden>
			<button type="button" class="dropdown-item" data-action="clear-completed-tasks" data-note-id="<?php p($id); ?>"><i class="lucide lucide-trash"></i> <?php p($tr->t('tasklist.clear_completed', [], 'Clear completed tasks')); ?></button>
			<button type="button" class="dropdown-item" data-action="uncheck-all-tasks" data-note-id="<?php p($id); ?>"><i class="lucide lucide-square"></i> <?php p($tr->t('tasklist.uncheck_all', [], 'Uncheck all tasks')); ?></button>
		</div>
	</div>
	<?php endif; ?>
	<?php $btn('btn-checklist note-action-btn', 'insert-checklist', 'lucide-list-check', $tr->t('editor.toolbar.insert_checklist'), false); ?>
	<?php $btn('btn-save note-action-btn', 'save-note', 'lucide-save', $tr->t('editor.toolbar.save_now', [], 'Save now')); ?>
	<?php $btn('btn-favorite note-action-btn' . ($note['favorite'] ? ' is-favorite' : ''), 'toggle-favorite', 'lucide-star', $note['favorite'] ? $tr->t('index.toolbar.favorite_remove', [], 'Remove from favorites') : $tr->t('index.toolbar.favorite_add', [], 'Add to favorites')); ?>
	<?php $btn('btn-attachment note-action-btn' . ($note['attachments'] > 0 ? ' has-attachments' : ''), 'show-attachment-dialog', 'lucide-paperclip', $tr->t('index.toolbar.attachments_with_count', ['count' => $note['attachments']], 'Attachments ({{count}})')); ?>
	<button type="button" class="toolbar-btn btn-reminder note-action-btn<?php p($note['reminderAt'] !== '' ? ' has-reminder' : ''); ?>" title="<?php p($tr->t('reminder.toolbar_button', [], 'Set reminder')); ?>" data-action="open-reminder-modal" data-note-id="<?php p($id); ?>" data-reminder-at="<?php p($note['reminderAt']); ?>"><i class="lucide lucide-bell"></i></button>
	<?php $btn('btn-open-new-tab note-action-btn', 'open-note-new-tab', 'lucide-external-link', $tr->t('editor.toolbar.open_in_new_tab', [], 'Open in a tab')); ?>
	<?php $btn('btn-duplicate note-action-btn', 'duplicate-note', 'lucide-copy', $tr->t('common.duplicate', [], 'Duplicate')); ?>
	<?php $btn('btn-move note-action-btn', 'show-move-folder-dialog', 'lucide-folder-output', $tr->t('common.move', [], 'Move')); ?>
	<?php if ($type !== 'linked' && !$note['hasLinked']): ?><?php $btn('btn-create-linked-note note-action-btn', 'create-linked-note', 'lucide-link', $tr->t('editor.toolbar.create_linked_note'), false); ?><?php endif; ?>
	<?php if ($prose): ?><?php $btn('btn-search-replace note-action-btn', 'open-search-replace-modal', 'lucide-search', $tr->t('editor.toolbar.search_replace', [], 'Search and replace')); ?><?php endif; ?>
	<button type="button" id="note-history-forward" class="toolbar-btn btn-history-nav btn-history-forward history-disabled" title="<?php p($tr->t('editor.toolbar.go_forward', [], 'Go forward')); ?>" disabled><i class="lucide lucide-circle-chevron-right"></i></button>
	<?php $btn('btn-trash note-action-btn', 'delete-note', 'lucide-trash-2', $tr->t('common.delete', [], 'Delete')); ?>
	<div class="toolbar-menu-anchor">
		<button type="button" class="toolbar-btn mobile-more-btn note-action-btn" title="<?php p($tr->t('common.menu', [], 'Menu')); ?>" data-action="toggle-mobile-toolbar-menu" aria-haspopup="true" aria-expanded="false"><i class="lucide lucide-more-vertical"></i></button>
		<div class="dropdown-menu mobile-toolbar-menu" hidden role="menu" aria-label="<?php p($tr->t('index.toolbar.menu_actions', [], 'Menu actions')); ?>">
			<?php if ($prose): ?><?php $item('trigger-mobile-action', 'lucide-search', $tr->t('editor.toolbar.search_replace', [], 'Search and replace'), ['selector' => '.btn-search-replace']); ?><?php endif; ?>
			<?php if ($type === 'tasklist'): ?>
			<?php $item('clear-completed-tasks', 'lucide-check-square', $tr->t('tasklist.clear_completed', [], 'Clear completed tasks'), ['note-id' => $id]); ?>
			<?php $item('uncheck-all-tasks', 'lucide-square', $tr->t('tasklist.uncheck_all', [], 'Uncheck all tasks'), ['note-id' => $id]); ?>
			<?php endif; ?>
			<?php if ($type !== 'linked' && !$note['hasLinked']): ?><?php $item('trigger-mobile-action', 'lucide-link', $tr->t('editor.toolbar.create_linked_note'), ['selector' => '.btn-create-linked-note']); ?><?php endif; ?>
			<?php $item('trigger-mobile-action', 'lucide-copy', $tr->t('common.duplicate', [], 'Duplicate'), ['selector' => '.btn-duplicate']); ?>
			<?php $item('trigger-mobile-action', 'lucide-folder-output', $tr->t('common.move', [], 'Move'), ['selector' => '.btn-move']); ?>
			<?php if ($type !== 'linked'): ?>
			<button type="button" class="dropdown-item mobile-toolbar-item speed-dial-state-on" role="menuitem" data-action="toggle-speed-dial" data-note-id="<?php p($id); ?>" style="display: none;"><i class="lucide lucide-zap"></i> <?php p($tr->t('speed_dial.remove', [], 'Remove from speed dial')); ?></button>
			<?php $item('toggle-speed-dial', 'lucide-zap', $tr->t('speed_dial.add', [], 'Add to speed dial'), ['note-id' => $id]); ?>
			<?php endif; ?>
			<?php if (!$note['archive']): ?><?php $item('archive-note', 'lucide-archive', $tr->t('archive.menu_item', [], 'Archive note'), ['note-id' => $id, 'note-title' => $note['heading']]); ?><?php endif; ?>
		</div>
	</div>
</div>
<?php if ($prose): ?>
<div class="search-replace-bar" id="searchReplaceBar<?php p($id); ?>" style="display: none;">
	<div class="search-replace-controls">
		<button type="button" class="search-replace-btn search-replace-toggle-btn" id="searchToggleReplaceBtn<?php p($id); ?>" title="<?php p($tr->t('search_replace.toggle_replace', [], 'Toggle replace')); ?>" aria-expanded="false"><i class="lucide lucide-chevron-down"></i></button>
		<div class="search-replace-input-group">
			<input type="text" class="search-replace-input" id="searchInput<?php p($id); ?>" placeholder="<?php p($tr->t('search_replace.search_placeholder', [], 'Find...')); ?>" autocomplete="off">
			<span class="search-replace-count" id="searchCount<?php p($id); ?>"></span>
		</div>
		<div class="search-replace-buttons">
			<button type="button" class="search-replace-btn search-replace-prev-btn" id="searchPrevBtn<?php p($id); ?>" title="<?php p($tr->t('search_replace.previous', [], 'Previous')); ?>"><i class="lucide lucide-chevron-left"></i></button>
			<button type="button" class="search-replace-btn search-replace-next-btn" id="searchNextBtn<?php p($id); ?>" title="<?php p($tr->t('search_replace.next', [], 'Next')); ?>"><i class="lucide lucide-chevron-right"></i></button>
			<button type="button" class="search-replace-btn search-replace-close-btn" id="searchCloseBtn<?php p($id); ?>" title="<?php p($tr->t('search_replace.close', [], 'Close')); ?>"><i class="lucide lucide-x"></i></button>
		</div>
	</div>
	<div class="search-replace-replace-row" id="searchReplaceRow<?php p($id); ?>">
		<div class="search-replace-input-group">
			<input type="text" class="search-replace-input" id="replaceInput<?php p($id); ?>" placeholder="<?php p($tr->t('search_replace.replace_placeholder', [], 'Replace...')); ?>" autocomplete="off">
		</div>
		<div class="search-replace-buttons">
			<button type="button" class="search-replace-btn" id="replaceBtn<?php p($id); ?>" title="<?php p($tr->t('search_replace.replace_one', [], 'Replace')); ?>"><?php p($tr->t('search_replace.replace', [], 'Replace')); ?></button>
			<button type="button" class="search-replace-btn" id="replaceAllBtn<?php p($id); ?>" title="<?php p($tr->t('search_replace.replace_all', [], 'Replace All')); ?>"><?php p($tr->t('search_replace.replace_all', [], 'All')); ?></button>
		</div>
	</div>
</div>
<?php endif; ?>
</div>
<div class="note-tags-row">
	<div class="folder-wrapper">
		<span class="lucide lucide-folder icon_folder cursor-pointer" data-action="show-move-folder-dialog" data-note-id="<?php p($id); ?>" title="<?php p($tr->t('modals.folder.change_folder', [], 'Change folder')); ?>"></span>
		<?php if ($note['folderPath'] !== []): ?>
		<span class="folder_name folder-breadcrumb"><?php foreach ($note['folderPath'] as $i => $segment): ?><?php if ($i > 0): ?><span class="folder-path-separator">/</span><?php endif; ?><span class="folder-path-segment" data-action="reveal-folder-in-tree" data-folder-id="<?php p((string)$segment['id']); ?>" title="<?php p($tr->t('index.folder_path.reveal_in_list', [], 'Show in folder list')); ?>"><?php p($segment['name']); ?></span><?php endforeach; ?></span>
		<?php else: ?>
		<span class="folder_name cursor-pointer" data-action="show-move-folder-dialog" data-note-id="<?php p($id); ?>" title="<?php p($tr->t('modals.folder.change_folder', [], 'Change folder')); ?>"><?php p($tr->t('modals.folder.no_folder', [], 'No folder')); ?></span>
		<?php endif; ?>
	</div>
	<div class="tag-actions-dropdown">
		<span class="lucide lucide-tag icon_tag cursor-pointer" data-action="show-tag-edit-modal" data-note-id="<?php p($id); ?>" title="<?php p($tr->t('tags.manage_note_tags', [], 'Manage note tags')); ?>"></span>
	</div>
	<span class="name_tags"><input type="hidden" id="tags<?php p($id); ?>" value="<?php p($note['tags']); ?>"/></span>
</div>
<?php if (!$note['attachmentsAtBottom']) { $attachmentsRow(); } ?>
<input type="hidden" id="folder<?php p($id); ?>" value="<?php p($note['folderName']); ?>"/>
<input type="hidden" id="folderId<?php p($id); ?>" value="<?php p($note['folderId'] !== null ? (string)$note['folderId'] : ''); ?>"/>
<h4 class="note-title-heading"<?php if ($note['titleColor'] !== ''): ?> data-title-color="<?php p($note['titleColorRaw']); ?>"<?php endif; ?><?php if ($note['titleSize'] !== null): ?> data-title-size="<?php p((string)$note['titleSize']); ?>" style="--note-title-size: <?php p((string)$note['titleSize']); ?>px;"<?php endif; ?>>
	<?php if ($note['showIcon']): ?><i class="<?php p($note['iconClass'] . ' note-icon note-title-icon'); ?>" data-custom-icon="<?php p($note['iconCustom'] ? 'true' : 'false'); ?>" data-action="open-note-icon-picker" data-note-id="<?php p($id); ?>" data-note-title="<?php p($note['heading']); ?>"<?php if ($note['iconColorRaw'] !== ''): ?> data-icon-color="<?php p($note['iconColorRaw']); ?>"<?php endif; ?> data-default-icon="<?php p($note['iconDefault']); ?>" title="<?php p($tr->t('notes_list.folder_actions.change_note_icon', [], 'Change note icon')); ?>" aria-label="<?php p($tr->t('notes_list.folder_actions.change_note_icon', [], 'Change note icon')); ?>"<?php if ($note['iconColor'] !== ''): ?> style="color: <?php p($note['iconColor']); ?> !important;"<?php endif; ?>></i><?php endif; ?>
	<input class="css-title" autocomplete="off" autocapitalize="off" spellcheck="false" id="inp<?php p($id); ?>" type="text" placeholder="<?php p($note['titlePlaceholder']); ?>" value="<?php p($note['titleValue']); ?>"<?php if ($note['titleColor'] !== ''): ?> style="color: <?php p($note['titleColor']); ?> !important;"<?php endif; ?>/>
	<?php if ($note['showDates']): ?>
	<span class="note-subline"><span class="note-subline-list"><span class="note-sub-dates">
		<?php if ($note['created'] !== ''): ?><span class="note-sub-created"><?php p($tr->t('index.note.created_on', ['date' => $note['created']], 'Created {{date}}')); ?></span><?php endif; ?>
		<?php if ($note['updated'] !== ''): ?><span class="note-sub-updated" id="noteUpdated<?php p($id); ?>" data-label="<?php p($tr->t('index.note.modified_on', [], 'Modified {{date}}')); ?>"><?php p($tr->t('index.note.modified_on', ['date' => $note['updated']], 'Modified {{date}}')); ?></span><?php endif; ?>
	</span><span class="note-sub-stats" id="noteStats<?php p($id); ?>" data-note-id="<?php p($id); ?>" data-labels="<?php p(json_encode($note['statsLabels'], JSON_UNESCAPED_UNICODE)); ?>"><span class="note-sub-stat note-sub-stat-characters" data-stat="characters"></span> <span class="note-sub-stat note-sub-stat-words" data-stat="words"></span> <span class="note-sub-stat note-sub-stat-lines" data-stat="lines"></span></span></span></span>
	<?php endif; ?>
</h4>
<?php if ($type === 'markdown'): ?>
<div class="noteentry markdown-pending" autocomplete="off" autocapitalize="off" spellcheck="false" id="entry<?php p($id); ?>" data-note-id="<?php p($id); ?>" data-note-heading="<?php p($note['heading']); ?>" data-ph="<?php p($tr->t('index.editor.placeholder_desktop', [], 'Enter text, use / or right-click for command menu, paste images or drop an image at cursor.')); ?>" data-ph-mobile="<?php p($tr->t('index.editor.placeholder_mobile', [], 'Enter text or paste images here...')); ?>"<?php if (!$note['canWrite']): ?> data-nextnotes-readonly="true"<?php endif; ?> contenteditable="false" data-note-type="markdown" data-markdown-content="<?php p($note['content']); ?>"><?php p($note['display']); ?></div>
<?php else: ?>
<div class="noteentry" autocomplete="off" autocapitalize="off" spellcheck="<?php p($note['spellcheck'] ? 'true' : 'false'); ?>"<?php if ($note['spellcheck']): ?> lang="<?php p($note['lang']); ?>"<?php endif; ?> id="entry<?php p($id); ?>" data-note-id="<?php p($id); ?>" data-note-heading="<?php p($note['heading']); ?>" data-ph="<?php p($tr->t('index.editor.placeholder_desktop', [], 'Enter text, use / or right-click for command menu, paste images or drop an image at cursor.')); ?>"<?php if ($type === 'note'): ?> data-ph-mobile="<?php p($tr->t('index.editor.placeholder_mobile', [], 'Enter text or paste images here...')); ?>"<?php endif; ?><?php if (!$note['canWrite']): ?> data-nextnotes-readonly="true"<?php endif; ?> contenteditable="<?php p($editable); ?>" data-note-type="<?php p($type); ?>"<?php if ($type === 'tasklist'): ?> data-tasklist-json="<?php p($note['content']); ?>"<?php endif; ?><?php if ($note['linkedNoteId'] !== null): ?> data-linked-note-id="<?php p((string)$note['linkedNoteId']); ?>"<?php endif; ?>><?php print_unescaped($note['display']); ?></div>
<?php endif; ?>
<?php if ($note['attachmentsAtBottom']) { $attachmentsRow(); } ?>
<div class="note-scroll-edge-controls">
	<button type="button" class="note-scroll-edge-btn note-scroll-top-btn" data-action="scroll-note-top" data-note-id="<?php p($id); ?>" title="<?php p($tr->t('common.scroll_top', [], 'Scroll to top')); ?>" aria-label="<?php p($tr->t('common.scroll_top', [], 'Scroll to top')); ?>" disabled><i class="lucide lucide-arrow-up"></i></button>
	<button type="button" class="note-scroll-edge-btn note-scroll-bottom-btn" data-action="scroll-note-bottom" data-note-id="<?php p($id); ?>" title="<?php p($tr->t('common.scroll_bottom', [], 'Scroll to bottom')); ?>" aria-label="<?php p($tr->t('common.scroll_bottom', [], 'Scroll to bottom')); ?>" disabled><i class="lucide lucide-arrow-down"></i></button>
</div>
<div class="note-bottom-space"></div>
</div>
</div>
