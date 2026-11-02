<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Access;

use OCA\NextNotes\Db\Note;
use OCA\NextNotes\Db\NoteMapper;
use OCA\NextNotes\Service\AccessService;
use OCA\NextNotes\Service\NoteService;
use OCA\NextNotes\Service\WorkspaceService;
use OCA\NextNotes\Tests\Support\ApiTestCase;
use OCP\Config\IUserConfig;
use OCP\Server;

// Done M2a 9: role ≠ note → 404 für alle (auch Besitzer); opened-Liste nach Freigabe-Entzug gefiltert
final class DenyByDefaultTest extends ApiTestCase {
	private static function wsId(string $uid, string $name): int {
		foreach (self::dump($uid)['workspaces'] as $w) {
			if ($w['name'] === $name) {
				return (int)$w['id'];
			}
		}
		self::fail("Workspace $name fehlt");
	}

	public function testNonNoteRoleIsInvisibleEvenForOwner(): void {
		$a = $this->createUser('dd');
		$ws = 'Dd ' . bin2hex(random_bytes(3));
		self::ok($a->call('POST', '/workspaces', ['name' => $ws]), 'Workspace');
		$marker = 'ddmk' . bin2hex(random_bytes(4));
		$errors = [];
		foreach (['template', 'submission'] as $role) {
			$n = new Note();
			$n->setWorkspaceId(self::wsId($a->uid, $ws));
			$n->setHeading("$marker $role");
			$n->setContent("<p>$marker</p>");
			$n->setSearchText($marker);
			$n->setType('note');
			$n->setRole($role);
			$n->setCreatedAt(time());
			$n->setUpdatedAt(time());
			$id = (int)Server::get(NoteMapper::class)->insert($n)->getId();
			$q = self::q(['workspace' => $ws]);
			foreach ([
				['GET', "/notes/$id"], ['PATCH', "/notes/$id"], ['DELETE', "/notes/$id"],
				['PUT', "/notes/$id/pinned"], ['POST', "/notes/$id/favorite"], ['POST', "/notes/$id/opened"],
			] as [$m, $p]) {
				$r = $a->call($m, $p . $q, $m === 'GET' ? null : ['heading' => 'x', 'pinned' => true]);
				if ($r->status !== 404) {
					$errors[] = "$role $m $p → " . $r->describe();
				}
			}
			foreach (['/notes', '/trash', '/folders/counts'] as $list) {
				$r = $a->call('GET', $list . $q);
				if (str_contains($r->body, $marker)) {
					$errors[] = "$role in Liste $list sichtbar";
				}
			}
		}
		self::assertSame([], $errors);
	}

	public function testOpenedListDropsNotesAfterUnshare(): void {
		$a = $this->createUser('oa');
		$b = $this->createUser('ob');
		$ws = 'Geteilt ' . bin2hex(random_bytes(3));
		self::ok($a->call('POST', '/workspaces', ['name' => $ws]), 'Workspace');
		$foreign = (int)self::ok($a->call('POST', '/notes', ['heading' => 'A', 'content' => '<p>a</p>', 'workspace' => $ws]), 'A-Notiz')['note']['id'];
		$own = (int)self::ok($b->call('POST', '/notes', ['heading' => 'B', 'content' => '<p>b</p>']), 'B-Notiz')['note']['id'];
		$svc = Server::get(WorkspaceService::class);
		$wsId = self::wsId($a->uid, $ws);
		$svc->share($a->uid, $wsId, $b->uid);

		self::ok($b->call('POST', "/notes/$foreign/opened"), 'B öffnet A-Notiz');
		self::ok($b->call('POST', "/notes/$own/opened"), 'B öffnet eigene');
		$notes = Server::get(NoteService::class);
		// HTTP-Prozess hat geschrieben; Cache dieses Prozesses verwerfen
		Server::get(IUserConfig::class)->clearCache($b->uid);
		self::assertContains($foreign, $notes->openedNoteIds($b->uid), 'Kontrolle: geteilte Notiz in der Liste');

		$svc->unshare($a->uid, $wsId, $b->uid);
		Server::get(AccessService::class)->resetCache();
		Server::get(IUserConfig::class)->clearCache($b->uid);
		$ids = $notes->openedNoteIds($b->uid);
		self::assertNotContains($foreign, $ids, 'fremde Notiz nach Entzug noch in opened');
		self::assertContains($own, $ids);
		self::assertSame(404, $b->call('GET', "/notes/$foreign")->status);
	}
}
