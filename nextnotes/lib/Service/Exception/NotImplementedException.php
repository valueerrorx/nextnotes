<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service\Exception;

// Funktion folgt später (z. B. Konvertierung mit Export in M4)
class NotImplementedException extends ApiException {
	public const STATUS = 501;
}
