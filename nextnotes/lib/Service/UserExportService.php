<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service;

use OCA\NextNotes\Db\NoteMapper;
use OCA\NextNotes\Db\SubmissionMapper;
use OCA\NextNotes\Db\WorkspaceMapper;

/**
 * Datenauskunft eines Benutzers (ADR 0008, SPEC §8): je eigenem Workspace (kind=normal) der strukturierte Export,
 * je eigener Abgabe der Export wie GET /submissions/{id}/export (Feedback erst ab returned). Zuordnung nur über
 * owner_id/student_uid, nie über student_uid_former. Gemeinsame Quelle für occ export-user und NextNotesMigrator.
 */
class UserExportService {
	public function __construct(
		private WorkspaceMapper $workspaces,
		private SubmissionMapper $submissions,
		private NoteMapper $notes,
		private AttachmentService $attachments,
		private ZipExportService $zipExport,
		private AssignmentExportService $assignmentExport,
	) {
	}

	public function hasData(string $uid): bool {
		return $this->workspaces->findOwned($uid, 'normal') !== [] || $this->submissions->findOwn($uid) !== [];
	}

	/**
	 * Jeder Teil als ZIP-Datei; $add bekommt Pfad und Stream und schließt den Stream
	 * @param callable(string, resource): void $add
	 * @return int Zahl der Teile
	 */
	public function export(string $uid, callable $add): int {
		$n = 0;
		foreach ($this->workspaces->findOwned($uid, 'normal') as $ws) {
			$zip = $this->zipExport->structuredWorkspace($uid, $ws);
			$add('workspaces/' . ExportFormatter::zipName((string)$ws->getName()) . '_' . (int)$ws->getId() . '.zip', $zip['stream']);
			$n++;
		}
		foreach ($this->submissions->findOwn($uid) as $s) {
			$zip = $this->assignmentExport->submission($uid, (int)$s->getId());
			$add('submissions/' . (int)$s->getId() . '_' . (string)$zip['name'], $zip['stream']);
			$n++;
		}
		return $n;
	}

	// Schätzung in Byte: Inhalte und Anhänge der eigenen Notizen und Abgaben (ohne ZIP-Aufwand)
	public function estimateBytes(string $uid): int {
		$ids = [];
		foreach ($this->workspaces->findOwned($uid, 'normal') as $ws) {
			foreach ($this->notes->idsInWorkspace((int)$ws->getId(), 'note') as $id) {
				$ids[] = (int)$id;
			}
		}
		foreach ($this->submissions->findOwn($uid) as $s) {
			if ($s->getNoteId() !== null) {
				$ids[] = (int)$s->getNoteId();
			}
		}
		return $ids === [] ? 0 : array_sum($this->notes->contentBytes($ids)) + $this->attachments->bytesOf($ids);
	}
}
