<?php

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel, Timothé Poznanski (Upstream, MIT)
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service\Convert;

// Port von src/html_to_markdown.php (M4b, SPEC §1.2.5; Basis-Hash in build/upstream-ported.txt): HTML → Markdown
// Rohfassung per build/port-convert.php, Änderungen von Hand nur mit Kommentar „B46“. Ohne strict_types wie Upstream.
final class HtmlToMarkdown {
	use \OCA\NextNotes\Service\PcreCalls;

	// ---- B46 (Hand): closed*-Aufrufe statt preg_* bei Mustern <öffner>(.*?)<schließer> ----
	// Ein Öffner hinter dem letzten Schließer findet nie ein Ende; Upstream suchte von jedem bis zum Textende (quadratisch).
	// Ersetzt wird nur der Teil bis zum Ende des letzten Schließers, der Rest bleibt; Ergebnis gleich Upstream.

	// Arbeitsgrenze (Zeichenschritte) für Muster mit mehreren .*?: darüber 413 statt minutenlanger Suche
	private const SCAN_BUDGET = 64 * 1048576;

	/**
	 * Ende des letzten Schließers; zwei Einträge = Folge (erster, Leerraum, zweiter), z. B. </code>\s*</pre>
	 * @param string[] $closers
	 */
	private static function closedCut(string $text, array $closers): int {
	    if (count($closers) === 2 && $closers[0] === '</code>') {
	        $pos = strlen($text);
	        while ($pos > 0 && ($pos = strripos($text, $closers[1], $pos - strlen($text) - 1)) !== false) {
	            $before = rtrim(substr($text, max(0, $pos - 4096), min($pos, 4096)), " \t\n\r\x0B\x0C");
	            if (strcasecmp(substr($before, -strlen($closers[0])), $closers[0]) === 0) {
	                return $pos + strlen($closers[1]);
	            }
	        }
	        return -1;
	    }
	    $cut = -1;
	    foreach ($closers as $closer) {
	        $pos = strripos($text, $closer);
	        if ($pos !== false) {
	            $cut = max($cut, $pos + strlen($closer));
	        }
	    }
	    return $cut;
	}

	// Muster mit mehreren .*? (details, callout): Öffner hinter dem letzten Zwischenteil suchen erfolglos bis zum Ende
	private static function scanGuard(string $text, string $opener, string $lastPart): void {
	    $lower = strtolower($text);
	    $last = strrpos($lower, $lastPart);
	    $failing = substr_count($last === false ? $lower : substr($lower, $last), $opener);
	    if ($failing * strlen($text) > self::SCAN_BUDGET) {
	        throw new \OCA\NextNotes\Service\Exception\TooLargeException('Content too complex to convert');
	    }
	}

	// <(ul|ol)>…</\1>: nur Öffner hinter dem letzten Schließer ihres Typs suchen erfolglos bis zum Ende
	private static function listGuard(string $text): void {
	    $lower = strtolower($text);
	    $failing = 0;
	    foreach (['ul', 'ol'] as $tag) {
	        $last = strrpos($lower, '</' . $tag . '>');
	        $failing += substr_count($last === false ? $lower : substr($lower, $last), '<' . $tag);
	    }
	    if ($failing * strlen($text) > self::SCAN_BUDGET) {
	        throw new \OCA\NextNotes\Service\Exception\TooLargeException('Content too complex to convert');
	    }
	}

	/** @param string[] $closers */
	private static function closedReplace(array $closers, string $pattern, string $replacement, mixed $subject): string {
	    $subject = (string)$subject;
	    $cut = self::closedCut($subject, $closers);
	    return $cut < 0 ? $subject : self::pregReplace($pattern, $replacement, substr($subject, 0, $cut)) . substr($subject, $cut);
	}

	/** @param string[] $closers */
	private static function closedReplaceCallback(array $closers, string $pattern, callable $callback, mixed $subject): string {
	    $subject = (string)$subject;
	    $cut = self::closedCut($subject, $closers);
	    return $cut < 0 ? $subject : self::pregReplaceCallback($pattern, $callback, substr($subject, 0, $cut)) . substr($subject, $cut);
	}

	/** @param string[] $closers */
	private static function closedMatchAll(array $closers, string $pattern, mixed $subject, mixed &$matches = null): int {
	    $subject = (string)$subject;
	    $cut = self::closedCut($subject, $closers);
	    return self::pregMatchAll($pattern, $cut < 0 ? '' : substr($subject, 0, $cut), $matches);
	}

