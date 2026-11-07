<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Teams;

use OCA\NextNotes\Command\TeamOrphanCheck;
use OCA\NextNotes\Service\TeamService;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

// Plan M5c Done 1/7/8: occ nextnotes:team:orphan-check (--dry-run, --force), Massenschutz, Fehler → nichts ändern
final class OrphanCheckCommandTest extends CleanupTestCase {
	/** @return array{0: int, 1: string} */
	private function runCommand(TeamService $teams, array $options = []): array {
		$cmd = new TeamOrphanCheck($this->service($teams));
		$out = new BufferedOutput();
		$rc = $cmd->run(new ArrayInput($options), $out);
		return [$rc, $out->fetch()];
	}

	public function testDryRunListsAndChangesNothing(): void {
		$x = $this->rawTeam('dry');
		$y = $this->rawTeam('dry', 'active', null, null, 1);
		[$rc, $out] = $this->runCommand($this->stubTeams([$x]), ['--dry-run' => true]);
		self::assertSame(0, $rc, $out);
		self::assertMatchesRegularExpression('/' . preg_quote($x, '/') . '.*missing.*orphan/', $out);
		self::assertMatchesRegularExpression('/' . preg_quote($y, '/') . '.*present.*reset/', $out);
		self::assertStringContainsString('nothing was changed', $out);
		self::assertSame('active', self::teamRowOrNull($x)['state']);
		self::assertSame(1, (int)self::teamRowOrNull($y)['missing_count']);
	}

	public function testWithoutDryRunOrphansAndReactivates(): void {
		$x = $this->rawTeam('now');
		$back = $this->rawTeam('back', 'orphaned', $this->now - 5 * self::DAY);
		$present = $this->rawTeam('pres', 'active', null, null, 1);
		[$rc, $out] = $this->runCommand($this->stubTeams([$x]));
		self::assertSame(0, $rc, $out);
		self::assertSame('orphaned', self::teamRowOrNull($x)['state'], 'fehlendes Team sofort verwaist (Admin bestätigt)');
		self::assertSame('active', self::teamRowOrNull($back)['state'], 'Rückweg');
		self::assertSame(0, (int)self::teamRowOrNull($present)['missing_count'], 'vorhanden → 0');
	}

	public function testMassProtectionAndForce(): void {
		$x = $this->rawTeam('mp');
		$y = $this->rawTeam('mp');
		[$rc, $out] = $this->runCommand($this->stubTeams([$x, $y]));
		self::assertSame(2, $rc, $out);
		self::assertStringContainsString('--force', $out);
		self::assertSame('active', self::teamRowOrNull($x)['state'], 'ohne --force nichts verwaist');
		self::assertSame(0, self::rowCount('notifications', ['app' => 'nextnotes', 'subject' => 'cleanup_suspicious']), 'occ benachrichtigt nicht');
		[$rc, $out] = $this->runCommand($this->stubTeams([$x, $y]), ['--force' => true]);
		self::assertSame(0, $rc, $out);
		self::assertSame('orphaned', self::teamRowOrNull($x)['state']);
		self::assertSame('orphaned', self::teamRowOrNull($y)['state']);
	}

	public function testUnavailableOrErrorChangesNothing(): void {
		$x = $this->rawTeam('un');
		[$rc, $out] = $this->runCommand($this->stubTeams([$x], [], null, false));
		self::assertSame(1, $rc, $out);
		self::assertStringContainsString('circles:check', $out);
		[$rc] = $this->runCommand($this->stubTeams([$x], [$x]), ['--force' => true]);
		self::assertSame(1, $rc, 'Fehler auch mit --force');
		self::assertSame('active', self::teamRowOrNull($x)['state']);
		self::assertSame(0, (int)self::teamRowOrNull($x)['missing_count']);
	}

	// H1: Team mit Fehler wird gelistet und übersprungen, ein fehlendes trotzdem verwaist
	public function testErrorTeamListedAndSkipped(): void {
		$x = $this->rawTeam('el');
		$y = $this->rawTeam('el');
		$z = $this->rawTeam('el');
		[$rc, $out] = $this->runCommand($this->stubTeams([$x], [$y]));
		self::assertSame(0, $rc, $out);
		self::assertMatchesRegularExpression('/' . preg_quote($y, '/') . '.*error/', $out);
		self::assertStringContainsString('could not be checked', $out);
		self::assertSame('orphaned', self::teamRowOrNull($x)['state'], 'fehlendes Team verwaist');
		self::assertSame('active', self::teamRowOrNull($y)['state'], 'Team mit Fehler unverändert');
		self::assertSame('active', self::teamRowOrNull($z)['state']);
	}

	// echter CLI-Aufruf: ohne Event gelöschtes Team wird sofort verwaist, versteckter Kreis bleibt
	public function testRealCli(): void {
		$a = $this->createUser('oc');
		$t = $this->teams->create($a->uid, 'CLI ' . bin2hex(random_bytes(3)));
		self::teamWs($a, $t);
		$h = $this->teams->create($a->uid, 'Versteckt ' . bin2hex(random_bytes(3)));
		self::teamWs($a, $h);
		$this->teams->setConfig($h, \OCA\NextNotes\Tests\Support\Teams::HIDDEN);
		$this->teams->destroy($a->uid, $t, true);
		self::setTeamRow($t, ['state' => 'active', 'orphaned_at' => null]);
		[$rc, $out] = self::occ('nextnotes:team:orphan-check');
		self::assertSame(0, $rc, $out);
		self::assertSame('orphaned', self::teamRowOrNull($t)['state'], $out);
		self::assertSame('active', self::teamRowOrNull($h)['state'], 'versteckter Kreis bleibt');
		self::assertSame(0, (int)self::teamRowOrNull($h)['missing_count']);
	}
}
