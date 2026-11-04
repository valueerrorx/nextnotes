<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service\View;

use OCA\NextNotes\Service\NotePalette;
use OCA\NextNotes\Db\Folder;
use OCA\NextNotes\Db\FolderMapper;
use OCA\NextNotes\Db\Note;
use OCA\NextNotes\Db\NoteMapper;
use OCA\NextNotes\Db\NoteTagMapper;
use OCA\NextNotes\Db\Workspace;
use OCA\NextNotes\Service\ContentUrlRewriter;
use OCA\NextNotes\Service\InputValidator;
use OCA\NextNotes\Service\Pcre;
use OCA\NextNotes\Service\SearchTextNormalizer;
use OCA\NextNotes\Service\SettingsService;
use OCA\NextNotes\Service\TagService;
use OCA\NextNotes\Service\TaskService;
use OCA\NextNotes\Service\WorkspaceService;
use OCP\IDateTimeZone;

/**
 * Daten der Dashboard-Seite (Upstream dashboard.php): Ordnerbaum mit Karten je Workspace des Bereichs.
 * Bereich aus der URL (scope=all|tag|list|single), sonst die gespeicherte Wahl dashboard_scope (P28; GET schreibt nichts).
 */
class DashboardViewService {
	private const EXCERPT = 220;
	// Vorschau aus dem Anfang der Notiz (wie Kanban); Aufgabenlisten brauchen mehr für das JSON
	private const PREVIEW_BYTES = 4096;
	private const TASKLIST_BYTES = 65536;
	private const MAX_DATA_URI = 65536;

	public function __construct(
		private WorkspaceService $workspaces,
		private FolderMapper $folders,
		private NoteMapper $notes,
		private NoteTagMapper $tags,
		private SettingsService $settings,
		private SearchTextNormalizer $text,
		private ContentUrlRewriter $urls,
		private InputValidator $validator,
		private IDateTimeZone $timeZone,
		private PageTranslator $tr,
		private NotePalette $palette,
	) {
	}

	/**
	 * @param array{workspace: Workspace, workspaceKey: string} $frame Workspace der Seite (Leserecht geprüft)
	 * @param array<string, mixed> $params Query-Parameter
	 * @return array<string, mixed>
	 */
	public function build(string $uid, array $frame, array $params): array {
		$favoritesOnly = ($params['favorites'] ?? '') === '1';
		$scope = $this->scope($uid, $frame, $params);
		$data = ['folders' => [], 'notes' => [], 'groups' => []];
		$empty = true;
		if ($scope['mode'] !== 'single') {
			foreach ($scope['workspaces'] as $key) {
				$board = $this->board($uid, $scope['ids'][$key], $key, $favoritesOnly);
				$data['groups'][] = ['workspace' => $key, 'tags' => $scope['tags'][$key] ?? [], 'color' => $scope['colors'][$key] ?? ''] + $board;
				$empty = $empty && $board['folders'] === [] && $board['notes'] === [];
			}
		} else {
			$board = $this->board($uid, (int)$frame['workspace']->getId(), $frame['workspaceKey'], $favoritesOnly);
			$data = $board + ['groups' => []];
			$empty = $board['folders'] === [] && $board['notes'] === [];
		}
		$data['scope'] = [
			'mode' => $scope['mode'],
			'tag' => $scope['tag'],
			'workspaces' => $scope['workspaces'],
			'key' => $scope['key'],
			'colors' => (object)$scope['colors'],
		];
		$tagColors = json_decode($this->settings->get($uid, 'tag_colors', ''), true);
		$palette = $this->palette->entries($uid);
		return [
			'globals' => ['TAG_COLORS' => is_array($tagColors) ? (object)$tagColors : new \stdClass(), 'NOTE_COLOR_PALETTE' => $palette],
			'data' => $data,
			'empty' => $empty,
			'mode' => $scope['mode'],
			'tag' => $scope['tag'],
			'count' => count($scope['workspaces']),
			'favoritesOnly' => $favoritesOnly,
		];
	}

