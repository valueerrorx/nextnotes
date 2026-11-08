<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service\View;

use OCA\NextNotes\Service\NotePalette;
use OCA\NextNotes\Db\Workspace;
use OCA\NextNotes\Service\AccessService;
use OCA\NextNotes\Service\Exception\NotFoundException;
use OCA\NextNotes\Service\SettingsService;
use OCA\NextNotes\Service\WorkspaceService;

/** Rahmen der Listen-Seiten (M4e: trash, list_tags, list_folders, attachments_list, notes_manager, create) */
class PageFrameService {
	public function __construct(
		private AccessService $access,
		private WorkspaceService $workspaces,
		private SettingsService $settings,
		private NotePalette $palette,
	) {
	}

	// Upstream getWorkspaceFilter: ?workspace=, sonst Standard-, sonst zuletzt geöffneter; unbekannt/fremd → Standard (SPEC §4.4)
	public function workspace(string $uid, string $requested): Workspace {
		$candidates = [$requested];
		$default = $this->settings->get($uid, 'default_workspace', '');
		if (!in_array($default, ['', '__last_opened__', '__last_opened_device__'], true)) {
			$candidates[] = $default;
		}
		$candidates[] = $this->settings->get($uid, 'last_opened_workspace', '');
		foreach ($candidates as $i => $candidate) {
			if ($candidate === '' || $candidate === '__last_opened__') {
				continue;
			}
			try {
				$ws = $this->workspaces->resolveKey($uid, $candidate);
			} catch (NotFoundException) {
				continue;
			}
			// Schüler-Workspace nur ausdrücklich angefragt, nie als Startseite (Q3)
			if ($i === 0 || $ws->getKind() === 'normal') {
				return $ws;
			}
		}
		return $this->workspaces->defaultWorkspace($uid);
	}

	/**
	 * @return array{workspace: Workspace, wsId: int, workspaceKey: string, workspaceColor: string, canWrite: bool, replaceWorkspace: ?string, dateTimeFormat: string}
	 */
	public function frame(string $uid, mixed $requested): array {
		$requested = is_string($requested) ? trim($requested) : '';
		$ws = $this->workspace($uid, $requested);
		$key = (string)$this->workspaces->keyFor($uid, (int)$ws->getId());
		$format = $this->settings->get($uid, 'date_time_format', 'default');
		return [
			'workspace' => $ws,
			'wsId' => (int)$ws->getId(),
			'workspaceKey' => $key,
			'workspaceColor' => $this->palette->hex($ws->getColor(), NotePalette::ownerOf($ws)),
			'canWrite' => $this->access->workspaceLevel($uid, $ws) >= AccessService::WRITE,
			'replaceWorkspace' => $requested !== $key ? $key : null,
			'dateTimeFormat' => preg_match('/^[A-Za-z0-9_:.-]{1,40}$/', $format) ? $format : 'default',
		];
	}
}
