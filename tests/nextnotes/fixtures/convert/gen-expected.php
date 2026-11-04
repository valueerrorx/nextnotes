<?php
// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// M4b: Erwartung aus den Upstream-Funktionen (Host-PHP, Upstream nur gelesen). Aufruf: php -n gen-expected.php <repo>/src > expected.json
$src = rtrim($argv[1] ?? '', '/');
if (!is_file($src . '/markdown_parser.php')) {
	fwrite(STDERR, "Aufruf: php -n gen-expected.php <src>\n");
	exit(2);
}
require $src . '/markdown_parser.php';
require $src . '/html_to_markdown.php';
require $src . '/lib/ai-note-diagrams.php';
require $src . '/lib/html-sanitize.php';

// Upstream NotesController::convert, Zweig html → markdown (ohne Base64-Teil)
function upstreamNoteToMarkdown(string $content): string {
	$md = poznoteHtmlToMarkdown(aiHideEmbeddedDiagrams($content, false));
	if (strpos($md, AI_DIAGRAM_PREFIX) !== false) {
		$md = preg_replace('~\s*(!\[[^\]\n]*\]\(' . preg_quote(AI_DIAGRAM_PREFIX, '~') . '[^)\n]*\))\s*~', "\n\n$1\n\n", $md) ?? $md;
		$md = ltrim(aiRestoreEmbeddedDiagrams($md, $content, true), "\n");
	}
	return $md;
}

$out = [];
foreach (require __DIR__ . '/corpus.php' as [$kind, $name, $input]) {
	$out[$name] = match ($kind) {
		'md' => parseMarkdownForRichText($input),
		'html' => poznoteHtmlToMarkdown($input),
		'note' => upstreamNoteToMarkdown($input),
	};
}
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
