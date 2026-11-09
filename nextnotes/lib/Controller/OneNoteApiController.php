<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Controller;

use OCA\NextNotes\AppInfo\Application;
use OCA\NextNotes\Http\CspFactory;
use OCA\NextNotes\OneNote\ConnectService;
use OCA\NextNotes\OneNote\OneNoteMessages;
use OCA\NextNotes\Service\Exception\BadRequestException;
use OCA\NextNotes\Service\WorkspaceService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Util;

// OneNote-Import (Plan M9 §2 Controller, ADR 0020): Anmeldung, Callback, Anzeige, Abbruch; Rechte nur über die uid der Zeile
class OneNoteApiController extends ApiController {
	public function __construct(IRequest $request, IUserSession $userSession, WorkspaceService $workspaces,
		private ConnectService $connect, private IURLGenerator $urls, private CspFactory $csp, private IL10N $l) {
		parent::__construct($request, $userSession, $workspaces);
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 10, period: 60)]
	public function connect(): JSONResponse {
		$raw = $this->param('import_id');
		$id = null;
		if ($raw !== null && $raw !== '') {
			if (!is_int($raw) && !(is_string($raw) && ctype_digit($raw))) {
				throw new BadRequestException('Invalid import_id');
			}
			$id = (int)$raw;
		}
		return $this->ok($this->connect->connect($this->uid(), $id));
	}

	#[NoAdminRequired]
	public function index(): JSONResponse {
		return $this->ok($this->connect->index($this->uid()));
	}

	#[NoAdminRequired]
	public function show(int $id): JSONResponse {
		return $this->ok($this->connect->show($this->uid(), $id));
	}

	#[NoAdminRequired]
	public function cancel(int $id): JSONResponse {
		return $this->ok($this->connect->cancel($this->uid(), $id));
	}

	// Rücksprung von Microsoft (Top-Level-GET ohne CSRF-Token): state schützt, gebunden an die uid (§4.2, §6 CSRF)
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function callback(): RedirectResponse|TemplateResponse {
		$result = $this->connect->callback($this->uid(), self::str($this->param('state')), self::str($this->param('code')), self::str($this->param('error')));
		if ($result->query === null) {
			$response = new TemplateResponse('core', '404', [], TemplateResponse::RENDER_AS_GUEST);
			$response->setStatus(Http::STATUS_NOT_FOUND);
			return $response;
		}
		return new RedirectResponse($this->urls->linkToRoute('nextnotes.one_note_api.page') . '?' . http_build_query($result->query));
	}

	// Ansicht onenote.php (ADR 0004); M9a: Status und Anmeldung, Auswahl folgt in M9b
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function page(): TemplateResponse {
		$uid = $this->uid();
		$data = $this->connect->index($uid);
		$current = null;
		$want = $this->param('import');
		foreach ($data['imports'] as $row) {
			if ((is_string($want) && ctype_digit($want) && (int)$want === $row['id']) || ($want === null && $current === null && in_array($row['status'], ['auth', 'select', 'queued', 'running', 'reauth'], true))) {
				$current = $row;
			}
		}
		$msg = self::str($this->param('msg'));
		$messages = [];
		foreach (OneNoteMessages::CODES as $code) {
			$messages[$code] = OneNoteMessages::forError($code, $this->l);
		}
		Util::addScript(Application::APP_ID, 'nextnotes-onenote');
		$response = new TemplateResponse(Application::APP_ID, 'onenote', [
			'configured' => $data['configured'],
			'import' => $current,
			'message' => in_array($msg, OneNoteMessages::CODES, true) ? $messages[$msg] : '',
			'messages' => $messages,
			'connectUrl' => $this->urls->linkToRoute('nextnotes.one_note_api.connect'),
			'importsUrl' => $this->urls->linkToRoute('nextnotes.one_note_api.index'),
			'appUrl' => $this->urls->linkToRoute('nextnotes.page.index'),
		], TemplateResponse::RENDER_AS_USER);
		$response->setContentSecurityPolicy($this->csp->forView('onenote'));
		return $response;
	}

	private static function str(mixed $v): string {
		return is_string($v) ? $v : '';
	}
}
