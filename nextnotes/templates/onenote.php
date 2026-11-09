<?php
/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// eigene Vorlage: OneNote-Import (Plan M9 §2 Ansicht, ADR 0004): Anmeldung, Auswahl, Fortschritt, Bericht; Logik in js/nextnotes-onenote.js

/** @var array $_ */
/** @var \OCP\IL10N $l */
$imp = $_['import'];
$statusText = [
	'auth' => $l->t('Waiting for the Microsoft sign-in'),
	'select' => $l->t('Choose what to import'),
	'queued' => $l->t('Waiting for the next run'),
	'running' => $l->t('Importing'),
	'reauth' => $l->t('Paused: sign in to Microsoft again'),
	'done' => $l->t('Finished'),
	'failed' => $l->t('Stopped'),
	'cancelled' => $l->t('Cancelled'),
	'expired' => $l->t('Expired'),
];
$reasons = [
	'not_found' => $l->t('not found at Microsoft'),
	'forbidden' => $l->t('no access'),
	'too_large' => $l->t('too large, imported as a note with a hint'),
	'error' => $l->t('could not be read'),
	'invalid_id' => $l->t('invalid ID'),
];
$open = $imp !== null && in_array($imp['status'], ['auth', 'select', 'queued', 'running', 'reauth'], true);
$progress = $imp !== null && in_array($imp['status'], ['queued', 'running', 'done', 'failed', 'cancelled'], true);
?>
<div id="app-content">
<div id="nextnotes-onenote" class="section"
	data-connect-url="<?php p($_['connectUrl']); ?>"
	data-imports-url="<?php p($_['importsUrl']); ?>"
	data-import-id="<?php p($imp !== null ? (string)$imp['id'] : ''); ?>"
	data-status="<?php p($imp !== null ? $imp['status'] : ''); ?>">
	<h2><?php p($l->t('Import from OneNote')); ?></h2>

	<?php if ($_['message'] !== '') { ?>
		<p id="nextnotes-onenote-message" class="nn-onenote-message" role="status"><?php p($_['message']); ?></p>
	<?php } ?>

	<?php if (!$_['configured']) { ?>
		<p><?php p($l->t('The OneNote import is not set up on this server.')); ?></p>
	<?php } elseif ($imp !== null) { ?>
		<p id="nextnotes-onenote-status" data-status="<?php p($imp['status']); ?>"><?php p($statusText[$imp['status']] ?? $imp['status']); ?></p>
		<?php if (($imp['error_code'] ?? null) !== null && ($_['messages'][$imp['error_code']] ?? '') !== '') { ?>
			<p id="nextnotes-onenote-reason" data-code="<?php p($imp['error_code']); ?>"><?php p($_['messages'][$imp['error_code']]); ?></p>
		<?php } ?>

		<?php if ($imp['status'] === 'select') { ?>
			<div id="nextnotes-onenote-select">
				<p class="nn-onenote-hint"><?php p($l->t('Your own notebooks are selected. Each notebook becomes a new workspace of yours; section groups and sections become folders. Pages imported before are skipped.')); ?></p>
				<p id="nextnotes-onenote-team-hint" class="nn-onenote-hint" hidden><?php p($l->t('Team and class notebooks can contain content of other people, for example of classmates. Nothing is selected there: choose only what you need.')); ?></p>
				<p id="nextnotes-onenote-loading"><?php p($l->t('Loading notebooks …')); ?></p>
				<div id="nextnotes-onenote-tree" class="nn-onenote-tree"></div>
				<p><button type="button" id="nextnotes-onenote-more-teams" data-action="more-teams" hidden><?php p($l->t('Load more teams')); ?></button></p>
				<p>
					<button type="button" class="primary" data-action="start" disabled><?php p($l->t('Import selected')); ?></button>
				</p>
			</div>
		<?php } ?>

		<?php if ($progress) { ?>
			<table id="nextnotes-onenote-progress" class="nn-onenote-progress">
				<tr><th scope="row"><?php p($l->t('Pages imported')); ?></th><td data-field="pages_done"><?php p((string)$imp['pages_done']); ?></td></tr>
				<tr><th scope="row"><?php p($l->t('Pages found')); ?></th><td data-field="pages_total"><?php p((string)$imp['pages_total']); ?></td></tr>
				<tr><th scope="row"><?php p($l->t('Skipped (imported before)')); ?></th><td data-field="pages_skipped"><?php p((string)$imp['pages_skipped']); ?></td></tr>
				<tr><th scope="row"><?php p($l->t('Images and files not imported')); ?></th><td data-field="resources_failed"><?php p((string)$imp['resources_failed']); ?></td></tr>
			</table>
			<p id="nextnotes-onenote-eta" class="nn-onenote-hint" data-eta="<?php p($imp['eta_seconds'] !== null ? (string)$imp['eta_seconds'] : ''); ?>"></p>
			<?php if (in_array($imp['status'], ['queued', 'running'], true)) { ?>
				<p class="nn-onenote-hint"><?php p($l->t('The import runs in the background, also when you close this page. Microsoft allows about 350 requests per hour, so large notebooks take hours.')); ?></p>
			<?php } ?>
			<div id="nextnotes-onenote-report"<?php if ($imp['report'] === []) { ?> hidden<?php } ?>>
				<h3><?php p($l->t('Not imported')); ?></h3>
				<ul class="nn-onenote-report">
					<?php foreach ($imp['report'] as $entry) { ?>
						<?php if (($entry['r'] ?? '') === 'more') { ?>
							<li><?php p($l->n('and %n more', 'and %n more', (int)($entry['n'] ?? 0))); ?></li>
						<?php } else { ?>
							<li data-reason="<?php p((string)($entry['r'] ?? '')); ?>"><span class="nn-onenote-title"><?php p((string)($entry['t'] ?? '') !== '' ? (string)$entry['t'] : $l->t('Untitled note')); ?></span> – <?php p($reasons[$entry['r'] ?? ''] ?? (string)($entry['r'] ?? '')); ?></li>
						<?php } ?>
					<?php } ?>
				</ul>
			</div>
		<?php } ?>

		<p>
			<?php if ($imp['status'] === 'reauth') { ?>
				<button type="button" class="primary" data-action="reauth"><?php p($l->t('Sign in to Microsoft again')); ?></button>
			<?php } ?>
			<?php if ($open) { ?>
				<button type="button" data-action="cancel"><?php p($l->t('Cancel import')); ?></button>
			<?php } else { ?>
				<button type="button" class="primary" data-action="connect"><?php p($l->t('Start a new import')); ?></button>
			<?php } ?>
		</p>
	<?php } else { ?>
		<p><?php p($l->t('Sign in with your school or work Microsoft account to import OneNote notebooks into your own workspaces.')); ?></p>
		<p><button type="button" class="primary" data-action="connect"><?php p($l->t('Connect to Microsoft')); ?></button></p>
	<?php } ?>

	<p id="nextnotes-onenote-error" role="alert"></p>
	<p><a href="<?php p($_['appUrl']); ?>"><?php p($l->t('Back to Next Notes')); ?></a></p>

	<div id="nextnotes-onenote-messages" hidden>
		<?php foreach ($_['messages'] as $code => $text) { ?>
			<span data-code="<?php p($code); ?>"><?php p($text); ?></span>
		<?php } ?>
		<span data-code="import_open"><?php p($l->t('Another OneNote import is still open. Finish or cancel it first.')); ?></span>
		<span data-code="connect_pending"><?php p($l->t('A sign-in is already in progress. Try again in a few seconds.')); ?></span>
		<span data-code="error"><?php p($l->t('The action failed. Reload the page and try again.')); ?></span>
		<span data-code="reconnect"><?php p($l->t('The Microsoft sign-in has expired. Connect again.')); ?></span>
		<span data-code="graph_busy"><?php p($l->t('Microsoft limits the number of requests. Try again in a few minutes.')); ?></span>
		<span data-code="graph_error"><?php p($l->t('Microsoft could not list the notebooks. Try again later.')); ?></span>
		<span data-code="empty"><?php p($l->t('No notebooks found.')); ?></span>
		<span data-code="mine"><?php p($l->t('My notebooks')); ?></span>
		<span data-code="team"><?php p($l->t('Team: %s', ['%s'])); ?></span>
		<span data-code="whole"><?php p($l->t('Whole notebook')); ?></span>
		<span data-code="show_items"><?php p($l->t('Choose sections')); ?></span>
		<span data-code="group"><?php p($l->t('Section group')); ?></span>
		<span data-code="eta"><?php p($l->t('Remaining time: about %s (only with background jobs via cron)', ['%s'])); ?></span>
		<span data-code="eta_h"><?php p($l->t('%1$s h %2$s min', ['%1$s', '%2$s'])); ?></span>
		<span data-code="eta_m"><?php p($l->t('%s min', ['%s'])); ?></span>
		<span data-code="too_many"><?php p($l->t('Too many entries selected (at most 200).')); ?></span>
	</div>
</div>
</div>
