<?php
/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// abgeleitet von src/public/graph.php @ 7e2e0b6aa501ba95ac1e9789fd2bb477c281a001
// Graph der Verweise zwischen Notizen (graph.js über api/v1/graph; nur lesbare Notizen, Kanten nur zwischen ihnen).
// Abweichungen: Icon-Leiste im NC-Rahmen, <body>-Attribute über die JSON-Insel, Workspace wie die übrigen Seiten (unbekannt → Standard, §4.4).
/** @var array $_ */
/** @var \OCA\NextNotes\Service\View\PageTranslator $tr */
$tr = $_['tr'];
$frame = $_['frame'];
$view = $_['view'];
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;
$island = ['data' => ['workspace' => $frame['workspaceKey']], 'replaceWorkspace' => $frame['replaceWorkspace']];
$titleIcon = 'lucide-network';
$titleText = $tr->t('home.graph', [], 'Graph');
?>
<div id="app-content">
<div id="nextnotes-root" class="nextnotes-listpage graph-page">
	<script type="application/json" id="nextnotes-body"><?php print_unescaped(json_encode($island, $jsonFlags)); ?></script>
	<?php include __DIR__ . '/partials/icon_sidebar.php'; ?>
	<main class="nn-page-main nn-graph-main" inert>
		<div class="graph-container">
			<div class="graph-head" id="graphHead">
				<?php include __DIR__ . '/partials/page_title.php'; ?>

				<div class="graph-toolbar">
					<button type="button" id="graphViewToggle" class="graph-reset-btn graph-icon-btn initially-hidden" data-txt-view="<?php p($tr->t('graph.view_toggle', [], 'View')); ?>" data-txt-network="<?php p($tr->t('graph.view_network', [], 'Network')); ?>" data-txt-tree="<?php p($tr->t('graph.view_tree', [], 'Tree')); ?>">
						<i class="lucide lucide-share-2"></i>
					</button>
					<button type="button" id="graphSearchBtn" class="graph-reset-btn graph-icon-btn" aria-expanded="false" aria-controls="graphSearchWrapper" aria-label="<?php p($tr->t('graph.search.placeholder', [], 'Find a note or a tag...')); ?>" title="<?php p($tr->t('graph.search.placeholder', [], 'Find a note or a tag...')); ?>">
						<i class="lucide lucide-search"></i>
					</button>
					<div class="graph-search-wrapper initially-hidden" id="graphSearchWrapper">
						<input
							type="text"
							id="graphSearchInput"
							class="home-search-input graph-search-input"
							placeholder="<?php p($tr->t('graph.search.placeholder', [], 'Find a note or a tag...')); ?>"
							autocomplete="off"
						>
						<button type="button" id="graphSearchClear" class="home-search-clear" aria-label="<?php p($tr->t('search.clear', [], 'Clear search')); ?>" title="<?php p($tr->t('search.clear', [], 'Clear search')); ?>">
							<i class="lucide lucide-x"></i>
						</button>
					</div>
					<button type="button" id="graphFolderFilterBtn" class="graph-reset-btn graph-icon-btn graph-folder-btn initially-hidden" aria-haspopup="dialog" data-txt-all="<?php p($tr->t('graph.folder_filter_all', [], 'All folders')); ?>" aria-label="<?php p($tr->t('graph.folder_filter_hint', [], 'Show only the notes of one folder')); ?>" title="<?php p($tr->t('graph.folder_filter_hint', [], 'Show only the notes of one folder')); ?>">
						<i class="lucide lucide-folder"></i>
					</button>
					<div class="graph-options">
						<button type="button" id="graphSeparateGroups" class="graph-reset-btn graph-icon-btn" disabled aria-label="<?php p($tr->t('graph.separate_groups', [], 'Separate groups')); ?>" title="<?php p($tr->t('graph.separate_groups_hint', [], 'Lay out each group of linked notes apart from the others')); ?>">
							<i class="lucide lucide-boxes"></i>
						</button>
						<button type="button" id="graphResetLayout" class="graph-reset-btn graph-icon-btn" aria-label="<?php p($tr->t('graph.reset_layout', [], 'Reset layout')); ?>" title="<?php p($tr->t('graph.reset_layout_hint', [], 'Forget the saved positions and rearrange the graph automatically')); ?>">
							<i class="lucide lucide-rotate-ccw"></i>
						</button>
						<button type="button" id="graphInfoBtn" class="graph-reset-btn graph-icon-btn" aria-haspopup="dialog" aria-label="<?php p($tr->t('graph.info', [], 'Information')); ?>" title="<?php p($tr->t('graph.info', [], 'Information')); ?>">
							<i class="lucide lucide-info"></i>
						</button>
					</div>
				</div>
				<div class="graph-checks">
					<label class="graph-orphans-toggle" title="<?php p($tr->t('graph.show_orphans_hint', [], 'Show notes that have no links')); ?>">
						<input type="checkbox" id="graphShowOrphans" checked>
						<span><?php p($tr->t('graph.show_orphans', [], 'Unlinked notes')); ?></span>
					</label>
					<label class="graph-orphans-toggle" title="<?php p($tr->t('graph.show_labels_hint', [], 'Show note titles under the dots')); ?>">
						<input type="checkbox" id="graphShowLabels" checked>
						<span><?php p($tr->t('graph.show_labels', [], 'Note titles')); ?></span>
					</label>
					<label class="graph-orphans-toggle initially-hidden" title="<?php p($tr->t('graph.show_icons_hint', [], 'Show the icon chosen in the sidebar in place of the dot')); ?>">
						<input type="checkbox" id="graphShowIcons" checked>
						<span><?php p($tr->t('graph.show_icons', [], 'Custom icons')); ?></span>
					</label>
					<label class="graph-orphans-toggle initially-hidden" title="<?php p($tr->t('graph.show_folders_hint', [], 'Show each folder as a hub linked to its notes')); ?>">
						<input type="checkbox" id="graphShowFolders">
						<span><?php p($tr->t('graph.show_folders', [], 'Folders')); ?></span>
					</label>
				</div>
			</div>
			<div class="graph-canvas-wrapper" id="graphCanvasWrapper">
				<div class="graph-loading" id="graphLoading">
					<i class="lucide lucide-network"></i>
					<span><?php p($tr->t('graph.loading', [], 'Building graph...')); ?></span>
				</div>
				<div class="graph-empty initially-hidden" id="graphEmpty">
					<i class="lucide lucide-network"></i>
					<p><?php p($tr->t('graph.empty', [], 'No notes to display.')); ?></p>
					<p class="graph-empty-hint"><?php p($tr->t('graph.empty_hint', [], 'Link notes together with [[Note Title]] to see connections here.')); ?></p>
				</div>
				<svg id="graphSvg" role="img" aria-label="<?php p($tr->t('graph.title', [], 'Note graph')); ?>"></svg>
				<div class="graph-tooltip initially-hidden" id="graphTooltip" data-txt-links="<?php p($tr->t('graph.tooltip.links', [], '{{count}} links')); ?>" data-txt-folder="<?php p($tr->t('graph.tooltip.folder_notes', [], 'Folder · {{count}} notes')); ?>"></div>
				<!-- The page opens on the notes modified last: how many are shown, and the button for the rest -->
				<div class="graph-limit initially-hidden" id="graphLimit">
					<span id="graphLimitText" data-txt="<?php p($tr->t('graph.limit_notice', [], 'The {{count}} most recently modified notes out of {{total}}')); ?>"></span>
					<button type="button" id="graphShowAll" class="graph-reset-btn" title="<?php p($tr->t('graph.show_all_hint', [], 'Load every note of the workspace')); ?>"><?php p($tr->t('graph.show_all', [], 'Show all')); ?></button>
				</div>
			</div>
		</div>

		<!-- Folder filter: pick the folder the graph is narrowed to -->
		<div id="graphFolderModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="graphFolderModalTitle">
			<div class="modal-content">
				<div class="modal-header">
					<h3 id="graphFolderModalTitle"><?php p($tr->t('graph.folder_modal_title', [], 'Choose a folder')); ?></h3>
				</div>
				<div class="modal-body">
					<input type="text" id="graphFolderSearch" class="graph-folder-search" placeholder="<?php p($tr->t('notes_manager.filter_folders', [], 'Filter folders...')); ?>" autocomplete="off">
					<div id="graphFolderList" class="graph-folder-list" data-txt-empty="<?php p($tr->t('graph.folder_modal_empty', [], 'No matching folder.')); ?>"></div>
				</div>
				<div class="modal-buttons">
					<button type="button" id="graphFolderModalClose" class="btn-cancel"><?php p($tr->t('common.close', [], 'Close')); ?></button>
				</div>
			</div>
		</div>

		<!-- Information: what the page shows, and what the mouse does on it -->
		<div id="graphInfoModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="graphInfoModalTitle">
			<div class="modal-content">
				<div class="modal-header">
					<h3 id="graphInfoModalTitle"><?php p($tr->t('graph.info', [], 'Information')); ?></h3>
				</div>
				<div class="modal-body">
					<p class="graph-stats" id="graphStats" data-txt-stats="<?php p($tr->t('graph.stats', [], '{{notes}} notes · {{links}} links')); ?>"></p>
					<ul class="graph-info-help">
						<li><?php p($tr->t('graph.help_ctrl_drag', [], 'Ctrl + drag moves a dot on its own, independently of its links')); ?></li>
						<li><?php p($tr->t('graph.help_ctrl_drag_line', [], 'Ctrl + drag on a line moves the folder it comes from with everything in it')); ?></li>
						<li><?php p($tr->t('graph.help_scroll', [], 'Scroll moves the view up and down')); ?></li>
						<li><?php p($tr->t('graph.help_zoom', [], 'Ctrl + scroll zooms in and out')); ?></li>
					</ul>
				</div>
				<div class="modal-buttons">
					<button type="button" id="graphInfoModalClose" class="btn-cancel"><?php p($tr->t('common.close', [], 'Close')); ?></button>
				</div>
			</div>
		</div>
	</main>
</div>
</div>
