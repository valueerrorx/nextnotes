<?php
/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// abgeleitet von src/public/index.php @ 7e2e0b6aa501ba95ac1e9789fd2bb477c281a001
// Hauptansicht: Daten aus IndexViewService, Inline-Skripte → JSON-Inseln (Adapter), Notiz in #right_col (M3b).
// Weggelassen: PWA/Manifest, Git-Sync, KI, Spracheingabe, What's new, Konten, UI-Anpassung/Mobil-Leiste (M4).
/** @var array $_ */
/** @var \OCA\NextNotes\Service\View\PageTranslator $tr */
$tr = $_['tr'];
$view = $_['view'];
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;
$islands = [
	'poznote-config' => $view['poznoteConfig'],
	'workspace-display-map-data' => (object)$view['workspaceMap'],
	'page-config-data' => $view['pageConfig'],
	'nextnotes-globals' => $view['globals'],
	'nextnotes-body' => $view['body'] + ['replaceWorkspace' => $view['replaceWorkspace']],
];
if ($view['noteId'] !== null) {
	$islands['current-note-data'] = ['noteId' => (string)$view['noteId']];
	if ($view['note']['type'] === 'tasklist') {
		$islands['tasklist-init-data'] = [$view['noteId']];
	} elseif ($view['note']['type'] === 'markdown') {
		$islands['markdown-init-data'] = [$view['noteId']];
	}
}
?>
<div id="app-content">
<div id="nextnotes-root">
	<?php foreach ($islands as $islandId => $islandData): ?>
	<script type="application/json" id="<?php p($islandId); ?>"><?php print_unescaped(json_encode($islandData, $jsonFlags)); ?></script>
	<?php endforeach; ?>
	<div id="save-indicator" class="save-indicator" style="display: none;"></div>

	<?php include __DIR__ . '/partials/modals.php'; ?>

	<?php include __DIR__ . '/partials/icon_sidebar.php'; ?>

	<?php include __DIR__ . '/partials/left_col.php'; ?>

	<div class="resize-handle" id="resizeHandle">
		<button class="toggle-sidebar-btn" id="toggleSidebarBtn" title="<?php p($tr->t('sidebar.toggle')); ?>" aria-label="<?php p($tr->t('sidebar.toggle')); ?>">
			<i class="lucide lucide-chevron-left"></i>
		</button>
	</div>

	<div id="right_pane" inert>
		<div id="right_col"><?php if ($view['note'] !== null) {
			$note = $view['note'];
			include __DIR__ . '/partials/note_display.php';
		} ?></div>
	</div>

	<div class="outline-mobile-backdrop" id="outlineMobileBackdrop"></div>
	<div class="outline-resize-handle" id="outlineResizeHandle">
		<button id="toggleOutlineBtn" class="toggle-outline-btn" aria-label="<?php p($tr->t('common.outline.toggle_panel', [], 'Toggle outline panel')); ?>" title="<?php p($tr->t('common.outline.toggle_panel', [], 'Toggle outline panel')); ?>">
			<i class="lucide lucide-chevron-right"></i>
		</button>
	</div>
	<div id="outline-panel" inert>
		<div class="outline-header">
			<h2 class="outline-title"><?php p($tr->t('common.outline.title', [], 'Outline')); ?></h2>
			<button type="button" class="outline-close-btn" aria-label="<?php p($tr->t('common.close')); ?>" title="<?php p($tr->t('common.close')); ?>">
				<i class="lucide lucide-x"></i>
			</button>
		</div>
		<ul class="outline-nav" id="outline-nav">
			<li class="outline-empty">
				<div class="outline-empty-icon">📄</div>
				<p class="outline-empty-text"><?php p($tr->t('common.outline.no_headings', [], 'No headings in this note')); ?></p>
			</li>
		</ul>
	</div>
</div>
</div>
