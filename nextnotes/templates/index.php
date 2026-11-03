<?php
/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// abgeleitet von src/public/index.php @ 7e2e0b6aa501ba95ac1e9789fd2bb477c281a001
// Rahmen der Hauptansicht (M3a2): Daten aus IndexViewService, Inline-Skripte → JSON-Inseln (Adapter), rechte Spalte leer bis M3b.
// Weggelassen: PWA/Manifest, Git-Sync, KI, Spracheingabe, What's new, Konten, Modals/Notizansicht (M3b), UI-Anpassung/Mobil-Leiste (M3b).
/** @var array $_ */
/** @var \OCA\NextNotes\Service\View\PageTranslator $tr */
$tr = $_['tr'];
$view = $_['view'];
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR;
$islands = [
	'poznote-config' => $view['poznoteConfig'],
	'workspace-display-map-data' => (object)$view['workspaceMap'],
	'page-config-data' => $view['pageConfig'],
	'nextnotes-globals' => $view['globals'],
	'nextnotes-body' => $view['body'] + ['replaceWorkspace' => $view['replaceWorkspace']],
];
if ($view['noteId'] !== null) {
	$islands['current-note-data'] = ['noteId' => (string)$view['noteId']];
}
?>
<div id="app-content">
<div id="nextnotes-root">
	<?php foreach ($islands as $islandId => $islandData): ?>
	<script type="application/json" id="<?php p($islandId); ?>"><?php print_unescaped(json_encode($islandData, $jsonFlags)); ?></script>
	<?php endforeach; ?>
	<div id="save-indicator" class="save-indicator" style="display: none;"></div>

	<?php include __DIR__ . '/partials/icon_sidebar.php'; ?>

	<div id="left_col">
		<div class="sidebar-header">
			<div class="sidebar-title-row">
				<div class="sidebar-title" role="button" tabindex="0" data-action="toggle-workspace-menu">
					<?php if ($view['workspaceColor'] !== ''): ?>
					<span class="workspace-title-dot" style="background-color: <?php p($view['workspaceColor']); ?>" aria-hidden="true"></span>
					<?php endif; ?>
					<span class="workspace-title-text" title="<?php p($view['workspaceKey']); ?>"><?php p($view['workspaceKey']); ?></span>
					<i class="lucide lucide-caret-down workspace-dropdown-icon"></i>
				</div>
				<div class="sidebar-title-actions">
					<?php // Erinnerungen zeigt die NC-Glocke (SPEC §6); Platzhalter für notifications-modal.js ?>
					<button class="sidebar-folder-toggle" id="sidebarNotificationsBtn" data-action="open-notifications-modal" title="<?php p($tr->t('reminder.notifications', [], 'Notifications')); ?>" aria-label="<?php p($tr->t('reminder.notifications', [], 'Notifications')); ?>" hidden>
						<i class="lucide lucide-bell"></i>
					</button>
					<?php if ($view['canWrite']): ?>
					<button class="sidebar-plus" id="sidebarCreateBtn" data-action="toggle-create-menu" title="<?php p($tr->t('sidebar.create')); ?>">
						<i class="lucide lucide-plus-circle"></i>
					</button>
					<?php endif; ?>
				</div>
				<div class="workspace-menu" id="workspaceMenu"></div>
			</div>
		</div>

		<?php include __DIR__ . '/partials/notes_list.php'; ?>
	</div>

	<div class="resize-handle" id="resizeHandle">
		<button class="toggle-sidebar-btn" id="toggleSidebarBtn" title="<?php p($tr->t('sidebar.toggle')); ?>" aria-label="<?php p($tr->t('sidebar.toggle')); ?>">
			<i class="lucide lucide-chevron-left"></i>
		</button>
	</div>

	<div id="right_pane">
		<div id="right_col"></div>
	</div>

	<div class="outline-mobile-backdrop" id="outlineMobileBackdrop"></div>
	<div class="outline-resize-handle" id="outlineResizeHandle">
		<button id="toggleOutlineBtn" class="toggle-outline-btn" aria-label="<?php p($tr->t('common.outline.toggle_panel', [], 'Toggle outline panel')); ?>" title="<?php p($tr->t('common.outline.toggle_panel', [], 'Toggle outline panel')); ?>">
			<i class="lucide lucide-chevron-right"></i>
		</button>
	</div>
	<div id="outline-panel">
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
