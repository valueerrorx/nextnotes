<?php
/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// abgeleitet von src/public/notes_manager.php @ 7e2e0b6aa501ba95ac1e9789fd2bb477c281a001
// Alle Notizen des Workspace mit Filter und Mehrfachaktionen (notes-manager.js über Notiz-/Ordner-API). Daten: PageController::notesManager.
// Abweichungen: Icon-Leiste im NC-Rahmen, body-Attribute über die JSON-Insel nextnotes-body; Offline-Aktionen gestrichen.
/** @var array $_ */
/** @var \OCA\NextNotes\Service\View\PageTranslator $tr */
$tr = $_['tr'];
$frame = $_['frame'];
$view = $_['view'];
$t = static fn (string $key, string $default, array $vars = []) => $tr->t($key, $vars, $default);
$txt = [
	'txt-error' => ['common.error', 'Error'],
	'txt-untitled' => ['common.untitled', 'Untitled'],
	'txt-loading' => ['common.loading', 'Loading...'],
	'txt-no-folder' => ['notes_list.system_folders.no_folder', 'No folder'],
	'txt-type-html' => ['notes_manager.type_html', 'Rich text'],
	'txt-type-markdown' => ['notes_manager.type_markdown', 'Markdown'],
	'txt-type-tasklist' => ['notes_manager.type_tasklist', 'Tasklist'],
	'txt-type-excalidraw' => ['notes_manager.type_excalidraw', 'Excalidraw'],
	'txt-select-folder' => ['notes_manager.select_folder', 'Select a target folder'],
	'txt-move' => ['notes_manager.move', 'Move'],
	'txt-cancel' => ['common.cancel', 'Cancel'],
	'txt-selected' => ['notes_manager.selected', 'selected'],
	'txt-select-all' => ['notes_manager.select_all', 'Select all'],
	'txt-select-all-folder' => ['notes_manager.select_all_in_folder', 'Select all in this folder'],
	'txt-deselect-all' => ['notes_manager.deselect_all', 'Deselect all'],
	'txt-move-to' => ['notes_manager.move_to', 'Move to...'],
	'txt-choose-action' => ['notes_manager.choose_action', 'Choose an action...'],
	'txt-add-tag' => ['notes_manager.add_tag', 'Add tag'],
	'txt-remove-tag' => ['notes_manager.remove_tag', 'Remove tag'],
	'txt-add-favorite' => ['notes_manager.add_favorite', 'Add to favorites'],
	'txt-remove-favorite' => ['notes_manager.remove_favorite', 'Remove from favorites'],
	'txt-trash' => ['notes_manager.move_to_trash', 'Move to trash'],
	'txt-trash-confirm' => ['notes_manager.trash_confirm', 'Move the selected notes to trash?'],
	'txt-enter-tag' => ['notes_manager.enter_tag', 'Enter at least one tag'],
	'txt-tags-placeholder' => ['notes_manager.tags_placeholder', 'tag1, tag2'],
	'txt-applying' => ['notes_manager.applying', 'Applying...'],
	'txt-moving' => ['notes_manager.moving', 'Moving...'],
	'txt-moved' => ['notes_manager.moved', 'Moved successfully'],
	'txt-root' => ['notes_manager.root', 'Root (no folder)'],
	'txt-collapse-all' => ['tasks_page.collapse_all', 'Collapse all'],
	'txt-expand-all' => ['tasks_page.expand_all', 'Expand all'],
];
$data = ['workspace' => $frame['workspaceKey'], 'date-time-format' => $frame['dateTimeFormat']];
foreach ($txt as $attr => [$key, $default]) {
	$data[$attr] = $t($key, $default);
}
$data['txt-show-more'] = $t('common.show_more_notes', 'Show more ({count} remaining)', ['count' => '{count}']);
$data['txt-age-labels'] = json_encode([
	'0' => $t('modals.note_age_filter.options.all', 'All notes'),
	'30' => $t('modals.note_age_filter.options.last_30_days', 'Last 30 days'),
	'90' => $t('modals.note_age_filter.options.last_3_months', 'Last 3 months'),
	'180' => $t('modals.note_age_filter.options.last_6_months', 'Last 6 months'),
	'365' => $t('modals.note_age_filter.options.last_12_months', 'Last 12 months'),
	'730' => $t('modals.note_age_filter.options.last_2_years', 'Last 2 years'),
], JSON_THROW_ON_ERROR);
$data['txt-age-custom'] = $t('modals.note_age_filter.options.custom_days', 'Last {days} days', ['days' => '{days}']);
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;
$islands = [
	'nextnotes-body' => ['data' => $data, 'replaceWorkspace' => $frame['replaceWorkspace']],
];
$titleIcon = 'lucide-sticky-note';
$titleText = $t('common.back_to_notes', 'Notes');
?>
<div id="app-content">
<div id="nextnotes-root" class="nextnotes-listpage">
	<?php foreach ($islands as $islandId => $islandData): ?>
	<script type="application/json" id="<?php p($islandId); ?>"><?php print_unescaped(json_encode($islandData, $jsonFlags)); ?></script>
	<?php endforeach; ?>
	<?php include __DIR__ . '/partials/icon_sidebar.php'; ?>
	<main class="nn-page-main">
	<div class="notes-manager-container">
		<?php include __DIR__ . '/partials/page_title.php'; ?>

		<div class="nm-filter-bar">
			<div class="filter-input-wrapper">
				<i class="lucide lucide-search nm-filter-icon"></i>
				<input type="text" id="nmFilterInput" class="filter-input" placeholder="<?php p($t('notes_manager.filter_placeholder', 'Filter notes by title or tags...')); ?>" autocomplete="off"/>
				<button id="nmClearFilter" class="clear-filter-btn initially-hidden" title="<?php p($t('search.clear', 'Clear')); ?>"><i class="lucide lucide-x"></i></button>
			</div>
			<select id="nmTypeFilter" class="nm-filter-select" aria-label="<?php p($t('notes_manager.filter_type', 'Filter by type')); ?>">
				<option value=""><?php p($t('notes_manager.all_types', 'All types')); ?></option>
				<option value="html"><?php p($t('notes_manager.type_html', 'Rich text')); ?></option>
				<option value="markdown"><?php p($t('notes_manager.type_markdown', 'Markdown')); ?></option>
				<option value="tasklist"><?php p($t('notes_manager.type_tasklist', 'Tasklist')); ?></option>
			</select>
			<button type="button" id="nmToggleAllBtn" class="nm-collapse-btn" aria-expanded="true" title="<?php p($t('tasks_page.collapse_all', 'Collapse all')); ?>">
				<i class="lucide lucide-chevron-up"></i>
				<span id="nmToggleAllLabel"><?php p($t('tasks_page.collapse_all', 'Collapse all')); ?></span>
			</button>
			<select id="nmAgeFilter" class="nm-filter-select" aria-label="<?php p($t('notes_manager.filter_age', 'Filter by age')); ?>">
				<option value=""><?php p($t('notes_manager.age_all', 'All notes')); ?></option>
				<option value="filtered"><?php p($t('notes_manager.age_recent', 'Recent only')); ?></option>
			</select>
			<div id="nmFilterStats" class="filter-stats initially-hidden"></div>
		</div>

		<div id="nmBulkBar" class="nm-bulk-bar nm-bulk-bar-hidden">
			<div class="nm-bulk-count-wrap">
				<span id="nmSelectedCount" class="nm-selected-count">0 <?php p($t('notes_manager.selected', 'selected')); ?></span>
			</div>
			<div class="nm-bulk-actions">
				<button id="nmSelectAllBtn" class="btn btn-sm btn-secondary nm-bulk-btn"><?php p($t('notes_manager.select_all', 'Select all visible')); ?></button>
				<button id="nmDeselectAllBtn" class="btn btn-sm btn-secondary nm-bulk-btn"><?php p($t('notes_manager.deselect_all', 'Deselect all')); ?></button>
				<select id="nmBulkActionSelect" class="nm-bulk-select" disabled aria-label="<?php p($t('notes_manager.actions', 'Bulk actions')); ?>">
					<option value=""><?php p($t('notes_manager.choose_action', 'Choose an action')); ?></option>
					<option value="move"><?php p($t('notes_manager.move_to', 'Move to...')); ?></option>
					<option value="add-tag"><?php p($t('notes_manager.add_tag', 'Add tag')); ?></option>
					<option value="remove-tag"><?php p($t('notes_manager.remove_tag', 'Remove tag')); ?></option>
					<option value="add-favorite"><?php p($t('notes_manager.add_favorite', 'Add to favorites')); ?></option>
					<option value="remove-favorite"><?php p($t('notes_manager.remove_favorite', 'Remove from favorites')); ?></option>
					<option value="trash"><?php p($t('notes_manager.move_to_trash', 'Move to trash')); ?></option>
				</select>
			</div>
		</div>

		<div class="nm-content">
			<div id="nmSpinner" class="loading-spinner">
				<i class="lucide lucide-loader-2 lucide-spin"></i>
				<?php p($t('common.loading', 'Loading...')); ?>
			</div>
			<div id="nmNotesContainer"></div>
			<div id="nmShowMore" class="nm-show-more" hidden>
				<button type="button" id="nmShowMoreBtn" class="btn btn-sm btn-secondary"></button>
			</div>
			<div id="nmEmptyMessage" class="empty-message initially-hidden">
				<p><?php p($t('notes_manager.empty', 'No notes found.')); ?></p>
			</div>
		</div>
	</div>
	</main>

	<div id="nmMoveModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="nmMoveModalTitle">
		<div class="modal-content">
			<div class="modal-header">
				<h3 id="nmMoveModalTitle"><?php p($t('notes_manager.move_to', 'Move to folder')); ?></h3>
			</div>
			<div class="modal-body">
				<div class="nm-folder-search-wrap">
					<input type="text" id="nmFolderSearch" class="filter-input nn-full-width" placeholder="<?php p($t('notes_manager.filter_folders', 'Filter folders...')); ?>" autocomplete="off"/>
				</div>
				<div id="nmFolderList" class="nm-folder-list"></div>
			</div>
			<div class="modal-buttons nm-move-modal-buttons">
				<button id="nmCancelMove" class="btn-cancel"><?php p($t('common.cancel', 'Cancel')); ?></button>
				<button id="nmConfirmMove" class="btn-primary" disabled><?php p($t('notes_manager.move', 'Move')); ?></button>
			</div>
		</div>
	</div>

	<div id="nmTagModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="nmTagModalTitle">
		<div class="modal-content">
			<div class="modal-header">
				<h3 id="nmTagModalTitle"><?php p($t('notes_manager.add_tag', 'Add tag')); ?></h3>
			</div>
			<div class="modal-body">
				<input type="text" id="nmTagInput" class="nm-modal-input" placeholder="<?php p($t('notes_manager.tags_placeholder', 'tag1, tag2')); ?>" autocomplete="off"/>
			</div>
			<div class="modal-buttons">
				<button id="nmConfirmTag" class="btn-primary" disabled><?php p($t('notes_manager.add_tag', 'Add tag')); ?></button>
				<button id="nmCancelTag" class="btn-cancel"><?php p($t('common.cancel', 'Cancel')); ?></button>
			</div>
		</div>
	</div>
</div>
</div>
