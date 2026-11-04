<?php
/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// abgeleitet von src/public/list_tags.php @ 7e2e0b6aa501ba95ac1e9789fd2bb477c281a001
// Tags des Workspace (list_tags.js, tag-actions.js, clickable-tags.js; Tag-API). Daten: PageController::listTags, TagListViewService.
// Abweichungen: Icon-Leiste im NC-Rahmen, interner Tag speed-dial ausgeblendet (B51), Inline-Skript → JSON-Insel nextnotes-globals.
/** @var array $_ */
/** @var \OCA\NextNotes\Service\View\PageTranslator $tr */
$tr = $_['tr'];
$frame = $_['frame'];
$view = $_['view'];
$tags = $_['tags'];
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;
$islands = [
	'nextnotes-body' => ['data' => ['workspace' => $frame['workspaceKey']], 'replaceWorkspace' => $frame['replaceWorkspace']],
	'nextnotes-globals' => $tags['globals'],
];
$t = static fn (string $key, string $default, array $vars = []) => $tr->t($key, $vars, $default);
$count = count($tags['tags']);
$titleIcon = 'lucide-tags';
$titleText = $t('notes_list.system_folders.tags', 'Tags');
?>
<div id="app-content">
<div id="nextnotes-root" class="nextnotes-listpage">
	<?php foreach ($islands as $islandId => $islandData): ?>
	<script type="application/json" id="<?php p($islandId); ?>"><?php print_unescaped(json_encode($islandData, $jsonFlags)); ?></script>
	<?php endforeach; ?>
	<?php include __DIR__ . '/partials/icon_sidebar.php'; ?>
	<main class="nn-page-main">
	<div class="tags-container">
		<?php include __DIR__ . '/partials/page_title.php'; ?>

		<div class="home-search-container">
			<div class="home-search-wrapper">
				<input type="text" id="tagsSearchInput" class="home-search-input tags-search-no-icon" placeholder="<?php p($t('tags.search.placeholder', 'Filter tags...')); ?>" autocomplete="off">
			</div>
		</div>

		<div class="tags-color-filter<?php if ($tags['colors'] === []): ?> initially-hidden<?php endif; ?>" id="tagsColorFilter" role="group" aria-label="<?php p($t('tags.color_filter.label', 'Filter by color')); ?>">
			<?php foreach ($tags['colors'] as $hex): ?>
			<button type="button" class="tag-color-filter-swatch" data-color-filter="<?php p($hex); ?>" style="--filter-color: <?php p($hex); ?>;" aria-pressed="false" title="<?php p($hex); ?>"></button>
			<?php endforeach; ?>
			<?php if ($tags['hasUncolored']): ?>
			<button type="button" class="tag-color-filter-swatch tag-color-filter-none" data-color-filter="__none__" aria-pressed="false" title="<?php p($t('tags.color_filter.none', 'No color')); ?>"></button>
			<?php endif; ?>
			<button type="button" class="tag-color-filter-clear initially-hidden" id="tagsColorFilterClear"><?php p($t('tags.color_filter.clear', 'Clear color filter')); ?></button>
		</div>

		<div class="tags-info"><?php p($count === 1 ? $t('tags.count.one', 'There is {{count}} tag total', ['count' => '1']) : $t('tags.count.other', 'There are {{count}} tags total', ['count' => (string)$count])); ?></div>

		<div class="tags-grid" id="tagsList">
			<?php if ($tags['tags'] === []): ?>
			<div class="no-tags"><?php p($t('tags.empty', 'No tags found.')); ?></div>
			<?php endif; ?>
			<?php foreach ($tags['tags'] as $tag): ?>
			<div class="tag-item" data-tag="<?php p($tag['name']); ?>" data-color="<?php p($tag['color']); ?>" data-count="<?php p((string)$tag['count']); ?>">
				<div class="tag-name"><?php if ($tag['color'] !== ''): ?><span class="tag-color-dot" style="background: <?php p($tag['color']); ?>;"></span><?php endif; ?><?php p($tag['name']); ?><span class="tag-note-count">(<?php p((string)$tag['count']); ?>)</span></div>
			</div>
			<?php endforeach; ?>
		</div>
	</div>
	</main>
</div>
</div>
