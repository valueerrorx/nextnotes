<?php
/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// abgeleitet von src/lib/workspaces.php @ 7e2e0b6aa501ba95ac1e9789fd2bb477c281a001 (poznoteRenderPageTitleWorkspace)
// Seitentitel der Listen-Seiten mit Workspace-Chip; ohne Umschaltmenü (Workspace wechselt über die Hauptansicht, Icon-Leiste behält ihn).
/** @var array $_ */
/** @var \OCA\NextNotes\Service\View\PageTranslator $tr */
/** @var string $titleIcon */
/** @var string $titleText */
$frame = $_['frame'];
?>
<h1 class="poznote-page-title"><span class="poznote-page-title-name"><i class="lucide <?php p($titleIcon); ?>"></i> <?php p($titleText); ?></span>
	<span class="poznote-page-title-workspace poznote-page-title-workspace-static" title="<?php p($frame['workspaceKey']); ?>"><?php if ($frame['workspaceColor'] !== ''): ?><span class="poznote-page-title-workspace-dot" style="background-color: <?php p($frame['workspaceColor']); ?>"></span><?php else: ?><i class="lucide lucide-layers poznote-page-title-workspace-icon" aria-hidden="true"></i><?php endif; ?><span class="poznote-page-title-workspace-name"><?php p($frame['workspaceKey']); ?></span></span>
</h1>
