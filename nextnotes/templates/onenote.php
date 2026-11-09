<?php
/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// eigene Vorlage: OneNote-Import (Plan M9 §2 Ansicht, ADR 0004); M9a nur Anmeldung, Status und Abbrechen, Auswahl folgt in M9b; Logik in js/nextnotes-onenote.js

/** @var array $_ */
/** @var \OCP\IL10N $l */
$imp = $_['import'];
$statusText = [
	'auth' => $l->t('Waiting for the Microsoft sign-in'),
	'select' => $l->t('Signed in. Choosing notebooks follows in a later version.'),
	'queued' => $l->t('Waiting for the next run'),
	'running' => $l->t('Importing'),
	'reauth' => $l->t('Paused: sign in to Microsoft again'),
	'done' => $l->t('Finished'),
	'failed' => $l->t('Stopped'),
	'cancelled' => $l->t('Cancelled'),
	'expired' => $l->t('Expired'),
];
$open = $imp !== null && in_array($imp['status'], ['auth', 'select', 'queued', 'running', 'reauth'], true);
?>
<div id="app-content">
<div id="nextnotes-onenote" class="section"
	data-connect-url="<?php p($_['connectUrl']); ?>"
	data-imports-url="<?php p($_['importsUrl']); ?>"
	data-import-id="<?php p($imp !== null ? (string)$imp['id'] : ''); ?>">
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
		<p>
			<?php if ($imp['status'] === 'reauth') { ?>
				<button type="button" class="primary" data-action="reauth"><?php p($l->t('Sign in to Microsoft again')); ?></button>
			<?php } ?>
			<?php if ($open) { ?>
				<button type="button" data-action="cancel"><?php p($l->t('Cancel import')); ?></button>
			<?php } else { ?>
				<button type="button" class="primary" data-action="connect"><?php p($l->t('Connect to Microsoft')); ?></button>
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
	</div>
</div>
</div>