	/**
	 * HTML to Markdown converter, shared by the REST API (whole-note conversion
	 * and the "Paste as Markdown" modal) and the AI assistant, which reads
	 * rich-text notes through it so that a rewrite keeps the note's structure.
	 *
	 * Handles: headers, bold, italic, strikethrough, links, images, line breaks,
	 * paragraphs, lists (ordered/unordered/checklists), code blocks, inline code,
	 * blockquotes, callouts, toggle blocks, tables, horizontal rules, audio/video.
	 */

	/**
	 * Markdown source cell for a rich-text formula cell (data-formula value),
	 * the form markdown_parser.php reads back
	 */
	public static function poznoteHtmlToMarkdownFormulaToken($formula) {
	    $names = ['sum' => 'SUM', 'avg' => 'AVG', 'min' => 'MIN', 'max' => 'MAX', 'count' => 'COUNT', 'prod' => 'PRODUCT'];
	    $parts = explode('-', (string)$formula);
	    if (count($parts) !== 2 || !isset($names[$parts[0]]) || !in_array($parts[1], ['col', 'row'], true)) {
	        return '';
	    }
	    return '=' . $names[$parts[0]] . '(' . $parts[1] . ')';
	}

	/**
	 * Inline tags kept when reducing a list item to text. List rules run before
	 * the inline rules, so these have to survive that step to become **bold**,
	 * *italic*, `code` and links; the whitelist matches the one the inline rules
	 * use among themselves.
	 */
	public const POZNOTE_HTML_TO_MD_LIST_ITEM_INLINE_TAGS = '<strong><b><em><i><code><a><del><s><strike><u><mark><span>';

