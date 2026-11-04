<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service;

use OCA\NextNotes\Db\Attachment;
use OCA\NextNotes\Db\Note;
use OCA\NextNotes\Db\NoteMapper;
use OCA\NextNotes\Db\NoteTagMapper;
use OCA\NextNotes\Service\Exception\BadRequestException;
use OCA\NextNotes\Service\Exception\NotFoundException;
use OCA\NextNotes\Service\Exception\TooLargeException;
use OCA\NextNotes\Service\View\IndexViewService;
use OCA\NextNotes\Service\View\PageTranslator;
use OCP\IDateTimeZone;

/**
 * Export einer Notiz (Upstream api_export_note.php, api_download_note.php; Plan M4 §1.3, SPEC §4.5).
 * Rechte: Leserecht auf die Notiz, verknüpfte Notiz → Ziel nur mit Leserecht (B42). Eingebettet/ins ZIP kommen nur
 * Anhänge der exportierten Notiz, [[…]] werden mit einem filterReadable-Aufruf aufgelöst (B47).
 * Ergebnis: ['kind' => 'file'|'zip'|'print', …]; die Antwort baut der Controller.
 */
class ExportService {
	public const FORMATS = ['html', 'markdown', 'json', 'html_embedded'];
	// Base64 im Speicher (html_embedded): ~4/3 der Bilder plus Kopien
	public const MAX_EMBED_BYTES = 33554432;
	private const IMG_TAG = '/<img\b[^<>]*+>/i';
	private const SRC_ATTR = '/\ssrc\s*=\s*(["\'])([^"\']*+)\1/i';
	private const ATTACHMENT_URL = '#^/api/v1/notes/(\d++)/attachments/(\d++)(?:[?\#].*)?$#';

	public function __construct(
		private AccessService $access,
		private WorkspaceService $workspaces,
		private NoteMapper $notes,
		private NoteTagMapper $tags,
		private AttachmentService $attachments,
		private FolderService $folders,
		private HtmlSanitizer $sanitizer,
		private ConversionService $conversion,
		private ContentUrlRewriter $urls,
		private SearchTextNormalizer $normalizer,
		private PageTranslator $tr,
		private SettingsService $settings,
		private IndexViewService $indexView,
		private IDateTimeZone $timeZone,
		private ExportArchive $archive,
	) {
	}

	/**
	 * GET api_export_note.php
	 * @return array<string, mixed>
	 */
	public function export(string $uid, mixed $rawId, mixed $wsKey, mixed $format, mixed $disposition): array {
		$format = is_string($format) && $format !== '' ? strtolower($format) : 'html';
		$disposition = is_string($disposition) && trim($disposition) !== '' ? strtolower(trim($disposition)) : 'attachment';
		if (!in_array($format, self::FORMATS, true)) {
			throw new BadRequestException('Invalid format. Use "html", "markdown", "json" or "html_embedded"');
		}
		if (!in_array($disposition, ['attachment', 'inline'], true)) {
			throw new BadRequestException('Invalid disposition. Use "attachment" or "inline"');
		}
		$note = $this->source($uid, $rawId, $wsKey);
		$type = self::typeOf($note);
		$content = (string)($note->getContent() ?? '');
		$title = (string)($note->getHeading() ?? '');
		if ($format === 'json') {
			if ($type !== 'tasklist') {
				throw new BadRequestException('JSON export is only available for tasklist notes');
			}
			$raw = Pcre::replace('/^\xEF\xBB\xBF/', '', $content);
			if (ExportFormatter::tasks($raw) === null) {
				throw new BadRequestException('Tasklist content is not valid JSON');
			}
			return self::file($raw, ExportFormatter::fileName($title) . '.json', 'application/json');
		}
		if ($format === 'markdown') {
			return $this->markdown($uid, $note, $type, $content, $title);
		}
		$this->language($uid);
		$body = $this->htmlBody($uid, $note, $type, $content);
		$tags = $this->tagsOf($note);
		// B48: inline nur für die Druckansicht (format=html), sonst immer Download
		if ($format === 'html' && $disposition === 'inline') {
			$reading = $this->indexView->readingSettings($uid);
			return ['kind' => 'print', 'title' => $title, 'tags' => $tags, 'noteId' => (int)$note->getId(), 'type' => $type,
				'body' => $this->urls->toClient($body), 'bodyClasses' => $reading['classes'], 'fontSize' => $reading['fontSize']];
		}
		$own = $this->ownAttachments($note);
		if ($format === 'html_embedded') {
			return self::file(ExportFormatter::styledHtml($this->embedImages($body, $note, $own), $title, $tags), ExportFormatter::fileName($title) . '.html', 'text/html');
		}
		if ($own === []) {
			return self::file(ExportFormatter::styledHtml($body, $title, $tags), ExportFormatter::fileName($title) . '.html', 'text/html');
		}
		$body = self::zipPaths($body, (int)$note->getId(), $own);
		$html = ExportFormatter::styledHtml($body, $title, $tags, ExportFormatter::attachmentLinks(array_values($own), $body));
		return $this->zip(ExportFormatter::fileName($title), '.html', $html, $own);
	}