	/**
	 * Upstream poznoteResolveWorkspaceScope + dashboardResolveRememberedScope (ohne Schreiben)
	 * @return array{mode: string, workspaces: list<string>, ids: array<string, int>, tag: string, key: string, tags: array<string, string[]>, colors: array<string, string>}
	 */
	private function scope(string $uid, array $frame, array $params): array {
		$keys = $this->workspaces->keys($uid);
		$tags = [];
		$colors = [];
		$ids = [];
		foreach ($this->workspaces->listVisible($uid) as $ws) {
			$key = $keys[(int)$ws->getId()];
			$ids[$key] = (int)$ws->getId();
			$tags[$key] = $this->validator->workspaceTags((string)($ws->getTags() ?? ''));
			$hex = $this->palette->hex($ws->getColor());
			if ($hex !== '') {
				$colors[$key] = $hex;
			}
		}
		$requested = is_string($params['scope'] ?? null) ? strtolower(trim($params['scope'])) : '';
		// ohne scope= (Icon-Leiste, Lesezeichen) gilt eine gespeicherte Mehrfach-Wahl; eine gespeicherte Einzelwahl folgt dem Workspace der Seite
		if (!in_array($requested, ['all', 'tag', 'list', 'single'], true)) {
			$saved = json_decode($this->settings->get($uid, 'dashboard_scope', ''), true);
			if (is_array($saved) && in_array($saved['scope'] ?? null, ['all', 'tag', 'list'], true)) {
				$restored = $this->resolve($saved, $ids, $tags);
				if ($restored['workspaces'] !== []) {
					return $restored + ['ids' => $ids, 'tags' => $tags, 'colors' => $colors];
				}
			}
		}
		$resolved = in_array($requested, ['all', 'tag', 'list'], true) ? $this->resolve($params, $ids, $tags) : null;
		if ($resolved === null || $resolved['mode'] === 'single') {
			$resolved = ['mode' => 'single', 'workspaces' => [$frame['workspaceKey']], 'tag' => '', 'key' => ''];
		}
		return $resolved + ['ids' => $ids, 'tags' => $tags, 'colors' => $colors];
	}

	/**
	 * @param array<string, mixed> $params
	 * @param array<string, int> $ids
	 * @param array<string, string[]> $tags
	 * @return array{mode: string, workspaces: list<string>, tag: string, key: string}
	 */
	private function resolve(array $params, array $ids, array $tags): array {
		$all = array_keys($ids);
		$single = ['mode' => 'single', 'workspaces' => [], 'tag' => '', 'key' => ''];
		switch ($params['scope'] ?? '') {
			case 'all':
				return $all === [] ? $single : ['mode' => 'all', 'workspaces' => $all, 'tag' => '', 'key' => 'all'];
			case 'tag':
				$tag = is_string($params['tag'] ?? null) ? trim($params['tag']) : '';
				if ($tag === '') {
					return $single;
				}
				$needle = mb_strtolower($tag);
				$matches = array_values(array_filter($all, static fn (string $k) => in_array($needle, array_map('mb_strtolower', $tags[$k] ?? []), true)));
				// auch ohne Treffer: die Seite sagt es
				return ['mode' => 'tag', 'workspaces' => $matches, 'tag' => $tag, 'key' => 'tag:' . $needle];
			case 'list':
				$wanted = $params['ws'] ?? [];
				$wanted = array_map('strval', array_filter(is_array($wanted) ? $wanted : [$wanted], 'is_scalar'));
				$matches = array_values(array_filter($all, static fn (string $k) => in_array($k, $wanted, true)));
				if (count($matches) > 1) {
					return ['mode' => 'list', 'workspaces' => $matches, 'tag' => '', 'key' => 'list:' . implode('|', $matches)];
				}
				return $single;
		}
		return $single;
	}

