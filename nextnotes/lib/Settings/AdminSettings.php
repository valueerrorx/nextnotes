<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Settings;

use OCA\NextNotes\AppInfo\Application;
use OCA\NextNotes\Controller\AdminSettingsController;
use OCA\NextNotes\OneNote\OneNoteConfig;
use OCA\NextNotes\Service\AttachmentService;
use OCA\NextNotes\Service\EmbedDomainService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;
use OCP\Util;

// Admin-Formular: Embed-Domains, Anhangsgrenzen (SPEC §1.4, M4-Plan M4a), OneNote-Import (Plan M9 §2)
class AdminSettings implements ISettings {
	public function __construct(
		private IAppConfig $appConfig,
		private EmbedDomainService $embedDomains,
		private IURLGenerator $urls,
		private OneNoteConfig $oneNote,
	) {
	}

	public function getForm(): TemplateResponse {
		Util::addScript(Application::APP_ID, 'nextnotes-admin');
		return new TemplateResponse(Application::APP_ID, 'settings/admin', [
			'saveUrl' => $this->urls->linkToRoute('nextnotes.admin_settings.save'),
			'domains' => $this->embedDomains->domains(),
			'maxFileMb' => $this->appConfig->getValueInt(Application::APP_ID, 'attachment_max_file_mb', AttachmentService::DEFAULT_MAX_FILE_MB),
			'quotaMb' => $this->appConfig->getValueInt(Application::APP_ID, 'attachment_quota_mb', AttachmentService::DEFAULT_QUOTA_MB),
			'limits' => AdminSettingsController::LIMITS,
			'maxDomains' => EmbedDomainService::MAX_ENTRIES,
			// Secret nie ins HTML, nur gesetzt/nicht gesetzt (Plan M9 §2 Admin)
			'oneNote' => [
				'authority' => $this->oneNote->authority(),
				'clientId' => $this->oneNote->clientId(),
				'secretSet' => $this->oneNote->hasSecret(),
				'teams' => $this->oneNote->teamsSwitch(),
				'redirectUri' => $this->oneNote->redirectUri(),
			],
		]);
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 50;
	}
}
