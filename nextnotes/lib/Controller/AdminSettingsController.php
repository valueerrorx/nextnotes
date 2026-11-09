<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Controller;

use OCA\NextNotes\AppInfo\Application;
use OCA\NextNotes\OneNote\ConnectService;
use OCA\NextNotes\OneNote\OneNoteConfig;
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
		private OneNoteConfig $oneNote,
		private ConnectService $oneNoteImports,
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
		[$oneNote, $oneNoteErrors] = $this->oneNoteInput();
		$errors = array_merge($errors, $oneNoteErrors);
		if ($errors !== []) {
			throw new BadRequestException('Invalid settings', ['errors' => $errors]);
		}
		foreach ($ints as $key => $value) {
			$this->appConfig->setValueInt(Application::APP_ID, $key, $value);
		}
		if ($domains !== null) {
			$domains = $this->embedDomains->save($domains);
		}
		$this->saveOneNote($oneNote);
		return new JSONResponse(['success' => true, EmbedDomainService::KEY => $domains ?? $this->embedDomains->domains()] + $ints + $this->oneNoteState());
	}

	/**
	 * OneNote-Import (Plan M9 §2 Admin): Authority, Client-ID (GUID), Secret nur schreibbar, Team-Schalter
	 * @return array{0: array<string, mixed>, 1: list<array<string, string>>}
	 */
	private function oneNoteInput(): array {
		$out = [];
		$errors = [];
		$raw = $this->request->getParam('onenote_authority');
		if ($raw !== null) {
			$v = is_string($raw) ? OneNoteConfig::normalizeAuthority($raw) : null;
			if ($v === null) {
				$errors[] = ['field' => 'onenote_authority', 'entry' => is_scalar($raw) ? mb_substr((string)$raw, 0, 50) : '', 'error' => 'onenote_authority'];
			} else {
				$out['authority'] = $v;
			}
		}
		$raw = $this->request->getParam('onenote_client_id');
		if ($raw !== null) {
			$v = is_string($raw) ? OneNoteConfig::normalizeClientId($raw) : null;
			if ($v === null) {
				$errors[] = ['field' => 'onenote_client_id', 'entry' => is_scalar($raw) ? mb_substr((string)$raw, 0, 50) : '', 'error' => 'onenote_client_id'];
			} else {
				$out['clientId'] = $v;
			}
		}
		// Secret: leer = unverändert, Löschen nur ausdrücklich; nie im Fehlertext
		$raw = $this->request->getParam('onenote_secret');
		if (is_string($raw) && trim($raw) !== '') {
			if (strlen($raw) > 1024 || preg_match('/[\x00-\x1f\x7f]/', $raw) === 1) {
				$errors[] = ['field' => 'onenote_secret', 'entry' => '', 'error' => 'onenote_secret'];
			} else {
				$out['secret'] = trim($raw);
			}
		} elseif ($raw !== null && !is_string($raw)) {
			$errors[] = ['field' => 'onenote_secret', 'entry' => '', 'error' => 'onenote_secret'];
		}
		if (self::flag($this->request->getParam('onenote_secret_clear')) === true) {
			$out['secret'] = '';
		}
		$teams = self::flag($this->request->getParam('onenote_teams'));
		if ($teams !== null) {
			$out['teams'] = $teams;
		}
		return [$out, $errors];
	}

	private static function flag(mixed $raw): ?bool {
		return match (true) {
			$raw === true, $raw === 1, $raw === '1', $raw === 'true' => true,
			$raw === false, $raw === 0, $raw === '0', $raw === 'false' => false,
			default => null,
		};
	}

	/** @param array<string, mixed> $in */
	private function saveOneNote(array $in): void {
		if ($in === []) {
			return;
		}
		$before = [$this->oneNote->clientId(), $this->oneNote->authority(), $this->oneNote->teamsEffective()];
		$this->oneNote->save($in['authority'] ?? null, $in['clientId'] ?? null, $in['secret'] ?? null, $in['teams'] ?? null);
		// Wechsel von Client-ID, Authority oder Team-Freigabe beendet betroffene Importe (§4.7); neues Secret ändert nichts
		if ($before !== [$this->oneNote->clientId(), $this->oneNote->authority(), $this->oneNote->teamsEffective()]) {
			$this->oneNoteImports->reconcile(null);
		}
	}

	/** @return array<string, mixed> Zustand für das Formular; das Secret nur als gesetzt/nicht gesetzt */
	private function oneNoteState(): array {
		return [
			'onenote_authority' => $this->oneNote->authority(),
			'onenote_client_id' => $this->oneNote->clientId(),
			'onenote_secret_set' => $this->oneNote->hasSecret(),
			'onenote_teams' => $this->oneNote->teamsSwitch(),
			'onenote_teams_effective' => $this->oneNote->teamsEffective(),
		];
	}
}
