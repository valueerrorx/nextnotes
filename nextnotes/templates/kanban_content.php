<?php
/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// abgeleitet von src/public/kanban_content.php @ 7e2e0b6aa501ba95ac1e9789fd2bb477c281a001
// Kanban eines Ordners, per fetch in #right_col geladen (utils-kanban.js). Daten: KanbanViewService.
// Abweichungen: Kartentext als Klartext ohne Links (#1672, M4), ohne Schreibrecht keine Bearbeiten-/Anlegen-Knöpfe,
// Fehlerfall als HTTP-Status statt Fehler-HTML mit Inline-Handler.
/** @var array $_ */
/** @var \OCA\NextNotes\Service\View\PageTranslator $tr */
$tr = $_['tr'];
$view = $_['view'];
$w = $view['canWrite'];
$newNote = $tr->t('index.note.new_note', [], 'New note');

$card = static function (array $c, int $folderId) use ($tr, $w, $newNote): void {
	$toggle = $c['completed'] ? $tr->t('kanban.completed.mark_active', [], 'Mark as not completed') : $tr->t('kanban.completed.mark_completed', [], 'Mark as completed');
	?><div class="kanban-card<?php p($c['completed'] ? ' kanban-card-completed' : ''); ?><?php p($c['colorHex'] !== '' ? ' has-note-color' : ''); ?>"<?php if ($c['colorHex'] !== ''): ?> style="--note-color: <?php p($c['colorHex']); ?>;"<?php endif; ?> data-note-id="<?php p((string)$c['id']); ?>" data-folder-id="<?php p((string)$folderId); ?>" data-completed="<?php p($c['completed'] ? '1' : '0'); ?>" data-color="<?php p($c['colorRaw']); ?>" data-manual-order="<?php p((string)$c['rank']); ?>" data-tags="<?php p(implode(',', $c['tags'])); ?>" data-reminder-due="<?php p($c['reminderDue']); ?>" draggable="<?php p($w ? 'true' : 'false'); ?>">
		<?php if ($w): ?>
		<button type="button" class="kanban-card-complete-btn" data-action="toggle-kanban-completed" data-note-id="<?php p((string)$c['id']); ?>" title="<?php p($toggle); ?>" aria-label="<?php p($toggle); ?>" aria-pressed="<?php p($c['completed'] ? 'true' : 'false'); ?>"><i class="lucide lucide-check"></i></button>
		<?php endif; ?>
		<?php if ($c['updated'] !== '' || $c['due'] !== ''): ?>
		<div class="kanban-card-topline">
			<?php if ($c['due'] !== ''): ?>
			<span class="kanban-card-due<?php p($c['overdue'] ? ' overdue' : ''); ?>" data-due-source="<?php p($c['dueSource']); ?>" title="<?php p($c['overdue'] ? $tr->t('kanban.due.overdue', [], 'Overdue') : $tr->t('kanban.due.label', [], 'Due date')); ?>"><i class="lucide <?php p($c['dueSource'] === 'task' ? 'lucide-alarm-clock' : 'lucide-bell'); ?>"></i><span class="kanban-card-due-text"><?php p($c['dueLabel']); ?></span></span>
			<?php endif; ?>
			<?php if ($c['updated'] !== ''): ?>
			<span class="kanban-card-date"><?php p($c['updated']); ?></span>
			<?php endif; ?>
		</div>
		<?php endif; ?>
		<?php if ($c['tags'] !== []): ?>
		<div class="kanban-card-tags"><?php foreach ($c['tags'] as $i => $tag): ?><span class="kanban-tag<?php p($c['tagColors'][$i] !== '' ? ' kanban-tag-colored' : ''); ?>"<?php if ($c['tagColors'][$i] !== ''): ?> style="--tag-color: <?php p($c['tagColors'][$i]); ?>;"<?php endif; ?>><?php p($tag); ?></span><?php endforeach; ?></div>
		<?php endif; ?>
		<div class="kanban-card-title"><?php p($c['title'] !== '' ? $c['title'] : $newNote); ?></div>
		<div class="kanban-card-snippet<?php p($c['tasks'] !== null ? ' kanban-card-tasklist' : ''); ?>"><?php if ($c['tasks'] !== null): ?><div class="kanban-tasklist-preview<?php p($c['tasks'] === [] ? ' is-empty' : ''); ?>" data-task-note-id="<?php p((string)$c['taskNoteId']); ?>" style="--kanban-task-preview-max-height: 68px;"><?php foreach ($c['tasks'] as $t): ?><label class="kanban-task-preview-item<?php p(($t['completed'] ? ' completed' : '') . ($t['important'] ? ' important' : '')); ?>"><input type="checkbox" class="kanban-task-checkbox" data-task-index="<?php p((string)$t['index']); ?>" data-task-id="<?php p($t['id']); ?>"<?php if ($t['completed']): ?> checked<?php endif; ?><?php if (!$w): ?> disabled<?php endif; ?>><span class="kanban-task-preview-text"><?php p($t['text']); ?></span></label><?php foreach ($t['subtasks'] as $s): ?><label class="kanban-task-preview-item kanban-task-preview-subitem<?php p($s['completed'] ? ' completed' : ''); ?>"><input type="checkbox" class="kanban-subtask-checkbox" data-task-index="<?php p((string)$t['index']); ?>" data-task-id="<?php p($t['id']); ?>" data-subtask-index="<?php p((string)$s['index']); ?>" data-subtask-id="<?php p($s['id']); ?>"<?php if ($s['completed']): ?> checked<?php endif; ?><?php if (!$w): ?> disabled<?php endif; ?>><span class="kanban-task-preview-text"><?php p($s['text']); ?></span></label><?php endforeach; ?><?php endforeach; ?></div><?php else: ?><?php p($c['excerpt']); ?><?php endif; ?></div>
	</div><?php
};

