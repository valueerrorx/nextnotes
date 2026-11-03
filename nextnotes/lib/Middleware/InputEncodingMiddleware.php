<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Middleware;

use OCA\NextNotes\Service\Exception\BadRequestException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Middleware;
use OCP\IRequest;

// Alle Request-Parameter (Query, Formular, JSON, Routenteile) nur als gültiges UTF-8; sonst 400 vor jeder Änderung (B40)
class InputEncodingMiddleware extends Middleware {
	public function __construct(
		private IRequest $request,
	) {
	}

	public function beforeController(Controller $controller, string $methodName): void {
		if (!self::valid($this->request->getParams())) {
			throw new BadRequestException('Invalid UTF-8 in request');
		}
	}

	/** @param array<array-key, mixed> $params */
	private static function valid(array $params): bool {
		foreach ($params as $key => $value) {
			if (is_string($key) && !mb_check_encoding($key, 'UTF-8')) {
				return false;
			}
			if (is_string($value) ? !mb_check_encoding($value, 'UTF-8') : (is_array($value) && !self::valid($value))) {
				return false;
			}
		}
		return true;
	}
}
