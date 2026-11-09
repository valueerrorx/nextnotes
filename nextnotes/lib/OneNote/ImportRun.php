<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\OneNote;

use OCA\NextNotes\Db\OneNoteImport;
use OCP\IL10N;

/**
 * Zustand eines Job-Laufs für einen Import (Plan M9 §4.4): Zeile, eigene Lease, Anfragezähler, dekodierte Auswahl/Cursor/Bericht/Zähler.
 * Wird vor jedem Schritt aus der DB neu geladen; Schreiben nur über OneNoteImportService (an die Lease gebunden).
 */
final class ImportRun {
	public const REPORT_MAX = 200;

	public OneNoteImport $import;
	public int $requests = 0;
	/** @var list<array{root: string, notebook: string, name: string, items: list<array{kind: string, id: string}>|null}> */
	public array $selection = [];
	/** @var array{nb: int, queue: list<array<string, mixed>>, cur: array<string, mixed>|null, base: list<int>|null} base = Zähler total/done/skipped, Berichtslänge und more-Anzahl vor dem aktuellen Notizbuch */
	public array $cursor = ['nb' => 0, 'queue' => [], 'cur' => null, 'base' => null];
	/** @var list<array{t: string, r: string, n?: int}> n: Anzahl (more: weitere Einträge, resources: fehlende Bilder/Dateien der Seite) */
	public array $report = [];
	public int $pagesTotal = 0;
	public int $pagesDone = 0;
	public int $pagesSkipped = 0;
	public int $resourcesDone = 0;
	public int $resourcesFailed = 0;
	public int $notFoundStreak = 0;
	public int $backoffLevel = 0;
	// tree: Liste wegen Anfragegrenze nicht vollständig geladen
	public bool $truncated = false;

	public function __construct(
		OneNoteImport $import,
		public readonly ?string $lease,
		public readonly IL10N $l,
	) {
		$this->load($import);
	}

	public function load(OneNoteImport $import): void {
		$this->import = $import;
		$sel = json_decode((string)$import->getSelection(), true);
		$this->selection = is_array($sel) ? array_values($sel) : [];
		$cur = json_decode((string)$import->getCursor(), true);
		$base = is_array($cur) && is_array($cur['base'] ?? null) && in_array(count($cur['base']), [3, 5], true) ? array_map('intval', array_values($cur['base'])) : null;
		$this->cursor = is_array($cur) ? [
			'nb' => (int)($cur['nb'] ?? 0),
			'queue' => is_array($cur['queue'] ?? null) ? array_values($cur['queue']) : [],
			'cur' => is_array($cur['cur'] ?? null) ? $cur['cur'] : null,
			'base' => $base,
		] : ['nb' => 0, 'queue' => [], 'cur' => null, 'base' => null];
		$rep = json_decode((string)$import->getReport(), true);
		$this->report = is_array($rep) ? array_values($rep) : [];
		$this->pagesTotal = (int)$import->getPagesTotal();
		$this->pagesDone = (int)$import->getPagesDone();
		$this->pagesSkipped = (int)$import->getPagesSkipped();
		$this->resourcesDone = (int)$import->getResourcesDone();
		$this->resourcesFailed = (int)$import->getResourcesFailed();
		$this->notFoundStreak = (int)$import->getNotFoundStreak();
		$this->backoffLevel = (int)$import->getBackoffLevel();
	}

	public function id(): int {
		return (int)$this->import->getId();
	}

	public function uid(): string {
		return (string)$this->import->getUid();
	}

	// Bericht ≤ 200 Einträge (Plan M9 §3); danach zählt ein Sammeleintrag „more“
	public function report(string $title, string $reason, int $n = 0): void {
		$title = mb_substr(trim($title), 0, 80);
		if (count($this->report) < self::REPORT_MAX - 1) {
			$this->report[] = $n > 0 ? ['t' => $title, 'r' => $reason, 'n' => $n] : ['t' => $title, 'r' => $reason];
			return;
		}
		$last = count($this->report) - 1;
		if ($last >= 0 && ($this->report[$last]['r'] ?? '') === 'more') {
			$this->report[$last]['n'] = (int)($this->report[$last]['n'] ?? 0) + 1;
			return;
		}
		$this->report[] = ['t' => '', 'r' => 'more', 'n' => 1];
	}

	/** @return list<int> Stand vor einem Notizbuch (cursor base) */
	public function mark(): array {
		$len = count($this->report);
		$last = $len > 0 ? $this->report[$len - 1] : null;
		return [$this->pagesTotal, $this->pagesDone, $this->pagesSkipped, $len, ($last['r'] ?? '') === 'more' ? (int)($last['n'] ?? 0) : 0];
	}

	/** @param list<int> $base Stand aus mark(); alte Cursor ohne Berichtslänge lassen den Bericht unverändert */
	public function rewind(array $base): void {
		[$this->pagesTotal, $this->pagesDone, $this->pagesSkipped] = $base;
		if (count($base) < 5) {
			return;
		}
		$this->report = array_slice($this->report, 0, max(0, $base[3]));
		$last = count($this->report) - 1;
		if ($last >= 0 && ($this->report[$last]['r'] ?? '') === 'more') {
			$this->report[$last]['n'] = $base[4];
		}
	}

	public function reported(): int {
		$n = 0;
		foreach ($this->report as $e) {
			// resources: Seite angelegt, nur Bilder/Dateien fehlen
			$n += match ($e['r'] ?? '') {
				'more' => (int)($e['n'] ?? 0),
				'resources' => 0,
				default => 1,
			};
		}
		return $n;
	}

	/** @return array<string, int|string> Fortschrittsspalten für ein bedingtes UPDATE */
	public function state(): array {
		return [
			'cursor' => (string)json_encode($this->cursor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
			'report' => (string)json_encode($this->report, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
			'pages_total' => $this->pagesTotal,
			'pages_done' => $this->pagesDone,
			'pages_skipped' => $this->pagesSkipped,
			'resources_done' => $this->resourcesDone,
			'resources_failed' => $this->resourcesFailed,
			'not_found_streak' => $this->notFoundStreak,
			'backoff_level' => $this->backoffLevel,
		];
	}
}
