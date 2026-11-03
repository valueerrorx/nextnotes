<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service\View;

use OCA\NextNotes\Db\Folder;
use OCA\NextNotes\Db\Note;
use OCA\NextNotes\Db\NoteMapper;
use OCA\NextNotes\Db\NoteTagMapper;
use OCA\NextNotes\Db\Workspace;
use OCA\NextNotes\Service\AccessService;
use OCA\NextNotes\Service\Exception\NotFoundException;
use OCA\NextNotes\Service\FolderService;
use OCA\NextNotes\Service\InputValidator;
use OCA\NextNotes\Service\MentionService;
use OCA\NextNotes\Service\NoteSerializer;
use OCA\NextNotes\Service\NoteService;
use OCA\NextNotes\Service\SettingsService;
use OCA\NextNotes\Service\WorkspaceService;

/**
 * Daten der Hauptansicht (Upstream index.php, notes_list.php, folders_display.php) ohne Markup.
 * Zugriff nur über sichtbare Workspaces bzw. AccessService; alle Farben/Klassen hier geprüft.
 */
class IndexViewService {
	// Upstream index.php:267-302 (nur Werte, die der Rahmen braucht)
	private const DEFAULTS = [
		'note_font_size' => '15',
		'sidebar_font_size' => '13',
		'center_note_content' => '0',
		'show_note_created' => '0',
		'show_note_icons' => '1',
		'hide_folder_actions' => '1',
		'notes_without_folders_after_folders' => '1',
		'folder_icon_opens_kanban' => '0',
		'folder_count_parentheses' => '0',
		'code_block_word_wrap' => '1',
		'code_block_line_numbers' => '0',
		'markdown_split_card_view' => '1',
		'markdown_split_preview_left' => '0',
		'markdown_heading_underline' => '1',
		'markdown_editor_word_wrap' => '1',
		'markdown_live_editing' => '0',
		'attachment_previews_in_note' => '0',
		'attachments_at_bottom' => '0',
		'backlinks_at_bottom' => '0',
		'note_virtual_space' => '0',
		'default_image_border_no_padding' => '0',
		'spellcheck_html_notes' => '0',
		'highlight_current_folder_tree' => '0',
		'folder_tree_dim_level' => '',
		'markdown_default_view_mode' => 'preview',
		'markdown_new_note_view_mode' => '',
		'markdown_live_show_syntax' => '1',
		'markdown_view_cycle' => 'live,rich,split',
		'favorites_sort' => 'heading_asc',
		'favorites_icon_color' => '',
		'emoji_icons_enabled' => '1',
		'slash_menu_trigger' => 'slash',
		'slash_menu_trigger_mobile' => 'slash',
		'format_toolbar_mode' => 'toolbar',
		'date_time_format' => 'default',
		'tasklist_insert_order' => 'bottom',
		'hidden_ui_elements' => '',
		'tag_colors' => '',
		'default_workspace' => '',
		'last_opened_workspace' => '',
	];
	// Upstream src/lib/note-sort.php
	public const SORT_MODES = ['heading_asc', 'updated_desc', 'created_desc', 'type_asc', 'manual'];
	public const FAVORITES_SORT_MODES = ['heading_asc', 'updated_desc', 'created_desc', 'type_asc'];
	public const SORT_ICONS = [
		'heading_asc' => 'lucide-arrow-down-a-z', 'updated_desc' => 'lucide-sort-date-modified',
		'created_desc' => 'lucide-sort-date-created', 'type_asc' => 'lucide-sort-type', 'manual' => 'lucide-sort-custom',
	];
	// Upstream src/lib/color-palette.php (Palette und Altfarben → Theme-Token)
	private const ICON_PALETTE = [
		'#dc2626' => 'red', '#ea580c' => 'orange', '#d97706' => 'amber', '#eab308' => 'yellow', '#84cc16' => 'lime',
		'#16a34a' => 'green', '#0d9488' => 'teal', '#0891b2' => 'cyan', '#2563eb' => 'blue', '#4f46e5' => 'indigo',
		'#9333ea' => 'purple', '#c026d3' => 'magenta', '#db2777' => 'pink', '#92400e' => 'brown', '#6b7280' => 'gray',
	];
	private const LEGACY_ICON_TOKENS = [
		'#ef4444' => '--pz-color-red', '#f97316' => '--pz-color-orange', '#f59e0b' => '--pz-color-amber',
		'#22c55e' => '--pz-color-green', '#10b981' => '--pz-color-green', '#14b8a6' => '--pz-color-teal',
		'#06b6d4' => '--pz-color-cyan', '#0ea5e9' => '--pz-color-cyan', '#3b82f6' => '--pz-color-blue',
		'#6366f1' => '--pz-color-indigo', '#8b5cf6' => '--pz-color-purple', '#a855f7' => '--pz-color-purple',
		'#d946ef' => '--pz-color-magenta', '#ec4899' => '--pz-color-pink', '#f43f5e' => '--pz-color-red',
		'#64748b' => '--pz-color-gray', '#78716c' => '--pz-color-gray', '#111827' => '--pz-text',
	];
	// Bis zur Portierung erzwungen ausgeblendet (ui-customization.js, __POZNOTE_GLOBAL_HIDDEN_UI_ELEMENTS__)
	public const FORCED_HIDDEN = [
		'wsmenu:edit-workspaces', // settings.php: M4
		'wsmenu:new-workspace', // Workspace-Modal: M3b
	];
	private const SPEED_DIAL_TAG = 'speed-dial';

