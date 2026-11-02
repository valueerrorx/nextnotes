<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Scenario;

use OCA\NextNotes\Service\WorkspaceService;
use OCA\NextNotes\Tests\Support\ApiTestCase;
use OCP\Config\IUserConfig;
use OCP\IConfig;
use OCP\Server;

// SPEC §2.2 Vereinfachungen M2c (Settings), §4.2 (/shared*), §11.2 B12/B24, §1.2.5 + Plan O2 (Konvertierung 501)
final class SettingsSystemTest extends ApiTestCase {
	private static function prefs(string $uid): array {
		return self::dump($uid)['prefs'];
	}

	public function testBlockedAndInvalidKeys(): void {
		$a = $this->createUser('sk');
		$before = self::prefs($a->uid);
		$bad = ['ai_api_key', 'smtp_password', 'git_token', 'github_token', 'admin_password', 'login_display_name',
			'custom_css_path', 'whats_new_seen_version', 'webhook_url', 'backup_path', 'Upper', '1abc', 'a.b', str_repeat('k', 64)];
		foreach ($bad as $key) {
			$r = $a->call('PUT', '/settings/' . self::enc($key), ['value' => 'x']);
			self::assertContains($r->status, [400, 404], "PUT $key: " . $r->describe());
		}
		self::assertSame($before, self::prefs($a->uid), 'gesperrte Schlüssel gespeichert');
		// direkt (am API vorbei) gespeicherter gesperrter Wert wird nicht ausgeliefert
		Server::get(IUserConfig::class)->setValueString($a->uid, 'nextnotes', 'ai_api_key', 'geheim-123');
		self::assertSame('', self::ok($a->call('GET', '/settings/ai_api_key'), 'GET gesperrt')['value']);
		self::assertStringNotContainsString('geheim-123', $a->call('GET', '/settings')->body);
		self::assertSame(200, $a->call('PUT', '/settings/' . str_repeat('k', 63), ['value' => 'x'])->status, '63 Zeichen erlaubt');
	}

	// B24: Wert ≤ 64 KiB, ≤ 300 Schlüssel je Benutzer
	public function testSizeLimitsB24(): void {
		$a = $this->createUser('sl');
		self::assertSame(200, $a->call('PUT', '/settings/my_free', ['value' => str_repeat('a', 65536)])->status, '64 KiB erlaubt');
		$r = $a->call('PUT', '/settings/my_free', ['value' => str_repeat('b', 65537)]);
		self::assertContains($r->status, [400, 413], '64 KiB + 1: ' . $r->describe());
		self::assertSame(str_repeat('a', 65536), self::ok($a->call('GET', '/settings/my_free'), 'GET')['value']);

		$cfg = Server::get(IUserConfig::class);
		$have = count($cfg->getKeys($a->uid, 'nextnotes'));
		for ($i = $have; $i < 300; $i++) {
			$cfg->setValueString($a->uid, 'nextnotes', 'fill_' . $i, 'v');
		}
		self::assertCount(300, $cfg->getKeys($a->uid, 'nextnotes'));
		$r = $a->call('PUT', '/settings/one_more', ['value' => 'x']);
		self::assertContains($r->status, [400, 413], '301. Schlüssel: ' . $r->describe());
		self::assertNotContains('one_more', $cfg->getKeys($a->uid, 'nextnotes'));
		self::assertSame(200, $a->call('PUT', '/settings/my_free', ['value' => 'kurz'])->status, 'vorhandenen Schlüssel ändern');
	}

