<?php
/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// abgeleitet von src/public/storage-stats-user.php @ 7e2e0b6aa501ba95ac1e9789fd2bb477c281a001
// Fragment für Einstellungen → Speicher (settings-page.js loadStorageSummary). Zeilen: Notizen (eigene Workspaces) und Anhänge
// gegen die Admin-Quote attachment_quota_mb; ohne Datenbank-/Dateigrößen, S3, Backups (gestrichen). Daten: SettingsViewService::storage.
/** @var array $_ */
/** @var \OCA\NextNotes\Service\View\PageTranslator $tr */
$tr = $_['tr'];
$stats = $_['stats'];
$t = static fn (string $key, string $default, array $vars = []) => $tr->t($key, $vars, $default);
$query = $_['workspace'] !== '' ? '?' . http_build_query(['workspace' => $_['workspace']]) : '';
$mb = static fn (int $bytes): string => number_format($bytes / 1048576, 2) . "\u{00a0}" . $t('admin_tools.storage_stats.quota_unit_mb', 'MB');
$used = $stats['attachmentBytes'];
$limit = $stats['quotaBytes'];
$percent = $limit > 0 ? min(100, max(0, $used / $limit * 100)) : 0.0;
$level = $limit <= 0 ? '' : ($used / $limit >= 0.8 ? 'quota-level-danger' : ($used / $limit >= 0.5 ? 'quota-level-warn' : ''));
$rows = [
	[
		'icon' => 'lucide-sticky-note',
		'href' => $_['baseUrl'] . 'notes_manager.php' . $query,
		'label' => $t('admin_tools.storage_stats.table_notes', 'Notes'),
		'detail' => $t('settings.storage_summary.notes_detail', '{{active}} in your notes, {{trash}} in the trash', ['active' => number_format($stats['notesActive']), 'trash' => number_format($stats['notesTrash'])]),
		'value' => number_format($stats['notesActive'] + $stats['notesTrash']),
		'limit' => null,
		'level' => '',
	],
	[
		'icon' => 'lucide-paperclip',
		'href' => $_['baseUrl'] . 'attachments_list.php' . $query,
		'label' => $t('settings.storage_summary.attachments', 'Attachments'),
		'detail' => '',
		'value' => $mb($used),
		'limit' => $limit > 0 ? $mb($limit) : null,
		'level' => $level,
	],
];
?>
<?php foreach ($rows as $row): ?>
<a class="settings-storage-row <?php p($row['level']); ?>" href="<?php p($row['href']); ?>">
	<i class="lucide <?php p($row['icon']); ?> settings-storage-icon"></i>
	<span class="settings-storage-text">
		<span class="settings-storage-label"><?php p($row['label']); ?></span>
		<?php if ($row['detail'] !== ''): ?>
		<span class="settings-storage-detail"><?php p($row['detail']); ?></span>
		<?php endif; ?>
	</span>
	<span class="settings-storage-figure">
		<span class="settings-storage-value"><?php p($row['value']); ?><?php if ($row['limit'] !== null): ?><span class="settings-storage-limit"> / <?php p($row['limit']); ?></span><?php endif; ?></span>
		<?php if ($row['limit'] === null): ?>
		<span class="settings-storage-meta"><?php p($t('admin_tools.storage_stats.quota_unlimited', 'Unlimited')); ?></span>
		<?php else: ?>
		<span class="settings-storage-bar"><span style="width: <?php p((string)($percent > 0 ? max(2, (int)round($percent)) : 0)); ?>%;"></span></span>
		<span class="settings-storage-meta"><?php p($t('admin_tools.storage_stats.quota_percent_used', '{{percent}}% used', ['percent' => $percent > 0 && $percent < 1 ? '<1' : (string)round($percent)])); ?></span>
		<?php endif; ?>
	</span>
</a>
<?php endforeach; ?>
