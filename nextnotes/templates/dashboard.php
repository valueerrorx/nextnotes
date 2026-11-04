<?php
/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// abgeleitet von src/public/dashboard.php @ 7e2e0b6aa501ba95ac1e9789fd2bb477c281a001
// Dashboard: Karten der Notizen nach Ordnern (dashboard-page.js), Bereich ein/mehrere/alle Workspaces oder Tag. Daten: PageController::dashboard, DashboardViewService.
// Abweichungen: Icon-Leiste im NC-Rahmen, Inline-Skripte → JSON-Insel (DASHBOARD_DATA, Texte), <body>-Attribute über die Insel; ohne AI-Chat (ADR 0010),
// ohne Kontext-Anpassungspanel, ohne „Workspaces verwalten“/„Farben verwalten“ (Einstellungen M4h); Bereich wird nicht per GET gespeichert (P28).
/** @var array $_ */
/** @var \OCA\NextNotes\Service\View\PageTranslator $tr */
$tr = $_['tr'];
$frame = $_['frame'];
$view = $_['view'];
$board = $_['board'];
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;
$t = static fn (string $key, string $default, array $vars = []) => $tr->t($key, $vars, $default);
$isMulti = $board['mode'] !== 'single';
$scopeLabel = match ($board['mode']) {
	'all' => $t('dashboard.scope.all', 'All workspaces'),
	'tag' => $t('dashboard.scope.tag_label', 'Tag: {{tag}}', ['tag' => $board['tag']]),
	'list' => $t('dashboard.scope.count_label', '{{count}} workspaces', ['count' => $board['count']]),
	default => $frame['workspaceKey'],
};
$emptyMessage = $board['favoritesOnly'] || !$isMulti
	? $t('dashboard.empty', 'No favorite notes yet. Mark notes as favorites to pin them to this board.')
	: $t('dashboard.scope.empty', 'No notes in this scope yet.');
