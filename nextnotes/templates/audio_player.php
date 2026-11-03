<?php
/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// abgeleitet von src/public/audio_player.php @ 7e2e0b6aa501ba95ac1e9789fd2bb477c281a001
// Nur der Player; weggelassen: Inline-Skript für das Rechtsklick-Menü (CSP), Menü bleibt das des Browsers.
/** @var array $_ */
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
	* { margin: 0; padding: 0; box-sizing: border-box; }
	html, body { width: 100%; height: 100%; overflow: hidden; background: transparent; }
	body { display: flex; align-items: center; }
	audio { width: 100%; height: 100%; display: block; margin: 0; border: 0; background: transparent; border-radius: 8px; }
</style>
</head>
<body>
<audio controls preload="metadata" src="<?php p($_['src']); ?>"></audio>
</body>
</html>
