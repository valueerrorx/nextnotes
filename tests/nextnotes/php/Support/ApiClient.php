<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Support;

// HTTP gegen die laufende Instanz (Basic-Auth mit App-Token, OCS-APIRequest wie Plan §3.2)
final class ApiClient {
	public const PREFIX = '/index.php/apps/nextnotes/api/v1';

	public function __construct(
		public readonly string $uid,
		private readonly ?string $secret,
		private readonly array $extraHeaders = ['OCS-APIRequest: true'],
	) {
	}

	/** Kopie mit zusätzlichen Kopfzeilen (z. B. X-Poznote-Fragment) */
	public function withHeaders(array $headers): self {
		return new self($this->uid, $this->secret, array_merge($this->extraHeaders, $headers));
	}

	public static function baseUrl(): string {
		return rtrim((string)(getenv('NN_BASE_URL') ?: 'http://localhost'), '/');
	}

	public function call(string $method, string $path, ?array $json = null, array $headers = []): Response {
		return self::raw($method, self::baseUrl() . self::PREFIX . $path, $json, array_merge($this->extraHeaders, $headers), $this->secret === null ? null : $this->uid . ':' . $this->secret);
	}

	/** Anfrage außerhalb des App-Präfixes (z. B. /ocs/v2.php/…) */
	public function absolute(string $method, string $path, ?array $json = null): Response {
		return self::raw($method, self::baseUrl() . $path, $json, $this->extraHeaders, $this->secret === null ? null : $this->uid . ':' . $this->secret);
	}

	/**
	 * Formular-POST außerhalb des API-Präfixes (Kompatibilitätsrouten wie workspaces.php, api_save_excalidraw.php);
	 * $multipart = true wie FormData im Browser, sonst application/x-www-form-urlencoded (Arrays als name[]).
	 */
	public function form(string $path, array $fields, bool $multipart = false): Response {
		if (!$multipart) {
			return self::raw('POST', self::baseUrl() . $path, null, $this->extraHeaders, $this->uid . ':' . $this->secret, null, http_build_query($fields));
		}
		$ch = curl_init(self::baseUrl() . $path);
		curl_setopt_array($ch, [
			CURLOPT_CUSTOMREQUEST => 'POST',
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HEADER => true,
			CURLOPT_TIMEOUT => 60,
			CURLOPT_USERPWD => $this->uid . ':' . $this->secret,
			CURLOPT_HTTPHEADER => array_merge($this->extraHeaders, ['Accept: application/json']),
			CURLOPT_POSTFIELDS => array_map('strval', $fields),
		]);
		return self::toResponse($ch, (string)curl_exec($ch));
	}

	/** Formular-Anfrage mit beliebigem Verb (urlencoded), z. B. PATCH mit Bytes, die JSON nicht tragen kann */
	public function formMethod(string $method, string $path, array $fields): Response {
		return self::raw($method, self::baseUrl() . $path, null, $this->extraHeaders, $this->uid . ':' . $this->secret, null, http_build_query($fields));
	}

	/** Datei-Upload als multipart/form-data (Feld file, weitere Formularfelder) */
	public function upload(string $path, string $filename, string $bytes, array $fields = [], string $mime = 'application/octet-stream'): Response {
		$ch = curl_init(self::baseUrl() . self::PREFIX . $path);
		curl_setopt_array($ch, [
			CURLOPT_CUSTOMREQUEST => 'POST',
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HEADER => true,
			CURLOPT_TIMEOUT => 60,
			CURLOPT_USERPWD => $this->uid . ':' . $this->secret,
			CURLOPT_HTTPHEADER => array_merge($this->extraHeaders, ['Accept: application/json']),
			CURLOPT_POSTFIELDS => ['file' => new \CURLStringFile($bytes, $filename, $mime)] + $fields,
		]);
		return self::toResponse($ch, (string)curl_exec($ch));
	}

	/** Eine Datei unter beliebigem Feldnamen an eine Kompatibilitätsroute (ohne API-Präfix), z. B. background */
	public function uploadField(string $path, string $field, string $filename, string $bytes, array $fields = [], string $mime = 'application/octet-stream'): Response {
		$ch = curl_init(self::baseUrl() . $path);
		curl_setopt_array($ch, [
			CURLOPT_CUSTOMREQUEST => 'POST',
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HEADER => true,
			CURLOPT_TIMEOUT => 60,
			CURLOPT_USERPWD => $this->uid . ':' . $this->secret,
			CURLOPT_HTTPHEADER => array_merge($this->extraHeaders, ['Accept: application/json']),
			CURLOPT_POSTFIELDS => [$field => new \CURLStringFile($bytes, $filename, $mime)] + array_map('strval', $fields),
		]);
		return self::toResponse($ch, (string)curl_exec($ch));
	}

	/**
	 * Mehrere Dateien als files[] (wie FormData im Browser) an eine Kompatibilitätsroute (ohne API-Präfix)
	 * @param list<array{0: string, 1: string}> $files [Dateiname, Bytes]
	 */
	public function uploadFiles(string $path, array $files, array $fields = []): Response {
		$post = [];
		foreach ($files as $i => [$name, $bytes]) {
			$post['files[' . $i . ']'] = new \CURLStringFile($bytes, $name, 'application/octet-stream');
		}
		$ch = curl_init(self::baseUrl() . $path);
		curl_setopt_array($ch, [
			CURLOPT_CUSTOMREQUEST => 'POST',
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HEADER => true,
			CURLOPT_TIMEOUT => 120,
			CURLOPT_USERPWD => $this->uid . ':' . $this->secret,
			CURLOPT_HTTPHEADER => array_merge($this->extraHeaders, ['Accept: application/json']),
			CURLOPT_POSTFIELDS => $post + array_map('strval', $fields),
		]);
		return self::toResponse($ch, (string)curl_exec($ch));
	}

