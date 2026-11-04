<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service;

use OCA\NextNotes\Db\Note;
use OCA\NextNotes\Db\NoteMapper;
use OCA\NextNotes\Db\SnapshotMapper;
use OCA\NextNotes\Service\Exception\BadRequestException;
use OCA\NextNotes\Service\Exception\NotFoundException;
use OCA\NextNotes\Service\Exception\TooLargeException;

/**
 * Excalidraw (Upstream excalidraw_editor.php, api_save_excalidraw.php, lib/excalidraw-*.php).
 * Eingebettet: <div class="excalidraw-container" id="…" data-excalidraw="JSON"> mit Vorschau-<img>.
 * Vollmodus (Altbestand): Container mit <div class="excalidraw-data">JSON</div>; Anlegen neuer Notizen (note_id=0) entfällt.
 * Container werden per Tag-Scanner gefunden (Anführungszeichen-bewusst, possessiv), nie per Regex über das JSON.
 */
class ExcalidrawService {
	// Obergrenze je Diagramm (maskiert bis ca. 2× im Inhalt; Sanitizer verarbeitet große Attribute per PARSEHUGE)
	public const MAX_DATA_BYTES = 7 * 1024 * 1024;
	public const MAX_PREVIEW_BYTES = 20 * 1024 * 1024;
	private const DIAGRAM_ID = '/^[A-Za-z0-9_-]{1,100}$/';
	private const TAG = '/<(\/?)(div|button)\b((?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+)>/i';
	private const TAG_AT = '/\G<(?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+>/';
	private const ATTR = '/([^\s=\/>"\']++)(?:\s*=\s*(?:"([^"]*+)"|\'([^\']*+)\'|([^\s>"\']++)))?/';
	private const BASE_IMG_STYLE = ['max-width' => '100%', 'height' => 'auto'];

	public function __construct(
		private AccessService $access,
		private WorkspaceService $workspaces,
		private LockService $locks,
		private NoteMapper $notes,
		private NoteService $noteService,
		private SnapshotService $snapshots,
		private SnapshotMapper $snapshotMapper,
		private AttachmentService $attachments,
		private Transaction $tx,
	) {
	}

	/**
	 * Daten der Editorseite; fremd/unbekannt → 404 (Leserecht genügt, Schreiben entscheidet canWrite)
	 * @return array{note: Note, diagramId: ?string, existingData: ?string, workspace: string, canWrite: bool}
	 */
	public function page(string $uid, mixed $rawNoteId, mixed $rawDiagramId, mixed $wsKey): array {
		$note = $this->note($uid, $rawNoteId, $wsKey, 'read');
		$diagramId = is_string($rawDiagramId) && $rawDiagramId !== '' ? $rawDiagramId : null;
		if ($diagramId !== null && !Pcre::match(self::DIAGRAM_ID, $diagramId)) {
			throw new NotFoundException('Note not found');
		}
		$content = (string)($note->getContent() ?? '');
		$data = $diagramId !== null ? $this->embeddedData($content, $diagramId) : $this->fullData($content);
		return [
			'note' => $note,
			'diagramId' => $diagramId,
			'existingData' => $data,
			'workspace' => (string)$this->workspaces->keyFor($uid, (int)$note->getWorkspaceId()),
			'canWrite' => $this->access->noteLevel($uid, $note) >= AccessService::WRITE && $note->getLinkedNoteId() === null,
		];
	}

	/** @return array<string, mixed> api_save_excalidraw.php action=save_embedded_diagram */
	public function saveEmbedded(string $uid, mixed $rawNoteId, mixed $rawDiagramId, mixed $wsKey, mixed $data, mixed $svg, mixed $cursor): array {
		if (!is_string($rawDiagramId) || !Pcre::match(self::DIAGRAM_ID, $rawDiagramId) || !is_string($rawNoteId) || !ctype_digit($rawNoteId) || (int)$rawNoteId <= 0) {
			throw new BadRequestException('Invalid note ID or diagram ID');
		}
		$diagramId = $rawDiagramId;
		$cursor = is_string($cursor) && Pcre::match('/^\d{1,9}$/', $cursor) ? (int)$cursor : null;
		$id = $this->writable($uid, $rawNoteId, $wsKey);
		$json = self::diagramJson($data);
		$preview = $this->preview($svg);
		$this->write($uid, $id, $preview, 'excalidraw_' . $diagramId . '.svg', function (string $content, string $type, ?int $aid) use ($id, $diagramId, $json, $cursor): array {
			return $this->placeEmbedded($content, $type === 'markdown', $id, $diagramId, $json, $aid, $cursor);
		});
		return ['note_id' => $id, 'diagram_id' => $diagramId, 'message' => 'Embedded diagram saved successfully'];
	}

