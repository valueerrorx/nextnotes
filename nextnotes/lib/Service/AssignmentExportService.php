<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service;

use OCA\NextNotes\Db\Note;
use OCA\NextNotes\Db\NoteMapper;
use OCA\NextNotes\Db\Submission;
use OCA\NextNotes\Db\SubmissionMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDateTimeZone;

/**
 * ZIP der Abgaben (SPEC §9.1.6, Plan M6 §2.6): je Schüler Ordner <Name>_<uid> mit Notiz, Anhängen und feedback.txt, dazu status.csv.
 * Lehrkraft: Aktion export (§9.7); Schüler: eigene Abgabe (ADR 0008). Bau über ExportArchive, ein Export je Benutzer gleichzeitig.
 */
class AssignmentExportService {
	public function __construct(
		private AccessService $access,
		private SubmissionMapper $submissions,
		private SubmissionStateService $states,
		private NoteMapper $notes,
		private AttachmentService $attachments,
		private ExportService $export,
		private ExportArchive $archive,
		private ZipExportService $zipExport,
		private IDateTimeZone $timeZone,
	) {
	}

	/**
	 * GET /assignments/{id}/export
	 * @return array<string, mixed>
	 */
	public function assignment(string $uid, int $id): array {
		$a = $this->access->requireAssignment($uid, $id, 'export');
		$this->states->materializeAssignment($a);
		$list = $this->submissions->findByAssignment((int)$a->getId());
		return $this->zipExport->locked($uid, function () use ($uid, $a, $list): array {
			$notes = $this->load($list);
			return $this->archive->build(ExportFormatter::fileName((string)$a->getTitle()) . '.zip', function (ExportArchive $zip) use ($uid, $list, $notes): void {
				$used = [];
				$rows = [['uid', 'name', 'status', 'submitted_at', 'points']];
				foreach ($list as $s) {
					$dir = self::dirName($s, $used);
					$this->writeSubmission($zip, $uid, $s, $notes[(int)$s->getNoteId()] ?? null, $dir, true);
					$rows[] = [self::studentUid($s), (string)$s->getStudentName(), (string)$s->getStatus(), $this->time($s->getSubmittedAt()), $s->getPoints() === null ? '' : (string)$s->getPoints()];
				}
				$zip->addString('status.csv', self::csv($rows));
			});
		});
	}

	/**
	 * GET /submissions/{id}/export: eigene Abgabe (Feedback erst nach Rückgabe) oder review
	 * @return array<string, mixed>
	 */
	public function submission(string $uid, int $id): array {
		[$s, $a] = $this->access->requireSubmission($uid, $id, 'read');
		$s = $this->states->materialize($s, $a);
		$teacher = $this->access->assignmentAllows($uid, $a, 'review');
		return $this->zipExport->locked($uid, function () use ($uid, $s, $a, $teacher): array {
			$notes = $this->load([$s]);
			$name = ExportFormatter::fileName((string)$a->getTitle() . ' ' . (string)$s->getStudentName()) . '.zip';
			return $this->archive->build($name, function (ExportArchive $zip) use ($uid, $s, $notes, $teacher): void {
				$this->writeSubmission($zip, $uid, $s, $notes[(int)$s->getNoteId()] ?? null, '', $teacher || $s->getStatus() === 'returned');
			});
		});
	}

	/**
	 * Kopie-Notizen mit Inhalt; Grenze vor dem Packen (Inhalt + Anhänge)
	 * @param Submission[] $list
	 * @return array<int, Note>
	 */
	private function load(array $list): array {
		$ids = array_values(array_filter(array_map(static fn (Submission $s) => $s->getNoteId() === null ? null : (int)$s->getNoteId(), $list)));
		$total = array_sum($this->notes->contentBytes($ids)) + $this->attachments->bytesOf($ids);
		ExportArchive::checkSize($total);
		$out = [];
		foreach ($ids as $id) {
			try {
				$out[$id] = $this->notes->find($id);
			} catch (DoesNotExistException) {
			}
		}
		return $out;
	}

	private function writeSubmission(ExportArchive $zip, string $uid, Submission $s, ?Note $note, string $dir, bool $withFeedback): void {
		$suffix = (string)(int)$s->getId();
		if ($note !== null) {
			$file = $this->export->archiveFile($uid, $note);
			$zip->addString($dir . ExportFormatter::fileName((string)($note->getHeading() ?? '')) . '.' . $file['ext'], $file['text'], $suffix);
			foreach ($file['own'] as $att) {
				$this->export->addAttachment($zip, $dir . 'attachments/' . $att['file'], $att['entity']);
			}
		}
		if ($withFeedback && ($s->getFeedback() !== null || $s->getPoints() !== null)) {
			$lines = [];
			if ($s->getPoints() !== null) {
				$lines[] = 'Points: ' . $s->getPoints();
			}
			if ((string)$s->getFeedbackByName() !== '') {
				$lines[] = 'By: ' . $s->getFeedbackByName() . ($s->getFeedbackAt() !== null ? ' (' . $this->time($s->getFeedbackAt()) . ')' : '');
			}
			$lines[] = '';
			$lines[] = (string)$s->getFeedback();
			$zip->addString($dir . 'feedback.txt', implode("\n", $lines) . "\n", $suffix);
		}
	}

	// Ordner <Name>_<uid bzw. frühere uid>, Pfadzeichen ersetzt; gleicher Name → _<Abgabe-ID> (keine Überschreibung)
	/** @param array<string, true> $used */
	private static function dirName(Submission $s, array &$used): string {
		$base = ExportFormatter::zipName(trim((string)$s->getStudentName()) . '_' . self::studentUid($s));
		$name = $base;
		for ($i = 2; isset($used[mb_strtolower($name)]); $i++) {
			$name = $base . '_' . (int)$s->getId() . ($i > 2 ? '_' . $i : '');
		}
		$used[mb_strtolower($name)] = true;
		return $name . '/';
	}

	private static function studentUid(Submission $s): string {
		return (string)($s->getStudentUid() ?? $s->getStudentUidFormer() ?? '');
	}

	private function time(?int $ts): string {
		if ($ts === null) {
			return '';
		}
		return (new \DateTimeImmutable('@' . $ts))->setTimezone($this->timeZone->getTimeZone())->format(\DateTimeInterface::ATOM);
	}

	/**
	 * CSV mit Schutz gegen Formel-Ausführung: Zellen, die mit = + - @ Tab oder CR beginnen, bekommen ein '
	 * @param list<list<string>> $rows
	 */
	public static function csv(array $rows): string {
		$out = fopen('php://temp', 'w+b');
		if ($out === false) {
			throw new \RuntimeException('Cannot build status.csv');
		}
		foreach ($rows as $row) {
			fputcsv($out, array_map(static fn (string $c): string => $c !== '' && str_contains("=+-@\t\r", $c[0]) ? "'" . $c : $c, $row), ',', '"', '');
		}
		rewind($out);
		$csv = (string)stream_get_contents($out);
		fclose($out);
		return $csv;
	}
}
