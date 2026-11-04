<?php
/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// abgeleitet von src/public/api_export_note.php @ 7e2e0b6aa501ba95ac1e9789fd2bb477c281a001 (generatePrintHtml)
// Druckansicht (api_export_note.php?format=html&disposition=inline) für jeden Notiztyp, NC-Grundlayout (RENDER_AS_BASE).
// Daten: ExportService::export; $body nach ExportFormatter::cleanContent erneut durch HtmlSanitizer::sanitizeHtml.
// Abweichungen: Inline-Skript → js-src/nextnotes-print.js mit JSON-Insel; auch HTML-/Aufgaben-Notizen (Upstream: eigenes Exportlayout).
/** @var array $_ */
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;
$islandData = ['assetBase' => $_['assetBase'], 'version' => $_['version'], 'bodyClasses' => $_['bodyClasses'], 'fontSize' => $_['fontSize']];
$id = (string)$_['noteId'];
$body = $_['body'];
?>
<script type="application/json" id="nextnotes-print-config"><?php print_unescaped(json_encode($islandData, $jsonFlags)); ?></script>
<div id="nextnotes-root" class="note-print-page">
	<div id="right_pane"><div id="right_col"><div class="notecard" id="note<?php p($id); ?>"><div class="innernote">
		<div class="note-print-header">
			<div class="note-print-title"><?php p($_['title']); ?></div>
			<?php if ($_['tags'] !== []): ?><div class="note-print-tags"><?php foreach ($_['tags'] as $tag): ?><span class="note-print-tag"><?php p($tag); ?></span><?php endforeach; ?></div><?php endif; ?>
		</div>
		<div class="noteentry" id="entry<?php p($id); ?>" data-note-id="<?php p($id); ?>" data-note-type="<?php p($_['type']); ?>"><?php if ($_['type'] === 'markdown'): ?><div class="markdown-preview"><?php endif; ?><?php print_unescaped($body); ?><?php if ($_['type'] === 'markdown'): ?></div><?php endif; ?></div>
	</div></div></div></div>
</div>
