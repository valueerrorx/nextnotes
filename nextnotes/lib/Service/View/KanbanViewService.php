<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service\View;

use OCA\NextNotes\Service\NotePalette;
use OCA\NextNotes\Db\Folder;
use OCA\NextNotes\Db\Note;
use OCA\NextNotes\Db\NoteMapper;
use OCA\NextNotes\Db\NoteTagMapper;
use OCA\NextNotes\Db\ReminderMapper;
use OCA\NextNotes\Service\AccessService;
use OCA\NextNotes\Service\Exception\NotFoundException;
use OCA\NextNotes\Service\FolderService;
use OCA\NextNotes\Service\NoteService;
use OCA\NextNotes\Service\Pcre;
use OCA\NextNotes\Service\SearchTextNormalizer;
use OCA\NextNotes\Service\SettingsService;
use OCA\NextNotes\Service\TagService;
use OCA\NextNotes\Service\TaskService;
use OCA\NextNotes\Service\WorkspaceService;
use OCP\IDateTimeZone;

/** Kanban-Ansicht eines Ordners (Upstream kanban_content.php): Unterordner = Spalten, tiefere = Gruppen */
class KanbanViewService {
	private const MAX_DEPTH = 10;
	private const EXCERPT = 220;
	// Vorschau nur aus dem Anfang der Notiz (Plan M4b): Aufwand je Karte begrenzt
	private const PREVIEW_BYTES = 4096;

	public function __construct(
		private AccessService $access,
		private WorkspaceService $workspaces,
		private FolderService $folders,
		private NoteMapper $notes,
		private NoteTagMapper $tags,
		private ReminderMapper $reminders,
		private SettingsService $settings,
		private SearchTextNormalizer $text,
		private IDateTimeZone $timeZone,
		private NotePalette $palette,
	) {
	}

	/**
	 * @return array<string, mixed>
	 * @throws NotFoundException fremder/unbekannter Ordner oder Workspace-Schlüssel passt nicht
	 */
	public function build(string $uid, mixed $rawFolderId, mixed $wsKey): array {
		if (!is_string($rawFolderId) || !ctype_digit($rawFolderId)) {
			throw new NotFoundException('Folder not found');
		}
		$root = $this->access->requireFolder($uid, (int)$rawFolderId, 'read');
		$wsId = (int)$root->getWorkspaceId();
		if (is_string($wsKey) && $wsKey !== '' && $this->workspaces->resolveOptional($uid, $wsKey)?->getId() !== $wsId) {
			throw new NotFoundException('Folder not found');
		}
		$canWrite = $this->access->workspaceLevel($uid, $this->access->requireWorkspace($uid, $wsId, 'read')) >= AccessService::WRITE;
		$byId = $this->folders->byId($wsId);
		$ids = FolderService::subtree((int)$root->getId(), $byId);
		$notes = $this->notes->findList([$wsId], ['folder_ids' => $ids], true);
		$noteIds = array_map(static fn (Note $n) => (int)$n->getId(), $notes);

		// Verknüpfungen zeigen Inhalt/Tags/Farbe/Erinnerung des Ziels, nur wenn lesbar
		$targetIds = [];
		foreach ($notes as $n) {
			if ($n->getType() === 'linked' && $n->getLinkedNoteId() !== null) {
				$targetIds[] = (int)$n->getLinkedNoteId();
			}
		}
		$targets = [];
		$found = $targetIds === [] ? [] : $this->notes->findByIds($targetIds);
		foreach ($this->access->filterReadable($uid, $found) as $t) {
			if ($t->getTrashedAt() === null) {
				$targets[(int)$t->getId()] = $t;
			}
		}
		$allIds = array_merge($noteIds, array_keys($targets));
		$tagMap = $this->tags->tagsFor($allIds);
		$reminderMap = $this->reminders->pendingNoteTimes($uid, $allIds);
		$tagColors = json_decode($this->settings->read($uid, 'tag_colors'), true);
		$tagColors = is_array($tagColors) ? $tagColors : [];

		$byFolder = [];
		foreach ($notes as $n) {
			$byFolder[(int)$n->getFolderId()][] = $this->card($n, $targets, $tagMap, $reminderMap, $tagColors);
		}
		$children = [];
		foreach ($byId as $fid => $f) {
			if ($f->getParentId() !== null) {
				$children[(int)$f->getParentId()][] = $f;
			}
		}
		$node = function (Folder $f, int $depth, array $visited) use (&$node, $children, $byFolder): array {
			$id = (int)$f->getId();
			$kids = [];
			if ($depth < self::MAX_DEPTH) {
				foreach (self::sortFolders($children[$id] ?? []) as $c) {
					if (!isset($visited[(int)$c->getId()])) {
						$kids[] = $node($c, $depth + 1, $visited + [(int)$c->getId() => true]);
					}
				}
			}
			return ['folder' => self::folderView($f), 'cards' => self::rank($byFolder[$id] ?? []), 'children' => $kids];
		};
		$rootNode = $node($root, 0, [(int)$root->getId() => true]);
		return [
			'folder' => $rootNode['folder'],
			'cards' => $rootNode['cards'],
			'columns' => $rootNode['children'],
			'canWrite' => $canWrite,
		];
	}

