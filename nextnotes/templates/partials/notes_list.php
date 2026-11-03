<?php
/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// abgeleitet von src/notes_list.php @ 7e2e0b6aa501ba95ac1e9789fd2bb477c281a001
// Suchleiste, Ordnerbaum mit Notizen, gemeinsame Menüs. Daten: $view['tree'] (IndexViewService).
// Weggelassen: Konten-Zeilen (gestrichen), Offline-Punkte (gestrichen), „Seitenleiste anpassen“ und Favoritenfarbe
// (UI-Anpassung/Farb-Modal, M4), Mini-Kalender (calendar.js, M4), Mention-Markierung (M4).
/** @var array $_ */
/** @var \OCA\NextNotes\Service\View\PageTranslator $tr */
include __DIR__ . '/folders_display.php';
$tree = $view['tree'];
$workspace = $view['workspaceKey'];
$expandLabel = $tr->t('sidebar.expand_all_folders', [], 'Expand all folders');
$treeOptionsTitle = $tr->t('sidebar.view_options', [], 'View options');
// Suchzustand (SearchService::pageSearch), Upstream notes_list.php:79-98
$search = $view['search'];
$hasDates = $search['from'] !== '' || $search['to'] !== '';

// Eine Notizzeile (renderNoteListItem)
$renderNote = static function (array $n, string $noteClass) use ($tr, $renderNoteActions, $view): void {
	$changeIcon = $tr->t('notes_list.folder_actions.change_note_icon', [], 'Change note icon');
	?><div class="note-list-item"><a class="<?php p(trim($noteClass . ($n['selected'] ? ' selected-note' : ''))); ?>" href="<?php p($n['url']); ?>" data-note-id="<?php p((string)$n['id']); ?>" data-note-db-id="<?php p((string)$n['id']); ?>" data-note-type="<?php p($n['type']); ?>"<?php if ($n['linkedNoteId'] !== null): ?> data-linked-note-id="<?php p((string)$n['linkedNoteId']); ?>"<?php endif; ?> data-folder-id="<?php p((string)$n['folderId']); ?>" data-folder="<?php p($n['folderName']); ?>" data-created="<?php p($n['created']); ?>" data-updated="<?php p($n['updated']); ?>" draggable="true" data-action="load-note" data-dblaction="open-note-new-tab"><span class="note-title<?php p($n['titleColor'] !== '' ? ' has-title-color' : ''); ?>"<?php if ($n['titleColor'] !== ''): ?> style="--note-title-color: <?php p($n['titleColor']); ?>"<?php endif; ?>><?php if ($view['showNoteIcons']): ?><i class="<?php p($n['iconClass'] . ' note-icon note-list-click-action'); ?>" data-custom-icon="<?php p($n['iconCustom'] ? 'true' : 'false'); ?>" data-action="open-note-icon-picker" data-note-id="<?php p((string)$n['id']); ?>" data-note-title="<?php p($n['title']); ?>"<?php if ($n['iconColorRaw'] !== ''): ?> data-icon-color="<?php p($n['iconColorRaw']); ?>"<?php endif; ?> data-default-icon="<?php p($n['iconDefault']); ?>" title="<?php p($changeIcon); ?>" aria-label="<?php p($changeIcon); ?>"<?php if ($n['iconColor'] !== ''): ?> style="color: <?php p($n['iconColor']); ?> !important;"<?php endif; ?>></i> <?php endif; ?><?php if ($n['type'] === 'linked'): ?><i class="lucide lucide-link note-type-icon-inline"></i> <?php endif; ?><?php p($n['title']); ?></span></a><?php $renderNoteActions($n); ?></div><div class="pxbetweennotes"></div><?php
};

