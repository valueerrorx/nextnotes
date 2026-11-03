<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Settings;

use InvalidArgumentException;
use OCA\NextNotes\Service\EmbedDomainService;
use OCA\NextNotes\Tests\Support\ApiClient;
use OCA\NextNotes\Tests\Support\ApiTestCase;
use OCA\NextNotes\Tests\Support\Response;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\AbstractLogger;

// Done M4a 1–3 (M4-Plan §1.4, SPEC §11.2 B41): Admin-Formular, Embed-Domains normalisiert, exakte Hosts für Sanitizer/CSP, Anhangsgrenzen
final class AdminSettingsTest extends ApiTestCase {
	private const APP = '/index.php/apps/nextnotes/';
	private const KEYS = ['allowed_iframe_domains', 'attachment_max_file_mb', 'attachment_quota_mb'];
	private const BAD = ['ftp://x', 'a.com:8080', '*.a.com', '1.2.3.4', '[::1]', 'com', 'localhost'];

	/** @var array<string, mixed> */
	private array $saved = [];

	protected function setUp(): void {
		parent::setUp();
		$cfg = Server::get(IAppConfig::class);
		foreach (self::KEYS as $k) {
			$type = $cfg->hasKey('nextnotes', $k) ? $cfg->getValueType('nextnotes', $k) : null;
			$this->saved[$k] = match ($type) {
				null => null,
				IAppConfig::VALUE_ARRAY => [$type, $cfg->getValueArray('nextnotes', $k)],
				IAppConfig::VALUE_INT => [$type, $cfg->getValueInt('nextnotes', $k)],
				default => [$type, $cfg->getValueString('nextnotes', $k)],
			};
		}
	}

	protected function tearDown(): void {
		$cfg = Server::get(IAppConfig::class);
		foreach ($this->saved as $k => $v) {
			$cfg->deleteKey('nextnotes', $k);
			if ($v !== null) {
				match ($v[0]) {
					IAppConfig::VALUE_ARRAY => $cfg->setValueArray('nextnotes', $k, $v[1]),
					IAppConfig::VALUE_INT => $cfg->setValueInt('nextnotes', $k, (int)$v[1]),
					default => $cfg->setValueString('nextnotes', $k, (string)$v[1]),
				};
			}
		}
		parent::tearDown();
	}

	private function admin(): ApiClient {
		$c = $this->createUser('adm');
		Server::get(IGroupManager::class)->get('admin')->addUser(Server::get(IUserManager::class)->get($c->uid));
		return $c;
	}

	private static function save(ApiClient $c, array $body): Response {
		return $c->absolute('POST', self::APP . 'admin/settings', $body);
	}

	// AppConfig-Lokalcache des Webservers (APCu, LOCAL_CACHE_TTL 3 s) abwarten. Pflicht nach jeder Änderung am Webserver vorbei
	// (CLI-deleteKey/-set, tearDown, PageCspTest): sonst hält der Webserver den Schlüssel für vorhanden, schreibt per UPDATE
	// auf eine gelöschte Zeile (0 Zeilen) und antwortet trotzdem 200 (NC-AppConfig, auch bei occ config:app:delete)
	private static function settle(): void {
		sleep(4);
	}

