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

	// M3c1 / SPEC §4.5: Seiten- und Kompatibilitätsrouten. NoCSRFRequired nur für Seiten-GETs und GET-Downloads ohne Seiteneffekt;
	// api_note_info (JSON-Daten per Adapter) und das Such-POST prüfen CSRF.
	private const PAGE_COMPAT_NOCSRF = [
		'PageController::index', 'PageController::dashboard', 'PageController::login', 'PageController::logout',
		'PageController::audioPlayer', 'PageController::revisions', 'PageController::attachments', 'PageController::kanbanContent',
		'CompatController::downloadNote', 'CompatController::exportNote', 'CompatController::exportFolder', 'CompatController::attachments',
		// M4d: ZIP-Exporte, GET-Downloads ohne Seiteneffekt
		'CompatController::exportEntries', 'CompatController::exportStructured', 'CompatController::exportAttachments',
		// M3c2: Editor-Seite, GET ohne Seiteneffekt (Speichern über api_save_excalidraw.php mit CSRF)
		'PageController::excalidrawEditor',
		// M4e: Listen-Seiten, GET ohne Seiteneffekt (Papierkorb-?sort= wird nicht gespeichert, B58)
		'PageController::trash', 'PageController::listTags', 'PageController::listFolders', 'PageController::attachmentsList',
		'PageController::notesManager', 'PageController::create',
		// M4f: Tagebuch und Aufgaben, GET ohne Seiteneffekt (B61: Tagebuch wird erst beim Schreiben markiert)
		'PageController::diary', 'PageController::tasks',
		// M4g: Graph, Speed Dial, Erwähnungen (GET schreibt keinen Erledigt-Zähler), Hintergrundbild-Status/-Bild (B43)
		'PageController::graph', 'PageController::speedDial', 'PageController::mentions', 'CompatController::background',
	];
	// M3c2: schreibende Kompatibilitätsrouten (POST) prüfen CSRF
	private const PAGE_COMPAT_CSRF = ['PageController::search', 'PageController::trashSearch', 'CompatController::noteInfo', 'CompatController::saveExcalidraw', 'CompatController::workspaces', 'CompatController::importDroppedNotes',
		'CompatController::backgroundUpload', 'CompatController::backgroundDelete'];

	public function testPageAndCompatControllerAttributes(): void {
		$errors = [];
		$seen = [];
		foreach (['PageController', 'CompatController'] as $short) {
			$rc = new \ReflectionClass('OCA\\NextNotes\\Controller\\' . $short);
			foreach ($rc->getAttributes() as $a) {
				if (in_array($a->getName(), [PublicPage::class, NoCSRFRequired::class], true)) {
					$errors[] = "$short: Klassenattribut " . $a->getName();
				}
			}
			foreach ($rc->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
				if ($m->isStatic() || $m->isConstructor() || $m->getDeclaringClass()->getName() !== $rc->getName()) {
					continue;
				}
				$name = "$short::" . $m->getName();
				$seen[] = $name;
				$attrs = array_map(static fn ($a) => $a->getName(), $m->getAttributes());
				$doc = (string)$m->getDocComment();
				if (!in_array(NoAdminRequired::class, $attrs, true)) {
					$errors[] = "$name: NoAdminRequired fehlt";
				}
				if (in_array(PublicPage::class, $attrs, true) || str_contains($doc, '@PublicPage')) {
					$errors[] = "$name: PublicPage";
				}
				$csrfOff = in_array(NoCSRFRequired::class, $attrs, true) || str_contains($doc, '@NoCSRFRequired');
				if ($csrfOff && !in_array($name, self::PAGE_COMPAT_NOCSRF, true)) {
					$errors[] = "$name: NoCSRFRequired gesetzt, nicht auf Allowlist";
				}
				if (!$csrfOff && !in_array($name, self::PAGE_COMPAT_CSRF, true)) {
					$errors[] = "$name: CSRF aktiv, aber unbekannte Methode (Allowlist prüfen)";
				}
			}
		}
		foreach (array_merge(self::PAGE_COMPAT_NOCSRF, self::PAGE_COMPAT_CSRF) as $expected) {
			if (!in_array($expected, $seen, true)) {
				$errors[] = "$expected: Methode fehlt";
			}
		}
		// NoCSRFRequired-Methoden nur über GET erreichbar
		$file = Server::get(IAppManager::class)->getAppPath('nextnotes') . '/appinfo/routes.php';
		$routes = (static fn () => require $file)();
		foreach ($routes['routes'] as $r) {
			[$ctl, $action] = explode('#', $r['name']);
			if (!in_array($ctl, ['page', 'compat'], true)) {
				continue;
			}
			$name = ucfirst($ctl) . 'Controller::' . $action;
			if (!in_array($name, $seen, true)) {
				$errors[] = "Route {$r['name']}: Zielmethode fehlt";
			}
			if (in_array($name, self::PAGE_COMPAT_NOCSRF, true) && strtoupper($r['verb'] ?? 'GET') !== 'GET') {
				$errors[] = "Route {$r['name']} {$r['verb']}: NoCSRFRequired nur für GET";
			}
		}
		self::assertSame([], $errors);
	}
}
