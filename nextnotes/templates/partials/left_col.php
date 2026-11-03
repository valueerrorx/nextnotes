<?php
/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// abgeleitet von src/public/index.php @ 7e2e0b6aa501ba95ac1e9789fd2bb477c281a001
// #left_col der Hauptansicht; eingebunden von index.php und fragment_left.php (P18). Erwartet $tr, $view, $_['baseUrl'].
?>
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

	<?php include __DIR__ . '/notes_list.php'; ?>
</div>
