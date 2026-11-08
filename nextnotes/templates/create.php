<?php
/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// abgeleitet von src/public/create.php @ 7e2e0b6aa501ba95ac1e9789fd2bb477c281a001
// Erstellen-Hub (utils-note-create.js executeCreateAction). Daten: PageController::create.
// Abweichungen: Icon-Leiste im NC-Rahmen, Inline-Skript (Karten-Klick) → Delegation in nextnotes-late.js, DEFAULT_NOTE_TITLES als
// JSON-Insel; ohne Schreibrecht im Workspace keine Karten.
/** @var array $_ */
/** @var \OCA\NextNotes\Service\View\PageTranslator $tr */
$tr = $_['tr'];
$frame = $_['frame'];
$view = $_['view'];
$t = static fn (string $key, string $default) => $tr->t($key, [], $default);
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;
$islands = [
	'nextnotes-body' => ['data' => ['workspace' => $frame['workspaceKey']], 'replaceWorkspace' => $frame['replaceWorkspace']],
	'nextnotes-globals' => $_['globals'],
];
$cards = [
	['id' => 'create-note-card', 'type' => 'html', 'icon' => 'lucide-file-alt', 'label' => $t('modals.create.note.title', 'Note')],
	['id' => 'create-markdown-note-card', 'type' => 'markdown', 'icon' => 'lucide-file-code', 'label' => $t('modals.create.markdown.title', 'Markdown Note')],
	['id' => 'create-task-list-card', 'type' => 'list', 'icon' => 'lucide-list-ul', 'label' => $t('modals.create.task_list.title', 'Task List')],
	['id' => 'create-diary-entry-card', 'type' => 'diary', 'icon' => 'lucide-book-open', 'label' => $t('diary.create_card_title', 'Diary entry'),
		'description' => $_['diaryMarkdown'] ? $t('modals.create.markdown.title', 'Markdown note') : $t('modals.create.note.title', 'Rich text note')],
	['id' => 'create-folder-card', 'type' => 'folder', 'icon' => 'lucide-folder', 'label' => $t('modals.create.folder.title', 'Folder')],
];
$titleIcon = 'lucide-plus-circle';
$titleText = $t('sidebar.create', 'Create');
?>
<div id="app-content">
<div id="nextnotes-root" class="nextnotes-listpage">
	<?php foreach ($islands as $islandId => $islandData): ?>
	<script type="application/json" id="<?php p($islandId); ?>"><?php print_unescaped(json_encode($islandData, $jsonFlags)); ?></script>
	<?php endforeach; ?>
	<?php include __DIR__ . '/partials/modals.php'; ?>
	<?php include __DIR__ . '/partials/icon_sidebar.php'; ?>
	<main class="nn-page-main" inert>
	<div class="home-container">
		<?php include __DIR__ . '/partials/page_title.php'; ?>
		<div class="home-grid">
			<?php if ($frame['canWrite']): ?>
			<?php foreach ($cards as $card): ?>
			<a href="#" class="home-card" id="<?php p($card['id']); ?>" data-create-type="<?php p($card['type']); ?>" title="<?php p($card['label']); ?>">
				<div class="home-card-icon"><i class="lucide <?php p($card['icon']); ?>"></i></div>
				<div class="home-card-content"><span class="home-card-title"><?php p($card['label']); ?></span><?php if (isset($card['description'])): ?><span class="home-card-description"><?php p($card['description']); ?></span><?php endif; ?></div>
			</a>
			<?php endforeach; ?>
			<?php else: ?>
			<p class="nn-readonly-hint"><?php p($t('common.read_only', 'Read only')); ?></p>
			<?php endif; ?>
		</div>
	</div>
	</main>
</div>
</div>
