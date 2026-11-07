<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Access;

use OCA\NextNotes\Db\Note;
use OCA\NextNotes\Service\AccessService;
use OCA\NextNotes\Service\WorkspaceService;
use OCA\NextNotes\Tests\Support\ApiTestCase;
use OCA\NextNotes\Tests\Support\Teams;
use OCP\Server;

// M3c1 / SPEC §3: AccessService::filterReadable (Listen ohne Einzelabfrage, Kanban-Verknüpfungen) liefert genau die Notizen
// mit noteLevel ≥ READ – für eigene, geteilte (lesen/schreiben), fremde, Team-, owner_type=deleted und fehlende Workspaces,
// auch mit (verwaisten) Freigabezeilen und Nicht-Notiz-Rollen. Sichert M5 (Teams) ab: weicht eine Seite ab, schlägt der Test an.
final class FilterReadableTest extends ApiTestCase {
	/** @var int[] */
	private array $rawWs = [];

	protected function tearDown(): void {
		$db = self::db();
		foreach ($this->rawWs as $id) {
			foreach (['nextnotes_ws_shares' => 'workspace_id', 'nextnotes_workspaces' => 'id'] as $t => $col) {
				$qb = $db->getQueryBuilder();
				$qb->delete($t)->where($qb->expr()->eq($col, $qb->createNamedParameter($id)))->executeStatement();
			}
		}
		$this->rawWs = [];
		parent::tearDown();
	}

	private function rawWorkspace(string $ownerType, string $ownerId, ?string $createdBy = null): int {
		$qb = self::db()->getQueryBuilder();
		$qb->insert('nextnotes_workspaces')->values([
			'owner_type' => $qb->createNamedParameter($ownerType),
			'owner_id' => $qb->createNamedParameter($ownerId),
			'created_by' => $qb->createNamedParameter($createdBy),
			'kind' => $qb->createNamedParameter('normal'),
			'name' => $qb->createNamedParameter('FR ' . bin2hex(random_bytes(3))),
			'created_at' => $qb->createNamedParameter(time()),
			'updated_at' => $qb->createNamedParameter(time()),
		])->executeStatement();
		$id = $qb->getLastInsertId();
		$this->rawWs[] = $id;
		return $id;
	}

	private static function rawShare(int $wsId, string $uid, string $perm, string $type = 'user'): void {
		$qb = self::db()->getQueryBuilder();
		$qb->insert('nextnotes_ws_shares')->values([
			'workspace_id' => $qb->createNamedParameter($wsId),
			'share_type' => $qb->createNamedParameter($type),
			'share_with' => $qb->createNamedParameter($uid),
			'permission' => $qb->createNamedParameter($perm),
			'created_by' => $qb->createNamedParameter('test'),
			'created_at' => $qb->createNamedParameter(time()),
		])->executeStatement();
	}

	private static function note(int $id, ?int $wsId, string $role = 'note'): Note {
		$n = new Note();
		$n->setId($id);
		$n->setWorkspaceId($wsId);
		$n->setRole($role);
		$n->setType('note');
		return $n;
	}

	public function testFilterReadableEqualsNoteLevel(): void {
		$a = $this->createUser('a');
		$b = $this->createUser('b');
		$c = $this->createUser('c');
		[, $own] = self::newWorkspace($a, 'Eigen');
		[, $bRead] = self::newWorkspace($b, 'Lesen');
		[, $bWrite] = self::newWorkspace($b, 'Schreiben');
		[, $bForeign] = self::newWorkspace($b, 'Fremd');
		[, $cOther] = self::newWorkspace($c, 'AndererGeteilt');
		$ws = Server::get(WorkspaceService::class);
		$ws->share($b->uid, $bRead, $a->uid, 'read');
		$ws->share($b->uid, $bWrite, $a->uid, 'write');
		$ws->share($c->uid, $cOther, $b->uid, 'read'); // geteilt mit jemand anderem
		$team = $this->rawWorkspace('team', 'team-' . bin2hex(random_bytes(3)));
		$teamShared = $this->rawWorkspace('team', 'team-' . bin2hex(random_bytes(3)));
		self::rawShare($teamShared, $a->uid, 'write');
		$deleted = $this->rawWorkspace('deleted', $b->uid);
		$deletedShared = $this->rawWorkspace('deleted', 'weg-' . bin2hex(random_bytes(3)));
		self::rawShare($deletedShared, $a->uid, 'write');
		$deletedOwnId = $this->rawWorkspace('deleted', $a->uid); // ehemals eigener Workspace (owner_id bleibt), Typ deleted
		$teamTypeShare = $this->rawWorkspace('user', $c->uid);
		self::rawShare($teamTypeShare, $a->uid, 'write', 'team'); // Freigabe an Team-ID, die zufällig der UID gleicht
		$missing = 2147480000 + random_int(0, 3000);

		$cases = [
			'eigen' => $own, 'geteilt lesen' => $bRead, 'geteilt schreiben' => $bWrite, 'fremd' => $bForeign, 'fremd an Dritte geteilt' => $cOther,
			'Team' => $team, 'Team mit Freigabezeile' => $teamShared, 'deleted' => $deleted, 'deleted mit Freigabezeile' => $deletedShared,
			'deleted, owner_id = uid' => $deletedOwnId, 'share_type team' => $teamTypeShare, 'fehlender Workspace' => $missing,
		];
		$notes = [];
		$labels = [];
		$i = 900000000;
		foreach ($cases as $label => $wsId) {
			foreach (['note', 'template', 'submission'] as $role) {
				$notes[] = self::note(++$i, $wsId, $role);
				$labels[$i] = "$label/$role";
			}
		}
		$notes[] = self::note(++$i, null);
		$labels[$i] = 'ohne Workspace';

		$access = Server::get(AccessService::class);
		$access->resetCache();
		$filtered = array_map(static fn (Note $n) => $labels[(int)$n->getId()], $access->filterReadable($a->uid, $notes));
		$byLevel = [];
		foreach ($notes as $n) {
			if ($access->noteLevel($a->uid, $n) >= AccessService::READ) {
				$byLevel[] = $labels[(int)$n->getId()];
			}
		}
		sort($filtered);
		sort($byLevel);
		self::assertSame($byLevel, $filtered, 'filterReadable weicht von noteLevel ≥ READ ab');
		// Erwartung nach SPEC §3 (Team-Rechte bis M5 deny by default)
		self::assertSame(['eigen/note', 'geteilt lesen/note', 'geteilt schreiben/note'], $byLevel, 'Lesbare Menge nicht wie Spec');
	}

