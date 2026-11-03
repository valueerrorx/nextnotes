<?php
/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// abgeleitet von src/public/index.php @ 7e2e0b6aa501ba95ac1e9789fd2bb477c281a001
// Fragment-Modus (X-Poznote-Fragment: left_col): nur #left_col für refreshNotesListAfterFolderAction (P18).
/** @var array $_ */
/** @var \OCA\NextNotes\Service\View\PageTranslator $tr */
$tr = $_['tr'];
$view = $_['view'];
include __DIR__ . '/partials/left_col.php';