$cards = static function (array $list, int $folderId, array $children) use (&$cards, $card, $tr, $w): void {
	$active = array_values(array_filter($list, static fn (array $c) => !$c['completed']));
	$done = array_values(array_filter($list, static fn (array $c) => $c['completed']));
	foreach ($active as $c) {
		$card($c, $folderId);
	}
	if ($done !== []): ?>
	<div class="kanban-completed-section" data-folder-id="<?php p((string)$folderId); ?>">
		<button type="button" class="kanban-completed-toggle" data-action="toggle-kanban-completed-section" data-folder-id="<?php p((string)$folderId); ?>" aria-expanded="false"><i class="lucide lucide-chevron-right kanban-completed-chevron"></i><span class="kanban-completed-label"><?php p($tr->t('kanban.completed.show', [], 'Show completed')); ?></span><span class="kanban-completed-count"><?php p((string)count($done)); ?></span></button>
		<div class="kanban-completed-content"><?php foreach ($done as $c) {
			$card($c, $folderId);
		} ?></div>
	</div>
	<?php endif;
	foreach ($children as $child):
		$f = $child['folder'];
		$fid = (string)$f['id'];
		$count = count(array_filter($child['cards'], static fn (array $c) => !$c['completed'])); ?>
	<div class="kanban-subfolder-section" data-folder-id="<?php p($fid); ?>">
		<div class="kanban-subfolder-header">
			<button type="button" class="kanban-subfolder-toggle" data-action="toggle-kanban-subfolder-section" data-folder-id="<?php p($fid); ?>" data-label-expand="<?php p($tr->t('kanban.subfolder.expand', [], 'Expand subfolder')); ?>" data-label-collapse="<?php p($tr->t('kanban.subfolder.collapse', [], 'Collapse subfolder')); ?>" title="<?php p($tr->t('kanban.subfolder.expand', [], 'Expand subfolder')); ?>" aria-expanded="false">
				<i class="lucide lucide-chevron-right kanban-subfolder-chevron"></i>
				<i class="<?php p($f['iconClass']); ?> folder-icon kanban-subfolder-icon" data-folder-id="<?php p($fid); ?>" data-icon-color="<?php p($f['iconColorRaw']); ?>"<?php if ($f['iconColor'] !== ''): ?> style="color: <?php p($f['iconColor']); ?> !important;"<?php endif; ?>></i>
				<span class="kanban-subfolder-name"><?php p($f['name']); ?></span>
				<span class="kanban-subfolder-count"><?php p((string)$count); ?></span>
			</button>
			<?php if ($w): ?>
			<button type="button" class="kanban-add-card-btn kanban-subfolder-add-btn" data-action="create-kanban-note" data-folder-id="<?php p($fid); ?>" data-folder-name="<?php p($f['name']); ?>" title="<?php p($tr->t('kanban.add_note', [], 'Add note')); ?>"><i class="lucide lucide-plus-circle"></i></button>
			<?php endif; ?>
		</div>
		<div class="kanban-subfolder-content" data-folder-id="<?php p($fid); ?>"><?php $cards($child['cards'], (int)$f['id'], $child['children']); ?></div>
	</div>
	<?php endforeach;
};

