<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Http;

use OCA\NextNotes\Db\Attachment;
use OCP\AppFramework\Http\EmptyContentSecurityPolicy;
use OCP\AppFramework\Http\FileDisplayResponse;
use OCP\Files\SimpleFS\ISimpleFile;

/** Auslieferung eines Anhangs (API-GET und Alt-URL api_attachments.php, SPEC §4.2/§10) */
class AttachmentResponse {
	// Passive Textformate inline als Text (wie Upstream getSafeInlineTextContentType)
	private const TEXT = ['txt' => 'text/plain', 'json' => 'application/json', 'csv' => 'text/csv', 'xml' => 'text/plain',
		'md' => 'text/plain', 'markdown' => 'text/plain', 'log' => 'text/plain', 'ini' => 'text/plain', 'cfg' => 'text/plain',
		'conf' => 'text/plain', 'toml' => 'text/plain', 'sql' => 'text/plain', 'yml' => 'text/plain', 'yaml' => 'text/plain'];

	public static function build(Attachment $attachment, ISimpleFile $file, bool $download): FileDisplayResponse {
		$mime = strtolower(trim(explode(';', (string)$attachment->getMime(), 2)[0]));
		$name = (string)$attachment->getOriginalName();
		[$type, $inline] = $download ? ['application/octet-stream', false] : self::presentation($mime, $name);
		$ascii = preg_replace('/[^\x20-\x7E]|["\\\\]/', '_', $name) ?? 'attachment';
		$response = new FileDisplayResponse($file);
		// Nach dem Konstruktor setzen: er schreibt eine eigene inline-Disposition
		$response->addHeader('Content-Type', $type);
		$response->addHeader('Content-Disposition', ($inline ? 'inline' : 'attachment') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
		$response->addHeader('X-Content-Type-Options', 'nosniff');
		// PDF-Betrachter der Browser laufen nicht unter sandbox; PDF nur im eigenen iFrame (ADR 0012 Nachtrag), sonst abgeschottet
		if ($type === 'application/pdf') {
			$csp = new EmptyContentSecurityPolicy();
			$csp->addAllowedFrameAncestorDomain("'self'");
			$response->setContentSecurityPolicy($csp);
		} else {
			$response->addHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'self'; sandbox");
		}
		if (!$download) {
			$response->cacheFor(3600);
		}
		return $response;
	}

	/**
	 * Inline: PDF, Rasterbilder, Audio, Video, passive Textformate (als Text); SVG und alles andere als Download
	 * @return array{0: string, 1: bool} Content-Type, inline
	 */
	private static function presentation(string $mime, string $name): array {
		$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
		if (isset(self::TEXT[$ext])) {
			return [self::TEXT[$ext] . '; charset=utf-8', true];
		}
		$family = strstr($mime, '/', true);
		$isSvg = str_contains($mime, 'svg') || in_array($ext, ['svg', 'svgz'], true);
		if (!$isSvg && ($mime === 'application/pdf' || in_array($family, ['image', 'audio', 'video'], true))) {
			return [$mime, true];
		}
		return [$mime !== '' && preg_match('#\A[a-z0-9][a-z0-9.+-]*/[a-z0-9][a-z0-9.+-]*\z#', $mime) ? $mime : 'application/octet-stream', false];
	}
}
