<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Page;

use OCA\NextNotes\Tests\Support\ApiTestCase;
use OCA\NextNotes\Tests\Support\Response;
use OCP\IAppConfig;
use OCP\Server;

// M3c1 / SPEC §4.6, §11.1 P13: Anhang-Seite erlaubt nur frame-src 'self' (PDF-Vorschau), Admin-Embed-Domains
// (allowed_iframe_domains) nur auf Ansichten mit Notizanzeige (index, revisions, kanban_content); nirgends unsafe-eval/-inline.
final class PageCspTest extends ApiTestCase {
	private const APP = '/index.php/apps/nextnotes/';
	private const DOMAIN = 'embed-csp-test.example.org';

	private static function frameSrc(Response $r): string {
		$csp = (string)$r->header('Content-Security-Policy');
		self::assertNotSame('', $csp, 'CSP-Kopfzeile fehlt');
		self::assertDoesNotMatchRegularExpression('/script-src [^;]*\'unsafe-(eval|inline)\'/', $csp, 'unsafe-eval/-inline');
		self::assertDoesNotMatchRegularExpression('/object-src (?!\'none\')/', $csp, 'object-src geöffnet');
		return preg_match('/(?:^|;)\s*frame-src ([^;]*)/', $csp, $m) ? trim($m[1]) : '';
	}

	public function testFrameSrcPerView(): void {
		$cfg = Server::get(IAppConfig::class);
		$had = $cfg->hasKey('nextnotes', 'allowed_iframe_domains');
		$old = $had ? $cfg->getValueArray('nextnotes', 'allowed_iframe_domains', []) : null;
		$cfg->setValueArray('nextnotes', 'allowed_iframe_domains', [self::DOMAIN]);
		// AppConfig-Lokalcache des Webservers (APCu, LOCAL_CACHE_TTL 3 s) abwarten
		sleep(4);
		try {
			$a = $this->createUser('a');
			[$ws] = self::newWorkspace($a, 'Csp');
			$f = (int)self::ok($a->call('POST', '/folders', ['folder_name' => 'Board', 'workspace' => $ws]), 'Ordner')['folder_id'];
			$n = self::newNote($a, $ws, 'Csp', '<p>x</p>', $f);
			$wq = 'workspace=' . self::enc($ws);
			$pages = [
				'index' => ["index.php?note=$n&$wq", true],
				'revisions' => ["revisions.php?note_id=$n&$wq", true],
				'kanban_content' => ["kanban_content.php?ajax=1&folder_id=$f&$wq", true],
				'attachments' => ["attachments.php?note_id=$n&$wq", false],
			];
			foreach ($pages as $view => [$path, $embed]) {
				$r = $a->absolute('GET', self::APP . $path);
				self::assertSame(200, $r->status, "$view: " . $r->describe());
				$fs = self::frameSrc($r);
				self::assertStringContainsString("'self'", $fs, "$view: frame-src ohne 'self' ($fs)");
				if ($embed) {
					self::assertStringContainsString('https://' . self::DOMAIN, $fs, "$view: Embed-Domain fehlt ($fs)");
				} else {
					self::assertStringNotContainsString(self::DOMAIN, $fs, "$view: Embed-Domain gesetzt ($fs)");
					self::assertSame("'self'", preg_replace('/\s+/', ' ', $fs), "$view: frame-src nicht nur 'self'");
				}
			}
		} finally {
			if ($had) {
				$cfg->setValueArray('nextnotes', 'allowed_iframe_domains', $old);
			} else {
				$cfg->deleteKey('nextnotes', 'allowed_iframe_domains');
			}
		}
	}
}
