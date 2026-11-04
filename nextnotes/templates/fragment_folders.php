<?php
/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// abgeleitet von src/public/list_folders.php @ 7e2e0b6aa501ba95ac1e9789fd2bb477c281a001
// Fragment-Modus (X-Poznote-Fragment: folders_list): nur #foldersList nach Ordner-Aktionen (P24, list_folders.js).
/** @var array $_ */
/** @var \OCA\NextNotes\Service\View\PageTranslator $tr */
$tr = $_['tr'];
$frame = $_['frame'];
$folders = $_['folders'];
$canWrite = $frame['canWrite'];
$key = $frame['workspaceKey'];
$t = static fn (string $k, string $default) => $tr->t($k, [], $default);
?>
<div id="foldersList" class="shared-notes-list"><?php include __DIR__ . '/partials/folder_rows.php'; ?></div>
