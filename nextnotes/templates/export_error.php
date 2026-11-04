<?php
/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// eigene Vorlage: Fehlerseite für Export-Links (Navigation statt fetch), Aufbau wie core/templates/429.php

/** @var array $_ */
/** @var \OCP\IL10N $l */
?>
<div class="body-login-container update" id="nextnotes-export-error" data-status="<?php p((string)$_['status']); ?>">
	<h2><?php p($l->t('Export not possible')); ?></h2>
	<p class="infogroup"><?php p($_['message']); ?></p>
	<p><a class="button primary" href="<?php p($_['backUrl']); ?>"><?php p($l->t('Back to Next Notes')); ?></a></p>
</div>
