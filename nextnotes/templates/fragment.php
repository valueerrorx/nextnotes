<?php
/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// abgeleitet von src/public/index.php @ 7e2e0b6aa501ba95ac1e9789fd2bb477c281a001
// Fragment-Modus (X-Poznote-Fragment: right_col, index.php:50-58, 1022-1044): nur #right_col für note-loader.js.
/** @var array $_ */
/** @var \OCA\NextNotes\Service\View\PageTranslator $tr */
$tr = $_['tr'];
?>
<div id="right_col"><?php if ($_['note'] !== null) {
	$note = $_['note'];
	include __DIR__ . '/partials/note_display.php';
} ?></div>
