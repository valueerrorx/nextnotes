<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Controller;

use OCA\NextNotes\AppInfo\Application;
use OCA\NextNotes\Http\CspFactory;
use OCA\NextNotes\Service\AttachmentService;
use OCA\NextNotes\Service\Exception\NotFoundException;
use OCA\NextNotes\Service\SettingsService;
use OCA\NextNotes\Service\SnapshotService;
use OCA\NextNotes\Service\View\IndexViewService;
use OCA\NextNotes\Service\View\KanbanViewService;
use OCA\NextNotes\Service\View\NotePageViewService;
use OCA\NextNotes\Service\View\PageAssets;
use OCA\NextNotes\Service\View\PageTranslator;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
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
		private IndexViewService $indexView,
		private AttachmentService $attachments,
		private KanbanViewService $kanbanView,
		private NotePageViewService $pageView,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	// Suchfelder der Hauptansicht (Upstream initializeSearchParams, handleUnifiedSearch)
	private const SEARCH_PARAMS = ['search', 'tags_search', 'created_from', 'created_to', 'search_combined', 'unified_search',
		'search_in_notes', 'search_in_tags', 'preserve_notes', 'preserve_tags'];

	// Hauptansicht (Upstream index.php); fremde/unbekannte ?note= → NC-404 ohne Inhalt
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse {
		return $this->render();
	}

	// Such-Formular (POST index.php, Upstream search_handler.php); CSRF über das versteckte Feld requesttoken
	#[NoAdminRequired]
	public function search(): TemplateResponse {
		return $this->render();
	}

	private function render(): TemplateResponse {
		$uid = $this->userSession->getUser()?->getUID() ?? '';
		$lang = $this->language($uid);
		$baseUrl = $this->urls->linkToRoute('nextnotes.page.index');
		if ($this->request->getHeader('X-Poznote-Fragment') === 'right_col') {
			return $this->fragment($uid);
		}
		try {
			$view = $this->indexView->build($uid, $baseUrl, [
				'note' => $this->request->getParam('note'),
				'workspace' => $this->request->getParam('workspace'),
				'folder' => $this->request->getParam('folder'),
				'open_folder' => $this->request->getParam('open_folder'),
			] + array_combine(self::SEARCH_PARAMS, array_map(fn (string $k) => $this->request->getParam($k), self::SEARCH_PARAMS)));
		} catch (NotFoundException) {
			return $this->notFound(TemplateResponse::RENDER_AS_GUEST);
		}
		$this->assets->index($lang);
		$this->provideConfig($uid, $baseUrl, $lang, [
			'hiddenUiElements' => $view['hiddenUiElements']['user'],
			'globalHiddenUiElements' => $view['hiddenUiElements']['forced'],
			'prefetchedSettings' => $view['prefetchedSettings'],
		]);
		return $this->page('index', 'index', [
			'tr' => $this->translator,
			'view' => $view,
			'baseUrl' => $baseUrl,
		]);
	}

	/** InitialState nextnotes/config (SPEC §4.1) für jede Seite */
	private function provideConfig(string $uid, string $baseUrl, string $lang, array $extra = []): void {
		$assetBase = $this->appManager->getAppWebPath(Application::APP_ID);
		$this->initialState->provideInitialState('config', [
			'baseUrl' => $baseUrl,
			'apiBase' => rtrim($baseUrl, '/') . '/api/v1',
			'assetBase' => $assetBase,
			'uid' => $uid,
			'displayName' => $this->userSession->getUser()?->getDisplayName() ?? '',
			'lang' => $lang,
			'teamsAvailable' => false, // M5
			'excalidrawAssetPath' => $assetBase . '/js/vendor/excalidraw-dist/',
			'allowedIframeDomains' => $this->cspFactory->embedDomains(),
		] + $extra);
	}

	private function language(string $uid): string {
		$lang = $this->translator->languageFor($this->settings->read($uid, 'language'));
		$this->translator->setLanguage($lang);
		return $lang;
	}

	private function notFound(string $renderAs): TemplateResponse {
		$response = new TemplateResponse('core', '404', [], $renderAs);
		$response->setStatus(Http::STATUS_NOT_FOUND);
		return $response;
	}

	// Upstream kanban_content.php: ?ajax=1 → nur das Board (utils-kanban.js), sonst Hauptansicht mit ?kanban=
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function kanbanContent(): Response {
		$uid = $this->userSession->getUser()?->getUID() ?? '';
		$this->language($uid);
		try {
			$view = $this->kanbanView->build($uid, $this->request->getParam('folder_id'), $this->request->getParam('workspace'));
		} catch (NotFoundException) {
			return $this->notFound(TemplateResponse::RENDER_AS_BLANK);
		}
		if ($this->request->getParam('ajax') !== '1') {
			$query = ['kanban' => (string)$view['folder']['id']];
			$workspace = $this->request->getParam('workspace');
			if (is_string($workspace) && $workspace !== '') {
				$query['workspace'] = $workspace;
			}
			return new RedirectResponse($this->urls->linkToRoute('nextnotes.page.index') . 'index.php?' . http_build_query($query));
		}
		$response = new TemplateResponse(Application::APP_ID, 'kanban_content', ['tr' => $this->translator, 'view' => $view], TemplateResponse::RENDER_AS_BLANK);
		$response->setContentSecurityPolicy($this->cspFactory->forView('kanban_content'));
		return $response;
	}

	// Upstream revisions.php: Verlauf einer Notiz (Snapshots-API); fremd/unbekannt → 404
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function revisions(): TemplateResponse {
		return $this->notePage('revisions', function (array $page, string $uid): array {
			$keep = (int)$this->settings->read($uid, 'snapshots_keep_count');
			$keep = $keep < 1 || $keep > SnapshotService::DEFAULT_KEEP_DAYS ? SnapshotService::DEFAULT_KEEP_DAYS : $keep;
			return ['config' => [
				'noteId' => $page['note']['id'],
				'noteType' => $page['note']['type'],
				'noteHeading' => $page['note']['heading'] !== '' ? $page['note']['heading'] : $this->translator->t('note_reference.untitled', [], 'Untitled'),
				'workspace' => $page['workspace'],
				'backUrl' => $page['backUrl'],
				'lang' => $this->translator->language(),
				'today' => $page['today'],
				'yesterday' => $page['yesterday'],
				'initialRevision' => is_string($this->request->getParam('revision')) ? $this->request->getParam('revision') : '',
			], 'keepCount' => $keep];
		});
	}

	// Upstream attachments.php: Anhänge einer Notiz verwalten (Anhang-API); fremd/unbekannt → 404
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function attachments(): TemplateResponse {
		return $this->notePage('attachments', function (array $page, string $uid): array {
			$format = $this->settings->read($uid, 'date_time_format');
			return ['poznoteConfig' => ['dateTimeFormat' => preg_match('/^[A-Za-z0-9_:.-]{1,40}$/', $format) ? $format : 'default', 'speechToText' => false]];
		});
	}

	/** Gemeinsamer Rahmen der Notiz-Unterseiten: Leserecht, Workspace-Abgleich, Assets, Konfiguration */
	private function notePage(string $page, callable $data): TemplateResponse {
		$uid = $this->userSession->getUser()?->getUID() ?? '';
		$lang = $this->language($uid);
		$baseUrl = $this->urls->linkToRoute('nextnotes.page.index');
		try {
			$info = $this->pageView->note($uid, $this->request->getParam('note_id'), $this->request->getParam('workspace'), $baseUrl);
		} catch (NotFoundException) {
			return $this->notFound(TemplateResponse::RENDER_AS_GUEST);
		}
		$this->assets->page($page, $lang);
		$this->provideConfig($uid, $baseUrl, $lang);
		return $this->page($page, $page, ['tr' => $this->translator, 'page' => $info] + $data($info, $uid));
	}

	// Fragment-Modus (note-loader.js): nur #right_col ohne Seitenrahmen; Zugriff wie die volle Seite
	private function fragment(string $uid): TemplateResponse {
		try {
			$view = $this->indexView->fragment($uid, $this->request->getParam('note'));
		} catch (NotFoundException) {
			$response = new TemplateResponse('core', '404', [], TemplateResponse::RENDER_AS_BLANK);
			$response->setStatus(Http::STATUS_NOT_FOUND);
			return $response;
		}
		$response = new TemplateResponse(Application::APP_ID, 'fragment', ['tr' => $this->translator, 'note' => $view['note']], TemplateResponse::RENDER_AS_BLANK);
		$response->setContentSecurityPolicy($this->cspFactory->forView('index'));
		return $response;
	}

	// Upstream audio_player.php: nur <audio> für den Anhang; fremde/unbekannte IDs → 404 (Leserecht wie Download)
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function audioPlayer(): TemplateResponse {
		$note = $this->request->getParam('note');
		$attachment = $this->request->getParam('attachment');
		$workspace = $this->request->getParam('workspace');
		try {
			if (!is_string($note) || !is_string($attachment) || !ctype_digit($note) || !ctype_digit($attachment)) {
				throw new NotFoundException('Attachment not found');
			}
			$this->attachments->download($this->userSession->getUser()?->getUID() ?? '', (int)$note, (int)$attachment, $workspace);
		} catch (NotFoundException) {
			$response = new TemplateResponse('core', '404', [], TemplateResponse::RENDER_AS_BLANK);
			$response->setStatus(Http::STATUS_NOT_FOUND);
			return $response;
		}
		$src = rtrim($this->urls->linkToRoute('nextnotes.page.index'), '/') . '/api/v1/notes/' . (int)$note . '/attachments/' . (int)$attachment
			. (is_string($workspace) && $workspace !== '' ? '?' . http_build_query(['workspace' => $workspace]) : '');
		$response = new TemplateResponse(Application::APP_ID, 'audio_player', ['src' => $src], TemplateResponse::RENDER_AS_BLANK);
		$response->setContentSecurityPolicy($this->cspFactory->forView('audio_player'));
		return $response;
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
