<?php
/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// abgeleitet von src/lib/workspaces.php @ 7e2e0b6aa501ba95ac1e9789fd2bb477c281a001 (poznoteRenderPageTitleWorkspace)
// Seitentitel mit Workspace-Auswahl (UI-ws): Menü baut nextnotes-late.js aus GET api/v1/workspaces, Wechsel lädt dieselbe Seite mit ?workspace=.
/** @var array $_ */
/** @var \OCA\NextNotes\Service\View\PageTranslator $tr */
/** @var string $titleIcon */
/** @var string $titleText */
/** @var bool|null $titleWorkspace false = ohne Workspace-Auswahl (Einstellungen) */
$frame = $_['frame'];
$switchLabel = $tr->t('page_title.switch_workspace', [], 'Switch workspace');
?>
<h1 class="poznote-page-title"><span class="poznote-page-title-name"><i class="lucide <?php p($titleIcon); ?>"></i> <?php p($titleText); ?></span>
	<?php if (($titleWorkspace ?? true) !== false): ?>
	<button type="button" class="poznote-page-title-workspace" id="poznotePageTitleWorkspaceBtn" data-nn-ws-chip="<?php p($frame['workspaceKey']); ?>" title="<?php p($switchLabel); ?>" aria-label="<?php p($switchLabel . ': ' . $frame['workspaceKey']); ?>" aria-haspopup="menu" aria-expanded="false"
		data-txt-manage="<?php p($tr->t('page_title.manage_workspaces', [], 'Manage workspaces')); ?>" data-txt-loading="<?php p($tr->t('workspaces.menu.loading', [], 'Loading workspaces...')); ?>" data-txt-error="<?php p($tr->t('workspaces.menu.error_loading', [], 'Error loading workspaces')); ?>" data-txt-menu="<?php p($tr->t('page_title.workspaces', [], 'Workspaces')); ?>"><?php if ($frame['workspaceColor'] !== ''): ?><span class="poznote-page-title-workspace-dot" style="background-color: <?php p($frame['workspaceColor']); ?>"></span><?php else: ?><i class="lucide lucide-layers poznote-page-title-workspace-icon" aria-hidden="true"></i><?php endif; ?><span class="poznote-page-title-workspace-name"><?php p($frame['workspaceKey']); ?></span><i class="lucide lucide-chevron-down poznote-page-title-workspace-chevron" aria-hidden="true"></i></button>
	<?php endif; ?>
</h1>