	/** @return array<string, mixed> api_save_excalidraw.php ohne action (Vollmodus, nur bestehende Notiz) */
	public function saveFull(string $uid, mixed $rawNoteId, mixed $wsKey, mixed $data, mixed $svg): array {
		if (!is_string($rawNoteId) || !ctype_digit($rawNoteId) || (int)$rawNoteId <= 0) {
			throw new BadRequestException('note_id is required');
		}
		$id = $this->writable($uid, $rawNoteId, $wsKey);
		$json = self::diagramJson($data);
		$preview = $this->preview($svg);
		$this->write($uid, $id, $preview, 'excalidraw_preview.svg', function (string $content, string $type, ?int $aid) use ($id, $json): array {
			return $this->placeFull($content, $id, $json, $aid);
		});
		return ['note_id' => $id, 'message' => 'Diagram saved successfully'];
	}

	// ---------- Rechte, Eingaben ----------

	private function note(string $uid, mixed $rawNoteId, mixed $wsKey, string $perm): Note {
		if (!is_string($rawNoteId) || !ctype_digit($rawNoteId)) {
			throw new NotFoundException('Note not found');
		}
		$note = $this->access->requireNote($uid, (int)$rawNoteId, $perm);
		$ws = $this->workspaces->resolveOptional($uid, $wsKey);
		if ($ws !== null && $ws->getId() !== $note->getWorkspaceId()) {
			throw new NotFoundException('Note not found');
		}
		return $note;
	}

	// Schreibrecht (Lesefreigabe → 403), keine Verknüpfung, keine fremde Sperre (ADR 0005, Einmal-Schreiber ohne Sitzung)
	private function writable(string $uid, string $rawNoteId, mixed $wsKey): int {
		$note = $this->note($uid, $rawNoteId, $wsKey, 'write');
		if ($note->getLinkedNoteId() !== null) {
			throw new BadRequestException('A shortcut cannot hold a diagram');
		}
		$this->locks->assertCanSave($uid, (int)$note->getId(), '');
		return (int)$note->getId();
	}

	private static function diagramJson(mixed $data): string {
		if (!is_string($data) || $data === '') {
			throw new BadRequestException('diagram_data is required');
		}
		if (strlen($data) > self::MAX_DATA_BYTES) {
			throw new TooLargeException('Diagram too large', ['max_mb' => self::MAX_DATA_BYTES >> 20]);
		}
		$decoded = json_decode($data, true);
		if (!is_array($decoded) || array_is_list($decoded) && $decoded !== []) {
			throw new BadRequestException('Invalid diagram data');
		}
		return $data;
	}

	// Upstream excalidrawReadPreviewSvg: Theme heraus, nur Excalidraw-SVG ohne Skript/Handler/fremde Verweise, Fotos im Dunkelmodus
	private function preview(mixed $svg): ?string {
		if (!is_string($svg) || trim($svg) === '') {
			return null;
		}
		if (strlen($svg) > self::MAX_PREVIEW_BYTES) {
			throw new TooLargeException('Preview too large', ['max_mb' => self::MAX_PREVIEW_BYTES >> 20]);
		}
		$svg = Pcre::replace('/\s++filter="invert\([^"]*+"/i', '', $svg);
		$svg = Pcre::replace('/<rect x="0" y="0" width="[^"]*+" height="[^"]*+" fill="[^"]*+"\s*+\/>/i', '', $svg, 1);
		if (!self::acceptableSvg($svg)) {
			throw new BadRequestException('Invalid image type');
		}
		if (stripos($svg, '<image') !== false && !str_contains($svg, 'poznote-photo-scheme')) {
			$style = '<style class="poznote-photo-scheme">@media (prefers-color-scheme: dark){'
				. 'image:not([href^="data:image/svg"]){filter:invert(100%) hue-rotate(180deg) saturate(1.25)}}</style>';
			$svg = Pcre::replace('/<svg\b[^>]*+>/i', '$0' . $style, $svg, 1);
		}
		return $svg;
	}