	/** @return array{folders: list<array<string, mixed>>, notes: list<array<string, mixed>>} Ordnerbaum und lose Notizen eines Workspace */
	private function board(string $uid, int $wsId, string $key, bool $favoritesOnly): array {
		$folders = [];
		$rows = array_values(array_filter($this->folders->findInWorkspace($wsId), static fn (Folder $f) => $f->getTrashedAt() === null));
		usort($rows, static fn (Folder $a, Folder $b) => [(int)$a->getDisplayOrder() > 0 ? 0 : 1, (int)$a->getDisplayOrder(), mb_strtolower((string)$a->getName())]
			<=> [(int)$b->getDisplayOrder() > 0 ? 0 : 1, (int)$b->getDisplayOrder(), mb_strtolower((string)$b->getName())]);
		$position = [];
		foreach ($rows as $i => $f) {
			$id = (int)$f->getId();
			$position[$id] = $i;
			$folders[$id] = [
				'id' => $id,
				'name' => trim((string)$f->getName()),
				'parent' => $f->getParentId(),
				'icon' => IndexViewService::iconClass((string)($f->getIcon() ?? ''), 'lucide-folder'),
				'color' => IndexViewService::iconColor((string)($f->getIconColor() ?? '')) ?: null,
				'cardColor' => (string)($f->getColor() ?? ''),
				'cardColorHex' => $this->palette->hex($f->getColor()),
				'pinned' => (int)$f->getPinned() > 0,
				'favorite' => $favoritesOnly && (int)$f->getFavorite() > 0,
				'notes' => [],
				'children' => [],
			];
		}
		foreach ($folders as $id => $f) {
			if ($f['parent'] !== null && isset($folders[$f['parent']])) {
				$folders[$f['parent']]['children'][] = $id;
			}
		}
		$notes = $this->notes->findList([$wsId]);
		if ($favoritesOnly) {
			// Favorit oder in einem Favoriten-Ordner (Upstream)
			$notes = array_values(array_filter($notes, static fn (Note $n) => (int)$n->getFavorite() > 0
				|| ($n->getFolderId() !== null && ($folders[(int)$n->getFolderId()]['favorite'] ?? false))));
		}
		usort($notes, static fn (Note $a, Note $b) => [(int)$b->getUpdatedAt(), (int)$b->getId()] <=> [(int)$a->getUpdatedAt(), (int)$a->getId()]);
		$cards = $this->cards($uid, $notes, $key);
		$loose = [];
		foreach ($notes as $rank => $n) {
			$card = $cards[(int)$n->getId()] + ['globalOrder' => $rank, 'dashboardOrder' => (int)$n->getDashboardOrder()];
			$fid = $n->getFolderId();
			if ($fid !== null && isset($folders[(int)$fid])) {
				$folders[(int)$fid]['notes'][] = $card;
			} else {
				$loose[] = $card;
			}
		}
		$roots = array_values(array_filter(array_keys($folders), static fn (int $id) => $folders[$id]['parent'] === null || !isset($folders[$folders[$id]['parent']])));
		$out = [];
		foreach ($roots as $id) {
			if (self::hasNotes($id, $folders, [])) {
				$out[] = self::tree($id, $folders, $position, []);
			}
		}
		return ['folders' => $out, 'notes' => self::pinnedFirst(self::sortCards($loose))];
	}

