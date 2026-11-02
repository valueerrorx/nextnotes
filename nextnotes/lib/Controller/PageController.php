<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Controller;

use OCA\NextNotes\AppInfo\Application;
use OCA\NextNotes\Http\CspFactory;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;

class PageController extends Controller {
	public function __construct(
		IRequest $request,
		private CspFactory $cspFactory,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse {
		return $this->page();
	}

	// Spike ADR 0004, noch ohne Inhalt
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function dashboard(): TemplateResponse {
		return $this->page();
	}

	private function page(): TemplateResponse {
		$response = new TemplateResponse(Application::APP_ID, 'main', [], TemplateResponse::RENDER_AS_USER);
		$response->setContentSecurityPolicy($this->cspFactory->create());
		return $response;
	}
}
