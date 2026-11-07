<?php
/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// abgeleitet von src/public/list_folders.php @ 7e2e0b6aa501ba95ac1e9789fd2bb477c281a001
// Zeilen von #foldersList; Seite list_folders.php und Fragment folders_list (P24).
/** @var array $folders */
/** @var bool $canWrite */
/** @var string $key */
/** @var callable $t */
$menuLabel = $t('notes_list.folder_actions.menu', 'Folder actions');
?>
	<?php if ($folders['rows'] === []): ?>
		<div class="empty-message"><p><?php p($t('folders.no_folders', 'No folders yet.')); ?></p></div>
	<?php endif; ?>
	<?php foreach ($folders['rows'] as $row): ?>
		<?php
		$folderId = (string)$row['id'];
		$folderData = ['note-count' => (string)$row['count'], 'speed-dial' => $row['speedDial'] ? '1' : '0', 'favorite' => $row['favorite'] ? '1' : '0'];
		?>
		<div class="shared-note-item folder-item" data-action="open-folder-kanban" data-kanban-url="<?php p('index.php?' . http_build_query(['kanban' => $folderId, 'workspace' => $key])); ?>" data-folder-name="<?php p($row['name']); ?>" data-folder-id="<?php p($folderId); ?>" data-depth="<?php p((string)$row['depth']); ?>" data-has-children="<?php p($row['hasChildren'] ? '1' : '0'); ?>" data-nn-write="<?php p($row['writable'] ? '1' : '0'); ?>" data-nn-material="<?php p($row['material']); ?>" style="padding-left: <?php p((string)(15 + $row['depth'] * 22)); ?>px">
			<div class="note-name-container">
				<?php if ($row['hasChildren']): ?>
				<button type="button" class="shared-folder-toggle" data-action="toggle-folder-collapse" aria-expanded="true" title="<?php p($t('public.collapse_folder', 'Collapse folder')); ?>" aria-label="<?php p($t('public.collapse_folder', 'Collapse folder')); ?>"><i class="lucide lucide-chevron-down"></i></button>
				<?php else: ?>
				<span class="shared-folder-toggle is-placeholder" aria-hidden="true"></span>
				<?php endif; ?>
				<div class="shared-folder-icon"><i class="<?php p($row['iconClass']); ?>"<?php if ($row['iconColor'] !== ''): ?> style="color: <?php p($row['iconColor']); ?> !important"<?php endif; ?>></i></div>
				<span class="folder-name-text"><?php p($row['name']); ?> <span class="folder-name-count">(<?php p((string)$row['direct']); ?>)</span></span>
				<?php if ($row['material'] !== ''): ?><i class="lucide lucide-book-open nn-material-mark<?php p($row['material'] === 'inherited' ? ' nn-material-inherited' : ''); ?>" role="img" title="<?php p($row['material'] === 'own' ? $folders['materialTitle'] : $folders['materialInherited']); ?>" aria-label="<?php p($row['material'] === 'own' ? $folders['materialTitle'] : $folders['materialInherited']); ?>"></i><?php endif; ?>
			</div>
			<div class="folder-list-actions">
				<?php if ($row['writable']): ?>
				<div class="folder-inline-actions">
					<?php $favLabel = $row['favorite'] ? $t('notes_list.folder_actions.remove_favorite', 'Remove from favorites') : $t('notes_list.folder_actions.add_favorite', 'Add to favorites'); ?>
					<button type="button" class="folder-inline-action-btn<?php if ($row['favorite']): ?> is-active<?php endif; ?>" title="<?php p($favLabel); ?>" aria-label="<?php p($favLabel); ?>" data-action="favorite-folder" data-folder-id="<?php p($folderId); ?>" data-folder-name="<?php p($row['name']); ?>" data-note-count="<?php p($folderData['note-count']); ?>" data-shared="0" data-speed-dial="<?php p($folderData['speed-dial']); ?>" data-favorite="<?php p($folderData['favorite']); ?>" data-offline="0"><i class="lucide lucide-star"></i></button>
					<button type="button" class="folder-inline-action-btn" title="<?php p($t('notes_list.folder_actions.rename_folder', 'Rename')); ?>" aria-label="<?php p($t('notes_list.folder_actions.rename_folder', 'Rename')); ?>" data-action="rename-folder" data-folder-id="<?php p($folderId); ?>" data-folder-name="<?php p($row['name']); ?>" data-note-count="<?php p($folderData['note-count']); ?>" data-shared="0" data-speed-dial="<?php p($folderData['speed-dial']); ?>" data-favorite="<?php p($folderData['favorite']); ?>" data-offline="0"><i class="lucide lucide-pencil"></i></button>
				</div>
				<?php endif; ?>
				<button type="button" class="folder-list-menu-btn" data-action="open-folder-actions-modal" data-folder-id="<?php p($folderId); ?>" data-nn-write="<?php p($row['writable'] ? '1' : '0'); ?>" data-folder-name="<?php p($row['name']); ?>" data-note-count="<?php p($folderData['note-count']); ?>" data-shared="0" data-speed-dial="<?php p($folderData['speed-dial']); ?>" data-favorite="<?php p($folderData['favorite']); ?>" data-offline="0" title="<?php p($menuLabel); ?>" aria-label="<?php p($menuLabel); ?>"><i class="lucide lucide-more-vertical"></i></button>
			</div>
		</div>
	<?php endforeach; ?>
