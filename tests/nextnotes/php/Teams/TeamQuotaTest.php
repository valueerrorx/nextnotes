<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Teams;

use OCA\NextNotes\Db\WorkspaceMapper;
use OCA\NextNotes\Service\AccessService;
use OCA\NextNotes\Service\Exception\ForbiddenException;
use OCA\NextNotes\Service\Exception\TeamUnavailableException;
use OCA\NextNotes\Service\LeaveRequest;
use OCA\NextNotes\Service\TeamService;
use OCA\NextNotes\Tests\Support\ApiClient;
use OCA\NextNotes\Tests\Support\TeamTestCase;
use OCP\Server;

// M5a-r1: H1 Quote bei Besitzerwechsel auf allen Verschiebe-Wegen, H4 Nachprüfung unter Sperre, H2 exists()
final class TeamQuotaTest extends TeamTestCase {
	private const COMPAT = '/index.php/apps/nextnotes/workspaces.php';

	protected function tearDown(): void {
		self::occ('config:app:delete', 'nextnotes', 'attachment_quota_mb');
		parent::tearDown();
	}

	private static function occ(string ...$args): int {
		exec('php /var/www/html/occ ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $out, $rc);
		return $rc;
	}

	private static function folder(ApiClient $c, string $ws): int {
		return (int)self::ok($c->call('POST', '/folders', ['folder_name' => 'F ' . bin2hex(random_bytes(3)), 'workspace' => $ws]), 'Ordner')['folder_id'];
	}

	private static function attach(ApiClient $c, int $note, int $bytes): int {
		return (int)self::ok($c->upload("/notes/$note/attachments", 'q.bin', str_repeat('q', $bytes)), "Anhang an $note")['attachment_id'];
	}

	private static function setWs(int $note, int $wsId): void {
		$qb = self::db()->getQueryBuilder();
		$qb->update('nextnotes_notes')->set('workspace_id', $qb->createNamedParameter($wsId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($note, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))->executeStatement();
	}

	// Jeder Weg privat → Team (anderer Besitzer) mit Anhängen über der Team-Quote: 413, nichts verschoben; unter der Quote 200
	public function testOwnerChangeQuotaOnAllMoveWays(): void {
		$a = $this->createUser('qma');
		$t = $this->teams->create($a->uid, 'Quote Wege ' . bin2hex(random_bytes(3)));
		[$tk, $tId] = self::teamWs($a, $t, 'QT');
		[$pk, $pId] = self::newWorkspace($a, 'QP');
		$filler = self::newNote($a, $tk, 'Füller', '<p>f</p>');
		self::assertSame(0, self::occ('config:app:set', 'nextnotes', 'attachment_quota_mb', '--value=2', '--type=integer'));
		sleep(4);
		$fillAtt = self::attach($a, $filler, 1500000);

		$n = self::newNote($a, $pk, 'Umzug', '<p>u</p>');
		self::attach($a, $n, 700000);
		$f = self::folder($a, $pk);
		$nf = self::newNote($a, $pk, 'Im Ordner', '<p>o</p>', $f);
		self::attach($a, $nf, 700000);

		$ways = [
			'PATCH workspace' => fn () => $a->call('PATCH', "/notes/$n", ['workspace' => $tk]),
			'PATCH workspace mit Inhalt' => fn () => $a->call('PATCH', "/notes/$n", ['workspace' => $tk, 'content' => '<p>u</p>']),
			'moveToFolder' => fn () => $a->call('POST', "/notes/$n/folder", ['workspace' => $tk]),
			'Ordner verschieben' => fn () => $a->call('POST', "/folders/$f/move", ['target_workspace' => $tk]),
			'move-files' => fn () => $a->call('POST', '/folders/move-files', ['source_folder_id' => $f, 'target_folder_id' => 0, 'target_workspace' => $tk]),
			'move_notes' => fn () => $a->form(self::COMPAT, ['action' => 'move_notes', 'name' => $pk, 'target' => $tk]),
		];
		foreach ($ways as $label => $call) {
			$r = $call();
			self::assertSame(413, $r->status, "$label über der Team-Quote: " . $r->describe());
			self::assertSame($pId, (int)self::noteRow($n)['workspace_id'], "$label: Notiz unverändert");
			self::assertSame($pId, (int)self::noteRow($nf)['workspace_id'], "$label: Ordner-Notiz unverändert");
		}

		// Rückweg Team → privat zählt gegen die Benutzer-Quote
		$r = $a->call('PATCH', "/notes/$filler", ['workspace' => $pk]);
		self::assertSame(413, $r->status, 'Team → privat über der Benutzer-Quote: ' . $r->describe());
		self::assertSame($tId, (int)self::noteRow($filler)['workspace_id']);

		// Platz schaffen: Wege gelingen
		self::ok($a->call('DELETE', "/notes/$filler/attachments/$fillAtt"), 'Füller-Anhang löschen');
		self::ok($a->call('PATCH', "/notes/$n", ['workspace' => $tk]), 'PATCH unter der Quote');
		self::assertSame($tId, (int)self::noteRow($n)['workspace_id']);
		self::ok($a->call('POST', "/folders/$f/move", ['target_workspace' => $tk]), 'Ordner unter der Quote');
		self::assertSame($tId, (int)self::noteRow($nf)['workspace_id']);
	}

	// H4: bei der Eigene-Notiz-Ausnahme bricht eine zwischen Prüfung und Verschieben angekommene fremde Notiz ab (403, Rollback)
	public function testMoveLeavingRejectsForeignArrival(): void {
		$a = $this->createUser('qla');
		$b = $this->createUser('qlb');
		$t = $this->teams->create($a->uid, 'Nachprüfung ' . bin2hex(random_bytes(3)));
		$this->teams->addUser($t, $a->uid, $b->uid);
		[, $tId] = self::teamWs($a, $t, 'NT');
		[, $pId] = self::newWorkspace($a, 'NP');
		$own = self::rawNote($tId, 'eigen', $a->uid);
		$mapper = Server::get(WorkspaceMapper::class);
		$access = Server::get(AccessService::class);
		$access->resetCache();
		$req = new LeaveRequest($mapper->find($tId), $mapper->find($pId), LeaveRequest::NOTES, [$own]);
		$foreign = 0;
		try {
			$access->moveLeaving($a->uid, $req, function () use ($own, $pId, $b, &$foreign): void {
				self::setWs($own, $pId);
				// paralleles Anlegen simuliert: fremde Notiz landet mit im Ziel
				$foreign = self::rawNote($pId, 'fremd', $b->uid);
			});
			self::fail('fremde Notiz im Ziel muss 403 liefern');
		} catch (ForbiddenException) {
		}
		self::assertGreaterThan(0, $foreign);
		// nur eigene angekommen → erlaubt
		$own2 = self::rawNote($tId, 'eigen2', $a->uid);
		$req2 = new LeaveRequest($mapper->find($tId), $mapper->find($pId), LeaveRequest::NOTES, [$own2]);
		$access->moveLeaving($a->uid, $req2, static function () use ($own2, $pId): void {
			self::setWs($own2, $pId);
		});
		self::assertSame($pId, (int)self::noteRow($own2)['workspace_id']);
	}

	// H2: vorhanden → Name; gelöscht → null; unbekannte ID-Form bleibt „fehlt“ nur bei eindeutiger Circles-Meldung
	public function testExistsDistinguishesDeleted(): void {
		$a = $this->createUser('qea');
		$t = $this->teams->create($a->uid, 'Existenz ' . bin2hex(random_bytes(3)));
		$svc = Server::get(TeamService::class);
		try {
			$found = $svc->exists($t);
		} catch (TeamUnavailableException $e) {
			self::fail('vorhandenes Team: ' . $e->getMessage());
		}
		self::assertSame($t, $found['id'] ?? null);
		$this->teams->destroy($a->uid, $t);
		self::assertNull($svc->exists($t), 'gelöschtes Team → fehlt');
	}
}
