<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service;

use OCA\NextNotes\Db\Note;
use OCA\NextNotes\Db\NoteMapper;
use OCA\NextNotes\Db\NoteTagMapper;
use OCA\NextNotes\Service\Exception\BadRequestException;
use OCA\NextNotes\Service\Exception\NotFoundException;
use OCP\IDateTimeZone;

// Suche (Upstream Notes::search, searchIds, resolveReference) und Unified Search; nur sichtbare Workspaces
class SearchService {
	// Obergrenze Suchbegriffe der Hauptansicht (eine LIKE-Bedingung je Begriff)
	public const MAX_TERMS = 20;

	public function __construct(
		private NoteMapper $notes,
		private NoteTagMapper $tags,
		private AccessService $access,
		private WorkspaceService $workspaces,
		private NoteService $noteService,
		private NoteSerializer $serializer,
		private SearchTextNormalizer $normalizer,
		private IDateTimeZone $timeZone,
	) {
	}

	/** @return int[] */
	private function scope(string $uid, mixed $wsKey): array {
		$ws = $this->workspaces->resolveOptional($uid, $wsKey);
		return $ws !== null ? [(int)$ws->getId()] : $this->access->visibleWorkspaceIds($uid);
	}

	private function dateFilter(string $raw, bool $end): ?int {
		$raw = trim($raw);
		if ($raw === '') {
			return null;
		}
		if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
			throw new BadRequestException(($end ? 'created_to' : 'created_from') . ' must use YYYY-MM-DD format');
		}
		return (new \DateTime($raw . ($end ? ' 23:59:59' : ' 00:00:00'), $this->timeZone->getTimeZone()))->getTimestamp();
	}

	/**
	 * Treffer (Titel/Text, Akzente und Groß/Klein egal), Titeltreffer zuerst, dann neueste
	 * @param int[] $wsIds
	 * @param array<string, int> $dates
	 * @return Note[]
	 */
	private function matches(array $wsIds, string $query, array $dates = []): array {
		$folded = $this->normalizer->fold(trim($query));
		$found = $this->notes->findList($wsIds, ['search' => $folded] + $dates);
		usort($found, function (Note $a, Note $b) use ($folded) {
			$ha = str_contains($this->normalizer->fold((string)$a->getHeading()), $folded) ? 0 : 1;
			$hb = str_contains($this->normalizer->fold((string)$b->getHeading()), $folded) ? 0 : 1;
			return ($ha <=> $hb) ?: ((int)$b->getUpdatedAt() <=> (int)$a->getUpdatedAt()) ?: ((int)$b->getId() <=> (int)$a->getId());
		});
		return $found;
	}

	private function excerpt(Note $n): string {
		$text = $this->normalizer->plainText((string)$n->getContent(), (string)$n->getType());
		return mb_strlen($text) >= 300 ? mb_substr($text, 0, 300) . '...' : $text;
	}

	/** @return array<string, mixed> GET /notes/search */
	public function search(string $uid, array $q): array {
		$query = is_scalar($q['q'] ?? null) ? (string)$q['q'] : '';
		if (trim($query) === '') {
			throw new BadRequestException('Search query (q) is required');
		}
		$limit = isset($q['limit']) && is_scalar($q['limit']) ? max(1, min(100, (int)$q['limit'])) : 10;
		$from = $this->dateFilter((string)($q['created_from'] ?? ''), false);
		$to = $this->dateFilter((string)($q['created_to'] ?? ''), true);
		if ($from !== null && $to !== null && $from > $to) {
			throw new BadRequestException('created_from must be before or equal to created_to');
		}
		$dates = array_filter(['created_from' => $from, 'created_to' => $to], static fn ($v) => $v !== null);
		$page = array_slice($this->matches($this->scope($uid, $q['workspace'] ?? null), $query, $dates), 0, $limit);
		$full = [];
		foreach ($this->notes->findByIds(array_map(static fn (Note $n) => (int)$n->getId(), $page)) as $n) {
			$full[(int)$n->getId()] = $n;
		}
		$tags = $this->tags->tagsFor(array_keys($full));
		$results = [];
		foreach ($page as $n) {
			$id = (int)$n->getId();
			$results[] = [
				'id' => $id,
				'heading' => $n->getHeading() ?? 'Untitled',
				'tags' => $this->serializer->tagString($tags[$id] ?? []),
				'folder' => $this->serializer->folderName($n->getFolderId()),
				'folder_id' => $n->getFolderId(),
				'workspace' => $this->workspaces->keyFor($uid, (int)$n->getWorkspaceId()),
				'excerpt' => isset($full[$id]) ? $this->excerpt($full[$id]) : '',
				'updated' => NoteSerializer::date($n->getUpdatedAt()),
				'created' => NoteSerializer::date($n->getCreatedAt()),
			];
		}
		return ['query' => $query, 'count' => count($results), 'results' => $results];
	}

	/** @return array{ids: int[]} GET /notes/search/ids: jedes Wort in Titel, Tags oder Text */
	public function ids(string $uid, array $q): array {
		$query = is_scalar($q['q'] ?? null) ? trim((string)$q['q']) : '';
		if ($query === '') {
			throw new BadRequestException('Search query (q) is required');
		}
		$words = preg_split('/\s+/u', $this->normalizer->fold($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];
		$notes = $this->notes->findList($this->scope($uid, $q['workspace'] ?? null), [], true);
		$tags = $this->tags->tagsFor(array_map(static fn (Note $n) => (int)$n->getId(), $notes));
		$ids = [];
		foreach ($notes as $n) {
			$id = (int)$n->getId();
			$haystack = (string)$n->getSearchText() . ' ' . $this->normalizer->fold(implode(' ', $tags[$id] ?? []));
			foreach ($words as $word) {
				if (!str_contains($haystack, $word)) {
					continue 2;
				}
			}
			$ids[] = $id;
		}
		return ['ids' => $ids];
	}

	/**
	 * Suche der Hauptansicht (Upstream search_handler.php, initializeSearchParams): POST-Formular oder GET (Tag-Klick, Reload)
	 * @param Note[] $notes nicht gelöschte Notizen eines Workspace
	 * @param array<int, string[]> $tagMap Tags je Notiz-ID (vom Aufrufer, einmal je Seitenaufruf)
	 * @param array<string, mixed> $q Formular-/Query-Parameter
	 * @return array{active: bool, search: string, tags: string, from: string, to: string, inNotes: bool, inTags: bool, combined: bool, ids: array<int, true>}
	 */
	public function pageSearch(array $notes, array $tagMap, array $q): array {
		$str = static fn (string $k): string => is_scalar($q[$k] ?? null) ? trim((string)$q[$k]) : '';
		$search = $str('search');
		$tags = $str('tags_search');
		$combined = $str('search_combined') === '1';
		$unified = $str('unified_search');
		if ($unified !== '') {
			// Upstream handleUnifiedSearch: Suchfeld schlägt die versteckten Felder
			$inNotes = $str('search_in_notes') !== '';
			$inTags = $str('search_in_tags') !== '';
			if ($combined || ($inNotes && $inTags)) {
				[$search, $tags, $combined] = [$unified, $unified, true];
			} elseif ($inNotes || $inTags) {
				[$search, $tags] = [$inNotes ? $unified : '', $inTags ? $unified : ''];
			}
		}
		// ungültiges Datum = kein Filter (Upstream normalizeDateOnlyFilter)
		$date = function (string $raw, bool $end): ?int {
			try {
				return $this->dateFilter($raw, $end);
			} catch (BadRequestException) {
				return null;
			}
		};
		$from = $date($str('created_from'), false);
		$to = $date($str('created_to'), true);
		$inNotes = $search !== '' || $str('preserve_notes') === '1' || $combined;
		$inTags = $tags !== '' || $str('preserve_tags') === '1' || $combined;
		if (!$inNotes && !$inTags) {
			[$inNotes, $inTags] = [true, true];
		}
		$state = [
			'active' => $search !== '' || $tags !== '' || $from !== null || $to !== null,
			'search' => $search,
			'tags' => $tags,
			'from' => $from !== null ? $str('created_from') : '',
			'to' => $to !== null ? $str('created_to') : '',
			'inNotes' => $inNotes,
			'inTags' => $inTags,
			'combined' => $inNotes && $inTags,
			'ids' => [],
		];
		if (!$state['active']) {
			return $state;
		}
		$byId = [];
		foreach ($notes as $n) {
			if (($from === null || (int)$n->getCreatedAt() >= $from) && ($to === null || (int)$n->getCreatedAt() <= $to)) {
				$byId[(int)$n->getId()] = $n;
			}
		}
		$noteHits = null;
		if ($search !== '') {
			// jedes Wort bzw. "Phrase" in Titel oder Text (search_text: gefaltet); höchstens MAX_TERMS Begriffe, Rest ignoriert
			preg_match_all('/"([^"]+)"|\S+/u', $search, $m, PREG_SET_ORDER);
			$terms = array_map(fn (array $t) => $this->normalizer->fold($t[1] ?? $t[0]), array_slice($m, 0, self::MAX_TERMS));
			$first = reset($byId);
			$noteHits = $first === false ? [] : array_fill_keys($this->notes->idsMatchingAll((int)$first->getWorkspaceId(), $terms), true);
		}
		$tagHits = null;
		if ($tags !== '') {
			$words = array_map(fn (string $w) => $this->normalizer->fold($w), preg_split('/[,\s]+/u', $tags, -1, PREG_SPLIT_NO_EMPTY) ?: []);
			$tagHits = [];
			foreach (array_intersect_key($tagMap, $byId) as $id => $list) {
				$haystack = $this->normalizer->fold(implode(',', $list));
				foreach ($words as $w) {
					if (!str_contains($haystack, $w)) {
						continue 2;
					}
				}
				$tagHits[(int)$id] = true;
			}
		}
		if ($noteHits === null && $tagHits === null) {
			$ids = array_fill_keys(array_keys($byId), true);
		} elseif ($noteHits === null || $tagHits === null) {
			$ids = $noteHits ?? $tagHits;
		} else {
			$ids = $combined ? $noteHits + $tagHits : array_intersect_key($noteHits, $tagHits);
		}
		$state['ids'] = array_intersect_key($ids, $byId);
		return $state;
	}

	/** @return array{id: int, heading: ?string, workspace: ?string} GET /notes/resolve */
	public function resolve(string $uid, mixed $reference, mixed $wsKey): array {
		if (!is_scalar($reference) || trim((string)$reference) === '') {
			throw new BadRequestException('No reference provided');
		}
		$reference = (string)$reference;
		$ws = $this->workspaces->resolveOptional($uid, $wsKey);
		if (ctype_digit($reference)) {
			$note = $this->access->requireNote($uid, (int)$reference, 'read');
			if ($ws !== null && $ws->getId() !== $note->getWorkspaceId()) {
				throw new NotFoundException('Note not found');
			}
		} else {
			$id = $this->noteService->findIdByTitle($ws !== null ? [(int)$ws->getId()] : $this->access->visibleWorkspaceIds($uid), $reference, false);
			if ($id === null) {
				throw new NotFoundException('Note not found');
			}
			$note = $this->access->requireNote($uid, $id, 'read');
		}
		return ['id' => (int)$note->getId(), 'heading' => $note->getHeading(), 'workspace' => $this->workspaces->keyFor($uid, (int)$note->getWorkspaceId())];
	}

	/**
	 * Unified Search: neueste zuerst, Cursor = Versatz
	 * @return array{0: list<array{note: Note, excerpt: string, workspace: ?string}>, 1: int} Treffer, nächster Versatz
	 */
	public function provider(string $uid, string $term, int $limit, int $offset): array {
		$folded = $this->normalizer->fold(trim($term));
		if ($folded === '') {
			return [[], $offset];
		}
		$found = $this->notes->findList($this->access->visibleWorkspaceIds($uid), ['search' => $folded]);
		usort($found, static fn (Note $a, Note $b) => ((int)$b->getUpdatedAt() <=> (int)$a->getUpdatedAt()) ?: ((int)$b->getId() <=> (int)$a->getId()));
		$page = array_slice($found, $offset, $limit);
		$full = [];
		foreach ($this->notes->findByIds(array_map(static fn (Note $n) => (int)$n->getId(), $page)) as $n) {
			$full[(int)$n->getId()] = $n;
		}
		$out = [];
		foreach ($page as $n) {
			$out[] = [
				'note' => $n,
				'excerpt' => isset($full[(int)$n->getId()]) ? mb_substr($this->excerpt($full[(int)$n->getId()]), 0, 120) : '',
				'workspace' => $this->workspaces->keyFor($uid, (int)$n->getWorkspaceId()),
			];
		}
		return [$out, $offset + count($page)];
	}
}
