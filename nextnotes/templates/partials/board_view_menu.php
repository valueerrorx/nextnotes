<?php
/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// abgeleitet von src/functions.php @ 7e2e0b6aa501ba95ac1e9789fd2bb477c281a001 (renderBoardViewMenu)
// Ansichtsknöpfe der Karten-Boards (board-view-menu.js): Raster/Liste/Größe, volle Höhe. $boardPrefix: 'diary' (M4f), 'dashboard' (M4g).
/** @var \OCA\NextNotes\Service\View\PageTranslator $tr */
/** @var string $boardPrefix */
$bt = static fn (string $key, string $default) => $tr->t($key, [], $default);
?>
<div class="board-view-controls" data-view-prefix="<?php p($boardPrefix); ?>">
	<button type="button" id="<?php p($boardPrefix); ?>ViewLayoutBtn" class="board-view-btn board-view-layout-toggle"
		data-badge-small="S" data-badge-medium="M" data-badge-large="L" data-badge-wide="W"
		data-label-grid="<?php p($bt('dashboard.view.layout_grid', 'Grid')); ?>" data-label-list="<?php p($bt('dashboard.view.layout_list', 'List')); ?>"
		data-label-small="<?php p($bt('dashboard.view.size_small', 'Small')); ?>" data-label-medium="<?php p($bt('dashboard.view.size_medium', 'Medium')); ?>"
		data-label-large="<?php p($bt('dashboard.view.size_large', 'Large')); ?>" data-label-wide="<?php p($bt('dashboard.view.size_wide', 'Wide')); ?>"><i class="lucide lucide-grid"></i><i class="lucide lucide-layout-list"></i><span class="board-view-size-letter"></span></button>
	<button type="button" id="<?php p($boardPrefix); ?>ViewFullHeightBtn" class="board-view-btn board-view-full-height-toggle" aria-pressed="false"
		title="<?php p($bt('dashboard.view.full_height', 'Full-height cards')); ?>" aria-label="<?php p($bt('dashboard.view.full_height', 'Full-height cards')); ?>"><i class="lucide lucide-move-vertical"></i></button>
</div>