	/** @param array<int, array<string, mixed>> $folders */
	private static function hasNotes(int $id, array $folders, array $seen): bool {
		if (isset($seen[$id])) {
			return false;
		}
		$seen[$id] = true;
		if ($folders[$id]['favorite'] || $folders[$id]['notes'] !== []) {
			return true;
		}
		foreach ($folders[$id]['children'] as $child) {
			if (self::hasNotes($child, $folders, $seen)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array<int, array<string, mixed>> $folders
	 * @param array<int, int> $position
	 * @return array<string, mixed>
	 */
	private static function tree(int $id, array $folders, array $position, array $seen): array {
		$seen[$id] = true;
		$f = $folders[$id];
		$children = $f['children'];
		usort($children, static fn (int $a, int $b) => ($position[$a] ?? 0) <=> ($position[$b] ?? 0));
		$sub = [];
		foreach ($children as $child) {
			if (!isset($seen[$child]) && self::hasNotes($child, $folders, $seen)) {
				$sub[] = self::tree($child, $folders, $position, $seen);
			}
		}
		return [
			'id' => $id,
			'name' => $f['name'],
			'icon' => $f['icon'],
			'color' => $f['color'],
			'cardColor' => $f['cardColor'],
			'cardColorHex' => $f['cardColorHex'],
			'pinned' => $f['pinned'],
			'folders' => $sub,
			'notes' => self::pinnedFirst(self::sortCards($f['notes'])),
		];
	}

	/**
	 * Upstream dashboardSortRows: abgelegte Karten (dashboard_order > 0) in ihrer Reihenfolge nach den übrigen (neueste zuerst)
	 * @param list<array<string, mixed>> $cards
	 * @return list<array<string, mixed>>
	 */
	private static function sortCards(array $cards): array {
		usort($cards, static function (array $a, array $b): int {
			$oa = $a['dashboardOrder'];
			$ob = $b['dashboardOrder'];
			if ($oa > 0 && $ob > 0) {
				return ($oa <=> $ob) ?: ($a['id'] <=> $b['id']);
			}
			if ($oa > 0 || $ob > 0) {
				return $oa > 0 ? 1 : -1;
			}
			return $a['globalOrder'] <=> $b['globalOrder'];
		});
		return array_map(static function (array $c): array {
			unset($c['dashboardOrder']);
			return $c;
		}, $cards);
	}

	/**
	 * Upstream dashboardSortPinnedFirst: angeheftete zuerst, baseOrder = Rang davor
	 * @param list<array<string, mixed>> $cards
	 * @return list<array<string, mixed>>
	 */
	private static function pinnedFirst(array $cards): array {
		$pinned = [];
		$rest = [];
		foreach ($cards as $i => $c) {
			$c['baseOrder'] = $i;
			if ($c['pinned']) {
				$pinned[] = $c;
			} else {
				$rest[] = $c;
			}
		}
		return array_merge($pinned, $rest);
	}

	/**
	 * Upstream dashboardBuildNoteData: Karte je Notiz; Vorschau aus dem Anfang des Inhalts (eine Abfrage je Länge)
	 * @param Note[] $notes
	 * @return array<int, array<string, mixed>>
	 */
	private function cards(string $uid, array $notes, string $key): array {
		$ids = array_map(static fn (Note $n) => (int)$n->getId(), $notes);
		$tasklists = array_map(static fn (Note $n) => (int)$n->getId(), array_filter($notes, static fn (Note $n) => $n->getType() === 'tasklist'));
		$heads = $this->notes->contentPrefixes(array_diff($ids, $tasklists), self::PREVIEW_BYTES) + $this->notes->contentPrefixes($tasklists, self::TASKLIST_BYTES);
		$tagMap = $this->tags->tagsFor($ids);
		$tz = $this->timeZone->getTimeZone(false, $uid);
		$untitled = $this->tr->t('common.untitled', [], 'Untitled');
		$out = [];
		foreach ($notes as $n) {
			$id = (int)$n->getId();
			$type = (string)($n->getType() ?: 'note');
			$heading = trim((string)($n->getHeading() ?? ''));
			$preview = $this->preview($heads[$id] ?? '', $type);
			$updated = (int)$n->getUpdatedAt();
			$out[$id] = [
				'id' => $id,
				'heading' => $heading !== '' ? $heading : $untitled,
				'type' => $type,
				'url' => 'index.php?' . http_build_query(['note' => $id, 'newtab' => 1, 'workspace' => $key]),
				'text' => $preview['text'],
				'tasks' => $preview['tasks'],
				'image' => $preview['image'],
				'tags' => array_values(array_filter($tagMap[$id] ?? [], static fn (string $t) => !TagService::isInternal($t))),
				'updated' => (new \DateTimeImmutable('@' . $updated))->setTimezone($tz)->format('Y-m-d'),
				'updatedAt' => $updated,
				'workspace' => $key,
				'icon' => trim((string)($n->getIcon() ?? '')) !== '' ? IndexViewService::iconClass((string)$n->getIcon(), 'lucide-file-text') : '',
				'iconColor' => IndexViewService::iconColor((string)($n->getIconColor() ?? '')),
				'color' => (string)($n->getColor() ?? ''),
				'colorHex' => $this->palette->hex($n->getColor()),
				'pinned' => (int)$n->getPinned() > 0,
			];
		}
		return $out;
	}

	/**
	 * Upstream buildNoteCardPreview (ohne Suchtext): Auszug, Aufgaben (höchstens 10), erstes Bild (Anhang oder kleines data:)
	 * @return array{text: string, tasks: ?list<array{text: string, done: bool}>, image: ?string}
	 */
	private function preview(string $head, string $type): array {
		if ($head === '') {
			return ['text' => '', 'tasks' => null, 'image' => null];
		}
		if ($type === 'tasklist') {
			$tasks = [];
			foreach (TaskService::cleanTasks($head) as $t) {
				$label = trim((string)$t['text']);
				if ($label !== '' && count($tasks) < 10) {
					$tasks[] = ['text' => $label, 'done' => (bool)$t['completed']];
				}
			}
			return ['text' => '', 'tasks' => $tasks, 'image' => null];
		}
		$image = null;
		if ($type === 'markdown' ? Pcre::match('/!\[[^\]]*+\]\(\s*([^)\s]++)/', $head, $m) : Pcre::match('/<img\b[^>]*?\bsrc=["\']([^"\']++)["\']/i', $head, $m)) {
			$src = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
			// nur eigene Anhänge (Recht prüft der Abruf) oder kleine data:-Bilder; fremde Hosts blockiert die CSP ohnehin
			if (Pcre::match('#^/api/v1/notes/\d+/attachments/\d+$#', $src)) {
				$image = $this->urls->toClient($src);
			} elseif (strlen($src) < self::MAX_DATA_URI && Pcre::match('#^data:image/(?:png|jpe?g|gif|webp);base64,[A-Za-z0-9+/=]+$#i', $src)) {
				$image = $src;
			}
		}
		$text = $this->text->plainText($type === 'markdown' ? $head : str_replace(['<br', '</p>', '</div>', '</li>'], [' <br', ' </p>', ' </div>', ' </li>'], $head), $type);
		if (mb_strlen($text) > self::EXCERPT) {
			$text = rtrim(mb_substr($text, 0, self::EXCERPT)) . '…';
		}
		return ['text' => $text, 'tasks' => null, 'image' => $image];
	}
}
