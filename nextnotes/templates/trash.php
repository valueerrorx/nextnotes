<?php
/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// abgeleitet von src/public/trash.php @ 7e2e0b6aa501ba95ac1e9789fd2bb477c281a001
// Papierkorb des Workspace (trash.js, Papierkorb-/Notiz-API). Daten: PageController::trash, TrashViewService.
// Abweichungen: Icon-Leiste im NC-Rahmen, Suche per POST mit requesttoken (Begriff danach in der URL, B59), ?sort= ohne Speichern (B58), Vorschau aus den
// ersten 16 KiB, je 50 mit „Mehr laden“ statt fester Grenze, ohne Schreibrecht keine Aktionsknöpfe, Inline-Skript → JSON-Insel.
/** @var array $_ */
/** @var \OCA\NextNotes\Service\View\PageTranslator $tr */
$tr = $_['tr'];
$frame = $_['frame'];
$view = $_['view'];
$trash = $_['trash'];
$canWrite = $frame['canWrite'];
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;
$islands = [
	'nextnotes-body' => ['data' => ['workspace' => $frame['workspaceKey'], 'date-time-format' => $frame['dateTimeFormat']],
		'replaceWorkspace' => $frame['replaceWorkspace'], 'replaceQuery' => $trash['search'] !== '' ? ['search' => $trash['search'], 'sort' => $trash['sort']] : new \stdClass()],
	'nextnotes-globals' => ['DEFAULT_NOTE_TITLES' => $tr->defaultNoteTitles()],
];
$t = static fn (string $key, string $default, array $vars = []) => $tr->t($key, $vars, $default);
$search = $trash['search'];
$sortQuery = static fn (string $sort) => '?' . http_build_query(array_filter(['sort' => $sort, 'search' => $search, 'workspace' => $frame['workspaceKey']], static fn ($v) => $v !== ''));
$titleIcon = 'lucide-trash-2';
$titleText = $t('notes_list.system_folders.trash', 'Trash');
?>
<div id="app-content">
<div id="nextnotes-root" class="nextnotes-listpage">
	<?php foreach ($islands as $islandId => $islandData): ?>
	<script type="application/json" id="<?php p($islandId); ?>"><?php print_unescaped(json_encode($islandData, $jsonFlags)); ?></script>
	<?php endforeach; ?>
	<?php include __DIR__ . '/partials/icon_sidebar.php'; ?>
	<main class="nn-page-main" inert>
	<div class="trash-container">
		<?php include __DIR__ . '/partials/page_title.php'; ?>

		<?php if ($search !== ''): ?>
		<div class="trash-search-notice">
			<?php p($t('trash.search.results_for', 'Results for "{{term}}"', ['term' => $search])); ?>
			<span class="trash-clear-search"><i class="lucide lucide-x"></i></span>
		</div>
		<?php endif; ?>

		<?php if ($canWrite): ?>
		<div class="trash-buttons-container">
			<button class="btn btn-danger" id="emptyTrashBtn" title="<?php p($t('trash.actions.empty_trash', 'Empty trash')); ?>"><?php p($t('trash.actions.empty_trash', 'Empty trash')); ?></button>
		</div>
		<?php endif; ?>

		<div class="trash-filter-bar">
			<div class="filter-input-wrapper">
				<form action="trash.php" method="POST" id="trashSearchForm">
					<input type="hidden" name="requesttoken" value="<?php p($_['requesttoken']); ?>">
					<input type="hidden" name="workspace" value="<?php p($frame['workspaceKey']); ?>">
					<input type="hidden" name="sort" value="<?php p($trash['sort']); ?>">
					<input type="text" name="search" id="searchInput" class="trash-search-input" placeholder="<?php p($t('trash.search.placeholder', 'Search in trash...')); ?>" value="<?php p($search); ?>" autocomplete="off">
				</form>
				<?php if ($search !== ''): ?>
				<button id="clearTrashSearchBtn" class="clear-filter-btn"><i class="lucide lucide-x"></i></button>
				<?php endif; ?>
			</div>
			<div class="trash-sort-toggle" role="group" aria-label="<?php p($t('trash.sort.label', 'Sort notes')); ?>">
				<?php foreach (['trashed' => $t('trash.sort.deleted', 'Deletion date'), 'created' => $t('trash.sort.created', 'Creation date')] as $sortKey => $sortLabel): ?>
				<a class="trash-sort-option<?php if ($trash['sort'] === $sortKey): ?> is-active<?php endif; ?>" href="<?php p($_['baseUrl'] . 'trash.php' . $sortQuery($sortKey)); ?>" aria-current="<?php p($trash['sort'] === $sortKey ? 'true' : 'false'); ?>"><?php p($sortLabel); ?></a>
				<?php endforeach; ?>
			</div>
			<div class="trash-page-count"><?php p($trash['total'] === 1 ? $t('trash.count_one', '1 note in trash') : $t('trash.count_other', '{{count}} notes in trash', ['count' => (string)$trash['total']])); ?></div>
		</div>

		<div class="trash-content">
			<?php include __DIR__ . '/partials/trash_cards.php'; ?>
			<?php if ($trash['notes'] === []): ?>
			<div class="trash-no-notes">
				<div class="trash-no-notes-icon"><i class="lucide lucide-trash"></i></div>
				<div class="trash-no-notes-text"><?php p($t('trash.empty', 'No notes in trash.')); ?></div>
			</div>
			<?php endif; ?>
		</div>
	</div>
	</main>

	<div id="emptyTrashConfirmModal" class="modal">
		<div class="modal-content">
			<h3><?php p($t('trash.modals.empty.title', 'Empty Trash')); ?></h3>
			<p><?php p($t('trash.modals.empty.message', 'Do you want to empty the trash completely? This action cannot be undone.')); ?></p>
			<div class="modal-buttons">
				<button type="button" class="btn-cancel"><?php p($t('common.cancel', 'Cancel')); ?></button>
				<button type="button" class="btn-danger"><?php p($t('trash.actions.empty_trash', 'Empty trash')); ?></button>
			</div>
		</div>
	</div>

	<div id="infoModal" class="modal">
		<div class="modal-content">
			<h3 id="infoModalTitle"><?php p($t('common.information', 'Information')); ?></h3>
			<p id="infoModalMessage"></p>
			<div class="modal-buttons">
				<button type="button" class="btn-primary"><?php p($t('common.close', 'Close')); ?></button>
			</div>
		</div>
	</div>

	<div id="restoreConfirmModal" class="modal">
		<div class="modal-content">
			<h3><?php p($t('trash.modals.restore.title', 'Restore Note')); ?></h3>
			<p><?php p($t('trash.modals.restore.message', 'Do you want to restore this note?')); ?></p>
			<div class="modal-buttons">
				<button type="button" class="btn-cancel"><?php p($t('common.cancel', 'Cancel')); ?></button>
				<button type="button" class="btn-primary"><?php p($t('trash.actions.restore', 'Restore')); ?></button>
			</div>
		</div>
	</div>

	<div id="deleteConfirmModal" class="modal">
		<div class="modal-content">
			<h3><?php p($t('trash.modals.delete.title', 'Permanently Delete Note')); ?></h3>
			<p><?php p($t('trash.modals.delete.message', 'Do you want to permanently delete this note? This action cannot be undone.')); ?></p>
			<div class="modal-buttons">
				<button type="button" class="btn-cancel"><?php p($t('common.cancel', 'Cancel')); ?></button>
				<button type="button" class="btn-danger"><?php p($t('trash.actions.delete_forever', 'Delete Forever')); ?></button>
			</div>
		</div>
	</div>
</div>
</div>
