<?php
/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// abgeleitet von src/public/mentions.php @ 7e2e0b6aa501ba95ac1e9789fd2bb477c281a001
// Erwähnungen: Notizen geteilter Workspaces, die den Benutzer mit @uid nennen, je Workspace eine Gruppe (mentions-page.js: erledigt/offen).
// Daten: PageController::mentions, MentionService::page. Abweichungen: Icon-Leiste im NC-Rahmen, <body>-Attribute über die JSON-Insel,
// Link direkt auf die Notiz (index.php?note=…&workspace=…) statt open_shared.php, Namen als NC-Anzeigenamen.
/** @var array $_ */
/** @var \OCA\NextNotes\Service\View\PageTranslator $tr */
$tr = $_['tr'];
$frame = $_['frame'];
$view = $_['view'];
$page = $_['mentions'];
$showHandled = $_['showHandled'];
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;
$t = static fn (string $key, string $default, array $vars = []) => $tr->t($key, $vars, $default);
$markLabel = $t('mentions.mark_handled', 'Mark as handled');
$reopenLabel = $t('mentions.reopen', 'Mark as not handled');
$island = [
	'data' => [
		'workspace' => $frame['workspaceKey'],
		'show-handled' => $showHandled ? '1' : '0',
		'txt-error' => $t('common.error', 'Error'),
		'txt-mark-handled' => $markLabel,
		'txt-reopen' => $reopenLabel,
	],
	'replaceWorkspace' => $frame['replaceWorkspace'],
];
$toggleQuery = ['workspace' => $frame['workspaceKey']] + ($showHandled ? [] : ['handled' => '1']);
?>
<div id="app-content">
<div id="nextnotes-root" class="nextnotes-listpage tasks-page mentions-page">
	<script type="application/json" id="nextnotes-body"><?php print_unescaped(json_encode($island, $jsonFlags)); ?></script>
	<?php include __DIR__ . '/partials/icon_sidebar.php'; ?>
	<main class="nn-page-main" inert>
	<div class="tasks-container">
		<h1 class="poznote-page-title"><span class="poznote-page-title-name"><i class="lucide lucide-at-sign"></i> <?php p($t('mentions.title', 'Mentions')); ?></span>
			<?php // UI-ws: Erwähnungen gelten workspaceübergreifend, Bereich statt Auswahl ?>
			<span class="poznote-page-title-workspace poznote-page-title-workspace-static nn-ws-scope"><i class="lucide lucide-layers poznote-page-title-workspace-icon" aria-hidden="true"></i><span class="poznote-page-title-workspace-name"><?php p($t('dashboard.scope.all', 'All workspaces')); ?></span></span>
		</h1>

		<div class="mentions-toolbar">
			<p class="mentions-intro"><?php p($t('mentions.intro', 'Notes of shared workspaces that mention you with @{{username}}.', ['username' => $_['uid']])); ?></p>
			<a class="mentions-toggle-handled<?php if ($showHandled): ?> active<?php endif; ?>" href="<?php p($_['baseUrl'] . 'mentions.php?' . http_build_query($toggleQuery)); ?>" aria-pressed="<?php p($showHandled ? 'true' : 'false'); ?>">
				<i class="lucide <?php p($showHandled ? 'lucide-eye-off' : 'lucide-eye'); ?>"></i>
				<span><?php p($showHandled ? $t('mentions.hide_handled', 'Hide handled') : $t('mentions.show_handled', 'Show handled')); ?></span>
			</a>
		</div>

		<div class="tasks-content" id="mentionsContent">
			<?php foreach ($page['groups'] as $group): ?>
			<section class="tasks-note-group mentions-group">
				<header class="mentions-group-header">
					<i class="lucide lucide-layers"></i>
					<span class="mentions-group-workspace"><?php p($group['workspace']); ?></span>
					<?php if ($group['ownerName'] !== ''): ?>
					<span class="mentions-group-owner"><?php p($t('mentions.shared_by', 'shared by {{owner}}', ['owner' => $group['ownerName']])); ?></span>
					<?php endif; ?>
				</header>
				<ul class="mentions-list">
					<?php foreach ($group['mentions'] as $m):
						$title = trim($m['title']) !== '' ? $m['title'] : $t('common.untitled', 'Untitled');
						$meta = [];
						if ($m['updatedByName'] !== '') {
							$meta[] = $t('mentions.modified_by', 'Last modified by {{user}}', ['user' => $m['updatedByName']]);
						}
						if ($m['updatedDisplay'] !== '') {
							$meta[] = $m['updatedDisplay'];
						}
					?>
					<li class="mentions-item<?php if ($m['handled']): ?> handled<?php endif; ?>" data-owner-id="<?php p($m['owner_path']); ?>" data-note-id="<?php p((string)$m['note_id']); ?>" data-handled="<?php p($m['handled'] ? '1' : '0'); ?>">
						<div class="mentions-item-body">
							<a class="mentions-item-title" href="<?php p($_['baseUrl'] . 'index.php?' . http_build_query(['note' => $m['note_id'], 'workspace' => $m['workspace']])); ?>"><?php p($title); ?></a>
							<?php if ($m['excerpt'] !== ''): ?>
							<p class="mentions-item-excerpt"><?php p($m['excerpt']); ?></p>
							<?php endif; ?>
							<?php if ($meta !== []): ?>
							<p class="mentions-item-meta"><?php p(implode(' · ', $meta)); ?></p>
							<?php endif; ?>
						</div>
						<button type="button" class="mentions-handled-btn" title="<?php p($m['handled'] ? $reopenLabel : $markLabel); ?>" aria-label="<?php p($m['handled'] ? $reopenLabel : $markLabel); ?>">
							<i class="lucide <?php p($m['handled'] ? 'lucide-rotate-ccw' : 'lucide-check'); ?>"></i>
						</button>
					</li>
					<?php endforeach; ?>
				</ul>
			</section>
			<?php endforeach; ?>

			<div id="mentionsEmpty" class="empty-message<?php if ($page['groups'] !== []): ?> initially-hidden<?php endif; ?>">
				<p><?php p($page['hasShared']
					? $t('mentions.empty', 'No note is waiting for you.')
					: $t('mentions.empty_no_workspace', 'Mentions work in shared workspaces: write @ followed by a username in a note to draw that person\'s attention to it.')); ?></p>
			</div>
		</div>
	</div>
	</main>
</div>
</div>
