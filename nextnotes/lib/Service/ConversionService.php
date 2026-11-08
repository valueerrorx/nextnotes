<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service;

use OCA\NextNotes\Db\NoteMapper;
use OCA\NextNotes\Service\Convert\EmbeddedDiagrams;
use OCA\NextNotes\Service\Convert\HtmlToMarkdown;
use OCA\NextNotes\Service\Convert\MarkdownRenderer;
use OCA\NextNotes\Service\Convert\MediaTags;
use OCA\NextNotes\Service\Exception\BadRequestException;
use OCA\NextNotes\Service\Exception\TooLargeException;
use OCA\NextNotes\Service\View\PageTranslator;

/**
 * Umwandlung Markdown ↔ HTML (SPEC §1.2.5, Upstream NotesController convert/convertHtml/convertMarkdown).
 * Jede Ausgabe läuft durch den HtmlSanitizer; Eingabe über MAX_RENDER_INPUT → 413 vor dem Parsen (B46).
 */
class ConversionService {
	// Gemessen (M4b, Container): schlechtester Fall Tabelle 384 KiB ≈ 0,8 s inkl. Sanitizer; 1 MiB nicht erreichbar (SPEC §4.5)
	public const MAX_RENDER_INPUT = 393216;

	private ?string $lang = null;

	public function __construct(
		private HtmlSanitizer $sanitizer,
		private EmbedDomainService $embedDomains,
		private PageTranslator $translator,
		private SettingsService $settings,
		private ContentUrlRewriter $urls,
		private AccessService $access,
		private NoteMapper $notes,
		private NoteService $noteService,
		private LockService $locks,
		private EmbeddedImageService $images,
		private AttachmentService $attachments,
		private Transaction $tx,
	) {
	}

	// Upstream t() in der Sprache des Benutzers (Callout-Titel), iframe-Hosts aus der Admin-Liste (B41)
	private function setup(string $uid): void {
		if ($this->lang === null) {
			$this->lang = $this->translator->languageFor($this->settings->read($uid, 'language'));
			$this->translator->setLanguage($this->lang);
		}
		$t = $this->translator;
		MarkdownRenderer::setTranslator(static fn (string $key, array $vars, ?string $default): string => $t->t($key, $vars, $default));
		MediaTags::setIframeHostCheck(fn (string $host): bool => $this->embedDomains->isAllowedHost($host));
	}

	public static function checkSize(string $input): void {
		if (strlen($input) > self::MAX_RENDER_INPUT) {
			throw new TooLargeException('Content too large to convert (max ' . intdiv(self::MAX_RENDER_INPUT, 1024) . ' KiB)');
		}
	}

	/** Markdown → sanitisiertes HTML in Client-Form; $richText = Inhalt einer HTML-Notiz (Upstream parseMarkdownForRichText) */
	public function markdownToHtml(string $uid, string $markdown, bool $richText = true): string {
		self::checkSize($markdown);
		$this->setup($uid);
		$markdown = $this->urls->toServer($markdown);
		$html = $richText ? MarkdownRenderer::parseMarkdownForRichText($markdown) : MarkdownRenderer::parseMarkdown($markdown);
		return $this->urls->toClient($this->sanitizer->sanitizeHtml($html));
	}

	/** HTML-Ausschnitt → sanitisiertes Markdown in Client-Form */
	public function htmlToMarkdown(string $uid, string $html): string {
		self::checkSize($html);
		$this->setup($uid);
		$markdown = HtmlToMarkdown::poznoteHtmlToMarkdown($this->urls->toServer($html));
		return $this->urls->toClient($this->sanitizer->sanitizeMarkdown($markdown));
	}

	/** Ganze HTML-Notiz → Markdown; eingebettete Excalidraw-Diagramme bleiben als HTML-Block (Upstream #1576) */
	public static function noteToMarkdown(string $content): string {
		$markdown = HtmlToMarkdown::poznoteHtmlToMarkdown(EmbeddedDiagrams::aiHideEmbeddedDiagrams($content, false));
		if (str_contains($markdown, EmbeddedDiagrams::AI_DIAGRAM_PREFIX)) {
			$markdown = ltrim(EmbeddedDiagrams::aiRestoreEmbeddedDiagrams(self::diagramBlocks($markdown), $content, true), "\n");
		}
		return $markdown;
	}

