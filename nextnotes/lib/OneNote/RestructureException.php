<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\OneNote;

// Ziel einer Seite (Workspace/Ordner) fehlt oder Item wurde parallel geändert: Transaktion zurück, Notizbuch neu aufbauen
final class RestructureException extends \RuntimeException {
}
