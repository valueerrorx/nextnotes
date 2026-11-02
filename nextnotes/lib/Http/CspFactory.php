<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Http;

use OCP\AppFramework\Http\ContentSecurityPolicy;

/** Einzige Quelle für CSP-Abweichungen (ADR 0012); ohne ADR keine Ausnahme. */
class CspFactory {
	public function create(): ContentSecurityPolicy {
		return new ContentSecurityPolicy();
	}
}