	/**
	 * GET api_download_note.php: HTML-, Aufgaben-, Excalidraw-Notiz als formatiertes HTML; Markdown roh als .md (B56)
	 * @return array<string, mixed>
	 */
	public function download(string $uid, mixed $rawId, mixed $wsKey): array {
		$note = $this->source($uid, $rawId, $wsKey);
		$type = self::typeOf($note);
		$content = (string)($note->getContent() ?? '');
		$name = Pcre::replace('/[^a-zA-Z0-9_\-]/', '_', (string)($note->getHeading() ?? ''));
		$name = $name !== '' ? $name : 'note_' . (int)$note->getId();
		if ($type === 'markdown') {
			return self::file($content, $name . '.md', 'text/markdown');
		}
		$this->language($uid);
		return self::file(ExportFormatter::styledHtml($this->htmlBody($uid, $note, $type, $content), (string)($note->getHeading() ?? ''), $this->tagsOf($note)), $name . '.html', 'text/html');
	}

	// Leserecht, ?workspace= muss (falls gesetzt) zur angefragten Notiz passen, verknüpfte Notiz → Ziel (B42)
	private function source(string $uid, mixed $rawId, mixed $wsKey): Note {
		if (!is_string($rawId) || !ctype_digit($rawId)) {
			throw new NotFoundException('Note not found');
		}
		$note = $this->access->requireNote($uid, (int)$rawId, 'read');
		$ws = $this->workspaces->resolveOptional($uid, $wsKey);
		if ($ws !== null && $ws->getId() !== $note->getWorkspaceId()) {
			throw new NotFoundException('Note not found');
		}
		return $this->access->resolveLinked($uid, $note);
	}

	private static function typeOf(Note $note): string {
		$type = (string)($note->getType() ?: 'note');
		return in_array($type, ['note', 'markdown', 'tasklist', 'excalidraw'], true) ? $type : 'note';
	}

	private function language(string $uid): void {
		$this->tr->setLanguage($this->tr->languageFor($this->settings->read($uid, 'language')));
	}

	/** @return list<string> */
	private function tagsOf(Note $note): array {
		return array_values(array_map('strval', $this->tags->tagsFor([(int)$note->getId()])[(int)$note->getId()] ?? []));
	}

	// Inhalt als bereinigtes HTML in Server-Form; Umwandlung Markdown → HTML mit Render-Grenze (413), Rest ohne
	private function htmlBody(string $uid, Note $note, string $type, string $content): string {
		$html = match ($type) {
			'markdown' => $this->conversion->markdownForExport($uid, $content),
			'tasklist' => ExportFormatter::tasklistToHtml($content),
			default => $content,
		};
		return $this->sanitizer->sanitizeHtml(ExportFormatter::cleanContent($html, fn (array $refs): array => $this->resolveReferences($uid, $note, $refs)));
	}

	/**
	 * Markdown mit Front Matter, mit Anhängen als ZIP (Upstream exportAsMarkdownZip); ohne Umwandlung, daher ohne Render-Grenze
	 * @return array<string, mixed>
	 */
	private function markdown(string $uid, Note $note, string $type, string $content, string $title): array {
		if ($type === 'tasklist') {
			$content = ExportFormatter::tasklistToMarkdown($content);
		} elseif ($type !== 'markdown') {
			throw new BadRequestException('Markdown export is only available for markdown and tasklist notes');
		}
		$folderId = $note->getFolderId();
		$md = $this->frontMatter($note, $this->tagsOf($note), $folderId !== null ? FolderService::pathOf((int)$folderId, $this->folders->byId((int)$note->getWorkspaceId())) : null, $type === 'tasklist');
		$own = $this->ownAttachments($note);
		if ($own === []) {
			return self::file($md . $content, ExportFormatter::fileName($title) . '.md', 'text/markdown');
		}
		$content = self::zipPaths($content, (int)$note->getId(), $own);
		return $this->zip(ExportFormatter::fileName($title), '.md', $md . $content . ExportFormatter::attachmentMarkdown(array_values($own), $content), $own);
	}

