<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\Security;

use OCA\NextNotes\Tests\Support\ApiClient;
use OCA\NextNotes\Tests\Support\ApiTestCase;

// Done M2a 6: Sicherheitsantworten bleiben NC-Standard (401 ohne Login, 412 ohne requesttoken)
final class CsrfTest extends ApiTestCase {
	private string $jar = '';

	protected function tearDown(): void {
		if ($this->jar !== '' && is_file($this->jar)) {
			unlink($this->jar);
		}
		parent::tearDown();
	}

	private static function api(string $path): string {
		return ApiClient::baseUrl() . ApiClient::PREFIX . $path;
	}

	public function testWithoutLoginIs401(): void {
		$r = ApiClient::raw('GET', self::api('/notes'), null, [], null);
		self::assertSame(401, $r->status, $r->describe());
		self::assertStringNotContainsString('"success":true', $r->body);
	}

	public function testCookieSessionNeedsRequestToken(): void {
		$c = $this->createUser('csrf');
		$this->jar = tempnam(sys_get_temp_dir(), 'nn-jar');
		$base = ApiClient::baseUrl();

		$login = ApiClient::raw('GET', $base . '/index.php/login', null, ['Accept: text/html'], null, $this->jar);
		self::assertSame(200, $login->status, 'Login-Seite');
		self::assertSame(1, preg_match('/data-requesttoken="([^"]+)"/', $login->body, $m), 'requesttoken auf Login-Seite');
		$form = http_build_query(['user' => $c->uid, 'password' => self::PASSWORD, 'requesttoken' => $m[1], 'timezone' => 'UTC', 'timezone_offset' => '0']);
		// NC verlangt Origin einer vertrauenswürdigen Domain (LoginController::tryLogin)
		$post = ApiClient::raw('POST', $base . '/index.php/login', null, ['Accept: text/html', 'Origin: ' . $base], null, $this->jar, $form);
		self::assertContains($post->status, [302, 303], 'Formular-Login: ' . $post->describe());
		self::assertStringNotContainsString('/login', (string)$post->header('location'), 'Login fehlgeschlagen: ' . $post->header('location'));

		// Sitzung ohne requesttoken → 412 (GET und POST)
		$get = ApiClient::raw('GET', self::api('/notes'), null, [], null, $this->jar);
		self::assertSame(412, $get->status, 'GET ohne requesttoken: ' . $get->describe());
		$postNote = ApiClient::raw('POST', self::api('/notes'), ['heading' => 'csrf'], [], null, $this->jar);
		self::assertSame(412, $postNote->status, 'POST ohne requesttoken: ' . $postNote->describe());

		// gültiges Token der Sitzung
		$tok = ApiClient::raw('GET', $base . '/index.php/csrftoken', null, [], null, $this->jar);
		self::assertSame(200, $tok->status, 'csrftoken: ' . $tok->describe());
		$token = $tok->json()['token'] ?? '';
		self::assertNotSame('', $token);

		$get = ApiClient::raw('GET', self::api('/notes'), null, ['requesttoken: ' . $token], null, $this->jar);
		self::assertSame(200, $get->status, 'GET mit requesttoken: ' . $get->describe());
		$postNote = ApiClient::raw('POST', self::api('/notes'), ['heading' => 'csrf ok'], ['requesttoken: ' . $token], null, $this->jar);
		self::assertTrue($postNote->ok(), 'POST mit requesttoken: ' . $postNote->describe());
	}
}
