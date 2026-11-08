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
use OCA\NextNotes\Service\BackgroundService;
use OCA\NextNotes\Service\EmbedDomainService;
use OCA\NextNotes\Service\ExcalidrawService;
use OCA\NextNotes\Service\MentionService;
use OCA\NextNotes\Service\Exception\NotFoundException;
use OCA\NextNotes\Service\SettingsService;
use OCA\NextNotes\Service\SnapshotService;
use OCA\NextNotes\Service\View\AssignmentsViewService;
use OCA\NextNotes\Service\View\DashboardViewService;
use OCA\NextNotes\Service\View\DiaryViewService;
use OCA\NextNotes\Service\View\IndexViewService;
use OCA\NextNotes\Service\View\KanbanViewService;
use OCA\NextNotes\Service\View\FolderListViewService;
use OCA\NextNotes\Service\View\NotePageViewService;
use OCA\NextNotes\Service\View\PageAssets;
use OCA\NextNotes\Service\View\PageFrameService;
use OCA\NextNotes\Service\View\PageTranslator;
use OCA\NextNotes\Service\View\SettingsViewService;
use OCA\NextNotes\Service\TeamService;
use OCA\NextNotes\Service\View\TagListViewService;
use OCA\NextNotes\Service\View\TeamsUiConfig;
use OCA\NextNotes\Service\View\TrashViewService;
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
		private ExcalidrawService $excalidraw,
		private EmbedDomainService $embedDomains,
		private PageFrameService $pageFrame,
		private TrashViewService $trashView,
		private TagListViewService $tagListView,
		private FolderListViewService $folderListView,
		private DiaryViewService $diaryView,
		private BackgroundService $backgrounds,
		private DashboardViewService $dashboardView,
		private MentionService $mentions,
		private SettingsViewService $settingsView,
		private TeamService $teams,
		private TeamsUiConfig $teamsUi,
		private AssignmentsViewService $assignmentsView,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	// Suchfelder der Hauptansicht (Upstream initializeSearchParams, handleUnifiedSearch)
	private const SEARCH_PARAMS = ['search', 'tags_search', 'created_from', 'created_to', 'search_combined', 'unified_search',
		'search_in_notes', 'search_in_tags', 'preserve_notes', 'preserve_tags'];

	// Hauptansicht (Upstream index.php); fremde/unbekannte ?note= → NC-404 ohne Inhalt; App-Start `/` ohne Parameter → Dashboard (UI-rail)
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): Response {
		if ($this->request->getParam('start') === '1' && $this->request->getHeader('X-Poznote-Fragment') === ''
			&& array_diff(array_keys($this->request->getParams()), ['_route', 'start']) === []) {
			return new RedirectResponse($this->urls->linkToRoute('nextnotes.page.dashboard'));
		}
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
		$fragment = $this->request->getHeader('X-Poznote-Fragment');
		if ($fragment === 'right_col') {
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
			return $fragment === 'left_col' ? $this->blankNotFound() : $this->notFound(TemplateResponse::RENDER_AS_GUEST);
		}
		if ($fragment === 'left_col') {
			// Baum-Neuaufbau nach Ordner-Aktionen (P18): nur #left_col, gleiche Daten und Rechte wie die Seite
			$response = new TemplateResponse(Application::APP_ID, 'fragment_left', ['tr' => $this->translator, 'view' => $view, 'baseUrl' => $baseUrl], TemplateResponse::RENDER_AS_BLANK);
			$response->setContentSecurityPolicy($this->cspFactory->forView('index'));
			return $response;
		}
		$this->assets->index($lang);
		// Hintergrundbild und Deckkraft mit der Seite (background-settings.js, kein zusätzlicher Request beim Laden)
		$opacityKey = 'background_opacity_' . $view['workspaceKey'];
		$this->provideConfig($uid, $baseUrl, $lang, [
			'hiddenUiElements' => $view['hiddenUiElements']['user'],
			'globalHiddenUiElements' => $view['hiddenUiElements']['forced'],
			'prefetchedSettings' => $view['prefetchedSettings'] + [$opacityKey => (string)$this->backgrounds->readOpacity($uid, $opacityKey)],
			'background' => $this->backgrounds->status($uid, $view['workspaceKey'], 'background'),
		]);
		return $this->page('index', 'index', [
			'tr' => $this->translator,
			'view' => $view,
			'baseUrl' => $baseUrl,
			'railMentions' => $this->mentions->railCount($uid),
			'railAssignments' => $this->assignmentsView->railLabel($uid),
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
			'teamsAvailable' => $this->teams->isAvailable(),
			'teams' => $this->teamsUi->config(),
			'excalidrawAssetPath' => $assetBase . '/js/vendor/excalidraw-dist/',
			'allowedIframeDomains' => $this->embedDomains->domains(),
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

	// Upstream excalidraw_editor.php: Diagramm einer Notiz (diagram_id = eingebettet); Lesefreigabe nur ansehen; ohne note_id → 404
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function excalidrawEditor(): TemplateResponse {
		$uid = $this->userSession->getUser()?->getUID() ?? '';
		$lang = $this->language($uid);
		$baseUrl = $this->urls->linkToRoute('nextnotes.page.index');
		try {
			$data = $this->excalidraw->page($uid, $this->request->getParam('note_id'), $this->request->getParam('diagram_id'), $this->request->getParam('workspace'));
		} catch (NotFoundException) {
			return $this->notFound(TemplateResponse::RENDER_AS_GUEST);
		}
		$note = $data['note'];
		$t = fn (string $key, string $default, array $vars = []) => $this->translator->t($key, $vars, $default);
		$heading = (string)($note->getHeading() ?? '');
		$this->assets->page('excalidraw_editor', $lang, ['vendor/excalidraw-dist/excalidraw-bundle.iife']);
		$this->provideConfig($uid, $baseUrl, $lang);
		return $this->page('excalidraw_editor', 'excalidraw_editor', [
			'tr' => $this->translator,
			'title' => $heading !== '' ? $heading : $t('excalidraw.editor.titles.embedded_diagram', 'Embedded Diagram'),
			'canWrite' => $data['canWrite'],
			'config' => [
				'language' => $lang,
				'noteId' => (int)$note->getId(),
				'noteTitle' => $heading,
				'workspace' => $data['workspace'],
				'diagramId' => $data['diagramId'],
				'isEmbeddedDiagram' => $data['diagramId'] !== null,
				'existingData' => $data['existingData'],
				'readOnly' => !$data['canWrite'],
				'txt' => [
					'editorNotReady' => $t('excalidraw.editor.alerts.editor_not_ready', 'Editor not ready'),
					'saving' => $t('excalidraw.editor.toolbar.saving', 'Saving...'),
					'saved' => $t('excalidraw.editor.toolbar.saved', 'Saved!'),
					'save' => $t('common.save', 'Save'),
					'saveAndExit' => $t('excalidraw.editor.toolbar.save_and_exit', 'Save and exit'),
					'failedToLoad' => $t('excalidraw.editor.errors.failed_to_load', 'Error: Failed to load Excalidraw. Please refresh the page.'),
					'initErrorTemplate' => $t('excalidraw.editor.errors.initializing_prefix', 'Error initializing Excalidraw: {{error}}', ['error' => '{{error}}']),
					'errorTemplate' => $t('excalidraw.editor.alerts.error_prefix', 'Error: {{error}}', ['error' => '{{error}}']),
					'saveFailed' => $t('excalidraw.editor.errors.save_failed', 'Save failed'),
					'saveTimeout' => $t('excalidraw.editor.errors.save_timeout', 'The server did not answer in time. Your diagram is still open here, try saving again.'),
				],
			],
		]);
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

	// Fragment-Modus right_col (note-loader.js): nur #right_col ohne Seitenrahmen; Zugriff wie die volle Seite
	private function fragment(string $uid): TemplateResponse {
		try {
			$view = $this->indexView->fragment($uid, $this->request->getParam('note'));
		} catch (NotFoundException) {
			return $this->blankNotFound();
		}
		$response = new TemplateResponse(Application::APP_ID, 'fragment', ['tr' => $this->translator, 'note' => $view['note']], TemplateResponse::RENDER_AS_BLANK);
		$response->setContentSecurityPolicy($this->cspFactory->forView('index'));
		return $response;
	}

	private function blankNotFound(): TemplateResponse {
		$response = new TemplateResponse('core', '404', [], TemplateResponse::RENDER_AS_BLANK);
		$response->setStatus(Http::STATUS_NOT_FOUND);
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

	// Upstream trash.php: Papierkorb des Workspace (je 50, Vorschau); ?sort= ohne Speichern (B58); X-Poznote-Fragment: trash_cards → nächste Karten ab ?after=
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function trash(): TemplateResponse {
		if ($this->request->getHeader('X-Poznote-Fragment') === 'trash_cards') {
			$uid = $this->userSession->getUser()?->getUID() ?? '';
			$this->language($uid);
			$frame = $this->pageFrame->frame($uid, $this->request->getParam('workspace'));
			$trash = $this->trashView->build($uid, $frame, $this->request->getParam('sort'), $this->request->getParam('search'), $this->request->getParam('after'));
			$response = new TemplateResponse(Application::APP_ID, 'fragment_trash', ['tr' => $this->translator, 'frame' => $frame, 'trash' => $trash], TemplateResponse::RENDER_AS_BLANK);
			$response->setContentSecurityPolicy($this->cspFactory->forView('trash'));
			return $response;
		}
		return $this->listPage('trash', fn (string $uid, array $frame) => [
			'trash' => $this->trashView->build($uid, $frame, $this->request->getParam('sort'), $this->request->getParam('search')),
		]);
	}

	// Such-Formular des Papierkorbs (POST trash.php); CSRF über das versteckte Feld requesttoken
	#[NoAdminRequired]
	public function trashSearch(): TemplateResponse {
		return $this->trash();
	}

	// Upstream list_tags.php: Tags des Workspace mit Anzahl und Farbe
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function listTags(): TemplateResponse {
		return $this->listPage('list_tags', fn (string $uid, array $frame) => ['tags' => $this->tagListView->build($uid, $frame)]);
	}

	// Upstream list_folders.php: Ordnerbaum des Workspace mit Aktionen über die Ordner-API; X-Poznote-Fragment: folders_list → nur #foldersList (P24)
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function listFolders(): TemplateResponse {
		if ($this->request->getHeader('X-Poznote-Fragment') === 'folders_list') {
			$uid = $this->userSession->getUser()?->getUID() ?? '';
			$this->language($uid);
			$frame = $this->pageFrame->frame($uid, $this->request->getParam('workspace'));
			$response = new TemplateResponse(Application::APP_ID, 'fragment_folders', ['tr' => $this->translator, 'frame' => $frame,
				'folders' => $this->folderListView->build($uid, $frame)], TemplateResponse::RENDER_AS_BLANK);
			$response->setContentSecurityPolicy($this->cspFactory->forView('list_folders'));
			return $response;
		}
		return $this->listPage('list_folders', fn (string $uid, array $frame) => ['folders' => $this->folderListView->build($uid, $frame)]);
	}

	// Upstream attachments_list.php: Liste lädt attachments-list.js über notes/with-attachments
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function attachmentsList(): TemplateResponse {
		return $this->listPage('attachments_list');
	}

	// Upstream notes_manager.php: Liste und Mehrfachaktionen lädt notes-manager.js über die Notiz-API
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function notesManager(): TemplateResponse {
		return $this->listPage('notes_manager');
	}

	// Upstream create.php entfernt (UI-rail): Erstellen über das „+“-Menü der Hauptansicht; alte Lesezeichen → Hauptansicht
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function create(): RedirectResponse {
		$frame = $this->pageFrame->frame($this->userSession->getUser()?->getUID() ?? '', $this->request->getParam('workspace'));
		return new RedirectResponse($this->urls->linkToRoute('nextnotes.page.index') . 'index.php?' . http_build_query(['workspace' => $frame['workspaceKey']]));
	}

	// Upstream diary.php: Tagebuch-Karten/Journal; ?date= mit vorhandenem Eintrag → Notiz, sonst legt diary-page.js ihn an
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function diary(): Response {
		$uid = $this->userSession->getUser()?->getUID() ?? '';
		$this->language($uid);
		$frame = $this->pageFrame->frame($uid, $this->request->getParam('workspace'));
		$diary = $this->diaryView->build($uid, $frame, $this->request->getParam('diary'), $this->request->getParam('date'));
		if ($diary['redirectNote'] !== null) {
			return new RedirectResponse($this->urls->linkToRoute('nextnotes.page.index') . 'index.php?'
				. http_build_query(['note' => $diary['redirectNote'], 'newtab' => 1, 'workspace' => $frame['workspaceKey']]));
		}
		return $this->listPage('diary', fn () => [
			'diary' => $diary,
			'globals' => ['calendarTranslations' => $this->calendarTranslations(), 'NOTE_COLOR_PALETTE' => $diary['palette'] ?? [], 'DIARY_DATA' => $diary['data']],
		], $frame);
	}

	// Upstream tasks.php: Aufgaben aller Aufgabenlisten/Checklisten des Workspace (tasks-page.js über api/v1/tasks)
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function tasks(): TemplateResponse {
		return $this->listPage('tasks', function (string $uid) {
			$hidden = json_decode($this->settings->get($uid, 'tasks_page_hidden_notes', '[]'), true);
			$format = $this->settings->get($uid, 'date_time_format', 'default');
			return [
				'hiddenNotes' => is_array($hidden) ? array_values(array_filter($hidden, static fn ($v) => is_int($v) || is_string($v))) : [],
				'dateTimeFormat' => preg_match('/^[A-Za-z0-9_:.-]{1,40}$/', $format) ? $format : 'default',
				'globals' => ['calendarTranslations' => $this->calendarTranslations() + ['apply' => $this->translator->t('common.apply', [], 'Apply')]],
			];
		});
	}

	// Aufgaben austeilen und einsammeln (M6g, SPEC §9.6): Liste, Abgaben und Dialoge per nextnotes-assignments.js über die Aufgaben-API;
	// ?team=, ?assignment=, ?create=<Notiz> wertet nur das Skript aus (Rechte prüft die API)
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function assignments(): TemplateResponse {
		return $this->listPage('assignments', fn (string $uid) => [
			'teacherTeams' => $teams = $this->assignmentsView->teacherTeams($uid),
			'createTeam' => $this->assignmentsView->createTeam($uid, $this->request->getParam('create'), $teams),
			'texts' => $this->assignmentsView->texts(),
			'hintBytes' => AssignmentsViewService::HINT_BYTES,
		]);
	}

	/** @return array<string, mixed> Monats-/Tagesnamen der Datumsauswahl (Upstream diary.php/tasks.php) */
	private function calendarTranslations(): array {
		$t = fn (string $k) => $this->translator->t($k);
		return [
			'months' => array_map(static fn ($m) => $t('calendar.months.' . $m), ['january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december']),
			'weekdays' => array_map(static fn ($d) => $t('calendar.weekdays.' . $d), ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday']),
			'previousMonth' => $t('calendar.buttons.previous_month'),
			'nextMonth' => $t('calendar.buttons.next_month'),
			'today' => $t('calendar.buttons.today'),
		];
	}

	/**
	 * Rahmen der Listen-Seiten (M4e): Icon-Leiste, Workspace wie die Hauptansicht (unbekannt → Standard, §4.4), eigenes Bündel
	 * @param ?callable(string, array): array<string, mixed> $data
	 */
	private function listPage(string $page, ?callable $data = null, ?array $frame = null): TemplateResponse {
		$uid = $this->userSession->getUser()?->getUID() ?? '';
		$lang = $this->language($uid);
		$baseUrl = $this->urls->linkToRoute('nextnotes.page.index');
		$frame ??= $this->pageFrame->frame($uid, $this->request->getParam('workspace'));
		$extra = $data !== null ? $data($uid, $frame) : [];
		$this->assets->page($page, $lang);
		$hidden = $this->indexView->hiddenUi($uid);
		$this->provideConfig($uid, $baseUrl, $lang, [
			'hiddenUiElements' => $hidden['user'],
			'globalHiddenUiElements' => $hidden['forced'],
		]);
		return $this->page($page, $page, [
			'tr' => $this->translator,
			'baseUrl' => $baseUrl,
			'frame' => $frame,
			'currentPage' => $page . '.php',
			'railMentions' => $this->mentions->railCount($uid),
			'railAssignments' => $this->assignmentsView->railLabel($uid),
			'view' => ['workspaceKey' => $frame['workspaceKey'], 'iconPalette' => IndexViewService::iconPalette()],
		] + $extra);
	}

	// Upstream dashboard.php: Karten der Notizen nach Ordnern; Bereich (ein/mehrere/alle Workspaces, Tag) aus der URL, sonst gespeichert (P28)
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function dashboard(): TemplateResponse {
		return $this->listPage('dashboard', function (string $uid, array $frame): array {
			$board = $this->dashboardView->build($uid, $frame, $this->request->getParams());
			return ['board' => $board, 'globals' => $board['globals']];
		});
	}

	// Upstream graph.php: Graph der Verweise (graph.js über api/v1/graph)
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function graph(): TemplateResponse {
		return $this->listPage('graph');
	}

	// Speed Dial entfernt (Thomas 2026-11-08, SPEC Frontend-Liste 28): alte Lesezeichen → Dashboard, Workspace bleibt
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function speedDial(): RedirectResponse {
		$frame = $this->pageFrame->frame($this->userSession->getUser()?->getUID() ?? '', $this->request->getParam('workspace'));
		return new RedirectResponse($this->urls->linkToRoute('nextnotes.page.dashboard') . '?' . http_build_query(['workspace' => $frame['workspaceKey']]));
	}

	// Upstream mentions.php: Notizen geteilter Workspaces, die den Benutzer mit @uid nennen; ?handled=1 zeigt erledigte
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function mentions(): TemplateResponse {
		$showHandled = $this->request->getParam('handled') === '1';
		return $this->listPage('mentions', fn (string $uid) => [
			'uid' => $uid,
			'showHandled' => $showHandled,
			'mentions' => $this->mentions->page($uid, $showHandled),
		]);
	}

	// Upstream settings.php: persönliche Einstellungen; Workspaces-Bereich (workspaces_section.php), Hintergrundbild je Workspace (B43)
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function settings(): TemplateResponse {
		return $this->listPage('settings', function (string $uid): array {
			$page = $this->settingsView->build($uid);
			return [
				'settingsPage' => $page,
				'uid' => $uid,
				'appVersion' => $this->appManager->getAppVersion(Application::APP_ID),
				'contactsUrl' => $this->teamsUi->contactsUrl(),
			];
		});
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
		$response->setFeaturePolicy($this->cspFactory->featurePolicyForView($view));
		return $response;
	}
}
