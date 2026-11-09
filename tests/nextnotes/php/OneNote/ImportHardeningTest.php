<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\OneNote;

use OCA\NextNotes\Db\OneNoteImportMapper;
use OCA\NextNotes\Db\OneNoteImport;
use OCA\NextNotes\OneNote\GraphClient;
use OCA\NextNotes\OneNote\ImportRun;
use OCA\NextNotes\OneNote\OneNoteImportService;
use OCP\IL10N;
use OCP\Server;

// Review M9b-r1 Hinweise 2–7 (in M9d umgesetzt): IDs, claim fällig, select-Frist ab Anlage, keine Doppelzählung, Titel im Cursor, tree-Budget
final class ImportHardeningTest extends ImportTestCase {
	// Hinweis 3: reine Punkt-IDs und leere !-Segmente nie
	public function testIdRules(): void {
		foreach (['.', '..', '...', 'a!!b', '!a', 'a!', ''] as $bad) {
			self::assertFalse(GraphClient::validId($bad), $bad);
		}
		foreach (['0-ABC!12', '1-5c3bc4c3d2e5a4e1!123', 'a.b', 'nb1'] as $ok) {
			self::assertTrue(GraphClient::validId($ok), $ok);
		}
		self::assertNull(GraphClient::resourceUrl('me', '..'));
	}

	// Hinweis 2: claim nur, wenn next_run_at fällig ist (Backoff gilt auch bei veraltetem findDue)
	public function testClaimRespectsNextRun(): void {
		$u = $this->createUser('hc');
		$id = self::insertImport($u->uid, 'queued', ['next_run_at' => $this->now + 300]);
		$m = Server::get(OneNoteImportMapper::class);
		self::assertSame(0, $m->claim($id, $u->uid, 'l1', $this->now, $this->now + 300));
		self::assertSame(1, $m->claim($id, $u->uid, 'l2', $this->now + 300, $this->now + 600));
	}

	// Hinweis 4: select läuft 24 h ab Anlage ab, auch wenn tree die Token zwischendurch erneuert
	public function testSelectExpiresFromCreation(): void {
		$u = $this->createUser('hs');
		$this->notebook('me', 'nbS', 1, 0);
		$id = $this->signIn($this->connectService($this->http), $u->uid);
		$this->now += 23 * 3600;
		$this->importer()->tree($u->uid, $id);
		self::assertSame('select', self::row($id)['status']);
		$this->now += 3600 + 60;
		$this->importer()->cleanup();
		self::assertSame('expired', self::row($id)['status']);
		self::assertNoSecrets($id);
	}

