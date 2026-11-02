<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Access;

use OCP\App\IAppManager;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\Server;
use PHPUnit\Framework\TestCase;

// Done M2a 7 / SPEC §4.5: alle API-Methoden NoAdminRequired, CSRF aktiv außer Allowlist, nie PublicPage
final class ControllerAttributeTest extends TestCase {
	// "Klasse::methode" mit #[NoCSRFRequired]; M2b: Anhang-GET (wird als <img src> geladen)
	private const CSRF_ALLOWLIST = ['AttachmentsApiController::show'];

	/** @return array<string, \ReflectionMethod> */
	private static function apiMethods(): array {
		$dir = Server::get(IAppManager::class)->getAppPath('nextnotes') . '/lib/Controller';
		$out = [];
		foreach (glob($dir . '/*ApiController.php') as $file) {
			$class = 'OCA\\NextNotes\\Controller\\' . basename($file, '.php');
			$rc = new \ReflectionClass($class);
			if ($rc->isAbstract()) {
				continue;
			}
			foreach ($rc->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
				if ($m->isStatic() || $m->isConstructor() || !str_starts_with($m->getDeclaringClass()->getName(), 'OCA\\NextNotes\\')) {
					continue;
				}
				$out[$rc->getShortName() . '::' . $m->getName()] = $m;
			}
		}
		return $out;
	}

	public function testEveryApiMethodHasExpectedAttributes(): void {
		$methods = self::apiMethods();
		self::assertGreaterThanOrEqual(76, count($methods), 'zu wenige API-Methoden gefunden');
		$errors = [];
		foreach ($methods as $name => $m) {
			$attrs = array_map(static fn ($a) => $a->getName(), $m->getAttributes());
			$doc = (string)$m->getDocComment();
			if (!in_array(NoAdminRequired::class, $attrs, true)) {
				$errors[] = "$name: NoAdminRequired fehlt";
			}
			$csrf = in_array(NoCSRFRequired::class, $attrs, true) || str_contains($doc, '@NoCSRFRequired');
			if ($csrf !== in_array($name, self::CSRF_ALLOWLIST, true)) {
				$errors[] = "$name: NoCSRFRequired " . ($csrf ? 'gesetzt, nicht auf Allowlist' : 'fehlt (Allowlist)');
			}
			if (in_array(PublicPage::class, $attrs, true) || str_contains($doc, '@PublicPage')) {
				$errors[] = "$name: PublicPage";
			}
		}
		foreach (array_unique(array_map(static fn ($m) => $m->getDeclaringClass(), $methods)) as $rc) {
			foreach ($rc->getAttributes() as $a) {
				if (in_array($a->getName(), [PublicPage::class, NoCSRFRequired::class], true)) {
					$errors[] = $rc->getName() . ': Klassenattribut ' . $a->getName();
				}
			}
		}
		self::assertSame([], $errors);
	}

	public function testEveryApiRouteTargetsACheckedMethod(): void {
		$methods = self::apiMethods();
		$file = Server::get(IAppManager::class)->getAppPath('nextnotes') . '/appinfo/routes.php';
		$routes = (static fn () => require $file)();
		$missing = [];
		foreach ($routes['routes'] as $r) {
			if (!str_starts_with($r['url'], '/api/v1/')) {
				continue;
			}
			[$ctl, $action] = explode('#', $r['name']);
			$class = str_replace(' ', '', ucwords(str_replace('_', ' ', $ctl))) . 'Controller';
			if (!isset($methods[$class . '::' . $action])) {
				$missing[] = $r['name'];
			}
		}
		self::assertSame([], $missing, 'Routenziele ohne geprüfte Methode');
	}
}
