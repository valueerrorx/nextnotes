<?php
/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// abgeleitet von src/public/attachments_list.php @ 7e2e0b6aa501ba95ac1e9789fd2bb477c281a001
// Anhänge aller Notizen des Workspace (attachments-list.js über notes/with-attachments). Daten: PageController::attachmentsList.
// Abweichungen: Icon-Leiste im NC-Rahmen, body-Attribute über die JSON-Insel nextnotes-body.
/** @var array $_ */
/** @var \OCA\NextNotes\Service\View\PageTranslator $tr */
$tr = $_['tr'];
$frame = $_['frame'];
$view = $_['view'];
$t = static fn (string $key, string $default) => $tr->t($key, [], $default);
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;
$islands = [
	'nextnotes-body' => ['data' => [
		'workspace' => $frame['workspaceKey'],
		'txt-untitled' => $t('common.untitled', 'Untitled'),
		'txt-no-results' => $t('attachments.list.no_filter_results', 'No results.'),
		'txt-all-file-types' => $t('attachments.list.all_file_types', 'All types'),
	], 'replaceWorkspace' => $frame['replaceWorkspace']],
];
$titleIcon = 'lucide-paperclip';
$titleText = $t('notes_list.system_folders.attachments', 'Attachments');
?>
<div id="app-content">
<div id="nextnotes-root" class="nextnotes-listpage">
	<?php foreach ($islands as $islandId => $islandData): ?>
	<script type="application/json" id="<?php p($islandId); ?>"><?php print_unescaped(json_encode($islandData, $jsonFlags)); ?></script>
	<?php endforeach; ?>
	<?php include __DIR__ . '/partials/icon_sidebar.php'; ?>
	<main class="nn-page-main" inert>
	<div class="shared-container">
		<?php include __DIR__ . '/partials/page_title.php'; ?>

		<div class="shared-filter-bar attachments-filter-bar">
			<div class="filter-input-wrapper">
				<input type="text" id="filterInput" class="filter-input" placeholder="<?php p($t('attachments.list.filter_placeholder', 'Filter by note title or attachment name...')); ?>"/>
				<button id="clearFilterBtn" class="clear-filter-btn initially-hidden"><i class="lucide lucide-x"></i></button>
			</div>
			<select id="fileTypeFilter" class="file-type-filter initially-hidden" aria-label="<?php p($t('attachments.list.file_type_filter', 'Filter by file type')); ?>" title="<?php p($t('attachments.list.file_type_filter', 'Filter by file type')); ?>"></select>
			<label class="toggle-checkbox thumbnails-toggle">
				<input type="checkbox" id="showThumbnailsToggle" checked>
				<span class="toggle-label"><?php p($t('attachments.list.show_thumbnails', 'Show thumbnails in this list')); ?></span>
			</label>
			<div id="filterStats" class="filter-stats initially-hidden"></div>
		</div>

		<div class="shared-content">
			<div id="loadingSpinner" class="loading-spinner">
				<i class="lucide lucide-loader-2 lucide-spin"></i>
				<?php p($t('common.loading', 'Loading...')); ?>
			</div>
			<div id="attachmentsContainer" class="attachments-list-container"></div>
			<div id="emptyMessage" class="empty-message initially-hidden">
				<p><?php p($t('attachments.list.no_attachments', 'No notes with attachments yet.')); ?></p>
			</div>
		</div>
	</div>
	</main>
</div>
</div>
