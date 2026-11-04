<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service;

use OCA\NextNotes\Db\Note;
use OCA\NextNotes\Db\Snapshot;
use OCA\NextNotes\Db\SnapshotMapper;
use OCA\NextNotes\Db\WorkspaceMapper;
use OCA\NextNotes\Service\Exception\BadRequestException;
use OCA\NextNotes\Service\Exception\NotFoundException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDateTimeZone;

// Snapshots (Upstream SnapshotsController, lib/snapshots.php) als DB-Zeilen; Schlüssel "<Datum>--<id>"
class SnapshotService {
	// Upstream-Grenzen (src/functions.php:452-457)
	public const AUTO_INTERVAL = 600;
	public const DENSE_HOURS = 24;
	public const DEFAULT_KEEP_DAYS = 30;
	public const MAX_AGE_DAYS = 30;
	private const KEY_PATTERN = '/^(\d{4}-\d{2}-\d{2})(?:--([A-Za-z0-9_-]+))?$/';

	public function __construct(
		private SnapshotMapper $snapshots,
		private AccessService $access,
		private TagService $tags,
		private SettingsService $settings,
		private WorkspaceMapper $workspaces,
		private ContentUrlRewriter $urls,
		private ITimeFactory $time,
		private IDateTimeZone $timeZone,
	) {
	}

	private function localDate(int $ts): string {
		return (new \DateTime('@' . $ts))->setTimezone($this->timeZone->getTimeZone())->format('Y-m-d');
	}

	private function localTime(int $ts): string {
		return (new \DateTime('@' . $ts))->setTimezone($this->timeZone->getTimeZone())->format('Y-m-d H:i:s');
	}

	private function key(Snapshot $s): string {
		return $this->localDate((int)$s->getCreatedAt()) . '--' . (int)$s->getId();
	}

	// Aufbewahrung nach Einstellung des Workspace-Besitzers (Team-Workspaces: Standard)
	private function keepDaysFor(Note $note): int {
		$ws = $this->workspaces->find((int)$note->getWorkspaceId());
		return $ws->getOwnerType() === 'user' ? $this->keepDays((string)$ws->getOwnerId()) : self::DEFAULT_KEEP_DAYS;
	}

	private function keepDays(string $uid): int {
		$v = (int)$this->settings->get($uid, 'snapshots_keep_count', (string)self::DEFAULT_KEEP_DAYS);
		return ($v < 1 || $v > self::DEFAULT_KEEP_DAYS) ? self::DEFAULT_KEEP_DAYS : $v;
	}

	private function tagString(int $noteId): string {
		return implode(', ', $this->tags->tagsOf($noteId));
	}

	// Upstream isSnapshotContentEmpty
	public static function isEmptyContent(string $content, string $type): bool {
		$content = trim($content);
		if ($content === '') {
			return true;
		}
		if ($type === 'markdown') {
			return false;
		}
		if ($type === 'tasklist') {
			$decoded = json_decode($content, true);
			if (is_array($decoded)) {
				foreach ($decoded as $task) {
					foreach (is_array($task) ? ['text', 'content', 'title', 'label'] : [] as $field) {
						if (trim((string)($task[$field] ?? '')) !== '') {
							return false;
						}
					}
					if (!is_array($task) && trim((string)$task) !== '') {
						return false;
					}
				}
				return true;
			}
		}
		if (preg_match('/<(img|svg|canvas|video|audio|iframe|object|embed|table|ul|ol|li|input)\b/i', $content)) {
			return false;
		}
		$text = html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		return preg_replace('/[\s\x{00A0}]+/u', '', $text) === '';
	}

	private function insert(Note $note, string $kind, ?string $uid): Snapshot {
		$s = new Snapshot();
		$s->setNoteId((int)$note->getId());
		$s->setHeading($note->getHeading());
		$s->setContent($note->getContent());
		$s->setType($note->getType() ?: 'note');
		$s->setKind($kind);
		$s->setCreatedBy($uid);
		$s->setCreatedAt($this->time->getTime());
		return $this->snapshots->insert($s);
	}

