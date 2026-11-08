<?php
/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// abgeleitet von src/public/diary.php @ 7e2e0b6aa501ba95ac1e9789fd2bb477c281a001
// Tagebuch: Karten je Monat, Journal-Ansicht (diary-page.js, Einträge über api/v1/diary/entries.php). Daten: PageController::diary, DiaryViewService.
// Abweichungen: Icon-Leiste im NC-Rahmen, Inline-Skripte → JSON-Inseln (DIARY_DATA, calendarTranslations) und Bootstrap (Journal vor dem Zeichnen),
// Seitenklassen am App-Element statt <body>, „Farben verwalten“ über data-action open-app-url, ohne „Markdown farbig“, ohne Schreibrecht keine Anlege-Knöpfe.
/** @var array $_ */
/** @var \OCA\NextNotes\Service\View\PageTranslator $tr */
$tr = $_['tr'];
$frame = $_['frame'];
$view = $_['view'];
$diary = $_['diary'];
$canWrite = $frame['canWrite'];
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;
$islands = [
	'nextnotes-body' => ['data' => ['workspace' => $frame['workspaceKey']], 'replaceWorkspace' => $frame['replaceWorkspace'], 'outline' => 'diary'],
	'nextnotes-globals' => $_['globals'],
];
$t = static fn (string $key, string $default, array $vars = []) => $tr->t($key, $vars, $default);
$titleIcon = 'lucide-book-open';
$titleText = $t('diary.title', 'Diary');
$boardPrefix = 'diary';
?>
<div id="app-content">
<div id="nextnotes-root" class="nextnotes-listpage favorites-page dashboard-page diary-page">
	<?php foreach ($islands as $islandId => $islandData): ?>
	<script type="application/json" id="<?php p($islandId); ?>"><?php print_unescaped(json_encode($islandData, $jsonFlags)); ?></script>
	<?php endforeach; ?>
	<?php include __DIR__ . '/partials/icon_sidebar.php'; ?>
	<main class="nn-page-main nn-diary-main" inert>
	<div class="favorites-container dashboard-container">
		<?php include __DIR__ . '/partials/page_title.php'; ?>

		<header class="dashboard-topbar">
			<?php if ($canWrite): ?>
			<div class="diary-actions">
				<button type="button" id="diaryNewBtn" class="btn btn-primary" title="<?php p($t('diary.new_modal_title', 'Create a new diary')); ?>">
					<i class="lucide lucide-plus"></i>
					<?php p($t('diary.new_button', 'New diary')); ?>
				</button>
				<button type="button" id="diaryTodayBtn" class="btn btn-primary">
					<i class="lucide <?php p($diary['todayExists'] ? 'lucide-calendar' : 'lucide-calendar-plus'); ?>"></i>
					<span class="diary-today-label"><?php p($diary['todayExists'] ? $t('diary.today_button_go', "Go to today's entry") : $t('diary.today_button_create', "Create today's entry")); ?></span>
				</button>
			</div>
			<?php endif; ?>
			<?php if ($diary['roots'] !== []): ?>
			<nav class="diary-switcher">
				<?php foreach ($diary['roots'] as $root): ?>
				<div class="diary-switch-btn<?php p($root['id'] === $diary['selectedId'] ? ' diary-switch-active' : ''); ?>" data-diary-id="<?php p((string)$root['id']); ?>" data-diary-name="<?php p($root['name']); ?>">
					<a class="diary-switch-link" href="<?php p($_['baseUrl'] . 'diary.php?' . http_build_query(['diary' => $root['id'], 'workspace' => $frame['workspaceKey']])); ?>">
						<i class="lucide lucide-book-open"></i>
						<?php p($root['name']); ?>
					</a>
				</div>
				<?php endforeach; ?>
			</nav>
			<?php endif; ?>
			<div class="board-filter-row">
				<button type="button" id="diaryJournalToggle" class="board-view-btn diary-journal-toggle" aria-pressed="false" title="<?php p($t('diary.journal_view', 'Journal view')); ?>">
					<i class="lucide lucide-scroll"></i>
				</button>
				<?php if ($canWrite): ?>
				<button type="button" id="diaryDatePickBtn" class="board-view-btn" aria-haspopup="true" title="<?php p($t('diary.pick_date', "Open or create another day's entry")); ?>" aria-label="<?php p($t('diary.pick_date', "Open or create another day's entry")); ?>">
					<i class="lucide lucide-calendar-alt"></i>
				</button>
				<?php endif; ?>
				<?php include __DIR__ . '/partials/board_view_menu.php'; ?>
				<div class="dashboard-color-filter-wrap">
					<button type="button" id="diaryFilterBtn" class="dashboard-color-filter-btn dashboard-filter-btn" title="<?php p($t('dashboard.filters.button', 'Filters')); ?>" aria-label="<?php p($t('dashboard.filters.button', 'Filters')); ?>" aria-haspopup="true" aria-expanded="false">
						<i class="lucide lucide-filter"></i>
						<span class="dashboard-filter-badge" hidden></span>
					</button>
					<div id="diaryFilterMenu" class="dashboard-color-filter-menu dashboard-filter-panel" hidden>
						<div class="dashboard-filter-section">
							<div class="dashboard-filter-section-title"><?php p($t('dashboard.filters.color', 'Color')); ?></div>
							<div id="diaryFilterColorSection" class="dashboard-filter-chips"></div>
						</div>
						<div class="dashboard-filter-footer" hidden>
							<button type="button" id="diaryFilterResetBtn" class="dashboard-filter-reset"><i class="lucide lucide-x"></i> <?php p($t('dashboard.filters.reset', 'Reset filters')); ?></button>
						</div>
					</div>
				</div>
				<div id="dashboardTopbarFilter" class="dashboard-topbar-filter">
					<i class="lucide lucide-search dashboard-filter-icon"></i>
					<input type="text" id="filterInput" class="dashboard-filter-input" placeholder="<?php p($t('dashboard.filter_placeholder', 'Filter by title, content or tag...')); ?>" autocomplete="off" />
					<button type="button" id="clearFilterBtn" class="dashboard-filter-clear initially-hidden" aria-label="<?php p($t('search.clear', 'Clear search')); ?>">
						<i class="lucide lucide-x"></i>
					</button>
				</div>
			</div>
		</header>

		<?php if ($diary['isEmpty']): ?>
		<div class="dashboard-empty diary-empty">
			<i class="lucide lucide-book-open"></i>
			<p><?php p($t('diary.empty', "No diary entries yet. Click \"Today's entry\" to write your first one. It will be stored in {{folder}}.", ['folder' => $diary['emptyFolder']])); ?></p>
		</div>
		<?php endif; ?>
		<div id="diaryNoResults" class="empty-message initially-hidden">
			<p><?php p($t('public.no_filter_results', 'No notes match your search.')); ?></p>
		</div>
		<div id="diaryContent" class="diary-content"></div>
	</div>

	<div class="outline-mobile-backdrop" id="outlineMobileBackdrop"></div>
	<div class="outline-resize-handle diary-outline-handle" id="outlineResizeHandle">
		<button type="button" id="toggleOutlineBtn" class="toggle-outline-btn" aria-label="<?php p($t('common.outline.title', 'Outline')); ?>" title="<?php p($t('common.outline.title', 'Outline')); ?>">
			<i class="lucide lucide-chevron-right"></i>
		</button>
	</div>
	<aside id="outline-panel" class="diary-outline" inert>
		<div class="outline-header">
			<h2 class="outline-title"><?php p($t('common.outline.title', 'Outline')); ?></h2>
			<button type="button" class="outline-close-btn" aria-label="<?php p($t('common.close', 'Close')); ?>" title="<?php p($t('common.close', 'Close')); ?>">
				<i class="lucide lucide-x"></i>
			</button>
		</div>
		<ul class="outline-nav" id="diaryOutlineNav"></ul>
	</aside>
	</main>

	<div id="noteColorModal" class="modal">
		<div class="modal-content">
			<h3 id="noteColorModalTitle"><?php p($t('note_color.modal_title', 'Note color')); ?></h3>
			<p class="note-color-modal-subtitle" id="noteColorModalNoteTitle"></p>
			<div class="note-color-grid" id="noteColorGrid" role="radiogroup"></div>
			<div class="modal-buttons">
				<button type="button" class="note-color-manage-btn" data-action="open-app-url" data-url="<?php p($_['baseUrl'] . 'settings.php?' . http_build_query(['workspace' => $_['frame']['workspaceKey'], 'open' => 'note-colors']) . '#note-color-palette-card'); ?>" title="<?php p($t('note_color.manage_button', 'Manage colors')); ?>" aria-label="<?php p($t('note_color.manage_button', 'Manage colors')); ?>"><i class="lucide lucide-palette"></i> <span class="note-color-btn-label"><?php p($t('note_color.manage_button', 'Manage colors')); ?></span></button>
				<button type="button" class="btn-danger" id="noteColorClearBtn" title="<?php p($t('note_color.remove', 'Remove color')); ?>" aria-label="<?php p($t('note_color.remove', 'Remove color')); ?>"><i class="lucide lucide-eraser"></i> <span class="note-color-btn-label"><?php p($t('note_color.remove', 'Remove color')); ?></span></button>
				<button type="button" class="btn-cancel" data-action="close-note-color-modal"><?php p($t('common.cancel', 'Cancel')); ?></button>
				<button type="button" class="btn-primary" id="noteColorApplyBtn"><?php p($t('common.apply', 'Apply')); ?></button>
			</div>
		</div>
	</div>
</div>
</div>