	// direkt aus der Tabelle: der AppConfig-Cache dieses Prozesses sieht Schreibvorgänge des Webservers erst verzögert
	private static function stored(): array {
		$qb = self::db()->getQueryBuilder();
		$qb->select('configvalue')->from('appconfig')
			->where($qb->expr()->eq('appid', $qb->createNamedParameter('nextnotes')))
			->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter('allowed_iframe_domains')));
		$res = $qb->executeQuery();
		$v = $res->fetchOne();
		$res->closeCursor();
		return $v === false ? [] : json_decode((string)$v, true);
	}

	private static function frameSrc(Response $r): string {
		$csp = (string)$r->header('Content-Security-Policy');
		self::assertNotSame('', $csp, 'CSP fehlt');
		return preg_match('/(?:^|;)\s*frame-src ([^;]*)/', $csp, $m) ? trim($m[1]) : '';
	}

	public static function normalizeCases(): array {
		return [
			['youtube.com', 'youtube.com'],
			['https://www.youtube.com/', 'www.youtube.com'],
			['https://www.youtube.com/embed/x', 'www.youtube.com'],
			['HTTPS://YouTube.com/embed/x', 'youtube.com'],
			['YouTube.com', 'youtube.com'],
			['youtube.com.', 'youtube.com'],
			['http://player.vimeo.com?x=1#y', 'player.vimeo.com'],
			['  www.youtube-nocookie.com  ', 'www.youtube-nocookie.com'],
		];
	}

	#[DataProvider('normalizeCases')]
	public function testNormalize(string $in, string $out): void {
		self::assertSame($out, EmbedDomainService::normalize($in));
	}

	public function testNormalizeIdn(): void {
		if (!function_exists('idn_to_ascii')) {
			self::markTestSkipped('intl fehlt');
		}
		self::assertSame('xn--bcher-kva.example', EmbedDomainService::normalize('bücher.example'));
	}

	public static function badCases(): array {
		return [
			['ftp://x', 'scheme'], ['a.com:8080', 'port'], ['*.a.com', 'wildcard'], ['1.2.3.4', 'ip'], ['[::1]', 'ip'],
			['com', 'single_label'], ['localhost', 'single_label'], ['user@a.com', 'userinfo'], ['javascript://a.com', 'scheme'],
			['a..com', 'invalid'], ['-a.com', 'invalid'], ['a_b.com', 'invalid'], ['1.2.3', 'ip'], ['::1', 'port'],
			[str_repeat('a.', 127) . 'com', 'too_long'], [str_repeat('a', 64) . '.com', 'invalid'],
		];
	}

	#[DataProvider('badCases')]
	public function testNormalizeRejects(string $in, string $reason): void {
		try {
			EmbedDomainService::normalize($in);
			self::fail("$in angenommen");
		} catch (InvalidArgumentException $e) {
			self::assertSame($reason, $e->getMessage(), $in);
		}
	}

	public function testAdminOnlyAndNormalizedSave(): void {
		$user = $this->createUser('usr');
		$admin = $this->admin();
		$cfg = Server::get(IAppConfig::class);
		$cfg->deleteKey('nextnotes', 'allowed_iframe_domains');
		self::settle();
		self::assertSame([], self::stored(), 'ab Werk leer (Q1)');

		$r = self::save($user, ['allowed_iframe_domains' => ['youtube.com']]);
		self::assertSame(403, $r->status, 'Nicht-Admin: ' . $r->describe());
		self::assertSame([], self::stored(), 'Nicht-Admin hat gespeichert');

		$r = self::save($admin, ['allowed_iframe_domains' => ['https://www.youtube.com/embed/x', 'YouTube.com', 'bücher.example', 'youtube.com']]);
		self::assertSame(200, $r->status, $r->describe());
		self::assertSame(['www.youtube.com', 'youtube.com', 'xn--bcher-kva.example'], self::stored());
		self::assertSame(['www.youtube.com', 'youtube.com', 'xn--bcher-kva.example'], $r->json()['allowed_iframe_domains']);

		// alle Fehler gemeldet, nichts gespeichert
		$r = self::save($admin, ['allowed_iframe_domains' => array_merge(['vimeo.com'], self::BAD)]);
		self::assertSame(400, $r->status, $r->describe());
		$entries = array_column($r->json()['errors'] ?? [], 'entry');
		self::assertSame(self::BAD, $entries, 'Antwort nennt alle sieben');
		self::assertSame(['www.youtube.com', 'youtube.com', 'xn--bcher-kva.example'], self::stored(), 'Liste verändert');

		// Textfeld-Form (Zeilen) und Obergrenze 100
		$r = self::save($admin, ['allowed_iframe_domains' => implode("\n", array_map(static fn ($i) => "h$i.example.org", range(1, 101)))]);
		self::assertSame(400, $r->status, '101 Einträge: ' . $r->describe());
		self::assertSame('too_many', $r->json()['errors'][0]['error'] ?? null);
		$r = self::save($admin, ['allowed_iframe_domains' => "a.example.org\nb.example.org"]);
		self::assertSame(200, $r->status, $r->describe());
		self::assertSame(['a.example.org', 'b.example.org'], self::stored());
	}

	public function testCspAfterSaveHasNoWildcard(): void {
		self::settle();
		$admin = $this->admin();
		self::assertSame(200, self::save($admin, ['allowed_iframe_domains' => ['https://Vimeo.com/', 'www.youtube-nocookie.com']])->status);
		self::settle();
		[$ws] = self::newWorkspace($admin, 'Csp');
		$n = self::newNote($admin, $ws, 'Csp', '<p>x</p>');
		$fs = self::frameSrc($admin->absolute('GET', self::APP . "index.php?note=$n&workspace=" . self::enc($ws)));
		self::assertStringNotContainsString('*', $fs, "frame-src mit Platzhalter: $fs");
		$hosts = preg_split('/\s+/', $fs);
		self::assertContains('https://vimeo.com', $hosts, $fs);
		self::assertContains('https://www.youtube-nocookie.com', $hosts, $fs);
	}

	// occ config:app:set … --type=array: tolerant normalisiert, ungültige Einträge verworfen und als Warnung geloggt
	public function testStoredValueReadTolerantly(): void {
		$cfg = Server::get(IAppConfig::class);
		$tag = bin2hex(random_bytes(3));
		$cfg->setValueArray('nextnotes', 'allowed_iframe_domains', ['https://Vimeo.com/', "bad$tag:8080", '*.x.org']);
		$logs = new class extends AbstractLogger {
			public array $lines = [];

			public function log($level, \Stringable|string $message, array $context = []): void {
				$this->lines[] = [$level, (string)$message, $context];
			}
		};
		$svc = new EmbedDomainService($cfg, Server::get(ICacheFactory::class), $logs);
		self::assertSame(['vimeo.com'], $svc->domains());
		self::assertCount(1, $logs->lines, 'genau eine Warnung');
		self::assertSame('warning', $logs->lines[0][0]);
		self::assertSame(["bad$tag:8080", '*.x.org'], array_column($logs->lines[0][2]['errors'], 'entry'));

		// falscher Typ (ohne --type=array) → keine Embeds, kein 500
		$cfg->deleteKey('nextnotes', 'allowed_iframe_domains');
		$cfg->setValueString('nextnotes', 'allowed_iframe_domains', 'vimeo.com');
		self::assertSame([], (new EmbedDomainService($cfg, Server::get(ICacheFactory::class), $logs))->domains());

		$cfg->deleteKey('nextnotes', 'allowed_iframe_domains');
		$cfg->setValueArray('nextnotes', 'allowed_iframe_domains', ['https://Vimeo.com/']);
		self::settle();
		$a = $this->createUser('occ');
		[$ws] = self::newWorkspace($a, 'Occ');
		$n = self::newNote($a, $ws, 'Occ', '<p>x</p>');
		$fs = self::frameSrc($a->absolute('GET', self::APP . "index.php?note=$n&workspace=" . self::enc($ws)));
		self::assertSame(["'self'", 'https://vimeo.com'], preg_split('/\s+/', $fs), "frame-src genau vimeo.com: $fs");
	}

	// B41: eine Liste, exakte Hosts – Sanitizer beim Speichern und CSP der Notizseite
	public function testB41SanitizerFollowsList(): void {
		self::settle();
		$admin = $this->admin();
		$a = $this->createUser('b41');
		[$ws] = self::newWorkspace($a, 'B41');
		$iframe = '<iframe src="https://www.youtube.com/embed/x"></iframe>';
		$content = static fn (int $n) => (string)(self::ok($a->call('GET', "/notes/$n"), 'GET')['note']['content'] ?? '');

		self::assertSame(200, self::save($admin, ['allowed_iframe_domains' => []])->status);
		self::settle();
		$n1 = self::newNote($a, $ws, 'Leer', "<p>a</p>$iframe");
		self::assertStringNotContainsString('<iframe', $content($n1), 'leere Liste: iframe bleibt');

		self::assertSame(200, self::save($admin, ['allowed_iframe_domains' => ['www.youtube.com']])->status);
		self::settle();
		$n2 = self::newNote($a, $ws, 'Exakt', "<p>b</p>$iframe");
		self::assertStringContainsString('https://www.youtube.com/embed/x', $content($n2), 'gelisteter Host entfernt');
		$fs = self::frameSrc($a->absolute('GET', self::APP . "index.php?note=$n2&workspace=" . self::enc($ws)));
		self::assertContains('https://www.youtube.com', preg_split('/\s+/', $fs), $fs);

		self::assertSame(200, self::save($admin, ['allowed_iframe_domains' => ['youtube.com']])->status);
		self::settle();
		$n3 = self::newNote($a, $ws, 'Eltern', "<p>c</p>$iframe");
		self::assertStringNotContainsString('<iframe', $content($n3), 'Subdomain von youtube.com durchgelassen');
		$fs = self::frameSrc($a->absolute('GET', self::APP . "index.php?note=$n3&workspace=" . self::enc($ws)));
		self::assertSame(["'self'", 'https://youtube.com'], preg_split('/\s+/', $fs), $fs);
	}

	public function testAttachmentLimits(): void {
		self::settle();
		$admin = $this->admin();
		foreach ([['attachment_max_file_mb', 0], ['attachment_max_file_mb', 2049], ['attachment_quota_mb', 0], ['attachment_quota_mb', 1048577],
			['attachment_max_file_mb', 'abc'], ['attachment_max_file_mb', '1.5'], ['attachment_quota_mb', -1]] as [$k, $v]) {
			$r = self::save($admin, [$k => $v, 'allowed_iframe_domains' => ['changed.example.org']]);
			self::assertSame(400, $r->status, "$k=$v: " . $r->describe());
			self::assertSame($k, $r->json()['errors'][0]['field'] ?? null);
		}
		self::assertNotContains('changed.example.org', self::stored(), 'bei Fehler trotzdem gespeichert');

		$a = $this->createUser('lim');
		[$ws] = self::newWorkspace($a, 'Lim');
		$n = self::newNote($a, $ws, 'Lim', '<p>x</p>');
		$bytes = str_repeat('x', (int)(1.5 * 1048576));
		self::assertSame(200, self::save($admin, ['attachment_max_file_mb' => 1, 'attachment_quota_mb' => 1048576])->status);
		self::settle();
		$r = $a->upload("/notes/$n/attachments", 'big.txt', $bytes, [], 'text/plain');
		self::assertSame(413, $r->status, 'max 1 MiB: ' . $r->describe());
		self::assertSame(200, self::save($admin, ['attachment_max_file_mb' => '2'])->status);
		self::settle();
		self::ok($a->upload("/notes/$n/attachments", 'big.txt', $bytes, [], 'text/plain'), 'max 2 MiB');
		self::assertSame(200, self::save($admin, ['attachment_quota_mb' => 2])->status);
		self::settle();
		$r = $a->upload("/notes/$n/attachments", 'big2.txt', $bytes, [], 'text/plain');
		self::assertSame(413, $r->status, 'Quote 2 MiB: ' . $r->describe());
	}
}
