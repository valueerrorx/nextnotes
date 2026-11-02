<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service;

use OCA\NextNotes\Db\Lock;
use OCA\NextNotes\Db\LockMapper;
use OCA\NextNotes\Service\Exception\BadRequestException;
use OCA\NextNotes\Service\Exception\ConflictException;
use OCA\NextNotes\Service\Exception\LockedException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbException;
use OCP\IUserManager;

// Bearbeitungssperre nach ADR 0005: Erwerb 409, Speichern gegen fremde Sitzung 423, Übernahme nur (uid, Sitzung)
class LockService {
	public const TTL = 90;

	public function __construct(
		private LockMapper $locks,
		private AccessService $access,
		private ITimeFactory $time,
		private IUserManager $users,
	) {
	}

	private static function session(string $session): string {
		$session = trim($session);
		if ($session === '') {
			throw new BadRequestException('Missing editor session');
		}
		if (strlen($session) > 64) {
			throw new BadRequestException('Invalid editor session');
		}
		return $session;
	}

	/** @return array<string, mixed> POST /notes/{id}/lock und /lock/heartbeat */
	public function acquire(string $uid, int $noteId, string $session): array {
		$this->access->requireNote($uid, $noteId, 'write');
		$session = self::session($session);
		$lock = $this->take($noteId, $uid, $session);
		if ($lock === null || !$this->holds($lock, $uid, $session)) {
			throw new ConflictException('Note is currently locked for editing', ['lock' => $this->payload($lock, $uid, $session)]);
		}
		return ['lock' => $this->payload($lock, $uid, $session), 'taken_over' => false];
	}

	// Erwerb/Verlängerung; außerhalb von Transaktionen, damit eine Unique-Verletzung auf PostgreSQL nichts abbricht
	private function take(int $noteId, string $uid, string $session): ?Lock {
		$now = $this->time->getTime();
		$expires = $now + self::TTL;
		if ($this->locks->takeOver($noteId, $uid, $session, $now, $expires) === 0) {
			try {
				$this->locks->create($noteId, $uid, $session, $now, $expires);
			} catch (DbException $e) {
				if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
					throw $e;
				}
			}
		}
		return $this->locks->findValid($noteId, $now);
	}

	/** @return array{lock: ?array<string, mixed>} */
	public function status(string $uid, int $noteId, string $session): array {
		$this->access->requireNote($uid, $noteId, 'read');
		return ['lock' => $this->payload($this->locks->findValid($noteId, $this->time->getTime()), $uid, trim($session))];
	}

	public function release(string $uid, int $noteId, string $session): bool {
		$this->access->requireNote($uid, $noteId, 'write');
		$this->locks->release($noteId, $uid, self::session($session));
		return true;
	}

	/**
	 * Speichern (PATCH, Beacon) wie Upstream validateCurrentNoteEditLock: gültige Sperre einer anderen Sitzung → 423.
	 * Ohne Sitzung (Skript, Einmal-Schreiber) blockiert nur die Sperre eines anderen Kontos; mit Sitzung ohne Sperre wird sie erworben.
	 */
	public function assertCanSave(string $uid, int $noteId, string $session): void {
		$session = trim($session);
		$lock = $this->locks->findValid($noteId, $this->time->getTime());
		if ($session === '') {
			if ($lock === null || $lock->getHolderUid() === $uid) {
				return;
			}
		} elseif ($lock !== null && $this->holds($lock, $uid, $session)) {
			return;
		} elseif ($lock === null && strlen($session) <= 64) {
			$lock = $this->take($noteId, $uid, $session);
			if ($lock !== null && $this->holds($lock, $uid, $session)) {
				return;
			}
		}
		$message = $lock !== null ? 'This note is currently being edited by ' . $this->holderName($lock) : 'You no longer hold the edit lock for this note';
		throw new LockedException($message, ['lock' => $this->payload($lock, $uid, $session)]);
	}

	private function holds(Lock $lock, string $uid, string $session): bool {
		return $session !== '' && $lock->getHolderUid() === $uid && hash_equals((string)$lock->getHolderSession(), $session);
	}

	private function holderName(Lock $lock): string {
		$name = $this->users->getDisplayName((string)$lock->getHolderUid());
		return $name !== null && $name !== '' ? $name : 'another user';
	}

	/** @return ?array<string, mixed> Upstream buildNoteEditLockPayload; numerische Benutzer-IDs gibt es nicht (0) */
	public function payload(?Lock $lock, string $uid, string $session): ?array {
		if ($lock === null) {
			return null;
		}
		$own = $lock->getHolderUid() === $uid;
		return [
			'target_user_id' => 0,
			'note_id' => (int)$lock->getNoteId(),
			'holder_login_user_id' => 0,
			'holder_username' => $this->holderName($lock),
			'holder_is_public' => false,
			'holder_is_current_user' => $own,
			'holder_is_current_editor_session' => $own && $session !== '' && hash_equals((string)$lock->getHolderSession(), $session),
			'expires_at' => gmdate('Y-m-d H:i:s', (int)$lock->getExpiresAt()),
			'last_seen_at' => gmdate('Y-m-d H:i:s', (int)$lock->getLastSeenAt()),
		];
	}

	// LockCleanupJob
	public function deleteExpired(): int {
		return $this->locks->deleteExpired($this->time->getTime());
	}
}