	public function testLanguageTimezoneReadOnly(): void {
		$a = $this->createUser('lt');
		$cfg = Server::get(IConfig::class);
		$cfg->setUserValue($a->uid, 'core', 'lang', 'de');
		$cfg->setUserValue($a->uid, 'core', 'timezone', 'Europe/Berlin');
		self::assertSame('de', self::ok($a->call('GET', '/settings/language'), 'lang')['value']);
		self::assertSame('Europe/Berlin', self::ok($a->call('GET', '/settings/timezone'), 'tz')['value']);
		$all = self::ok($a->call('GET', '/settings'), 'all')['settings'];
		self::assertSame('de', $all['language']);
		self::assertSame('Europe/Berlin', $all['timezone']);
		foreach (['language' => 'en', 'timezone' => 'UTC', 'recently_opened_notes' => '[[1,"x"]]'] as $k => $v) {
			$r = $a->call('PUT', "/settings/$k", ['value' => $v]);
			self::assertSame(400, $r->status, "PUT $k: " . $r->describe());
		}
		self::assertSame('de', $cfg->getUserValue($a->uid, 'core', 'lang'));
		self::assertSame('Europe/Berlin', $cfg->getUserValue($a->uid, 'core', 'timezone'));
		self::assertSame('de', self::ok($a->call('GET', '/users/me'), 'me')['language']);
	}

	// „zuletzt geöffnet“ nur mit Leserecht; nach Entzug leer; eingeschleuste IDs gefiltert
	public function testRecentlyOpenedFiltered(): void {
		$a = $this->createUser('ra');
		$b = $this->createUser('rb');
		$mark = 'rmk' . bin2hex(random_bytes(4));
		[$ws, $wsId] = self::newWorkspace($a, "Geteilt $mark");
		$n = self::newNote($a, $ws, "Offen $mark", '<p>x</p>');
		[$wsP] = self::newWorkspace($a, 'Privat');
		$p = self::newNote($a, $wsP, "Privat $mark", '<p>x</p>');
		Server::get(WorkspaceService::class)->share($a->uid, $wsId, $b->uid, 'read');
		self::ok($b->call('POST', "/notes/$n/opened"), 'opened');
		self::assertSame(404, $b->call('POST', "/notes/$p/opened")->status, 'fremde Notiz als geöffnet markiert');
		$v = json_decode(self::ok($b->call('GET', '/settings/recently_opened_notes'), 'GET')['value'], true);
		self::assertSame([$n], array_column($v, 0));
		$all = self::ok($b->call('GET', '/settings'), 'all')['settings'];
		self::assertSame([$n], array_column(json_decode($all['recently_opened_notes'], true), 0));

		// am API vorbei eingeschleuste fremde ID wird nicht ausgeliefert
		$cfg = Server::get(IUserConfig::class);
		$cfg->setValueString($b->uid, 'nextnotes', 'recently_opened_notes', json_encode([[$p, 'x'], [$n, 'y']]));
		$body = $b->call('GET', '/settings/recently_opened_notes')->body;
		self::assertSame([$n], array_column(json_decode(json_decode($body, true)['value'], true), 0));

		Server::get(WorkspaceService::class)->unshare($a->uid, $wsId, $b->uid);
		self::assertSame('[]', self::ok($b->call('GET', '/settings/recently_opened_notes'), 'nach Entzug')['value']);
		$r = $b->call('GET', '/settings' . self::q(['keys' => 'recently_opened_notes,note_list_sort']));
		self::assertStringNotContainsString($mark, $r->body);
		self::assertSame('[]', self::ok($r, 'keys')['settings']['recently_opened_notes']);
	}

	public function testSettingsPerUser(): void {
		$a = $this->createUser('pa');
		$b = $this->createUser('pb');
		$mark = 'smk' . bin2hex(random_bytes(4));
		self::ok($a->call('PUT', '/settings/note_list_sort', ['value' => 'heading_asc']), 'A sort');
		self::ok($a->call('PUT', '/settings/my_note', ['value' => $mark]), 'A frei');
		$before = self::prefs($a->uid);
		self::assertStringNotContainsString($mark, $b->call('GET', '/settings')->body);
		self::assertSame('', self::ok($b->call('GET', '/settings/my_note'), 'B GET')['value']);
		self::ok($b->call('PUT', '/settings/my_note', ['value' => 'b-wert']), 'B PUT');
		self::ok($b->call('PUT', '/settings/note_list_sort', ['value' => 'created_desc']), 'B sort');
		self::assertSame($before, self::prefs($a->uid), 'B ändert Einstellungen von A');
		self::assertSame($mark, self::ok($a->call('GET', '/settings/my_note'), 'A GET')['value']);
		self::assertSame('heading_asc', self::ok($a->call('GET', '/settings/note_list_sort'), 'A sort')['value']);
	}

