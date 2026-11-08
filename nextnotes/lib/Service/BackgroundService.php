<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service;

use OCA\NextNotes\AppInfo\Application;
use OCA\NextNotes\Db\Workspace;
use OCA\NextNotes\Service\Exception\BadRequestException;
use OCA\NextNotes\Service\Exception\NotFoundException;
use OCA\NextNotes\Service\Exception\TooLargeException;
use OCP\Config\IUserConfig;
use OCP\Files\IMimeTypeDetector;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IURLGenerator;

// Hintergrundbilder (Upstream api_upload_background.php, B43): IAppData backgrounds/<workspaceId>/<uid>/<scope>.<ext>, je Benutzer
class BackgroundService {
	public const MAX_BYTES = 5 * 1024 * 1024;
	// Upstream-Scope → Dateiname; speed_dial entfällt mit dem Speed Dial (M6z), Altdateien gehen mit dem Workspace
	private const SCOPES = ['background' => 'background'];
	private const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
	// Upstream-Einstellung je Workspace-Name → intern je Workspace-ID (Umbenennen behält die Deckkraft)
	private const OPACITY = ['background_opacity_' => ['bg_opacity_', 5, 25]];

	public function __construct(
		private BackgroundFiles $files,
		private IMimeTypeDetector $mimeDetector,
		private IURLGenerator $urls,
		private IUserConfig $config,
		private WorkspaceService $workspaces,
		private AccessService $access,
	) {
	}

	// ohne Angabe Hauptansicht, unbekannter Bereich 400 (nach der Workspace-Prüfung: Fremde sehen 404)
	private static function scope(mixed $raw): string {
		$scope = $raw === null || $raw === '' ? 'background' : $raw;
		return is_string($scope) && isset(self::SCOPES[$scope]) ? $scope : throw new BadRequestException('Unknown scope');
	}

	/** @return array{0: Workspace, 1: string} Workspace mit Recht, Schlüssel */
	private function workspace(string $uid, mixed $wsKey, string $perm): array {
		$key = is_string($wsKey) ? trim($wsKey) : '';
		$ws = $key !== '' ? $this->workspaces->requireByKey($uid, $key, $perm) : $this->workspaces->defaultWorkspace($uid);
		if ($key === '') {
			$this->access->requireWorkspace($uid, $ws, $perm);
		}
		return [$ws, (string)$this->workspaces->keyFor($uid, (int)$ws->getId())];
	}

	private function file(int $wsId, string $uid, string $scope): ?ISimpleFile {
		$folder = $this->files->folder($wsId, $uid, false);
		if ($folder === null) {
			return null;
		}
		foreach (self::TYPES as $ext) {
			$name = self::SCOPES[$scope] . '.' . $ext;
			if ($folder->fileExists($name)) {
				return $folder->getFile($name);
			}
		}
		return null;
	}

	public function imageUrl(string $key, string $scope, int $mtime): string {
		return $this->urls->linkToRoute('nextnotes.compat.background') . '?' . http_build_query(['image' => 1, 'workspace' => $key, 'scope' => $scope, 'v' => $mtime]);
	}

	/** Bild-URL der Hauptansicht oder null; Leserecht hat der Aufrufer */
	public function urlFor(string $uid, Workspace $ws, string $scope): ?string {
		$file = $this->file((int)$ws->getId(), $uid, self::scope($scope));
		return $file !== null ? $this->imageUrl((string)$this->workspaces->keyFor($uid, (int)$ws->getId()), self::scope($scope), $file->getMTime()) : null;
	}

	/** @return array<string, mixed> GET: vorhandenes Bild des Benutzers in diesem Workspace */
	public function status(string $uid, mixed $wsKey, mixed $rawScope): array {
		[$ws, $key] = $this->workspace($uid, $wsKey, 'read');
		$url = $this->urlFor($uid, $ws, self::scope($rawScope));
		return $url !== null ? ['url' => $url, 'exists' => true, 'workspace' => $key] : ['exists' => false, 'workspace' => $key];
	}

	/** GET ?image=1: Leserecht; ohne eigenes Bild 404 */
	public function image(string $uid, mixed $wsKey, mixed $rawScope): ISimpleFile {
		[$ws] = $this->workspace($uid, $wsKey, 'read');
		return $this->file((int)$ws->getId(), $uid, self::scope($rawScope)) ?? throw new NotFoundException('Background not found');
	}