// Ordner rekursiv (displayFolderRecursive); $header=false: Wurzel unter Ordnerfilter (Banner nennt sie)
$renderFolder = static function (array $f, bool $header, bool $rootFirst) use (&$renderFolder, $renderNote, $renderFolderActions, $tr, $view): void {
	$domId = 'folder-' . $f['id'];
	$noteClass = $header ? 'links_arbo_left note-in-folder' : 'links_arbo_left';
	if ($f['depth'] > 0) {
		$noteClass .= ' note-in-subfolder';
	}
	$notes = static function () use ($f, $renderNote, $noteClass): void {
		foreach ($f['notes'] as $n) {
			$renderNote($n, $noteClass);
		}
	};
	if ($header) {
		$class = 'folder-header' . ($f['depth'] > 0 ? ' subfolder subfolder-level-' . $f['depth'] : '') . $f['treeClass'];
		$iconTitle = $view['folderIconOpensKanban'] ? $tr->t('notes_list.folder_actions.kanban_view', [], 'Kanban view') : $tr->t('notes_list.folder_actions.change_icon', [], 'Change icon');
		?><div class="<?php p($class); ?>" data-folder-id="<?php p((string)$f['id']); ?>" data-folder="<?php p($f['name']); ?>" data-folder-key="folder_<?php p((string)$f['id']); ?>" data-action="select-folder"><div class="folder-toggle" data-action="toggle-folder" data-folder-dom-id="<?php p($domId); ?>" data-folder-id="<?php p((string)$f['id']); ?>" data-folder="<?php p($f['name']); ?>" draggable="true"><i class="<?php p($f['iconClass'] . ' folder-icon folder-list-click-action'); ?>" data-custom-icon="<?php p($f['iconCustom'] ? 'true' : 'false'); ?>"<?php if ($f['iconColorRaw'] !== ''): ?> data-icon-color="<?php p($f['iconColorRaw']); ?>"<?php endif; ?> data-action="open-folder-icon-picker" data-folder-id="<?php p((string)$f['id']); ?>" data-folder-name="<?php p($f['name']); ?>" title="<?php p($iconTitle); ?>"<?php if ($f['iconColor'] !== ''): ?> style="color: <?php p($f['iconColor']); ?> !important;"<?php endif; ?>></i><span class="folder-name" data-action="toggle-folder" data-folder-dom-id="<?php p($domId); ?>" data-folder-id="<?php p($domId); ?>" data-dblaction="edit-folder-name" data-folder-name="<?php p($f['name']); ?>"><?php p($f['name']); ?></span><span class="folder-note-count" id="count-<?php p((string)$f['id']); ?>"><?php p((string)$f['count']); ?></span><span class="folder-actions"><?php $renderFolderActions($f); ?></span></div><div class="folder-content" id="<?php p($domId); ?>" style="display: <?php p($f['open'] ? 'block' : 'none'); ?>;"><?php
	}
	if ($rootFirst) {
		$notes();
	}
	foreach ($f['children'] as $child) {
		$renderFolder($child, true, $rootFirst);
	}
	if (!$rootFirst) {
		$notes();
	}
	if ($header) {
		?></div></div><?php
	}
};
?>
<div class="contains_forms_search" id="search-bar-container" style="display: block;">
	<form id="unified-search-form" action="<?php p($_['baseUrl'] . 'index.php'); ?>" method="POST">
		<input type="hidden" name="requesttoken" value="<?php p($_['requesttoken']); ?>">
		<div class="unified-search-container">
			<div class="searchbar-row searchbar-icon-row">
				<div class="searchbar-type-icons" id="searchbar-type-icons">
					<button type="button" id="search-notes-btn" class="searchbar-type-btn searchbar-type-notes<?php p($search['inNotes'] ? ' active' : ''); ?>" data-search-type="notes" aria-pressed="<?php p($search['inNotes'] ? 'true' : 'false'); ?>" title="<?php p($tr->t('search.search_in_notes', [], 'Search in notes')); ?>">
						<i class="lucide lucide-file-alt"></i>
					</button>
					<button type="button" id="search-tags-btn" class="searchbar-type-btn searchbar-type-tags<?php p($search['inTags'] ? ' active' : ''); ?>" data-search-type="tags" aria-pressed="<?php p($search['inTags'] ? 'true' : 'false'); ?>" title="<?php p($tr->t('search.search_in_tags', [], 'Search in tags')); ?>">
						<i class="lucide lucide-tag"></i>
					</button>
				</div>
				<div class="searchbar-input-wrapper searchbar-has-date-toggle<?php p($search['active'] ? ' searchbar-has-clear' : ''); ?>">
					<input autocomplete="off" autocapitalize="off" spellcheck="false" id="unified-search" type="text" name="unified_search" class="search form-control searchbar-input" placeholder="<?php p($tr->t('search.placeholder_notes')); ?>" value="<?php p($search['search'] !== '' ? $search['search'] : $search['tags']); ?>" />
					<button type="button" id="search-date-toggle" class="searchbar-date-toggle<?php p($hasDates ? ' active' : ''); ?>" data-action="toggle-date-filter" title="<?php p($tr->t('search.toggle_date_filter', [], 'Toggle date filter')); ?>" aria-label="<?php p($tr->t('search.toggle_date_filter', [], 'Toggle date filter')); ?>" aria-controls="search-date-filter" aria-expanded="<?php p($hasDates ? 'true' : 'false'); ?>">
						<i class="lucide lucide-calendar"></i>
					</button>
					<?php if ($search['active']): ?>
					<button type="button" class="searchbar-clear" title="<?php p($tr->t('search.clear')); ?>" data-action="clear-search"><span class="clear-icon">×</span></button>
					<?php endif; ?>
				</div>
			</div>
			<div class="search-date-filter<?php p($hasDates ? ' active' : ''); ?>" id="search-date-filter"<?php if (!$hasDates): ?> hidden<?php endif; ?>>
				<div class="search-date-field">
					<label for="created-from"><i class="lucide lucide-calendar"></i><span><?php p($tr->t('search.created_from', [], 'Created from')); ?></span></label>
					<input id="created-from" class="search-date-input" type="date" name="created_from" value="<?php p($search['from']); ?>">
				</div>
				<div class="search-date-field">
					<label for="created-to"><i class="lucide lucide-calendar"></i><span><?php p($tr->t('search.created_to', [], 'Created to')); ?></span></label>
					<input id="created-to" class="search-date-input" type="date" name="created_to" value="<?php p($search['to']); ?>">
				</div>
			</div>
			<input type="hidden" id="search-notes-hidden" name="search" value="<?php p($search['search']); ?>">
			<input type="hidden" id="search-tags-hidden" name="tags_search" value="<?php p($search['tags']); ?>">
			<input type="hidden" name="workspace" value="<?php p($workspace); ?>">
			<input type="hidden" id="search-in-notes" name="search_in_notes" value="<?php p($search['inNotes'] ? '1' : ''); ?>">
			<input type="hidden" id="search-in-tags" name="search_in_tags" value="<?php p($search['inTags'] ? '1' : ''); ?>">
			<input type="hidden" id="search-combined-mode" name="search_combined" value="<?php p($search['combined'] ? '1' : ''); ?>">
		</div>
	</form>