	/** @var array<string, string> */
	private array $values = [];

	public function __construct(
		private AccessService $access,
		private WorkspaceService $workspaces,
		private FolderService $folders,
		private NoteMapper $notes,
		private NoteTagMapper $tags,
		private SettingsService $settings,
		private MentionService $mentions,
		private PageTranslator $tr,
	) {
	}

	/**
	 * @param array<string, mixed> $query note, workspace, folder, open_folder
	 * @return array<string, mixed>
	 * @throws NotFoundException fremde oder unbekannte Notiz in ?note=
	 */
	public function build(string $uid, string $baseUrl, array $query): array {
		$this->values = [];
		$rawNote = is_string($query['note'] ?? null) ? trim($query['note']) : '';
		$note = null;
		if ($rawNote !== '') {
			if (!ctype_digit($rawNote)) {
				throw new NotFoundException('Note not found');
			}
			$note = $this->access->requireNote($uid, (int)$rawNote, 'read');
		}
		$requested = is_string($query['workspace'] ?? null) ? $query['workspace'] : '';
		$ws = $this->resolveWorkspace($uid, $note, $requested);
		$wsId = (int)$ws->getId();
		$key = (string)$this->workspaces->keyFor($uid, $wsId);
		$canWrite = $this->access->workspaceLevel($uid, $ws) >= AccessService::WRITE;

		$sort = $this->settings->noteListSort($uid);
		$favSort = in_array($this->value($uid, 'favorites_sort'), self::FAVORITES_SORT_MODES, true) ? $this->value($uid, 'favorites_sort') : 'heading_asc';
		$folderFilter = is_string($query['folder'] ?? null) ? trim($query['folder']) : '';
		$openFolder = is_string($query['open_folder'] ?? null) ? $query['open_folder'] : '';
		$byId = $this->folders->byId($wsId);
		$notes = $this->notes->findList([$wsId]);
		$noteFolderId = $note?->getFolderId();

		$tree = $this->tree($uid, $baseUrl, $key, $byId, $notes, $sort, $favSort, $note, $folderFilter, $openFolder);
		$titleColor = $this->colorHex($ws->getColor());

		return [
			'workspaceKey' => $key,
			'workspaceColor' => $titleColor,
			'canWrite' => $canWrite,
			'replaceWorkspace' => $requested !== $key ? $key : null,
			'noteId' => $note !== null ? (int)$note->getId() : null,
			'sort' => $sort,
			'sortIcons' => self::SORT_ICONS,
			'sortModes' => self::SORT_MODES,
			'favoritesSort' => $favSort,
			'favoritesSortModes' => self::FAVORITES_SORT_MODES,
			'favoritesIconColor' => $this->iconColor($this->value($uid, 'favorites_icon_color')),
			'folderIconOpensKanban' => $this->bool($uid, 'folder_icon_opens_kanban'),
			'showNoteIcons' => $this->bool($uid, 'show_note_icons'),
			'tree' => $tree,
			'exitFolderUrl' => $baseUrl . 'index.php?' . http_build_query(['workspace' => $key]),
			'body' => $this->body($uid, $key, $note === null),
			'poznoteConfig' => $this->poznoteConfig($uid),
			'workspaceMap' => $this->workspaceMap($uid),
			'pageConfig' => $this->pageConfig($uid, $key, $noteFolderId !== null ? ($byId[$noteFolderId] ?? null)?->getName() : null, $canWrite),
			'globals' => $this->globals($uid, $key),
			'hiddenUiElements' => $this->hiddenUi($uid),
			'prefetchedSettings' => ['tasklist_insert_order' => $this->value($uid, 'tasklist_insert_order') === 'top' ? 'top' : 'bottom'],
		];
	}