	// Hinweis 5: Neuaufbau nach RestructureException zählt pages_total und pages_skipped nicht doppelt
	public function testRestructureDoesNotDoubleCount(): void {
		$u = $this->createUser('hr');
		$this->notebook('me', 'nbR', 1, 3);
		$this->graph->errors['me/onenote/pages/nbR-s0-p-1/content'] = [429];
		$id = $this->started($u->uid, [['root' => 'me', 'notebook' => 'nbR', 'items' => null]]);
		$this->importer()->runDue();
		$row = self::row($id);
		self::assertSame([3, 1, 0], [(int)$row['pages_total'], (int)$row['pages_done'], (int)$row['pages_skipped']]);
		// Zielordner in den Papierkorb: die nächste Seite löst den Neuaufbau aus
		$qb = self::db()->getQueryBuilder();
		$qb->update('nextnotes_folders')->set('trashed_at', $qb->createNamedParameter($this->now))
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter((int)self::notesOf($u->uid)[0]['workspace_id'])))->executeStatement();
		self::assertSame('done', $this->runUntilDone($id));
		$row = self::row($id);
		self::assertSame([3, 3, 0], [(int)$row['pages_total'], (int)$row['pages_done'], (int)$row['pages_skipped']]);
		self::assertCount(3, self::notesOf($u->uid));
	}

	// Hinweis 6: Seitentitel im Cursor gekürzt
	public function testCursorTitleIsShort(): void {
		$u = $this->createUser('ht');
		$this->notebook('me', 'nbT', 1, 2);
		$this->graph->notebooks['me'][0]['sections'][0]['pages'][1]['title'] = str_repeat('Ä', 1000);
		$this->graph->errors['me/onenote/pages/nbT-s0-p-0/content'] = [429];
		$id = $this->started($u->uid, [['root' => 'me', 'notebook' => 'nbT', 'items' => null]]);
		$this->importer()->runDue();
		$cursor = (string)self::row($id)['cursor'];
		self::assertStringContainsString(str_repeat('Ä', OneNoteImportService::CURSOR_TITLE), $cursor);
		self::assertStringNotContainsString(str_repeat('Ä', OneNoteImportService::CURSOR_TITLE + 1), $cursor);
		self::assertSame('done', $this->runUntilDone($id));
		self::assertSame(OneNoteImportService::CURSOR_TITLE, mb_strlen((string)self::notesOf($u->uid)[1]['heading']));
	}

	// Hinweis 7: tree begrenzt die Graph-Anfragen je Aufruf und lädt Teams seitenweise nach
	public function testTreeRequestBudget(): void {
		$u = $this->createUser('hb');
		$this->notebook('me', 'nbA', 1, 0);
		for ($i = 0; $i < 30; $i++) {
			$this->graph->teams[] = ['id' => "team$i", 'displayName' => "Team $i"];
			$this->notebook("groups/team$i", "nbT$i", 1, 0);
		}
		$id = $this->signIn($this->connectService($this->http), $u->uid);
		$seen = [];
		$offset = 0;
		for ($call = 0; $call < 5; $call++) {
			$before = $this->graph->count();
			$tree = $this->importer()->tree($u->uid, $id, $offset);
			self::assertLessThanOrEqual(OneNoteImportService::TREE_REQUESTS, $this->graph->count() - $before, "Aufruf $call");
			foreach ($tree['notebooks'] as $nb) {
				$seen[$nb['id']] = true;
			}
			if (!$tree['teams_more']) {
				break;
			}
			self::assertGreaterThan($offset, $tree['teams_next']);
			$offset = $tree['teams_next'];
		}
		self::assertCount(31, $seen, 'alle Notizbücher über mehrere Aufrufe');
		self::assertGreaterThan(0, $call, 'mehr als ein Aufruf nötig');
	}

	// Review M9d-r1 Hinweis 3: Neuaufbau kürzt den Bericht auf den Stand vor dem Notizbuch (not_found nicht doppelt)
	public function testRestructureDoesNotDoubleReport(): void {
		$u = $this->createUser('hp');
		$this->notebook('me', 'nbP', 1, 3);
		$this->graph->errors['me/onenote/pages/nbP-s0-p-1/content'] = [429];
		$id = $this->started($u->uid, [['root' => 'me', 'notebook' => 'nbP', 'items' => [['kind' => 'section', 'id' => 'nbP-s0'], ['kind' => 'section', 'id' => 'gone']]]]);
		$this->importer()->runDue();
		self::assertSame(['not_found'], array_column(self::report($id), 'r'));
		$qb = self::db()->getQueryBuilder();
		$qb->update('nextnotes_folders')->set('trashed_at', $qb->createNamedParameter($this->now))
			->where($qb->expr()->eq('workspace_id', $qb->createNamedParameter((int)self::notesOf($u->uid)[0]['workspace_id'])))->executeStatement();
		self::assertSame('done', $this->runUntilDone($id));
		self::assertSame(['not_found'], array_column(self::report($id), 'r'));
		self::assertCount(3, self::notesOf($u->uid));
	}

	// Hinweis 3: mark/rewind mit Sammeleintrag more und alter Cursor mit drei Zählern
	public function testRewindKeepsReportedExact(): void {
		$import = new OneNoteImport();
		$import->setUid('x');
		$run = new ImportRun($import, null, $this->createStub(IL10N::class));
		for ($i = 0; $i < ImportRun::REPORT_MAX + 4; $i++) {
			$run->report("p$i", 'not_found');
		}
		$base = $run->mark();
		self::assertSame(ImportRun::REPORT_MAX + 4, $run->reported());
		for ($i = 0; $i < 7; $i++) {
			$run->report("q$i", 'forbidden');
		}
		$run->pagesTotal = 99;
		$run->rewind($base);
		self::assertSame(ImportRun::REPORT_MAX + 4, $run->reported());
		self::assertCount(ImportRun::REPORT_MAX, $run->report);
		self::assertSame(0, $run->pagesTotal);
		$run->report('a', 'not_found');
		$run->rewind([1, 0, 0]);
		self::assertSame(ImportRun::REPORT_MAX + 5, $run->reported(), 'alter Cursor: Bericht bleibt');
		self::assertSame(1, $run->pagesTotal);
	}

	// Hinweis 5: Liste an der Anfragegrenze abgebrochen → incomplete
	public function testTreeIncomplete(): void {
		$u = $this->createUser('hi');
		$this->notebook('me', 'nbI', 1, 0);
		$id = $this->signIn($this->connectService($this->http), $u->uid);
		self::assertFalse($this->importer()->tree($u->uid, $id)['incomplete']);
		$this->graph->teamsEndless = true;
		$tree = $this->importer()->tree($u->uid, $id);
		self::assertTrue($tree['incomplete']);
		self::assertCount(1, $tree['notebooks']);
	}
}
