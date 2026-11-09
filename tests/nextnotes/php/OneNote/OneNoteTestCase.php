<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\OneNote;

use OCA\NextNotes\Db\OneNoteImportMapper;
use OCA\NextNotes\Db\OneNoteItemMapper;
use OCA\NextNotes\OneNote\ConnectService;
use OCA\NextNotes\OneNote\HttpGate;
use OCA\NextNotes\OneNote\OAuthService;
use OCA\NextNotes\OneNote\OneNoteConfig;
use OCA\NextNotes\OneNote\OneNoteNotifications;
use OCA\NextNotes\OneNote\TokenVault;
use OCA\NextNotes\Tests\Support\ApiTestCase;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\Security\ISecureRandom;
use OCP\Server;
use Psr\Log\AbstractLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

// M9a: OneNote-Dienste im Prozess mit gefälschtem IClientService und gestellter Zeit; Konfiguration wird im tearDown zurückgesetzt
abstract class OneNoteTestCase extends ApiTestCase {
	public const CLIENT = '11111111-2222-3333-4444-555555555555';
	public const CLIENT2 = '66666666-7777-8888-9999-000000000000';
	public const TENANT = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
	public const SECRET = 'mock-secret-Zq7!';
	protected const KEYS = [OneNoteConfig::KEY_CLIENT_ID, OneNoteConfig::KEY_SECRET, OneNoteConfig::KEY_AUTHORITY, OneNoteConfig::KEY_TEAMS];

	protected int $now = 1_900_000_000;
	/** @var list<array{level: string, message: string, context: array<mixed>}> */
	protected array $logs = [];
	/** @var array<string, array{0: int, 1: mixed, 2: bool}|null> */
	private array $savedConfig = [];

	protected function setUp(): void {
		parent::setUp();
		// wie cron.php: ohne geladene notifications-App verwirft IManager::notify still
		Server::get(IAppManager::class)->loadApp('notifications');
		$cfg = Server::get(IAppConfig::class);
		foreach (self::KEYS as $k) {
			if (!$cfg->hasKey('nextnotes', $k, null)) {
				$this->savedConfig[$k] = null;
				continue;
			}
			$lazy = $k === OneNoteConfig::KEY_SECRET;
			$this->savedConfig[$k] = $cfg->getValueType('nextnotes', $k) === IAppConfig::VALUE_BOOL
				? [IAppConfig::VALUE_BOOL, $cfg->getValueBool('nextnotes', $k), false]
				: [IAppConfig::VALUE_STRING, $cfg->getValueString('nextnotes', $k, '', $lazy), $lazy];
		}
		$this->configure(self::TENANT, self::CLIENT, self::SECRET, true);
	}

	protected function tearDown(): void {
		$cfg = Server::get(IAppConfig::class);
		foreach ($this->savedConfig as $k => $v) {
			$cfg->deleteKey('nextnotes', $k);
			if ($v !== null) {
				if ($v[0] === IAppConfig::VALUE_BOOL) {
					$cfg->setValueBool('nextnotes', $k, (bool)$v[1]);
				} else {
					$cfg->setValueString('nextnotes', $k, (string)$v[1], $v[2], $v[2]);
				}
			}
		}
		parent::tearDown();
	}

	protected function configure(string $authority, string $client, string $secret, bool $teams): void {
		Server::get(OneNoteConfig::class)->save($authority, $client, $secret, $teams);
	}

	protected function time(): ITimeFactory {
		$t = $this->createStub(ITimeFactory::class);
		$t->method('getTime')->willReturnCallback(fn (): int => $this->now);
		return $t;
	}

	protected function logger(): AbstractLogger {
		return new class($this->logs) extends AbstractLogger {
			public function __construct(private array &$sink) {
			}

			public function log($level, \Stringable|string $message, array $context = []): void {
				$this->sink[] = ['level' => (string)$level, 'message' => (string)$message, 'context' => $context];
			}
		};
	}

	protected function connectService(FakeHttp $http): ConnectService {
		return new ConnectService(
			Server::get(OneNoteConfig::class),
			Server::get(OneNoteImportMapper::class),
			Server::get(OneNoteItemMapper::class),
			new OAuthService(new HttpGate($http)),
			Server::get(TokenVault::class),
			Server::get(ISecureRandom::class),
			$this->time(),
			Server::get(OneNoteNotifications::class),
			$this->logger(),
		);
	}

