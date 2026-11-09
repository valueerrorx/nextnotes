<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\OneNote\Convert;

// Ergebnis des Seiten-Konverters: bereinigtes HTML mit Platzhaltern und Bericht-Angaben
final class ConvertResult {
	/** @param list<ConvertResource> $resources höchstens PageConverter::MAX_RESOURCES */
	public function __construct(
		public readonly string $html,
		public readonly array $resources,
		// Ressourcen über der Grenze je Seite oder mit fremder/ungültiger URL, gleich als Platzhalter ausgegeben
		public readonly int $skippedResources,
		// Freihand-Stellen, als Hinweis ausgegeben
		public readonly int $ink,
		// Seite zu groß oder zu komplex: html ist nur der Hinweis
		public readonly bool $tooLarge,
	) {
	}
}
