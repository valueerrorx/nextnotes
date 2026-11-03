<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Controller;

use OCA\NextNotes\AppInfo\Application;
use OCA\NextNotes\Http\CspFactory;
use OCA\NextNotes\Service\SettingsService;
use OCA\NextNotes\Service\View\PageAssets;
use OCA\NextNotes\Service\View\PageTranslator;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

class PageController extends Controller {
	public function __construct(
		IRequest $request,
		private CspFactory $cspFactory,
		private PageAssets $assets,
		private PageTranslator $translator,
		private IInitialState $initialState,
		private IUserSession $userSession,
		private IURLGenerator $urls,
		private IAppManager $appManager,
		private SettingsService $settings,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	// M3a1: leere Seite mit allen Assets der Hauptansicht; Inhalt folgt in M3a2
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse {
		$user = $this->userSession->getUser();
		$uid = $user?->getUID() ?? '';
		$lang = $this->translator->languageFor($this->settings->read($uid, 'language'));
		$this->translator->setLanguage($lang);
		$this->assets->index($lang);
		$baseUrl = $this->urls->linkToRoute('nextnotes.page.index');
		$assetBase = $this->appManager->getAppWebPath(Application::APP_ID);
		$this->initialState->provideInitialState('config', [
			'baseUrl' => $baseUrl,
			'apiBase' => rtrim($baseUrl, '/') . '/api/v1',
			'assetBase' => $assetBase,
			'uid' => $uid,
			'displayName' => $user?->getDisplayName() ?? '',
			'lang' => $lang,
			'teamsAvailable' => false, // M5
			'excalidrawAssetPath' => $assetBase . '/js/vendor/excalidraw-dist/',
			'allowedIframeDomains' => $this->cspFactory->embedDomains(),
			'hiddenUiElements' => [],
		]);
		return $this->page('main', 'index', ['tr' => $this->translator]);
	}

	// Spike ADR 0004, leere Seite bis M4
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function dashboard(): TemplateResponse {
		return $this->page('main', 'dashboard');
	}

	// Upstream-Login (SPEC §4.2): angemeldet zur App; abgemeldet leitet NC selbst auf den Login mit Rücksprung
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function login(): RedirectResponse {
		return new RedirectResponse($this->urls->linkToRoute('nextnotes.page.index'));
	}

	// Abmelden nur über das NC-Menü: eine Weiterleitung auf die Logout-URL mit Token hebelte deren CSRF-Schutz aus
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function logout(): RedirectResponse {
		return new RedirectResponse($this->urls->linkToRoute('nextnotes.page.index'));
	}

	/** @param array<string, mixed> $params */
	private function page(string $template, string $view, array $params = []): TemplateResponse {
		$response = new TemplateResponse(Application::APP_ID, $template, $params, TemplateResponse::RENDER_AS_USER);
		$response->setContentSecurityPolicy($this->cspFactory->forView($view));
		return $response;
	}
}
