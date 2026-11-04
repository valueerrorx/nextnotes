<?php
/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// abgeleitet von src/public/trash.php @ 7e2e0b6aa501ba95ac1e9789fd2bb477c281a001
// Fragment-Modus (X-Poznote-Fragment: trash_cards): nächste Karten ab dem Cursor für „Mehr laden“ (nextnotes-late.js).
/** @var array $_ */
/** @var \OCA\NextNotes\Service\View\PageTranslator $tr */
$tr = $_['tr'];
$frame = $_['frame'];
$trash = $_['trash'];
$canWrite = $frame['canWrite'];
$t = static fn (string $key, string $default, array $vars = []) => $tr->t($key, $vars, $default);
?>
<div id="trashCardsFragment"><?php include __DIR__ . '/partials/trash_cards.php'; ?></div>
