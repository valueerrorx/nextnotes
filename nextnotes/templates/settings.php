<?php
/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// abgeleitet von src/public/settings.php @ 7e2e0b6aa501ba95ac1e9789fd2bb477c281a001 (mit settings_shell.php, workspaces_section.php)
// Persönliche Einstellungen: nur Karten mit Funktion im Port, IDs wie Upstream (settings-page.js, Anheften, Elementsichtbarkeit).
// Gestrichen: Abschnitt „Mein Konto“ (Profil, Sprache/Zeitzone im NC-Benutzermenü; Datumsformat → Anzeige, Speicher → Workspaces), Konto/Passwort/2FA/App-Passwörter/Konto löschen, Backup/Restore/Import,
// Git, Offline, Integrationen, Apps, Admin-Werkzeuge, Schriften, Login-Titel, Icon-Leisten-Reihenfolge/-Größe, What's new,
// Diskussionen/Discord/Tipps/Spenden, Update-Prüfung, Kontext-Anpassungspanel. Daten: PageController::settings.
/** @var array $_ */
/** @var \OCP\IL10N $l */
/** @var \OCA\NextNotes\Service\View\PageTranslator $tr */
$tr = $_['tr'];
$frame = $_['frame'];
$view = $_['view'];
$page = $_['settingsPage'];
$baseUrl = $_['baseUrl'];
$wsKey = $frame['workspaceKey'];
$wsQuery = http_build_query(['workspace' => $wsKey]);
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;
$t = static fn (string $key, ?string $default = null, array $vars = []) => $tr->t($key, $vars, $default);
$several = count($page['rows']) > 1;
// eigene Workspaces löschen nur, wenn ein weiterer eigener bleibt (B18); Team-Workspaces nach Q2
$severalOwn = $page['ownCount'] > 1;
$sections = [
	['key' => 'settings-pinned-section-grid', 'icon' => 'lucide-pin', 'label' => $t('settings.categories.pinned', 'Pinned')],
	['key' => 'settings-workspaces-section-grid', 'icon' => 'lucide-layers', 'label' => $t('settings.categories.workspaces', 'Workspaces')],
	['key' => 'settings-export-import-section-grid', 'icon' => 'lucide-arrow-up-down', 'label' => $t('settings.groups.export', 'Export')],
	['key' => 'settings-display-section-grid', 'icon' => 'lucide-monitor', 'label' => $t('settings.categories.display', 'Display')],
	['key' => 'settings-ui-customization-section-grid', 'icon' => 'lucide-eye-off', 'label' => $t('settings.categories.ui_customization', 'Element visibility')],
	['key' => 'settings-sidebar-section-grid', 'icon' => 'lucide-panel-left', 'label' => $t('settings.categories.sidebar', 'Sidebar')],
	['key' => 'settings-note-content-section-grid', 'icon' => 'lucide-file-text', 'label' => $t('settings.categories.note_content', 'Note content')],
	['key' => 'settings-markdown-section-grid', 'icon' => 'lucide-file-code', 'label' => $t('settings.categories.markdown', 'Markdown')],
	['key' => 'settings-diary-section-grid', 'icon' => 'lucide-book-open', 'label' => $t('settings.categories.diary', 'Diary')],
	['key' => 'settings-documentation-section-grid', 'icon' => 'lucide-info', 'label' => $t('settings.categories.documentation', 'About')],
];
$islands = [
	'nextnotes-body' => [
		'data' => [
			'workspace' => $wsKey,
			'workspaces' => json_encode($page['names'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
			'current-user-id' => $_['uid'],
			'can-share' => '1',
			'txt-workspace-share-enable-btn' => $t('workspaces.share.actions.enable', 'Share'),
			'txt-workspace-share-edit-btn' => $t('workspaces.share.actions.edit', 'Edit share'),
			'txt-workspace-share-disable-btn' => $t('workspaces.share.actions.disable', 'Unshare'),
			'txt-workspace-share-cancel' => $t('common.cancel', 'Cancel'),
			'txt-enabled' => $t('common.enabled'),
			'txt-disabled' => $t('common.disabled'),
			'txt-not-defined' => $t('common.not_defined'),
			'txt-saved' => $t('common.saved'),
			'txt-error' => $t('common.error'),
			'txt-workspace-info-not-shared' => $t('workspaces.share.status.not_shared', 'Not shared'),
			'txt-yes' => $t('common.yes', 'Yes'),
			'txt-no' => $t('common.no', 'No'),
			'txt-none' => $t('common.none', 'None'),
		],
		'replaceWorkspace' => $frame['replaceWorkspace'],
	],
	'nextnotes-globals' => [
		'NOTE_COLOR_DEFAULT_PALETTE' => $page['defaultPalette'],
		'NOTE_COLOR_LOCALIZED_NAMES' => (object)$page['localizedNames'],
		'NOTE_COLOR_KNOWN_NAMES' => (object)$page['knownNames'],
	],
	'page-config-data' => ['canUseSettingsApi' => true, 'settings' => (object)$page['settings'], 'passwordStatus' => null],
];
$titleIcon = 'lucide-settings';
$titleText = $t('settings.title', 'Settings');
$titleWorkspace = false; // UI-ws: Einstellungen gelten überwiegend für alle Workspaces, keine Auswahl im Titel
$filterLabel = $t('workspaces.filter.placeholder', 'Filter by name or tag...');
$rowLabels = [
	'drag' => $t('workspaces.order.handle', 'Drag to reorder'),
	'rename' => $t('common.rename', 'Rename'),
	'tags' => $t('workspaces.tags.action', 'Tags'),
	'color' => $t('workspaces.color.action', 'Color'),
	'background' => $t('workspaces.actions.background', 'Background'),
	'move' => $t('workspaces.actions.move_notes', 'Move notes'),
	'delete' => $t('common.delete', 'Delete'),
	'info' => $t('common.information', 'Information'),
	'menu' => $t('workspaces.actions.menu', 'Workspace actions'),
	'select' => $t('workspaces.actions.select', 'Select'),
	'share' => $t('workspaces.share.actions.enable', 'Share'),
	'shareEdit' => $t('workspaces.share.actions.edit', 'Edit share'),
	'team' => $l->t('Team workspace'),
];
$jsonAttr = static fn (mixed $v): string => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
?>
<div id="app-content">
<div id="nextnotes-root" class="nextnotes-listpage">
	<?php foreach ($islands as $islandId => $islandData): ?>
	<script type="application/json" id="<?php p($islandId); ?>"><?php print_unescaped(json_encode($islandData, $jsonFlags)); ?></script>
	<?php endforeach; ?>
	<?php include __DIR__ . '/partials/icon_sidebar.php'; ?>
	<main class="nn-page-main" inert>
	<div class="home-container settings-with-nav settings-shell">
		<?php include __DIR__ . '/partials/page_title.php'; ?>

		<div class="settings-layout">
		<aside class="settings-sidebar">
			<div class="settings-filter-row">
				<div class="home-search-wrapper">
					<i class="lucide lucide-search home-search-icon"></i>
					<input type="text" id="home-search-input" class="home-search-input" placeholder="<?php p($t('home.filter_placeholder', 'Filter...')); ?>" autocomplete="off">
					<button type="button" id="home-search-clear" class="home-search-clear" aria-label="<?php p($t('search.clear', 'Clear search')); ?>" title="<?php p($t('search.clear', 'Clear search')); ?>">
						<i class="lucide lucide-x"></i>
					</button>
				</div>
				<button type="button" id="settingsCollapseAll" class="settings-collapse-all"
					data-label-collapse="<?php p($t('settings.collapse_all_sections', 'Collapse all')); ?>"
					data-label-expand="<?php p($t('settings.expand_all_sections', 'Expand all')); ?>">
					<i class="lucide lucide-chevron-down"></i>
				</button>
			</div>
			<nav id="settings-nav" class="settings-nav" aria-label="<?php p($t('settings.title', 'Settings')); ?>">
				<?php foreach ($sections as $section): ?>
				<a class="settings-nav-item" data-section="<?php p($section['key']); ?>" href="<?php p($baseUrl . 'settings.php?' . $wsQuery . '#section=' . $section['key']); ?>"<?php if ($section['key'] === 'settings-pinned-section-grid'): ?> hidden<?php endif; ?>>
					<i class="lucide <?php p($section['icon']); ?>"></i>
					<span class="settings-nav-label"><?php p($section['label']); ?></span>
				</a>
				<?php endforeach; ?>
			</nav>
		</aside>
		<div class="settings-content">

		<h2 class="settings-category-title" id="settings-pinned-section-title" hidden><?php p($t('settings.categories.pinned', 'Pinned')); ?></h2>
		<div class="home-grid" id="settings-pinned-section-grid" hidden></div>

		<!-- Workspaces: eigene (Upstream workspaces_section.php, Teilen M5d) und Team-Workspaces mit Kennzeichen; Verwalten nur mit manage -->
		<h2 class="settings-category-title" id="settings-workspaces-section-title"><?php p($t('settings.categories.workspaces', 'Workspaces')); ?></h2>
		<div class="home-grid settings-grouped" id="settings-workspaces-section-grid">
			<div class="settings-group">
				<div class="settings-workspaces-list workspace-list" id="settings-workspaces-list">
					<div id="topAlert" class="initially-hidden alert-with-margin"></div>
					<?php if ($_['contactsUrl'] !== null): ?>
					<p class="nn-manage-teams"><a href="<?php p($_['contactsUrl']); ?>"><i class="lucide lucide-users"></i> <?php p($l->t('Manage teams')); ?></a></p>
					<?php endif; ?>
					<?php if ($several): ?>
					<div class="home-search-wrapper ws-filter">
						<i class="lucide lucide-search home-search-icon"></i>
						<input type="text" id="workspace-filter-input" class="home-search-input" autocomplete="off" placeholder="<?php p($filterLabel); ?>" aria-label="<?php p($filterLabel); ?>">
						<button type="button" id="workspace-filter-clear" class="home-search-clear" aria-label="<?php p($t('search.clear', 'Clear search')); ?>" title="<?php p($t('search.clear', 'Clear search')); ?>">
							<i class="lucide lucide-x"></i>
						</button>
					</div>
					<?php endif; ?>
					<?php if ($page['rows'] === []): ?>
					<div class="ws-list-empty"><?php p($t('workspaces.sections.existing.empty', 'No workspaces defined.')); ?></div>
					<?php else: ?>
					<ul>
						<?php foreach ($page['rows'] as $row): ?>
						<?php $shared = $row['share'] !== null && $row['share']['shared']; ?>
						<li class="ws-row<?php if ($row['team'] !== null): ?> nn-team-row<?php endif; ?>" data-ws="<?php p($row['name']); ?>">
							<?php if ($several): ?>
							<div class="ws-col ws-col-order">
								<button type="button" class="ws-drag-handle" title="<?php p($rowLabels['drag']); ?>" aria-label="<?php p($rowLabels['drag']); ?>">
									<i class="lucide lucide-grip-vertical"></i>
								</button>
							</div>
							<?php endif; ?>
							<div class="ws-col ws-col-name">
								<div class="ws-name-block">
									<div class="ws-name-row">
										<?php if ($row['colorHex'] !== ''): ?>
										<span class="ws-color-dot" style="background-color: <?php p($row['colorHex']); ?>" title="<?php p($rowLabels['color']); ?>"></span>
										<?php endif; ?>
										<a class="workspace-name-item workspace-name-link" href="<?php p($baseUrl . 'index.php?' . http_build_query(['workspace' => $row['name']])); ?>" data-ws="<?php p($row['name']); ?>" title="<?php p($rowLabels['select']); ?>"><?php p($row['name']); ?></a>
										<?php if ($row['team'] !== null): ?>
										<span class="nn-team-badge" title="<?php p($rowLabels['team']); ?>"><i class="lucide lucide-users" aria-hidden="true"></i><span><?php p($row['team']); ?></span></span>
										<?php endif; ?>
										<?php if ($row['tags'] !== []): ?>
										<div class="ws-tags-row">
											<?php foreach ($row['tags'] as $tag): ?>
											<span class="ws-tag-chip"><i class="lucide lucide-tag"></i><?php p($tag); ?></span>
											<?php endforeach; ?>
										</div>
										<?php endif; ?>
									</div>
								</div>
							</div>
							<div class="ws-col ws-col-actions">
								<div class="ws-icon-actions"></div>
								<button type="button" class="ws-actions-toggle" aria-haspopup="true" aria-expanded="false" title="<?php p($rowLabels['menu']); ?>" aria-label="<?php p($rowLabels['menu']); ?>">
									<i class="lucide lucide-more-horizontal"></i>
								</button>
								<div class="ws-actions-menu">
									<?php if ($row['share'] !== null): ?>
									<button type="button" class="ws-icon-btn btn-share-toggle<?php if ($shared): ?> is-shared<?php endif; ?>" data-ws="<?php p($row['name']); ?>"
										data-shared="<?php p($shared ? '1' : '0'); ?>" data-allowed-users="<?php p($jsonAttr($row['share']['allowed_users'])); ?>" data-shared-with="<?php p($jsonAttr($row['share']['shared_with'])); ?>"
										title="<?php p($shared ? $rowLabels['shareEdit'] : $rowLabels['share']); ?>" aria-label="<?php p($shared ? $rowLabels['shareEdit'] : $rowLabels['share']); ?>">
										<i class="lucide lucide-share-2"></i><span class="ws-icon-btn-text"><?php p($shared ? $rowLabels['shareEdit'] : $rowLabels['share']); ?></span>
									</button>
									<?php endif; ?>
									<?php if ($row['manage']): ?>
									<button type="button" class="ws-icon-btn workspace-rename-action" data-ws="<?php p($row['name']); ?>" title="<?php p($rowLabels['rename']); ?>" aria-label="<?php p($rowLabels['rename']); ?>">
										<i class="lucide lucide-pencil"></i><span class="ws-icon-btn-text"><?php p($rowLabels['rename']); ?></span>
									</button>
									<button type="button" class="ws-icon-btn workspace-tags-action" data-ws="<?php p($row['name']); ?>" data-tags="<?php p(implode(', ', $row['tags'])); ?>" title="<?php p($rowLabels['tags']); ?>" aria-label="<?php p($rowLabels['tags']); ?>">
										<i class="lucide lucide-tag"></i><span class="ws-icon-btn-text"><?php p($rowLabels['tags']); ?></span>
									</button>
									<button type="button" class="ws-icon-btn workspace-color-action" data-ws="<?php p($row['name']); ?>" data-color="<?php p($row['color']); ?>" title="<?php p($rowLabels['color']); ?>" aria-label="<?php p($rowLabels['color']); ?>">
										<i class="lucide lucide-palette"></i><span class="ws-icon-btn-text"><?php p($rowLabels['color']); ?></span>
									</button>
									<?php endif; ?>
									<button type="button" class="ws-icon-btn workspace-background-action" data-ws="<?php p($row['name']); ?>" title="<?php p($rowLabels['background']); ?>" aria-label="<?php p($rowLabels['background']); ?>">
										<i class="lucide lucide-image"></i><span class="ws-icon-btn-text"><?php p($rowLabels['background']); ?></span>
									</button>
									<?php if ($row['manage']): ?>
									<button type="button" class="ws-icon-btn btn-move" data-ws="<?php p($row['name']); ?>" title="<?php p($rowLabels['move']); ?>" aria-label="<?php p($rowLabels['move']); ?>"<?php if ($row['notes'] === 0 || !$several): ?> disabled<?php endif; ?>>
										<i class="lucide lucide-folder-output"></i><span class="ws-icon-btn-text"><?php p($rowLabels['move']); ?></span>
									</button>
									<?php endif; ?>
									<button type="button" class="ws-icon-btn workspace-info-action" data-ws="<?php p($row['name']); ?>"
										data-notes-count="<?php p((string)$row['notes']); ?>" data-folders-count="<?php p((string)$row['folders']); ?>"
										data-tags="<?php p(json_encode($row['tags'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)); ?>" data-shared="<?php p($shared ? '1' : '0'); ?>" data-shared-with="<?php p($jsonAttr($row['share']['shared_with'] ?? [])); ?>"
										title="<?php p($rowLabels['info']); ?>" aria-label="<?php p($rowLabels['info']); ?>">
										<i class="lucide lucide-info"></i><span class="ws-icon-btn-text"><?php p($rowLabels['info']); ?></span>
									</button>
									<?php if ($row['team'] !== null ? $row['manage'] : $severalOwn): ?>
									<button type="button" class="ws-icon-btn ws-icon-btn-danger btn-delete" data-ws="<?php p($row['name']); ?>"<?php if ($row['team'] !== null): ?> data-team-targets="<?php p($jsonAttr($row['teamTargets'])); ?>" data-team-assignments="<?php p((string)$row['teamAssignments']); ?>"<?php endif; ?> title="<?php p($rowLabels['delete']); ?>" aria-label="<?php p($rowLabels['delete']); ?>">
										<i class="lucide lucide-trash-2"></i><span class="ws-icon-btn-text"><?php p($rowLabels['delete']); ?></span>
									</button>
									<?php endif; ?>
								</div>
							</div>
						</li>
						<?php endforeach; ?>
					</ul>
					<p class="ws-filter-empty" id="workspace-filter-empty" hidden><?php p($t('workspaces.filter.no_results', 'No workspace matches this filter.')); ?></p>
					<?php endif; ?>
					<div id="ajaxAlert" class="initially-hidden alert-with-margin"></div>
				</div>
			</div>

			<?php if (($_['oneNoteUrl'] ?? null) !== null): ?>
			<div class="settings-group">
				<a href="<?php p($_['oneNoteUrl']); ?>" class="home-card" id="onenote-import-card">
					<div class="home-card-icon"><i class="lucide lucide-arrow-up-down"></i></div>
					<div class="home-card-content">
						<span class="home-card-title"><?php p($l->t('Import from OneNote')); ?></span>
					</div>
				</a>
			</div>
			<?php endif; ?>

			<div class="settings-group-title"><?php p($t('settings.groups.preferences', 'Preferences')); ?></div>
			<div class="settings-group">
				<!-- „Zuletzt auf diesem Gerät“ entfällt: der Port merkt sich den zuletzt geöffneten Workspace nur serverseitig -->
				<div class="home-card settings-inline-card settings-card-stacks" id="default-workspace-card">
					<span class="setting-help" data-tooltip="<?php p($t('workspaces.default.description_1', 'Choose which workspace opens when you start Poznote.')); ?>"><i class="lucide lucide-help-circle"></i></span>
					<div class="home-card-icon"><i class="lucide lucide-layers"></i></div>
					<div class="home-card-content">
						<label class="home-card-title" for="default-workspace-select"><?php p($t('workspaces.default.title', 'Default Workspace')); ?></label>
						<select id="default-workspace-select" class="settings-inline-control settings-inline-select" data-control="default-workspace-select">
							<option value="__last_opened__"><?php p($t('workspaces.default.last_opened', 'Last workspace opened (all devices)')); ?></option>
							<?php foreach ($page['defaultOptions'] as $name): ?>
							<option value="<?php p($name); ?>"><?php p($name); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>
			</div>

			<div class="settings-group-title"><?php p($t('settings.groups.storage', 'Storage')); ?></div>
			<div class="settings-group">
				<div class="settings-storage-summary" id="storage-stats-user-card"
					data-src="<?php p($baseUrl . 'storage-stats-user.php?fragment=1&' . $wsQuery); ?>"
					data-error="<?php p($t('settings.storage_summary.error', 'Storage usage could not be loaded.')); ?>">
					<div class="settings-storage-summary-loading"><?php p($t('common.loading')); ?></div>
				</div>
			</div>
		</div>

		<!-- Export: ZIP direkt (api_export_*); „alle“ heißt eigene Workspaces -->
		<h2 class="settings-category-title" id="settings-export-import-section-title"><?php p($t('settings.groups.export', 'Export')); ?></h2>
		<div class="home-grid" id="settings-export-import-section-grid">
			<a href="<?php p($baseUrl . 'api_export_structured.php?' . $wsQuery); ?>" class="home-card" id="structured-export-card" title="<?php p($t('backup_export.sections.structured_export.title', 'Structured Export')); ?>">
				<span class="setting-help" data-tooltip="<?php p($t('settings.card_help.structured_export', 'Export your notes as files, organized in folders like in Poznote.')); ?>"><i class="lucide lucide-help-circle"></i></span>
				<div class="home-card-icon"><i class="lucide lucide-folder-tree"></i></div>
				<div class="home-card-content">
					<span class="home-card-title"><?php p($t('backup_export.sections.structured_export.title', 'Structured Export')); ?></span>
					<span class="setting-status enabled"><?php p($wsKey); ?></span>
				</div>
			</a>
			<a href="<?php p($baseUrl . 'api_export_structured.php'); ?>" class="home-card" id="structured-export-all-card" title="<?php p($t('backup_export.sections.structured_export.title', 'Structured Export')); ?>">
				<span class="setting-help" data-tooltip="<?php p($l->t('Export the notes of all your own workspaces as files, organized in their folders.')); ?>"><i class="lucide lucide-help-circle"></i></span>
				<div class="home-card-icon"><i class="lucide lucide-layers"></i></div>
				<div class="home-card-content">
					<span class="home-card-title"><?php p($t('backup_export.sections.structured_export.title', 'Structured Export')); ?></span>
					<span class="setting-status enabled"><?php p($l->t('All my workspaces')); ?></span>
				</div>
			</a>
			<a href="<?php p($baseUrl . 'api_export_attachments.php'); ?>" class="home-card" id="attachments-export-card" title="<?php p($t('backup_export.sections.attachments_export.title', 'Attachments Export')); ?>">
				<span class="setting-help" data-tooltip="<?php p($l->t('Download the attachments of all your own workspaces in a single ZIP archive.')); ?>"><i class="lucide lucide-help-circle"></i></span>
				<div class="home-card-icon"><i class="lucide lucide-paperclip"></i></div>
				<div class="home-card-content">
					<span class="home-card-title"><?php p($t('backup_export.sections.attachments_export.title', 'Attachments Export')); ?></span>
				</div>
			</a>
		</div>

		<h2 class="settings-category-title" id="display"><?php p($t('settings.categories.display', 'Display')); ?></h2>
		<div class="home-grid" id="settings-display-section-grid">
			<div class="home-card" id="font-size-card">
				<span class="setting-help" data-tooltip="<?php p($t('settings.card_help.font_size', 'Adjust the font size of notes, sidebar, code blocks and the settings page.')); ?>"><i class="lucide lucide-help-circle"></i></span>
				<div class="home-card-icon">
					<i class="lucide lucide-type-height"></i>
				</div>
				<div class="home-card-content">
					<span class="home-card-title"><?php p($t('display.cards.note_font_size', 'Font size')); ?></span>
					<div>
						<span id="font-size-badge" class="setting-status" data-tooltip="<?php p($t('settings.value_help.font_size_notes', 'Note content')); ?>"><?php p($t('common.loading')); ?></span>
						<span id="sidebar-font-size-badge" class="setting-status" data-tooltip="<?php p($t('settings.value_help.font_size_sidebar', 'Sidebar')); ?>"><?php p($t('common.loading')); ?></span>
						<span id="code-block-font-size-badge" class="setting-status" data-tooltip="<?php p($t('settings.value_help.font_size_code_blocks', 'Code blocks')); ?>"><?php p($t('common.loading')); ?></span>
						<span id="settings-font-size-badge" class="setting-status" data-tooltip="<?php p($t('settings.value_help.font_size_settings', 'Settings page')); ?>"><?php p($t('common.loading')); ?></span>
					</div>
				</div>
			</div>
			<div class="home-card settings-inline-card" id="index-icon-scale-card">
				<span class="setting-help" data-tooltip="<?php p($t('settings.card_help.index_icon_scale', 'Adjust the size of the icons in the note list.')); ?>"><i class="lucide lucide-help-circle"></i></span>
				<div class="home-card-icon">
					<i class="lucide lucide-maximize-2"></i>
				</div>
				<div class="home-card-content">
					<label class="home-card-title" for="index-icon-scale-range"><?php p($t('display.cards.index_icon_scale', 'Index icon scaling')); ?></label>
					<span class="settings-inline-range">
						<input type="range" id="index-icon-scale-range" class="settings-inline-control" data-control="index-icon-scale-range" min="0.5" max="2.0" step="0.1" value="1.0">
						<output class="settings-inline-value" for="index-icon-scale-range">1.0x</output>
					</span>
				</div>
			</div>
			<div class="home-card" id="date-time-format-card">
				<span class="setting-help" data-tooltip="<?php p($t('settings.card_help.date_time_format', 'Choose how dates and times are displayed.')); ?>"><i class="lucide lucide-help-circle"></i></span>
				<div class="home-card-icon"><i class="lucide lucide-calendar"></i></div>
				<div class="home-card-content">
					<span class="home-card-title"><?php p($t('display.cards.date_time_format', 'Date & time format')); ?></span>
					<span id="date-time-format-badge" class="setting-status"><?php p($t('common.loading')); ?></span>
				</div>
			</div>
			<div class="home-card" id="note-color-palette-card">
				<span class="setting-help" data-tooltip="<?php p($t('settings.card_help.note_color_palette', 'Customize the color palette available for notes.')); ?>"><i class="lucide lucide-help-circle"></i></span>
				<div class="home-card-icon"><i class="lucide lucide-palette"></i></div>
				<div class="home-card-content">
					<span class="home-card-title"><?php p($t('display.cards.note_color_palette', 'Note colors')); ?></span>
					<span id="note-color-palette-badge" class="setting-status"><?php p($t('common.loading')); ?></span>
				</div>
			</div>
		</div>

		<h2 class="settings-category-title" id="ui-customization"><?php p($t('settings.categories.ui_customization', 'Element visibility')); ?></h2>
		<div class="home-grid settings-section-inline" id="settings-ui-customization-section-grid">
			<div id="uiCustomizationSettings" class="ui-custom-settings">
				<p class="ui-custom-description" id="uiCustomizationSettingsDescription"
					data-description-user="<?php p($t('modals.ui_customization.description', 'Show or hide interface elements. Unchecked items will be hidden.')); ?>"><?php p($t('modals.ui_customization.description', 'Show or hide interface elements. Unchecked items will be hidden.')); ?></p>
				<p class="ui-custom-summary"><span id="ui-customization-badge" class="setting-status"><?php p($t('common.loading')); ?></span></p>
				<div class="ui-custom-filter">
					<button type="button" id="uiCustomizationToggleAll" class="ui-custom-toggle-all ui-custom-toggle-all-global" data-label-check="<?php p($t('modals.ui_customization.check_all', 'Check all')); ?>" data-label-uncheck="<?php p($t('modals.ui_customization.uncheck_all', 'Uncheck all')); ?>"></button>
					<button type="button" id="uiCustomizationCollapseAll" class="ui-custom-collapse-all"
						data-label-collapse="<?php p($t('modals.ui_customization.collapse_all', 'Collapse all')); ?>"
						data-label-expand="<?php p($t('modals.ui_customization.expand_all', 'Expand all')); ?>"><i class="lucide lucide-chevron-down"></i></button>
					<input type="search" id="uiCustomizationFilterInput" class="ui-custom-filter-input" placeholder="<?php p($t('modals.ui_customization.filter_placeholder', 'Filter items...')); ?>" autocomplete="off">
				</div>
				<label class="ui-custom-hidden-only">
					<input type="checkbox" id="uiCustomizationHiddenOnly">
					<span><?php p($t('modals.ui_customization.show_unchecked_only', 'Show only unchecked items')); ?></span>
				</label>
				<div class="ui-custom-sections">
					<div class="ui-custom-empty" id="uiCustomizationFilterEmpty" hidden
						data-empty-default="<?php p($t('modals.ui_customization.no_results', 'No matching items found.')); ?>"
						data-empty-unchecked="<?php p($t('modals.ui_customization.no_unchecked_results', 'No unchecked items.')); ?>"><?php p($t('modals.ui_customization.no_results', 'No matching items found.')); ?></div>
					<?php include __DIR__ . '/partials/ui_customization_sections.php'; ?>
				</div>
				<div class="ui-custom-settings-footer">
					<button type="button" class="btn btn-primary ui-custom-save-btn" id="saveUiCustomizationBtn"><?php p($t('common.save')); ?></button>
				</div>
			</div>
		</div>

		<h2 class="settings-category-title" id="sidebar"><?php p($t('settings.categories.sidebar', 'Sidebar')); ?></h2>
		<div class="home-grid" id="settings-sidebar-section-grid">
			<div class="home-card" id="folder-tree-highlight-card">
				<span class="setting-help" data-tooltip="<?php p($t('settings.card_help.highlight_current_folder_tree', 'Dim the notes and folders that sit outside the folder hierarchy you are working in, as much or as little as you like.')); ?>"><i class="lucide lucide-help-circle"></i></span>
				<div class="home-card-icon"><i class="lucide lucide-folder-tree"></i></div>
				<div class="home-card-content">
					<span class="home-card-title"><?php p($t('display.cards.highlight_current_folder_tree', 'Highlight current folder tree')); ?></span>
					<span id="folder-tree-highlight-status" class="setting-status"><?php p($t('common.loading')); ?></span>
				</div>
			</div>
			<div class="home-card" id="note-age-filter-card">
				<span class="setting-help" data-tooltip="<?php p($t('settings.card_help.note_age_filter', 'Only show notes updated within the chosen number of days.')); ?>"><i class="lucide lucide-help-circle"></i></span>
				<div class="home-card-icon"><i class="lucide lucide-filter"></i></div>
				<div class="home-card-content">
					<span class="home-card-title"><?php p($t('display.cards.note_age_filter', 'Note age filter')); ?></span>
					<span id="note-age-filter-badge" class="setting-status"><?php p($t('common.loading')); ?></span>
				</div>
			</div>
			<div class="home-card" id="notes-without-folders-card">
				<span class="setting-help" data-tooltip="<?php p($t('settings.card_help.notes_without_folders', 'Show notes without a folder after the folder list instead of before.')); ?>"><i class="lucide lucide-help-circle"></i></span>
				<div class="home-card-icon"><i class="lucide lucide-folder-tree"></i></div>
				<div class="home-card-content">
					<span class="home-card-title"><?php p($t('display.cards.notes_without_folders_after', 'Show notes after folders')); ?></span>
					<span id="notes-without-folders-status" class="setting-status disabled"><?php p($t('common.disabled')); ?></span>
				</div>
			</div>
			<div class="home-card" id="folder-icon-kanban-card">
				<span class="setting-help" data-tooltip="<?php p($t('settings.card_help.folder_icon_opens_kanban', 'A click on a folder icon in the notes list opens the folder\'s Kanban view instead of the icon picker. The icon can still be changed from the folder menu or from the Kanban view.')); ?>"><i class="lucide lucide-help-circle"></i></span>
				<div class="home-card-icon"><i class="lucide lucide-columns-2"></i></div>
				<div class="home-card-content">
					<span class="home-card-title"><?php p($t('display.cards.folder_icon_opens_kanban', 'Folder icon opens Kanban')); ?></span>
					<span id="folder-icon-kanban-status" class="setting-status disabled"><?php p($t('common.disabled')); ?></span>
				</div>
			</div>
			<div class="home-card" id="folder-count-parentheses-card">
				<span class="setting-help" data-tooltip="<?php p($t('settings.card_help.folder_count_parentheses', 'Show the number of notes of a folder in parentheses, like (12), instead of a small pill.')); ?>"><i class="lucide lucide-help-circle"></i></span>
				<div class="home-card-icon"><i class="lucide lucide-hash"></i></div>
				<div class="home-card-content">
					<span class="home-card-title"><?php p($t('display.cards.folder_count_parentheses', 'Folder counts in parentheses')); ?></span>
					<span id="folder-count-parentheses-status" class="setting-status disabled"><?php p($t('common.disabled')); ?></span>
				</div>
			</div>
		</div>

		<h2 class="settings-category-title" id="note-content"><?php p($t('settings.categories.note_content', 'Note content')); ?></h2>
		<div class="home-grid settings-grouped" id="settings-note-content-section-grid">
			<div class="settings-group-title"><?php p($t('settings.groups.layout', 'Layout')); ?></div>
			<div class="settings-group">
				<div class="home-card settings-inline-card desktop-only" id="note-width-card">
					<span class="setting-help" data-tooltip="<?php p($t('settings.card_help.note_width', 'Adjust the maximum width of the note content.')); ?>"><i class="lucide lucide-help-circle"></i></span>
					<div class="home-card-icon"><i class="lucide lucide-move-horizontal"></i></div>
					<div class="home-card-content">
						<label class="home-card-title" for="note-width-range"><?php p($t('display.cards.note_content_width', 'Note Content Width')); ?></label>
						<span class="settings-inline-range">
							<input type="range" id="note-width-range" class="settings-inline-control" data-control="note-width-range" min="10" max="100" step="5" value="100">
							<output class="settings-inline-value" for="note-width-range"><?php p($t('modals.note_width.full_width', 'Full Width')); ?></output>
						</span>
					</div>
				</div>
				<div class="home-card desktop-only" id="note-virtual-space-card">
					<span class="setting-help" data-tooltip="<?php p($t('settings.card_help.note_virtual_space', 'Leave empty room under the last line of a note, so the end of the text can be scrolled up to the middle of the screen instead of staying at the very bottom.')); ?>"><i class="lucide lucide-help-circle"></i></span>
					<div class="home-card-icon"><i class="lucide lucide-move-vertical"></i></div>
					<div class="home-card-content">
						<span class="home-card-title"><?php p($t('display.cards.note_virtual_space', 'Space below the note')); ?></span>
						<span id="note-virtual-space-status" class="setting-status disabled"><?php p($t('common.disabled')); ?></span>
					</div>
				</div>
				<div class="home-card" id="backlinks-at-bottom-card">
					<span class="setting-help" data-tooltip="<?php p($t('settings.card_help.backlinks_at_bottom', 'Show the backlinks section at the bottom of notes instead of the top.')); ?>"><i class="lucide lucide-help-circle"></i></span>
					<div class="home-card-icon"><i class="lucide lucide-link"></i></div>
					<div class="home-card-content">
						<span class="home-card-title"><?php p($t('display.cards.backlinks_at_bottom', 'Backlinks at bottom')); ?></span>
						<span id="backlinks-at-bottom-status" class="setting-status disabled"><?php p($t('common.disabled')); ?></span>
					</div>
				</div>
				<div class="home-card" id="default-image-border-card">
					<span class="setting-help" data-tooltip="<?php p($t('settings.card_help.default_image_border', 'Display images in notes with a border and no padding by default.')); ?>"><i class="lucide lucide-help-circle"></i></span>
					<div class="home-card-icon"><i class="lucide lucide-image"></i></div>
					<div class="home-card-content">
						<span class="home-card-title"><?php p($t('display.cards.default_image_border_no_padding', 'Default image border (no padding)')); ?></span>
						<span id="default-image-border-status" class="setting-status disabled"><?php p($t('common.disabled')); ?></span>
					</div>
				</div>
			</div>
			<div class="settings-group-title"><?php p($t('settings.groups.editing', 'Editing')); ?></div>
			<div class="settings-group">
				<div class="home-card" id="spellcheck-html-notes-card">
					<span class="setting-help" data-tooltip="<?php p($t('settings.card_help.spellcheck_html_notes', 'Enable the browser spell checker in rich text and Markdown notes.')); ?>"><i class="lucide lucide-help-circle"></i></span>
					<div class="home-card-icon"><i class="lucide lucide-list-check"></i></div>
					<div class="home-card-content">
						<span class="home-card-title"><?php p($t('display.cards.spellcheck_html_notes', 'Spell check')); ?></span>
						<span id="spellcheck-html-notes-status" class="setting-status disabled"><?php p($t('common.disabled')); ?></span>
					</div>
				</div>
				<div class="home-card settings-inline-card settings-card-stacks" id="slash-menu-trigger-card">
					<span class="setting-help" data-tooltip="<?php p($t('settings.card_help.slash_menu_trigger', 'On a computer, choose whether the command menu opens by typing /, with Alt + /, or not from the keyboard at all. A right-click in a note opens it whatever this is set to.')); ?>"><i class="lucide lucide-help-circle"></i></span>
					<div class="home-card-icon"><i class="lucide lucide-monitor"></i></div>
					<div class="home-card-content">
						<label class="home-card-title" for="slash-menu-trigger-select"><?php p($t('display.cards.slash_menu_trigger', 'Command menu shortcut (computer)')); ?></label>
						<select id="slash-menu-trigger-select" class="settings-inline-control settings-inline-select" data-control="slash-menu-trigger-select">
							<option value="slash"><?php p($t('display.badges.slash_menu_slash', '/')); ?></option>
							<option value="alt-slash"><?php p($t('display.badges.slash_menu_alt_slash', 'Alt + /')); ?></option>
							<option value="disabled"><?php p($t('common.disabled', 'Disabled')); ?></option>
						</select>
					</div>
				</div>
				<div class="home-card settings-inline-card settings-card-stacks settings-card-with-note" id="slash-menu-trigger-mobile-card">
					<span class="setting-help" data-tooltip="<?php p($t('settings.card_help.slash_menu_trigger_mobile', 'On mobile, choose whether the command menu opens by typing /, with Alt + / from a hardware keyboard, or not from the keyboard at all.')); ?>"><i class="lucide lucide-help-circle"></i></span>
					<div class="home-card-icon"><i class="lucide lucide-smartphone"></i></div>
					<div class="home-card-content">
						<label class="home-card-title" for="slash-menu-trigger-mobile-select"><?php p($t('display.cards.slash_menu_trigger_mobile', 'Command menu shortcut (mobile)')); ?></label>
						<select id="slash-menu-trigger-mobile-select" class="settings-inline-control settings-inline-select" data-control="slash-menu-trigger-mobile-select">
							<option value="slash"><?php p($t('display.badges.slash_menu_slash', '/')); ?></option>
							<option value="alt-slash"><?php p($t('display.badges.slash_menu_alt_slash', 'Alt + /')); ?></option>
							<option value="disabled"><?php p($t('common.disabled', 'Disabled')); ?></option>
						</select>
						<span class="settings-card-note"><?php p($t('settings.card_notes.slash_menu_trigger_mobile', 'On mobile, the toolbar above the keyboard has a + button that opens the same menu, whatever this is set to.')); ?></span>
					</div>
				</div>
				<div class="home-card settings-inline-card settings-card-stacks" id="format-toolbar-mode-card">
					<span class="setting-help" data-tooltip="<?php p($t('settings.card_help.format_toolbar_mode', 'On a computer, choose where the formatting buttons show when you select text in a note: in the note toolbar, in place of the note actions, or in a menu floating above the selection, which leaves the note toolbar as it is.')); ?>"><i class="lucide lucide-help-circle"></i></span>
					<div class="home-card-icon"><i class="lucide lucide-bold"></i></div>
					<div class="home-card-content">
						<label class="home-card-title" for="format-toolbar-mode-select"><?php p($t('display.cards.format_toolbar_mode', 'Formatting toolbar')); ?></label>
						<select id="format-toolbar-mode-select" class="settings-inline-control settings-inline-select" data-control="format-toolbar-mode-select">
							<option value="toolbar"><?php p($t('display.badges.format_toolbar_mode_toolbar', 'In the note toolbar')); ?></option>
							<option value="floating"><?php p($t('display.badges.format_toolbar_mode_floating', 'Floating above the selection')); ?></option>
						</select>
					</div>
				</div>
				<div class="home-card settings-inline-card" id="tasklist-insert-order-card">
					<span class="setting-help" data-tooltip="<?php p($t('settings.card_help.tasklist_insert_order', 'Choose whether new tasks are added to the top or the bottom of task lists.')); ?>"><i class="lucide lucide-help-circle"></i></span>
					<div class="home-card-icon"><i class="lucide lucide-arrow-down"></i></div>
					<div class="home-card-content">
						<label class="home-card-title" for="tasklist-insert-order-select"><?php p($t('display.cards.tasklist_insert_order', 'Task list insert order')); ?></label>
						<select id="tasklist-insert-order-select" class="settings-inline-control settings-inline-select" data-control="tasklist-insert-order-select">
							<option value="top"><?php p($t('tasklist.insert_order_top', 'Top')); ?></option>
							<option value="bottom" selected><?php p($t('tasklist.insert_order_bottom', 'Bottom')); ?></option>
						</select>
					</div>
				</div>
				<div class="home-card" id="snapshots-card">
					<span class="setting-help" data-tooltip="<?php p($l->t('Choose for how many days one automatic revision per day is kept.')); ?>"><i class="lucide lucide-help-circle"></i></span>
					<div class="home-card-icon"><i class="lucide lucide-history"></i></div>
					<div class="home-card-content">
						<span class="home-card-title"><?php p($t('display.cards.snapshots', 'Revisions')); ?></span>
						<span id="snapshots-badge" class="setting-status"><?php p($t('common.loading')); ?></span>
					</div>
				</div>
			</div>
			<div class="settings-group-title"><?php p($t('settings.groups.attachments', 'Attachments')); ?></div>
			<div class="settings-group">
				<div class="home-card" id="attachments-at-bottom-card">
					<span class="setting-help" data-tooltip="<?php p($t('settings.card_help.attachments_at_bottom', 'Show the attachments section at the bottom of notes instead of the top.')); ?>"><i class="lucide lucide-help-circle"></i></span>
					<div class="home-card-icon"><i class="lucide lucide-paperclip"></i></div>
					<div class="home-card-content">
						<span class="home-card-title"><?php p($t('display.cards.attachments_at_bottom', 'Attachments at bottom')); ?></span>
						<span id="attachments-at-bottom-status" class="setting-status disabled"><?php p($t('common.disabled')); ?></span>
					</div>
				</div>
				<div class="home-card" id="attachment-previews-card">
					<span class="setting-help" data-tooltip="<?php p($t('settings.card_help.attachment_previews', 'Show previews of attachments inside notes.')); ?>"><i class="lucide lucide-help-circle"></i></span>
					<div class="home-card-icon"><i class="lucide lucide-file-image"></i></div>
					<div class="home-card-content">
						<span class="home-card-title"><?php p($t('display.cards.attachment_previews_in_note', 'Attachment previews')); ?></span>
						<span id="attachment-previews-status" class="setting-status disabled"><?php p($t('common.disabled')); ?></span>
					</div>
				</div>
			</div>
			<div class="settings-group-title"><?php p($t('settings.groups.code', 'Code')); ?></div>
			<div class="settings-group">
				<div class="home-card" id="code-wrap-card">
					<span class="setting-help" data-tooltip="<?php p($t('settings.card_help.code_wrap', 'Wrap long lines in code blocks instead of scrolling horizontally.')); ?>"><i class="lucide lucide-help-circle"></i></span>
					<div class="home-card-icon"><i class="lucide lucide-code"></i></div>
					<div class="home-card-content">
						<span class="home-card-title"><?php p($t('display.cards.code_block_word_wrap', 'Code block word wrap')); ?></span>
						<span id="code-wrap-status" class="setting-status enabled"><?php p($t('common.enabled')); ?></span>
					</div>
				</div>
				<div class="home-card" id="code-line-numbers-card">
					<span class="setting-help" data-tooltip="<?php p($t('settings.card_help.code_line_numbers', 'Show line numbers in code blocks in the markdown preview.')); ?>"><i class="lucide lucide-help-circle"></i></span>
					<div class="home-card-icon"><i class="lucide lucide-list-ordered"></i></div>
					<div class="home-card-content">
						<span class="home-card-title"><?php p($t('display.cards.code_block_line_numbers', 'Code block line numbers')); ?></span>
						<span id="code-line-numbers-status" class="setting-status disabled"><?php p($t('common.disabled')); ?></span>
					</div>
				</div>
			</div>
		</div>

		<h2 class="settings-category-title" id="markdown"><?php p($t('settings.categories.markdown', 'Markdown')); ?></h2>
		<div class="home-grid" id="settings-markdown-section-grid">
			<div class="home-card settings-inline-card settings-card-stacks" id="markdown-default-view-mode-card">
				<span class="setting-help" data-tooltip="<?php p($t('settings.card_help.markdown_default_view_mode', 'Choose whether markdown notes open in preview, edit or split mode.')); ?> <?php p($t('modals.markdown_default_view_mode.hint', 'A note you switch to another mode opens again in the default mode next time.')); ?>"><i class="lucide lucide-help-circle"></i></span>
				<div class="home-card-icon"><i class="lucide lucide-book-open"></i></div>
				<div class="home-card-content">
					<label class="home-card-title" for="markdown-default-view-mode-select"><?php p($t('display.cards.markdown_default_view_mode', 'Default view mode')); ?></label>
					<select id="markdown-default-view-mode-select" class="settings-inline-control settings-inline-select" data-control="markdown-default-view-mode-select">
						<option value="preview"><?php p($t('modals.markdown_default_view_mode.options.preview', 'Preview')); ?></option>
						<option value="edit"><?php p($t('modals.markdown_default_view_mode.options.edit', 'Edit')); ?></option>
						<option value="split"><?php p($t('modals.markdown_default_view_mode.options.split_short', 'Split')); ?></option>
						<option value="last"><?php p($t('modals.markdown_default_view_mode.options.last', 'Last used mode')); ?></option>
					</select>
				</div>
			</div>
			<div class="home-card settings-inline-card settings-card-stacks" id="markdown-new-note-view-mode-card">
				<span class="setting-help" data-tooltip="<?php p($t('settings.card_help.markdown_new_note_view_mode', 'Choose whether a new markdown note opens in split, edit or live rendering mode. Live rendering is then turned on for the other notes too. On a phone a new note never opens in split mode.')); ?>"><i class="lucide lucide-help-circle"></i></span>
				<div class="home-card-icon"><i class="lucide lucide-file-plus"></i></div>
				<div class="home-card-content">
					<label class="home-card-title" for="markdown-new-note-view-mode-select"><?php p($t('display.cards.markdown_new_note_view_mode', 'New note view mode')); ?></label>
					<select id="markdown-new-note-view-mode-select" class="settings-inline-control settings-inline-select" data-control="markdown-new-note-view-mode-select">
						<option value="split"><?php p($t('modals.markdown_default_view_mode.options.split_short', 'Split')); ?></option>
						<option value="edit"><?php p($t('modals.markdown_default_view_mode.options.edit', 'Edit')); ?></option>
						<option value="live"><?php p($t('modals.markdown_default_view_mode.options.live', 'Live preview')); ?></option>
						<option value="rich"><?php p($t('modals.markdown_default_view_mode.options.rich', 'Rich text')); ?></option>
					</select>
				</div>
			</div>
			<div class="home-card settings-inline-card settings-card-stacks" id="markdown-view-cycle-card">
				<span class="setting-help" data-tooltip="<?php p($t('settings.card_help.markdown_view_cycle', 'Choose the views the view menu of a markdown note offers besides the classic view (preview, with the pencil to edit). The views left out are not listed. With none chosen, the button is hidden. On a phone the menu never offers split mode.')); ?>"><i class="lucide lucide-help-circle"></i></span>
				<div class="home-card-icon"><i class="lucide lucide-list"></i></div>
				<div class="home-card-content">
					<span class="home-card-title" id="markdown-view-cycle-title"><?php p($t('display.cards.markdown_view_cycle', 'View menu')); ?></span>
					<div class="settings-inline-control settings-inline-checks" id="markdown-view-cycle-checks" role="group" aria-labelledby="markdown-view-cycle-title">
						<label><input type="checkbox" value="live" checked><span><?php p($t('modals.markdown_default_view_mode.options.live', 'Live preview')); ?></span></label>
						<label><input type="checkbox" value="rich" checked><span><?php p($t('modals.markdown_default_view_mode.options.rich', 'Rich text')); ?></span></label>
						<label><input type="checkbox" value="split" checked><span><?php p($t('modals.markdown_default_view_mode.options.split_short', 'Split')); ?></span></label>
					</div>
				</div>
			</div>
			<div class="home-card" id="markdown-split-card-view-card">
				<span class="setting-help" data-tooltip="<?php p($t('settings.card_help.markdown_split_card_view', 'Display markdown notes in a framed split view with editor and preview.')); ?>"><i class="lucide lucide-help-circle"></i></span>
				<div class="home-card-icon"><i class="lucide lucide-columns-2"></i></div>
				<div class="home-card-content">
					<span class="home-card-title"><?php p($t('display.cards.markdown_split_card_view', 'Framed markdown')); ?></span>
					<span id="markdown-split-card-view-status" class="setting-status disabled"><?php p($t('common.disabled')); ?></span>
				</div>
			</div>
			<div class="home-card" id="markdown-split-preview-left-card">
				<span class="setting-help" data-tooltip="<?php p($t('settings.card_help.markdown_split_preview_left', 'In split mode, show the preview on the left and the editor on the right.')); ?>"><i class="lucide lucide-help-circle"></i></span>
				<div class="home-card-icon"><i class="lucide lucide-flip-horizontal"></i></div>
				<div class="home-card-content">
					<span class="home-card-title"><?php p($t('display.cards.markdown_split_preview_left', 'Preview on the left')); ?></span>
					<span id="markdown-split-preview-left-status" class="setting-status disabled"><?php p($t('common.disabled')); ?></span>
				</div>
			</div>
			<div class="home-card" id="markdown-heading-underline-card">
				<span class="setting-help" data-tooltip="<?php p($t('settings.card_help.markdown_heading_underline', 'Draw a line under the level 1 and level 2 headings of markdown notes.')); ?>"><i class="lucide lucide-help-circle"></i></span>
				<div class="home-card-icon"><i class="lucide lucide-underline"></i></div>
				<div class="home-card-content">
					<span class="home-card-title"><?php p($t('display.cards.markdown_heading_underline', 'Heading underline')); ?></span>
					<span id="markdown-heading-underline-status" class="setting-status enabled"><?php p($t('common.enabled')); ?></span>
				</div>
			</div>
			<div class="home-card" id="markdown-editor-wrap-card">
				<span class="setting-help" data-tooltip="<?php p($t('settings.card_help.markdown_editor_word_wrap', 'Wrap long lines in the markdown editor and the live preview instead of scrolling horizontally. The note itself is not changed. Rich text always wraps.')); ?>"><i class="lucide lucide-help-circle"></i></span>
				<div class="home-card-icon"><i class="lucide lucide-corner-down-left"></i></div>
				<div class="home-card-content">
					<span class="home-card-title"><?php p($t('display.cards.markdown_editor_word_wrap', 'Editor word wrap')); ?></span>
					<span id="markdown-editor-wrap-status" class="setting-status enabled"><?php p($t('common.enabled')); ?></span>
				</div>
			</div>
			<div class="home-card" id="markdown-colored-card">
				<span class="setting-help" data-tooltip="<?php p($t('settings.card_help.markdown_colored', 'Color markdown notes: pick a color for each heading level, inline code, code block background, quotes, table headers and separators.')); ?>"><i class="lucide lucide-help-circle"></i></span>
				<div class="home-card-icon"><i class="lucide lucide-palette"></i></div>
				<div class="home-card-content">
					<span class="home-card-title"><?php p($t('display.cards.markdown_colored', 'Colored markdown')); ?></span>
					<span id="markdown-colored-status" class="setting-status disabled"><?php p($t('common.disabled')); ?></span>
				</div>
			</div>
		</div>

		<h2 class="settings-category-title" id="diary"><?php p($t('settings.categories.diary', 'Diary')); ?></h2>
		<div class="home-grid" id="settings-diary-section-grid">
			<div class="home-card settings-inline-card" id="diary-note-type-card">
				<span class="setting-help" data-tooltip="<?php p($t('settings.card_help.diary_note_type', 'Choose the note format used for new diary entries.')); ?>"><i class="lucide lucide-help-circle"></i></span>
				<div class="home-card-icon"><i class="lucide lucide-book-open"></i></div>
				<div class="home-card-content">
					<label class="home-card-title" for="diary-note-type-select"><?php p($t('display.cards.diary_default_note_type', 'Diary entry format')); ?></label>
					<select id="diary-note-type-select" class="settings-inline-control settings-inline-select" data-control="diary-note-type-select">
						<option value="html"><?php p($t('modals.create.note.title', 'Note')); ?></option>
						<option value="markdown"><?php p($t('modals.create.markdown.title', 'Markdown Note')); ?></option>
					</select>
				</div>
			</div>
			<!-- Vorlage je Workspace (diary_templates nach Workspace-ID), Auswahl aus api/v1/notes/templates -->
			<div class="home-card settings-inline-card" id="diary-template-card">
				<span class="setting-help" data-tooltip="<?php p($t('settings.card_help.diary_template', 'Choose a note whose content every new diary entry of the {{workspace}} workspace starts with. The list shows the notes kept in a folder named Templates in that workspace. Each workspace has its own template.', ['workspace' => $wsKey])); ?>"><i class="lucide lucide-help-circle"></i></span>
				<div class="home-card-icon"><i class="lucide lucide-file-text"></i></div>
				<div class="home-card-content">
					<label class="home-card-title" for="diary-template-select"><?php p($t('display.cards.diary_template', 'Diary entry template')); ?></label>
					<select id="diary-template-select" class="settings-inline-control settings-inline-select" data-control="diary-template-select" data-workspace="<?php p($wsKey); ?>" data-workspace-id="<?php p((string)$frame['wsId']); ?>">
						<option value=""><?php p($t('settings.diary_template.none', 'None')); ?></option>
					</select>
				</div>
			</div>
			<div class="home-card" id="diary-date-format-card">
				<span class="setting-help" data-tooltip="<?php p($t('settings.card_help.diary_date_format', 'Choose the date format used to title new diary entries.')); ?>"><i class="lucide lucide-help-circle"></i></span>
				<div class="home-card-icon"><i class="lucide lucide-calendar-alt"></i></div>
				<div class="home-card-content">
					<span class="home-card-title"><?php p($t('display.cards.diary_date_format', 'Diary entry date format')); ?></span>
					<span id="diary-date-format-badge" class="setting-status"><?php p($t('common.loading')); ?></span>
				</div>
			</div>
		</div>

		<!-- Über: Version der App, Herkunft Poznote -->
		<h2 class="settings-category-title" id="settings-documentation-section-title"><?php p($t('settings.categories.documentation', 'About')); ?></h2>
		<div class="home-grid" id="settings-documentation-section-grid">
			<div class="home-card" id="check-updates-card">
				<span class="setting-help" data-tooltip="<?php p($l->t('Next Notes is based on Poznote by Timothé Poznanski and is updated through the Nextcloud app store.')); ?>"><i class="lucide lucide-help-circle"></i></span>
				<div class="home-card-icon"><i class="lucide lucide-info"></i></div>
				<div class="home-card-content">
					<span class="home-card-title"><?php p($l->t('Next Notes')); ?></span>
					<span class="setting-status enabled"><?php p($_['appVersion']); ?></span>
				</div>
			</div>
			<a href="https://github.com/valueerrorx/nextnotes" target="_blank" rel="noopener noreferrer" class="home-card" id="nextnotes-website-card">
				<span class="setting-help" data-tooltip="<?php p($l->t('Open the Next Notes project on GitHub.')); ?>"><i class="lucide lucide-help-circle"></i></span>
				<div class="home-card-icon">
					<i class="lucide lucide-globe"></i>
				</div>
				<div class="home-card-content">
					<span class="home-card-title"><?php p($l->t('Next Notes Website')); ?></span>
				</div>
			</a>
		</div>

		</div>
		</div>
	</div>
	</main>

	<?php include __DIR__ . '/partials/shared_modals.php'; ?>
	<?php include __DIR__ . '/partials/settings_modals.php'; ?>
</div>
</div>
