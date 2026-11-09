<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\OneNote\Convert;

// Ressource einer Seite (Bild oder Datei) in Dokumentreihenfolge; Platzhalter im HTML: #nn-onenote-res-{index}
final class ConvertResource {
	public const KIND_IMAGE = 'image';
	public const KIND_FILE = 'file';

	public function __construct(
		public readonly int $index,
		// geprüfte Graph-Ressourcen-ID (Plan M9 §6), URL baut der Abruf neu
		public readonly string $id,
		public readonly string $kind,
		// Dateiname aus data-attachment bzw. leer bei Bildern
		public readonly string $name,
		// angegebener Typ (data-fullres-src-type/type), nur Hinweis; maßgeblich ist der Inhalt
		public readonly string $type,
	) {
	}
}
