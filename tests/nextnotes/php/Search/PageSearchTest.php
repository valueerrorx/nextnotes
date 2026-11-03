<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Search;

use OCA\NextNotes\Db\NoteMapper;
use OCA\NextNotes\Db\NoteTagMapper;
use OCA\NextNotes\Service\SearchService;
use OCA\NextNotes\Tests\Support\ApiTestCase;
use OCP\Server;

// M3b2 Suche der Hauptansicht (SearchService::pageSearch, NoteMapper::idsMatchingAll): Mehrwort UND, "Phrase",
// Tags UND, kombiniert ODER, Datumsfilter, % und _ nur wörtlich, > MAX_TERMS Begriffe ohne Fehler, nur eigener Workspace
final class PageSearchTest extends ApiTestCase {
	private int $wsId;
	private string $ws;
	/** @var array<string, int> */
	private array $n = [];

	private function setUpNotes(): void {
		$a = $this->createUser('ps');
		[$this->ws, $this->wsId] = self::newWorkspace($a, 'PS');
		$notes = [
			'ab' => ['Erste', '<p>alpha gamma beta</p>'],
			'phrase' => ['Zweite', '<p>alpha beta zusammen</p>'],
			'a' => ['Dritte', '<p>nur alpha</p>'],
			'pct' => ['Prozent', '<p>rabatt 50% heute</p>'],
			'num' => ['Zahl', '<p>rabatt 500 heute</p>'],
			'us' => ['Unterstrich', '<p>wert a_b hier</p>'],
			'ux' => ['Ohne', '<p>wert axb hier</p>'],
			'accent' => ['Élan', '<p>Café crème</p>'],
		];
		foreach ($notes as $k => [$h, $c]) {
			$this->n[$k] = self::newNote($a, $this->ws, $h, $c);
		}
		self::ok($a->call('PUT', '/notes/' . $this->n['a'] . '/tags', ['tags' => 'rot,blau', 'workspace' => $this->ws]), 'Tags a');
		self::ok($a->call('PUT', '/notes/' . $this->n['pct'] . '/tags', ['tags' => 'rot', 'workspace' => $this->ws]), 'Tags pct');
		// Datum: ab = 2024-01-10, phrase = 2024-03-01, Rest jetzt
		$db = self::db();
		foreach (['ab' => '2024-01-10 12:00:00', 'phrase' => '2024-03-01 12:00:00'] as $k => $d) {
			$qb = $db->getQueryBuilder();
			$qb->update('nextnotes_notes')->set('created_at', $qb->createNamedParameter((new \DateTime($d))->getTimestamp()))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($this->n[$k])))->executeStatement();
		}
		// zweiter Benutzer mit gleichem Wort: darf nie auftauchen
		$b = $this->createUser('psb');
		[$wsB] = self::newWorkspace($b, 'PSB');
		$this->n['foreign'] = self::newNote($b, $wsB, 'Fremd', '<p>alpha beta</p>');
	}

	/** @return string[] Schlüssel der Treffer, sortiert */
	private function hits(array $q): array {
		$notes = Server::get(NoteMapper::class)->findList([$this->wsId]);
		$tagMap = Server::get(NoteTagMapper::class)->tagsFor(array_map(static fn ($n) => (int)$n->getId(), $notes));
		$state = Server::get(SearchService::class)->pageSearch($notes, $tagMap, $q);
		$keys = [];
		foreach (array_keys($state['ids']) as $id) {
			$k = array_search((int)$id, $this->n, true);
			$keys[] = $k === false ? "id$id" : $k;
		}
		sort($keys);
		return $keys;
	}

	public function testSearchCases(): void {
		$this->setUpNotes();
		self::assertSame(['ab', 'phrase'], $this->hits(['search' => 'beta alpha']), 'Mehrwort = UND, Reihenfolge egal');
		self::assertSame(['phrase'], $this->hits(['search' => '"alpha beta"']), 'Phrase zusammenhängend');
		self::assertSame(['accent'], $this->hits(['search' => 'CAFE CREME']), 'Akzente/Groß-Klein gefaltet');
		self::assertSame(['pct'], $this->hits(['search' => '50%']), '% wörtlich');
		self::assertSame(['us'], $this->hits(['search' => 'a_b']), '_ wörtlich');
		self::assertSame(['pct'], $this->hits(['search' => '%']), '% allein nur wörtlich');
		self::assertSame(['us'], $this->hits(['search' => '_']), '_ allein nur wörtlich');
		self::assertSame(['a'], $this->hits(['tags_search' => 'rot blau']), 'Tags UND');
		self::assertSame(['a', 'pct'], $this->hits(['tags_search' => 'rot']), 'Tag rot');
		self::assertSame(['a', 'ab', 'pct', 'phrase'], $this->hits(['search' => 'alpha', 'tags_search' => 'rot', 'search_combined' => '1']), 'kombiniert = Text ODER Tag');
		self::assertSame(['a', 'ab', 'phrase'], $this->hits(['unified_search' => 'alpha', 'search_combined' => '1']), 'Suchfeld kombiniert');
		self::assertSame(['a'], $this->hits(['search' => 'alpha', 'tags_search' => 'rot']), 'nicht kombiniert = Text UND Tag');
		self::assertSame(['ab'], $this->hits(['search' => 'alpha', 'created_from' => '2024-01-01', 'created_to' => '2024-01-31']), 'Datumsfilter von/bis');
		self::assertSame(['ab', 'phrase'], $this->hits(['created_from' => '2024-01-01', 'created_to' => '2024-12-31']), 'nur Datum');
		self::assertContains('a', $this->hits(['search' => 'alpha', 'created_from' => '2024-13-45']), 'ungültiges Datum = kein Filter');
		$many = implode(' ', array_merge(array_fill(0, SearchService::MAX_TERMS, 'alpha'), array_map(static fn ($i) => "zz$i", range(1, 10))));
		self::assertSame(['a', 'ab', 'phrase'], $this->hits(['search' => $many]), '> MAX_TERMS: Rest ignoriert, kein Fehler');
		self::assertNotContains('foreign', $this->hits(['search' => 'alpha beta']), 'nie fremde Notizen');
	}
}