if ($board['mode'] === 'tag' && $board['count'] === 0) {
	$emptyMessage = $t('dashboard.scope.no_workspace_for_tag', 'No workspace carries the tag "{{tag}}".', ['tag' => $board['tag']]);
}
$islands = [
	'nextnotes-body' => [
		'data' => ['workspace' => $frame['workspaceKey'], 'scope' => $board['data']['scope']['key']],
		'replaceWorkspace' => $frame['replaceWorkspace'],
	],
	'nextnotes-globals' => $_['globals'] + [
		'DASHBOARD_DATA' => $board['data'],
		'NOTE_COLOR_TXT' => [
			'applyError' => $t('note_color.apply_error', 'Could not update the note color.'),
			'modalTitle' => $t('note_color.modal_title', 'Note color'),
			'folderModalTitle' => $t('note_color.folder_modal_title', 'Folder color'),
			'filterAll' => $t('note_color.filter_all', 'All notes'),
			'filterAnyColor' => $t('note_color.filter_any', 'Any color'),
			'filterNoColor' => $t('note_color.filter_none', 'No color'),
		],
		'DASHBOARD_PIN_TXT' => [
			'pin' => $t('dashboard.pin_note', 'Pin to top'),
			'unpin' => $t('dashboard.unpin_note', 'Unpin'),
			'error' => $t('dashboard.pin_error', 'Could not update the pinned state.'),
			'others' => $t('dashboard.others_section', 'Others'),
		],
		'DASHBOARD_REORDER_TXT' => ['error' => $t('dashboard.reorder_error', 'Could not save the card order.')],
		'DASHBOARD_SHOW_MORE_TXT' => $t('common.show_more_notes', 'Show more ({count} remaining)', ['count' => '{count}']),
		'DASHBOARD_SCOPE_TXT' => [
			'all' => $t('dashboard.scope.all', 'All workspaces'),
			'byTag' => $t('dashboard.scope.by_tag', 'By tag'),
			'workspaces' => $t('dashboard.scope.workspaces', 'Workspaces'),
			'noTags' => $t('dashboard.scope.no_tags', 'No workspace has tags yet. Add tags to your workspaces from the Workspaces page.'),
			'open' => $t('dashboard.scope.open', 'Open only this workspace'),
			'manageWorkspaces' => $t('dashboard.scope.manage_workspaces', 'Manage workspaces'),
			'loading' => $t('common.loading', 'Loading...'),
			'loadError' => $t('dashboard.scope.load_error', 'Could not load the workspaces.'),
			'empty' => $t('dashboard.scope.no_workspaces', 'No workspaces available'),
			'groupEmpty' => $t('dashboard.scope.group_empty', 'Nothing here yet.'),
		],
		'DASHBOARD_MODIFIED_TXT' => [
			'any' => $t('dashboard.modified.any', 'Any time'),
			'today' => $t('dashboard.modified.today', 'Today'),
			'week' => $t('dashboard.modified.week', 'Last 7 days'),
			'month' => $t('dashboard.modified.month', 'Last 30 days'),
			'quarter' => $t('dashboard.modified.quarter', 'Last 90 days'),
			'year' => $t('dashboard.modified.year', 'Last 12 months'),
		],
		'DASHBOARD_TAG_FILTER_TXT' => [
			'all' => $t('dashboard.tag_filter.all', 'All tags'),
			'empty' => $t('dashboard.tag_filter.empty', 'No tags on this board.'),
			'search' => $t('dashboard.tag_filter.search', 'Filter tags...'),
			'noMatch' => $t('dashboard.tag_filter.no_match', 'No matching tag.'),
		],
		'DASHBOARD_NOTE_MODAL_TXT' => [
			'loading' => $t('common.loading', 'Loading...'),
			'saving' => $t('diary.journal_saving', 'Saving...'),
			'saved' => $t('diary.journal_saved', 'Saved'),
			'saveError' => $t('dashboard.note_modal.save_error', 'Could not save this note.'),
			'loadError' => $t('dashboard.note_modal.load_error', 'Could not load this note.'),
			'conflict' => $t('dashboard.note_modal.conflict', 'This note was changed elsewhere. Your latest changes here were not saved: reload the page to see the current version.'),
			'addTask' => $t('tasklist.input_placeholder', 'Write a new task and press Enter to add to list...'),
			'emptyTasks' => $t('dashboard.note_modal.empty_tasks', 'No tasks yet.'),
			'deleteTask' => $t('common.delete', 'Delete'),
		],
		'NOTIFICATIONS_TXT' => [
			'dismiss' => $t('reminder.dismiss', 'Dismiss'),
			'justNow' => $t('reminder.just_now', 'Just now'),
			'repeats' => $t('reminder.repeats', 'Repeats'),
		],
	],
];
$boardPrefix = 'dashboard';
$filterLabel = $t('dashboard.filters.button', 'Filters');
$scopeTitle = $t('dashboard.scope.title', 'Scope');
$colorTitle = $t('note_color.modal_title', 'Note color');
?>
<div id="app-content">
<div id="nextnotes-root" class="nextnotes-listpage favorites-page dashboard-page">
	<?php foreach ($islands as $islandId => $islandData): ?>
	<script type="application/json" id="<?php p($islandId); ?>"><?php print_unescaped(json_encode($islandData, $jsonFlags)); ?></script>
	<?php endforeach; ?>
	<?php include __DIR__ . '/partials/icon_sidebar.php'; ?>
	<main class="nn-page-main nn-dashboard-main">
	<div class="favorites-container dashboard-container">
		<h1 class="poznote-page-title"><span class="poznote-page-title-name"><i class="lucide lucide-layout-dashboard"></i> <?php p($t('common.back_to_home', 'Dashboard')); ?></span>
			<button type="button" class="poznote-page-title-workspace" id="poznotePageTitleWorkspaceBtn" data-action="open-workspace-switcher-modal" title="<?php p($scopeTitle); ?>" aria-haspopup="dialog"><?php if (!$isMulti && $frame['workspaceColor'] !== ''): ?><span class="poznote-page-title-workspace-dot" style="background-color: <?php p($frame['workspaceColor']); ?>"></span><?php else: ?><i class="lucide lucide-layers poznote-page-title-workspace-icon" aria-hidden="true"></i><?php endif; ?><span class="poznote-page-title-workspace-name"><?php p($scopeLabel); ?></span><i class="lucide lucide-chevron-down poznote-page-title-workspace-chevron" aria-hidden="true"></i></button>
		</h1>

		<header class="dashboard-topbar">
			<div class="board-filter-row">
			<?php include __DIR__ . '/partials/board_view_menu.php'; ?>
			<div class="dashboard-color-filter-wrap">
				<button type="button" id="dashboardFilterBtn" class="dashboard-color-filter-btn dashboard-filter-btn" title="<?php p($filterLabel); ?>" aria-label="<?php p($filterLabel); ?>" aria-haspopup="true" aria-expanded="false">
					<i class="lucide lucide-filter"></i>
					<span class="dashboard-filter-badge" hidden></span>
				</button>
				<div id="dashboardFilterMenu" class="dashboard-color-filter-menu dashboard-filter-panel" hidden>
					<div class="dashboard-filter-section">
						<div class="dashboard-filter-section-title"><?php p($t('dashboard.filters.color', 'Color')); ?></div>
						<div id="dashboardFilterColorSection" class="dashboard-filter-chips"></div>
					</div>
					<div class="dashboard-filter-section">
						<div class="dashboard-filter-section-title"><?php p($t('dashboard.filters.modified', 'Last modified')); ?></div>
						<div id="dashboardFilterModifiedSection" class="dashboard-filter-chips"></div>
					</div>
					<div class="dashboard-filter-section">
						<div class="dashboard-filter-section-title"><?php p($t('dashboard.filters.tags', 'Tags')); ?></div>
						<div id="dashboardFilterTagSection"></div>
					</div>
					<div class="dashboard-filter-footer" hidden>
						<button type="button" id="dashboardFilterResetBtn" class="dashboard-filter-reset"><i class="lucide lucide-x"></i> <?php p($t('dashboard.filters.reset', 'Reset filters')); ?></button>
					</div>
				</div>
			</div>
			<div id="dashboardTopbarFilter" class="dashboard-topbar-filter">
				<i class="lucide lucide-search dashboard-filter-icon"></i>
				<input type="text" id="filterInput" class="dashboard-filter-input" placeholder="<?php p($t('dashboard.filter_placeholder', 'Filter by title, content or tag...')); ?>" autocomplete="off"/>
				<button type="button" id="clearFilterBtn" class="dashboard-filter-clear initially-hidden" aria-label="<?php p($t('search.clear', 'Clear search')); ?>">
					<i class="lucide lucide-x"></i>
				</button>
			</div>
			</div>
			<div id="dashboardSelectionBar" class="dashboard-selection-bar" hidden>
				<button type="button" class="board-view-btn" data-action="clear-card-selection" title="<?php p($t('common.close', 'Close')); ?>" aria-label="<?php p($t('common.close', 'Close')); ?>">
					<i class="lucide lucide-x"></i>
				</button>
				<span id="dashboardSelectionTitle" class="dashboard-selection-title"></span>
				<button type="button" id="dashboardSelectionColorBtn" class="board-view-btn" title="<?php p($colorTitle); ?>" aria-label="<?php p($colorTitle); ?>">
					<i class="lucide lucide-palette"></i>
				</button>
			</div>
		</header>

	<?php if ($board['empty']): ?>
		<div class="dashboard-empty">
			<i class="lucide <?php p($isMulti && !$board['favoritesOnly'] ? 'lucide-layers' : 'lucide-star'); ?>"></i>
			<p><?php p($emptyMessage); ?></p>
		</div>
	<?php else: ?>
		<div id="dashboardNoResults" class="empty-message initially-hidden">
			<p><?php p($t('public.no_filter_results', 'No notes match your search.')); ?></p>
		</div>
		<nav id="dashboardBreadcrumb" class="dashboard-breadcrumb" hidden aria-label="breadcrumb"></nav>
		<div id="dashboardGrid" class="dashboard-grid-container"></div>
	<?php endif; ?>
	</div>
	</main>

	<div id="workspaceSwitcherModal" class="modal">
		<div class="modal-content dashboard-scope-modal">
			<h3><?php p($scopeTitle); ?></h3>
			<div class="modal-body">
				<p><?php p($t('dashboard.scope.hint', 'Show one workspace, several, all of them, or every workspace carrying a tag.')); ?></p>
				<div id="workspaceSwitcherList" class="dashboard-scope-body">
					<div class="move-task-empty"><?php p($t('common.loading', 'Loading...')); ?></div>
				</div>
			</div>
			<div class="modal-buttons">
				<button type="button" class="btn-cancel" data-action="close-workspace-switcher-modal"><?php p($t('common.close', 'Close')); ?></button>
				<button type="button" class="btn-primary" id="dashboardScopeApplyBtn" disabled><?php p($t('common.apply', 'Apply')); ?></button>
			</div>
		</div>
	</div>

	<div id="noteColorModal" class="modal">
		<div class="modal-content">
			<h3 id="noteColorModalTitle"><?php p($colorTitle); ?></h3>
			<p class="note-color-modal-subtitle" id="noteColorModalNoteTitle"></p>
			<div class="note-color-grid" id="noteColorGrid"></div>
			<div class="modal-buttons">
				<button type="button" class="btn-danger" id="noteColorClearBtn" title="<?php p($t('note_color.remove', 'Remove color')); ?>" aria-label="<?php p($t('note_color.remove', 'Remove color')); ?>"><i class="lucide lucide-eraser"></i> <span class="note-color-btn-label"><?php p($t('note_color.remove', 'Remove color')); ?></span></button>
				<button type="button" class="btn-cancel" data-action="close-note-color-modal"><?php p($t('common.cancel', 'Cancel')); ?></button>
				<button type="button" class="btn-primary" id="noteColorApplyBtn"><?php p($t('common.apply', 'Apply')); ?></button>
			</div>
		</div>
	</div>

	<div id="dashboardNoteModal" class="modal dashboard-note-modal" role="dialog" aria-modal="true" aria-labelledby="dashboardNoteModalTitle">
		<div class="modal-content dashboard-note-modal-content">
			<div class="dashboard-note-modal-header">
				<h3 id="dashboardNoteModalTitle" class="dashboard-note-modal-title"></h3>
				<button type="button" class="dashboard-note-modal-icon-btn dashboard-note-modal-close" data-action="close-note-modal" title="<?php p($t('common.close', 'Close')); ?>" aria-label="<?php p($t('common.close', 'Close')); ?>"><i class="lucide lucide-x"></i></button>
			</div>
			<div id="dashboardNoteModalBody" class="dashboard-note-modal-body"></div>
			<div class="dashboard-note-modal-footer">
				<span id="dashboardNoteModalStatus" class="dashboard-note-modal-status" aria-live="polite"></span>
				<div class="modal-buttons">
					<button type="button" class="btn-cancel" data-action="close-note-modal"><?php p($t('common.close', 'Close')); ?></button>
					<a id="dashboardNoteModalOpen" class="btn btn-primary dashboard-note-modal-open" href="#"><i class="lucide lucide-pencil"></i> <?php p($t('dashboard.note_modal.open_editor', 'Open in the editor')); ?></a>
				</div>
			</div>
		</div>
	</div>
</div>
</div>
