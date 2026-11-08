<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Assignments;

use OC\Authentication\Token\IProvider;
use OCA\NextNotes\Service\UserDataService;
use OCA\NextNotes\Tests\Support\ApiClient;
use OCP\Authentication\Token\IToken;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use OCP\Server;

// M6d-r1 H1 (vorgezogen, Plan M6 §2.5 O3/H3): Kontolöschung eines Schülers löscht keine Abgabe; neu vergebene UID sieht sie nicht; Job/Sweep nur role=note
final class AssignmentAccountDeletionTest extends AssignmentTestCase {
	public function testStudentDeletionKeepsSubmission(): void {
		$fx = $this->distributedFixture();
		['l' => $l, 's1' => $s1, 'aid' => $aid] = $fx;
		$uid = $s1->uid;
		$sub = $fx['subs'][$uid];
		$sid = (int)$sub['id'];
		$note = (int)$sub['note_id'];
		$wsId = (int)self::row('nextnotes_notes', $note)['workspace_id'];
		self::ok($s1->call('PATCH', "/notes/$note", ['content' => '<p>meine Arbeit</p>']), 'S1 arbeitet');
		self::ok($s1->call('POST', "/submissions/$sid/submit"), 'abgeben');
		$att = (int)self::ok($l->call('GET', "/notes/$note/attachments"), 'Anhänge')['attachments'][0]['id'];

		self::assertTrue(Server::get(IUserManager::class)->get($uid)->delete(), 'Kontolöschung');
		// synchron: UID gelöst, Kopie ohne Workspace, Schüler-Workspace entkoppelt
		$row = self::row('nextnotes_submissions', $sid);
		self::assertSame([null, $uid, 'submitted'], [$row['student_uid'], $row['student_uid_former'], $row['status']], 'Abgabe gelöst');
		self::assertNotNull($row['student_deleted_at']);
		self::assertSame('Schüler Eins', $row['student_name']);
		$n = self::row('nextnotes_notes', $note);
		self::assertSame([null, null], [$n['workspace_id'], $n['folder_id']], 'Kopie ohne Ort');
		$ws = self::row('nextnotes_workspaces', $wsId);
		self::assertSame('deleted', $ws['owner_type']);

		// Job und Sweep: Abgabe, Inhalt, Anhang bleiben; leerer Schüler-Workspace samt Aufgaben-Ordner geht
		Server::get(UserDataService::class)->purgeWorkspaces((string)$ws['owner_id']);
		Server::get(UserDataService::class)->sweep(60);
		self::assertStringContainsString('meine Arbeit', (string)self::row('nextnotes_notes', $note)['content'], 'Inhalt bleibt');
		self::assertNull(self::row('nextnotes_workspaces', $wsId), 'leerer Schüler-Workspace gelöscht');
		self::assertSame(200, $l->call('GET', "/notes/$note/attachments/$att")->status, 'Anhang bleibt');
		self::ok($l->call('GET', "/notes/$note"), 'L liest Kopie');
		$view = self::ok($l->call('GET', "/submissions/$sid"), 'L liest Abgabe')['submission'];
		self::assertSame([true, 'Schüler Eins', $uid], [$view['student_deleted'], $view['student_name'], $view['student_uid_former']]);
		$r = $l->call('POST', "/submissions/$sid/reopen");
		self::assertSame([409, 'student_deleted'], [$r->status, $r->json()['code'] ?? null], 'reopen nach Kontolöschung');

		// gleiche UID neu: sieht nichts
		$again = $this->recreate($uid);
		try {
			$this->assertNothingFor($again, $aid, $sid, $note, $att);
		} finally {
			Server::get(IUserManager::class)->get($uid)?->delete();
		}
	}

	private function assertNothingFor(ApiClient $again, int $aid, int $sid, int $note, int $att): void {
		$list = self::ok($again->call('GET', '/assignments'), 'Liste neue UID')['assignments'];
		self::assertSame([], array_values(array_filter($list, static fn ($a) => (int)$a['id'] === $aid)), 'neue UID sieht die Aufgabe nicht');
		foreach (["/submissions/$sid", "/notes/$note", "/notes/$note/attachments/$att"] as $p) {
			self::assertSame(404, $again->call('GET', $p)->status, "neue UID: $p");
		}
	}

	// H3 defensiv: Job/Sweep und Workspace-Purge löschen nur role=note; Workspace mit Abgabe-Notiz bleibt
	public function testPurgeKeepsSystemNotes(): void {
		$fx = $this->fixture();
		$sub = $fx['sub1'];
		$plain = self::rawNote($sub['wsId'], 'normal', $fx['s1']->uid);
		$token = '#' . bin2hex(random_bytes(8));
		self::setColumn('nextnotes_workspaces', $sub['wsId'], 'owner_type', 'deleted');
		self::setColumn('nextnotes_workspaces', $sub['wsId'], 'owner_id', $token);
		Server::get(UserDataService::class)->purgeWorkspaces($token);
		self::assertNull(self::row('nextnotes_notes', $plain), 'role=note gelöscht');
		self::assertNotNull(self::row('nextnotes_notes', $sub['note']), 'Abgabe-Notiz bleibt');
		self::assertNotNull(self::row('nextnotes_workspaces', $sub['wsId']), 'Workspace bleibt');
		self::assertNotNull(self::row('nextnotes_folders', $sub['folder']), 'Aufgaben-Ordner bleibt');
		self::setColumn('nextnotes_workspaces', $sub['wsId'], 'owner_type', 'user');
		self::setColumn('nextnotes_workspaces', $sub['wsId'], 'owner_id', $fx['s1']->uid);
	}

	private function recreate(string $uid): ApiClient {
		self::assertNotFalse(Server::get(IUserManager::class)->createUser($uid, self::PASSWORD), "Benutzer $uid");
		$token = Server::get(ISecureRandom::class)->generate(72, ISecureRandom::CHAR_ALPHANUMERIC);
		Server::get(IProvider::class)->generateToken($token, $uid, $uid, null, 'nn-test', IToken::PERMANENT_TOKEN);
		return new ApiClient($uid, $token);
	}
}
