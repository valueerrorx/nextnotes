<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service;

use OCA\NextNotes\AppInfo\Application;
use OCA\NextNotes\Db\AttachmentMapper;
use OCA\NextNotes\Db\NoteMapper;
use OCA\NextNotes\Db\SnapshotMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException as FilesNotFoundException;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

// CleanupJob Schritt 4 (Plan M5 §2.6): Anhangsdateien ohne Zeile und nicht mehr verwendete Excalidraw-Vorschauen; nur sicher Erkanntes, älter als 24 h
class OrphanFileCleanup {
	private const MIN_AGE = 86400;
	private const BATCH = 100;
	private const CURSOR_FILES = 'cleanup_files_cursor';
	private const CURSOR_PREVIEWS = 'cleanup_preview_cursor';

	public function __construct(
		private IRootFolder $root,
		private AttachmentMapper $attachments,
		private NoteMapper $notes,
		private SnapshotMapper $snapshots,
		private AttachmentService $attachmentService,
		private Transaction $tx,
		private IAppConfig $config,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	public function run(int $deadline): void {
		try {
			$this->previews($deadline);
			$this->files($deadline);
		} catch (\Throwable $e) {
			$this->logger->error('nextnotes: orphan file cleanup failed', ['exception' => $e]);
		}
	}

	/** Vorschauen (origin=excalidraw) ohne Verweis in irgendeiner Notiz oder einem Snapshot; Notiz und Vorschau seit 24 h unverändert (offene Tabs) */
	public function previews(int $deadline): int {
		$cursor = $this->config->getValueInt(Application::APP_ID, self::CURSOR_PREVIEWS, 0, true);
		$cutoff = $this->time->getTime() - self::MIN_AGE;
		$removed = 0;
		while (true) {
			$batch = $this->attachments->findByOriginAfter(AttachmentService::ORIGIN_EXCALIDRAW, $cursor, $cutoff, self::BATCH);
			if ($batch === []) {
				$cursor = 0;
				break;
			}
			$noteIds = array_map(static fn ($a) => (int)$a->getNoteId(), $batch);
			$notes = [];
			foreach ($this->notes->findListByIds($noteIds) as $n) {
				$notes[(int)$n->getId()] = $n;
			}
			$contents = $this->notes->contentByIds(array_keys($notes));
			foreach ($batch as $a) {
				if ($this->time->getTime() >= $deadline) {
					$this->config->setValueInt(Application::APP_ID, self::CURSOR_PREVIEWS, $cursor, true);
					return $removed;
				}
				$cursor = $aid = (int)$a->getId();
				$noteId = (int)$a->getNoteId();
				$note = $notes[$noteId] ?? null;
				if ($note === null || (int)$note->getUpdatedAt() > $cutoff || $this->inUse($noteId, $aid, $contents[$noteId] ?? '')) {
					continue;
				}
				$removed += $this->tx->run(function () use ($noteId, $aid, $cutoff): int {
					// unter Zeilensperre neu prüfen (gleichzeitiges Speichern)
					$this->notes->lockRow($noteId);
					$fresh = $this->notes->contentByIds([$noteId]);
					$note = $this->notes->findListByIds([$noteId])[0] ?? null;
					if ($note === null || (int)$note->getUpdatedAt() > $cutoff || $this->inUse($noteId, $aid, $fresh[$noteId] ?? '')
						|| $this->usedElsewhere($aid)) {
						return 0;
					}
					$this->attachmentService->retire($noteId, $aid);
					return 1;
				});
			}
		}
		$this->config->setValueInt(Application::APP_ID, self::CURSOR_PREVIEWS, $cursor, true);
		return $removed;
	}

	// Verweis im Inhalt (wie ExcalidrawService) oder in einem Snapshot (ohne Endzeichen: lieber zu viel behalten)
	private function inUse(int $noteId, int $attachmentId, string $content): bool {
		$ref = '/notes/' . $noteId . '/attachments/' . $attachmentId;
		return Pcre::match('#' . preg_quote($ref, '#') . '(?![0-9])#', $content) || $this->snapshots->contains($noteId, $ref);
	}

	// Verweis in irgendeiner Notiz (auch Papierkorb, per HTML kopiert) oder irgendeinem Snapshot; ohne Notiz-ID und Endzeichen: lieber zu viel behalten
	private function usedElsewhere(int $attachmentId): bool {
		$needle = '/attachments/' . $attachmentId;
		return $this->notes->anyContentLike($needle) || $this->snapshots->anyContentLike($needle);
	}

	/** Dateien attachments/<note_id>/<id> ohne Zeile; Ordner gelöschter Notizen; nur älter als 24 h (laufender Upload schreibt die Zeile vor der Datei) */
	public function files(int $deadline): int {
		try {
			$base = $this->root->get($this->root->getAppDataDirectoryName() . '/' . Application::APP_ID . '/attachments');
		} catch (FilesNotFoundException) {
			return 0;
		}
		if (!$base instanceof Folder) {
			return 0;
		}
		$folders = [];
		foreach ($base->getDirectoryListing() as $node) {
			if ($node instanceof Folder && ctype_digit($node->getName())) {
				$folders[(int)$node->getName()] = $node;
			}
		}
		ksort($folders);
		$cursor = $this->config->getValueInt(Application::APP_ID, self::CURSOR_FILES, 0, true);
		$folders = array_filter($folders, static fn (int $id) => $id > $cursor, ARRAY_FILTER_USE_KEY);
		$cutoff = $this->time->getTime() - self::MIN_AGE;
		$removed = 0;
		foreach (array_chunk($folders, self::BATCH, true) as $chunk) {
			$ids = array_keys($chunk);
			$known = [];
			foreach ($this->attachments->findForNotes($ids) as $noteId => $list) {
				foreach ($list as $a) {
					$known[$noteId][(int)$a->getId()] = true;
				}
			}
			$existing = array_flip(array_map(static fn ($n) => (int)$n->getId(), $this->notes->findListByIds($ids)));
			foreach ($chunk as $noteId => $folder) {
				if ($this->time->getTime() >= $deadline) {
					$this->config->setValueInt(Application::APP_ID, self::CURSOR_FILES, $cursor, true);
					return $removed;
				}
				$cursor = $noteId;
				$removed += $this->cleanFolder($folder, $known[$noteId] ?? [], isset($existing[$noteId]), $cutoff);
			}
		}
		$this->config->setValueInt(Application::APP_ID, self::CURSOR_FILES, 0, true);
		return $removed;
	}

	/** @param array<int, true> $known Anhang-IDs mit Zeile */
	private function cleanFolder(Folder $folder, array $known, bool $noteExists, int $cutoff): int {
		$stale = [];
		$keep = 0;
		foreach ($folder->getDirectoryListing() as $node) {
			$name = $node->getName();
			// Unbekanntes (Unterordner, fremde Namen) bleibt
			if (!$node instanceof File || !ctype_digit($name) || isset($known[(int)$name]) || $node->getMTime() > $cutoff) {
				$keep++;
				continue;
			}
			$stale[] = $node;
		}
		if ($stale === []) {
			// leerer Ordner einer gelöschten Notiz
			if (!$noteExists && $keep === 0 && $known === [] && $folder->getMTime() <= $cutoff) {
				$folder->delete();
			}
			return 0;
		}
		// Notiz gelöscht und nichts mehr zu behalten: ganzer Ordner
		if (!$noteExists && $keep === 0 && $known === []) {
			$folder->delete();
			$this->logger->info('nextnotes: orphaned attachment folder deleted', ['note' => $folder->getName(), 'files' => count($stale)]);
			return count($stale);
		}
		foreach ($stale as $file) {
			$file->delete();
		}
		$this->logger->info('nextnotes: orphaned attachment files deleted', ['note' => $folder->getName(), 'files' => count($stale)]);
		return count($stale);
	}
}
