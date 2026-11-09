<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Assignments;

use OCA\NextNotes\Db\WorkspaceMapper;
use OCA\NextNotes\Service\View\AssignmentsViewService;
use OCP\Server;

// UI-tplpick (Thomas 2026-11-09): Vorlagen-Quelle nur aus dem Team-Workspace der Aufgabe; alles andere 403 source_not_allowed bzw. 404
final class AssignmentSourceWorkspaceTest extends AssignmentTestCase {
	public function testSourceOnlyFromAssignmentWorkspace(): void {
		$fx = $this->fixture(false);
		['l' => $l, 'n' => $n, 't' => $t, 'ws' => $ws, 'wsId' => $wsId] = $fx;
		$body = static fn (int $src, ?string $key) => ['team' => $t, 'title' => 'x', 'due_at' => time() + 600, 'source_note_id' => $src]
			+ ($key !== null ? ['workspace' => $key] : []);
		$here = self::newNote($l, $ws, 'Hier', '<p>h</p>');

		// erlaubt: gleicher Workspace, mit und ohne workspace=
		foreach (['mit workspace' => $ws, 'ohne workspace' => null] as $what => $key) {
			$r = $l->call('POST', '/assignments', $body($here, $key));
			self::assertSame(201, $r->status, "gleicher Workspace $what: " . $r->describe());
			$this->assignmentIds[] = (int)$r->json()['assignment']['id'];
		}

		[$ws2, $ws2Id] = self::teamWs($l, $t, 'Zwei');
		$sibling = self::newNote($l, $ws2, 'Nachbar', '<p>n</p>');
		[$own] = self::newWorkspace($l, 'Privat');
		$private = self::newNote($l, $own, 'Privat', '<p>p</p>');
		[$nWs, $nWsId] = self::newWorkspace($n, 'Geteilt');
		$shared = self::newNote($n, $nWs, 'Geteilt', '<p>g</p>');
		self::rawShare($nWsId, $l->uid, 'read');
		$other = $this->teams->create($l->uid, 'Anderes ' . bin2hex(random_bytes(3)));
		[$otherWs] = self::teamWs($l, $other, 'Andere');
		$foreignTeam = self::newNote($l, $otherWs, 'Fremdes Team', '<p>f</p>');
		$link = (int)self::ok($l->call('POST', '/notes', ['heading' => 'Link Nachbar', 'type' => 'linked', 'linked_note_id' => $sibling, 'workspace' => $ws]), 'Link')['note']['id'];
		[$asgWs, $asgWsId] = self::teamWs($l, $t, 'Abgabe');
		$inAsg = self::newNote($l, $asgWs, 'Im Abgabe-Workspace', '<p>a</p>');
		self::setColumn('nextnotes_workspaces', $asgWsId, 'kind', 'assignments');
		$trashed = self::newNote($l, $ws, 'Papierkorb', '<p>t</p>');
		self::ok($l->call('DELETE', "/notes/$trashed"), 'in den Papierkorb');

		$count = self::countRows('nextnotes_assignments', 'team_id', $t);
		$denied = [
			'anderer Workspace desselben Teams' => [$sibling, $ws],
			'Verknüpfung auf anderen Workspace' => [$link, $ws],
			'eigener privater Workspace' => [$private, $ws],
			'privat ohne workspace' => [$private, null],
			'fremde Lesefreigabe' => [$shared, $ws],
			'anderes Team' => [$foreignTeam, $ws],
			'Quelle passt, workspace eines anderen Teams' => [$here, $otherWs],
		];
		foreach ($denied as $what => [$src, $key]) {
			$r = $l->call('POST', '/assignments', $body($src, $key));
			self::assertSame([403, 'source_not_allowed'], [$r->status, $r->json()['code'] ?? null], $what . ': ' . $r->describe());
		}
		self::assertContains($l->call('POST', '/assignments', $body($inAsg, $ws))->status, [403, 404], 'Abgabe-Workspace');
		self::assertContains($l->call('POST', '/assignments', $body($inAsg, null))->status, [403, 404], 'Abgabe-Workspace ohne workspace');
		self::assertSame(404, $l->call('POST', '/assignments', $body($trashed, $ws))->status, 'Papierkorb');
		self::assertSame(404, $l->call('POST', '/assignments', $body($here, 'gibt es nicht ' . bin2hex(random_bytes(3))))->status, 'unbekannter Workspace');
		self::assertSame($count, self::countRows('nextnotes_assignments', 'team_id', $t), 'nichts angelegt');

		// ?create=: mit Frame-Workspace nur Notizen aus diesem
		$view = Server::get(AssignmentsViewService::class);
		$frame = Server::get(WorkspaceMapper::class)->find($wsId);
		self::assertSame($here, $view->createSource($l->uid, (string)$here, $t, $frame), 'create: Notiz dieses Workspace');
		self::assertNull($view->createSource($l->uid, (string)$sibling, $t, $frame), 'create: anderer Workspace des Teams nicht');
		self::assertSame($sibling, $view->createSource($l->uid, (string)$sibling, $t, Server::get(WorkspaceMapper::class)->find($ws2Id)), 'create: im eigenen Workspace');
	}
}