	/**
	 * Front Matter einer Notiz (Upstream addFrontMatterToMarkdown), Zeiten in der Server-Zeitzone
	 * @param list<string> $tags
	 */
	public function frontMatter(Note $note, array $tags, ?string $folderPath, bool $tasklist): string {
		$tz = $this->timeZone->getTimeZone();
		$date = static fn (?int $ts): string => $ts === null ? '' : (new \DateTime('@' . $ts))->setTimezone($tz)->format('Y-m-d H:i:s');
		$title = (string)($note->getHeading() ?? '');
		return ExportFormatter::frontMatter([
			'title' => $title !== '' ? $title : 'New note',
			'tags' => $tags,
			'folder' => $folderPath,
			'tasklist' => $tasklist,
			'favorite' => (int)$note->getFavorite() === 1,
			'created' => $date($note->getCreatedAt()),
			'updated' => $date($note->getUpdatedAt()),
		]);
	}

	/**
	 * Anhänge der exportierten Notiz (B47: nie die anderer Notizen)
	 * @return array<int, array{id: int, name: string, mime: string, file: string, size: int, entity: Attachment}>
	 */
	private function ownAttachments(Note $note): array {
		return self::attachmentEntries($this->attachments->forNotes([(int)$note->getId()])[(int)$note->getId()] ?? []);
	}

	/**
	 * Anhänge mit Dateiname im ZIP (<id>.<ext>, wie Upstream)
	 * @param Attachment[] $list
	 * @return array<int, array{id: int, name: string, mime: string, file: string, size: int, entity: Attachment}>
	 */
	public static function attachmentEntries(array $list): array {
		$out = [];
		foreach ($list as $a) {
			$name = (string)$a->getOriginalName();
			$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
			$id = (int)$a->getId();
			$out[$id] = ['id' => $id, 'name' => $name, 'mime' => (string)$a->getMime(), 'size' => (int)$a->getSize(), 'entity' => $a,
				'file' => $id . (Pcre::match('/^[a-z0-9]{1,10}$/', $ext) ? '.' . $ext : '')];
		}
		return $out;
	}

	/**
	 * Verweise /api/v1/notes/<eigene>/attachments/<eigener Anhang> → <prefix><datei> (Upstream exportAs*Zip); Fremdes bleibt (B47)
	 * @param array<int, array{file: string}> $own
	 */
	public static function zipPaths(string $content, int $noteId, array $own, string $prefix = 'attachments/'): string {
		if ($own === [] || !str_contains($content, '/attachments/')) {
			return $content;
		}
		return Pcre::replaceCallback('#/api/v1/notes/(\d++)/attachments/(\d++)#', static fn (array $m): string => (int)$m[1] === $noteId && isset($own[(int)$m[2]])
			? $prefix . $own[(int)$m[2]]['file'] : $m[0], $content);
	}

	/**
	 * <img> auf eigene Bild-Anhänge → data: (Upstream convertImagesToBase64InHtml); Fremdes/Unbekanntes bleibt Verweis (B47)
	 * @param array<int, array{id: int, mime: string, size: int, entity: Attachment}> $own
	 */
	private function embedImages(string $html, Note $note, array $own): string {
		$id = (int)$note->getId();
		$wanted = [];
		Pcre::matchAll(self::IMG_TAG, $html, $tags);
		foreach ($tags[0] as $tag) {
			$aid = Pcre::match(self::SRC_ATTR, $tag, $src) ? self::ownImage($src[2], $id, $own) : null;
			if ($aid !== null) {
				$wanted[$aid] = true;
			}
		}
		$bytes = array_sum(array_map(static fn (int $aid): int => $own[$aid]['size'], array_keys($wanted)));
		if ($bytes > self::MAX_EMBED_BYTES) {
			throw new TooLargeException('Images too large to embed (max ' . intdiv(self::MAX_EMBED_BYTES, 1048576) . ' MB)');
		}
		$data = [];
		foreach (array_keys($wanted) as $aid) {
			try {
				$data[$aid] = 'data:' . strtolower($own[$aid]['mime']) . ';base64,' . base64_encode($this->attachments->fileFor($own[$aid]['entity'])->getContent());
			} catch (NotFoundException) {
			}
		}
		if ($data === []) {
			return $html;
		}
		return Pcre::replaceCallback(self::IMG_TAG, static function (array $m) use ($id, $own, $data): string {
			if (!Pcre::match(self::SRC_ATTR, $m[0], $src, PREG_OFFSET_CAPTURE)) {
				return $m[0];
			}
			$aid = self::ownImage($src[2][0], $id, $own);
			return $aid !== null && isset($data[$aid]) ? substr_replace($m[0], $data[$aid], $src[2][1], strlen($src[2][0])) : $m[0];
		}, $html);
	}