	/** @return array{0: string, 1: array<string, string>, 2: string} Upstream poznoteNoteSortLabel → PageTranslator::t() */
	public static function sortLabel(string $mode): array {
		return match ($mode) {
			'heading_asc' => ['sort.modes.name', [], 'Name'],
			'created_desc' => ['sort.modes.date_created', [], 'Date created'],
			'type_asc' => ['sort.modes.type', [], 'Type'],
			'manual' => ['sort.modes.custom', [], 'Custom'],
			default => ['sort.modes.date_modified', [], 'Date modified'],
		};
	}

	/** @return array{0: string, 1: array<string, string>, 2: string} Upstream poznoteNoteSortMenuLabel */
	public static function sortMenuLabel(string $mode): array {
		return match ($mode) {
			'heading_asc' => ['sort.menu_items.name', [], 'Sort by name'],
			'created_desc' => ['sort.menu_items.date_created', [], 'Sort by date created'],
			'type_asc' => ['sort.menu_items.type', [], 'Sort by type'],
			'manual' => ['sort.menu_items.custom', [], 'Custom order'],
			default => ['sort.menu_items.date_modified', [], 'Sort by date modified'],
		};
	}

	/** @return array{0: string, 1: array<string, string>, 2: string} Upstream poznoteNoteSortHint */
	public static function sortHint(string $mode): array {
		return match ($mode) {
			'heading_asc' => ['sort.hints.name', [], 'Notes by title and folders by name, from A to Z'],
			'created_desc' => ['sort.hints.date_created', [], 'Most recently created notes and folders first'],
			'type_asc' => ['sort.hints.type', [], 'Notes grouped by type (note, markdown, task list, drawing, link), then by title'],
			'manual' => ['sort.hints.custom', [], 'The order you set by dragging notes and folders'],
			default => ['sort.hints.date_modified', [], 'Most recently modified notes first'],
		};
	}

	// Upstream index.php:107-160 und getWorkspaceFilter(); ohne Speichern (GET ohne Seiteneffekt, JS speichert per API)
	private function resolveWorkspace(string $uid, ?Note $note, string $requested): Workspace {
		if ($note !== null) {
			$key = $this->workspaces->keyFor($uid, (int)$note->getWorkspaceId());
			if ($key !== null) {
				return $this->workspaces->resolveKey($uid, $key);
			}
		}
		$candidates = [$requested];
		$default = $this->value($uid, 'default_workspace');
		if (!in_array($default, ['', '__last_opened__', '__last_opened_device__'], true)) {
			$candidates[] = $default;
		}
		$candidates[] = $this->value($uid, 'last_opened_workspace');
		foreach ($candidates as $candidate) {
			if ($candidate === '' || $candidate === '__last_opened__') {
				continue;
			}
			try {
				return $this->workspaces->resolveKey($uid, $candidate);
			} catch (NotFoundException) {
				// unbekannt oder nicht sichtbar: nächster Kandidat (SPEC §4.4)
			}
		}
		return $this->workspaces->defaultWorkspace($uid);
	}

