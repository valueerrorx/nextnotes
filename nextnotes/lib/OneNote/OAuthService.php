<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\OneNote;

// OAuth-Code-Flow mit PKCE S256 gegen login.microsoftonline.com (Plan M9 §4.1–4.2, §4.6, ADR 0020); keine Basis-URL konfigurierbar
class OAuthService {
	public const BASE = 'https://' . HttpGate::LOGIN_HOST . '/';

	public function __construct(
		private HttpGate $http,
	) {
	}

	public static function challenge(#[\SensitiveParameter] string $verifier): string {
		return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
	}

	/** @param list<string> $scopes */
	public function authorizeUrl(string $authority, string $clientId, string $redirectUri, array $scopes, string $state, #[\SensitiveParameter] string $verifier): string {
		return self::BASE . rawurlencode($authority) . '/oauth2/v2.0/authorize?' . http_build_query([
			'client_id' => $clientId,
			'response_type' => 'code',
			'redirect_uri' => $redirectUri,
			'response_mode' => 'query',
			'scope' => implode(' ', $scopes),
			'state' => $state,
			'code_challenge' => self::challenge($verifier),
			'code_challenge_method' => 'S256',
			'prompt' => 'select_account',
		], '', '&', PHP_QUERY_RFC3986);
	}

	/**
	 * @param list<string> $scopes
	 * @throws OAuthException
	 */
	public function redeemCode(string $authority, string $clientId, #[\SensitiveParameter] string $secret, string $redirectUri,
		#[\SensitiveParameter] string $code, #[\SensitiveParameter] string $verifier, array $scopes): TokenSet {
		return $this->token($authority, [
			'grant_type' => 'authorization_code',
			'code' => $code,
			'redirect_uri' => $redirectUri,
			'code_verifier' => $verifier,
			'client_id' => $clientId,
			'client_secret' => $secret,
			'scope' => implode(' ', $scopes),
		], true);
	}

	/**
	 * Neuer Refresh-Token ersetzt den alten (F12)
	 * @param list<string> $scopes
	 * @throws OAuthException
	 */
	public function refresh(string $authority, string $clientId, #[\SensitiveParameter] string $secret, #[\SensitiveParameter] string $refreshToken, array $scopes): TokenSet {
		return $this->token($authority, [
			'grant_type' => 'refresh_token',
			'refresh_token' => $refreshToken,
			'client_id' => $clientId,
			'client_secret' => $secret,
			'scope' => implode(' ', $scopes),
		], false);
	}

	/**
	 * ID-Token aus der direkten TLS-Antwort (OIDC Core 3.1.3.7, ohne Signaturprüfung): aud, exp, tid, sub
	 * @return string ms_account = sha256(tid:sub)
	 * @throws OAuthException id_token
	 */
	public static function accountOf(#[\SensitiveParameter] ?string $idToken, string $clientId, int $now): string {
		$parts = explode('.', (string)$idToken);
		if (count($parts) !== 3) {
			throw new OAuthException('id_token');
		}
		$json = base64_decode(strtr($parts[1], '-_', '+/'), true);
		$claims = is_string($json) ? json_decode($json, true) : null;
		if (!is_array($claims)) {
			throw new OAuthException('id_token');
		}
		$aud = $claims['aud'] ?? null;
		$tid = $claims['tid'] ?? null;
		$sub = $claims['sub'] ?? null;
		$exp = $claims['exp'] ?? null;
		if (!is_string($aud) || strtolower($aud) !== strtolower($clientId) || !is_int($exp) || $exp <= $now
			|| !is_string($tid) || $tid === '' || !is_string($sub) || $sub === '') {
			throw new OAuthException('id_token');
		}
		return hash('sha256', $tid . ':' . $sub);
	}

	/** @param array<string, string> $form */
	private function token(string $authority, #[\SensitiveParameter] array $form, bool $needIdToken): TokenSet {
		$url = self::BASE . rawurlencode($authority) . '/oauth2/v2.0/token';
		try {
			$res = $this->http->send('POST', $url, HttpGate::LOGIN_HOST, [], $form);
		} catch (GateException $e) {
			throw new OAuthException($e->reason === 'host' ? 'host' : 'network');
		}
		$data = $res->json();
		if ($res->status !== 200) {
			$error = is_array($data) && is_string($data['error'] ?? null) ? $data['error'] : 'http';
			// nur bekannte Codes weitergeben, Rest als http
			$known = ['invalid_grant', 'invalid_client', 'unauthorized_client', 'invalid_scope', 'interaction_required', 'consent_required'];
			throw new OAuthException(in_array($error, $known, true) ? $error : 'http', $res->status);
		}
		$access = is_array($data) ? ($data['access_token'] ?? null) : null;
		$refresh = is_array($data) ? ($data['refresh_token'] ?? null) : null;
		$expires = is_array($data) ? ($data['expires_in'] ?? null) : null;
		$id = is_array($data) ? ($data['id_token'] ?? null) : null;
		if (is_string($expires) && ctype_digit($expires)) {
			$expires = (int)$expires;
		}
		if (!is_string($access) || $access === '' || !is_string($refresh) || $refresh === '' || !is_int($expires) || $expires <= 0
			|| ($needIdToken && !is_string($id))) {
			throw new OAuthException('invalid_response', $res->status);
		}
		return new TokenSet($access, $refresh, $expires, is_string($id) ? $id : null);
	}
}
