<?php
/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// abgeleitet von src/public/trash.php @ 7e2e0b6aa501ba95ac1e9789fd2bb477c281a001
// Karten des Papierkorbs und „Mehr laden“ (Cursor der letzten Karte); Seite trash.php und Fragment trash_cards.
/** @var array $_ */
/** @var \OCA\NextNotes\Service\View\PageTranslator $tr */
/** @var array $frame */
/** @var array $trash */
/** @var bool $canWrite */
/** @var callable $t */
?>
<?php foreach ($trash['notes'] as $card): ?>
<div id="note<?php p((string)$card['id']); ?>" class="trash-notecard">
	<div class="trash-innernote">
		<div class="trash-note-header">
			<div class="trash-note-titleblock">
				<h3 class="css-title"><?php p($card['heading']); ?></h3>
				<div class="lastupdated"><?php p($card['dateLabel']); ?></div>
			</div>
			<?php if ($canWrite): ?>
			<div class="trash-action-icons">
				<button type="button" class="trash-action-btn trash-restore-btn" title="<?php p($t('trash.actions.restore_note_tooltip', 'Restore this note')); ?>" data-noteid="<?php p((string)$card['id']); ?>"><i class="lucide lucide-undo-2"></i></button>
				<button type="button" class="trash-action-btn trash-delete-btn" title="<?php p($t('trash.actions.delete_permanently_tooltip', 'Delete permanently')); ?>" data-noteid="<?php p((string)$card['id']); ?>"><i class="lucide lucide-trash-2"></i></button>
			</div>
			<?php endif; ?>
		</div>
		<div class="trash-note-preview">
			<?php // Vorschau: TrashViewService::preview (gespeicherter bzw. neu bereinigter Inhalt, gerendertes Markdown, escapte Aufgabenliste) ?>
			<div class="noteentry"><?php print_unescaped($card['html']); ?></div>
			<div class="trash-preview-fade"></div>
		</div>
		<button type="button" class="trash-expand-btn" hidden>
			<span class="trash-expand-more"><i class="lucide lucide-chevron-down"></i> <?php p($t('trash.note.show_more', 'Show more')); ?></span>
			<span class="trash-expand-less"><i class="lucide lucide-chevron-up"></i> <?php p($t('trash.note.show_less', 'Show less')); ?></span>
		</button>
	</div>
</div>
<?php endforeach; ?>
<?php if ($trash['remaining'] > 0): ?>
<div class="trash-load-more">
	<button type="button" class="btn btn-secondary trash-load-more-btn" data-after="<?php p($trash['cursor']); ?>" data-sort="<?php p($trash['sort']); ?>" data-search="<?php p($trash['search']); ?>" data-workspace="<?php p($frame['workspaceKey']); ?>"><?php p($t('common.show_more_notes', 'Show more ({{count}} remaining)', ['count' => (string)$trash['remaining']])); ?></button>
</div>
<?php endif; ?>
