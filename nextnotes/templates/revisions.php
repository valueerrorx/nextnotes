<?php
/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// abgeleitet von src/public/revisions.php @ 7e2e0b6aa501ba95ac1e9789fd2bb477c281a001
// Revisionen einer Notiz (revisions-page.js, Snapshots-API). Daten: PageController::revisions, NotePageViewService.
// Abweichungen: ohne Icon-Leiste (NC-Rahmen), Inline-Skripte → JSON-Inseln, Aufbewahrung als Text statt Link auf
// settings.php (M4), Absatz zu KI/MCP in der Beschreibung weggelassen (gestrichen), Wörterbuch aus der i18n-Datei.
/** @var array $_ */
/** @var \OCA\NextNotes\Service\View\PageTranslator $tr */
$tr = $_['tr'];
$page = $_['page'];
$config = $_['config'];
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR;
$islands = [
	'revisions-config' => $config,
	'nextnotes-body' => ['classes' => ['revisions-page'], 'data' => ['note-type' => $page['note']['type']]],
];
$description = explode("\n\n", $tr->t('revisions.description', [
	'count' => (string)$_['keepCount'],
	'days' => (string)\OCA\NextNotes\Service\SnapshotService::MAX_AGE_DAYS,
	'minutes' => (string)intdiv(\OCA\NextNotes\Service\SnapshotService::AUTO_INTERVAL, 60),
	'hours' => (string)\OCA\NextNotes\Service\SnapshotService::DENSE_HOURS,
]));
// KI/MCP-Absatz entfällt (kein KI/MCP im Port); in allen Sprachen am Wort „MCP“ erkennbar
$description = array_values(array_filter($description, static fn (string $para) => !str_contains($para, 'MCP')));
$h = static fn (string $key, string $default) => $tr->t('revisions.' . $key, [], $default);
?>
<div id="app-content">
<div id="nextnotes-root" class="nextnotes-subpage">
	<?php foreach ($islands as $islandId => $islandData): ?>
	<script type="application/json" id="<?php p($islandId); ?>"><?php print_unescaped(json_encode($islandData, $jsonFlags)); ?></script>
	<?php endforeach; ?>
	<div class="revisions-shell">
		<header class="revisions-header">
			<div class="poznote-back-to-note-bar"><a href="<?php p($page['backUrl']); ?>" class="poznote-back-to-note-btn"><i class="lucide lucide-arrow-left"></i> <?php p($tr->t('info.actions.back_to_note', [], 'Go back to the note')); ?></a></div>
			<div class="revisions-heading">
				<h1 class="revisions-title"><i class="lucide lucide-history"></i> <span><?php p($h('title', 'Revisions')); ?></span></h1>
				<button type="button" class="revisions-info-btn" id="revisionsInfoBtn" aria-haspopup="dialog" title="<?php p($tr->t('common.information', [], 'Information')); ?>" aria-label="<?php p($tr->t('common.information', [], 'Information')); ?>"><i class="lucide lucide-info"></i></button>
			</div>
		</header>
		<template id="revisionsInfoContent"><span class="revisions-description"><?php p(implode("\n\n", $description)); ?></span></template>

		<div class="revisions-layout">
			<aside class="revisions-sidebar" aria-label="<?php p($h('history', 'History')); ?>">
				<div class="revisions-sidebar-title"><?php p($h('history', 'History')); ?></div>
				<div id="revisionsList" class="revisions-list" role="listbox" tabindex="0" aria-label="<?php p($h('history', 'History')); ?>"></div>
			</aside>

			<main class="revisions-main">
				<div id="revisionsLoading" class="revisions-state">
					<i class="lucide lucide-loader-2 revisions-spinner"></i>
					<p><?php p($h('loading', 'Loading...')); ?></p>
				</div>

				<div id="revisionsEmpty" class="revisions-state" hidden>
					<i class="lucide lucide-history"></i>
					<p class="revisions-state-title"><?php p($h('empty_title', 'No revision yet')); ?></p>
					<p class="revisions-state-hint"><?php p($h('empty_hint', 'A revision is saved automatically when you change the note. You can also save one now.')); ?></p>
					<?php if ($page['canWrite']): ?>
					<button type="button" class="revisions-btn revisions-btn-secondary revisions-new-btn revisions-empty-new"><i class="lucide lucide-plus"></i> <span><?php p($h('actions.new', 'Save a revision')); ?></span></button>
					<?php endif; ?>
				</div>

				<div id="revisionsPanel" class="revisions-panel" hidden>
					<div class="revisions-panel-head">
						<div class="revisions-selected">
							<h2 class="revisions-selected-title" id="revisionsSelectedWhen"><?php p($config['noteHeading']); ?></h2>
						</div>
						<div class="revisions-panel-actions">
							<div class="revisions-tabs" role="tablist">
								<button type="button" class="revisions-tab" role="tab" data-view="content" id="revisionsTabContent"><?php p($h('tabs.content', 'Content')); ?></button>
								<button type="button" class="revisions-tab" role="tab" data-view="changes" id="revisionsTabChanges"><?php p($h('tabs.changes', 'Changes')); ?></button>
							</div>
							<div class="revisions-segmented" id="revisionsMdMode" role="group" hidden>
								<button type="button" data-md-mode="preview"><?php p($h('markdown_preview', 'Preview')); ?></button>
								<button type="button" data-md-mode="source"><?php p($h('markdown_source', 'Source')); ?></button>
							</div>
							<div class="revisions-menu-wrap">
								<button type="button" class="revisions-btn revisions-btn-secondary" id="revisionsActionsBtn" aria-haspopup="menu" aria-expanded="false" aria-controls="revisionsActionsMenu"><span><?php p($h('actions.menu', 'Actions')); ?></span> <i class="lucide lucide-chevron-down"></i></button>
								<div class="revisions-menu" id="revisionsActionsMenu" role="menu" hidden>
									<?php // Ohne Schreibrecht nur Kopieren; Knöpfe bleiben im DOM (revisions-page.js bindet sie), aber verborgen ?>
									<button type="button" class="revisions-menu-item" role="menuitem" id="revisionsRestoreBtn"<?php if (!$page['canWrite']): ?> hidden<?php endif; ?>><i class="lucide lucide-rotate-ccw"></i> <span><?php p($h('actions.restore', 'Restore this revision')); ?></span></button>
									<button type="button" class="revisions-menu-item revisions-new-btn" role="menuitem"<?php if (!$page['canWrite']): ?> hidden<?php endif; ?>><i class="lucide lucide-plus"></i> <span><?php p($h('actions.new', 'Save a revision')); ?></span></button>
									<button type="button" class="revisions-menu-item" role="menuitem" id="revisionsCopyBtn"><i class="lucide lucide-copy"></i> <span><?php p($h('actions.copy', 'Copy content')); ?></span></button>
									<div class="revisions-menu-separator" role="separator"<?php if (!$page['canWrite']): ?> hidden<?php endif; ?>></div>
									<button type="button" class="revisions-menu-item revisions-menu-danger" role="menuitem" id="revisionsDeleteBtn"<?php if (!$page['canWrite']): ?> hidden<?php endif; ?>><i class="lucide lucide-trash-2"></i> <span><?php p($h('actions.delete', 'Delete this revision')); ?></span></button>
								</div>
							</div>
						</div>
					</div>

					<div class="revisions-toolbar" id="revisionsToolbar">
						<div class="revisions-toolbar-group" data-for-view="changes">
							<label class="revisions-compare"><span><?php p($h('compare_with', 'Compare with')); ?></span> <select id="revisionsCompare"></select></label>
							<div class="revisions-segmented" role="group" aria-label="<?php p($h('layout.label', 'Layout')); ?>">
								<button type="button" data-layout="unified" title="<?php p($h('layout.unified', 'Unified')); ?>"><i class="lucide lucide-rows-2"></i><span><?php p($h('layout.unified', 'Unified')); ?></span></button>
								<button type="button" data-layout="split" title="<?php p($h('layout.split', 'Side by side')); ?>"><i class="lucide lucide-columns-2"></i><span><?php p($h('layout.split', 'Side by side')); ?></span></button>
							</div>
						</div>
					</div>

					<div class="revisions-diff-bar" id="revisionsDiffBar" data-for-view="changes">
						<div class="revisions-diff-direction" id="revisionsDiffDirection"></div>
						<div class="revisions-diff-stats" id="revisionsDiffStats"></div>
						<div class="revisions-diff-nav">
							<button type="button" class="revisions-btn revisions-btn-icon" id="revisionsPrevChange" title="<?php p($h('prev_change', 'Previous change')); ?>" aria-label="<?php p($h('prev_change', 'Previous change')); ?>"><i class="lucide lucide-chevron-up"></i></button>
							<button type="button" class="revisions-btn revisions-btn-icon" id="revisionsNextChange" title="<?php p($h('next_change', 'Next change')); ?>" aria-label="<?php p($h('next_change', 'Next change')); ?>"><i class="lucide lucide-chevron-down"></i></button>
						</div>
					</div>

					<div class="revisions-body" id="revisionsBody"></div>
				</div>
			</main>
		</div>
	</div>
</div>
</div>
