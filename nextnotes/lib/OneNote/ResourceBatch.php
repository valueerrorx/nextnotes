<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\OneNote;

// Geholte Ressourcen einer Seite: Temp-Dateien je Index, Platzhalter mit Grund, Summe; cleanup() im finally des Aufrufers
final class ResourceBatch {
	/** @var array<int, array{path: string, name: string, mime: string, bytes: int}> */
	public array $files = [];
	/** @var array<int, string> Index → missing | too_large | page_limit | type */
	public array $failed = [];
	public int $bytes = 0;
	/** @var list<string> alle angelegten Temp-Dateien */
	public array $temp = [];

	// Temp-Datei einer gescheiterten Ressource gleich löschen
	public function discard(string $path): void {
		if (is_file($path)) {
			unlink($path);
		}
	}

	public function cleanup(): void {
		foreach ($this->temp as $path) {
			$this->discard($path);
		}
		$this->temp = [];
		$this->files = [];
	}
}
