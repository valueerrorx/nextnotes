<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Scenario;

use OCA\NextNotes\Tests\Support\ApiTestCase;
use OCP\IConfig;
use OCP\Server;

// Lead-Zusatz M2c: ETag spiegelt reminder_at, If-Match mit vollem ETag, if_version unter Zeilensperre
final class NoteVersionTest extends ApiTestCase {
	public function testEtagChangesWithReminder(): void {
		$a = $this->createUser('et');
		[$ws] = self::newWorkspace($a, 'ET');
		$n = self::newNote($a, $ws, 'Etag', '<p>x</p>');
		$r1 = $a->call('GET', "/notes/$n");
		self::assertSame(200, $r1->status);
		$e1 = (string)$r1->header('ETag');
		self::assertNotSame('', $e1);
		self::assertSame(304, $a->call('GET', "/notes/$n", null, ['If-None-Match: ' . $e1])->status, 'Kontrolle: unverändert → 304');
		self::ok($a->call('POST', "/notes/$n/reminder", ['reminder_at' => '2040-01-01T08:00:00Z']), 'Erinnerung');
		$r2 = $a->call('GET', "/notes/$n", null, ['If-None-Match: ' . $e1]);
		self::assertSame(200, $r2->status, 'nach Erinnerung 304 trotz geänderter Antwort');
		self::assertNotSame($e1, (string)$r2->header('ETag'));
		self::assertSame('2040-01-01 08:00:00', $r2->json()['note']['reminder_at'] ?? $r2->json()['reminder_at'] ?? null);
		$r3 = $a->call('PATCH', "/notes/$n", ['content' => '<p>neu</p>'], ['If-Match: ' . (string)$r2->header('ETag')]);
		self::assertSame(200, $r3->status, 'If-Match mit vollem ETag: ' . $r3->describe());
		$r4 = $a->call('PATCH', "/notes/$n", ['content' => '<p>alt</p>'], ['If-Match: ' . (string)$r2->header('ETag')]);
		self::assertSame(409, $r4->status, 'veralteter ETag: ' . $r4->describe());
	}

	public function testParallelPatchSameVersion(): void {
		$a = $this->createUser('pv');
		[$ws] = self::newWorkspace($a, 'PV');
		$n = self::newNote($a, $ws, 'Parallel', '<p>x</p>');
		$version = (string)self::ok($a->call('GET', "/notes/$n"), 'GET')['note']['version'];
		$res = $a->parallel([
			['PATCH', "/notes/$n", ['content' => '<p>eins</p>', 'if_version' => $version]],
			['PATCH', "/notes/$n", ['content' => '<p>zwei</p>', 'if_version' => $version]],
		]);
		$st = [$res[0]->status, $res[1]->status];
		sort($st);
		$desc = $res[0]->describe() . ' | ' . $res[1]->describe();
		if (Server::get(IConfig::class)->getSystemValueString('dbtype') === 'sqlite3' && in_array(500, $st, true)) {
			self::markTestIncomplete('SQLite: database is locked: ' . $desc);
		}
		self::assertSame([200, 409], $st, 'genau einer 409: ' . $desc);
		$winner = $res[0]->status === 200 ? '<p>eins</p>' : '<p>zwei</p>';
		self::assertSame($winner, self::ok($a->call('GET', "/notes/$n"), 'GET')['note']['content']);
	}
}