	private static function acceptableSvg(string $svg): bool {
		// Wurzelelement svg (die Upstream-Prüfung per finfo meldet kurze SVG in NC als text/plain); ausgeliefert als image/svg+xml, nie inline (B22)
		if (!Pcre::match('/^\s*+(?:<\?xml[^>]*+>\s*+)?<svg\b/i', $svg)) {
			return false;
		}
		$markup = Pcre::replace('/;base64,[A-Za-z0-9+\/]++=*+/', ';base64,', $svg);
		foreach ([
			'/<\s*(script|foreignObject|iframe|embed|object)\b/i',
			'/<(?:[^>\s"\']++|"[^"]*+"|\'[^\']*+\'|\s(?!on[a-z]+\s*=))*+\son[a-z]+\s*=/i',
			'/\b(?:xlink:)?href\s*=\s*["\'](?!data:image\/|#)/i',
		] as $pattern) {
			if (Pcre::match($pattern, $markup)) {
				return false;
			}
		}
		return true;
	}

	// ---------- Speichern ----------

	/**
	 * Vorschau als Anhang, Inhalt unter Zeilensperre umschreiben, ersetzte Vorschauen entfernen (außer ein Snapshot zeigt sie)
	 * @param callable(string, string, ?int): array{0: string, 1: int[]} $place neuer Inhalt, ersetzte Anhang-IDs
	 */
	private function write(string $uid, int $id, ?string $preview, string $previewName, callable $place): void {
		$note = $this->notes->find($id);
		$quota = $preview !== null ? $this->attachments->quotaFor((int)$note->getWorkspaceId(), strlen($preview)) : null;
		// neue Vorschau-Datei entfernt Transaction::onRollback (createFromString)
		$this->tx->run(function () use ($uid, $id, $preview, $previewName, $place, $quota) {
			$this->attachments->enforceQuota($quota);
			$this->notes->lockRow($id);
			$note = $this->notes->find($id);
			$created = $preview !== null ? $this->attachments->createFromString($id, $previewName, 'image/svg+xml', $preview, $uid) : null;
			[$content, $replaced] = $place((string)($note->getContent() ?? ''), (string)($note->getType() ?: 'note'), $created !== null ? (int)$created->getId() : null);
			// Revision des alten Stands zuerst, damit eine dort sichtbare Vorschau erhalten bleibt
			$this->snapshots->autoBefore($note, $uid);
			foreach (array_unique($replaced) as $old) {
				if (!self::references($content, $id, $old) && !$this->snapshotMapper->contains($id, '/notes/' . $id . '/attachments/' . $old . '"')) {
					$this->attachments->retire($id, $old);
				}
			}
			$this->noteService->saveContent($note, $content, $uid);
		});
	}

	private static function references(string $content, int $noteId, int $attachmentId): bool {
		return Pcre::match('#/notes/' . $noteId . '/attachments/' . $attachmentId . '(?![0-9])#', $content);
	}

