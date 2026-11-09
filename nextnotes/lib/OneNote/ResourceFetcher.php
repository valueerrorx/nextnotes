<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\OneNote;

use OCA\NextNotes\OneNote\Convert\ConvertResource;
use OCA\NextNotes\Service\AttachmentService;
use OCA\NextNotes\Service\Exception\BadRequestException;
use OCP\ITempManager;

/**
 * Ressourcen einer Seite in Temp-Dateien (Plan M9 §6, M9d): je Ressource ≤ min(attachment_max_file_mb, 100 MiB) dekodiert,
 * je Seite ≤ 256 MiB Summe und ≤ 15 min; Typen wie beim Upload, Bilder nur PNG/JPEG/GIF/WebP/BMP laut Inhalt.
 * Ohne Transaktion; vorübergehende Fehler (429/5xx/Netz) verwerfen die ganze Seite.
 */
class ResourceFetcher {
	public const FILE_CAP = 100 * 1024 * 1024;
	public const PAGE_BYTES = 256 * 1024 * 1024;
	public const PAGE_SECONDS = 900;
	// je Ressource: bleibt unter der Lease (GraphClient::LEASE_SECONDS), die nach jeder Anfrage verlängert wird
	public const RESOURCE_SECONDS = 240;
	public const TEMP_SUFFIX = '.nn-onenote';
	// Reste nach Prozessabbruch (SIGKILL, OOM): älter als eine Seite dauern darf
	public const TEMP_MAX_AGE = 3600;

	public function __construct(
		private ITempManager $temp,
		private AttachmentService $attachments,
	) {
	}

	/**
	 * @param list<ConvertResource> $resources
	 * @param callable(string, string, int, int): HttpResult $download URL, Datei, Grenze, Timeout; wirft GraphException
	 * @throws GraphException|TokenException vorübergehend oder Lauf beendet; Temp-Dateien sind dann schon gelöscht
	 */
	public function fetch(string $root, array $resources, callable $download): ResourceBatch {
		$batch = new ResourceBatch();
		$deadline = $this->clock() + $this->pageSeconds();
		$perFile = min($this->attachments->maxFileBytes(), self::FILE_CAP);
		try {
			foreach ($resources as $res) {
				$this->one($batch, $root, $res, $download, $deadline, $perFile);
			}
		} catch (\Throwable $e) {
			$batch->cleanup();
			throw $e;
		}
		return $batch;
	}

	/** @param callable(string, string, int, int): HttpResult $download */
	private function one(ResourceBatch $batch, string $root, ConvertResource $res, callable $download, float $deadline, int $perFile): void {
		$url = GraphClient::resourceUrl($root, $res->id);
		if ($url === null) {
			$batch->failed[$res->index] = 'missing';
			return;
		}
		$left = $this->pageBytes() - $batch->bytes;
		$start = $this->clock();
		$seconds = min(self::RESOURCE_SECONDS, (int)floor($deadline - $start));
		if ($left <= 0 || $seconds < 1) {
			$batch->failed[$res->index] = 'page_limit';
			return;
		}
		$limit = min($perFile, $left);
		$path = $this->temp->getTemporaryFile(self::TEMP_SUFFIX);
		if ($path === false) {
			throw new GraphException('network');
		}
		$batch->temp[] = $path;
		try {
			$result = $download($url, $path, $limit, $seconds);
		} catch (GraphException $e) {
			$reason = match (true) {
				$e->reason === 'too_large' => $limit < $perFile ? 'page_limit' : 'too_large',
				// Zeitgrenze (Ressource oder Seite) erreicht: Platzhalter statt Backoff
				$e->reason === 'network' && $this->clock() - $start >= $seconds - 1 => 'page_limit',
				in_array($e->reason, ['not_found', 'forbidden', 'http', 'redirect', 'host'], true) => 'missing',
				default => null,
			};
			if ($reason === null) {
				throw $e;
			}
			$batch->discard($path);
			$batch->failed[$res->index] = $reason;
			return;
		}
		$bytes = $result->bytes;
		if ($res->kind === ConvertResource::KIND_IMAGE) {
			$mime = $this->attachments->imageType($path);
			if ($mime === null) {
				$batch->discard($path);
				$batch->failed[$res->index] = 'type';
				return;
			}
			$name = AttachmentService::generatedName('image-' . ($res->index + 1), AttachmentService::IMAGE_TYPES[$mime], 'image');
		} else {
			try {
				[$name, $mime] = $this->attachments->fileRules($path, $res->name !== '' ? $res->name : 'attachment');
			} catch (BadRequestException) {
				$batch->discard($path);
				$batch->failed[$res->index] = 'type';
				return;
			}
		}
		$batch->files[$res->index] = ['path' => $path, 'name' => $name, 'mime' => $mime, 'bytes' => $bytes];
		$batch->bytes += $bytes;
	}

	/** Temp-Dateien dieses Imports älter als TEMP_MAX_AGE löschen (nur reguläre Dateien, keine Symlinks); Anzahl gelöscht */
	public function cleanStale(int $now): int {
		$dir = rtrim((string)$this->temp->getTempBaseDir(), '/');
		$handle = @opendir($dir);
		if ($handle === false) {
			return 0;
		}
		$n = 0;
		try {
			while (($entry = readdir($handle)) !== false) {
				if (!str_starts_with($entry, 'oc_tmp_') || !str_ends_with($entry, self::TEMP_SUFFIX)) {
					continue;
				}
				$path = $dir . '/' . $entry;
				$stat = @lstat($path);
				// 0100000 = S_IFREG: Symlinks, Ordner und Sonderdateien bleiben
				if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || $stat['mtime'] > $now - self::TEMP_MAX_AGE) {
					continue;
				}
				if (@unlink($path)) {
					$n++;
				}
			}
		} finally {
			closedir($handle);
		}
		return $n;
	}

	protected function clock(): float {
		return microtime(true);
	}

	protected function pageBytes(): int {
		return self::PAGE_BYTES;
	}

	protected function pageSeconds(): int {
		return self::PAGE_SECONDS;
	}
}
