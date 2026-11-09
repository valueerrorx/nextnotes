<?php
/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// eigene Vorlage (M6g, SPEC §9.6): Aufgaben austeilen und einsammeln, Rahmen wie tasks.php (Icon-Leiste, Titelzeile mit Workspace-Chip, Insel).
// Liste, Abgaben, Lesemodus und Dialoge baut nextnotes-assignments.js aus der Aufgaben-API; Texte aus NC-l10n (AssignmentsViewService::texts).
/** @var array $_ */
/** @var \OCA\NextNotes\Service\View\PageTranslator $tr */
$tr = $_['tr'];
$frame = $_['frame'];
$view = $_['view'];
$txt = $_['texts'];
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;
$asg = $_['asg'];
$islands = [
	'nextnotes-body' => ['data' => ['workspace' => $frame['workspaceKey']], 'replaceWorkspace' => $frame['replaceWorkspace']],
	'nextnotes-assignments' => ['team' => $asg['team'], 'teacher' => $asg['teacher'], 'workspaceId' => $asg['workspaceId'],
		'createSource' => $asg['createSource'], 'txt' => $txt, 'hintBytes' => $_['hintBytes']],
];
$titleIcon = 'lucide-graduation-cap';
$titleText = $txt['title'];
?>
<div id="app-content">
<div id="nextnotes-root" class="nextnotes-listpage tasks-page nn-asg-page">
	<?php foreach ($islands as $islandId => $islandData): ?>
	<script type="application/json" id="<?php p($islandId); ?>"><?php print_unescaped(json_encode($islandData, $jsonFlags)); ?></script>
	<?php endforeach; ?>
	<?php include __DIR__ . '/partials/icon_sidebar.php'; ?>
	<main class="nn-page-main" inert>
	<div class="tasks-container nn-asg">
		<?php // M6i/ADR 0021: Bereich = Workspace, Wechsel über den Workspace-Chip wie auf den anderen Seiten ?>
		<?php include __DIR__ . '/partials/page_title.php'; ?>
		<?php if ($asg['team'] === null): ?>
		<p class="empty-message nn-asg-empty nn-asg-no-team"><?php p($txt['noTeamWorkspace']); ?></p>
		<?php endif; ?>
		<div class="nn-asg-toolbar">
			<?php if ($asg['teacher'] !== null): ?>
			<?php // ausgeblendet, bis nextnotes-assignments.js den Klick verdrahtet hat (M6i-fix: veraltetes Skript im Browser-Cache) ?>
			<button type="button" id="nnAsgNew" class="btn-primary nn-asg-new" hidden><i class="lucide lucide-plus" aria-hidden="true"></i> <?php p($txt['newAssignment']); ?></button>
			<?php endif; ?>
		</div>
		<div id="nnAsgStatus" class="nn-asg-status" role="status" aria-live="polite"></div>
		<div id="nnAsgList" class="nn-asg-list" aria-busy="true"></div>
		<section id="nnAsgDetail" class="nn-asg-detail" hidden></section>
	</div>
	</main>
</div>
</div>
