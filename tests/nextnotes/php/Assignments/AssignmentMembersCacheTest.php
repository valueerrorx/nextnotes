<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Assignments;

use OCA\NextNotes\Service\AccessService;
use OCA\NextNotes\Service\AssignmentService;
use OCA\NextNotes\Service\TeamService;
use OCA\NextNotes\Service\View\AssignmentsViewService;
use OCP\Server;
use Psr\Container\ContainerInterface;

// M6g1-r1 Blocker: Liste und Seite fragen die Schülerliste je Team und Request genau einmal bei Circles ab (TeamService::members, Request-Cache)
final class AssignmentMembersCacheTest extends AssignmentTestCase {
	public function testOneMembersLookupPerTeam(): void {
		$fx = $this->distributedFixture();
		['l' => $l, 't' => $t, 'src' => $src] = $fx;
		foreach (['Zwei', 'Drei'] as $title) {
			$id = (int)$this->createAssignment($l, $t, $src, $title)['id'];
			self::ok($l->call('POST', "/assignments/$id/distribute"), "Austeilen $title");
		}
		$teams = Server::get(TeamService::class);
		Server::get(AccessService::class)->resetCache();
		$prop = new \ReflectionProperty(TeamService::class, 'container');
		$old = $prop->getValue($teams);
		$spy = new class($old) implements ContainerInterface {
			public int $members = 0;

			public function __construct(private ContainerInterface $inner) {
			}

			public function get(string $id): mixed {
				// guarded() holt den CirclesManager je Circles-Aufruf einmal; Aufrufer members() zählen
				$trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4);
				foreach ($trace as $i => $f) {
					if ($f['function'] === 'guarded') {
						// direkter Aufrufer von guarded(): members (nicht isNormalTeam o. Ä.)
						$this->members += ($trace[$i + 1]['function'] ?? '') === 'members' ? 1 : 0;
						break;
					}
				}
				return $this->inner->get($id);
			}

			public function has(string $id): bool {
				return $this->inner->has($id);
			}
		};
		$prop->setValue($teams, $spy);
		try {
			$list = Server::get(AssignmentService::class)->index($l->uid)['assignments'];
			$mine = array_values(array_filter($list, static fn ($a) => $a['team'] === $t));
			self::assertCount(3, $mine, 'drei ausgeteilte Aufgaben');
			self::assertSame([0, 0, 0], array_column($mine, 'not_distributed'), 'alle ausgeteilt');
			(clone Server::get(AssignmentsViewService::class))->teacherTeams($l->uid);
			self::assertSame(1, $spy->members, 'ein members-Aufruf je Team und Request');
			$teams->resetCache();
			Server::get(AssignmentService::class)->index($l->uid);
			self::assertSame(2, $spy->members, 'resetCache leert den Cache');
		} finally {
			$prop->setValue($teams, $old);
		}
	}
}