	// Upstream ~\s*(![…](/poznote-diagram/…))\s*~ → "\n\n$1\n\n", Leerraum ohne Regex entfernt (linear, B46)
	private static function diagramBlocks(string $markdown): string {
		$prefix = preg_quote(EmbeddedDiagrams::AI_DIAGRAM_PREFIX, '~');
		Pcre::matchAll('~!\[[^\]\n]*+\]\(' . $prefix . '[^)\n]*+\)~', $markdown, $m, PREG_OFFSET_CAPTURE);
		$out = '';
		$pos = 0;
		foreach ($m[0] as [$image, $at]) {
			if ($at < $pos) {
				continue;
			}
			$out .= rtrim(substr($markdown, $pos, $at - $pos), " \t\n\r\x0B\x0C") . "\n\n" . $image . "\n\n";
			$pos = $at + strlen($image);
			$pos += strspn($markdown, " \t\n\r\x0B\x0C", $pos);
		}
		return $out . substr($markdown, $pos);
	}

	/** @return array{markdown: string} POST /convert-html */
	public function convertHtml(string $uid, mixed $html): array {
		$html = is_scalar($html) ? (string)$html : '';
		if (trim($html) === '') {
			throw new BadRequestException('Missing "html" field');
		}
		return ['markdown' => $this->htmlToMarkdown($uid, $html)];
	}

	/** @return array{html: string} POST /convert-markdown */
	public function convertMarkdown(string $uid, mixed $markdown): array {
		$markdown = is_scalar($markdown) ? (string)$markdown : '';
		if (trim($markdown) === '') {
			throw new BadRequestException('Missing "markdown" field');
		}
		return ['html' => $this->markdownToHtml($uid, $markdown)];
	}

	/** Export einer Markdown-Notiz (Upstream parseMarkdown): sanitisiertes HTML in Server-Form; > MAX_RENDER_INPUT → 413 */
	public function markdownForExport(string $uid, string $markdown): string {
		self::checkSize($markdown);
		$this->setup($uid);
		return $this->sanitizer->sanitizeHtml(MarkdownRenderer::parseMarkdown($this->urls->toServer($markdown)));
	}

	/** Snapshot-Vorschau ?render=1 (Revisionen-Seite) */
	public function renderSnapshot(string $uid, string $markdown): string {
		return $this->markdownToHtml($uid, $markdown, false);
	}

	/**
	 * POST /notes/{id}/convert: Schreibrecht, Sperre wie PATCH, Snapshot vorher, Base64-Bilder → Anhänge (HTML → Markdown)
	 * @return array{id: int, type: string, message: string}
	 */
	public function convertNote(string $uid, int $id, mixed $target, string $session): array {
		$target = is_string($target) ? strtolower(trim($target)) : '';
		if (!in_array($target, ['html', 'markdown'], true)) {
			throw new BadRequestException('Invalid target type. Use "html" or "markdown"');
		}
		$note = $this->access->requireNote($uid, $id, 'write');
		self::checkType((string)($note->getType() ?: 'note'), $target);
		$this->locks->assertCanSave($uid, $id, $session);
		$this->setup($uid);
		$content = (string)$note->getContent();
		$images = null;
		if ($target === 'markdown') {
			$images = $this->images->prepare($content, $note);
		} else {
			self::checkSize($content);
		}
		$newType = $this->tx->run(function () use ($uid, $id, $target, $images): string {
			$this->attachments->enforceQuota($images['quota'] ?? null);
			$this->notes->lockRow($id);
			$note = $this->notes->find($id);
			self::checkType((string)($note->getType() ?: 'note'), $target);
			$content = (string)$note->getContent();
			if ($target === 'markdown') {
				if ($images !== null) {
					$content = $this->images->store($images, $id, $content, $uid)['content'];
				}
				self::checkSize($content);
				$this->noteService->storeConverted($note, 'markdown', self::noteToMarkdown($content), $uid);
				return 'markdown';
			}
			self::checkSize($content);
			$this->noteService->storeConverted($note, 'note', MarkdownRenderer::parseMarkdownForRichText($content), $uid);
			return 'note';
		});
		return ['id' => $id, 'type' => $newType, 'message' => 'Note converted successfully'];
	}

	private static function checkType(string $current, string $target): void {
		if ($target === 'html' && $current !== 'markdown') {
			throw new BadRequestException('Only markdown notes can be converted to HTML');
		}
		if ($target === 'markdown' && !in_array($current, ['note', 'html'], true)) {
			throw new BadRequestException('Only HTML notes can be converted to markdown');
		}
	}
}
