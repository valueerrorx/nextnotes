<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\OneNote;

use OCP\App\IAppManager;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Server;

// M9b Done 7, 8 (reauth fortsetzen), 10, 11, 11a, 11b: Konto-Zustände, Budget/Backoff, Aufräumen, Konfigurationswechsel
final class AccountStateTest extends ImportTestCase {
	public function testDisabledUser(): void {
		$u = $this->createUser('ad');
		$this->notebook('me', 'nbD', 1, 2);
		$id = $this->started($u->uid, [['root' => 'me', 'notebook' => 'nbD', 'items' => null]]);
		$user = Server::get(IUserManager::class)->get($u->uid);
		$user->setEnabled(false);
		try {
			$this->importer()->runDue();
		} finally {
			$user->setEnabled(true);
		}
		self::assertSame(['reauth', 'user_disabled'], [self::row($id)['status'], self::row($id)['error_code']]);
		self::assertNoSecrets($id);
		self::assertSame(0, $this->graph->count());
	}

	public function testAppAccessRemoved(): void {
		$u = $this->createUser('aa');
		$this->notebook('me', 'nbA', 1, 2);
		$id = $this->started($u->uid, [['root' => 'me', 'notebook' => 'nbA', 'items' => null]]);
		$groups = Server::get(IGroupManager::class);
		$gid = 'nn-onenote-' . bin2hex(random_bytes(3));
		$group = $groups->createGroup($gid);
		$apps = Server::get(IAppManager::class);
		try {
			$apps->enableAppForGroups('nextnotes', [$group]);
			$this->importer()->runDue();
		} finally {
			$apps->enableApp('nextnotes');
			$group->delete();
		}
		self::assertSame(['reauth', 'app_disabled'], [self::row($id)['status'], self::row($id)['error_code']]);
		self::assertNoSecrets($id);
		self::assertSame(0, $this->graph->count());
	}

	// Done 8: Neuanmeldung nach reauth setzt fort, ohne Duplikate
	public function testReauthContinues(): void {
		$u = $this->createUser('ar');
		$this->notebook('me', 'nbR', 1, 3);
		$id = $this->started($u->uid, [['root' => 'me', 'notebook' => 'nbR', 'items' => null]]);
		$this->graph->errors['me/onenote/pages/nbR-s0-p-1/content'] = [401, 401];
		$this->importer()->runDue();
		self::assertSame('reauth', self::row($id)['status']);
		self::assertCount(1, self::notesOf($u->uid));
		$svc = $this->connectService($this->http);
		$c = $svc->connect($u->uid, $id);
		$svc->callback($u->uid, self::stateOf($c['authorize_url']), 'code-x', '');
		self::assertSame('queued', self::row($id)['status']);
		self::assertSame('done', $this->runUntilDone($id));
		self::assertCount(3, self::notesOf($u->uid));
	}

	// Done 7: 429 → next_run_at, kein Folgeaufruf; Backoff erst nach angelegter Seite zurück; Laufbudget 50 Anfragen
	public function testBudgetAndBackoff(): void {
		$u = $this->createUser('ab');
		$this->notebook('me', 'nbB', 1, 80);
		$id = $this->started($u->uid, [['root' => 'me', 'notebook' => 'nbB', 'items' => null]]);
		$this->graph->errors['me/onenote/pages/nbB-s0-p-3/content'] = [429, 500, 200];
		$this->importer()->runDue();
		$row = self::row($id);
		self::assertSame(['queued', 1, $this->now + 300], [$row['status'], (int)$row['backoff_level'], (int)$row['next_run_at']]);
		self::assertSame(6, $this->graph->count(), 'kein Folgeaufruf nach 429');
		$this->now += 300;
		$this->importer()->runDue();
		$row = self::row($id);
		self::assertSame([2, $this->now + 600], [(int)$row['backoff_level'], (int)$row['next_run_at']], '5xx wie 429, Stufe nicht zurückgesetzt');
		$this->now += 600;
		$before = $this->graph->count();
		$this->importer()->runDue();
		self::assertSame(0, (int)self::row($id)['backoff_level'], 'angelegte Seite setzt zurück');
		self::assertSame(50, $this->graph->count() - $before, 'Laufbudget 50 Anfragen');
		self::assertSame('done', $this->runUntilDone($id));

		// 10 Fehlversuche ohne angelegte Seite → failed
		$v = $this->createUser('ab2');
		$this->notebook('me', 'nbE', 1, 1);
		$id2 = $this->started($v->uid, [['root' => 'me', 'notebook' => 'nbE', 'items' => null]]);
		$this->graph->errors['me/onenote/pages/nbE-s0-p-0/content'] = 503;
		self::assertSame('failed', $this->runUntilDone($id2, 15));
		self::assertSame(['graph_unavailable', 9], [self::row($id2)['error_code'], (int)self::row($id2)['backoff_level']]);
		self::assertNoSecrets($id2);
	}

	// Done 11, 11a: select 24 h → expired, reauth 7 Tage → expired; auch bei abgeschalteter Funktion, ohne Graph
	public function testCleanupAlsoWhenDisabled(): void {
		$u = $this->createUser('ac');
		$v = $this->createUser('ac2');
		$sel = $this->signIn($this->connectService($this->http), $u->uid);
		$re = self::insertImport($v->uid, 'reauth', ['updated_at' => $this->now]);
		$this->configure(self::TENANT, self::CLIENT, '', true);
		$this->now += 86400 - 10;
		$this->importer()->runDue();
		self::assertSame('select', self::row($sel)['status']);
		$this->now += 20;
		$this->importer()->runDue();
		self::assertSame('expired', self::row($sel)['status']);
		self::assertNoSecrets($sel);
		self::assertSame('reauth', self::row($re)['status']);
		$this->now += 6 * 86400;
		$this->importer()->runDue();
		self::assertSame('expired', self::row($re)['status']);
		self::assertSame(0, $this->graph->count());
		// abgeschlossene Importe nach 30 Tagen weg
		$this->now += 31 * 86400;
		$this->importer()->runDue();
		self::assertNull(self::row($sel));
		self::assertNull(self::row($re));
	}

	// Done 11b: Team-Schalter aus → select mit team_scopes=1 und queued mit group:-Auswahl failed; nur me läuft weiter
	public function testTeamSwitchOff(): void {
		$a = $this->createUser('at');
		$b = $this->createUser('at2');
		$c = $this->createUser('at3');
		$sel = $this->signIn($this->connectService($this->http), $a->uid);
		$grp = $this->started($b->uid, [['root' => 'group:team1', 'notebook' => 'nbG', 'items' => null]]);
		$me = $this->started($c->uid, [['root' => 'me', 'notebook' => 'nbM', 'items' => null]]);
		$this->notebook('me', 'nbM', 1, 1);
		$this->configure(self::TENANT, self::CLIENT, self::SECRET, false);
		$this->importer()->runDue();
		foreach ([$sel, $grp] as $id) {
			self::assertSame(['failed', 'config_changed'], [self::row($id)['status'], self::row($id)['error_code']]);
			self::assertNoSecrets($id);
		}
		self::assertSame('done', self::row($me)['status']);
		self::assertSame(0, $this->graph->count('groups/'));
	}
}
