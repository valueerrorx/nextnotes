<?php
/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// eigene Vorlage: Admin-Formular Next Notes (Settings\AdminSettings); ohne Inline-Skript, Logik in js/nextnotes-admin.js

/** @var array $_ */
/** @var \OCP\IL10N $l */
$messages = [
	'empty' => $l->t('empty entry'),
	'wildcard' => $l->t('wildcards are not allowed, enter each host exactly'),
	'scheme' => $l->t('only http and https addresses are allowed'),
	'userinfo' => $l->t('user names in addresses are not allowed'),
	'port' => $l->t('ports are not allowed'),
	'ip' => $l->t('IP addresses are not allowed'),
	'single_label' => $l->t('a host needs at least one dot (e.g. example.org)'),
	'too_long' => $l->t('host name is too long'),
	'ascii_only' => $l->t('only ASCII host names are supported on this server'),
	'invalid' => $l->t('invalid host name'),
	'too_many' => $l->t('at most %s entries', [(string)$_['maxDomains']]),
	'range' => $l->t('value out of range'),
	'onenote_authority' => $l->t('enter a tenant ID, a domain, organizations or common'),
	'onenote_client_id' => $l->t('the client ID is a GUID like 00000000-0000-0000-0000-000000000000'),
	'onenote_secret' => $l->t('invalid client secret'),
	'secret_set' => $l->t('A client secret is stored.'),
	'secret_unset' => $l->t('No client secret stored.'),
	'saved' => $l->t('Saved'),
	'failed' => $l->t('Could not save the settings'),
];
?>
<div id="nextnotes-admin" class="section" data-save-url="<?php p($_['saveUrl']); ?>">
	<h2><?php p($l->t('Next Notes')); ?></h2>

	<h3><label for="nextnotes-embed-domains"><?php p($l->t('Allowed embed domains')); ?></label></h3>
	<p class="settings-hint"><?php p($l->t('One host per line, e.g. www.youtube-nocookie.com. Only exactly these hosts are allowed, subdomains must be entered separately. Addresses like https://www.youtube.com/embed/… are reduced to the host.')); ?></p>
	<p class="settings-hint"><?php p($l->t('The list is empty by default. Embedded videos load content from third parties that can track viewers; prefer privacy-friendly hosts such as youtube-nocookie.com.')); ?></p>
	<p class="settings-hint"><strong><?php p($l->t('Embedded frames from hosts that are not on this list are removed permanently when a note is saved.')); ?></strong></p>
	<textarea id="nextnotes-embed-domains" name="allowed_iframe_domains" rows="6" cols="50" spellcheck="false"><?php p(implode("\n", $_['domains'])); ?></textarea>

	<h3><?php p($l->t('Attachments')); ?></h3>
	<p>
		<label for="nextnotes-max-file"><?php p($l->t('Maximum file size (MiB)')); ?></label>
		<input id="nextnotes-max-file" name="attachment_max_file_mb" type="number" required
			min="<?php p((string)$_['limits']['attachment_max_file_mb'][0]); ?>" max="<?php p((string)$_['limits']['attachment_max_file_mb'][1]); ?>"
			value="<?php p((string)$_['maxFileMb']); ?>">
	</p>
	<p>
		<label for="nextnotes-quota"><?php p($l->t('Attachment quota per user (MiB)')); ?></label>
		<input id="nextnotes-quota" name="attachment_quota_mb" type="number" required
			min="<?php p((string)$_['limits']['attachment_quota_mb'][0]); ?>" max="<?php p((string)$_['limits']['attachment_quota_mb'][1]); ?>"
			value="<?php p((string)$_['quotaMb']); ?>">
	</p>
	<p class="settings-hint"><?php p($l->t('Exports are limited to 1 GiB. Larger attachments can only be downloaded individually, not as part of an export.')); ?></p>

	<?php $on = $_['oneNote']; $common = $on['authority'] === 'common'; ?>
	<h3><?php p($l->t('OneNote import')); ?></h3>
	<p class="settings-hint"><?php p($l->t('Lets users import their OneNote notebooks through Microsoft Graph. Register an app (platform Web) in the Microsoft Entra admin center of your school and enter its values here. Without client ID and secret the import is off.')); ?></p>
	<p>
		<label for="nextnotes-onenote-redirect"><?php p($l->t('Redirect URI for the app registration')); ?></label>
		<input id="nextnotes-onenote-redirect" type="text" readonly size="60" value="<?php p($on['redirectUri']); ?>">
	</p>
	<p>
		<label for="nextnotes-onenote-authority"><?php p($l->t('Authority (tenant ID, organizations or common)')); ?></label>
		<input id="nextnotes-onenote-authority" name="onenote_authority" type="text" size="40" spellcheck="false" value="<?php p($on['authority']); ?>">
	</p>
	<p>
		<label for="nextnotes-onenote-client"><?php p($l->t('Application (client) ID')); ?></label>
		<input id="nextnotes-onenote-client" name="onenote_client_id" type="text" size="40" spellcheck="false" autocomplete="off" value="<?php p($on['clientId']); ?>">
	</p>
	<p>
		<label for="nextnotes-onenote-secret"><?php p($l->t('Client secret')); ?></label>
		<input id="nextnotes-onenote-secret" name="onenote_secret" type="password" size="40" autocomplete="new-password" value="" placeholder="<?php p($on['secretSet'] ? $l->t('unchanged') : ''); ?>">
		<span id="nextnotes-onenote-secret-state" data-set="<?php p($on['secretSet'] ? '1' : '0'); ?>"><?php p($on['secretSet'] ? $l->t('A client secret is stored.') : $l->t('No client secret stored.')); ?></span>
	</p>
	<p>
		<input id="nextnotes-onenote-secret-clear" type="checkbox" class="checkbox">
		<label for="nextnotes-onenote-secret-clear"><?php p($l->t('Remove the stored client secret')); ?></label>
	</p>
	<p>
		<input id="nextnotes-onenote-teams" name="onenote_teams" type="checkbox" class="checkbox"<?php if ($on['teams']) { ?> checked<?php } ?><?php if ($common) { ?> disabled<?php } ?>>
		<label for="nextnotes-onenote-teams"><?php p($l->t('Allow team and class notebooks')); ?></label>
	</p>
	<p id="nextnotes-onenote-teams-hint" class="settings-hint"<?php if (!$common) { ?> hidden<?php } ?>><?php p($l->t('Team and class notebooks are only available with an authority for organization accounts (tenant ID or organizations).')); ?></p>

	<p>
		<button id="nextnotes-admin-save" type="button" class="primary"><?php p($l->t('Save')); ?></button>
		<span id="nextnotes-admin-status" role="status"></span>
	</p>
	<ul id="nextnotes-admin-errors" class="nextnotes-admin-errors" role="alert"></ul>

	<div id="nextnotes-admin-messages" hidden>
		<?php foreach ($messages as $code => $text) { ?>
			<span data-code="<?php p($code); ?>"><?php p($text); ?></span>
		<?php } ?>
	</div>
</div>
