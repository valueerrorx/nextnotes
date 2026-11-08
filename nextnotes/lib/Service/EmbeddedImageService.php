<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service;

use OCA\NextNotes\Db\Note;
use OCA\NextNotes\Service\Exception\TooLargeException;

/**
 * Base64-Bilder einer HTML-Notiz als Anhänge (Upstream NotesController::convertBase64ImagesToAttachments, M4b).
 * Ablauf: prepare() vor der Transaktion (dekodieren, Grenzen, Quote vorprüfen) → in der Transaktion zuerst
 * AttachmentService::enforceQuota, dann store(); rollt eine (auch äußere) Transaktion zurück, löscht Transaction::onRollback die Dateien.
 */
class EmbeddedImageService {
	private const IMG_TAG = '/<img\b[^<>]*+>/i';
	private const DATA_SRC = '/\ssrc\s*=\s*(["\'])(data:image\/([a-zA-Z0-9+.-]+);base64,([^"\']+))\1/i';
	private const TYPES = ['png' => 'png', 'jpeg' => 'jpg', 'jpg' => 'jpg', 'gif' => 'gif', 'webp' => 'webp', 'svg+xml' => 'svg', 'bmp' => 'bmp'];

	public function __construct(
		private AttachmentService $attachments,
	) {
	}

	/**
	 * @return array{images: array<string, array{data: string, mime: string, ext: string, alt: string}>, quota: ?array}|null null = keine Base64-Bilder
	 * @throws TooLargeException Bild über attachment_max_file_mb oder Quote überschritten (nichts geändert)
	 */
	public function prepare(string $html, Note $note, ?int $targetWorkspaceId = null): ?array {
		if (stripos($html, 'data:image/') === false) {
			return null;
		}
		$images = [];
		$bytes = 0;
		$max = $this->attachments->maxFileBytes();
		Pcre::matchAll(self::IMG_TAG, $html, $tags);
		foreach ($tags[0] as $tag) {
			if (!Pcre::match(self::DATA_SRC, $tag, $src)) {
				continue;
			}
			$url = html_entity_decode($src[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
			$type = strtolower($src[3]);
			if (isset($images[$url]) || !isset(self::TYPES[$type])) {
				continue;
			}
			$data = base64_decode($src[4]);
			if ($data === false || $data === '') {
				continue;
			}
			if (strlen($data) > $max) {
				throw new TooLargeException('Image too large (max ' . intdiv($max, 1048576) . 'MB)');
			}
			$alt = Pcre::match('/\salt\s*=\s*(["\'])(.*?)\1/is', $tag, $a) ? html_entity_decode($a[2], ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
			$images[$url] = ['data' => $data, 'mime' => 'image/' . ($type === 'jpg' ? 'jpeg' : $type), 'ext' => self::TYPES[$type], 'alt' => $alt];
			$bytes += strlen($data);
		}
		if ($images === []) {
			return null;
		}
		// Umzug beim Speichern: Ziel-Workspace; sonst Quote der Notiz (Vorlagen/Kopien beim Team, Plan M6 §2.3a)
		$quota = $targetWorkspaceId !== null ? $this->attachments->quotaFor($targetWorkspaceId, $bytes) : $this->attachments->quotaForNote($note, $bytes);
		return ['images' => $images, 'quota' => $quota];
	}

	/**
	 * In der Transaktion nach enforceQuota: Anhänge anlegen, src ersetzen
	 * @param array{images: array<string, array{data: string, mime: string, ext: string, alt: string}>, quota: ?array} $prepared
	 * @return array{content: string, adopted: list<array{url: string, id: string}>}
	 */
	public function store(array $prepared, int $noteId, string $html, string $uid): array {
		$ids = [];
		$adopted = [];
		foreach ($prepared['images'] as $url => $img) {
			$name = AttachmentService::generatedName($img['alt'], $img['ext'], 'image');
			// Datei verschwindet mit dem Rollback (createFromString, M4b-r1 MINOR 1)
			$a = $this->attachments->createFromString($noteId, $name, $img['mime'], $img['data'], $uid);
			$ids[$url] = (string)$a->getId();
			$adopted[] = ['url' => $url, 'id' => (string)$a->getId()];
		}
		$content = Pcre::replaceCallback(self::IMG_TAG, static function (array $m) use ($ids, $noteId): string {
			$tag = $m[0];
			if (!Pcre::match(self::DATA_SRC, $tag, $src, PREG_OFFSET_CAPTURE)) {
				return $tag;
			}
			$id = $ids[html_entity_decode($src[2][0], ENT_QUOTES | ENT_HTML5, 'UTF-8')] ?? null;
			if ($id === null) {
				return $tag;
			}
			$tag = substr_replace($tag, ' src="/api/v1/notes/' . $noteId . '/attachments/' . $id . '"', $src[0][1], strlen($src[0][0]));
			foreach (['loading' => 'lazy', 'decoding' => 'async'] as $name => $value) {
				if (!Pcre::match('/\s' . $name . '\s*=/i', $tag)) {
					$tag = Pcre::replace('/\s*\/?>$/', ' ' . $name . '="' . $value . '">', $tag, 1);
				}
			}
			return $tag;
		}, $html);
		return ['content' => $content, 'adopted' => $adopted];
	}

}
