<?php
/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// abgeleitet von src/icon_sidebar.php @ 7e2e0b6aa501ba95ac1e9789fd2bb477c281a001
// Icon-Leiste: App-Symbol „Next Notes“ als Startseiten-Link (Dashboard), Einträge der portierten Seiten (M4e: Notizen, Ordner,
// Tags, Anhänge, Papierkorb; M4f: Aufgaben, Tagebuch; M4g: Dashboard, Graph, Speed Dial, Erwähnungen nur mit geteiltem Workspace und Zähler).
// UI-rail: oben Dashboard, Hauptansicht (#iconSidebarHomeBtn, href pflegen tabs.js/icon-sidebar-toggle.js), Aufgaben, Tagebuch; Erstellen-Seite entfernt.
// Unten Einstellungen (M4h; „Über“ entfernt, Thomas 2026-11-08: in den Einstellungen erreichbar); gestrichen: Teilen-Seite, Offline, Theme-Umschalter,
// Abmelden, Konten, Git, Profil-/Farb-/Reihenfolge-Modals (gespeicherte icon_sidebar_order wird nicht angewendet).
/** @var array $_ */
/** @var \OCA\NextNotes\Service\View\PageTranslator $tr */
$railToggleLabel = $tr->t('sidebar.toggle_icon_sidebar', [], 'Hide/Show icon sidebar');
$overflowLabel = $tr->t('sidebar.show_hidden_icons', [], 'Show hidden icons');
$railPage = $_['currentPage'] ?? 'index.php';
$railQuery = '?' . http_build_query(['workspace' => $view['workspaceKey']]);
// Gruppen wie Upstream (Trennlinie beim Gruppenwechsel); Kern-Ansichten als eigene Gruppe oben (UI-rail)
$railItems = [
	['id' => 'iconSidebarDashboardBtn', 'group' => 'start', 'page' => 'dashboard.php', 'icon' => 'lucide-layout-dashboard', 'label' => $tr->t('common.back_to_home', [], 'Dashboard')],
	['id' => 'iconSidebarHomeBtn', 'group' => 'start', 'page' => 'index.php', 'icon' => 'lucide-folder-tree', 'label' => $tr->t('common.notes', [], 'Notes')],
	['id' => 'iconSidebarTasksBtn', 'group' => 'start', 'page' => 'tasks.php', 'icon' => 'lucide-list-todo', 'label' => $tr->t('tasks_page.title', [], 'Tasks')],
	['id' => 'iconSidebarDiaryBtn', 'group' => 'start', 'page' => 'diary.php', 'icon' => 'lucide-book-open', 'label' => $tr->t('diary.title', [], 'Diary')],
	['id' => 'iconSidebarGraphBtn', 'group' => 'home', 'page' => 'graph.php', 'icon' => 'lucide-network', 'label' => $tr->t('home.graph', [], 'Graph')],
	['id' => 'iconSidebarSpeedDialBtn', 'group' => 'home', 'page' => 'speed_dial.php', 'icon' => 'lucide-zap', 'label' => $tr->t('speed_dial.title', [], 'Speed dial')],
	['id' => 'iconSidebarNotesBtn', 'group' => 'content', 'page' => 'notes_manager.php', 'icon' => 'lucide-layout-list', 'label' => $tr->t('notes_manager.age_all', [], 'All notes')],
	...(($_['railMentions'] ?? null) !== null ? [['id' => 'iconSidebarMentionsBtn', 'group' => 'content', 'page' => 'mentions.php', 'icon' => 'lucide-at-sign', 'label' => $tr->t('mentions.title', [], 'Mentions'), 'badge' => (int)$_['railMentions']]] : []),
	['id' => 'iconSidebarFoldersBtn', 'group' => 'content', 'page' => 'list_folders.php', 'icon' => 'lucide-folder-open', 'label' => $tr->t('home.folders', [], 'Folders')],
	['id' => 'iconSidebarTagsBtn', 'group' => 'collections', 'page' => 'list_tags.php', 'icon' => 'lucide-tags', 'label' => $tr->t('notes_list.system_folders.tags', [], 'Tags')],
	['id' => 'iconSidebarAttachmentsBtn', 'group' => 'collections', 'page' => 'attachments_list.php', 'icon' => 'lucide-paperclip', 'label' => $tr->t('notes_list.system_folders.attachments', [], 'Attachments')],
	['id' => 'iconSidebarTrashBtn', 'group' => 'utility', 'page' => 'trash.php', 'icon' => 'lucide-trash-2', 'label' => $tr->t('notes_list.system_folders.trash', [], 'Trash')],
];
$railBottom = [
	['id' => 'iconSidebarSettingsBtn', 'href' => $_['baseUrl'] . 'settings.php' . $railQuery, 'icon' => 'lucide-settings', 'label' => $tr->t('sidebar.settings', [], 'Settings'), 'active' => $railPage === 'settings.php'],
];
$railGroup = null;
?>
<nav id="icon_sidebar">
	<div class="icon-sidebar-scroll">
	<?php foreach ($railItems as $railItem): ?>
		<?php if ($railGroup !== null && $railGroup !== $railItem['group']): ?>
		<div class="icon-sidebar-divider" role="separator"></div>
		<?php endif; ?>
		<?php $railGroup = $railItem['group']; $railActive = $railItem['page'] === $railPage; ?>
		<a href="<?php p($_['baseUrl'] . $railItem['page'] . $railQuery); ?>" id="<?php p($railItem['id']); ?>" class="icon-sidebar-btn<?php if ($railActive): ?> icon-sidebar-btn-active<?php endif; ?>" title="<?php p($railItem['label']); ?>" aria-label="<?php p($railItem['label']); ?>"<?php if ($railActive): ?> aria-current="page"<?php endif; ?>>
			<i class="lucide <?php p($railItem['icon']); ?>"></i>
			<?php if (isset($railItem['badge'])): // offene Erwähnungen, mentions-page.js hält den Zähler aktuell ?>
			<span class="mention-badge"<?php if ($railItem['badge'] <= 0): ?> hidden<?php endif; ?>><?php p($railItem['badge'] > 99 ? '99+' : (string)$railItem['badge']); ?></span>
			<?php endif; ?>
		</a>
	<?php endforeach; ?>
	</div>
	<button type="button" id="iconSidebarOverflowBtn" class="icon-sidebar-btn" title="<?php p($overflowLabel); ?>" aria-label="<?php p($overflowLabel); ?>" aria-haspopup="true" aria-expanded="false" aria-controls="iconSidebarOverflowMenu">
		<i class="lucide lucide-more-horizontal"></i>
	</button>
	<div class="icon-sidebar-bottom">
	<?php foreach ($railBottom as $railItem): ?>
		<a href="<?php p($railItem['href']); ?>" id="<?php p($railItem['id']); ?>" class="icon-sidebar-btn<?php if ($railItem['active']): ?> icon-sidebar-btn-active<?php endif; ?>" title="<?php p($railItem['label']); ?>" aria-label="<?php p($railItem['label']); ?>"<?php if ($railItem['active']): ?> aria-current="page"<?php endif; ?>>
			<i class="lucide <?php p($railItem['icon']); ?>"></i>
		</a>
	<?php endforeach; ?>
	</div>
</nav>
<div id="iconSidebarOverflowMenu" role="menu" aria-labelledby="iconSidebarOverflowBtn"></div>
<button type="button" id="iconSidebarToggle" title="<?php p($railToggleLabel); ?>" aria-label="<?php p($railToggleLabel); ?>" aria-expanded="true" aria-controls="icon_sidebar">
	<i class="lucide lucide-chevron-left"></i>
</button>
