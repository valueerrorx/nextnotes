<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service;

use OCA\NextNotes\Db\AssignmentMapper;
use OCA\NextNotes\Db\NoteMapper;
use OCA\NextNotes\Db\Submission;
use OCA\NextNotes\Db\SubmissionMapper;
use OCA\NextNotes\Db\WorkspaceMapper;

/**
 * Datenauskunft eines Benutzers (ADR 0008, SPEC §8): je eigenem Workspace (kind=normal) der strukturierte Export,
 * je eigener Abgabe der Export wie GET /submissions/{id}/export (Feedback erst ab returned). Zuordnung nur über
 * owner_id/student_uid, nie über student_uid_former. Gemeinsame Quelle für occ export-user (alle eigenen Abgaben) und NextNotesMigrator
 * (vom Benutzer selbst ausgelöst: nur Abgaben, die er erreicht; M7h).
 */
class UserExportService {
	public function __construct(
		private WorkspaceMapper $workspaces,
		private SubmissionMapper $submissions,
		private NoteMapper $notes,
		private AttachmentService $attachments,
		private ZipExportService $zipExport,
		private AssignmentExportService $assignmentExport,
		private AssignmentMapper $assignments,
		private AccessService $access,
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
	public function export(string $uid, callable $add, bool $reachableOnly = false): int {
		$n = 0;
		foreach ($this->workspaces->findOwned($uid, 'normal') as $ws) {
			$zip = $this->zipExport->structuredWorkspace($uid, $ws);
			$add('workspaces/' . ExportFormatter::zipName((string)$ws->getName()) . '_' . (int)$ws->getId() . '.zip', $zip['stream']);
			$n++;
		}
		foreach ($this->ownSubmissions($uid, $reachableOnly) as $s) {
			$zip = $this->assignmentExport->ownSubmission($uid, $s);
			$add('submissions/' . (int)$s->getId() . '_' . (string)$zip['name'], $zip['stream']);
			$n++;
		}
		return $n;
	}

	// Schätzung in Byte: Inhalte und Anhänge der eigenen Notizen und Abgaben (ohne ZIP-Aufwand)
	public function estimateBytes(string $uid, bool $reachableOnly = false): int {
		$ids = [];
		foreach ($this->workspaces->findOwned($uid, 'normal') as $ws) {
			foreach ($this->notes->idsInWorkspace((int)$ws->getId(), 'note') as $id) {
				$ids[] = (int)$id;
			}
		}
		foreach ($this->ownSubmissions($uid, $reachableOnly) as $s) {
			if ($s->getNoteId() !== null) {
				$ids[] = (int)$s->getNoteId();
			}
		}
		return $ids === [] ? 0 : array_sum($this->notes->contentBytes($ids)) + $this->attachments->bytesOf($ids);
	}

	/** @return Submission[] eigene Abgaben; $reachableOnly = nur die, die der Schüler selbst erreicht (studentAccess, M7h) */
	private function ownSubmissions(string $uid, bool $reachableOnly): array {
		$own = $this->submissions->findOwn($uid);
		if (!$reachableOnly || $own === []) {
			return array_values($own);
		}
		$byId = [];
		foreach ($this->assignments->findByIds(array_keys($own)) as $a) {
			$byId[(int)$a->getId()] = $a;
		}
		return array_values(array_filter($own, fn (Submission $s): bool => isset($byId[(int)$s->getAssignmentId()])
			&& $this->access->studentAccess($uid, $s, $byId[(int)$s->getAssignmentId()])));
	}
}
