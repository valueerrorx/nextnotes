<?php
/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// abgeleitet von src/icon_sidebar.php @ 7e2e0b6aa501ba95ac1e9789fd2bb477c281a001
// Icon-Leiste: App-Symbol „Next Notes“ als Startseiten-Link. Weggelassen bis zur Portierung (M4): Einträge auf Seiten
// (Dashboard, Graph, Speed Dial, Notizen, Aufgaben, Ordner, Tagebuch, Tags, Anhänge, Papierkorb, Einstellungen, Über);
// gestrichen: Teilen-Seite, Offline, Theme-Umschalter, Abmelden, Konten, Git, Profil-/Farb-/Reihenfolge-Modals.
/** @var array $_ */
/** @var \OCA\NextNotes\Service\View\PageTranslator $tr */
$homeLabel = $tr->t('common.home', [], 'Home');
$railToggleLabel = $tr->t('sidebar.toggle_icon_sidebar', [], 'Hide/Show icon sidebar');
$overflowLabel = $tr->t('sidebar.show_hidden_icons', [], 'Show hidden icons');
?>
<nav id="icon_sidebar">
	<a href="<?php p($_['baseUrl'] . 'index.php?' . http_build_query(['workspace' => $view['workspaceKey']])); ?>" id="iconSidebarHomeBtn" class="icon-sidebar-logo" title="<?php p($homeLabel); ?>" aria-label="<?php p($homeLabel); ?>" aria-current="page">
		<span class="poznote-logo nextnotes-logo" aria-hidden="true"></span>
	</a>
	<div class="icon-sidebar-scroll"></div>
	<button type="button" id="iconSidebarOverflowBtn" class="icon-sidebar-btn" title="<?php p($overflowLabel); ?>" aria-label="<?php p($overflowLabel); ?>" aria-haspopup="true" aria-expanded="false" aria-controls="iconSidebarOverflowMenu">
		<i class="lucide lucide-more-horizontal"></i>
	</button>
	<div class="icon-sidebar-bottom"></div>
</nav>
<div id="iconSidebarOverflowMenu" role="menu" aria-labelledby="iconSidebarOverflowBtn"></div>
<button type="button" id="iconSidebarToggle" title="<?php p($railToggleLabel); ?>" aria-label="<?php p($railToggleLabel); ?>" aria-expanded="true" aria-controls="icon_sidebar">
	<i class="lucide lucide-chevron-left"></i>
</button>