	/**
	 * Ordnerbaum mit Notizen (Upstream organizeNotesByFolder, handleFavorites, buildFolderHierarchy, shouldFolderBeOpen)
	 * @param array<int, Folder> $byId
	 * @param Note[] $notes
	 * @return array<string, mixed>
	 */
	private function tree(string $uid, string $baseUrl, string $key, array $byId, array $notes, string $sort, string $favSort, ?Note $selected, string $folderFilter, string $openFolder): array {
		$tags = $this->tags->tagsFor(array_map(static fn (Note $n) => (int)$n->getId(), $notes));
		$byNoteId = [];
		foreach ($notes as $n) {
			$byNoteId[(int)$n->getId()] = $n;
		}
		$selectedId = $selected !== null ? (int)$selected->getId() : 0;
		$linkBase = ['workspace' => $key];
		if ($folderFilter !== '') {
			$linkBase['folder'] = $folderFilter;
		}

		$item = function (Note $n) use ($uid, $baseUrl, $linkBase, $tags, $byNoteId, $byId, $selectedId): array {
			$id = (int)$n->getId();
			$type = $n->getType() ?: 'note';
			$icon = (string)($n->getIcon() ?? '');
			$iconColor = (string)($n->getIconColor() ?? '');
			$titleColor = (string)($n->getTitleColor() ?? '');
			// Verknüpfung ohne eigenes Symbol erbt Symbol und Titelfarbe der Zielnotiz (nur aus diesem Workspace)
			$target = $type === 'linked' && $n->getLinkedNoteId() !== null ? ($byNoteId[(int)$n->getLinkedNoteId()] ?? null) : null;
			if ($target !== null && $icon === '') {
				$icon = (string)($target->getIcon() ?? '');
				$iconColor = (string)($target->getIconColor() ?? '');
			}
			if ($target !== null && $titleColor === '') {
				$titleColor = (string)($target->getTitleColor() ?? '');
			}
			$folderId = $n->getFolderId();
			$folder = $folderId !== null ? ($byId[$folderId] ?? null) : null;
			$title = $this->noteTitle((string)($n->getHeading() ?? ''));
			$default = match ($type) {
				'tasklist' => 'lucide-list-todo',
				'markdown' => 'lucide-file-code',
				default => 'lucide-file-text',
			};
			return [
				'id' => $id,
				'title' => $title,
				'type' => $type,
				'linkedNoteId' => $type === 'linked' && $n->getLinkedNoteId() !== null ? (int)$n->getLinkedNoteId() : null,
				'folderId' => $folder !== null ? (int)$folderId : '',
				'folderName' => $folder !== null ? $folder->getName() : '',
				'created' => (string)NoteSerializer::date($n->getCreatedAt()),
				'updated' => (string)NoteSerializer::date($n->getUpdatedAt()),
				'favorite' => (int)$n->getFavorite() === 1,
				'speedDial' => in_array(self::SPEED_DIAL_TAG, array_map(static fn ($t) => mb_strtolower(trim((string)$t)), $tags[$id] ?? []), true),
				'iconClass' => $this->iconClass($icon, $default),
				'iconCustom' => $icon !== '',
				'iconDefault' => $default,
				'iconColorRaw' => $this->iconColor($iconColor) !== '' ? $iconColor : '',
				'iconColor' => $this->iconColor($iconColor),
				'titleColor' => $this->iconColor($titleColor),
				'selected' => $id === $selectedId,
				'url' => $baseUrl . 'index.php?' . http_build_query($linkBase + ['note' => $id]),
			];
		};

		// Notizen je Ordner; Notiz in unbekanntem Ordner gilt als ohne Ordner
		$inFolder = [];
		$root = [];
		$favorites = [];
		foreach ($notes as $n) {
			$fid = $n->getFolderId();
			if ($fid !== null && isset($byId[$fid])) {
				$inFolder[$fid][] = $n;
			} else {
				$root[] = $n;
			}
			if ((int)$n->getFavorite() === 1) {
				$favorites[] = $n;
			}
		}
		$sortNotes = static function (array $list, string $mode): array {
			usort($list, static fn (Note $a, Note $b) => NoteService::compareNotes($mode, $a, $b));
			return $list;
		};

		// Ordner, die offen sein sollen: Ordner der gewählten Notiz samt Vorfahren, ?open_folder=folder_<id>, bei ≤ 3 Notizen alle
		$total = count($notes);
		$open = [];
		$activeId = null;
		$branch = [];
		if ($selected !== null && $selected->getFolderId() !== null && isset($byId[$selected->getFolderId()])) {
			$activeId = (int)$selected->getFolderId();
			foreach ($this->folders->ancestry($activeId, $byId) as $f) {
				$fid = (int)$f->getId();
				$open[$fid] = true;
				if ($fid !== $activeId) {
					$branch[$fid] = true;
				}
			}
			$open[$activeId] = true;
		}
		if (preg_match('/^folder_(\d+)$/', $openFolder, $m)) {
			$open[(int)$m[1]] = true;
		}
		$highlight = $this->bool($uid, 'highlight_current_folder_tree');

		$build = function (?int $parent, int $depth) use (&$build, $byId, $inFolder, $sort, $sortNotes, $item, $open, $total, $activeId, $branch, $highlight): array {
			$children = array_filter($byId, static fn (Folder $f) => $f->getParentId() === $parent);
			uasort($children, static fn (Folder $a, Folder $b) => FolderService::compare($sort, $a, $b));
			$out = [];
			foreach ($children as $id => $f) {
				$sub = $build((int)$id, $depth + 1);
				$own = array_map($item, $sortNotes($inFolder[$id] ?? [], $sort));
				$count = count($own) + array_sum(array_map(static fn ($c) => $c['count'], $sub));
				$icon = (string)($f->getIcon() ?? '');
				$isOpen = $total <= 3 || isset($open[$id]);
				$out[] = [
					'id' => (int)$id,
					'name' => $f->getName(),
					'depth' => $depth,
					'count' => $count,
					'open' => $isOpen,
					'favorite' => (int)$f->getFavorite() === 1,
					'iconClass' => $icon !== '' ? $this->iconClass($icon, 'lucide-folder') : ($isOpen ? 'lucide lucide-folder-open' : 'lucide lucide-folder'),
					'iconCustom' => $icon !== '',
					'iconColorRaw' => $this->iconColor((string)($f->getIconColor() ?? '')) !== '' ? (string)$f->getIconColor() : '',
					'iconColor' => $this->iconColor((string)($f->getIconColor() ?? '')),
					'treeClass' => !$highlight ? '' : ($id === $activeId ? ' folder-tree-active' : (isset($branch[$id]) ? ' folder-tree-branch' : '')),
					'notes' => $own,
					'children' => $sub,
				];
			}
			return $out;
		};
		$folders = $build(null, 0);

		// ?folder=<Name>: nur dieser Teilbaum, Wurzel ohne Kopf (Banner); unbekannt → leerer Baum wie Upstream
		$filterRoot = null;
		if ($folderFilter !== '') {
			$find = static function (array $nodes) use (&$find, $folderFilter): ?array {
				foreach ($nodes as $node) {
					if ($node['name'] === $folderFilter) {
						return $node;
					}
					if (($hit = $find($node['children'])) !== null) {
						return $hit;
					}
				}
				return null;
			};
			$filterRoot = $find($folders);
			$folders = [];
		}

		$favoriteFolders = array_values(array_filter($byId, static fn (Folder $f) => (int)$f->getFavorite() === 1));
		usort($favoriteFolders, static fn (Folder $a, Folder $b) => strnatcasecmp($a->getName(), $b->getName()) ?: ((int)$a->getId() <=> (int)$b->getId()));
		$favFolders = array_map(fn (Folder $f) => [
			'id' => (int)$f->getId(),
			'name' => $f->getName(),
			'iconClass' => $this->iconClass((string)($f->getIcon() ?? ''), 'lucide-folder'),
			'iconColor' => $this->iconColor((string)($f->getIconColor() ?? '')),
			'url' => $baseUrl . 'index.php?' . http_build_query(['folder' => $f->getName(), 'workspace' => $this->workspaces->keyFor($uid, (int)$f->getWorkspaceId())]),
		], $favoriteFolders);

		$rootFirst = !$this->bool($uid, 'notes_without_folders_after_folders')
			&& !in_array($sort, ['heading_asc', 'type_asc'], true);
		$showFavorites = $folderFilter === '' && ($favorites !== [] || $favFolders !== []);

		return [
			'filter' => $folderFilter,
			'filterRoot' => $filterRoot,
			'favorites' => $showFavorites ? array_map($item, $sortNotes($favorites, $favSort)) : null,
			'favoriteFolders' => $favFolders,
			'root' => $folderFilter === '' ? array_map($item, $sortNotes($root, $sort)) : [],
			'rootFirst' => $rootFirst,
			'folders' => $folders,
		];
	}