	public static function mimeOf(ISimpleFile $file): string {
		$ext = strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION));
		return (string)(array_search($ext, self::TYPES, true) ?: 'application/octet-stream');
	}

	/**
	 * POST: Schreibrecht, ≤ 5 MiB, Typ nach Inhalt (JPG/PNG/GIF/WebP)
	 * @param array<string, mixed>|null $file Eintrag aus IRequest::getUploadedFile
	 * @return array<string, mixed>
	 */
	public function upload(string $uid, mixed $wsKey, mixed $rawScope, ?array $file): array {
		[$ws, $key] = $this->workspace($uid, $wsKey, 'write');
		$scope = self::scope($rawScope);
		if ($file === null || !isset($file['tmp_name'])) {
			throw new BadRequestException('No file uploaded');
		}
		match ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE)) {
			UPLOAD_ERR_OK => null,
			UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => throw new TooLargeException('File too large. Maximum size is 5MB.'),
			default => throw new BadRequestException('Upload failed'),
		};
		$tmp = (string)$file['tmp_name'];
		if (!is_uploaded_file($tmp)) {
			throw new BadRequestException('Invalid uploaded file.');
		}
		if ((int)filesize($tmp) > self::MAX_BYTES) {
			throw new TooLargeException('File too large. Maximum size is 5MB.');
		}
		$mime = strtolower(trim(explode(';', $this->mimeDetector->detectContent($tmp), 2)[0]));
		$ext = self::TYPES[$mime] ?? throw new BadRequestException('Invalid file type. Only JPG, PNG, GIF, and WebP are allowed.');
		$folder = $this->files->folder((int)$ws->getId(), $uid, true);
		$name = self::SCOPES[$scope] . '.' . $ext;
		$this->removeFiles($folder, $scope, $name);
		$handle = fopen($tmp, 'rb');
		if ($handle === false) {
			throw new BadRequestException('Invalid uploaded file.');
		}
		try {
			$stored = $folder->fileExists($name) ? $folder->getFile($name) : $folder->newFile($name);
			$stored->putContent($handle);
		} finally {
			if (is_resource($handle)) {
				fclose($handle);
			}
		}
		$stored = $folder->getFile($name);
		return ['url' => $this->imageUrl($key, $scope, $stored->getMTime()), 'workspace' => $key];
	}

	/** @return array<string, mixed> DELETE: Schreibrecht */
	public function delete(string $uid, mixed $wsKey, mixed $rawScope): array {
		[$ws, $key] = $this->workspace($uid, $wsKey, 'write');
		$scope = self::scope($rawScope);
		$folder = $this->files->folder((int)$ws->getId(), $uid, false);
		if ($folder !== null) {
			$this->removeFiles($folder, $scope, null);
		}
		return ['workspace' => $key];
	}

	private function removeFiles(ISimpleFolder $folder, string $scope, ?string $keep): void {
		foreach (self::TYPES as $ext) {
			$name = self::SCOPES[$scope] . '.' . $ext;
			if ($name !== $keep && $folder->fileExists($name)) {
				$folder->getFile($name)->delete();
			}
		}
	}

	// ---------- Deckkraft (Upstream-Einstellung je Workspace-Name) ----------

	/**
	 * Upstream-Schlüssel background_opacity_<Workspace> → interner Schlüssel je ID; null = kein Deckkraft-Schlüssel
	 * @return ?array{0: string, 1: int, 2: int} Schlüssel, min, max
	 */
	public function opacityKey(string $uid, string $key, bool $write): ?array {
		foreach (self::OPACITY as $prefix => [$internal, $min, $max]) {
			if (!str_starts_with($key, $prefix)) {
				continue;
			}
			[$ws] = $this->workspace($uid, substr($key, strlen($prefix)), $write ? 'write' : 'read');
			return [$internal . (int)$ws->getId(), $min, $max];
		}
		return null;
	}

	public function readOpacity(string $uid, string $key): ?string {
		try {
			$mapped = $this->opacityKey($uid, $key, false);
		} catch (NotFoundException) {
			return '';
		}
		return $mapped === null ? null : $this->config->getValueString($uid, Application::APP_ID, $mapped[0], '', true);
	}

	public function writeOpacity(string $uid, string $key, mixed $value): ?string {
		$mapped = $this->opacityKey($uid, $key, true);
		if ($mapped === null) {
			return null;
		}
		if (!is_scalar($value) || !is_numeric((string)$value)) {
			throw new BadRequestException('value must be a number');
		}
		$normalized = (string)max($mapped[1], min($mapped[2], (int)$value));
		$this->config->setValueString($uid, Application::APP_ID, $mapped[0], $normalized, true);
		return $normalized;
	}
}
