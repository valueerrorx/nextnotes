<?php
/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// abgeleitet von src/public/tasks.php @ 7e2e0b6aa501ba95ac1e9789fd2bb477c281a001
// Aufgaben aller Aufgabenlisten und Checklisten des Workspace (tasks-page.js über api/v1/tasks, abhaken über die Notiz-/Aufgaben-API).
// Abweichungen: Icon-Leiste im NC-Rahmen, <body>-Attribute über die JSON-Insel, calendarTranslations als JSON-Insel; ausgeblendete Listen
// sind Einstellung des Benutzers (keine geliehenen Konten), Fälligkeits-Modal ohne E-Mail.
/** @var array $_ */
/** @var \OCA\NextNotes\Service\View\PageTranslator $tr */
$tr = $_['tr'];
$frame = $_['frame'];
$view = $_['view'];
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;
$t = static fn (string $key, string $default) => $tr->t($key, [], $default);
$islands = [
	'nextnotes-body' => ['data' => [
		'workspace' => $frame['workspaceKey'],
		'date-time-format' => $_['dateTimeFormat'],
		'hidden-notes' => json_encode($_['hiddenNotes'], JSON_THROW_ON_ERROR),
		'can-hide-notes' => '1',
		'txt-error' => $t('common.error', 'Error'),
		'txt-untitled' => $t('common.untitled', 'Untitled'),
		'txt-progress' => $t('tasks_page.progress', '{{completed}} of {{total}} tasks completed'),
		'txt-no-filter-results' => $t('public.no_filter_results', 'No notes match your search.'),
		'txt-empty-filtered' => $t('tasks_page.empty_filtered', 'No tasks match this filter.'),
		'txt-empty-hidden' => $t('tasks_page.empty_hidden', 'Every list is hidden.'),
		'txt-hide-list' => $t('tasks_page.hide_list', 'Hide from this page'),
		'txt-unhide-list' => $t('tasks_page.unhide_list', 'Show on this page again'),
		'txt-show-hidden' => $t('tasks_page.show_hidden', 'Show hidden lists'),
		'txt-collapse' => $t('tasks_page.collapse', 'Collapse'),
		'txt-expand' => $t('tasks_page.expand', 'Expand'),
		'txt-collapse-all' => $t('tasks_page.collapse_all', 'Collapse all'),
		'txt-expand-all' => $t('tasks_page.expand_all', 'Expand all'),
		'txt-due' => $t('tasklist.due_date', 'Due date'),
		'txt-due-remove' => $t('tasklist.due_remove', 'Remove due date'),
		'txt-due-remove-time' => $t('tasklist.due_remove_time', 'Remove time'),
		'txt-cal-no-tasks' => $t('tasks_page.calendar_no_tasks', 'No tasks on this day.'),
		'txt-note-badge' => $t('tasks_page.note_badge', 'Note'),
		'txt-note-badge-title' => $t('tasks_page.note_badge_title', 'Checklist items of a note'),
	], 'replaceWorkspace' => $frame['replaceWorkspace']],
	'nextnotes-globals' => $_['globals'],
];
$titleIcon = 'lucide-list-todo';
$titleText = $t('tasks_page.title', 'Tasks');
?>
<div id="app-content">
<div id="nextnotes-root" class="nextnotes-listpage tasks-page">
	<?php foreach ($islands as $islandId => $islandData): ?>
	<script type="application/json" id="<?php p($islandId); ?>"><?php print_unescaped(json_encode($islandData, $jsonFlags)); ?></script>
	<?php endforeach; ?>
	<?php include __DIR__ . '/partials/icon_sidebar.php'; ?>
	<main class="nn-page-main" inert>
	<div class="tasks-container">
		<?php include __DIR__ . '/partials/page_title.php'; ?>

		<div id="tasksProgressSection" class="tasks-progress-section initially-hidden">
			<div class="tasks-progress-header">
				<span id="tasksProgressLabel" class="tasks-progress-label"></span>
			</div>
			<div class="tasks-progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" id="tasksProgressBar">
				<div class="tasks-progress-fill" id="tasksProgressFill"></div>
			</div>
			<label class="tasks-progress-option" for="tasksShowNoteChecklists" title="<?php p($t('tasks_page.note_badge_title', 'Checklist items of a note')); ?>">
				<input type="checkbox" id="tasksShowNoteChecklists" checked>
				<span><?php p($t('tasks_page.show_note_checklists', 'Display tasks in notes')); ?></span>
			</label>
			<label class="tasks-progress-option disabled" id="tasksShowHiddenOption" for="tasksShowHidden">
				<input type="checkbox" id="tasksShowHidden" disabled>
				<span id="tasksShowHiddenLabel"><?php p($t('tasks_page.show_hidden', 'Show hidden lists')); ?></span>
			</label>
		</div>

		<div class="tasks-filter-bar">
			<div class="tasks-view-toggle" id="tasksViewToggle" role="group">
				<button type="button" class="tasks-view-btn active" data-view="list" title="<?php p($t('tasks_page.view_list', 'List view')); ?>">
					<i class="lucide lucide-layout-list"></i>
				</button>
				<button type="button" class="tasks-view-btn" data-view="calendar" title="<?php p($t('tasks_page.view_calendar', 'Calendar view')); ?>">
					<i class="lucide lucide-calendar"></i>
				</button>
			</div>
			<div class="tasks-filter-wrap">
				<button type="button" id="tasksFilterBtn" class="tasks-filter-btn" title="<?php p($t('tasks_page.filters', 'Filters')); ?>" aria-label="<?php p($t('tasks_page.filters', 'Filters')); ?>" aria-haspopup="true" aria-expanded="false" aria-controls="tasksFilterPanel">
					<i class="lucide lucide-filter"></i>
					<span class="tasks-filter-badge" hidden></span>
				</button>
				<div id="tasksFilterPanel" class="tasks-filter-panel" hidden>
					<div class="tasks-filter-chips" id="tasksFilterChips">
						<button type="button" class="tasks-filter-chip active" data-filter="all"><?php p($t('tasks_page.filter_all', 'All')); ?></button>
						<button type="button" class="tasks-filter-chip" data-filter="open"><?php p($t('tasks_page.filter_open', 'To do')); ?></button>
						<button type="button" class="tasks-filter-chip" data-filter="important"><?php p($t('tasks_page.filter_important', 'Important')); ?></button>
						<button type="button" class="tasks-filter-chip" data-filter="overdue"><?php p($t('tasks_page.filter_overdue', 'Overdue')); ?></button>
						<button type="button" class="tasks-filter-chip" data-filter="dated"><?php p($t('tasks_page.filter_dated', 'With due date')); ?></button>
						<button type="button" class="tasks-filter-chip" data-filter="completed"><?php p($t('tasks_page.filter_completed', 'Completed')); ?></button>
					</div>
				</div>
			</div>
			<div class="tasks-filter-search">
				<div class="filter-input-wrapper">
					<input type="text" id="filterInput" class="filter-input" placeholder="<?php p($t('tasks_page.filter_placeholder', 'Filter by task, title or folder name...')); ?>" />
					<button id="clearFilterBtn" class="clear-filter-btn initially-hidden">
						<i class="lucide lucide-x"></i>
					</button>
				</div>
				<div class="tasks-collapse-actions">
					<button type="button" id="toggleAllNotesBtn" class="tasks-collapse-btn" aria-expanded="true" title="<?php p($t('tasks_page.collapse_all', 'Collapse all')); ?>">
						<i class="lucide lucide-chevron-up"></i>
						<span id="toggleAllNotesLabel"><?php p($t('tasks_page.collapse_all', 'Collapse all')); ?></span>
					</button>
				</div>
			</div>
		</div>

		<div class="tasks-content">
			<div id="loadingSpinner" class="loading-spinner">
				<i class="lucide lucide-loader-2 lucide-spin"></i>
				<?php p($t('common.loading', 'Loading...')); ?>
			</div>
			<div id="tasksNotesContainer"></div>
			<div id="emptyMessage" class="empty-message initially-hidden">
				<p><?php p($t('tasks_page.empty', 'No tasks yet. Create a task list note or add a checklist to a note to get started.')); ?></p>
			</div>
		</div>
	</div>
	</main>

	<?php include __DIR__ . '/partials/task_due_modal.php'; ?>

	<div id="calDayModal" class="modal">
		<div class="modal-content">
			<h3 id="calDayModalTitle"></h3>
			<div id="calDayModalList"></div>
			<div class="modal-buttons">
				<button type="button" class="btn-cancel" data-action="close-modal" data-modal="calDayModal"><?php p($t('common.close', 'Close')); ?></button>
			</div>
		</div>
	</div>
</div>
</div>
