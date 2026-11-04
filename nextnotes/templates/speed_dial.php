<?php
/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// abgeleitet von src/public/speed_dial.php @ 7e2e0b6aa501ba95ac1e9789fd2bb477c281a001
// Speed Dial: Notizen mit dem internen Tag speed-dial als Kacheln/Gruppen (speed-dial.js: Filter, Größe, Reihenfolge, Label-Farbe).
// Daten: PageController::speedDial, SpeedDialViewService. Abweichungen: Icon-Leiste im NC-Rahmen, <body>-Attribute über die JSON-Insel,
// Hintergrund-Knopf in der Werkzeugleiste (Upstream im Kontext-Anpassungspanel, nur mit Schreibrecht), keine fremden Favicons (B62).
/** @var array $_ */
/** @var \OCA\NextNotes\Service\View\PageTranslator $tr */
$tr = $_['tr'];
$frame = $_['frame'];
$view = $_['view'];
$sd = $_['speedDial'];
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;
$t = static fn (string $key, string $default, array $vars = []) => $tr->t($key, $vars, $default);
$island = [
	'data' => ['workspace' => $frame['workspaceKey']],
	'style' => ['--speed-dial-tile-width' => $sd['tileSize'] . 'px'],
	'replaceWorkspace' => $frame['replaceWorkspace'],
];
$titleIcon = 'lucide-zap';
$titleText = $t('speed_dial.title', 'Speed dial');
$editLabel = $t('speed_dial.edit_note', 'Open the note');
$tileSizeLabel = $t('speed_dial.tile_size', 'Tile size');
$bgLabel = $t('modals.background_image.title', 'Background Image Settings');
/** Kachel-Link wie Upstream speedDialRenderLink; Bilder per data-src (speed-dial.js lädt sie) */
$renderLink = static function (array $tile, string $fallbackUrl): void {
	?>
	<a class="speed-dial-link" draggable="false" href="<?php p($tile['link'] ?? $fallbackUrl); ?>"<?php if ($tile['link'] !== null): ?> target="_blank" rel="noopener noreferrer" data-url="<?php p($tile['link']); ?>"<?php endif; ?>>
		<span class="speed-dial-icon">
			<span class="speed-dial-letter"><?php p($tile['letter']); ?></span>
			<?php if ($tile['image'] !== null): ?>
			<img data-src="<?php p($tile['image']); ?>" alt="" draggable="false" referrerpolicy="no-referrer">
			<?php endif; ?>
		</span>
		<span class="speed-dial-label"><?php p($tile['label']); ?></span>
	</a>
	<?php
};
?>
<div id="app-content">
<div id="nextnotes-root" class="nextnotes-listpage speed-dial-page<?php if ($sd['backgroundUrl'] !== null): ?> has-speed-dial-background<?php endif; ?>">
	<script type="application/json" id="nextnotes-body"><?php print_unescaped(json_encode($island, $jsonFlags)); ?></script>
	<?php include __DIR__ . '/partials/icon_sidebar.php'; ?>
	<main class="nn-page-main nn-speed-dial-main">
	<?php if ($sd['backgroundUrl'] !== null): ?>
	<div class="speed-dial-background" style="background-image: url('<?php p($sd['backgroundUrl']); ?>'); opacity: <?php p((string)($sd['backgroundOpacity'] / 100)); ?>;"></div>
	<?php endif; ?>
	<div class="speed-dial-container">
		<?php include __DIR__ . '/partials/page_title.php'; ?>

		<?php if ($sd['items'] === []): ?>
		<?php // eigener Text (NC-l10n): Tag speed-dial ist reserviert (P27), keine Favicons (B62) ?>
		<div class="speed-dial-empty">
			<p><?php p($l->t('Your speed dial is empty.')); ?></p>
			<p><?php p($l->t('To add a tile, use "%s" in the menu of a note or folder. A tile opens the first link of the note, or the note itself; its icon is the first image of the note or the first letter of the title. A note with several links becomes a group of tiles, a horizontal line starts a new row.', [$t('speed_dial.add', 'Add to speed dial')])); ?></p>
		</div>
		<?php endif; ?>
		<div class="speed-dial-toolbar">
		<?php if ($sd['items'] !== []): ?>
		<div id="speedDialSize" class="speed-dial-size">
			<button type="button" id="speedDialSizeBtn" class="speed-dial-size-btn" aria-expanded="false" aria-controls="speedDialSizePopover" title="<?php p($tileSizeLabel); ?>" aria-label="<?php p($tileSizeLabel); ?>">
				<i class="lucide lucide-zoom-in"></i>
			</button>
			<div id="speedDialSizePopover" class="speed-dial-size-popover" hidden>
				<label for="speedDialSizeInput"><?php p($tileSizeLabel); ?>: <span id="speedDialSizeValue"><?php p((string)$sd['tileSize']); ?></span> px</label>
				<input type="range" id="speedDialSizeInput" min="80" max="260" step="10" value="<?php p((string)$sd['tileSize']); ?>">
			</div>
		</div>
		<?php endif; ?>
		<?php if ($sd['canWrite']): ?>
		<button type="button" id="edgeMenuSpeedDialBackground" class="speed-dial-size-btn" title="<?php p($bgLabel); ?>" aria-label="<?php p($bgLabel); ?>">
			<i class="lucide lucide-image"></i>
		</button>
		<?php endif; ?>
		<?php if ($sd['items'] !== []): ?>
		<div id="speedDialFilter" class="home-search-container">
			<div class="home-search-wrapper">
				<input type="text" id="speedDialFilterInput" class="home-search-input" placeholder="<?php p($t('public.filter_placeholder', 'Filter by name or link...')); ?>" autocomplete="off">
				<button type="button" id="speedDialFilterClear" class="home-search-clear" aria-label="<?php p($t('search.clear', 'Clear search')); ?>" title="<?php p($t('search.clear', 'Clear search')); ?>">
					<i class="lucide lucide-x"></i>
				</button>
			</div>
		</div>
		<?php endif; ?>
		</div>
		<?php if ($sd['items'] !== []): ?>
		<div id="speedDialGrid" class="speed-dial-grid" data-label-colors="<?php p(json_encode((object)$sd['labelColors'], JSON_THROW_ON_ERROR)); ?>" data-txt-reorder-error="<?php p($t('dashboard.reorder_error', 'Could not save the card order.')); ?>">
			<?php foreach ($sd['items'] as $item): ?>
			<?php if (isset($item['rows'])): $labelColor = $sd['labelColors'][$item['id']] ?? ''; ?>
			<div class="speed-dial-item speed-dial-group" data-note-id="<?php p((string)$item['id']); ?>" data-filter="<?php p($item['heading']); ?>">
				<div class="speed-dial-group-title"><a class="speed-dial-group-name" draggable="false" href="<?php p($item['noteUrl']); ?>"<?php if ($labelColor !== ''): ?> style="--speed-dial-label-color: <?php p($labelColor); ?>"<?php endif; ?>><?php p($item['heading']); ?></a></div>
				<?php foreach ($item['rows'] as $rowIndex => $row): ?>
				<?php if ($rowIndex > 0): ?><hr class="speed-dial-group-rule"><?php endif; ?>
				<?php if ($row !== []): ?>
				<div class="speed-dial-group-row">
					<?php foreach ($row as $tile): ?>
					<div class="speed-dial-tile" data-filter="<?php p($tile['label'] . ' ' . $tile['link']); ?>">
						<?php $renderLink($tile, $item['noteUrl']); ?>
					</div>
					<?php endforeach; ?>
				</div>
				<?php endif; ?>
				<?php endforeach; ?>
			</div>
			<?php else: $tile = $item['tile']; ?>
			<div class="speed-dial-item speed-dial-tile" data-note-id="<?php p((string)$item['id']); ?>" data-filter="<?php p($tile['label'] . ' ' . ($tile['link'] ?? '')); ?>">
				<?php $renderLink($tile, $item['noteUrl']); ?>
				<?php if ($tile['link'] !== null): ?>
				<a class="speed-dial-edit" draggable="false" href="<?php p($item['noteUrl']); ?>" aria-label="<?php p($editLabel); ?>" title="<?php p($editLabel); ?>"><i class="lucide lucide-pencil"></i></a>
				<?php endif; ?>
			</div>
			<?php endif; ?>
			<?php endforeach; ?>
		</div>
		<p id="speedDialNoResults" class="speed-dial-empty" hidden><?php p($t('public.no_filter_results', 'No items match your search.')); ?></p>
		<div id="noteTitleMenu" role="dialog" aria-label="<?php p($t('speed_dial.label_color', 'Label color')); ?>" hidden>
			<div class="note-title-menu-label"><?php p($t('speed_dial.label_color', 'Label color')); ?></div>
			<div class="note-title-menu-swatches">
				<button type="button" class="note-title-menu-swatch is-default" data-color="" title="<?php p($t('colors.default', 'Default')); ?>" aria-label="<?php p($t('colors.default', 'Default')); ?>"></button>
				<?php foreach ($view['iconPalette'] as $color): ?>
				<button type="button" class="note-title-menu-swatch" data-color="<?php p($color['hex']); ?>" style="background-color: <?php p($color['css']); ?>" title="<?php p($t('colors.' . $color['id'], ucfirst($color['id']))); ?>" aria-label="<?php p($t('colors.' . $color['id'], ucfirst($color['id']))); ?>"></button>
				<?php endforeach; ?>
			</div>
		</div>
		<?php endif; ?>
	</div>
	</main>

	<?php if ($sd['canWrite']): ?>
	<div id="backgroundImageModal" class="modal">
		<div class="modal-content">
			<div class="modal-header">
				<h3><?php p($bgLabel); ?></h3>
			</div>
			<div class="modal-body">
				<p><?php p($t('modals.background_image.description', 'Upload a background image for your workspace. You can adjust the opacity to make it subtle.')); ?></p>
				<div class="background-preview-container" id="backgroundPreviewContainer">
					<div class="background-preview" id="backgroundPreview">
						<p class="no-background-text"><?php p($t('modals.background_image.no_image', 'No background image set')); ?></p>
					</div>
				</div>
				<div class="background-upload-controls">
					<input type="file" id="backgroundImageInput" accept="image/jpeg,image/png,image/gif,image/webp" hidden>
					<button type="button" class="btn-secondary" id="uploadBackgroundBtn"><?php p($t('modals.background_image.upload', 'Upload Image')); ?></button>
					<button type="button" class="btn-danger" id="removeBackgroundBtn" style="display: none;"><?php p($t('modals.background_image.remove', 'Remove')); ?></button>
				</div>
				<div class="background-opacity-control">
					<label for="backgroundOpacityInput"><?php p($t('modals.background_image.opacity', 'Background Opacity')); ?>: <span id="backgroundOpacityValue">100</span>%</label>
					<input type="range" id="backgroundOpacityInput" min="5" max="100" step="1" value="100">
				</div>
			</div>
			<div class="modal-buttons">
				<button type="button" class="btn-cancel" id="cancelBackgroundBtn"><?php p($t('common.cancel', 'Cancel')); ?></button>
				<button type="button" class="btn-primary" id="saveBackgroundBtn"><?php p($t('common.save', 'Save')); ?></button>
			</div>
		</div>
	</div>
	<?php endif; ?>
</div>
</div>
