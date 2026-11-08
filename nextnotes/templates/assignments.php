<?php
/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// eigene Vorlage (M6g, SPEC §9.6): Aufgaben austeilen und einsammeln, Rahmen wie tasks.php/mentions.php (Icon-Leiste, Titelzeile, Insel).
// Liste, Abgaben, Lesemodus und Dialoge baut nextnotes-assignments.js aus der Aufgaben-API; Texte aus NC-l10n (AssignmentsViewService::texts).
/** @var array $_ */
$frame = $_['frame'];
$view = $_['view'];
$txt = $_['texts'];
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;
$islands = [
	'nextnotes-body' => ['data' => ['workspace' => $frame['workspaceKey']], 'replaceWorkspace' => $frame['replaceWorkspace']],
	'nextnotes-assignments' => ['teams' => $_['teacherTeams'], 'txt' => $txt, 'hintBytes' => $_['hintBytes'], 'workspace' => $frame['workspaceKey'], 'createTeam' => $_['createTeam']],
];
?>
<div id="app-content">
<div id="nextnotes-root" class="nextnotes-listpage tasks-page nn-asg-page">
	<?php foreach ($islands as $islandId => $islandData): ?>
	<script type="application/json" id="<?php p($islandId); ?>"><?php print_unescaped(json_encode($islandData, $jsonFlags)); ?></script>
	<?php endforeach; ?>
	<?php include __DIR__ . '/partials/icon_sidebar.php'; ?>
	<main class="nn-page-main" inert>
	<div class="tasks-container nn-asg">
		<h1 class="poznote-page-title"><span class="poznote-page-title-name"><i class="lucide lucide-graduation-cap"></i> <?php p($txt['title']); ?></span>
			<?php // Bereich statt Workspace: alle Teams oder ein Team (Filter, ?team=) ?>
			<label class="poznote-page-title-workspace poznote-page-title-workspace-static nn-ws-scope nn-asg-scope"><i class="lucide lucide-users poznote-page-title-workspace-icon" aria-hidden="true"></i>
				<select id="nnAsgTeam" class="nn-asg-team-select" aria-label="<?php p($txt['team']); ?>"><option value=""><?php p($txt['allTeams']); ?></option></select>
			</label>
		</h1>
		<div class="nn-asg-toolbar">
			<?php if ($_['teacherTeams'] !== []): ?>
			<button type="button" id="nnAsgNew" class="btn-primary nn-asg-new"><i class="lucide lucide-plus" aria-hidden="true"></i> <?php p($txt['newAssignment']); ?></button>
			<?php endif; ?>
		</div>
		<div id="nnAsgStatus" class="nn-asg-status" role="status" aria-live="polite"></div>
		<div id="nnAsgList" class="nn-asg-list" aria-busy="true"></div>
		<section id="nnAsgDetail" class="nn-asg-detail" hidden></section>
	</div>
	</main>
</div>
</div>