	/**
	 * Mehrere Uploads gleichzeitig (curl_multi); $uploads = [[Pfad, Dateiname, Bytes], …]
	 * @return Response[]
	 */
	public function parallelUpload(array $uploads): array {
		$mh = curl_multi_init();
		$handles = [];
		foreach ($uploads as $i => [$path, $filename, $bytes]) {
			$ch = curl_init(self::baseUrl() . self::PREFIX . $path);
			curl_setopt_array($ch, [
				CURLOPT_CUSTOMREQUEST => 'POST',
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_HEADER => true,
				CURLOPT_TIMEOUT => 60,
				CURLOPT_USERPWD => $this->uid . ':' . $this->secret,
				CURLOPT_HTTPHEADER => array_merge($this->extraHeaders, ['Accept: application/json']),
				CURLOPT_POSTFIELDS => ['file' => new \CURLStringFile($bytes, $filename, 'application/octet-stream')],
			]);
			$handles[$i] = $ch;
			curl_multi_add_handle($mh, $ch);
		}
		do {
			$st = curl_multi_exec($mh, $running);
			if ($running) {
				curl_multi_select($mh, 1.0);
			}
		} while ($running && $st === CURLM_OK);
		$out = [];
		foreach ($handles as $i => $ch) {
			$out[$i] = self::toResponse($ch, (string)curl_multi_getcontent($ch));
			curl_multi_remove_handle($mh, $ch);
		}
		curl_multi_close($mh);
		return $out;
	}

	/** Mehrere Anfragen gleichzeitig (curl_multi); $requests = [[Verb, Pfad, ?JSON-Body], …], Reihenfolge der Antworten = Eingabe */
	public function parallel(array $requests): array {
		$mh = curl_multi_init();
		$handles = [];
		foreach ($requests as $i => $req) {
			[$method, $path] = $req;
			$handles[$i] = self::handle($method, self::baseUrl() . self::PREFIX . $path, $req[2] ?? null, $this->extraHeaders, $this->uid . ':' . $this->secret);
			curl_multi_add_handle($mh, $handles[$i]);
		}
		do {
			$st = curl_multi_exec($mh, $running);
			if ($running) {
				curl_multi_select($mh, 1.0);
			}
		} while ($running && $st === CURLM_OK);
		$out = [];
		foreach ($handles as $i => $ch) {
			$out[$i] = self::toResponse($ch, (string)curl_multi_getcontent($ch));
			curl_multi_remove_handle($mh, $ch);
		}
		curl_multi_close($mh);
		return $out;
	}

	public static function raw(string $method, string $url, ?array $json, array $headers, ?string $userpwd, ?string $cookieJar = null, ?string $form = null): Response {
		$ch = self::handle($method, $url, $json, $headers, $userpwd, $cookieJar, $form);
		return self::toResponse($ch, (string)curl_exec($ch));
	}

	private static function handle(string $method, string $url, ?array $json, array $headers, ?string $userpwd, ?string $cookieJar = null, ?string $form = null): \CurlHandle {
		$ch = curl_init($url);
		// eigenes Accept (z. B. text/html wie ein Browser-Link) hat Vorrang
		if (preg_grep('/^Accept:/i', $headers) === []) {
			$headers[] = 'Accept: application/json';
		}
		$opts = [
			CURLOPT_CUSTOMREQUEST => $method,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HEADER => true,
			CURLOPT_TIMEOUT => 60,
			CURLOPT_FOLLOWLOCATION => false,
		];
		if ($userpwd !== null) {
			$opts[CURLOPT_USERPWD] = $userpwd;
		}
		if ($json !== null) {
			$headers[] = 'Content-Type: application/json';
			$opts[CURLOPT_POSTFIELDS] = json_encode($json, JSON_THROW_ON_ERROR);
		} elseif ($form !== null) {
			$headers[] = 'Content-Type: application/x-www-form-urlencoded';
			$opts[CURLOPT_POSTFIELDS] = $form;
		}
		if ($cookieJar !== null) {
			$opts[CURLOPT_COOKIEJAR] = $cookieJar;
			$opts[CURLOPT_COOKIEFILE] = $cookieJar;
		}
		$opts[CURLOPT_HTTPHEADER] = $headers;
		curl_setopt_array($ch, $opts);
		return $ch;
	}

	private static function toResponse(\CurlHandle $ch, string $raw): Response {
		$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		$hsize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
		$head = substr($raw, 0, $hsize);
		$body = substr($raw, $hsize);
		$headers = [];
		foreach (preg_split('/\r?\n/', $head) as $line) {
			if (str_contains($line, ':')) {
				[$k, $v] = explode(':', $line, 2);
				$headers[strtolower(trim($k))] = trim($v);
			}
		}
		return new Response($status, $body, $headers);
	}
}
