<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service;

use OCA\NextNotes\Service\Exception\TooLargeException;
use OCP\ITempManager;

/**
 * ZIP-Bau für Exporte (Plan M4 §1.3): eigener Temp-Ordner oc_tmp_*.nextnotes-export, Inhalte und Anhänge erst als
 * Datei, dann ZipArchive::addFile (Speicher je Eintrag, nicht je Export); Ordner im finally gelöscht. Zurück kommt
 * die geöffnete ZIP-Datei, ihr Platz wird mit Request-Ende frei. Harte Abbrüche räumt ExportTempCleanupJob.
 */
class ExportArchive {
	public const TEMP_POSTFIX = 'nextnotes-export';
	// Unkomprimierte Gesamtgröße eines Exports (Plan M4 §1.3)
	public const MAX_EXPORT_BYTES = 1073741824;
	// Kleine Einträge direkt aus dem Speicher
	private const INLINE_BYTES = 65536;

	private ?\ZipArchive $zip = null;
	private string $dir = '';
	private int $staged = 0;
	/** @var array<string, true> belegte Pfade, Kleinschreibung */
	private array $names = [];
	/** @var array<string, true> vorgemerkte Pfade (Anhänge), Kleinschreibung */
	private array $reserved = [];

	public function __construct(
		private ITempManager $temp,
	) {
	}

	public static function checkSize(int $bytes): void {
		if ($bytes > self::MAX_EXPORT_BYTES) {
			throw new TooLargeException('Export too large (max ' . intdiv(self::MAX_EXPORT_BYTES, 1048576) . ' MB)');
		}
	}

	/**
	 * Baut ein ZIP; $fill bekommt dieses Objekt (addString/addStream/unique). Grenze vorher mit checkSize prüfen.
	 * @param callable(self): void $fill
	 * @return array{kind: string, stream: resource, size: int, name: string, mime: string}
	 */
	public function build(string $downloadName, callable $fill): array {
		if ($this->zip !== null) {
			throw new \LogicException('Export archive already in use');
		}
		$dir = $this->temp->getTemporaryFolder(self::TEMP_POSTFIX);
		if ($dir === false) {
			throw new \RuntimeException('No temporary folder for the export');
		}
		$this->dir = rtrim($dir, '/');
		$this->staged = 0;
		$this->names = [];
		$this->reserved = [];
		try {
			$path = $this->dir . '/export.zip';
			$this->zip = new \ZipArchive();
			if ($this->zip->open($path, \ZipArchive::CREATE | \ZipArchive::EXCL) !== true) {
				throw new \RuntimeException('Cannot create export archive');
			}
			$fill($this);
			$zip = $this->zip;
			$this->zip = null;
			if (!$zip->close()) {
				throw new \RuntimeException('Cannot write export archive');
			}
			$handle = fopen($path, 'rb');
			$stat = $handle !== false ? fstat($handle) : false;
			if ($handle === false || $stat === false) {
				throw new \RuntimeException('Cannot read export archive');
			}
			return ['kind' => 'zip', 'stream' => $handle, 'size' => (int)$stat['size'], 'name' => $downloadName, 'mime' => 'application/zip'];
		} finally {
			// abgebrochener Bau: Änderungen verwerfen, damit libzip beim Aufräumen nichts mehr schreibt
			if ($this->zip !== null) {
				@$this->zip->unchangeAll();
				@$this->zip->close();
				$this->zip = null;
			}
			self::removeDir($this->dir);
		}
	}

	// Pfad vormerken, auf den Notizen verweisen (attachments/<id>.<ext>); unique() weicht ihm aus (B57)
	public function reserve(string $path): void {
		$this->reserved[mb_strtolower($path)] = true;
	}

	/**
	 * Freier Pfad im ZIP (B57: gleiche Pfade überschrieben sich im Upstream): belegt → name_<suffix>.ext, dann Zähler
	 */
	public function unique(string $path, string $suffix): string {
		if (!$this->taken($path)) {
			return $path;
		}
		$dot = strrpos($path, '.');
		$slash = strrpos($path, '/');
		[$base, $ext] = $dot !== false && ($slash === false || $dot > $slash) ? [substr($path, 0, $dot), substr($path, $dot)] : [$path, ''];
		$candidate = $base . '_' . $suffix . $ext;
		for ($i = 2; $this->taken($candidate); $i++) {
			$candidate = $base . '_' . $suffix . '_' . $i . $ext;
		}
		return $candidate;
	}

	private function taken(string $path): bool {
		$key = mb_strtolower($path);
		return isset($this->names[$key]) || isset($this->reserved[$key]);
	}

	// Eintrag belegen, nie überschreiben: belegt → Suffix; vorgemerkte Pfade nur für Anhänge (addStream)
	private function claim(string $path, string $suffix, bool $reservedOk): string {
		if ($reservedOk) {
			unset($this->reserved[mb_strtolower($path)]);
		}
		$path = $this->unique($path, $suffix);
		$this->names[mb_strtolower($path)] = true;
		return $path;
	}

	/** @return string tatsächlicher Pfad */
	public function addString(string $path, string $data, string $suffix = 'dup'): string {
		$zip = $this->open();
		$path = $this->claim($path, $suffix, false);
		if (strlen($data) <= self::INLINE_BYTES) {
			$zip->addFromString($path, $data);
			return $path;
		}
		$tmp = $this->stageFile();
		if (file_put_contents($tmp, $data) !== strlen($data)) {
			throw new \RuntimeException('Cannot stage export entry');
		}
		$zip->addFile($tmp, $path);
		return $path;
	}

	/**
	 * @param resource $in wird geschlossen
	 * @return string tatsächlicher Pfad
	 */
	public function addStream(string $path, $in, string $suffix = 'dup'): string {
		$zip = $this->open();
		$tmp = $this->stageFile();
		$out = fopen($tmp, 'wb');
		if ($out === false) {
			fclose($in);
			throw new \RuntimeException('Cannot copy attachment for the export');
		}
		$ok = stream_copy_to_stream($in, $out);
		fclose($in);
		fclose($out);
		if ($ok === false) {
			throw new \RuntimeException('Cannot copy attachment for the export');
		}
		$path = $this->claim($path, $suffix, true);
		$zip->addFile($tmp, $path);
		return $path;
	}

	private function open(): \ZipArchive {
		if ($this->zip === null) {
			throw new \LogicException('Export archive not open');
		}
		return $this->zip;
	}

	private function stageFile(): string {
		return $this->dir . '/s' . (++$this->staged);
	}

	// Export-Ordner ist flach (export.zip, Zwischendateien, libzip-Temp)
	public static function removeDir(string $dir): void {
		if ($dir === '' || !is_dir($dir)) {
			return;
		}
		foreach ((array)scandir($dir) as $f) {
			if ($f !== '.' && $f !== '..' && is_string($f)) {
				@unlink($dir . '/' . $f);
			}
		}
		@rmdir($dir);
	}
}