	// M5a Done 6: Team-Fälle (Mitglied, Ex-Mitglied, Gruppe, eingeladen, versteckt, verwaist, Freigabezeile) – filterReadable ≡ noteLevel ≥ READ
	public function testFilterReadableEqualsNoteLevelForTeams(): void {
		$teams = new Teams();
		try {
			$owner = $this->createUser('to');
			$a = $this->createUser('ta');
			$t = fn (string $label, int $config = 0) => $teams->create($owner->uid, $label . ' ' . bin2hex(random_bytes(3)), $config);
			$member = $t('Mitglied');
			$teams->addUser($member, $owner->uid, $a->uid);
			$former = $t('Ehemals');
			$teams->remove($owner->uid, $teams->addUser($former, $owner->uid, $a->uid));
			$group = $t('Gruppe');
			$teams->addGroup($group, $owner->uid, [$a->uid]);
			$invited = $t('Eingeladen', Teams::INVITE);
			$teams->addUser($invited, $owner->uid, $a->uid);
			$hidden = $t('Versteckt', Teams::HIDDEN);
			$teams->addUser($hidden, $owner->uid, $a->uid);
			$orphanMine = $t('VerwaistMeins');
			$orphanOther = $t('VerwaistFremd');
			$teams->addUser($orphanOther, $owner->uid, $a->uid);
			$cases = [
				'Mitglied' => $this->rawWorkspace('team', $member, $owner->uid),
				'Ex-Mitglied' => $this->rawWorkspace('team', $former, $owner->uid),
				'Gruppe' => $this->rawWorkspace('team', $group, $owner->uid),
				'eingeladen' => $this->rawWorkspace('team', $invited, $owner->uid),
				'versteckt' => $this->rawWorkspace('team', $hidden, $a->uid),
				'verwaist, created_by' => $this->rawWorkspace('team', $orphanMine, $a->uid),
				'verwaist, Mitglied' => $this->rawWorkspace('team', $orphanOther, $owner->uid),
				'Freigabezeile' => $this->rawWorkspace('team', $invited, $owner->uid),
			];
			self::rawShare($cases['Freigabezeile'], $a->uid, 'write');
			foreach ([$orphanMine, $orphanOther] as $id) {
				self::db()->insertIgnoreConflict('nextnotes_teams', ['team_id' => $id, 'state' => 'orphaned', 'missing_count' => 0, 'created_at' => time(), 'updated_at' => time()]);
			}
			$notes = [];
			$labels = [];
			$i = 910000000;
			foreach ($cases as $label => $wsId) {
				foreach (['note', 'submission'] as $role) {
					$notes[] = self::note(++$i, $wsId, $role);
					$labels[$i] = "$label/$role";
				}
			}
			$access = Server::get(AccessService::class);
			$access->resetCache();
			$filtered = array_map(static fn (Note $n) => $labels[(int)$n->getId()], $access->filterReadable($a->uid, $notes));
			$byLevel = [];
			foreach ($notes as $n) {
				if ($access->noteLevel($a->uid, $n) >= AccessService::READ) {
					$byLevel[] = $labels[(int)$n->getId()];
				}
			}
			sort($filtered);
			sort($byLevel);
			self::assertSame($byLevel, $filtered, 'filterReadable weicht von noteLevel ≥ READ ab (Teams)');
			self::assertSame(['Gruppe/note', 'Mitglied/note', 'verwaist, created_by/note'], $byLevel, 'Lesbare Menge nicht wie SPEC §3');
		} finally {
			$db = self::db();
			foreach ([$orphanMine ?? null, $orphanOther ?? null] as $id) {
				if ($id !== null) {
					$qb = $db->getQueryBuilder();
					$qb->delete('nextnotes_teams')->where($qb->expr()->eq('team_id', $qb->createNamedParameter($id)))->executeStatement();
				}
			}
			$teams->cleanup();
		}
	}
}
