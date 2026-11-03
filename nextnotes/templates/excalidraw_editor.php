<?php
/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// abgeleitet von src/public/excalidraw_editor.php @ 7e2e0b6aa501ba95ac1e9789fd2bb477c281a001
// Excalidraw-Editor (excalidraw-editor.js). Daten: PageController::excalidrawEditor, ExcalidrawService::page.
// Abweichungen: im NC-Rahmen, Inline-Skripte → JSON-Inseln (EXCALIDRAW_ASSET_PATH aus der App-Konfiguration),
// Titel und Ladehinweis ohne „Poznote“, Lesefreigabe ohne Speichern-Knöpfe (Ansicht, P14), kein Anlegen neuer Notizen.
/** @var array $_ */
/** @var \OCA\NextNotes\Service\View\PageTranslator $tr */
$tr = $_['tr'];
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;
$islands = [
	'excalidraw-config' => $_['config'],
	'nextnotes-body' => ['classes' => array_merge(['excalidraw-editor-page'], $_['canWrite'] ? [] : ['nextnotes-readonly'])],
];
?>
<div id="app-content">
<div id="nextnotes-root" class="nextnotes-excalidraw">
	<?php foreach ($islands as $islandId => $islandData): ?>
	<script type="application/json" id="<?php p($islandId); ?>"><?php print_unescaped(json_encode($islandData, $jsonFlags)); ?></script>
	<?php endforeach; ?>
	<div class="excalidraw-page-wrapper">
		<div class="poznote-toolbar">
			<div class="poznote-toolbar-buttons">
				<?php if ($_['canWrite']): ?>
				<button id="saveBtn" class="excalidraw-btn excalidraw-save-btn" disabled><?php p($tr->t('common.save', [], 'Save')); ?></button>
				<button id="saveAndExitBtn" class="excalidraw-btn excalidraw-btn-blue"><?php p($tr->t('excalidraw.editor.toolbar.save_and_exit', [], 'Save and exit')); ?></button>
				<button id="cancelBtn" class="excalidraw-btn excalidraw-btn-neutral"><?php p($tr->t('excalidraw.editor.toolbar.exit_without_saving', [], 'Exit without saving')); ?></button>
				<?php else: ?>
				<button id="cancelBtn" class="excalidraw-btn excalidraw-btn-neutral"><?php p($tr->t('info.actions.back_to_note', [], 'Go back to the note')); ?></button>
				<?php endif; ?>
			</div>
			<h3 class="poznote-toolbar-title"><?php p($_['title']); ?></h3>
			<div class="poznote-toolbar-spacer"></div>
		</div>
		<div id="app" class="excalidraw-app-container">
			<div id="loading" class="excalidraw-loading"><?php p($tr->t('common.loading', [], 'Loading...')); ?></div>
		</div>
	</div>
</div>
</div>