	// Upstream translateDefaultNoteTitle; Standardtitel aller Sprachen aus dem Build
	private function noteTitle(string $heading): string {
		if (trim($heading) === '') {
			return $this->tr->t('index.note.new_note', [], 'New note');
		}
		foreach ($this->tr->defaultNoteTitles() as $default) {
			if (preg_match('/^' . preg_quote($default, '/') . '(?: \((\d+)\))?$/u', trim($heading), $m)) {
				return isset($m[1]) && $m[1] !== ''
					? $this->tr->t('index.note.new_note_numbered', ['number' => $m[1]], 'New note (' . $m[1] . ')')
					: $this->tr->t('index.note.new_note', [], 'New note');
			}
		}
		return $heading;
	}

	// Nur lucide-Klassen (InputValidator::icon erlaubt [A-Za-z0-9 _-]); sonst Standard
	private function iconClass(string $icon, string $default): string {
		$classes = array_values(array_filter(preg_split('/\s+/', trim($icon)) ?: [], static fn ($c) => preg_match('/^lucide(-[a-z0-9-]+)?$/', $c) === 1));
		$hasIcon = array_filter($classes, static fn ($c) => $c !== 'lucide') !== [];
		if (!$hasIcon) {
			$classes[] = $default;
		}
		return implode(' ', array_values(array_unique(array_merge(['lucide'], $classes))));
	}

