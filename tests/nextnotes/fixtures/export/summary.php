<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Vergleichbare Zusammenfassung eines Export-ZIP (Plan M4d Done 1): Pfade mit symbolischen IDs, Front-Matter-Schlüssel, Manifest-Schlüssel
if (!function_exists('nnExportSummary')) {
	/**
	 * @param array{notes: array<int|string, string>, attachments: array<int|string, string>} $ids echte ID → Schlüssel (N1, A1)
	 * @return array{files: list<string>, frontmatter: array<string, list<string>>, manifest: list<string>}
	 */
	function nnExportSummary(string $zipPath, array $ids): array {
		$zip = new ZipArchive();
		if ($zip->open($zipPath) !== true) {
			throw new RuntimeException('ZIP nicht lesbar: ' . $zipPath);
		}
		$files = [];
		$front = [];
		$manifest = [];
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = (string)$zip->getNameIndex($i);
			$norm = nnExportNormalize($name, $ids);
			$files[] = $norm;
			$data = (string)$zip->getFromIndex($i);
			if (str_ends_with($name, '.md') && preg_match('/^---\n(.*?)\n---\n/s', $data, $m)) {
				preg_match_all('/^([a-z_]+):/m', $m[1], $k);
				$front[$norm] = $k[1];
			}
			if ($name === 'poznote_attachments_metadata.json') {
				foreach ((array)json_decode($data, true) as $row) {
					$manifest[] = implode(',', array_keys((array)$row)) . '|' . implode(',', array_keys((array)($row['attachment_data'] ?? [])))
						. '|' . nnExportNormalize('files/' . (string)($row['attachment_data']['filename'] ?? ''), $ids)
						. '|' . ($ids['notes'][(string)($row['note_id'] ?? '')] ?? '?');
				}
			}
		}
		$zip->close();
		sort($files);
		ksort($front);
		sort($manifest);
		return ['files' => $files, 'frontmatter' => $front, 'manifest' => $manifest];
	}

	/** @param array{notes: array<int|string, string>, attachments: array<int|string, string>} $ids */
	function nnExportNormalize(string $path, array $ids): string {
		$dir = str_contains($path, '/') ? substr($path, 0, strrpos($path, '/') + 1) : '';
		$base = substr($path, strlen($dir));
		$note = static fn (string $id): ?string => $ids['notes'][$id] ?? null;
		if (in_array($dir, ['attachments/', 'files/'], true) && preg_match('/^([0-9]+)(\.[a-z0-9]+)?$/', $base, $m) && isset($ids['attachments'][$m[1]])) {
			return $dir . '{' . $ids['attachments'][$m[1]] . '}' . ($m[2] ?? '');
		}
		if (preg_match('/^([0-9]+)\.(html|md)$/', $base, $m) && $note($m[1]) !== null) {
			return $dir . '{' . $note($m[1]) . '}.' . $m[2];
		}
		if (preg_match('/^(.*)_([0-9]+)\.(html|md)$/', $base, $m) && $note($m[2]) !== null) {
			return $dir . $m[1] . '_{' . $note($m[2]) . '}.' . $m[3];
		}
		return $path;
	}
}
