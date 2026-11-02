<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Routes;

use OCP\App\IAppManager;
use OCP\Server;
use PHPUnit\Framework\TestCase;

// Done M2a 2 / SPEC §13 M2.1: routes.php = Router-Zeilen index.php − „streichen“ − „ausstehend“
final class RouteDiffTest extends TestCase {
	// Inventurnummern, die erst M2b/M2c liefern (Plan §0); M2c leert die Liste
	private const PENDING = '1,4-7,20,24,28-31,46,50-54,84,110-114,8-19,47-49,55-64,115-117,124,126-130,142,160';
	// Umfang M2a laut Plan §0 (Gegenkontrolle der Rechnung)
	private const M2A = '2,3,21-23,32-41,43-45,69-83,85-91,93-95,100-109';

	private static function numbers(string $spec): array {
		$out = [];
		foreach (explode(',', $spec) as $part) {
			[$a, $b] = array_pad(explode('-', $part), 2, null);
			foreach (range((int)$a, (int)($b ?? $a)) as $n) {
				$out[] = $n;
			}
		}
		sort($out);
		return $out;
	}

	private static function norm(string $verb, string $path): string {
		return strtoupper($verb) . ' ' . preg_replace('/\{[^}]+\}/', '{}', $path);
	}

	private static function refDir(): string {
		$dir = getenv('NN_REF_DIR');
		self::assertNotFalse($dir, 'NN_REF_DIR fehlt (run-phpunit.sh setzt es)');
		return $dir;
	}

	/** @return array<int, string> Nummer → "VERB /pfad" aus index.php */
	private static function routerLines(): array {
		$src = file_get_contents(self::refDir() . '/index.php');
		preg_match_all('/\$router->(get|post|put|patch|delete)\(\s*\'([^\']+)\'/', $src, $m, PREG_SET_ORDER);
		$out = [];
		foreach ($m as $i => $x) {
			$out[$i + 1] = self::norm($x[1], $x[2]);
		}
		return $out;
	}

	/** @return array<int, array{0: string, 1: string}> Nummer → [Route, Status] aus Inventur A §1.1 */
	private static function inventory(): array {
		$md = file_get_contents(self::refDir() . '/A-backend.md');
		$start = strpos($md, '### 1.1');
		$end = strpos($md, "\n### ", $start + 5);
		$section = substr($md, $start, $end === false ? null : $end - $start);
		$out = [];
		foreach (explode("\n", $section) as $line) {
			// nur Router-Zeilen (#185 liegt vor dem Router, index.php:62)
			if (preg_match('/^\|\s*(\d+)\s*\|\s*(GET|POST|PUT|PATCH|DELETE)\s*\|\s*(\S+)\s*\|([^|]*)\|.*\|\s*([^|]+?)\s*\|\s*$/', $line, $m) && !str_contains($m[4], 'vor Router')) {
				$out[(int)$m[1]] = [self::norm($m[2], $m[3]), $m[5]];
			}
		}
		return $out;
	}

	/** @return string[] "VERB /pfad" der App-Routen unter /api/v1 */
	private static function appRoutes(): array {
		$file = Server::get(IAppManager::class)->getAppPath('nextnotes') . '/appinfo/routes.php';
		$routes = (static fn () => require $file)();
		$out = [];
		foreach ($routes['routes'] as $r) {
			if (str_starts_with($r['url'], '/api/v1/')) {
				$out[] = self::norm($r['verb'] ?? 'GET', substr($r['url'], strlen('/api/v1')));
			}
		}
		return $out;
	}

	public function testInventoryMatchesRouter(): void {
		$router = self::routerLines();
		$inv = self::inventory();
		self::assertCount(184, $router, 'Router-Zeilen index.php');
		self::assertSame(array_keys($router), array_keys($inv), 'Inventur-Nummern ≠ Router-Zeilen');
		foreach ($router as $n => $route) {
			self::assertSame($route, $inv[$n][0], "Inventur #$n");
		}
		foreach (self::numbers(self::PENDING) as $n) {
			self::assertStringStartsNotWith('streichen', $inv[$n][1], "ausstehend #$n ist gestrichen");
		}
	}

	public function testRoutesMatchUpstreamMinusStruckMinusPending(): void {
		$inv = self::inventory();
		$pending = self::numbers(self::PENDING);
		$expected = [];
		foreach ($inv as $n => [$route, $status]) {
			if (str_starts_with($status, 'streichen') || in_array($n, $pending, true)) {
				continue;
			}
			$expected[$n] = $route;
		}
		self::assertSame(self::numbers(self::M2A), array_keys($expected), 'Rechnung ergibt nicht den M2a-Umfang des Plans');

		$actual = self::appRoutes();
		self::assertSame([], array_values(array_diff($actual, $expected)), 'Routen in routes.php ohne Upstream-Gegenstück');
		self::assertSame([], array_values(array_diff($expected, $actual)), 'Upstream-Routen fehlen in routes.php');
		self::assertSame(count($actual), count(array_unique($actual)), 'doppelte Routen in routes.php');
		self::assertCount(53, $actual);
	}
}