	// Upstream poznoteIconColorCss: Paletten-/Altfarbe → Token, sonst geprüfte Farbe, sonst ''
	private function iconColor(string $color): string {
		$color = trim($color);
		if (!preg_match('/^(#[0-9a-fA-F]{3,8}|[a-zA-Z]{3,30})$/', $color)) {
			return '';
		}
		$lower = strtolower($color);
		if (isset(self::ICON_PALETTE[$lower])) {
			return 'var(--pz-color-' . self::ICON_PALETTE[$lower] . ', ' . $lower . ')';
		}
		if (isset(self::LEGACY_ICON_TOKENS[$lower])) {
			return 'var(' . self::LEGACY_ICON_TOKENS[$lower] . ', ' . $lower . ')';
		}
		return $color;
	}

	private function colorHex(?string $stored): string {
		$hex = $stored === null || $stored === '' ? '' : ($stored[0] === '#' ? $stored : (InputValidator::PALETTE[$stored] ?? ''));
		return preg_match('/^#[0-9a-f]{3,8}$/i', $hex) ? $hex : '';
	}

	/** @return array<string, mixed> Klassen/Attribute für <body> (Upstream index.php:480-635), setzt der Adapter */
	private function body(string $uid, string $key, bool $noNote): array {
		$classes = [];
		$flag = function (string $setting, string $class, bool $when = true) use ($uid, &$classes): void {
			if ($this->bool($uid, $setting) === $when) {
				$classes[] = $class;
			}
		};
		$flag('show_note_created', 'show-note-created');
		$flag('hide_folder_actions', 'folder-actions-always-visible');
		$flag('folder_icon_opens_kanban', 'folder-icon-opens-kanban');
		$flag('folder_count_parentheses', 'folder-count-parentheses');
		$flag('code_block_word_wrap', 'code-block-no-wrap', false);
		$flag('code_block_line_numbers', 'code-block-line-numbers');
		$flag('markdown_split_card_view', 'markdown-split-card-view');
		$flag('markdown_split_preview_left', 'markdown-split-preview-left');
		$flag('markdown_heading_underline', 'markdown-no-heading-underline', false);
		$flag('markdown_editor_word_wrap', 'markdown-editor-no-wrap', false);
		$flag('note_virtual_space', 'note-virtual-space');
		$style = [];
		if ($this->bool($uid, 'highlight_current_folder_tree')) {
			$classes[] = 'highlight-folder-tree';
			$dim = $this->value($uid, 'folder_tree_dim_level');
			if (ctype_digit($dim) && (int)$dim >= 10 && (int)$dim <= 90) {
				$style['--folder-tree-dim-opacity'] = number_format((100 - (int)$dim) / 100, 2, '.', '');
			}
		}
		// Notizbreite (Upstream index.php:324-333) als CSS-Länge
		$width = trim($this->value($uid, 'center_note_content'));
		$maxWidth = '800px';
		if (!in_array(strtolower($width), ['', '0', 'false', '100%'], true)) {
			$classes[] = 'center-note-content';
			if (preg_match('/^(\d{1,3})%$/', $width, $m)) {
				$maxWidth = (int)$m[1] . '%';
			} elseif (ctype_digit($width) && $width !== '1') {
				$maxWidth = (int)$width . 'px';
			}
		}
		$fontSize = static fn (string $v, string $d) => (ctype_digit($v) && (int)$v >= 8 && (int)$v <= 40 ? (int)$v : (int)$d) . 'px';
		$rootStyle = [
			'--note-font-size' => $fontSize($this->value($uid, 'note_font_size'), '15'),
			'--sidebar-font-size' => $fontSize($this->value($uid, 'sidebar_font_size'), '13'),
			'--note-max-width' => $maxWidth,
		];
		$mode = $this->value($uid, 'markdown_default_view_mode');
		$newMode = $this->value($uid, 'markdown_new_note_view_mode');
		$cycle = trim($this->value($uid, 'markdown_view_cycle'));
		return [
			'classes' => $classes,
			'style' => $style,
			'rootStyle' => $rootStyle,
			'htmlClasses' => $noNote ? ['outline-no-note'] : [],
			'data' => [
				'workspace' => $key,
				'markdown-default-mode' => in_array($mode, ['preview', 'edit', 'split', 'last'], true) ? $mode : 'preview',
				'markdown-new-note-mode' => in_array($newMode, ['split', 'edit', 'live', 'rich'], true) ? $newMode : '',
				'markdown-live-editing' => $this->bool($uid, 'markdown_live_editing') ? '1' : '0',
				'markdown-live-syntax' => $this->bool($uid, 'markdown_live_show_syntax') ? '1' : '0',
				'markdown-view-cycle' => $cycle === 'none' || preg_match('/^(live|rich|split)(,(live|rich|split))*$/', $cycle) ? $cycle : 'live,rich,split',
				'spellcheck' => $this->bool($uid, 'spellcheck_html_notes') ? '1' : '0',
				'spellcheck-lang' => $this->tr->language(),
			],
		];
	}