	// /shared*: 200 mit leeren Listen im Upstream-Format, auch wenn Workspace-Freigaben bestehen (ADR 0011); B12
	public function testSharedAndSystem(): void {
		$a = $this->createUser('sa');
		$b = $this->createUser('sb');
		[, $wsId] = self::newWorkspace($a, 'Sh');
		Server::get(WorkspaceService::class)->share($a->uid, $wsId, $b->uid);
		foreach ([$a, $b] as $c) {
			$r = $c->call('GET', '/shared');
			self::assertSame(200, $r->status);
			self::assertSame(['success' => true, 'shared_notes' => [], 'shared_folders' => []], self::sortKeys($r->json(), ['success', 'shared_notes', 'shared_folders']));
			$r = $c->call('GET', '/shared/with-me');
			self::assertSame(200, $r->status);
			self::assertSame(['success' => true, 'items' => [], 'shared_workspaces' => []], self::sortKeys($r->json(), ['success', 'items', 'shared_workspaces']));
		}
		$before = self::prefs($a->uid);
		$w = self::ok($a->call('GET', '/system/whats-new'), 'whats-new');
		self::assertFalse($w['show']);
		self::assertSame([], $w['releases'] ?? null);
		self::ok($a->call('POST', '/system/whats-new/seen'), 'seen');
		self::assertSame($before, self::prefs($a->uid), 'B12: whats-new mit Seiteneffekt');
		self::assertIsString(self::ok($a->call('GET', '/system/version'), 'version')['current_version'] ?? null);
	}

	// Konvertierung: Rechteprüfung, dann 501 ohne Inhalt (SPEC §1.2.5, Plan O2)
	public function testConversionNotImplemented(): void {
		$a = $this->createUser('ca');
		$b = $this->createUser('cb');
		$mark = 'cmk' . bin2hex(random_bytes(4));
		[$ws] = self::newWorkspace($a, 'Conv');
		$n = self::newNote($a, $ws, "Konv $mark", "<p>$mark</p>");
		$before = self::dump($a->uid);
		$r = $a->call('POST', "/notes/$n/convert", ['target' => 'markdown']);
		self::assertSame(501, $r->status, $r->describe());
		self::assertSame(['success' => false, 'error' => 'not_implemented'], $r->json());
		$r = $b->call('POST', "/notes/$n/convert", ['target' => 'markdown']);
		self::assertSame(404, $r->status, $r->describe());
		self::assertStringNotContainsString($mark, $r->body);
		foreach (['/convert-html' => ['html' => "<p>$mark</p>"], '/convert-markdown' => ['markdown' => "**$mark**"]] as $p => $body) {
			$r = $b->call('POST', $p, $body);
			self::assertSame(501, $r->status, "$p: " . $r->describe());
			self::assertStringNotContainsString($mark, $r->body);
		}
		self::assertSame($before, self::dump($a->uid), 'Konvertierung ändert Daten');
	}

	// Lead-Zusatz: Werte > 128 Byte lazy in oc_preferences
	public function testLargeValuesLazy(): void {
		$a = $this->createUser('lz');
		self::ok($a->call('PUT', '/settings/my_big', ['value' => str_repeat('z', 200)]), 'groß');
		$qb = self::db()->getQueryBuilder();
		$qb->select('lazy')->from('preferences')
			->where($qb->expr()->eq('userid', $qb->createNamedParameter($a->uid)))
			->andWhere($qb->expr()->eq('appid', $qb->createNamedParameter('nextnotes')))
			->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter('my_big')));
		self::assertSame(1, (int)$qb->executeQuery()->fetchOne(), 'Wert > 128 Byte nicht lazy');
		self::assertSame(str_repeat('z', 200), self::ok($a->call('GET', '/settings/my_big'), 'GET')['value']);
	}

	private static function sortKeys(mixed $j, array $order): array {
		self::assertIsArray($j);
		self::assertEqualsCanonicalizing($order, array_keys($j), 'Schlüssel abweichend');
		$out = [];
		foreach ($order as $k) {
			$out[$k] = $j[$k];
		}
		return $out;
	}
}