	/** @return array{0: string, 1: int[]} */
	private function placeEmbedded(string $content, bool $markdown, int $noteId, string $diagramId, string $json, ?int $aid, ?int $cursor): array {
		$span = self::firstSpan($content, $diagramId, static fn (string $tag, array $attrs): bool => ($attrs['id'] ?? null) === $diagramId
			&& ($tag === 'button' || self::hasClass($attrs, 'excalidraw-container')));
		$old = $span !== null ? substr($content, $span['start'], $span['end'] - $span['start']) : '';
		[$imgClass, $imgStyle] = self::imageLook($old);
		$margins = '';
		foreach (['margin-left', 'margin-right'] as $prop) {
			if (Pcre::match('/(?:^|;)\s*+' . $prop . '\s*+:\s*+(auto|0(?:px)?)\s*+(?:;|$)/i', $span['attrs']['style'] ?? '', $m)) {
				$margins .= ' ' . $prop . ': ' . strtolower($m[1]) . ';';
			}
		}
		$html = '<div class="excalidraw-container" id="' . self::h($diagramId) . '" style="cursor: pointer; text-align: center;' . $margins
			. '" data-diagram-id="' . self::h($diagramId) . '" data-excalidraw="' . self::h($json) . '">';
		$html .= $aid !== null
			? '<img src="/api/v1/notes/' . $noteId . '/attachments/' . $aid . '" class="' . self::h($imgClass) . '" data-is-excalidraw="true" style="' . self::h($imgStyle) . '" alt="Excalidraw diagram" />'
			: '<i class="lucide lucide-draw-polygon" style="font-size: 48px; color: #666; margin-bottom: 10px;"></i><p style="color: #666; font-size: 16px; margin: 0;">Excalidraw diagram</p>';
		$html .= '</div>';
		if ($span !== null) {
			// an Ort und Stelle; Upstream setzte in Markdown je Speichern neue Leerzeilen davor und danach (B36)
			return [substr($content, 0, $span['start']) . $html . substr($content, $span['end']), self::attachmentIds($old, $noteId)];
		}
		$block = "\n\n" . $html . "\n\n";
		if ($markdown) {
			if ($content === '') {
				return [trim($block) . "\n", []];
			}
			$length = mb_strlen($content, 'UTF-8');
			if ($cursor !== null && $cursor <= $length) {
				return [mb_substr($content, 0, $cursor, 'UTF-8') . $block . mb_substr($content, $cursor, null, 'UTF-8'), []];
			}
			return [$content . $block, []];
		}
		// Leere Absätze davor und danach, damit der Cursor über und unter das Diagramm kommt
		$block = '<p class="excalidraw-placeholder"></p>' . $html . '<p class="excalidraw-placeholder"></p>';
		if ($cursor !== null && $content !== '' && $cursor <= self::textLength($content)) {
			$at = self::htmlOffset($content, $cursor);
			return [substr($content, 0, $at) . $block . substr($content, $at), []];
		}
		return [$content . $block, []];
	}

	/** @return array{0: string, 1: int[]} Vollmodus: ersten Container ersetzen, weitere (Altkopien, Upstream #1567) entfernen */
	private function placeFull(string $content, int $noteId, string $json, ?int $aid): array {
		// Container mit div.excalidraw-data; Fundstellen einmal sammeln statt je Container den Teilstring zu kopieren
		$data = [];
		for ($p = strpos($content, 'class="excalidraw-data"'); $p !== false; $p = strpos($content, 'class="excalidraw-data"', $p + 1)) {
			$data[] = $p;
		}
		$spans = [];
		self::eachSpan($content, 'excalidraw-container', static function (string $tag, string $raw, int $start, int $openEnd, int $closeStart, int $end) use ($data, &$spans): void {
			if (self::hasClass(self::attrs($raw), 'excalidraw-container') && self::anyIn($data, $start, $end)) {
				$spans[] = ['start' => $start, 'end' => $end];
			}
		});
		usort($spans, static fn (array $a, array $b) => $a['start'] <=> $b['start']);
		$first = $spans !== [] ? substr($content, $spans[0]['start'], $spans[0]['end'] - $spans[0]['start']) : '';
		[$imgClass] = self::imageLook($first);
		$html = '<div class="excalidraw-container" contenteditable="false">';
		$html .= $aid !== null
			? '<img src="/api/v1/notes/' . $noteId . '/attachments/' . $aid . '" alt="Excalidraw diagram" class="' . self::h($imgClass) . '" data-is-excalidraw="true" data-excalidraw-note-id="' . $noteId . '" />'
			: '<p style="text-align:center; padding: 40px; color: #999;">Excalidraw diagram</p>';
		$html .= '<div class="excalidraw-data" style="display: none;">' . self::h($json) . '</div></div>';
		if ($spans === []) {
			return [$content . $html, []];
		}
		$out = '';
		$pos = 0;
		$replaced = [];
		foreach ($spans as $i => $s) {
			$out .= substr($content, $pos, $s['start'] - $pos) . ($i === 0 ? $html : '');
			$replaced = array_merge($replaced, self::attachmentIds(substr($content, $s['start'], $s['end'] - $s['start']), $noteId));
			$pos = $s['end'];
		}
		return [$out . substr($content, $pos), $replaced];
	}

