<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Jobs;

use OCA\NextNotes\Service\TaskService;
use OCA\NextNotes\Service\WorkspaceService;
use OCA\NextNotes\Tests\Support\ApiTestCase;
use OCP\Server;

// M6e2 Zusatz (SPEC §4.2): GET /notes/{id}/task-reminder?task_id= liefert nur die eigene Erinnerung; ohne Leserecht 404, ohne task_id 400
final class TaskReminderShowTest extends ApiTestCase {
	public function testOnlyOwnReminderAndRights(): void {
		$a = $this->createUser('tra');
		$b = $this->createUser('trb');
		$c = $this->createUser('trc');
		[$ws, $wsId] = self::newWorkspace($a, 'TR');
		Server::get(WorkspaceService::class)->share($a->uid, $wsId, $b->uid, 'read');
		$tl = (int)self::ok($a->call('POST', '/notes', ['heading' => 'Liste', 'type' => 'tasklist', 'content' => '[]', 'workspace' => $ws]), 'tl')['note']['id'];
		$tid = TaskService::idString(self::ok($a->call('POST', "/notes/$tl/tasks", ['text' => 'Lesen']), 'task')['task']['id']);
		$q = '?task_id=' . rawurlencode($tid);

		// vorher: niemand hat eine Erinnerung
		$r = self::ok($a->call('GET', "/notes/$tl/task-reminder$q"), 'A GET leer');
		self::assertSame([$tl, $tid, null], [$r['note_id'], $r['task_id'], $r['reminder_at']], 'A ohne Erinnerung');

		self::ok($a->call('POST', "/notes/$tl/task-reminder", ['task_id' => $tid, 'reminder_at' => '2031-02-03T04:05:00Z']), 'A setzt');
		self::assertSame('2031-02-03 04:05:00', self::ok($a->call('GET', "/notes/$tl/task-reminder$q"), 'A GET')['reminder_at'], 'A sieht eigene');
		// B (nur Leserecht): Gegenprobe 200, aber nicht A's Erinnerung
		self::assertNull(self::ok($b->call('GET', "/notes/$tl/task-reminder$q"), 'B GET')['reminder_at'], 'B sieht Erinnerung von A');
		self::ok($b->call('POST', "/notes/$tl/task-reminder", ['task_id' => $tid, 'reminder_at' => '2032-01-01T08:00:00Z', 'recurrence' => '1d']), 'B setzt eigene');
		$rb = self::ok($b->call('GET', "/notes/$tl/task-reminder$q"), 'B GET eigene');
		self::assertSame(['2032-01-01 08:00:00', '1d'], [$rb['reminder_at'], $rb['recurrence']], 'B sieht eigene');
		self::assertSame('2031-02-03 04:05:00', self::ok($a->call('GET', "/notes/$tl/task-reminder$q"), 'A GET nach B')['reminder_at'], 'A unverändert');

		// C ohne Leserecht: 404, keine Daten im Body
		$r = $c->call('GET', "/notes/$tl/task-reminder$q");
		self::assertSame(404, $r->status, 'C ohne Leserecht: ' . $r->describe());
		self::assertStringNotContainsString('2031', $r->body);
		self::assertSame(404, $c->call('GET', '/notes/999999999/task-reminder?task_id=1')->status, 'unbekannte Notiz');
		// fremde Notiz ohne task_id: Rechte vor Validierung (404, nicht 400)
		self::assertSame(404, $c->call('GET', "/notes/$tl/task-reminder")->status, 'C ohne task_id');

		// ohne/leere task_id → 400; unbekannte task_id → null
		self::assertSame(400, $a->call('GET', "/notes/$tl/task-reminder")->status, 'ohne task_id');
		self::assertSame(400, $a->call('GET', "/notes/$tl/task-reminder?task_id=")->status, 'leere task_id');
		self::assertNull(self::ok($a->call('GET', "/notes/$tl/task-reminder?task_id=424242"), 'unbekannte Aufgabe')['reminder_at']);

		// nach DELETE: null; Leserecht entzogen → 404
		self::ok($a->call('DELETE', "/notes/$tl/task-reminder$q"), 'A löscht');
		self::assertNull(self::ok($a->call('GET', "/notes/$tl/task-reminder$q"), 'A GET nach Löschen')['reminder_at']);
		self::assertSame('2032-01-01 08:00:00', self::ok($b->call('GET', "/notes/$tl/task-reminder$q"), 'B nach A-Löschen')['reminder_at'], 'B unberührt');
		Server::get(WorkspaceService::class)->unshare($a->uid, $wsId, $b->uid);
		self::assertSame(404, $b->call('GET', "/notes/$tl/task-reminder$q")->status, 'B nach Entzug');
	}
}