	/** @return array<string, mixed> #poznote-config (Upstream index.php:692-711, ohne Git/STT) */
	private function poznoteConfig(string $uid): array {
		$format = $this->value($uid, 'date_time_format');
		return [
			'gitSyncAutoPush' => false,
			'gitSyncedWorkspaces' => null,
			'dateTimeFormat' => preg_match('/^[A-Za-z0-9_:.-]{1,40}$/', $format) ? $format : 'default',
			'inlineAttachmentPreviews' => $this->bool($uid, 'attachment_previews_in_note'),
			'attachmentsAtBottom' => $this->bool($uid, 'attachments_at_bottom'),
			'backlinksAtBottom' => $this->bool($uid, 'backlinks_at_bottom'),
			'defaultImageBorderNoPadding' => $this->bool($uid, 'default_image_border_no_padding'),
			'archiveWorkspace' => WorkspaceService::ARCHIVE_NAME,
			'speechToText' => false,
			'speechToTextLanguage' => '',
			'speechToTextMaxSeconds' => 0,
		];
	}

	/** @return array<string, string> Schlüssel → Anzeigename (Upstream generateWorkspaceDisplayMap) */
	private function workspaceMap(string $uid): array {
		$map = [];
		foreach ($this->workspaces->keys($uid) as $key) {
			$map[$key] = $key;
		}
		return $map;
	}

	/** @return array<string, mixed> #page-config-data (Upstream index.php:938-965) */
	private function pageConfig(string $uid, string $key, ?string $noteFolder, bool $canWrite): array {
		return [
			'isSearchMode' => false,
			'currentNoteFolder' => $noteFolder,
			'selectedWorkspace' => $key,
			'userId' => $uid,
			'userEntriesPath' => '',
			'defaultNoteSortType' => $this->settings->noteListSort($uid),
			'isAdmin' => false,
			'canUseSettingsApi' => true,
			'canWrite' => $canWrite,
			'offlineMode' => false,
			'settings' => [
				'emoji_icons_enabled' => $this->bool($uid, 'emoji_icons_enabled') ? '1' : '0',
				'slash_menu_trigger' => $this->value($uid, 'slash_menu_trigger'),
				'slash_menu_trigger_mobile' => $this->value($uid, 'slash_menu_trigger_mobile'),
				'format_toolbar_mode' => $this->value($uid, 'format_toolbar_mode'),
			],
		];
	}

