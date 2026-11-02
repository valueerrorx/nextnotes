<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Scenario;

use OCA\NextNotes\Service\TaskService;
use OCA\NextNotes\Service\WorkspaceService;
use OCA\NextNotes\Tests\Support\ApiClient;
use OCA\NextNotes\Tests\Support\ApiTestCase;
use OCP\IConfig;
use OCP\Server;

// Plan Done M2c 4, SPEC §2.2 (Zeilensperre), §3 (Tasks abgeleitet von Notiz), §11.2 B25
final class TasksTest extends ApiTestCase {
	private static function tasklist(ApiClient $c, string $ws, string $heading = 'Liste'): int {
		return (int)self::ok($c->call('POST', '/notes', ['heading' => $heading, 'type' => 'tasklist', 'content' => '[]', 'workspace' => $ws]), 'tasklist')['note']['id'];
	}

	private static function tasks(ApiClient $c, int $tl): array {
		return self::ok($c->call('GET', "/notes/$tl/tasks"), 'GET tasks')['tasks'];
	}

	public function testCrudWithSubtasks(): void {
		$a = $this->createUser('ta');
		[$ws] = self::newWorkspace($a, 'T');
		$tl = self::tasklist($a, $ws);
		$t = self::ok($a->call('POST', "/notes/$tl/tasks", ['text' => 'Eins']), 'create')['task'];
		$tid = TaskService::idString($t['id']);
		self::assertFalse($t['completed']);
		$s = self::ok($a->call('POST', "/notes/$tl/tasks/$tid/subtasks", ['text' => 'Unter']), 'sub')['subtask'];
		$sid = TaskService::idString($s['id']);
		self::ok($a->call('PATCH', "/notes/$tl/tasks/$tid/subtasks/$sid", ['completed' => true, 'text' => 'Unter b']), 'sub patch');
		self::ok($a->call('PATCH', "/notes/$tl/tasks/$tid", ['text' => 'Eins b', 'completed' => true]), 'patch');
		$list = self::tasks($a, $tl);
		self::assertCount(1, $list);
		self::assertSame('Eins b', $list[0]['text']);
		self::assertTrue($list[0]['completed']);
		self::assertSame([['text' => 'Unter b', 'completed' => true]], array_map(static fn ($x) => ['text' => $x['text'], 'completed' => $x['completed']], $list[0]['subtasks']));
		self::assertSame(404, $a->call('PATCH', "/notes/$tl/tasks/1/subtasks/$sid", ['text' => 'x'])->status, 'unbekannte Aufgabe');
		self::assertSame(404, $a->call('PATCH', "/notes/$tl/tasks/$tid/subtasks/1", ['text' => 'x'])->status, 'unbekannte Unteraufgabe');
		self::ok($a->call('DELETE', "/notes/$tl/tasks/$tid/subtasks/$sid"), 'sub delete');
		self::assertSame([], self::tasks($a, $tl)[0]['subtasks'] ?? []);
		self::ok($a->call('DELETE', "/notes/$tl/tasks/$tid"), 'delete');
		self::assertSame([], self::tasks($a, $tl));
		// keine Tasklist → 400
		$plain = self::newNote($a, $ws, 'Text', '<p>x</p>');
		self::assertSame(400, $a->call('POST', "/notes/$plain/tasks", ['text' => 'x'])->status);
	}

	public function testForeignAndReadOnlyTasklist(): void {
		$a = $this->createUser('fa');
		$b = $this->createUser('fb');
		$mark = 'tmk' . bin2hex(random_bytes(4));
		[$ws, $wsId] = self::newWorkspace($a, 'Priv');
		$tl = self::tasklist($a, $ws, "Liste $mark");
		$tid = TaskService::idString(self::ok($a->call('POST', "/notes/$tl/tasks", ['text' => "Aufgabe $mark"]), 'task')['task']['id']);
		$sid = TaskService::idString(self::ok($a->call('POST', "/notes/$tl/tasks/$tid/subtasks", ['text' => "Unter $mark"]), 'sub')['subtask']['id']);
		$before = self::dump($a->uid);
		$calls = [
			['GET', "/notes/$tl/tasks", null], ['POST', "/notes/$tl/tasks", ['text' => 'x']],
			['PATCH', "/notes/$tl/tasks/$tid", ['completed' => true]], ['DELETE', "/notes/$tl/tasks/$tid", null],
			['POST', "/notes/$tl/tasks/$tid/subtasks", ['text' => 'x']], ['PATCH', "/notes/$tl/tasks/$tid/subtasks/$sid", ['completed' => true]],
			['DELETE', "/notes/$tl/tasks/$tid/subtasks/$sid", null],
		];
		foreach ($calls as [$m, $p, $body]) {
			$r = $b->call($m, $p, $body);
			self::assertSame(404, $r->status, "B $m $p: " . $r->describe());
			self::assertStringNotContainsString($mark, $r->body);
		}
		$r = $b->call('GET', '/tasks');
		self::assertStringNotContainsString($mark, $r->body, 'GET /tasks zeigt fremde Aufgaben');
		self::assertSame($before, self::dump($a->uid), 'A-Daten verändert');

		// nur Leserecht: lesen ja, schreiben 403
		Server::get(WorkspaceService::class)->share($a->uid, $wsId, $b->uid, 'read');
		self::assertSame("Aufgabe $mark", self::tasks($b, $tl)[0]['text']);
		foreach (array_slice($calls, 1) as [$m, $p, $body]) {
			$r = $b->call($m, $p, $body);
			self::assertSame(403, $r->status, "B (read) $m $p: " . $r->describe());
		}
		self::assertSame($before['notes'], self::dump($a->uid)['notes'], 'Lesefreigabe: Notiz verändert');
	}

