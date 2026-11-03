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