	public static function poznoteHtmlToMarkdown(string $html): string {
	    $md = $html;
	    
	    // Remove copy buttons from code blocks first
	    $md = self::closedReplace(['</button>'], '/<button[^>]*class="[^"]*code-block-copy-btn[^"]*"[^>]*>.*?<\/button>/is', '', $md);
	    
	    // Remove SVG icons (callout icons, etc.)
	    $md = self::closedReplace(['</svg>'], '/<svg[^>]*>.*?<\/svg>/is', '', $md);
	    
	    // ---- Code blocks (must be processed FIRST to protect content) ----
	    
	    // Handle <pre><code class="language-xxx">...</code></pre>
	    $md = self::closedReplaceCallback(['</code>', '</pre>'], '/<pre[^>]*>\s*<code[^>]*(?:class=["\'][^"\']*language-([a-zA-Z0-9_+-]+)[^"\']*["\'])[^>]*>(.*?)<\/code>\s*<\/pre>/is', function($matches) {
	        $lang = $matches[1];
	        $code = html_entity_decode($matches[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
	        $code = strip_tags($code);
	        return "\n\n```" . $lang . "\n" . trim($code) . "\n```\n\n";
	    }, $md);
	    
	    // Handle <pre><code>...</code></pre> without language
	    $md = self::closedReplaceCallback(['</code>', '</pre>'], '/<pre[^>]*>\s*<code[^>]*>(.*?)<\/code>\s*<\/pre>/is', function($matches) {
	        $code = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
	        $code = strip_tags($code);
	        return "\n\n```\n" . trim($code) . "\n```\n\n";
	    }, $md);
	    
	    // Handle <pre>...</pre> without <code> tag
	    $md = self::closedReplaceCallback(['</pre>'], '/<pre[^>]*>(.*?)<\/pre>/is', function($matches) {
	        $code = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
	        $code = strip_tags($code);
	        return "\n\n```\n" . trim($code) . "\n```\n\n";
	    }, $md);
	    
	    // ---- Toggle blocks (<details class="toggle-block">) ----
	    self::scanGuard($md, '<details', '</summary>');
	    $md = self::closedReplaceCallback(['</details>'], '/<details[^>]*class="[^"]*toggle-block[^"]*"[^>]*>\s*<summary[^>]*>(.*?)<\/summary>\s*(?:<div[^>]*class="[^"]*toggle-content[^"]*"[^>]*>(.*?)<\/div>)?\s*<\/details>/is', function($matches) {
	        $summary = strip_tags(trim($matches[1]));
	        $content = isset($matches[2]) ? trim($matches[2]) : '';
	        $innerMd = $content ? self::poznoteHtmlToMarkdown($content) : '';
	        return "\n\n<details>\n<summary>" . $summary . "</summary>\n\n" . $innerMd . "\n\n</details>\n\n";
	    }, $md);
	    
	    // Handle generic <details>/<summary>
	    self::scanGuard($md, '<details', '</summary>');
	    $md = self::closedReplaceCallback(['</details>'], '/<details[^>]*>\s*<summary[^>]*>(.*?)<\/summary>(.*?)<\/details>/is', function($matches) {
	        $summary = strip_tags(trim($matches[1]));
	        $content = trim($matches[2]);
	        $innerMd = $content ? self::poznoteHtmlToMarkdown($content) : '';
	        return "\n\n<details>\n<summary>" . $summary . "</summary>\n\n" . $innerMd . "\n\n</details>\n\n";
	    }, $md);
	    
	    // ---- Callouts (<aside class="callout callout-xxx">) ----
	    self::scanGuard($md, '<aside', 'callout-body');
	    $md = self::closedReplaceCallback(['</aside>'], '/<aside[^>]*class="[^"]*callout\s+callout-([a-z]+)[^"]*"[^>]*>\s*<div[^>]*class="[^"]*callout-title[^"]*"[^>]*>.*?<span[^>]*class="[^"]*callout-title-text[^"]*"[^>]*>(.*?)<\/span>\s*<\/div>\s*<div[^>]*class="[^"]*callout-body[^"]*"[^>]*>(.*?)<\/div>\s*<\/aside>/is', function($matches) {
	        $type = $matches[1];
	        $title = strip_tags(trim($matches[2]));
	        $body = trim($matches[3]);
	        $innerMd = $body ? self::poznoteHtmlToMarkdown($body) : '';
	        $lines = "> [!" . strtoupper($type) . "] " . $title . "\n";
	        foreach (explode("\n", $innerMd) as $line) {
	            $lines .= "> " . $line . "\n";
	        }
	        return "\n\n" . rtrim($lines) . "\n\n";
	    }, $md);
	    
	    // ---- Tables ----
	    $md = self::closedReplaceCallback(['</table>'], '/<table[^>]*>(.*?)<\/table>/is', function($matches) {
	        $tableHtml = $matches[1];
	        $rows = [];
	        self::closedMatchAll(['</tr>'], '/<tr[^>]*>(.*?)<\/tr>/is', $tableHtml, $rowMatches);
	        foreach ($rowMatches[1] as $rowHtml) {
	            $cells = [];
	            self::closedMatchAll(['</th>', '</td>'], '/<(?:th|td)([^>]*)>(.*?)<\/(?:th|td)>/is', $rowHtml, $cellMatches);
	            foreach ($cellMatches[2] as $cellIndex => $cellHtml) {
	                // A formula cell goes back to its Markdown form, =SUM(col)
	                $formulaToken = self::pregMatch('/\bdata-formula=["\']([a-z-]+)["\']/i', $cellMatches[1][$cellIndex], $formulaMatch)
	                    ? self::poznoteHtmlToMarkdownFormulaToken(strtolower($formulaMatch[1]))
	                    : '';
	                $cells[] = $formulaToken !== '' ? $formulaToken : trim(strip_tags($cellHtml));
	            }
	            if (!empty($cells)) $rows[] = $cells;
	        }
	        if (empty($rows)) return '';
	        $result = "\n\n";
	        $result .= "| " . implode(" | ", $rows[0]) . " |\n";
	        $result .= "| " . implode(" | ", array_fill(0, count($rows[0]), '---')) . " |\n";
	        for ($i = 1; $i < count($rows); $i++) {
	            while (count($rows[$i]) < count($rows[0])) $rows[$i][] = '';
	            $result .= "| " . implode(" | ", $rows[$i]) . " |\n";
	        }
	        return $result . "\n";
	    }, $md);
	    
	    // ---- Checklists / Task lists ----
	    self::listGuard($md);
	    // Consume the whole <ul>/<ol> so the generic list handlers below cannot
	    // re-scan the leftover wrapper and drop the already-converted items.
	    $md = self::closedReplaceCallback(['</ul>', '</ol>'], '/<(ul|ol)[^>]*>(.*?)<\/\1>/is', function($matches) {
	        $listHtml = $matches[2];

	        // Only treat this list as a checklist if it actually contains checkboxes
	        if (!self::pregMatch('/<input[^>]*type=["\']checkbox["\']/i', $listHtml)) {
	            return $matches[0];
	        }

	        self::closedMatchAll(['</li>'], '/<li[^>]*>(.*?)<\/li>/is', $listHtml, $liMatches);
	        $items = [];
	        foreach ($liMatches[1] as $liContent) {
	            if (!self::pregMatch('/<input[^>]*type=["\']checkbox["\'][^>]*>/i', $liContent, $inputMatch)) {
	                // Plain item inside a checklist: keep it as a normal bullet.
	                // Inline tags survive so the rules further down turn them
	                // into **bold**, *italic*, `code` and links; stripping them
	                // here would flatten the item to plain text.
	                $text = trim(strip_tags($liContent, self::POZNOTE_HTML_TO_MD_LIST_ITEM_INLINE_TAGS));
	                if ($text !== '') $items[] = "- " . $text;
	                continue;
	            }

	            // Search the input tag itself, otherwise [^>]* swallows "checked".
	            // Match only a real attribute: "checklist-checkbox" as a class value
	            // must not count, and data-checked="0" means unchecked.
	            $inputTag = $inputMatch[0];
	            if (self::pregMatch('/\sdata-checked=["\']?([01])/i', $inputTag, $dataChecked)) {
	                $checked = $dataChecked[1] === '1';
	            } else {
	                $checked = (bool)self::pregMatch('/\schecked(?=[\s>=\/])/i', $inputTag);
	            }

	            $text = trim(strip_tags(str_replace($inputMatch[0], '', $liContent), self::POZNOTE_HTML_TO_MD_LIST_ITEM_INLINE_TAGS));
	            $text = str_replace("\xE2\x80\x8B", '', $text); // zero-width space in empty items
	            $items[] = ($checked ? "- [x] " : "- [ ] ") . $text;
	        }

	        if (empty($items)) return $matches[0];
	        return "\n\n" . implode("\n", $items) . "\n\n";
	    }, $md);

	    // ---- Ordered lists ----
	    $md = self::closedReplaceCallback(['</ol>'], '/<ol[^>]*>(.*?)<\/ol>/is', function($matches) {
	        $items = [];
	        self::closedMatchAll(['</li>'], '/<li[^>]*>(.*?)<\/li>/is', $matches[1], $liMatches);
	        $i = 1;
	        foreach ($liMatches[1] as $liContent) {
	            $items[] = $i . ". " . strip_tags(trim($liContent), self::POZNOTE_HTML_TO_MD_LIST_ITEM_INLINE_TAGS);
	            $i++;
	        }
	        return "\n\n" . implode("\n", $items) . "\n\n";
	    }, $md);
	    
	    // ---- Unordered lists ----
	    $md = self::closedReplaceCallback(['</ul>'], '/<ul[^>]*>(.*?)<\/ul>/is', function($matches) {
	        $items = [];
	        self::closedMatchAll(['</li>'], '/<li[^>]*>(.*?)<\/li>/is', $matches[1], $liMatches);
	        foreach ($liMatches[1] as $liContent) {
	            $text = strip_tags(trim($liContent), self::POZNOTE_HTML_TO_MD_LIST_ITEM_INLINE_TAGS);
	            if (!empty($text)) $items[] = "- " . $text;
	        }
	        return "\n\n" . implode("\n", $items) . "\n\n";
	    }, $md);
	    
	    // ---- Orphan checklist items ----
	    // Pasted fragments often carry <li> items with no enclosing list, or a
	    // wrapper that is never closed; the handlers above all require a closed
	    // <ul>/<ol> pair, so without this pass the checkbox state would be lost.
	    $md = self::closedReplaceCallback(['</li>'], '/<li[^>]*>(.*?)<\/li>/is', function($matches) {
	        $liContent = $matches[1];
	        if (!self::pregMatch('/<input[^>]*type=["\']checkbox["\'][^>]*>/i', $liContent, $inputMatch)) {
	            return $matches[0];
	        }
	        $inputTag = $inputMatch[0];
	        if (self::pregMatch('/\sdata-checked=["\']?([01])/i', $inputTag, $dataChecked)) {
	            $checked = $dataChecked[1] === '1';
	        } else {
	            $checked = (bool)self::pregMatch('/\schecked(?=[\s>=\/])/i', $inputTag);
	        }
	        $text = trim(strip_tags(str_replace($inputTag, '', $liContent), self::POZNOTE_HTML_TO_MD_LIST_ITEM_INLINE_TAGS));
	        $text = str_replace("\xE2\x80\x8B", '', $text);
	        return "\n" . ($checked ? "- [x] " : "- [ ] ") . $text . "\n";
	    }, $md);

	    // ---- Headers ----
	    $md = self::closedReplace(['</h1>'], '/<h1[^>]*>(.*?)<\/h1>/is', "\n\n# $1\n\n", $md);
	    $md = self::closedReplace(['</h2>'], '/<h2[^>]*>(.*?)<\/h2>/is', "\n\n## $1\n\n", $md);
	    $md = self::closedReplace(['</h3>'], '/<h3[^>]*>(.*?)<\/h3>/is', "\n\n### $1\n\n", $md);
	    $md = self::closedReplace(['</h4>'], '/<h4[^>]*>(.*?)<\/h4>/is', "\n\n#### $1\n\n", $md);
	    $md = self::closedReplace(['</h5>'], '/<h5[^>]*>(.*?)<\/h5>/is', "\n\n##### $1\n\n", $md);
	    $md = self::closedReplace(['</h6>'], '/<h6[^>]*>(.*?)<\/h6>/is', "\n\n###### $1\n\n", $md);
	    
	    // ---- Structural elements (Paragraphs, Divs, Line breaks) ----
	    // Doing this before inline formatting prevents bold/italic from wrapping across blocks
	    
	    // Horizontal rule
	    $md = self::pregReplace('/<hr\s*\/?>/i', "\n\n---\n\n", $md);
	    
	    // Aligned paragraphs (text-align on a <p> or <div> holding no other block)
	    // become <p align="…"> lines, the syntax the Markdown parsers read back.
	    // Markers keep the text in the stream for the inline rules below; they are
	    // turned into tags at the end, one per line.
	    $md = self::closedReplaceCallback(['</p>', '</div>'], '/<(p|div)\b([^>]*)>((?:(?!<\/?(?:p|div)\b).)*)<\/\1>/is', function($matches) {
	        if (!self::pregMatch('/\bstyle\s*=\s*(["\'])[^"\']*\btext-align\s*:\s*(center|right|justify)\b[^"\']*\1/i', $matches[2], $align)) {
	            return $matches[0];
	        }
	        return "\n\n\x01ALIGN:" . strtolower($align[2]) . "\x01" . $matches[3] . "\x01/ALIGN\x01\n\n";
	    }, $md);

	    // Line breaks
	    $md = self::pregReplace('/<br\s*\/?>/i', "\n", $md);
	    
	    // Paragraphs
	    $md = self::closedReplace(['</p>'], '/<p[^>]*>(.*?)<\/p>/is', "$1\n\n", $md);
	    
	    // Divs
	    $md = self::closedReplace(['</div>'], '/<div[^>]*>(.*?)<\/div>/is', "$1\n", $md);
	    
	    // ---- Inline formatting ----
	    // Bold: handles both <strong> and <b>, avoids double wrapping
	    $md = self::closedReplaceCallback(['</strong>', '</b>'], '/<(?:strong|b)[^>]*>(.*?)<\/(?:strong|b)>/is', function($matches) {
	        $inner = $matches[1];
	        // Keep nested formatting but strip others
	        $inner = strip_tags($inner, '<em><i><code><a><del><s><strike><u><mark><span>');
	        self::pregMatch('/^(\s*)(.*?)(\s*)$/s', $inner, $parts);
	        $text = $parts[2];
	        if ($text === '') return $parts[1] . $parts[3];
	        return $parts[1] . '**' . $text . '**' . $parts[3];
	    }, $md);
	    
	    // Italic: handles both <em> and <i>, avoids double wrapping
	    $md = self::closedReplaceCallback(['</em>', '</i>'], '/<(?:em|i)[^>]*>(.*?)<\/(?:em|i)>/is', function($matches) {
	        if (isset($matches[0]) && self::pregMatch('/class=["\"][^"\"]*(?:\blucide\b|\blucide-)/', $matches[0])) return ''; // Skip Lucide icons
	        $inner = $matches[1];
	        $inner = strip_tags($inner, '<strong><b><code><a><del><s><strike><u><mark><span>');
	        self::pregMatch('/^(\s*)(.*?)(\s*)$/s', $inner, $parts);
	        $text = $parts[2];
	        if ($text === '') return $parts[1] . $parts[3];
	        return $parts[1] . '*' . $text . '*' . $parts[3];
	    }, $md);
	    
	    // Strikethrough: handles del, s, strike
	    $md = self::closedReplaceCallback(['</del>', '</s>', '</strike>'], '/<(?:del|s|strike)[^>]*>(.*?)<\/(?:del|s|strike)>/is', function($matches) {
	        $inner = strip_tags($matches[1], '<strong><b><em><i><code><a><u><mark><span>');
	        self::pregMatch('/^(\s*)(.*?)(\s*)$/s', $inner, $parts);
	        $text = $parts[2];
	        if ($text === '') return $parts[1] . $parts[3];
	        return $parts[1] . '~~' . $text . '~~' . $parts[3];
	    }, $md);
	    
	    $md = self::closedReplace(['</u>'], '/<u[^>]*>(.*?)<\/u>/is', "<u>$1</u>", $md);
	    
	    $md = self::closedReplaceCallback(['</mark>'], '/<mark[^>]*>(.*?)<\/mark>/is', function($matches) {
	        $inner = strip_tags($matches[1], '<strong><b><em><i><code><a><u><span>');
	        self::pregMatch('/^(\s*)(.*?)(\s*)$/s', $inner, $parts);
	        $text = $parts[2];
	        if ($text === '') return $parts[1] . $parts[3];
	        return $parts[1] . '==' . $text . '==' . $parts[3];
	    }, $md);
	    
	    // Colors & Backgrounds (preserves span with style)
	    // Detect spans with style and keep them if they have color or background
	    $md = self::closedReplaceCallback(['</span>'], '/<span[^>]*style=["\']([^"\']*(?:color|background)[^"\']*)["\'][^>]*>(.*?)<\/span>/is', function($matches) {
	        $style = $matches[1];
	        $inner = $matches[2];
	        return '<span style="' . $style . '">' . $inner . '</span>';
	    }, $md);
	    
	    // ---- Links & Media ----
	    
	    // Inline code
	    $md = self::closedReplaceCallback(['</code>'], '/<code[^>]*>(.*?)<\/code>/is', function($matches) {
	        $code = $matches[1];
	        if (strpos($code, "\n") !== false || strlen($code) > 100) {
	            $code = html_entity_decode($code, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	            $code = strip_tags($code);
	            return "\n\n```\n" . trim($code) . "\n```\n\n";
	        }
	        $code = html_entity_decode(strip_tags($code), ENT_QUOTES | ENT_HTML5, 'UTF-8');
	        if (strpos($code, '`') === false) {
	            return '`' . $code . '`';
	        }
	        // Code holding backticks takes a longer run around it, padded with a
	        // space so the run does not merge with a backtick at either end
	        self::pregMatchAll('/`+/', $code, $runs);
	        $ticks = str_repeat('`', max(array_map('strlen', $runs[0])) + 1);
	        return $ticks . ' ' . $code . ' ' . $ticks;
	    }, $md);
	    
	    // Links
	    $md = self::closedReplace(['</a>'], '/<a[^>]*href=["\']([^"\']*)["\'][^>]*>(.*?)<\/a>/is', "[$2]($1)", $md);
	    
	    // Images
	    $md = self::pregReplace('/<img[^<>]*alt=["\']([^"\']*)["\'][^<>]*src=["\']([^"\']*)["\'][^<>]*\/?>/is', "![$1]($2)", $md); // B46: Tag endet vor dem nächsten <
	    $md = self::pregReplace('/<img[^<>]*src=["\']([^"\']*)["\'][^<>]*alt=["\']([^"\']*)["\'][^<>]*\/?>/is', "![$2]($1)", $md); // B46: Tag endet vor dem nächsten <
	    $md = self::pregReplace('/<img[^<>]*src=["\']([^"\']*)["\'][^<>]*\/?>/is', "![]($1)", $md); // B46: Tag endet vor dem nächsten <
	    
	    // Audio / Video / Iframe
	    $md = self::closedReplace(['</audio>'], '/<audio[^>]*src=["\']([^"\']*)["\'][^>]*>.*?<\/audio>/is', '[$1]($1)', $md);
	    $md = self::closedReplace(['</video>'], '/<video[^>]*src=["\']([^"\']*)["\'][^>]*>.*?<\/video>/is', '[$1]($1)', $md);
	    $md = self::closedReplace(['</iframe>'], '/<iframe[^>]*src=["\']([^"\']*)["\'][^>]*>.*?<\/iframe>/is', '[$1]($1)', $md);
	    
	    // Blockquotes
	    $md = self::closedReplaceCallback(['</blockquote>'], '/<blockquote[^>]*>(.*?)<\/blockquote>/is', function($matches) {
	        $inner = trim(strip_tags($matches[1], '<strong><b><em><i><code><a><u><mark><span>'));
	        $lines = explode("\n", $inner);
	        $quoted = '';
	        foreach ($lines as $line) {
	            $quoted .= "> " . trim($line) . "\n";
	        }
	        return "\n\n" . rtrim($quoted) . "\n\n";
	    }, $md);
	    
	    // ---- Cleanup ----
	    
	    // Keep only spans the Markdown renderer actually understands, i.e. a
	    // lone style attribute (used for colors and highlights). Pasted web
	    // content carries spans with extra attributes (Wikipedia ships
	    // data-mw-comment-end, ids, classes); the renderer does not match those,
	    // so keeping them would print the raw tag as text in the note.
	    //
	    // Innermost-first: the inner pattern forbids nested span tags, so
	    // repeating it unwraps one level per pass and never leaves the stray
	    // </span> that a single non-greedy pass produces on nested spans.
	    $previous = null;
	    $passes = 0;
	    while ($previous !== $md && $passes < 20) {
	        $previous = $md;
	        $md = self::closedReplaceCallback(['</span>'], '/<span([^>]*)>((?:(?!<\/?span\b).)*)<\/span>/is', function($matches) {
	            $attrs = trim($matches[1]);
	            $inner = $matches[2];
	            // Only a color/background span carries meaning the Markdown
	            // renderer reproduces. Layout styles such as Wikipedia's
	            // "display: inline-block" render as a literal tag in the note.
	            if (self::pregMatch('/^style=(["\'])([^"\']*(?:color|background)[^"\']*)\1$/i', $attrs)) {
	                return '<span ' . $attrs . '>' . $inner . '</span>';
	            }
	            return $inner;
	        }, $md);
	        $passes++;
	    }

	    // Unpaired tags can remain when the source markup is malformed (a
	    // <span> that is never closed). Drop opening tags the loop did not
	    // keep, plus any closing tag with no matching opener, so neither can
	    // reach the note as literal text.
	    $openStyledSpans = 0;
	    $md = self::pregReplaceCallback('/<span([^>]*)>|<\/span>/i', function($matches) use (&$openStyledSpans) {
	        if ($matches[0][1] === '/') {
	            if ($openStyledSpans > 0) {
	                $openStyledSpans--;
	                return '</span>';
	            }
	            return '';
	        }
	        $attrs = trim($matches[1]);
	        if (self::pregMatch('/^style=(["\'])([^"\']*(?:color|background)[^"\']*)\1$/i', $attrs)) {
	            $openStyledSpans++;
	            return '<span ' . $attrs . '>';
	        }
	        return '';
	    }, $md);

	    // Remove remaining HTML tags (keep details/summary/u/span)
	    $md = strip_tags($md, '<details><summary><u><span>');
	    
	    // ---- Convert HTML entities ----
	    $md = html_entity_decode($md, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	    
	    // Replace non-breaking spaces
	    $md = str_replace("\xC2\xA0", ' ', $md);
	    // Remove zero-width spaces
	    $md = str_replace("\xE2\x80\x8B", '', $md);
	    
	    $md = self::closedReplaceCallback(["\x01/ALIGN\x01"], '/\x01ALIGN:(center|right|justify)\x01(.*?)\x01\/ALIGN\x01/s', function($matches) {
	        $lines = [];
	        foreach (explode("\n", $matches[2]) as $line) {
	            $line = trim($line);
	            if ($line !== '') {
	                $lines[] = '<p align="' . $matches[1] . '">' . $line . '</p>';
	            }
	        }
	        return implode("\n", $lines);
	    }, $md);

	    // Clean up lines that are only whitespace
	    $md = self::pregReplace('/^[ \t]+$/m', '', $md);
	    
	    // Clean up excessive newlines
	    $md = self::pregReplace('/\n{3,}/', "\n\n", $md);
	    $md = trim($md);
	    
	    return $md;
	}
}
