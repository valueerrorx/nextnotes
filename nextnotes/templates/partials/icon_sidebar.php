<?php
/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// abgeleitet von src/icon_sidebar.php @ 7e2e0b6aa501ba95ac1e9789fd2bb477c281a001
// Icon-Leiste: App-Symbol „Next Notes“ als Startseiten-Link, Einträge der portierten Seiten (M4e: Erstellen, Notizen, Ordner,
// Tags, Anhänge, Papierkorb). Weggelassen bis zur Portierung: Dashboard, Graph, Speed Dial, Aufgaben, Tagebuch (M4f/M4g),
// Einstellungen/Über (M4h); gestrichen: Teilen-Seite, Offline, Theme-Umschalter, Abmelden, Konten, Git, Profil-/Farb-/Reihenfolge-Modals.
/** @var array $_ */
/** @var \OCA\NextNotes\Service\View\PageTranslator $tr */
$homeLabel = $tr->t('common.home', [], 'Home');
$railToggleLabel = $tr->t('sidebar.toggle_icon_sidebar', [], 'Hide/Show icon sidebar');
$overflowLabel = $tr->t('sidebar.show_hidden_icons', [], 'Show hidden icons');
$railPage = $_['currentPage'] ?? 'index.php';
$railQuery = '?' . http_build_query(['workspace' => $view['workspaceKey']]);
// Gruppen wie Upstream (Trennlinie beim Gruppenwechsel); „Erstellen“ als eigene Gruppe oben (Plan M4e)
$railItems = [
	['id' => 'iconSidebarCreateBtn', 'group' => 'create', 'page' => 'create.php', 'icon' => 'lucide-plus-circle', 'label' => $tr->t('sidebar.create', [], 'Create')],
	['id' => 'iconSidebarNotesBtn', 'group' => 'content', 'page' => 'notes_manager.php', 'icon' => 'lucide-sticky-note', 'label' => $tr->t('common.notes', [], 'Notes')],
	['id' => 'iconSidebarFoldersBtn', 'group' => 'content', 'page' => 'list_folders.php', 'icon' => 'lucide-folder-open', 'label' => $tr->t('home.folders', [], 'Folders')],
	['id' => 'iconSidebarTagsBtn', 'group' => 'collections', 'page' => 'list_tags.php', 'icon' => 'lucide-tags', 'label' => $tr->t('notes_list.system_folders.tags', [], 'Tags')],
	['id' => 'iconSidebarAttachmentsBtn', 'group' => 'collections', 'page' => 'attachments_list.php', 'icon' => 'lucide-paperclip', 'label' => $tr->t('notes_list.system_folders.attachments', [], 'Attachments')],
	['id' => 'iconSidebarTrashBtn', 'group' => 'utility', 'page' => 'trash.php', 'icon' => 'lucide-trash-2', 'label' => $tr->t('notes_list.system_folders.trash', [], 'Trash')],
];
$railGroup = null;
?>
<nav id="icon_sidebar">
	<a href="<?php p($_['baseUrl'] . 'index.php' . $railQuery); ?>" id="iconSidebarHomeBtn" class="icon-sidebar-logo" title="<?php p($homeLabel); ?>" aria-label="<?php p($homeLabel); ?>"<?php if ($railPage === 'index.php'): ?> aria-current="page"<?php endif; ?>>
		<span class="poznote-logo nextnotes-logo" aria-hidden="true"></span>
	</a>
	<div class="icon-sidebar-scroll">
	<?php foreach ($railItems as $railItem): ?>
		<?php if ($railGroup !== null && $railGroup !== $railItem['group']): ?>
		<div class="icon-sidebar-divider" role="separator"></div>
		<?php endif; ?>
		<?php $railGroup = $railItem['group']; $railActive = $railItem['page'] === $railPage; ?>
		<a href="<?php p($_['baseUrl'] . $railItem['page'] . $railQuery); ?>" id="<?php p($railItem['id']); ?>" class="icon-sidebar-btn<?php if ($railActive): ?> icon-sidebar-btn-active<?php endif; ?>" title="<?php p($railItem['label']); ?>" aria-label="<?php p($railItem['label']); ?>"<?php if ($railActive): ?> aria-current="page"<?php endif; ?>>
			<i class="lucide <?php p($railItem['icon']); ?>"></i>
		</a>
	<?php endforeach; ?>
	</div>
	<button type="button" id="iconSidebarOverflowBtn" class="icon-sidebar-btn" title="<?php p($overflowLabel); ?>" aria-label="<?php p($overflowLabel); ?>" aria-haspopup="true" aria-expanded="false" aria-controls="iconSidebarOverflowMenu">
		<i class="lucide lucide-more-horizontal"></i>
	</button>
	<div class="icon-sidebar-bottom"></div>
</nav>
<div id="iconSidebarOverflowMenu" role="menu" aria-labelledby="iconSidebarOverflowBtn"></div>
<button type="button" id="iconSidebarToggle" title="<?php p($railToggleLabel); ?>" aria-label="<?php p($railToggleLabel); ?>" aria-expanded="true" aria-controls="icon_sidebar">
	<i class="lucide lucide-chevron-left"></i>
</button>