</div>

<?php if ($tree['filter'] !== ''): ?>
<div class="folder-filter-banner" id="folder-filter-banner">
	<span class="folder-filter-banner-name">
		<?php if ($tree['filterRoot'] !== null): ?>
		<i class="lucide lucide-folder folder-filter-banner-icon" data-action="open-kanban-view" data-folder-id="<?php p((string)$tree['filterRoot']['id']); ?>" data-folder-name="<?php p($tree['filter']); ?>" title="<?php p($tr->t('notes_list.folder_actions.kanban_view', [], 'Kanban view')); ?>" role="button" tabindex="0" aria-label="<?php p($tr->t('notes_list.folder_actions.kanban_view', [], 'Kanban view')); ?>"></i>
		<?php else: ?>
		<i class="lucide lucide-folder"></i>
		<?php endif; ?>
		<span><?php p($tree['filter']); ?></span>
	</span>
	<a class="folder-filter-banner-clear" href="<?php p($view['exitFolderUrl']); ?>" title="<?php p($tr->t('notes_list.folder_filter.exit', [], 'Exit folder view')); ?>" aria-label="<?php p($tr->t('notes_list.folder_filter.exit', [], 'Exit folder view')); ?>"><span class="clear-icon">×</span></a>
</div>
<?php endif; ?>

<div class="notes-list-scrollable-content">
<?php if ($tree['favorites'] !== null): ?>
<div class="folder-header favorites-section system-folder" data-folder-id="0" data-folder="Favorites" data-folder-key="folder_0" data-action="select-folder"><div class="folder-toggle" data-action="toggle-folder" data-folder-dom-id="folder-favorites" data-folder-id="favorites" data-folder="Favorites"><i class="lucide lucide-star folder-icon"<?php if ($view['favoritesIconColor'] !== ''): ?> style="color: <?php p($view['favoritesIconColor']); ?> !important;"<?php endif; ?>></i><span class="folder-name" data-action="toggle-folder" data-folder-dom-id="folder-favorites" data-folder-id="folder-favorites"><?php p($tr->t('notes_list.system_folders.favorites', [], 'Favorites')); ?></span><span class="folder-actions"><div class="folder-actions-toggle" data-action="toggle-favorites-menu" title="<?php p($tr->t('notes_list.favorites_menu.title', [], 'Favorites actions')); ?>"><i class="lucide lucide-more-vertical"></i></div></span></div><div class="folder-content" id="folder-favorites" style="display: block;"><?php
	foreach ($tree['favoriteFolders'] as $ff): ?><div class="note-list-item favorite-folder-item"><a class="links_arbo_left note-in-folder favorite-folder-link" href="<?php p($ff['url']); ?>" data-folder-id="<?php p((string)$ff['id']); ?>" data-folder="<?php p($ff['name']); ?>"><span class="note-title"><i class="<?php p($ff['iconClass'] . ' favorite-folder-icon'); ?>"<?php if ($ff['iconColor'] !== ''): ?> style="color: <?php p($ff['iconColor']); ?> !important;"<?php endif; ?>></i><?php p($ff['name']); ?></span></a></div><div class="pxbetweennotes"></div><?php endforeach;
	foreach ($tree['favorites'] as $n) {
		$renderNote($n, 'links_arbo_left note-in-folder');
	}
