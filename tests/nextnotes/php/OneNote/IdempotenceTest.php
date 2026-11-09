<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\OneNote;

use OCA\NextNotes\Db\OneNoteImportMapper;
use OCA\NextNotes\Service\NoteService;
use OCA\NextNotes\Service\Exception\ConflictException;
use OCP\Server;

// M9b Done 5, 5a, 6: erneuter Import überspringt (Q2), purgierte Zielnotiz neu, fremd gewordener Workspace nicht genutzt
final class IdempotenceTest extends ImportTestCase {
	private function again(string $uid, string $nb): int {
		$this->now += 10;
		return $this->started($uid, [['root' => 'me', 'notebook' => $nb, 'items' => null]]);
	}

	public function testReimportSkipsAndRecreatesPurged(): void {
		$u = $this->createUser('id');
		$this->notebook('me', 'nbI', 2, 3);
		$first = $this->started($u->uid, [['root' => 'me', 'notebook' => 'nbI', 'items' => null]]);
		self::assertSame('done', $this->runUntilDone($first));
		$notes = self::notesOf($u->uid);
		self::assertCount(6, $notes);
		$wsCount = count(self::workspacesOf($u->uid));

		// Done 5: 0 neue Notizen, gleicher Workspace
		$second = $this->again($u->uid, 'nbI');
		self::assertSame('done', $this->runUntilDone($second));
		self::assertCount(6, self::notesOf($u->uid));
		self::assertSame([0, 6], [(int)self::row($second)['pages_done'], (int)self::row($second)['pages_skipped']]);
		self::assertCount($wsCount, self::workspacesOf($u->uid));
		self::assertSame(0, $this->graph->count('me/onenote/pages/nbI-s0-p-0/content') - 1, 'übersprungen ohne Inhaltsabruf');

		// Papierkorb zählt als vorhanden; Purge → genau diese neu
		$svc = Server::get(NoteService::class);
		$svc->delete($u->uid, (int)$notes[0]['id'], false, null);
		$svc->purge([(int)$notes[1]['id']]);
		$third = $this->again($u->uid, 'nbI');
		self::assertSame('done', $this->runUntilDone($third));
		self::assertSame([1, 5], [(int)self::row($third)['pages_done'], (int)self::row($third)['pages_skipped']]);
		self::assertCount(6, self::notesOf($u->uid));
	}

	// Done 5: Workspace inzwischen Team-eigen bzw. kind≠normal → neuer Workspace
	public function testForeignWorkspaceIsNotReused(): void {
		$u = $this->createUser('iw');
		$this->notebook('me', 'nbW', 1, 1);
		$first = $this->started($u->uid, [['root' => 'me', 'notebook' => 'nbW', 'items' => null]]);
		$this->runUntilDone($first);
		$ws = array_values(array_filter(self::workspacesOf($u->uid), static fn ($w) => $w['name'] === 'Buch nbW'))[0];
		$qb = self::db()->getQueryBuilder();
		$qb->update('nextnotes_workspaces')->set('kind', $qb->createNamedParameter('assignments'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$ws['id'])))->executeStatement();
		// Notiz bleibt vorhanden → übersprungen; Notizbuch-Struktur neu
		$second = $this->again($u->uid, 'nbW');
		self::assertSame('done', $this->runUntilDone($second));
		$same = array_values(array_filter(self::workspacesOf($u->uid), static fn ($w) => $w['name'] === 'Buch nbW'));
		self::assertCount(2, $same, 'neuer Workspace');
		self::assertSame('normal', $same[1]['kind']);
		self::assertSame(1, (int)self::row($second)['pages_skipped']);
	}

	// Done 5a: config_changed → Neustart mit neuer Client-ID und anderem sub, 0 neue Notizen
	public function testRestartAfterConfigChange(): void {
		$u = $this->createUser('ic');
		$this->notebook('me', 'nbC', 1, 4);
		$id = $this->started($u->uid, [['root' => 'me', 'notebook' => 'nbC', 'items' => null]]);
		self::assertSame('done', $this->runUntilDone($id));
		$this->configure(self::TENANT, self::CLIENT2, self::SECRET, true);
		$token = $this->tokenResponder('sub-OTHER');
		$graph = $this->graph;
		$this->http->responder = fn ($m, $url, $o) => parse_url($url, PHP_URL_HOST) === 'graph.microsoft.com' ? $graph->respond($url) : $token($m, $url, $o);
		$id2 = $this->again($u->uid, 'nbC');
		self::assertSame('done', $this->runUntilDone($id2));
		self::assertSame([0, 4], [(int)self::row($id2)['pages_done'], (int)self::row($id2)['pages_skipped']]);
		self::assertCount(4, self::notesOf($u->uid));
	}

	// Done 6: zweiter offener Import in queued → 409; fremde ID → 404 (tree/start)
	public function testSecondOpenImportAndForeignIds(): void {
		$u = $this->createUser('io');
		$o = $this->createUser('io2');
		$this->notebook('me', 'nbO', 1, 1);
		$id = $this->started($u->uid, [['root' => 'me', 'notebook' => 'nbO', 'items' => null]]);
		$this->now += 10;
		try {
			$this->connectService($this->http)->connect($u->uid, null);
			self::fail('409 erwartet');
		} catch (ConflictException) {
		}
		foreach (['tree', 'start'] as $action) {
			try {
				$action === 'tree' ? $this->importer()->tree($o->uid, $id) : $this->importer()->start($o->uid, $id, [['root' => 'me', 'notebook' => 'x']]);
				self::fail("404 erwartet ($action)");
			} catch (\OCA\NextNotes\Service\Exception\NotFoundException) {
			}
		}
		self::assertSame('queued', Server::get(OneNoteImportMapper::class)->findOwn($id, $u->uid)->getStatus());
	}
}
