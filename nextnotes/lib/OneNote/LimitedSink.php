<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\OneNote;

use Psr\Http\Message\StreamInterface;

/**
 * Ziel für die Option sink von IClient (Plan M9 §6): zählt die geschriebenen Bytes. curl ruft write() mit bereits
 * dekodierten Daten (gzip/br) auf; über der Grenze liefert write() 0, curl bricht die Übertragung sofort ab.
 */
final class LimitedSink implements StreamInterface {
	/** @var resource|null */
	private $handle;
	private int $bytes = 0;
	private bool $exceeded = false;

	/** @param resource $handle beschreibbar, z. B. php://temp oder Temp-Datei */
	public function __construct($handle, private int $limit) {
		$this->handle = $handle;
	}

	public function bytes(): int {
		return $this->bytes;
	}

	public function exceeded(): bool {
		return $this->exceeded;
	}

	public function write(string $string): int {
		$len = strlen($string);
		if ($this->handle === null || $this->exceeded) {
			return 0;
		}
		if ($this->bytes + $len > $this->limit) {
			$this->exceeded = true;
			return 0;
		}
		$n = fwrite($this->handle, $string);
		if ($n === false || $n !== $len) {
			throw new \RuntimeException('write failed');
		}
		$this->bytes += $n;
		return $n;
	}

	// Inhalt ab Anfang, höchstens $max Bytes
	public function contents(int $max): string {
		if ($this->handle === null) {
			return '';
		}
		rewind($this->handle);
		$out = stream_get_contents($this->handle, $max);
		return $out === false ? '' : $out;
	}

	public function __toString(): string {
		return $this->contents($this->limit);
	}

	public function close(): void {
		if ($this->handle !== null) {
			fclose($this->handle);
			$this->handle = null;
		}
	}

	public function detach() {
		$h = $this->handle;
		$this->handle = null;
		return $h;
	}

	public function getSize(): ?int {
		return $this->bytes;
	}

	public function tell(): int {
		$pos = $this->handle !== null ? ftell($this->handle) : false;
		return $pos === false ? 0 : $pos;
	}

	public function eof(): bool {
		return $this->handle === null || feof($this->handle);
	}

	public function isSeekable(): bool {
		return $this->handle !== null;
	}

	public function seek(int $offset, int $whence = SEEK_SET): void {
		if ($this->handle === null || fseek($this->handle, $offset, $whence) !== 0) {
			throw new \RuntimeException('seek failed');
		}
	}

	public function rewind(): void {
		$this->seek(0);
	}

	public function isWritable(): bool {
		return $this->handle !== null;
	}

	public function isReadable(): bool {
		return $this->handle !== null;
	}

	public function read(int $length): string {
		if ($this->handle === null || $length <= 0) {
			return '';
		}
		$out = fread($this->handle, $length);
		return $out === false ? '' : $out;
	}

	public function getContents(): string {
		if ($this->handle === null) {
			return '';
		}
		$out = stream_get_contents($this->handle);
		return $out === false ? '' : $out;
	}

	public function getMetadata(?string $key = null) {
		if ($this->handle === null) {
			return $key === null ? [] : null;
		}
		$meta = stream_get_meta_data($this->handle);
		return $key === null ? $meta : ($meta[$key] ?? null);
	}
}