	/** @return array{0: string, 1: string} Klassen (Rahmen bleibt) und Stil des bisherigen Vorschaubilds (B36: Grundstil nicht doppelt) */
	private static function imageLook(string $old): array {
		$class = 'excalidraw-image excalidraw-image-neutral';
		$decls = self::BASE_IMG_STYLE;
		if ($old !== '' && Pcre::match('/<img\b((?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+)>/i', $old, $m)) {
			$attrs = self::attrs($m[1]);
			$classes = Pcre::split('/\s+/', $attrs['class'] ?? '');
			if (in_array('img-with-border-no-padding', $classes, true)) {
				$class .= ' img-with-border-no-padding';
			} elseif (in_array('img-with-border', $classes, true)) {
				$class .= ' img-with-border';
			}
			foreach (explode(';', $attrs['style'] ?? '') as $decl) {
				$parts = explode(':', $decl, 2);
				if (count($parts) === 2 && trim($parts[0]) !== '' && trim($parts[1]) !== '') {
					$decls[strtolower(trim($parts[0]))] = trim($parts[1]);
				}
			}
		}
		$style = implode(' ', array_map(static fn (string $k, string $v) => $k . ': ' . $v . ';', array_keys($decls), $decls));
		return [$class, $style];
	}

	/** @return int[] Anhang-IDs der Notiz, auf die das Markup verweist */
	private static function attachmentIds(string $markup, int $noteId): array {
		Pcre::matchAll('#/notes/' . $noteId . '/attachments/(\d++)(?![0-9])#', $markup, $m);
		return array_map('intval', $m[1]);
	}

	// ---------- Lesen ----------

	private function embeddedData(string $content, string $diagramId): ?string {
		$span = self::firstSpan($content, $diagramId, static fn (string $tag, array $attrs): bool => ($attrs['id'] ?? null) === $diagramId
			&& self::hasClass($attrs, 'excalidraw-container'));
		return self::validJson($span['attrs']['data-excalidraw'] ?? null);
	}

