<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service\Exception;

// Circles-Fehler: Ergebnis unbekannt, nie als „Team fehlt“ werten (Plan M5 §2.1)
class TeamUnavailableException extends \RuntimeException {
}
