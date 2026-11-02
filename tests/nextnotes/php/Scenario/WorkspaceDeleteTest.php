<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Scenario;

use OCA\NextNotes\Service\WorkspaceService;
use OCA\NextNotes\Tests\Support\ApiClient;
use OCA\NextNotes\Tests\Support\ApiTestCase;
use OCP\Config\IUserConfig;
use OCP\Server;

// SPEC §11.2 B18: Workspace löschen – Inhalte nur in eigenen, nicht geteilten Workspace (nie „Archives“), sonst neuer Standard-Workspace; letzter → 400
final class WorkspaceDeleteTest extends ApiTestCase {
	private static function wsByName(string $uid): array {
		$out = [];
		foreach (self::dump($uid)['workspaces'] as $w) {
			$out[$w['name']] = (int)$w['id'];
		}
		return $out;
	}

	/** @return int[] Workspace-IDs der Notizen mit $marker in der Überschrift */
	private static function notesWs(string $uid, string $marker): array {
		$out = [];
		foreach (self::dump($uid)['notes'] as $n) {
			if (str_contains((string)$n['heading'], $marker)) {
				$out[] = (int)$n['workspace_id'];
			}
		}
		return $out;
	}

	private function owner(string $role): ApiClient {
		$c = $this->createUser($role);
		Server::get(IUserConfig::class)->setValueString($c->uid, 'core', 'lang', 'de');
		self::ok($c->call('GET', '/workspaces'), 'Erstzugriff');
		return $c;
	}

	private static function guestSees(ApiClient $b, string $marker): bool {
		foreach (['/notes', '/trash', '/folders'] as $p) {
			if (stripos($b->call('GET', $p)->body, $marker) !== false) {
				return true;
			}
		}
		foreach (self::ok($b->call('GET', '/workspaces'), 'B workspaces')['workspaces'] as $w) {
			foreach (['/notes', '/folders'] as $p) {
				if (stripos($b->call('GET', $p . self::q(['workspace' => $w['name']]))->body, $marker) !== false) {
					return true;
				}
			}
		}
		return false;
	}

	public function testContentGoesToOwnUnsharedWorkspaceNeverArchivesOrShared(): void {
		$a = $this->owner('wdA');
		$b = $this->createUser('wdB');
		$svc = Server::get(WorkspaceService::class);
		$m = 'wdm' . bin2hex(random_bytes(4));

		// Kandidaten, die nicht in Frage kommen (geteilt, „Archives“), liegen alphabetisch und nach ID vor dem Ziel
		foreach (['Aaa geteilt', 'Del'] as $n) {
			self::ok($a->call('POST', '/workspaces', ['name' => $n]), "ws $n");
		}
		$tmp = self::ok($a->call('POST', '/notes', ['heading' => 'Archivieren', 'content' => '<p>x</p>', 'workspace' => 'Del']), 'Notiz')['note']['id'];
		self::ok($a->call('POST', "/notes/$tmp/archive"), 'Archiv anlegen');
		self::ok($a->call('POST', '/workspaces', ['name' => 'Zzz eigen']), 'ws Zzz eigen');
		$ws = self::wsByName($a->uid);
		self::assertArrayHasKey('Archives', $ws, 'Archiv-Workspace fehlt: ' . json_encode(array_keys($ws)));
		self::assertLessThan($ws['Zzz eigen'], $ws['Archives']);
		$svc->share($a->uid, $ws['Aaa geteilt'], $b->uid);
		$svc->share($a->uid, $ws['Notizen'], $b->uid);
		$svc->share($a->uid, $ws['Del'], $b->uid);

		self::ok($a->call('POST', '/notes', ['heading' => "A $m", 'content' => "<p>$m</p>", 'workspace' => 'Del']), 'A-Notiz');
		self::ok($a->call('POST', '/folders', ['folder_name' => "Ordner $m", 'workspace' => 'Del']), 'A-Ordner');
		// vom Gast verfasst
		$keyForB = array_values(array_filter(self::workspaceNames($b), static fn ($n) => str_starts_with($n, 'Del')))[0] ?? 'Del';
		self::ok($b->call('POST', '/notes', ['heading' => "Gast $m", 'content' => "<p>$m</p>", 'workspace' => $keyForB]), 'Gast-Notiz');
		self::assertTrue(self::guestSees($b, $m), 'Kontrolle: Gast sieht Inhalte vor dem Löschen');

		self::ok($a->call('DELETE', '/workspaces/Del'), 'Del löschen');
		$targets = array_unique(self::notesWs($a->uid, $m));
		self::assertSame([$ws['Zzz eigen']], array_values($targets), 'Ziel ist nicht der eigene, ungeteilte Workspace');
		self::assertCount(2, self::notesWs($a->uid, $m), 'A- und Gast-Notiz beide verschoben');
		self::assertFalse(self::guestSees($b, $m), 'Gast sieht verschobene Inhalte');
	}

	public function testNoCandidateCreatesNewDefaultWorkspace(): void {
		$a = $this->owner('wdC');
		$b = $this->createUser('wdD');
		$svc = Server::get(WorkspaceService::class);
		$m = 'wdn' . bin2hex(random_bytes(4));
		self::ok($a->call('POST', '/workspaces', ['name' => 'Weg']), 'ws Weg');
		$ws = self::wsByName($a->uid);
		$svc->share($a->uid, $ws['Notizen'], $b->uid);
		self::ok($a->call('POST', '/notes', ['heading' => "A $m", 'content' => "<p>$m</p>", 'workspace' => 'Weg']), 'Notiz');

		self::ok($a->call('DELETE', '/workspaces/Weg'), 'Weg löschen');
		$ws = self::wsByName($a->uid);
		self::assertArrayHasKey('Notizen 2', $ws, 'neuer Standard-Workspace „Notizen 2“ fehlt: ' . json_encode(array_keys($ws)));
		self::assertSame([$ws['Notizen 2']], self::notesWs($a->uid, $m));
		self::assertFalse(self::guestSees($b, $m), 'Gast sieht verschobene Inhalte');
	}

	public function testLastOwnWorkspaceIs400(): void {
		$a = $this->owner('wdE');
		$before = self::dump($a->uid);
		self::assertCount(1, $before['workspaces']);
		$r = $a->call('DELETE', '/workspaces/Notizen');
		self::assertSame(400, $r->status, $r->describe());
		self::assertSame($before, self::dump($a->uid));
	}
}