?></div></div>
<?php endif; ?>
<div class="notes-list-actions"><button type="button" class="sidebar-folder-toggle" id="sidebarExpandFoldersBtn" data-action="toggle-all-folders" title="<?php p($expandLabel); ?>" aria-label="<?php p($expandLabel); ?>"><i class="lucide lucide-chevron-down"></i></button><button type="button" class="folder-actions-toggle" id="sidebarTreeOptionsBtn" data-action="toggle-tree-options-menu" aria-haspopup="menu" aria-expanded="false" title="<?php p($treeOptionsTitle); ?>" aria-label="<?php p($treeOptionsTitle); ?>"><i class="lucide lucide-more-vertical"></i></button></div>
<?php if ($tree['filter'] === ''): ?>
<div id="root-drop-zone" class="root-drop-zone initially-hidden"><div class="drop-zone-content"><i class="lucide lucide-home drop-zone-icon"></i><span class="drop-zone-text"><?php p($tr->t('notes_list.drop_zone.remove_from_folder', [], 'Drop here to remove from folder')); ?></span></div></div>
<?php endif; ?>
<?php
if ($tree['rootFirst']) {
	foreach ($tree['root'] as $n) {
		$renderNote($n, 'links_arbo_left note-without-folder');
	}
}
if ($tree['filterRoot'] !== null) {
	$renderFolder($tree['filterRoot'], false, $tree['rootFirst']);
}
foreach ($tree['folders'] as $f) {
	$renderFolder($f, true, $tree['rootFirst']);
}
if (!$tree['rootFirst']) {
	foreach ($tree['root'] as $n) {
		$renderNote($n, 'links_arbo_left note-without-folder');
	}
}
?>
</div>

<?php $renderFolderActionsMenu($workspace, $view['folderIconOpensKanban']); ?>
<div class="folder-actions-menu" id="favorites-actions-menu" role="menu" data-workspace="<?php p($workspace); ?>" data-icon-color="">
	<div class="favorites-menu-label"><?php p($tr->t('sort.header', [], 'Sort by')); ?></div>
	<?php foreach ($view['favoritesSortModes'] as $mode): ?>
	<div class="folder-actions-menu-item<?php p($mode === $view['favoritesSort'] ? ' favorites-sort-active active-state' : ''); ?>" role="menuitemradio" aria-checked="<?php p($mode === $view['favoritesSort'] ? 'true' : 'false'); ?>" data-favorites-action="sort" data-sort-mode="<?php p($mode); ?>"><i class="lucide <?php p($view['sortIcons'][$mode]); ?>"></i><span><?php p($tr->t(...\OCA\NextNotes\Service\View\IndexViewService::sortLabel($mode))); ?></span><i class="lucide lucide-check favorites-sort-check"></i></div>
	<?php endforeach; ?>
	<div class="folder-actions-menu-separator"></div>
	<div class="folder-actions-menu-item danger" role="menuitem" data-favorites-action="clear"><i class="lucide lucide-eraser"></i><span><?php p($tr->t('notes_list.favorites_menu.clear', [], 'Remove all from favorites')); ?></span></div>
</div>
<div class="folder-actions-menu" id="tree-options-menu" role="menu" aria-label="<?php p($treeOptionsTitle); ?>">
	<div id="sidebarSortBtn" role="group" data-note-sort-mode="<?php p($view['sort']); ?>">
		<?php foreach ($view['sortModes'] as $mode): ?>
		<div class="folder-actions-menu-item<?php p($mode === $view['sort'] ? ' active-state' : ''); ?>" role="menuitemradio" aria-checked="<?php p($mode === $view['sort'] ? 'true' : 'false'); ?>" data-action="set-note-sort" data-sort-mode="<?php p($mode); ?>" title="<?php p($tr->t(...\OCA\NextNotes\Service\View\IndexViewService::sortHint($mode))); ?>"><i class="lucide <?php p($view['sortIcons'][$mode]); ?>"></i><span><?php p($tr->t(...\OCA\NextNotes\Service\View\IndexViewService::sortMenuLabel($mode))); ?></span></div>
		<?php endforeach; ?>
	</div>
</div>
<?php $renderNoteActionsMenu($workspace); ?>
