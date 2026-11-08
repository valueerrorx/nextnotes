<?php
/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// abgeleitet von src/public/list_folders.php @ 7e2e0b6aa501ba95ac1e9789fd2bb477c281a001
// Ordnerbaum des Workspace (list_folders.js, Ordner-Aktionen aus utils-*.js über die Ordner-API). Daten: PageController::listFolders, FolderListViewService.
// Abweichungen: Icon-Leiste im NC-Rahmen, Seiten-<style> und Inline-Stile nach css-src/nextnotes-layout.css, Dialoge aus partials/modals.php;
// gestrichen: öffentliches Teilen, Offline; ohne Schreibrecht nur Kanban, Filter und Herunterladen; Aktionen bauen nur #foldersList neu (P24).
/** @var array $_ */
/** @var \OCA\NextNotes\Service\View\PageTranslator $tr */
$tr = $_['tr'];
$frame = $_['frame'];
$view = $_['view'];
$folders = $_['folders'];
$canWrite = $frame['canWrite'];
$key = $frame['workspaceKey'];
$t = static fn (string $k, string $default) => $tr->t($k, [], $default);
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;
$islands = [
	'nextnotes-body' => ['data' => [
		'workspace' => $key,
		'archive-workspace' => \OCA\NextNotes\Service\WorkspaceService::ARCHIVE_NAME,
		'txt-expand-folder' => $t('public.expand_folder', 'Expand folder'),
		'txt-collapse-folder' => $t('public.collapse_folder', 'Collapse folder'),
		'txt-expand-all' => $t('public.expand_all', 'Expand all'),
		'txt-collapse-all' => $t('public.collapse_all', 'Collapse all'),
	], 'replaceWorkspace' => $frame['replaceWorkspace']],
];
// Menüeintrag wie partials/folders_display.php; $action immer Literal
$menuItem = static function (string $base, string $action, string $icon, string $label, string $extra = ''): void {
	?><div class="<?php p(trim($base . ' ' . $extra)); ?>" data-action="<?php p($action); ?>"><i class="lucide <?php p($icon); ?>"></i><span><?php p($label); ?></span></div><?php
};
// Aktionen ohne Zeilensymbol (Upstream folderListActions ohne inline), für Dropdown und Mobil-Dialog
$renderMenu = static function (string $id, string $class) use ($menuItem, $t, $canWrite, $folders): void {
	$i = 'folder-actions-menu-item';
	?><div class="<?php p($class); ?>" id="<?php p($id); ?>"><?php
	$menuItem($i, 'open-kanban-view', 'lucide-columns-2', $t('notes_list.folder_actions.kanban_view', 'Kanban view'));
	$menuItem($i, 'show-only-folder', 'lucide-filter', $t('notes_list.folder_actions.show_only', 'Show only this folder'));
	if ($canWrite) {
		$menuItem($i, 'move-folder-files', 'lucide-folder-open', $t('notes_list.folder_actions.move_all_files', 'Move all files'), 'requires-notes');
		$menuItem($i, 'move-entire-folder', 'lucide-folder-output', $t('notes_list.folder_actions.move_folder', 'Move to subfolder'));
		$menuItem($i, 'duplicate-folder', 'lucide-copy', $t('notes_list.folder_actions.duplicate_folder', 'Duplicate folder'));
	}
	$menuItem($i, 'download-folder', 'lucide-download', $t('notes_list.folder_actions.download_folder', 'Download folder'), 'requires-notes');
	if ($canWrite) {
		$menuItem($i, 'tag-folder-notes', 'lucide-tag', $t('modals.tag_folder_notes.title', 'Tag all notes'), 'requires-notes');
		$menuItem($i, 'favorite-folder', 'lucide-star', $t('notes_list.folder_actions.remove_favorite', 'Remove from favorites'), 'favorite-state-favorite');
		$menuItem($i, 'favorite-folder', 'lucide-star', $t('notes_list.folder_actions.add_favorite', 'Add to favorites'), 'favorite-state-not-favorite');
		$menuItem($i, 'rename-folder', 'lucide-pencil', $t('notes_list.folder_actions.rename_folder', 'Rename'));
		$menuItem($i, 'change-folder-icon', 'lucide-palette', $t('notes_list.folder_actions.change_icon', 'Change icon'));
		if ($folders['canArchive']) {
			$menuItem($i, 'archive-folder', 'lucide-archive', $t('archive.folder_menu_item', 'Archive folder'));
		}
		?><div class="folder-actions-menu-separator"></div><?php
		$menuItem($i, 'delete-folder', 'lucide-trash-2', $t('notes_list.folder_actions.delete_folder', 'Delete'), 'danger folder-actions-danger');
	}
	?></div><?php
};
$titleIcon = 'lucide-folder-open';
$titleText = $t('home.folders', 'Folders');
?>
<div id="app-content">
<div id="nextnotes-root" class="nextnotes-listpage">
	<?php foreach ($islands as $islandId => $islandData): ?>
	<script type="application/json" id="<?php p($islandId); ?>"><?php print_unescaped(json_encode($islandData, $jsonFlags)); ?></script>
	<?php endforeach; ?>
	<?php include __DIR__ . '/partials/modals.php'; ?>
	<?php include __DIR__ . '/partials/icon_sidebar.php'; ?>
	<main class="nn-page-main" inert>
	<div class="shared-container">
		<?php include __DIR__ . '/partials/page_title.php'; ?>

		<div class="shared-filter-bar">
			<div class="filter-search-row folders-filter-row">
				<div class="filter-input-wrapper">
					<input type="text" id="filterInput" class="filter-input" placeholder="<?php p($t('folders.filter_placeholder', 'Filter by folder name...')); ?>"/>
					<button id="clearFilterBtn" class="clear-filter-btn initially-hidden"><i class="lucide lucide-x"></i></button>
				</div>
				<div class="shared-filter-tree-actions initially-hidden" id="foldersTreeToolbar">
					<button type="button" id="toggleAllFoldersBtn" class="btn btn-secondary" aria-expanded="true">
						<i class="lucide lucide-chevron-up"></i>
						<span id="toggleAllFoldersLabel"><?php p($t('public.collapse_all', 'Collapse all')); ?></span>
					</button>
				</div>
			</div>
			<div id="filterStats" class="filter-stats initially-hidden"></div>
		</div>

		<div class="shared-content">
			<div id="foldersList" class="shared-notes-list">
			<?php include __DIR__ . '/partials/folder_rows.php'; ?>
			</div>
		</div>
	</div>
	</main>

	<?php $renderMenu('folderRowActionsMenu', 'folder-actions-menu'); ?>

	<div id="folderActionsModal" class="modal">
		<div class="modal-content">
			<div class="folder-actions-modal-header">
				<i class="lucide lucide-folder" id="folderActionsModalIcon"></i>
				<span id="folderActionsModalTitle"></span>
			</div>
			<div class="folder-actions-modal-body">
				<?php $renderMenu('folder-actions-menu', 'folder-actions-menu show'); ?>
			</div>
			<div class="modal-buttons">
				<button type="button" class="btn-cancel" data-action="close-modal" data-modal="folderActionsModal"><?php p($t('common.cancel', 'Cancel')); ?></button>
			</div>
		</div>
	</div>
</div>
</div>