	/** @return array<string, string|int> */
	private static function folderView(Folder $f): array {
		$color = (string)($f->getIconColor() ?? '');
		return [
			'id' => (int)$f->getId(),
			'name' => (string)$f->getName(),
			'iconClass' => IndexViewService::iconClass((string)($f->getIcon() ?? ''), 'lucide-folder'),
			'iconColorRaw' => IndexViewService::iconColor($color) !== '' ? $color : '',
			'iconColor' => IndexViewService::iconColor($color),
		];
	}

	// Upstream: display_order > 0 zuerst, dann Name ohne Groß/Klein
	/** @param Folder[] $list @return Folder[] */
	private static function sortFolders(array $list): array {
		usort($list, static function (Folder $a, Folder $b): int {
			$oa = (int)$a->getDisplayOrder();
			$ob = (int)$b->getDisplayOrder();
			return (($oa > 0 ? 0 : 1) <=> ($ob > 0 ? 0 : 1)) ?: ($oa <=> $ob)
				?: strcasecmp((string)$a->getName(), (string)$b->getName()) ?: ((int)$a->getId() <=> (int)$b->getId());
		});
		return $list;
	}

	// Reihenfolge: erledigt zuletzt erledigt oben, sonst neueste zuerst; Rang für die Sortierung „Eigene“ (kanban.js)
	/** @param list<array<string, mixed>> $cards @return list<array<string, mixed>> */
	private static function rank(array $cards): array {
		$placed = $cards;
		usort($placed, static fn (array $a, array $b) => NoteService::comparePlaced((int)$a['note']->getKanbanOrder(), (int)$b['note']->getKanbanOrder(), $a['note'], $b['note']));
		$ranks = [];
		foreach ($placed as $i => $c) {
			$ranks[$c['id']] = $i + 1;
		}
		usort($cards, static fn (array $a, array $b) => ((int)$b['note']->getKanbanCompleted() <=> (int)$a['note']->getKanbanCompleted())
			?: ((int)$b['note']->getUpdatedAt() <=> (int)$a['note']->getUpdatedAt()) ?: ($b['id'] <=> $a['id']));
		foreach ($cards as &$c) {
			$c['rank'] = $ranks[$c['id']];
			unset($c['note']);
		}
		return $cards;
	}

	/**
	 * @param array<int, Note> $targets
	 * @param array<int, string[]> $tagMap
	 * @param array<int, int> $reminderMap
	 * @param array<string, mixed> $tagColors
	 * @return array<string, mixed>
	 */
	private function card(Note $n, array $targets, array $tagMap, array $reminderMap, array $tagColors): array {
		$id = (int)$n->getId();
		$target = $n->getType() === 'linked' && $n->getLinkedNoteId() !== null ? ($targets[(int)$n->getLinkedNoteId()] ?? null) : null;
		$source = $target ?? $n;
		$type = (string)($source->getType() ?: 'note');
		$tags = $tagMap[$id] ?? [];
		if ($tags === [] && $target !== null) {
			$tags = $tagMap[(int)$target->getId()] ?? [];
		}
		$tags = array_values(array_filter($tags, static fn ($t) => !TagService::isInternal((string)$t)));
		$ownColor = (string)($n->getColor() ?? '');
		$color = $ownColor !== '' || $target === null ? $ownColor : (string)($target->getColor() ?? '');
		$reminder = $reminderMap[$id] ?? ($target !== null ? ($reminderMap[(int)$target->getId()] ?? null) : null);
		$reminderDue = $reminder !== null ? $this->local($reminder, 'Y-m-d\TH:i') : '';
		$content = (string)($source->getContent() ?? '');
		$tasks = null;
		$taskDue = '';
		$excerpt = [];
		if ($type === 'tasklist') {
			$tasks = [];
			foreach (TaskService::cleanTasks($content) as $i => $t) {
				$tasks[] = ['index' => $i, 'id' => TaskService::idString($t['id']), 'text' => $t['text'], 'completed' => $t['completed'], 'important' => $t['important'],
					'subtasks' => array_map(static fn (int $si, array $s) => ['index' => $si, 'id' => TaskService::idString($s['id']), 'text' => $s['text'], 'completed' => $s['completed']],
						array_keys($t['subtasks'] ?? []), $t['subtasks'] ?? [])];
				if (!$t['completed'] && $t['dueAt'] !== null && ($taskDue === '' || $t['dueAt'] < $taskDue)) {
					$taskDue = $t['dueAt'];
				}
			}
		} else {
			$excerpt = $this->excerpt($content, $type);
		}
		$due = $taskDue !== '' ? $taskDue : $reminderDue;
		$now = $this->local(time(), 'Y-m-d\TH:i');
		$overdue = $due !== '' && (strlen($due) > 10 ? $due < $now : $due < substr($now, 0, 10));
		$colors = [];
		foreach ($tags as $tag) {
			$raw = $tagColors[$tag] ?? null;
			$colors[] = is_string($raw) ? $this->palette->hex($raw) : '';
		}
		return [
			'note' => $n,
			'id' => $id,
			'title' => (string)($n->getHeading() ?? ''),
			'completed' => (int)$n->getKanbanCompleted() > 0,
			'colorRaw' => $ownColor,
			'colorHex' => $this->palette->hex($color),
			'tags' => $tags,
			'tagColors' => $colors,
			'updated' => $n->getUpdatedAt() !== null ? $this->local((int)$n->getUpdatedAt(), 'd/m H:i') : '',
			'reminderDue' => $reminderDue,
			'due' => $due,
			'dueSource' => $taskDue !== '' ? 'task' : 'reminder',
			'dueLabel' => $due === '' ? '' : sprintf('%02d/%02d', (int)substr($due, 8, 2), (int)substr($due, 5, 2)) . (strlen($due) > 10 ? ' ' . substr($due, 11, 5) : ''),
			'overdue' => $overdue,
			'tasks' => $tasks,
			'taskNoteId' => (int)$source->getId(),
			'excerpt' => $excerpt,
		];
	}

