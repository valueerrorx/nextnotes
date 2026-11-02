<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Search;

use OCA\NextNotes\AppInfo\Application;
use OCA\NextNotes\Service\SearchService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;

// Unified Search: nur Notizen mit Leserecht, nicht im Papierkorb; Cursor = Versatz
class NotesProvider implements IProvider {
	public function __construct(
		private SearchService $search,
		private IURLGenerator $urls,
		private IL10N $l,
	) {
	}

	public function getId(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return $this->l->t('Next Notes');
	}

	public function getOrder(string $route, array $routeParameters): ?int {
		return str_starts_with($route, Application::APP_ID . '.') ? -1 : 30;
	}

	public function search(IUser $user, ISearchQuery $query): SearchResult {
		$offset = max(0, (int)$query->getCursor());
		[$hits, $next] = $this->search->provider($user->getUID(), $query->getTerm(), max(1, $query->getLimit()), $offset);
		$base = $this->urls->linkToRoute('nextnotes.page.indexphp');
		$icon = $this->urls->imagePath(Application::APP_ID, 'app-dark.svg');
		$entries = [];
		foreach ($hits as $hit) {
			$note = $hit['note'];
			$params = ['note' => (int)$note->getId()];
			if ($hit['workspace'] !== null) {
				$params['workspace'] = $hit['workspace'];
			}
			$entry = new SearchResultEntry('', (string)$note->getHeading(), $hit['excerpt'], $base . '?' . http_build_query($params), $icon);
			$entry->addAttribute('noteId', (string)$note->getId());
			$entries[] = $entry;
		}
		return SearchResult::paginated($this->getName(), $entries, $next);
	}
}