	// ID-Token wie Entra v2 (Signatur ohne Bedeutung, OIDC Core 3.1.3.7)
	protected static function idToken(string $aud, string $tid, string $sub, int $exp): string {
		$b = static fn (array $a): string => rtrim(strtr(base64_encode((string)json_encode($a)), '+/', '-_'), '=');
		return $b(['alg' => 'RS256', 'typ' => 'JWT']) . '.' . $b(['aud' => $aud, 'tid' => $tid, 'sub' => $sub, 'exp' => $exp, 'iat' => $exp - 3600]) . '.c2ln';
	}

	/** Token-Endpunkt: Code → Token (sub, aud), Refresh → neue Token; $fail = error-Code des Endpunkts */
	protected function tokenResponder(string $sub = 'sub-A', ?string $aud = null, ?string $fail = null, int $expiresIn = 3600): \Closure {
		$n = 0;
		return function (string $method, string $url, array $options) use ($sub, $aud, $fail, $expiresIn, &$n) {
			if ($fail !== null) {
				return FakeHttp::json($fail === 'invalid_client' ? 401 : 400, ['error' => $fail, 'error_description' => 'AADSTS-DESCRIPTION-SECRET']);
			}
			$n++;
			$grant = $options['form_params']['grant_type'] ?? '';
			$data = ['token_type' => 'Bearer', 'expires_in' => $expiresIn, 'access_token' => "mock-access-$n-" . bin2hex(random_bytes(4)),
				'refresh_token' => "mock-refresh-$n-" . bin2hex(random_bytes(4))];
			if ($grant === 'authorization_code') {
				$data['id_token'] = self::idToken($aud ?? (string)$options['form_params']['client_id'], self::TENANT, $sub, $this->now + 3600);
			}
			return FakeHttp::json(200, $data);
		};
	}

	/** @return array<string, mixed>|null Rohzeile */
	protected static function row(int $id): ?array {
		$qb = self::db()->getQueryBuilder();
		$qb->select('*')->from(OneNoteImportMapper::TABLE)->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
		$r = $qb->executeQuery()->fetchAssociative();
		return $r === false ? null : $r;
	}

	/** @return list<array<string, mixed>> */
	protected static function rowsOf(string $uid): array {
		$qb = self::db()->getQueryBuilder();
		$qb->select('*')->from(OneNoteImportMapper::TABLE)->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))->orderBy('id');
		return $qb->executeQuery()->fetchAllAssociative();
	}

	protected static function stateOf(string $authorizeUrl): string {
		parse_str((string)parse_url($authorizeUrl, PHP_URL_QUERY), $q);
		return (string)$q['state'];
	}

	/** Verbinden + Callback mit gültigem state; liefert die Import-ID */
	protected function signIn(ConnectService $svc, string $uid): int {
		$c = $svc->connect($uid, null);
		$r = $svc->callback($uid, self::stateOf($c['authorize_url']), 'code-' . bin2hex(random_bytes(4)), '');
		self::assertSame(['import' => $c['import_id']], $r->query, 'Callback ok');
		return $c['import_id'];
	}

	/** Zeile direkt anlegen (Lebenszyklus-Tests) */
	protected static function insertImport(string $uid, string $status, array $extra = []): int {
		$qb = self::db()->getQueryBuilder();
		$values = $extra + ['uid' => $uid, 'status' => $status, 'authority' => self::TENANT, 'client_id' => self::CLIENT,
			'access_enc' => null, 'refresh_enc' => null, 'created_at' => 1_900_000_000, 'updated_at' => 1_900_000_000];
		$qb->insert(OneNoteImportMapper::TABLE);
		foreach ($values as $k => $v) {
			$qb->setValue($k, $qb->createNamedParameter($v));
		}
		$qb->executeStatement();
		return $qb->getLastInsertId();
	}

	protected static function insertItem(string $uid, int $importId): void {
		$qb = self::db()->getQueryBuilder();
		$qb->insert(OneNoteItemMapper::TABLE)->values([
			'uid' => $qb->createNamedParameter($uid),
			'source_hash' => $qb->createNamedParameter(hash('sha256', 'page:' . bin2hex(random_bytes(4)))),
			'kind' => $qb->createNamedParameter('page'),
			'target_id' => $qb->createNamedParameter(1),
			'import_id' => $qb->createNamedParameter($importId),
			'created_at' => $qb->createNamedParameter(1_900_000_000),
		])->executeStatement();
	}

	/**
	 * occ-Befehl ohne Rückfrage
	 * @param array<string, mixed> $args
	 * @return array{0: int, 1: string}
	 */
	protected static function runCommand(string $class, array $args): array {
		/** @var Command $cmd */
		$cmd = Server::get($class);
		$input = new ArrayInput($args);
		$input->setInteractive(false);
		$out = new BufferedOutput();
		return [$cmd->run($input, $out), $out->fetch()];
	}
}