	// B25: ungültige Kalendertage und Formate → 400, nichts gespeichert
	public function testInvalidDueAtB25(): void {
		$a = $this->createUser('da');
		[$ws] = self::newWorkspace($a, 'Due');
		$tl = self::tasklist($a, $ws);
		foreach (['2026-02-30', '2026-13-01', '2025-02-29', '2026-04-31T10:00', 'morgen', '2026-1-5'] as $bad) {
			$r = $a->call('POST', "/notes/$tl/tasks", ['text' => 'x', 'due_at' => $bad]);
			self::assertSame(400, $r->status, "POST due_at=$bad: " . $r->describe());
		}
		self::assertSame([], self::tasks($a, $tl), 'ungültige Aufgabe gespeichert');
		$tid = TaskService::idString(self::ok($a->call('POST', "/notes/$tl/tasks", ['text' => 'ok', 'due_at' => '2028-02-29']), 'Schalttag')['task']['id']);
		self::assertSame(400, $a->call('PATCH', "/notes/$tl/tasks/$tid", ['due_at' => '2026-02-30'])->status, 'PATCH due_at ungültig');
		self::assertSame('2028-02-29', self::tasks($a, $tl)[0]['dueAt']);
		self::assertSame(400, $a->call('POST', "/notes/$tl/tasks", ['text' => 'x', 'due_at' => '2028-01-01', 'recurrence' => 'jeden Tag'])->status, 'Wiederholung ungültig');
	}

	// B25 sinngemäß: ungültige Uhrzeit im due_at (Format 'YYYY-MM-DDTHH:MM') → 400 statt Speichern
	public function testInvalidDueAtTimeOfDay(): void {
		$a = $this->createUser('dt');
		[$ws] = self::newWorkspace($a, 'DueT');
		$tl = self::tasklist($a, $ws);
		foreach (['2026-02-28T25:99', '2026-02-28T24:00', '2026-02-28T12:60'] as $bad) {
			$r = $a->call('POST', "/notes/$tl/tasks", ['text' => 'x', 'due_at' => $bad]);
			self::assertSame(400, $r->status, "POST due_at=$bad: " . $r->describe());
		}
		self::assertSame([], self::tasks($a, $tl));
	}

	// SPEC §2.2: Aufgaben-Schreibrouten unter Zeilensperre → parallele Schreibzugriffe ohne verlorene Änderung
	public function testParallelWritesConsistent(): void {
		$a = $this->createUser('pa');
		[$ws] = self::newWorkspace($a, 'Par');
		$tl = self::tasklist($a, $ws);
		$n = 8;
		$reqs = [];
		for ($i = 0; $i < $n; $i++) {
			$reqs[] = ['POST', "/notes/$tl/tasks", ['text' => "P$i"]];
		}
		$res = $a->parallel($reqs);
		$okTexts = [];
		$fail = [];
		foreach ($res as $i => $r) {
			if ($r->ok()) {
				$okTexts[] = "P$i";
			} else {
				$fail[] = "P$i: " . $r->describe();
			}
		}
		$stored = array_column(self::tasks($a, $tl), 'text');
		sort($stored);
		sort($okTexts);
		self::assertSame($okTexts, $stored, 'gespeicherte Aufgaben ≠ erfolgreiche Anfragen (verlorene Änderung)');
		if (Server::get(IConfig::class)->getSystemValueString('dbtype') !== 'sqlite3') {
			self::assertSame([], $fail, 'parallele Anlage fehlgeschlagen');
		} else {
			self::assertGreaterThan(0, count($okTexts));
		}

		// parallele Änderungen verschiedener Aufgaben
		$ids = array_map(static fn ($t) => TaskService::idString($t['id']), self::tasks($a, $tl));
		$reqs = [];
		foreach ($ids as $k => $id) {
			$reqs[] = ['PATCH', "/notes/$tl/tasks/$id", ['text' => "Q$k"]];
		}
		$res = $a->parallel($reqs);
		foreach ($res as $k => $r) {
			$expect = $r->ok() ? "Q$k" : null;
			$got = null;
			foreach (self::tasks($a, $tl) as $t) {
				if (TaskService::idString($t['id']) === $ids[$k]) {
					$got = $t['text'];
				}
			}
			self::assertNotNull($got, "Aufgabe {$ids[$k]} verloren");
			if ($expect !== null) {
				self::assertSame($expect, $got, "Änderung von Aufgabe {$ids[$k]} verloren");
			}
		}
		self::assertCount(count($ids), self::tasks($a, $tl));
	}
}
