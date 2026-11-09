<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\OneNote;

use OCA\NextNotes\Db\OneNoteImportMapper;
use OCA\NextNotes\Db\OneNoteItemMapper;
use OCP\IUserManager;
use OCP\Server;

// M9b Done 4a, 4b, 4c, 10 (Wettläufe im Prozess): Claim genau einmal, Abbruch/Löschung/fremde Lease mitten in einer Anfrage ohne Schreibwirkung
final class ConcurrencyTest extends ImportTestCase {
	private static function rowsIn(string $table, string $uid): int {
		$qb = self::db()->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from($table)->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));
		return (int)$qb->executeQuery()->fetchOne();
	}

	public function testClaimOnlyOnce(): void {
		$u = $this->createUser('cl');
		$id = $this->started($u->uid, [['root' => 'me', 'notebook' => 'nbX', 'items' => null]]);
		$m = Server::get(OneNoteImportMapper::class);
		self::assertSame(1, $m->claim($id, $u->uid, 'lease-1', $this->now, $this->now + 300));
		self::assertSame(0, $m->claim($id, $u->uid, 'lease-2', $this->now, $this->now + 300), 'zweiter Lauf übernimmt nicht');
		self::assertSame([], array_filter($m->findDue($this->now, 100), static fn ($i) => (int)$i->getId() === $id), 'nicht fällig mit Lease');
		// abgelaufene Lease (SIGKILL) → wieder übernehmbar
		self::assertSame(1, $m->claim($id, $u->uid, 'lease-3', $this->now + 301, $this->now + 601));
	}

	// Done 4b: cancel während der Anfrage → keine Notiz danach, keine weitere Anfrage, Token NULL
	public function testCancelDuringRequest(): void {
		$u = $this->createUser('cc');
		$this->notebook('me', 'nbC', 1, 5);
		$id = $this->started($u->uid, [['root' => 'me', 'notebook' => 'nbC', 'items' => null]]);
		$connect = $this->connectService($this->http);
		$this->graph->onRequest = function (string $path) use ($connect, $u, $id): void {
			if ($path === 'me/onenote/pages/nbC-s0-p-2/content') {
				$connect->cancel($u->uid, $id);
			}
		};
		$this->importer()->runDue();
		self::assertSame('cancelled', self::row($id)['status']);
		self::assertCount(2, self::notesOf($u->uid), 'nur die Seiten vor dem Abbruch');
		self::assertSame(0, $this->graph->count('me/onenote/pages/nbC-s0-p-3'), 'keine Anfrage nach dem Abbruch');
		self::assertNoSecrets($id);
		$this->now += 4000;
		$this->importer()->runDue();
		self::assertSame(0, $this->graph->count('me/onenote/pages/nbC-s0-p-3'));
	}

	// Done 4c/10: Kontolöschung während der Anfrage → keine Zeile, keine neue Notiz
	public function testUserDeletedDuringRequest(): void {
		$u = $this->createUser('cd');
		$this->notebook('me', 'nbD', 1, 4);
		$id = $this->started($u->uid, [['root' => 'me', 'notebook' => 'nbD', 'items' => null]]);
		$notesBefore = null;
		$this->graph->onRequest = function (string $path) use ($u, &$notesBefore): void {
			if ($path === 'me/onenote/pages/nbD-s0-p-1/content') {
				$notesBefore = count(self::notesOf($u->uid));
				Server::get(IUserManager::class)->get($u->uid)?->delete();
			}
		};
		$this->importer()->runDue();
		self::assertNull(self::row($id));
		self::assertSame(0, self::rowsIn(OneNoteImportMapper::TABLE, $u->uid));
		self::assertSame(0, self::rowsIn(OneNoteItemMapper::TABLE, $u->uid));
		self::assertSame(0, $this->graph->count('me/onenote/pages/nbD-s0-p-2'));
		self::assertLessThanOrEqual((int)$notesBefore, count(self::notesOf($u->uid)), 'keine Notiz nach der Löschung');
	}

	// fremder Lauf übernimmt die Lease mitten in der Anfrage → eigener Lauf schreibt nichts mehr
	public function testLeaseLostStopsWithoutWrites(): void {
		$u = $this->createUser('cx');
		$this->notebook('me', 'nbL', 1, 3);
		$id = $this->started($u->uid, [['root' => 'me', 'notebook' => 'nbL', 'items' => null]]);
		$this->graph->onRequest = function (string $path) use ($u, $id): void {
			if ($path === 'me/onenote/pages/nbL-s0-p-1/content') {
				Server::get(OneNoteImportMapper::class)->updateWhere($id, $u->uid, ['lease_token' => 'other']);
			}
		};
		$this->importer()->runDue();
		$row = self::row($id);
		self::assertSame(['running', 'other', 1], [$row['status'], $row['lease_token'], (int)$row['pages_done']]);
		self::assertCount(1, self::notesOf($u->uid));
	}
}
