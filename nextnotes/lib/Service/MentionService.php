<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service;

use OCA\NextNotes\Db\MentionHandledMapper;
use OCA\NextNotes\Db\Note;
use OCA\NextNotes\Db\NoteMapper;
use OCA\NextNotes\Db\Workspace;
use OCA\NextNotes\Db\WsShareMapper;
use OCA\NextNotes\Service\Exception\BadRequestException;
use OCA\NextNotes\Service\Exception\NotFoundException;
use OCP\AppFramework\Utility\ITimeFactory;

// „@uid“ in Notizen geteilter Workspaces (Upstream lib/mentions.php); gespeichert wird nur „erledigt“
class MentionService {
	public function __construct(
		private NoteMapper $notes,
		private WsShareMapper $shares,
		private MentionHandledMapper $handled,
		private AccessService $access,
		private WorkspaceService $workspaces,
		private SearchTextNormalizer $normalizer,
		private ITimeFactory $time,
	) {
	}

	/** @return array{open: int, mentions: list<array<string, mixed>>} GET /mentions */
	public function index(string $uid, bool $includeHandled): array {
		$mentions = $this->collect($uid, $includeHandled);
		$open = count(array_filter($mentions, static fn (array $m) => !$m['handled']));
		return ['open' => $open, 'mentions' => $mentions];
	}

	/** @return string[] Besitzer zuerst, dann Freigabeempfänger; leer ohne Freigabe */
	public function members(string $uid, mixed $wsKey): array {
		if (!is_string($wsKey) || trim($wsKey) === '') {
			throw new BadRequestException('workspace is required');
		}
		$ws = $this->workspaces->requireByKey($uid, trim($wsKey), 'read');
		$recipients = $this->shares->recipients((int)$ws->getId());
		if ($recipients === [] || $ws->getOwnerType() !== 'user') {
			return [];
		}
		return array_values(array_unique(array_merge([(string)$ws->getOwnerId()], $recipients)));
	}

	/** @return array{handled: bool, open: int} POST/DELETE /mentions/{ownerId}/{noteId}/handled */
	public function setHandled(string $uid, int $noteId, bool $handled): array {
		$note = $this->access->requireNote($uid, $noteId, 'read');
		$hit = null;
		foreach ($this->collect($uid, true, [(int)$note->getWorkspaceId()]) as $m) {
			if ($m['note_id'] === $noteId) {
				$hit = $m;
			}
		}
		if ($hit === null) {
			throw new NotFoundException('This note does not mention you');
		}
		if ($handled) {
			$this->handled->store($noteId, $uid, (int)$hit['count'], $this->time->getTime());
		} else {
			$this->handled->remove($noteId, $uid);
		}
		return ['handled' => $handled, 'open' => count($this->collect($uid, false))];
	}