	/**
	 * Vorschau mit klickbaren Links (Upstream buildNoteCardPreview + renderKanbanSnippet, #1672): Linktext zwischen \x02/\x03
	 * @return list<array{text: string, href: ?string, noteId: ?int}>
	 */
	private function excerpt(string $content, string $type): array {
		$head = str_replace(["\x02", "\x03"], '', mb_strcut($content, 0, self::PREVIEW_BYTES, 'UTF-8'));
		$links = [];
		if ($type === 'markdown') {
			$destination = '\((?:[^()]++|\([^()]*+\))*+\)';
			$head = Pcre::replace('/!\[[^\]]*+\]' . $destination . '/', ' ', $head);
			$head = Pcre::replaceCallback('/\[([^\]]*+)\](' . $destination . ')/', static function (array $m) use (&$links): string {
				$links[] = trim((string)(Pcre::split('/\s+/', trim(substr($m[2], 1, -1)))[0] ?? ''), '<>');
				return "\x02" . $m[1] . "\x03";
			}, $head);
		} else {
			// nur bis zum letzten </a>: Öffner dahinter finden kein Ende (linear, vgl. B46)
			$cut = strripos($head, '</a>');
			if ($cut !== false) {
				$cut += 4;
				$head = Pcre::replaceCallback('/<a\b([^>]*+)>((?:[^<]++|<(?!\/a>))*+)<\/a>/i', static function (array $m) use (&$links): string {
					$href = '';
					if (Pcre::match('/\bdata-note-id\s*=\s*["\']?(\d+)/i', $m[1], $h)) {
						$href = 'index.php?note=' . $h[1];
					} elseif (Pcre::match('/\bhref\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $m[1], $h)) {
						$href = html_entity_decode($h[1] !== '' ? $h[1] : ($h[2] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
					}
					$links[] = $href;
					return "\x02" . $m[2] . "\x03";
				}, substr($head, 0, $cut)) . substr($head, $cut);
			}
		}
		$plain = $this->text->plainText($head, $type);
		$out = [];
		$visible = 0;
		$href = null;
		$noteId = null;
		$i = 0;
		foreach (Pcre::split('/([\x02\x03])/', $plain, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $part) {
			if ($part === "\x02" || $part === "\x03") {
				[$href, $noteId] = $part === "\x02" ? self::linkTarget((string)($links[$i++] ?? '')) : [null, null];
				continue;
			}
			$room = self::EXCERPT - $visible;
			$cutText = mb_strlen($part) > $room;
			$part = $cutText ? mb_substr($part, 0, $room) : $part;
			$out[] = ['text' => $part, 'href' => $href, 'noteId' => $noteId];
			$visible += mb_strlen($part);
			if ($cutText) {
				$out[] = ['text' => '...', 'href' => null, 'noteId' => null];
				break;
			}
		}
		return $out;
	}

	/** @return array{0: ?string, 1: ?int} interne Notiz (index.php?note=ID) oder http(s)/mailto; sonst kein Link */
	private static function linkTarget(string $href): array {
		if (Pcre::match('/(?:^|\/)index\.php\?[^#\s]*\bnote=(\d+)\b|^\?[^#\s]*\bnote=(\d+)\b/', $href, $m)) {
			$id = (int)(($m[1] ?? '') !== '' ? $m[1] : $m[2]);
			return ['index.php?note=' . $id, $id];
		}
		return Pcre::match('/^(https?:\/\/|mailto:)/i', $href) ? [$href, null] : [null, null];
	}

	private function local(int $ts, string $format): string {
		return (new \DateTimeImmutable('@' . $ts))->setTimezone($this->timeZone->getTimeZone())->format($format);
	}
}