	/** @param array<int, array{mime: string}> $own */
	private static function ownImage(string $src, int $noteId, array $own): ?int {
		$path = html_entity_decode($src, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		if (!Pcre::match(self::ATTACHMENT_URL, $path, $m) || (int)$m[1] !== $noteId || !isset($own[(int)$m[2]])) {
			return null;
		}
		return str_starts_with(strtolower($own[(int)$m[2]]['mime']), 'image/') ? (int)$m[2] : null;
	}

	/**
	 * [[…]] wie Upstream im Workspace der Notiz (Zahl = Notiz-ID, sonst Titel ohne Groß/Klein/Akzente); eine Notizabfrage,
	 * ≤ 3 Abfragen. Gefunden → Link (bei Zahl mit Titel), sonst Zahl → „Nicht verfügbar“, Titel → eigener Text (B47)
	 * @param list<string> $refs
	 * @return array<string, array{href: ?string, text: string}>
	 */
	private function resolveReferences(string $uid, Note $source, array $refs): array {
		$wsId = (int)$source->getWorkspaceId();
		$ids = [];
		$titles = [];
		foreach ($refs as $ref) {
			if (ctype_digit($ref)) {
				$ids[] = (int)$ref;
			} elseif (trim($ref) !== '') {
				$titles[$this->normalizer->fold(trim($ref))][] = $ref;
			}
		}
		// Nur referenzierte Notizen im Workspace der Quelle, eine Abfrage für Zahlen und Titel; Rechte über AccessService (B47)
		$readable = [];
		$byTitle = [];
		$found = $this->notes->findReferenced($wsId, $ids, array_map('strval', array_keys($titles)));
		foreach ($this->access->filterReadableBeside($source, $found) as $n) {
			$readable[(int)$n->getId()] = $n;
			$heading = trim((string)$n->getHeading());
			foreach ($titles[$this->normalizer->fold($heading)] ?? [] as $ref) {
				// Upstream findIdByTitle: exakte Schreibweise vor neuester Änderung vor höchster ID
				$rank = [$heading === trim($ref) ? 1 : 0, (int)$n->getUpdatedAt(), (int)$n->getId()];
				if (!isset($byTitle[$ref]) || $rank > $byTitle[$ref][0]) {
					$byTitle[$ref] = [$rank, (int)$n->getId()];
				}
			}
		}
		$key = $this->workspaces->keyFor($uid, $wsId) ?? '';
		$href = static fn (int $id): string => 'index.php?' . http_build_query(['note' => $id, 'workspace' => $key]);
		$out = [];
		foreach ($refs as $ref) {
			if (ctype_digit($ref)) {
				$n = $readable[(int)$ref] ?? null;
				$heading = $n !== null ? trim((string)$n->getHeading()) : '';
				$out[$ref] = $n !== null
					? ['href' => $href((int)$ref), 'text' => $heading !== '' ? $heading : $ref]
					: ['href' => null, 'text' => $this->tr->t('common.not_available', [], 'Not available')];
				continue;
			}
			$id = $byTitle[$ref][1] ?? null;
			$out[$ref] = ['href' => $id !== null ? $href($id) : null, 'text' => $ref];
		}
		return $out;
	}

	/**
	 * ZIP mit Hauptdatei und Anhängen (Upstream exportAs*Zip) über ExportArchive
	 * @param array<int, array{file: string, size: int, entity: Attachment}> $own
	 * @return array<string, mixed>
	 */
	private function zip(string $base, string $ext, string $main, array $own): array {
		ExportArchive::checkSize(strlen($main) + array_sum(array_map(static fn (array $a): int => $a['size'], $own)));
		return $this->archive->build($base . '.zip', function (ExportArchive $zip) use ($base, $ext, $main, $own): void {
			$zip->addString($base . $ext, $main);
			foreach ($own as $a) {
				$this->addAttachment($zip, 'attachments/' . $a['file'], $a['entity']);
			}
		});
	}

	/** Anhang gestreamt ins ZIP; Datei fehlt → ausgelassen */
	public function addAttachment(ExportArchive $zip, string $path, Attachment $a): bool {
		try {
			$in = $this->attachments->fileFor($a)->read();
		} catch (NotFoundException) {
			return false;
		}
		if (!is_resource($in)) {
			throw new \RuntimeException('Cannot copy attachment for the export');
		}
		$zip->addStream($path, $in);
		return true;
	}

	/** @return array{kind: string, body: string, name: string, mime: string} */
	private static function file(string $body, string $name, string $mime): array {
		return ['kind' => 'file', 'body' => $body, 'name' => $name, 'mime' => $mime . '; charset=utf-8'];
	}
}