	/**
	 * Notizen, die $uid in Workspaces mit Freigabe nennen; neueste zuerst
	 * @param int[]|null $onlyWs
	 * @return list<array<string, mixed>>
	 */
	private function collect(string $uid, bool $includeHandled, ?array $onlyWs = null): array {
		$visible = $this->access->visibleWorkspaceIds($uid);
		if ($onlyWs !== null) {
			$visible = array_values(array_intersect($visible, $onlyWs));
		}
		$wsIds = $this->shares->sharedWorkspaceIds($visible);
		if ($wsIds === []) {
			return [];
		}
		$counts = $this->handled->countsFor($uid);
		$byWs = [];
		foreach ($this->workspaces->listVisible($uid) as $ws) {
			$byWs[(int)$ws->getId()] = $ws;
		}
		$notes = $this->notes->findList($wsIds, ['search' => '@' . $this->normalizer->fold($uid)], true);
		usort($notes, static fn (Note $a, Note $b) => [(int)$b->getUpdatedAt(), (int)$b->getId()] <=> [(int)$a->getUpdatedAt(), (int)$a->getId()]);
		$out = [];
		foreach ($notes as $n) {
			$id = (int)$n->getId();
			$type = (string)($n->getType() ?: 'note');
			$text = self::plainText((string)$n->getContent(), $type);
			$count = self::count($text, $uid);
			if ($count === 0) {
				continue;
			}
			$handledCount = $counts[$id] ?? null;
			// Erledigte Nennungen entfernt: die nächste neue zählt wieder als offen
			if ($handledCount !== null && $count < $handledCount) {
				$this->handled->store($id, $uid, $count, $this->time->getTime());
				$handledCount = $count;
			}
			$open = $handledCount === null || $count > $handledCount;
			if (!$open && !$includeHandled) {
				continue;
			}
			$ws = $byWs[(int)$n->getWorkspaceId()] ?? null;
			$owner = $ws instanceof Workspace && $ws->getOwnerType() === 'user' ? (string)$ws->getOwnerId() : '';
			$out[] = [
				'owner_user_id' => $owner,
				'owner_username' => $owner,
				'workspace' => (string)$this->workspaces->keyFor($uid, (int)$n->getWorkspaceId()),
				'note_id' => $id,
				'title' => (string)($n->getHeading() ?? ''),
				'type' => $type,
				'excerpt' => self::excerpt($text, $uid),
				'count' => $count,
				'handled' => !$open,
				'updated' => NoteSerializer::date($n->getUpdatedAt()),
				'updated_by' => (string)($n->getUpdatedBy() ?? $owner),
			];
		}
		return $out;
	}

	// Upstream poznoteMentionPattern: kein Treffer in E-Mail-Adressen oder längeren Namen
	private static function pattern(string $uid): string {
		return '/(?<![\p{L}\p{N}_.@\/-])@' . preg_quote($uid, '/') . '(?![\p{L}\p{N}_-])(?!\.[\p{L}\p{N}])/iu';
	}

	public static function count(string $text, string $uid): int {
		if ($uid === '' || $text === '') {
			return 0;
		}
		return (int)preg_match_all(self::pattern($uid), $text);
	}

	// Upstream poznoteMentionPlainText
	public static function plainText(string $content, string $type): string {
		if ($type === 'markdown') {
			return $content;
		}
		if ($type === 'tasklist') {
			$tasks = json_decode($content, true);
			if (is_array($tasks)) {
				$texts = [];
				array_walk_recursive($tasks, static function ($v, $k) use (&$texts): void {
					if ($k === 'text' && is_string($v)) {
						$texts[] = $v;
					}
				});
				$content = implode("\n", $texts);
			}
		}
		$content = (string)preg_replace('/<(br|\/p|\/div|\/li|\/h[1-6]|\/td|\/th|\/tr|\/blockquote|\/pre)\b[^>]*>/i', "\n", $content);
		$content = (string)preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $content);
		return str_replace("\u{00A0}", ' ', html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
	}

	// Upstream poznoteMentionExcerpt
	public static function excerpt(string $text, string $uid, int $radius = 90): string {
		if ($uid === '' || !preg_match(self::pattern($uid), $text, $m, PREG_OFFSET_CAPTURE)) {
			return '';
		}
		$offset = (int)$m[0][1];
		$start = strrpos(substr($text, 0, $offset), "\n");
		$start = $start === false ? 0 : $start + 1;
		$end = strpos($text, "\n", $offset);
		$end = $end === false ? strlen($text) : $end;
		$before = (string)preg_replace('/\s+/u', ' ', substr($text, $start, $offset - $start));
		$after = (string)preg_replace('/\s+/u', ' ', substr($text, $offset, $end - $offset));
		if (mb_strlen($before, 'UTF-8') > $radius) {
			$before = '…' . ltrim(mb_substr($before, -$radius, null, 'UTF-8'));
		}
		if (mb_strlen($after, 'UTF-8') > $radius) {
			$after = rtrim(mb_substr($after, 0, $radius, 'UTF-8')) . '…';
		}
		return trim($before . $after);
	}
}