	/** @return array<string, mixed> window-Globals der Upstream-Inline-Skripte (index.php:1107-1180, icon_sidebar.php:446-455) */
	private function globals(string $uid, string $key): array {
		$t = fn (string $k, string $d = '') => $this->tr->t($k, [], $d === '' ? null : $d);
		$months = ['january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december'];
		$days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
		try {
			$members = array_values(array_filter($this->mentions->members($uid, $key), static fn ($m) => strtolower($m) !== strtolower($uid)));
		} catch (NotFoundException) {
			$members = [];
		}
		$tagColors = json_decode($this->value($uid, 'tag_colors'), true);
		$palette = [];
		foreach (InputValidator::PALETTE as $id => $hex) {
			$palette[] = ['id' => $id, 'name' => $t('note_color.names.' . $id, ucfirst($id)), 'hex' => $hex];
		}
		return [
			'DEFAULT_NOTE_TITLES' => $this->tr->defaultNoteTitles(),
			'PoznoteMentionMembers' => $members,
			'TAG_COLORS' => is_array($tagColors) ? (object)$tagColors : new \stdClass(),
			'NOTE_COLOR_PALETTE' => $palette,
			'PoznoteActiveAccountIsOwn' => true,
			'calendarTranslations' => [
				'months' => array_map(static fn ($m) => $t('calendar.months.' . $m), $months),
				'weekdays' => array_map(static fn ($d) => $t('calendar.weekdays.' . $d), $days),
				'previousMonth' => $t('calendar.buttons.previous_month'),
				'nextMonth' => $t('calendar.buttons.next_month'),
				'today' => $t('calendar.buttons.today'),
				'apply' => $t('common.apply', 'Apply'),
				'showCalendar' => $t('calendar.buttons.show_calendar'),
				'hideCalendar' => $t('calendar.buttons.hide_calendar'),
				'modes' => ['created' => $t('calendar.modes.created', 'Created'), 'modified' => $t('calendar.modes.modified', 'Modified')],
				'dayCounts' => $t('calendar.day_counts', 'Created: {{created}} / Modified: {{modified}}'),
				'modal' => [
					'title' => $t('calendar.modal.title'),
					'open_all' => $t('calendar.modal.open_all'),
					'close' => $t('calendar.modal.close'),
					'no_notes' => $t('calendar.modal.no_notes', 'No notes on this day.'),
					'diary_open' => $t('calendar.modal.diary_open', 'Open diary entry'),
					'diary_create' => $t('calendar.modal.diary_create', 'Create diary entry'),
					'diary_error' => $t('diary.create_error', 'Could not create the diary entry.'),
				],
			],
			'NOTIFICATIONS_TXT' => [
				'dismiss' => $t('reminder.dismiss', 'Dismiss'),
				'justNow' => $t('reminder.just_now', 'Just now'),
				'repeats' => $t('reminder.repeats', 'Repeats'),
			],
		];
	}

	/** @return array{user: string[], forced: string[]} */
	private function hiddenUi(string $uid): array {
		$user = json_decode($this->value($uid, 'hidden_ui_elements'), true);
		$user = is_array($user) ? array_values(array_filter($user, static fn ($k) => is_string($k) && strlen($k) <= 100)) : [];
		return ['user' => array_values(array_unique(array_merge(self::FORCED_HIDDEN, $user))), 'forced' => self::FORCED_HIDDEN];
	}

	private function value(string $uid, string $key): string {
		return $this->values[$key] ??= $this->settings->get($uid, $key, self::DEFAULTS[$key] ?? '');
	}

	// Upstream poznoteSettingEnabled
	private function bool(string $uid, string $key): bool {
		$v = strtolower(trim($this->value($uid, $key)));
		return !in_array($v, ['', '0', 'false', 'off', 'no'], true);
	}
}
