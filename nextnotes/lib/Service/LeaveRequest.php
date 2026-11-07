<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service;

use OCA\NextNotes\Db\Folder;
use OCA\NextNotes\Db\Workspace;

// Eingabe für AccessService::requireLeave (Plan M5 §2.2, §2.8); M6 erweitert um Aufgaben-Ausnahmen
final class LeaveRequest {
	// Notizen einzeln (PATCH, moveToFolder, moveFiles, Archiv einer Notiz)
	public const NOTES = 'notes';
	// Ordnerzweig (FolderService::move, Ordner-Archiv)
	public const FOLDER = 'folder';
	// ganzer Workspace (moveNotes, Workspace löschen)
	public const WORKSPACE = 'workspace';
	// Kopie (Duplikat Notiz/Ordner): auch im selben Workspace gegen Abgaben/Vorlagen geprüft
	public const COPY = 'copy';
	// Anhang auf andere Notiz: auch im selben Workspace geprüft
	public const ATTACHMENT = 'attachment';

	/**
	 * @param int[] $noteIds betroffene Notizen (NOTES, COPY, ATTACHMENT: Quellnotiz)
	 * @param Folder[] $folders Ordnerzweig (FOLDER; bei COPY eines Ordners)
	 */
	public function __construct(
		public readonly Workspace $source,
		public readonly Workspace $target,
		public readonly string $kind,
		public readonly array $noteIds = [],
		public readonly array $folders = [],
	) {
	}

	public function leaves(): bool {
		return $this->source->getId() !== $this->target->getId();
	}

	// Anderer Quoten-Eigentümer (Benutzer bzw. Team) beim Ziel
	public function changesOwner(): bool {
		return $this->source->getOwnerType() !== $this->target->getOwnerType() || $this->source->getOwnerId() !== $this->target->getOwnerId();
	}
}