	// Snapshot per Schlüssel oder lokalem Datum (neuester des Tages)
	private function find(int $noteId, string $key, string $date): ?Snapshot {
		$wantId = null;
		if ($key !== '') {
			if (!preg_match(self::KEY_PATTERN, $key, $m)) {
				throw new BadRequestException('Invalid snapshot key');
			}
			$date = $m[1];
			if (isset($m[2]) && $m[2] !== '') {
				if (!ctype_digit($m[2])) {
					return null;
				}
				$wantId = (int)$m[2];
			}
		} elseif ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
			throw new BadRequestException('Invalid date format');
		}
		foreach ($this->snapshots->findForNote($noteId) as $s) {
			if ($wantId !== null ? (int)$s->getId() === $wantId : $this->localDate((int)$s->getCreatedAt()) === $date) {
				return $s;
			}
		}
		return null;
	}

	/** Snapshot für Wiederherstellen (NoteService), Note schon mit write geprüft */
	public function require(Note $note, string $key, ?string $date): Snapshot {
		$s = $this->find((int)$note->getId(), trim($key), $date ?? $this->localDate($this->time->getTime()));
		if ($s === null) {
			throw new NotFoundException('No snapshot found for today');
		}
		return $s;
	}

	private function isCreatedToday(Note $note): bool {
		return $this->localDate((int)$note->getCreatedAt()) === $this->localDate($this->time->getTime());
	}

	/** @return array<string, mixed> POST /notes/{id}/snapshot */
	public function create(string $uid, int $noteId, bool $manual): array {
		$note = $this->access->requireNote($uid, $noteId, 'write');
		$today = $this->localDate($this->time->getTime());
		if (!$manual) {
			foreach ($this->snapshots->findForNote($noteId) as $s) {
				if ($s->getKind() === 'auto' && $this->localDate((int)$s->getCreatedAt()) === $today) {
					return ['exists' => true, 'message' => 'Snapshot already exists for today'];
				}
			}
			if ($this->isCreatedToday($note) && self::isEmptyContent((string)$note->getContent(), (string)$note->getType())) {
				return ['skipped' => true, 'reason' => 'empty_new_note', 'empty_new_note' => true,
					'message' => 'No automatic snapshot yet because this new note is empty'];
			}
		}
		$s = $this->insert($note, $manual ? 'manual' : 'auto', $uid);
		$this->thin($noteId, $this->keepDaysFor($note));
		return ['created' => true, 'updated' => false, 'date' => $today, 'snapshot_key' => $this->key($s), 'manual' => $manual];
	}

	// Vor einer Änderung (Upstream poznoteCreateAutomaticSnapshot): höchstens alle 10 Minuten, nie doppelt
	public function autoBefore(Note $note, string $uid): void {
		$newest = $this->snapshots->newest((int)$note->getId());
		$now = $this->time->getTime();
		if ($newest !== null && $now - (int)$newest->getCreatedAt() < self::AUTO_INTERVAL) {
			return;
		}
		if (self::isEmptyContent((string)$note->getContent(), (string)$note->getType())) {
			return;
		}
		if ($newest !== null && (string)$newest->getContent() === (string)$note->getContent()
			&& (string)$newest->getHeading() === (string)$note->getHeading()) {
			return;
		}
		$this->insert($note, 'auto', $uid);
		$this->thin((int)$note->getId(), $this->keepDaysFor($note));
	}

	// Vor einer Typ-Umwandlung immer (ohne 10-Minuten-Regel), damit der alte Typ wiederherstellbar bleibt (M4b)
	public function beforeConversion(Note $note, string $uid): void {
		if (self::isEmptyContent((string)$note->getContent(), (string)$note->getType())) {
			return;
		}
		$this->insert($note, 'auto', $uid);
		$this->thin((int)$note->getId(), $this->keepDaysFor($note));
	}

	/** @return array<string, mixed> GET /notes/{id}/snapshots */
	public function list(string $uid, int $noteId): array {
		$note = $this->access->requireNote($uid, $noteId, 'read');
		$out = [];
		foreach ($this->snapshots->findForNote($noteId) as $s) {
			$content = (string)$s->getContent();
			$out[] = [
				'snapshot_key' => $this->key($s),
				'date' => $this->localDate((int)$s->getCreatedAt()),
				'heading' => (string)$s->getHeading(),
				// Tags werden nicht versioniert (wie Upstream-Snapshots vor der Versionierung)
				'tags' => null,
				'type' => $s->getType() ?: 'note',
				'manual' => $s->getKind() === 'manual',
				'origin' => '',
				'created_at' => $this->localTime((int)$s->getCreatedAt()),
				'content_hash' => sha1($content),
				'size' => strlen($content),
			];
		}
		$current = (string)$note->getContent();
		return [
			'empty_new_note' => $out === [] && $this->isCreatedToday($note) && self::isEmptyContent($current, (string)$note->getType()),
			'current_hash' => sha1($current),
			'current_heading' => (string)$note->getHeading(),
			'current_tags' => $this->tagString($noteId),
			'snapshots' => $out,
		];
	}

	/** @return array<string, mixed> GET /notes/{id}/snapshot */
	public function show(string $uid, int $noteId, string $key, ?string $date): array {
		$note = $this->access->requireNote($uid, $noteId, 'read');
		$s = $this->find($noteId, trim($key), $date ?? $this->localDate($this->time->getTime()));
		if ($s === null) {
			return ['exists' => false, 'message' => 'No snapshot found for today', 'snapshot' => null];
		}
		return ['exists' => true, 'snapshot' => [
			'note_id' => $noteId,
			'snapshot_key' => $this->key($s),
			'date' => $this->localDate((int)$s->getCreatedAt()),
			'heading' => (string)$s->getHeading(),
			'tags' => null,
			'type' => $s->getType() ?: (string)$note->getType(),
			'manual' => $s->getKind() === 'manual',
			'origin' => '',
			'content' => $this->urls->toClient((string)$s->getContent()),
			'created_at' => $this->localTime((int)$s->getCreatedAt()),
		]];
	}

	/** @return array<string, mixed> DELETE /notes/{id}/snapshot */
	public function destroy(string $uid, int $noteId, string $key, string $date): array {
		$this->access->requireNote($uid, $noteId, 'write');
		$key = trim($key);
		$date = trim($date);
		if ($key === '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
			throw new BadRequestException('Invalid date format');
		}
		$s = $this->find($noteId, $key, $date);
		if ($s === null) {
			throw new NotFoundException('Snapshot not found');
		}
		$this->snapshots->delete($s);
		return ['snapshot_key' => $this->key($s), 'message' => 'Snapshot deleted'];
	}

	/**
	 * Upstream thinAutomaticSnapshots + Höchstalter: alle der letzten 24 h, danach der neueste je Tag für $keepDays Tage
	 */
	public function thin(int $noteId, int $keepDays): int {
		$now = $this->time->getTime();
		$denseCutoff = $now - self::DENSE_HOURS * 3600;
		$ageCutoff = $now - self::MAX_AGE_DAYS * 86400;
		$keptDays = [];
		$remove = [];
		foreach ($this->snapshots->findForNote($noteId) as $s) {
			$ts = (int)$s->getCreatedAt();
			if ($ts < $ageCutoff) {
				$remove[] = (int)$s->getId();
				continue;
			}
			if ($s->getKind() !== 'auto' || $ts >= $denseCutoff) {
				continue;
			}
			// Tage in UTC, damit der Job ohne Benutzerkontext gleich entscheidet
			$day = gmdate('Y-m-d', $ts);
			if (!isset($keptDays[$day]) && count($keptDays) < $keepDays) {
				$keptDays[$day] = true;
				continue;
			}
			$remove[] = (int)$s->getId();
		}
		$this->snapshots->deleteByIds($remove);
		return count($remove);
	}

	// SnapshotRetentionJob: Höchstalter, verwaiste Zeilen, Ausdünnen mit der Einstellung des Workspace-Besitzers
	public function retention(): int {
		$now = $this->time->getTime();
		$removed = $this->snapshots->deleteOlderThan($now - self::MAX_AGE_DAYS * 86400);
		$orphans = $this->snapshots->orphanIds();
		$this->snapshots->deleteByIds($orphans);
		$removed += count($orphans);
		$keep = [];
		foreach ($this->snapshots->autoNoteOwnersBefore($now - self::DENSE_HOURS * 3600) as $noteId => $owner) {
			$days = $owner === null ? self::DEFAULT_KEEP_DAYS : ($keep[$owner] ??= $this->keepDays($owner));
			$removed += $this->thin($noteId, $days);
		}
		return $removed;
	}
}
