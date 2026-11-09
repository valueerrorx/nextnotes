<?php
// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// Mock für login.microsoftonline.com und graph.microsoft.com (nur Tests, Profil msmock, Plan M9 §9 M9a).
// Steuerung über http://ms-mock/__ctl?op=…: reset, counts, set (expires_in, token_error, sub, graph_status, pages), hold/release (Pfad anhalten),
// error (path=<host+pfad>&status=N, 0 = entfernen). M9b: Notizbuch nb1 (Abschnitt s1 mit `pages` Seiten, Gruppe g1 mit Abschnitt s2, 2 Seiten).
// Code-Format beim Token-Abruf: mock.<sub> (aud = client_id) oder mock-badaud.<sub> (aud falsch).

declare(strict_types=1);

const STATE = '/tmp/msmock-state.json';
const TID = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

function state(callable $fn): mixed {
	$h = fopen(STATE, 'c+');
	flock($h, LOCK_EX);
	$raw = stream_get_contents($h);
	$s = json_decode($raw ?: '', true);
	if (!is_array($s)) {
		$s = ['counts' => [], 'settings' => [], 'hold' => [], 'errors' => []];
	}
	$out = $fn($s);
	ftruncate($h, 0);
	rewind($h);
	fwrite($h, (string)json_encode($s));
	flock($h, LOCK_UN);
	fclose($h);
	return $out;
}

function json(int $status, array $data, array $headers = []): never {
	http_response_code($status);
	header('Content-Type: application/json');
	foreach ($headers as $k => $v) {
		header("$k: $v");
	}
	echo json_encode($data);
	exit;
}

function b64(array $a): string {
	return rtrim(strtr(base64_encode((string)json_encode($a)), '+/', '-_'), '=');
}

$host = strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]);
$path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$https = ($_SERVER['HTTPS'] ?? '') === 'on';

// Steuerung nur über HTTP (Port 80, Dienstname), nie über die Microsoft-Namen
if (!$https && str_starts_with($path, '/__ctl')) {
	$op = $_GET['op'] ?? '';
	$res = state(function (array &$s) use ($op) {
		switch ($op) {
			case 'reset':
				$s = ['counts' => [], 'settings' => [], 'hold' => [], 'errors' => []];
				return ['ok' => true];
			case 'counts':
				return ['counts' => $s['counts']];
			case 'set':
				foreach (['expires_in', 'token_error', 'sub', 'graph_status', 'pages'] as $k) {
					if (isset($_GET[$k])) {
						$s['settings'][$k] = $_GET[$k];
					}
				}
				return ['settings' => $s['settings']];
			case 'hold':
				$s['hold'][(string)($_GET['path'] ?? '')] = true;
				return ['ok' => true];
			case 'error':
				$path = (string)($_GET['path'] ?? '');
				if ((int)($_GET['status'] ?? 0) > 0) {
					$s['errors'][$path] = (int)$_GET['status'];
				} else {
					unset($s['errors'][$path]);
				}
				return ['errors' => $s['errors']];
			case 'release':
				unset($s['hold'][(string)($_GET['path'] ?? '')]);
				return ['ok' => true];
		}
		return ['error' => 'unknown op'];
	});
	json(200, $res);
}
if (!$https) {
	json(400, ['error' => 'https only']);
}

$key = $host . $path;
$settings = state(function (array &$s) use ($key) {
	$s['counts'][$key] = ($s['counts'][$key] ?? 0) + 1;
	return $s['settings'] + ['__error' => (int)($s['errors'][$key] ?? 0)];
});
// Anhalten: wartet, bis der Pfad freigegeben ist (höchstens 120 s)
for ($i = 0; $i < 1200 && state(fn (array &$s) => isset($s['hold'][$key])); $i++) {
	usleep(100_000);
}

