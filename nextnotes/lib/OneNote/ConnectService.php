<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\OneNote;

use OCA\NextNotes\Db\OneNoteImport;
use OCA\NextNotes\Db\OneNoteImportMapper;
use OCA\NextNotes\Db\OneNoteItemMapper;
use OCA\NextNotes\Service\Exception\ConflictException;
use OCA\NextNotes\Service\Exception\ForbiddenException;
use OCA\NextNotes\Service\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * Anmeldung und Lebenszyklus einer Import-Zeile (Plan M9 §3, §4.1, §4.2, §4.6, §4.7): connect, callback, cancel, Anzeige,
 * Token-Erneuerung, Konfigurationswechsel. Jede Methode bekommt die uid explizit; keine Netz-I/O in DB-Transaktionen.
 */
class ConnectService {
	public const AUTH_TTL = 600;
	// eine auth-Zeile jünger als das wird nicht ersetzt: zwei gleichzeitige connect → genau einer gewinnt (Done M9a 14)
	public const REPLACE_GRACE = 5;
	public const KEEP_DAYS = 30;
	public const REFRESH_MARGIN = 300;
	private const BLOCKING = ['queued', 'running', 'reauth'];

	public function __construct(
		private OneNoteConfig $config,
		private OneNoteImportMapper $imports,
		private OneNoteItemMapper $items,
		private OAuthService $oauth,
		private TokenVault $vault,
		private ISecureRandom $random,
		private ITimeFactory $time,
		private OneNoteNotifications $notify,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Neue Anmeldung (ohne $importId) oder Neuanmeldung eines Imports in reauth
	 * @return array{import_id: int, authorize_url: string}
	 */
	public function connect(string $uid, ?int $importId): array {
		if (!$this->config->enabled()) {
			throw new ForbiddenException('OneNote import is not configured', ['code' => 'not_configured']);
		}
		$this->reconcile($uid);
		$now = $this->time->getTime();
		$state = $this->random->generate(43, ISecureRandom::CHAR_ALPHANUMERIC);
		$verifier = $this->random->generate(64, ISecureRandom::CHAR_ALPHANUMERIC);
		$redirect = $this->config->redirectUri();
		$auth = [
			'state_hash' => hash('sha256', $state),
			'pkce_enc' => $this->vault->seal($verifier),
			'redirect_uri' => $redirect,
			'auth_expires_at' => $now + self::AUTH_TTL,
			'updated_at' => $now,
		];
		if ($importId !== null) {
			$import = $this->own($uid, $importId);
			if ($import->getStatus() !== 'reauth') {
				throw new ConflictException('Import does not need a new sign-in', ['code' => 'not_reauth']);
			}
			// Team-Scopes nur, solange sie noch erlaubt sind (§4.7)
			$teams = (int)$import->getTeamScopes() === 1 && $this->config->teamsEffective();
			$n = $this->imports->updateWhere($importId, $uid, $auth + ['team_scopes' => $teams ? 1 : 0],
				['status' => 'reauth', 'client_id' => $this->config->clientId(), 'authority' => $this->config->authority()]);
			if ($n !== 1) {
				throw new ConflictException('Import changed, reload', ['code' => 'changed']);
			}
		} else {
			$teams = $this->config->teamsEffective();
			$importId = $this->insertNew($uid, $now, $auth, $teams);
		}
		return [
			'import_id' => $importId,
			'authorize_url' => $this->oauth->authorizeUrl($this->config->authority(), $this->config->clientId(), $redirect,
				OneNoteConfig::scopes($teams), $state, $verifier),
		];
	}

	/** @param array<string, int|string> $auth */
	private function insertNew(string $uid, int $now, array $auth, bool $teams): int {
		$open = $this->imports->findOpen($uid);
		foreach ($open as $row) {
			if (in_array($row->getStatus(), self::BLOCKING, true)) {
				throw new ConflictException('Another OneNote import is open', ['code' => 'import_open', 'import_id' => (int)$row->getId()]);
			}
			if ($row->getStatus() === 'auth' && (int)$row->getCreatedAt() > $now - self::REPLACE_GRACE) {
				throw new ConflictException('A sign-in is already in progress', ['code' => 'connect_pending']);
			}
		}
		$buckets = $this->imports->latestRateBuckets($uid);
		// offene auth/select ersetzen (samt Token); nur die gelesenen Zeilen, nie eine inzwischen neue
		foreach ($open as $row) {
			$this->imports->deleteWhere((int)$row->getId(), $uid, ['status' => (string)$row->getStatus()]);
		}
		$e = new OneNoteImport();
		$e->setUid($uid);
		$e->setStatus('auth');
		$e->setAuthority($this->config->authority());
		$e->setClientId($this->config->clientId());
		$e->setTeamScopes($teams ? 1 : 0);
		$e->setStateHash((string)$auth['state_hash']);
		$e->setPkceEnc((string)$auth['pkce_enc']);
		$e->setRedirectUri((string)$auth['redirect_uri']);
		$e->setAuthExpiresAt((int)$auth['auth_expires_at']);
		$e->setRateBuckets($buckets);
		foreach (['setPagesTotal', 'setPagesDone', 'setPagesSkipped', 'setResourcesDone', 'setResourcesFailed', 'setRateVersion', 'setBackoffLevel', 'setNotFoundStreak'] as $setter) {
			$e->$setter(0);
		}
		$e->setCreatedAt($now);
		$e->setUpdatedAt($now);
		$id = (int)$this->imports->insert($e)->getId();
		// Nachzählen nach dem Commit (§3): nur die kleinste offene ID bleibt
		$ids = array_map(static fn (OneNoteImport $i): int => (int)$i->getId(), $this->imports->findOpen($uid));
		if (!in_array($id, $ids, true) || min($ids) !== $id) {
			$this->imports->deleteWhere($id, $uid);
			throw new ConflictException('Another OneNote import is open', ['code' => 'import_open']);
		}
		return $id;
	}

	// Callback-Reihenfolge fest (§4.2): erst state, dann error=, dann Token
	public function callback(string $uid, string $state, string $code, string $error): CallbackResult {
		if ($uid === '' || $state === '' || strlen($state) > 256) {
			return CallbackResult::notFound();
		}
		$hash = hash('sha256', $state);
		$now = $this->time->getTime();
		$import = $this->imports->findByState($hash, $uid, $now);
		if ($import === null || !hash_equals((string)$import->getStateHash(), $hash)
			|| !in_array($import->getStatus(), ['auth', 'reauth'], true)) {
			return CallbackResult::notFound();
		}
		$id = (int)$import->getId();
		$reauth = $import->getStatus() === 'reauth';
		if (!$this->config->enabled()) {
			$this->discard($import, $hash);
			return CallbackResult::view(['msg' => 'not_configured']);
		}
		if ($this->reconcile($uid) > 0 && $this->stillPending($id, $uid, $hash) === null) {
			return CallbackResult::view(['msg' => 'config_changed']);
		}
		if ($error !== '') {
			$this->discard($import, $hash);
			$msg = OneNoteMessages::forCallbackError($error);
			$this->logger->info('nextnotes: onenote sign-in returned an error', ['import' => $id, 'error' => array_key_exists($error, OneNoteMessages::CALLBACK_ERRORS) ? $error : 'other']);
			return CallbackResult::view(['msg' => $msg]);
		}
		$verifier = $this->vault->open($import->getPkceEnc());
		if ($code === '' || $verifier === null) {
			$this->discard($import, $hash);
			return CallbackResult::view(['msg' => 'failed']);
		}
		try {
			$tokens = $this->oauth->redeemCode((string)$import->getAuthority(), (string)$import->getClientId(), $this->config->secret(),
				(string)$import->getRedirectUri(), $code, $verifier, OneNoteConfig::scopes((int)$import->getTeamScopes() === 1));
			$account = OAuthService::accountOf($tokens->idToken, (string)$import->getClientId(), $now);
		} catch (OAuthException $e) {
			$this->discard($import, $hash);
			$this->logOAuth($id, $e);
			return CallbackResult::view(['msg' => isset(OneNoteMessages::CALLBACK_ERRORS[$e->error]) ? OneNoteMessages::forCallbackError($e->error) : 'failed']);
		}
		if ($reauth && $import->getMsAccount() !== null && !hash_equals((string)$import->getMsAccount(), $account)) {
			$this->imports->updateWhere($id, $uid, ['state_hash' => null, 'pkce_enc' => null, 'auth_expires_at' => null,
				'error_code' => 'account_mismatch', 'updated_at' => $now], ['state_hash' => $hash, 'status' => 'reauth']);
			return CallbackResult::view(['import' => $id, 'msg' => 'account_mismatch']);
		}
		$set = [
			'access_enc' => $this->vault->seal($tokens->access),
			'refresh_enc' => $this->vault->seal($tokens->refresh),
			'access_expires_at' => $now + $tokens->expiresIn,
			'state_hash' => null, 'pkce_enc' => null, 'auth_expires_at' => null,
			'error_code' => null, 'updated_at' => $now,
		];
		$set += $reauth ? ['status' => 'queued', 'next_run_at' => $now] : ['status' => 'select', 'ms_account' => $account];
		$n = $this->imports->updateWhere($id, $uid, $set, ['state_hash' => $hash, 'status' => (string)$import->getStatus()]);
		if ($n !== 1) {
			return CallbackResult::view(['msg' => 'failed']);
		}
		return CallbackResult::view(['import' => $id]);
	}

	private function stillPending(int $id, string $uid, string $hash): ?OneNoteImport {
		try {
			$row = $this->imports->findOwn($id, $uid);
		} catch (DoesNotExistException) {
			return null;
		}
		return $row->getStateHash() === $hash && in_array($row->getStatus(), ['auth', 'reauth'], true) ? $row : null;
	}

	// Anmeldeversuch verwerfen: neue Anmeldung → Zeile weg, Neuanmeldung → nur state/Verifier weg (bleibt reauth)
	private function discard(OneNoteImport $import, string $hash): void {
		$id = (int)$import->getId();
		$uid = (string)$import->getUid();
		if ($import->getStatus() === 'auth') {
			$this->imports->deleteWhere($id, $uid, ['state_hash' => $hash, 'status' => 'auth']);
			return;
		}
		$this->imports->updateWhere($id, $uid, ['state_hash' => null, 'pkce_enc' => null, 'auth_expires_at' => null,
			'updated_at' => $this->time->getTime()], ['state_hash' => $hash, 'status' => 'reauth']);
	}

	private function logOAuth(int $id, OAuthException $e): void {
		$context = ['import' => $id, 'error' => $e->error, 'status' => $e->httpStatus];
		if ($e->error === 'invalid_client') {
			$this->logger->warning('nextnotes: OneNote client ID or secret rejected by Microsoft (check the admin settings)', $context);
			return;
		}
		$this->logger->info('nextnotes: OneNote token request failed', $context);
	}

	/**
	 * Gültiger Zugriffstoken; erneuert, wenn er in < 5 min abläuft (§4.6). Nie in einer offenen Transaktion aufrufen.
	 * @throws TokenException
	 */
	public function accessToken(OneNoteImport $import, ?string $leaseToken = null): string {
		$access = $this->vault->open($import->getAccessEnc());
		$refresh = $this->vault->open($import->getRefreshEnc());
		if ($access === null || $refresh === null) {
			$this->toReauth($import, 'invalid_grant', $leaseToken);
			throw new TokenException('reauth');
		}
		if ((int)$import->getAccessExpiresAt() >= $this->time->getTime() + self::REFRESH_MARGIN) {
			return $access;
		}
		return $this->refresh($import, $leaseToken);
	}

	/**
	 * Refresh sofort (auch nach Graph 401); der neue Refresh-Token ersetzt den alten bedingt
	 * @throws TokenException
	 */
	public function refresh(OneNoteImport $import, ?string $leaseToken = null): string {
		$refresh = $this->vault->open($import->getRefreshEnc());
		if ($refresh === null) {
			$this->toReauth($import, 'invalid_grant', $leaseToken);
			throw new TokenException('reauth');
		}
		try {
			$tokens = $this->oauth->refresh((string)$import->getAuthority(), (string)$import->getClientId(), $this->config->secret(),
				$refresh, OneNoteConfig::scopes((int)$import->getTeamScopes() === 1));
		} catch (OAuthException $e) {
			$this->logOAuth((int)$import->getId(), $e);
			if ($e->error === 'invalid_client') {
				$this->fail($import, 'invalid_client', $leaseToken);
				throw new TokenException('failed');
			}
			if (in_array($e->error, ['invalid_grant', 'interaction_required', 'consent_required', 'invalid_scope', 'unauthorized_client'], true)) {
				$this->toReauth($import, 'invalid_grant', $leaseToken);
				throw new TokenException('reauth');
			}
			throw new TokenException('transient');
		}
		$now = $this->time->getTime();
		$set = [
			'access_enc' => $this->vault->seal($tokens->access),
			'refresh_enc' => $this->vault->seal($tokens->refresh),
			'access_expires_at' => $now + $tokens->expiresIn,
			'updated_at' => $now,
		];
		$n = $this->imports->updateWhere((int)$import->getId(), (string)$import->getUid(), $set,
			$this->guard($import, $leaseToken) + ['refresh_enc' => (string)$import->getRefreshEnc()]);
		if ($n !== 1) {
			throw new TokenException('stale');
		}
		$import->setAccessEnc((string)$set['access_enc']);
		$import->setRefreshEnc((string)$set['refresh_enc']);
		$import->setAccessExpiresAt((int)$set['access_expires_at']);
		$import->setUpdatedAt($now);
		return $tokens->access;
	}

	/** @return array<string, string> Bedingung der Schreibvorgänge: gleicher Status, im Job zusätzlich eigene Lease */
	private function guard(OneNoteImport $import, ?string $leaseToken): array {
		return ['status' => (string)$import->getStatus()] + ($leaseToken !== null ? ['lease_token' => $leaseToken] : []);
	}

	// invalid_grant: reauth mit Benachrichtigung; in select gibt es nichts fortzusetzen → Zeile löschen (§4.6)
	public function toReauth(OneNoteImport $import, string $code, ?string $leaseToken): void {
		$id = (int)$import->getId();
		$uid = (string)$import->getUid();
		if ($import->getStatus() === 'select') {
			$this->imports->deleteWhere($id, $uid, $this->guard($import, $leaseToken));
			return;
		}
		$n = $this->imports->updateWhere($id, $uid, self::cleared() + ['status' => 'reauth', 'error_code' => $code,
			'updated_at' => $this->time->getTime()], $this->guard($import, $leaseToken));
		if ($n === 1) {
			$this->notify->send($uid, $id, OneNoteNotifications::REAUTH, $code);
		}
	}

	public function fail(OneNoteImport $import, string $code, ?string $leaseToken = null): bool {
		$now = $this->time->getTime();
		$n = $this->imports->updateWhere((int)$import->getId(), (string)$import->getUid(), self::cleared() + ['status' => 'failed',
			'error_code' => $code, 'finished_at' => $now, 'updated_at' => $now], $this->guard($import, $leaseToken));
		if ($n === 1) {
			$this->notify->send((string)$import->getUid(), (int)$import->getId(), OneNoteNotifications::FAILED, $code);
		}
		return $n === 1;
	}

	/** @return array<string, null> alle Geheimnis- und Lease-Spalten auf NULL */
	public static function cleared(): array {
		return array_fill_keys(OneNoteImportMapper::SECRET_COLUMNS, null);
	}

	/**
	 * Konfigurationswechsel (§4.7): andere Client-ID/Authority oder Team-Bezug ohne erlaubte Teams → failed/config_changed
	 * (auth-Zeilen gelöscht). Bei abgeschalteter Funktion nichts (Importe ruhen). uid null = alle Benutzer.
	 * @return int betroffene Zeilen
	 */
	public function reconcile(?string $uid): int {
		if (!$this->config->enabled()) {
			return 0;
		}
		$client = $this->config->clientId();
		$authority = $this->config->authority();
		$teams = $this->config->teamsEffective();
		$done = 0;
		foreach ($this->imports->findOpen($uid) as $import) {
			$mismatch = $import->getClientId() !== $client || $import->getAuthority() !== $authority;
			$teamLoss = !$teams && (int)$import->getTeamScopes() === 1
				&& (in_array($import->getStatus(), ['auth', 'select'], true) || self::selectsGroups($import->getSelection()));
			if (!$mismatch && !$teamLoss) {
				continue;
			}
			if ($import->getStatus() === 'auth') {
				$done += $this->imports->deleteWhere((int)$import->getId(), (string)$import->getUid(), ['status' => 'auth']);
				continue;
			}
			$done += $this->fail($import, 'config_changed') ? 1 : 0;
		}
		return $done;
	}

	// Auswahl mit group:-Wurzel (Team-Notizbuch)
	private static function selectsGroups(?string $selection): bool {
		$data = $selection !== null && $selection !== '' ? json_decode($selection, true) : null;
		if (!is_array($data)) {
			return false;
		}
		foreach ($data as $entry) {
			if (is_array($entry) && is_string($entry['root'] ?? null) && str_starts_with($entry['root'], 'group:')) {
				return true;
			}
		}
		return false;
	}

	/** @return array<string, mixed> */
	public function cancel(string $uid, int $id): array {
		$this->reconcile($uid);
		$import = $this->own($uid, $id);
		$status = (string)$import->getStatus();
		if ($status === 'auth') {
			$this->imports->deleteWhere($id, $uid, ['status' => 'auth']);
			return ['import' => null];
		}
		if ($status === 'cancelled') {
			return ['import' => self::view($import)];
		}
		if (!in_array($status, OneNoteImportMapper::OPEN, true)) {
			throw new ConflictException('Import is already finished', ['code' => 'finished']);
		}
		$now = $this->time->getTime();
		$n = $this->imports->updateWhere($id, $uid, self::cleared() + ['status' => 'cancelled', 'finished_at' => $now, 'updated_at' => $now],
			['status' => $status]);
		if ($n !== 1) {
			throw new ConflictException('Import changed, reload', ['code' => 'changed']);
		}
		return ['import' => self::view($this->own($uid, $id))];
	}

	/** @return array<string, mixed> */
	public function show(string $uid, int $id): array {
		$this->reconcile($uid);
		return ['import' => self::view($this->own($uid, $id))];
	}

	/** @return array<string, mixed> eigene Importe der letzten 30 Tage und alle offenen */
	public function index(string $uid): array {
		$this->reconcile($uid);
		$since = $this->time->getTime() - self::KEEP_DAYS * 86400;
		return [
			'configured' => $this->config->enabled(),
			'imports' => array_map(self::view(...), $this->imports->findRecent($uid, $since)),
		];
	}

	private function own(string $uid, int $id): OneNoteImport {
		try {
			return $this->imports->findOwn($id, $uid);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Import not found');
		}
	}

	/** @return array<string, mixed> öffentliche Felder; nie Token, state, Verifier, ms_account, Lease, Cursor */
	public static function view(OneNoteImport $i): array {
		return [
			'id' => (int)$i->getId(),
			'status' => (string)$i->getStatus(),
			'error_code' => $i->getErrorCode(),
			'team_scopes' => (int)$i->getTeamScopes() === 1,
			'pages_total' => (int)$i->getPagesTotal(),
			'pages_done' => (int)$i->getPagesDone(),
			'pages_skipped' => (int)$i->getPagesSkipped(),
			'resources_done' => (int)$i->getResourcesDone(),
			'resources_failed' => (int)$i->getResourcesFailed(),
			'report' => self::report($i->getReport()),
			'eta_seconds' => self::eta($i),
			'next_run_at' => $i->getNextRunAt(),
			'created_at' => (int)$i->getCreatedAt(),
			'updated_at' => (int)$i->getUpdatedAt(),
			'finished_at' => $i->getFinishedAt(),
		];
	}

	// Restzeit (§4.9): offene bekannte Seiten × mittlere Anfragen je Seite / 350 je h, auf 10 min gerundet; null ohne offene Seiten
	private static function eta(OneNoteImport $i): ?int {
		$open = (int)$i->getPagesTotal() - (int)$i->getPagesDone() - (int)$i->getPagesSkipped();
		if ($open <= 0 || !in_array($i->getStatus(), ['queued', 'running'], true)) {
			return null;
		}
		$perPage = 1 + ((int)$i->getResourcesDone() + (int)$i->getResourcesFailed()) / max(1, (int)$i->getPagesDone());
		return (int)(ceil($open * $perPage / RateBudget::MAX * 3600 / 600) * 600);
	}

	/** @return list<mixed> */
	private static function report(?string $json): array {
		$data = $json !== null && $json !== '' ? json_decode($json, true) : [];
		return is_array($data) ? array_values($data) : [];
	}

	/** @return list<array<string, mixed>> Auskunft (§7): Status, Zeitpunkte, Zähler, error_code, Bericht; nichts Technisches */
	public function exportRows(string $uid): array {
		return array_map(static function (OneNoteImport $i): array {
			$v = self::view($i);
			unset($v['next_run_at'], $v['team_scopes']);
			return $v;
		}, $this->imports->findAllOf($uid));
	}

	// Kontolöschung, delete-user-data: beide Tabellen per uid (Plan M9 §3 Lebenszyklus)
	public function deleteForUser(string $uid): void {
		$this->imports->deleteByUid($uid);
		$this->items->deleteByUid($uid);
	}
}