// Spaltenkopf: Symbol und Name öffnen Symbolwahl bzw. Umbenennen (nur mit Schreibrecht)
$title = static function (array $f) use ($w): void {
	?><i class="<?php p($f['iconClass']); ?> folder-icon"<?php if ($w): ?> data-action="open-folder-icon-picker"<?php endif; ?> data-folder-id="<?php p((string)$f['id']); ?>" data-folder-name="<?php p($f['name']); ?>" data-icon-color="<?php p($f['iconColorRaw']); ?>"<?php if ($f['iconColor'] !== ''): ?> style="color: <?php p($f['iconColor']); ?> !important;"<?php endif; ?>></i>
	<span<?php if ($w): ?> data-action="rename-folder" class="cursor-pointer"<?php endif; ?> data-folder-id="<?php p((string)$f['id']); ?>" data-folder-name="<?php p($f['name']); ?>"><?php p($f['name']); ?></span><?php
};
$addBtn = static function (array $f) use ($tr, $w): void {
	if (!$w) {
		return;
	}
	?><button class="kanban-add-card-btn" data-action="create-kanban-note" data-folder-id="<?php p((string)$f['id']); ?>" data-folder-name="<?php p($f['name']); ?>" title="<?php p($tr->t('kanban.add_note', [], 'Add note')); ?>"><i class="lucide lucide-plus-circle"></i></button><?php
};
$root = $view['folder'];
$rootId = (string)$root['id'];
?>
<div id="kanban-view-container" class="kanban-inline-view" data-folder-id="<?php p($rootId); ?>">
	<div class="kanban-inline-header">
		<button class="kanban-scroll-btn-header left" id="kanbanScrollLeft" title="<?php p($tr->t('common.scroll_left', [], 'Scroll Left')); ?>"><i class="lucide lucide-chevron-left"></i></button>
		<h1 class="kanban-title"><?php $title($root); ?></h1>
		<div class="kanban-header-actions">
			<button class="kanban-scroll-btn-header right" id="kanbanScrollRight" title="<?php p($tr->t('common.scroll_right', [], 'Scroll Right')); ?>"><i class="lucide lucide-chevron-right"></i></button>
			<button class="kanban-sort-toggle" data-action="cycle-kanban-card-sort" data-label="<?php p($tr->t('kanban.sort.label', [], 'Sort by')); ?>" data-label-date="<?php p($tr->t('kanban.sort.date', [], 'Date')); ?>" data-label-tag="<?php p($tr->t('kanban.sort.tag', [], 'Tag')); ?>" data-label-manual="<?php p($tr->t('sort.modes.custom', [], 'Custom')); ?>" title="<?php p($tr->t('kanban.sort.label', [], 'Sort by')); ?>"><i class="lucide lucide-calendar kanban-sort-icon"></i></button>
			<button class="kanban-size-toggle" data-action="cycle-kanban-card-size" data-label="<?php p($tr->t('kanban.card_size', [], 'Card size')); ?>" data-label-small="<?php p($tr->t('dashboard.view.size_small', [], 'Small')); ?>" data-label-medium="<?php p($tr->t('dashboard.view.size_medium', [], 'Medium')); ?>" data-label-large="<?php p($tr->t('dashboard.view.size_large', [], 'Large')); ?>" title="<?php p($tr->t('kanban.card_size', [], 'Card size')); ?>"><span class="kanban-size-letter">M</span></button>
			<?php if ($w): ?>
			<button class="kanban-add-column-btn" data-action="create-kanban-column" data-parent-id="<?php p($rootId); ?>" title="<?php p($tr->t('kanban.add_column', [], 'Add column')); ?>"><i class="lucide lucide-plus-circle"></i></button>
			<?php endif; ?>
		</div>
	</div>
	<div class="kanban-board-wrapper">
		<div class="kanban-board" id="kanbanBoard">
			<?php if ($view['cards'] !== []): ?>
			<div class="kanban-column" data-folder-id="<?php p($rootId); ?>">
				<div class="kanban-column-header">
					<div class="kanban-column-title"><span><?php p($tr->t('kanban.uncategorized', [], 'Uncategorized')); ?></span></div>
					<div class="kanban-column-header-actions"><?php $addBtn($root); ?><span class="kanban-column-count"><?php p((string)count(array_filter($view['cards'], static fn (array $c) => !$c['completed']))); ?></span></div>
				</div>
				<div class="kanban-column-content" data-folder-id="<?php p($rootId); ?>"><?php $cards($view['cards'], (int)$root['id'], []); ?></div>
			</div>
			<?php endif; ?>
			<?php foreach ($view['columns'] as $col): $f = $col['folder']; ?>
			<div class="kanban-column" data-folder-id="<?php p((string)$f['id']); ?>">
				<div class="kanban-column-header">
					<div class="kanban-column-title"><?php $title($f); ?></div>
					<div class="kanban-column-header-actions"><?php $addBtn($f); ?><span class="kanban-column-count"><?php p((string)count(array_filter($col['cards'], static fn (array $c) => !$c['completed']))); ?></span></div>
				</div>
				<div class="kanban-column-content" data-folder-id="<?php p((string)$f['id']); ?>"><?php $cards($col['cards'], (int)$f['id'], $col['children']); ?></div>
			</div>
			<?php endforeach; ?>
			<?php if ($view['columns'] === [] && $view['cards'] === []): ?>
			<div class="kanban-empty-state">
				<h2><?php p($tr->t('kanban.empty.title', [], 'No subfolders yet')); ?></h2>
				<p><?php p($tr->t('kanban.empty.message', [], 'Create subfolders in this folder to use the Kanban view. Each subfolder will become a column.')); ?></p>
			</div>
			<?php endif; ?>
		</div>
	</div>
</div>
