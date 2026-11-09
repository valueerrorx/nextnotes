<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Tests\OneNote;

use OCA\NextNotes\OneNote\TokenSet;
use OCA\NextNotes\OneNote\TokenVault;
use OCP\Security\ICrypto;
use OCP\Server;
use PHPUnit\Framework\TestCase;

// M9a: Token und Verifier nur verschlüsselt (ICrypto); Entschlüsselungsfehler → null (Aufrufer setzt reauth)
final class TokenVaultTest extends TestCase {
	public function testSealAndOpen(): void {
		$vault = Server::get(TokenVault::class);
		$plain = 'mock-access-' . bin2hex(random_bytes(16));
		$sealed = $vault->seal($plain);
		self::assertNotSame($plain, $sealed);
		self::assertStringNotContainsString($plain, $sealed);
		self::assertNotSame($sealed, $vault->seal($plain), 'zufälliger IV');
		self::assertSame($plain, $vault->open($sealed));
	}

	public function testBrokenValuesGiveNull(): void {
		$vault = Server::get(TokenVault::class);
		self::assertNull($vault->open(null));
		self::assertNull($vault->open(''));
		self::assertNull($vault->open('kaputt'));
		$sealed = $vault->seal('x');
		self::assertNull($vault->open(substr($sealed, 0, -4) . 'AAAA'), 'HMAC falsch');
		// anderes secret (z. B. geändertes config.php secret)
		$foreign = Server::get(ICrypto::class)->encrypt('x', 'anderes-passwort');
		self::assertNull($vault->open($foreign));
	}

	public function testTokenSetHidesValuesInDumps(): void {
		$set = new TokenSet('mock-access-geheim', 'mock-refresh-geheim', 60, 'eyJ.id.token');
		foreach ([print_r($set, true), var_export($set->__debugInfo(), true)] as $dump) {
			self::assertStringNotContainsString('geheim', $dump);
			self::assertStringNotContainsString('eyJ', $dump);
		}
	}
}
