<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Controller;

use OCA\NextNotes\AppInfo\Application;
use OCA\NextNotes\Service\EmbedDomainService;
use OCA\NextNotes\Service\Exception\BadRequestException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;

// Admin-Formular (Settings\AdminSettings); ohne NoAdminRequired → nur Admins, CSRF aktiv (SPEC §4.5)
class AdminSettingsController extends Controller {
	/** Schlüssel → [min, max] in MiB */
	public const LIMITS = [
		'attachment_max_file_mb' => [1, 2048],
		'attachment_quota_mb' => [1, 1048576],
	];

	public function __construct(
		IRequest $request,
		private IAppConfig $appConfig,
		private EmbedDomainService $embedDomains,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	// alle übergebenen Werte prüfen; ein Fehler → 400 mit Liste, nichts gespeichert
	public function save(): JSONResponse {
		$errors = [];
		$ints = [];
		foreach (self::LIMITS as $key => [$min, $max]) {
			$raw = $this->request->getParam($key);
			if ($raw === null) {
				continue;
			}
			$value = is_int($raw) ? $raw : (is_string($raw) && preg_match('/^\s*\d{1,8}\s*$/', $raw) ? (int)$raw : null);
			if ($value === null || $value < $min || $value > $max) {
				$errors[] = ['field' => $key, 'entry' => is_scalar($raw) ? mb_substr((string)$raw, 0, 50) : '', 'error' => 'range'];
				continue;
			}
			$ints[$key] = $value;
		}
		$domains = null;
		$raw = $this->request->getParam(EmbedDomainService::KEY);
		if ($raw !== null) {
			$list = is_array($raw) ? array_values($raw) : preg_split('/[\s,]+/', (string)$raw);
			[$domains, $bad] = $this->embedDomains->normalizeList($list);
			foreach ($bad as $b) {
				$errors[] = ['field' => EmbedDomainService::KEY] + $b;
			}
			if (count($domains) > EmbedDomainService::MAX_ENTRIES) {
				$errors[] = ['field' => EmbedDomainService::KEY, 'entry' => '', 'error' => 'too_many'];
			}
		}
		if ($errors !== []) {
			throw new BadRequestException('Invalid settings', ['errors' => $errors]);
		}
		foreach ($ints as $key => $value) {
			$this->appConfig->setValueInt(Application::APP_ID, $key, $value);
		}
		if ($domains !== null) {
			$domains = $this->embedDomains->save($domains);
		}
		return new JSONResponse(['success' => true, EmbedDomainService::KEY => $domains ?? $this->embedDomains->domains()] + $ints);
	}
}