	// Vollmodus: JSON aus div.excalidraw-data, sonst Notiz, die nur aus dem JSON besteht (Typ excalidraw, Altbestand)
	private function fullData(string $content): ?string {
		$s = self::firstSpan($content, 'excalidraw-data', static fn (string $tag, array $attrs): bool => self::hasClass($attrs, 'excalidraw-data'));
		if ($s !== null) {
			return self::validJson(html_entity_decode(substr($content, $s['openEnd'], $s['closeStart'] - $s['openEnd']), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
		}
		return self::validJson($content);
	}

	private static function validJson(?string $json): ?string {
		if ($json === null || trim($json) === '' || trim($json) === '{}') {
			return null;
		}
		return is_array(json_decode($json, true)) ? $json : null;
	}

	// ---------- Markup ----------

	/**
	 * Erstes div/button (nach Startposition), dessen Start-Tag $needle enthält und $accept erfüllt
	 * @param callable(string, array<string, string>): bool $accept
	 * @return array{tag: string, attrs: array<string, string>, start: int, openEnd: int, closeStart: int, end: int}|null
	 */
	private static function firstSpan(string $html, string $needle, callable $accept): ?array {
		$best = null;
		self::eachSpan($html, $needle, static function (string $tag, string $raw, int $start, int $openEnd, int $closeStart, int $end) use ($accept, &$best): void {
			if ($best !== null && $best['start'] < $start) {
				return;
			}
			$attrs = self::attrs($raw);
			if ($accept($tag, $attrs)) {
				$best = ['tag' => $tag, 'attrs' => $attrs, 'start' => $start, 'openEnd' => $openEnd, 'closeStart' => $closeStart, 'end' => $end];
			}
		});
		return $best;
	}

	/**
	 * Paart div-/button-Tags (Schließreihenfolge); $visit nur für Start-Tags mit $needle. Linear, Speicher ein int je offenem Tag (B39)
	 * @param callable(string, string, int, int, int, int): void $visit Tag, Attribut-Rohtext, start, openEnd, closeStart, end
	 */
	private static function eachSpan(string $html, string $needle, callable $visit): void {
		if (stripos($html, '<div') === false && stripos($html, '<button') === false) {
			return;
		}
		// Stapel: start << 1 | button (ein int je offenem Tag); Start-Tag wird beim Paaren neu gelesen
		$stack = [];
		$offset = 0;
		while (Pcre::match(self::TAG, $html, $t, PREG_OFFSET_CAPTURE, $offset)) {
			$start = $t[0][1];
			$offset = $start + strlen($t[0][0]);
			$button = strtolower($t[2][0]) === 'button' ? 1 : 0;
			if ($t[1][0] === '') {
				$stack[] = $start << 1 | $button;
				continue;
			}
			while ($stack !== []) {
				$open = array_pop($stack);
				if (($open & 1) === $button) {
					$openStart = $open >> 1;
					Pcre::match(self::TAG_AT, $html, $o, 0, $openStart);
					if (stripos($o[0], $needle) !== false) {
						Pcre::match(self::TAG, $o[0], $parts);
						$visit($button === 1 ? 'button' : 'div', $parts[3], $openStart, $openStart + strlen($o[0]), $start, $offset);
					}
					break;
				}
			}
		}
	}

	/** @return array<string, string> Attribute eines Start-Tags (Werte entschlüsselt, Namen klein) */
	private static function attrs(string $raw): array {
		$out = [];
		Pcre::matchAll(self::ATTR, $raw, $m, PREG_SET_ORDER);
		foreach ($m as $a) {
			$name = strtolower($a[1]);
			if (!isset($out[$name])) {
				$value = ($a[2] ?? '') !== '' ? $a[2] : (($a[3] ?? '') !== '' ? $a[3] : ($a[4] ?? ''));
				$out[$name] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
			}
		}
		return $out;
	}

	/** @param list<int> $sorted aufsteigend; true, wenn ein Wert in [$from, $to) liegt */
	private static function anyIn(array $sorted, int $from, int $to): bool {
		$lo = 0;
		$hi = count($sorted);
		while ($lo < $hi) {
			$mid = ($lo + $hi) >> 1;
			if ($sorted[$mid] < $from) {
				$lo = $mid + 1;
			} else {
				$hi = $mid;
			}
		}
		return $lo < count($sorted) && $sorted[$lo] < $to;
	}

	/** @param array<string, string> $attrs */
	private static function hasClass(array $attrs, string $class): bool {
		return in_array($class, Pcre::split('/\s+/', $attrs['class'] ?? ''), true);
	}

	private static function h(string $s): string {
		return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}

	// Textlänge wie die DOM-Auswahl im Client (Upstream api_save_excalidraw.php: br und Blockenden = Zeilenumbruch)
	private static function textLength(string $html): int {
		$text = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$text = Pcre::replace('/<br\s*+\/?\s*+>/i', "\n", $text);
		$text = Pcre::replace('/<\/(p|div|li|h[1-6])\s*+>/i', "\n", $text);
		return mb_strlen(strip_tags($text), 'UTF-8');
	}

	// Upstream poznoteHtmlOffsetForTextPosition: Byte-Position im HTML zur Textposition
	private static function htmlOffset(string $html, int $textOffset): int {
		$length = strlen($html);
		$position = 0;
		$text = 0;
		while ($position < $length && $text < $textOffset) {
			$byte = $html[$position];
			if ($byte === '<') {
				// ganzes Tag überspringen, auch mit „>“ in Attributwerten (libxml schreibt JSON in einfache Anführungszeichen)
				if (!Pcre::match(self::TAG_AT, $html, $m, 0, $position)) {
					break;
				}
				if (Pcre::match('/^\s*+(?:br\b|\/(?:p|div|li|h[1-6])\b)/i', substr($html, $position + 1, 16))) {
					$text++;
				}
				$position += strlen($m[0]);
				continue;
			}
			if ($byte === '&') {
				$semicolon = strpos(substr($html, $position, 34), ';');
				if ($semicolon !== false) {
					$entity = substr($html, $position, $semicolon + 1);
					$decoded = html_entity_decode($entity, ENT_QUOTES | ENT_HTML5, 'UTF-8');
					if ($decoded !== $entity) {
						$text += mb_strlen($decoded, 'UTF-8');
						$position += $semicolon + 1;
						continue;
					}
				}
			}
			$position++;
			while ($position < $length && (ord($html[$position]) & 0xC0) === 0x80) {
				$position++;
			}
			$text++;
		}
		return $position;
	}
}
