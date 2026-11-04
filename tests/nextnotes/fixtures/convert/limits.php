<?php
// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// M4b Grenzfall-Korpus (Plan M4b.2): Erzeuger je Fall für eine Zielgröße in Bytes; md = MarkdownRenderer, html = HtmlToMarkdown
$fill = static fn (string $unit, int $bytes): string => substr(str_repeat($unit, intdiv($bytes, strlen($unit)) + 1), 0, $bytes);
return [
	'md-text' => ['md', static fn (int $n) => $fill("Ein ganz normaler Satz mit Wörtern. ", $n)],
	'md-paragraphs' => ['md', static fn (int $n) => $fill("Absatz mit **fett** und `code`.\n\n", $n)],
	'md-lists' => ['md', static fn (int $n) => $fill("- eins\n  - zwei\n    - drei\n", $n)],
	'md-table' => ['md', static fn (int $n) => "| a | b |\n|---|---|\n" . $fill("| x | y |\n", $n - 20)],
	'md-quotes' => ['md', static fn (int $n) => $fill("> a\n>> b\n>>> c\n", $n)],
	'md-stars' => ['md', static fn (int $n) => $fill('*', $n)],
	'md-underscore' => ['md', static fn (int $n) => $fill('_a ', $n)],
	'md-brackets' => ['md', static fn (int $n) => $fill('[[[(', $n)],
	'md-backticks' => ['md', static fn (int $n) => $fill('`', $n)],
	'md-tilde-eq' => ['md', static fn (int $n) => $fill('~~a ==b ', $n)],
	'md-links' => ['md', static fn (int $n) => $fill('[x](http://a.b/c_d) ', $n)],
	'md-html' => ['md', static fn (int $n) => $fill('<div><span>', $n)],
	'html-a-open' => ['html', static fn (int $n) => $fill('<a href="x">t ', $n)],
	'html-nested' => ['html', static fn (int $n) => $fill('<div><ul><li><b>', $n)],
	'html-callout-open' => ['html', static fn (int $n) => $fill('<span class=callout>', $n)],
	'html-text' => ['html', static fn (int $n) => $fill('<p>Text mit <b>fett</b> und <i>kursiv</i>.</p>', $n)],
	'html-lt' => ['html', static fn (int $n) => $fill('<', $n)],
	'html-p-open' => ['html', static fn (int $n) => $fill('<p style="text-align:center">x', $n)],
	'html-span-open' => ['html', static fn (int $n) => $fill('<span>', $n)],
	'html-table-open' => ['html', static fn (int $n) => $fill('<table><tr><td>', $n)],
	'html-pre-open' => ['html', static fn (int $n) => $fill('<pre><code class="language-x">', $n)],
];