if ($host === 'login.microsoftonline.com') {
	if (!preg_match('#^/([A-Za-z0-9.-]+)/oauth2/v2\.0/(authorize|token)$#', $path, $m)) {
		json(404, ['error' => 'not_found']);
	}
	if ($m[2] === 'authorize') {
		// Browser-Weg ohne Anmeldung: sofort zurück mit Code
		$sub = (string)($settings['sub'] ?? 'mock-sub-1');
		$q = ['code' => 'mock.' . $sub, 'state' => (string)($_GET['state'] ?? '')];
		header('Location: ' . (string)($_GET['redirect_uri'] ?? '/') . '?' . http_build_query($q), true, 302);
		exit;
	}
	if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ($_POST['client_secret'] ?? '') === '' || ($_POST['client_id'] ?? '') === '') {
		json(401, ['error' => 'invalid_client', 'error_description' => 'AADSTS7000215 mock description']);
	}
	$error = (string)($settings['token_error'] ?? '');
	if ($error !== '') {
		json($error === 'invalid_client' ? 401 : 400, ['error' => $error, 'error_description' => 'AADSTS mock description ' . $error]);
	}
	$grant = (string)($_POST['grant_type'] ?? '');
	$expires = (int)($settings['expires_in'] ?? 3600);
	$tokens = ['token_type' => 'Bearer', 'expires_in' => $expires, 'scope' => (string)($_POST['scope'] ?? ''),
		'access_token' => 'msmock-access-' . bin2hex(random_bytes(12)), 'refresh_token' => 'msmock-refresh-' . bin2hex(random_bytes(12))];
	if ($grant === 'authorization_code') {
		$code = (string)($_POST['code'] ?? '');
		if (($_POST['code_verifier'] ?? '') === '' || !preg_match('/^mock(-badaud)?\.([A-Za-z0-9_-]{1,64})$/', $code, $c)) {
			json(400, ['error' => 'invalid_grant', 'error_description' => 'AADSTS mock bad code']);
		}
		$aud = $c[1] === '-badaud' ? '00000000-0000-0000-0000-000000000000' : (string)$_POST['client_id'];
		$tokens['id_token'] = b64(['alg' => 'RS256', 'typ' => 'JWT']) . '.' . b64(['aud' => $aud, 'tid' => TID, 'sub' => $c[2], 'exp' => time() + 3600, 'iat' => time()]) . '.bW9jaw';
		json(200, $tokens);
	}
	if ($grant === 'refresh_token' && str_starts_with((string)($_POST['refresh_token'] ?? ''), 'msmock-refresh-')) {
		json(200, $tokens);
	}
	json(400, ['error' => 'invalid_grant', 'error_description' => 'AADSTS mock bad grant']);
}

if ($host === 'graph.microsoft.com') {
	$auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? (array_change_key_case(getallheaders())['authorization'] ?? ''));
	if (!str_starts_with($auth, 'Bearer msmock-access-')) {
		json(401, ['error' => ['code' => 'InvalidAuthenticationToken', 'message' => 'mock']]);
	}
	$forced = (int)($settings['graph_status'] ?? 0);
	if ($forced > 0) {
		json($forced, ['error' => ['code' => (string)$forced, 'message' => 'mock']]);
	}
	if ($path === '/v1.0/__redirect') {
		header('Location: https://graph.microsoft.com/v1.0/__target', true, 302);
		exit;
	}
	if ($settings['__error'] > 0) {
		json($settings['__error'], ['error' => ['code' => (string)$settings['__error'], 'message' => 'mock']]);
	}
	$n = max(0, min(1000, (int)($settings['pages'] ?? 3)));
	$sec = static fn (string $id, string $name): array => ['id' => $id, 'displayName' => $name];
	$nb = ['id' => 'nb1', 'displayName' => 'Mock-Buch', 'lastModifiedDateTime' => '2024-05-06T07:08:09Z',
		'sections' => [$sec('s1', 'Abschnitt')], 'sectionGroups' => [['id' => 'g1', 'displayName' => 'Gruppe', 'sections' => [$sec('s2', 'Innen')], 'sectionGroups' => []]]];
	if ($path === '/v1.0/me/onenote/notebooks') {
		json(200, ['value' => [['id' => 'nb1', 'displayName' => 'Mock-Buch', 'sections' => $nb['sections'], 'sectionGroups' => [$sec('g1', 'Gruppe')]]]]);
	}
	if ($path === '/v1.0/me/onenote/notebooks/nb1') {
		json(200, $nb);
	}
	if ($path === '/v1.0/me/joinedTeams') {
		json(200, ['value' => []]);
	}
	if (preg_match('#^/v1\.0/me/onenote/sections/(s1|s2)/pages$#', $path, $m)) {
		$ids = $m[1] === 's1' ? array_map(static fn (int $i): string => 'p-' . $i, $n > 0 ? range(0, $n - 1) : []) : ['q-0', 'q-1'];
		$skip = (int)($_GET['$skip'] ?? 0);
		$top = max(1, min(100, (int)($_GET['$top'] ?? 20)));
		$value = [];
		foreach (array_slice($ids, $skip, $top) as $k => $id) {
			$value[] = ['id' => $id, 'title' => 'Seite ' . $id, 'level' => 0, 'order' => $skip + $k,
				'createdDateTime' => '2024-01-02T03:04:05Z', 'lastModifiedDateTime' => '2024-02-03T04:05:06Z'];
		}
		$out = ['value' => $value];
		if ($skip + $top < count($ids)) {
			$out['@odata.nextLink'] = 'https://graph.microsoft.com/v1.0/me/onenote/sections/' . $m[1] . '/pages?pagelevel=true&$top=' . $top . '&$skip=' . ($skip + $top);
		}
		json(200, $out);
	}
	if (preg_match('#^/v1\.0/me/onenote/pages/([pq]-\d+)/content$#', $path, $m)) {
		header('Content-Type: text/html');
		echo '<html><body data-absolute-enabled="true"><div style="position:absolute;left:10px;top:20px"><p>Inhalt ' . $m[1] . '</p></div></body></html>';
		exit;
	}
	json(404, ['error' => ['code' => '20102', 'message' => 'mock not found']]);
}
json(404, ['error' => 'unknown host']);
