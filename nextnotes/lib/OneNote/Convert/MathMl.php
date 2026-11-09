<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\OneNote\Convert;

use DOMElement;
use DOMNode;
use OCA\NextNotes\Service\Pcre;

// Präsentations-MathML → LaTeX für den Formel-Vertrag (SPEC §4.7 Punkt 1); null = nicht umwandelbar, Aufrufer nimmt den Text
final class MathMl {
	// Grenze aus BlockMarkup (data-math 1–10 000 Zeichen)
	public const MAX_LATEX = 10000;

	private const FUNCTIONS = ['sin', 'cos', 'tan', 'cot', 'sec', 'csc', 'arcsin', 'arccos', 'arctan', 'sinh', 'cosh', 'tanh',
		'log', 'ln', 'lg', 'exp', 'lim', 'max', 'min', 'sup', 'inf', 'det', 'gcd', 'deg', 'dim', 'arg'];
	private const SYMBOLS = [
		'α' => '\alpha', 'β' => '\beta', 'γ' => '\gamma', 'δ' => '\delta', 'ε' => '\varepsilon', 'ζ' => '\zeta', 'η' => '\eta',
		'θ' => '\theta', 'ι' => '\iota', 'κ' => '\kappa', 'λ' => '\lambda', 'μ' => '\mu', 'ν' => '\nu', 'ξ' => '\xi', 'π' => '\pi',
		'ρ' => '\rho', 'σ' => '\sigma', 'τ' => '\tau', 'υ' => '\upsilon', 'φ' => '\varphi', 'χ' => '\chi', 'ψ' => '\psi', 'ω' => '\omega',
		'Γ' => '\Gamma', 'Δ' => '\Delta', 'Θ' => '\Theta', 'Λ' => '\Lambda', 'Ξ' => '\Xi', 'Π' => '\Pi', 'Σ' => '\Sigma',
		'Φ' => '\Phi', 'Ψ' => '\Psi', 'Ω' => '\Omega',
		'×' => '\times', '·' => '\cdot', '⋅' => '\cdot', '∙' => '\cdot', '÷' => '\div', '±' => '\pm', '∓' => '\mp', '−' => '-',
		'≤' => '\le', '≥' => '\ge', '≠' => '\ne', '≈' => '\approx', '≡' => '\equiv', '∝' => '\propto', '∼' => '\sim',
		'→' => '\to', '←' => '\leftarrow', '⇒' => '\Rightarrow', '⇔' => '\Leftrightarrow', '↔' => '\leftrightarrow',
		'∞' => '\infty', '∂' => '\partial', '∇' => '\nabla', '∑' => '\sum', '∏' => '\prod', '∫' => '\int', '∬' => '\iint', '∮' => '\oint',
		'∈' => '\in', '∉' => '\notin', '⊂' => '\subset', '⊆' => '\subseteq', '∪' => '\cup', '∩' => '\cap', '∅' => '\emptyset',
		'∀' => '\forall', '∃' => '\exists', '¬' => '\neg', '∧' => '\wedge', '∨' => '\vee', '°' => '^\circ', '…' => '\ldots', '⋯' => '\cdots',
		'ℝ' => '\mathbb{R}', 'ℕ' => '\mathbb{N}', 'ℤ' => '\mathbb{Z}', 'ℚ' => '\mathbb{Q}', 'ℂ' => '\mathbb{C}',
		'{' => '\{', '}' => '\}', '#' => '\#', '$' => '\$', '%' => '\%', '&' => '\&', '_' => '\_', '\\' => '\backslash', '~' => '\sim', '^' => '\hat{}',
		// unsichtbare Operatoren (Funktionsanwendung, Malpunkt, Trenner, Plus)
		"\u{2061}" => '', "\u{2062}" => '', "\u{2063}" => '', "\u{2064}" => '',
	];
	private const LARGE_OPS = ['∑', '∏', '∫', '∬', '∮', 'lim', 'max', 'min', '⋃', '⋂'];
	private const ACCENTS = ['¯' => '\overline', '‾' => '\overline', '_' => '\overline', '^' => '\hat', 'ˆ' => '\hat', '→' => '\vec', '⃗' => '\vec',
		'~' => '\tilde', '˜' => '\tilde', '˙' => '\dot', '¨' => '\ddot', '⏞' => '\overbrace'];
	private const UNDER_ACCENTS = ['¯' => '\underline', '‾' => '\underline', '_' => '\underline', '⏟' => '\underbrace'];

	public static function toLatex(DOMElement $math): ?string {
		$tex = self::annotation($math) ?? self::node($math);
		if ($tex === null) {
			return null;
		}
		$tex = trim(self::squash($tex));
		return $tex === '' || mb_strlen($tex) > self::MAX_LATEX ? null : $tex;
	}

	public static function isBlock(DOMElement $math): bool {
		return strtolower($math->getAttribute('display')) === 'block' || strtolower($math->getAttribute('mode')) === 'display';
	}

	// Lokaler Name ohne Präfix (mml:mi → mi)
	public static function name(DOMNode $node): string {
		$name = strtolower($node->nodeName);
		$colon = strrpos($name, ':');
		return $colon === false ? $name : substr($name, $colon + 1);
	}

	// Vorhandene TeX-Annotation hat Vorrang
	private static function annotation(DOMElement $math): ?string {
		foreach ($math->getElementsByTagName('*') as $el) {
			if (self::name($el) === 'annotation' && in_array(strtolower($el->getAttribute('encoding')), ['application/x-tex', 'tex', 'latex'], true)) {
				$text = trim($el->textContent);
				return $text === '' ? null : $text;
			}
		}
		return null;
	}

	private static function node(DOMNode $node): ?string {
		if (!$node instanceof DOMElement) {
			return $node->nodeType === XML_TEXT_NODE && trim($node->textContent) !== '' ? null : '';
		}
		$kids = self::elements($node);
		switch (self::name($node)) {
			case 'math':
			case 'mrow':
			case 'mstyle':
			case 'mpadded':
			case 'menclose':
			case 'merror':
			case 'mtd':
				return self::row($node);
			case 'semantics':
				return isset($kids[0]) ? self::node($kids[0]) : '';
			case 'mphantom':
			case 'annotation':
			case 'annotation-xml':
			case 'mspace':
				return self::name($node) === 'mspace' ? '\ ' : '';
			case 'mi':
				return self::identifier(trim($node->textContent));
			case 'mn':
				return self::escape(trim($node->textContent));
			case 'mo':
				return ' ' . self::escape(trim($node->textContent)) . ' ';
			case 'mtext':
			case 'ms':
				$text = $node->textContent;
				return trim($text) === '' ? '\ ' : '\text{' . self::escapeText($text) . '}';
			case 'msup':
				return count($kids) === 2 ? self::script($kids[0], '^', $kids[1]) : null;
			case 'msub':
				return count($kids) === 2 ? self::script($kids[0], '_', $kids[1]) : null;
			case 'msubsup':
				return count($kids) === 3 ? self::join(self::script($kids[0], '_', $kids[1]), self::group($kids[2]), '^') : null;
			case 'mfrac':
				return count($kids) === 2 ? self::wrap('\frac', $kids[0], $kids[1]) : null;
			case 'msqrt':
				$inner = self::row($node);
				return $inner === null ? null : '\sqrt{' . $inner . '}';
			case 'mroot':
				if (count($kids) !== 2) {
					return null;
				}
				$base = self::node($kids[0]);
				$index = self::node($kids[1]);
				return $base === null || $index === null ? null : '\sqrt[' . trim($index) . ']{' . trim($base) . '}';
			case 'mfenced':
				return self::fenced($node, $kids);
			case 'mover':
				return count($kids) === 2 ? self::over($kids[0], $kids[1]) : null;
			case 'munder':
				return count($kids) === 2 ? self::under($kids[0], $kids[1]) : null;
			case 'munderover':
				if (count($kids) !== 3) {
					return null;
				}
				$base = self::node($kids[0]);
				$under = self::group($kids[1]);
				$over = self::group($kids[2]);
				return $base === null || $under === null || $over === null ? null : trim($base) . '_' . $under . '^' . $over . ' ';
			case 'mtable':
				return self::table($kids);
			default:
				return null;
		}
	}

	/** @return list<DOMElement> */
	private static function elements(DOMElement $node): array {
		$out = [];
		for ($c = $node->firstChild; $c !== null; $c = $c->nextSibling) {
			if ($c instanceof DOMElement) {
				$out[] = $c;
			}
		}
		return $out;
	}

	private static function row(DOMElement $node): ?string {
		$out = '';
		for ($c = $node->firstChild; $c !== null; $c = $c->nextSibling) {
			$part = self::node($c);
			if ($part === null) {
				return null;
			}
			$out .= $part;
		}
		return $out;
	}

	private static function group(DOMElement $node): ?string {
		$tex = self::node($node);
		return $tex === null ? null : '{' . trim($tex) . '}';
	}

	private static function script(DOMElement $base, string $op, DOMElement $arg): ?string {
		$b = self::node($base);
		$a = self::group($arg);
		if ($b === null || $a === null) {
			return null;
		}
		$b = trim($b);
		// zusammengesetzte Basis klammern, damit der Exponent für alles gilt
		return (mb_strlen($b) > 1 && !str_starts_with($b, '\\') && !str_starts_with($b, '{') ? '{' . $b . '}' : $b) . $op . $a;
	}

	private static function join(?string $left, ?string $right, string $op): ?string {
		return $left === null || $right === null ? null : $left . $op . $right . ' ';
	}

	private static function wrap(string $cmd, DOMElement $a, DOMElement $b): ?string {
		$x = self::group($a);
		$y = self::group($b);
		return $x === null || $y === null ? null : $cmd . $x . $y . ' ';
	}

	/** @param list<DOMElement> $kids */
	private static function fenced(DOMElement $node, array $kids): ?string {
		$open = $node->hasAttribute('open') ? $node->getAttribute('open') : '(';
		$close = $node->hasAttribute('close') ? $node->getAttribute('close') : ')';
		$sep = $node->hasAttribute('separators') ? trim($node->getAttribute('separators')) : ',';
		$parts = [];
		foreach ($kids as $kid) {
			$tex = self::node($kid);
			if ($tex === null) {
				return null;
			}
			$parts[] = trim($tex);
		}
		$fence = static fn (string $f): string => $f === '' ? '.' : (self::SYMBOLS[$f] ?? $f);
		return '\left' . $fence($open) . implode(($sep === '' ? '' : mb_substr($sep, 0, 1)) . ' ', $parts) . '\right' . $fence($close) . ' ';
	}

	private static function over(DOMElement $base, DOMElement $over): ?string {
		$mark = trim($over->textContent);
		if (self::name($over) === 'mo' && isset(self::ACCENTS[$mark])) {
			$b = self::group($base);
			return $b === null ? null : self::ACCENTS[$mark] . $b . ' ';
		}
		if (in_array(trim($base->textContent), self::LARGE_OPS, true)) {
			return self::join(self::node($base) === null ? null : trim((string)self::node($base)), self::group($over), '^');
		}
		return self::wrap('\overset', $over, $base);
	}

	private static function under(DOMElement $base, DOMElement $under): ?string {
		$mark = trim($under->textContent);
		if (self::name($under) === 'mo' && isset(self::UNDER_ACCENTS[$mark])) {
			$b = self::group($base);
			return $b === null ? null : self::UNDER_ACCENTS[$mark] . $b . ' ';
		}
		if (in_array(trim($base->textContent), self::LARGE_OPS, true)) {
			return self::join(self::node($base) === null ? null : trim((string)self::node($base)), self::group($under), '_');
		}
		return self::wrap('\underset', $under, $base);
	}

	/** @param list<DOMElement> $rows */
	private static function table(array $rows): ?string {
		$lines = [];
		foreach ($rows as $row) {
			if (!in_array(self::name($row), ['mtr', 'mlabeledtr'], true)) {
				return null;
			}
			$cells = [];
			foreach (self::elements($row) as $cell) {
				$tex = self::node($cell);
				if ($tex === null) {
					return null;
				}
				$cells[] = trim($tex);
			}
			$lines[] = implode(' & ', $cells);
		}
		return '\begin{matrix}' . implode(' \\\\ ', $lines) . '\end{matrix} ';
	}

	private static function identifier(string $text): string {
		if ($text === '') {
			return '';
		}
		if (in_array($text, self::FUNCTIONS, true)) {
			return '\\' . $text . ' ';
		}
		if (mb_strlen($text) === 1) {
			return self::escape($text);
		}
		return '\mathrm{' . self::escapeText($text) . '}';
	}

	// Zeichenweise: Sonderzeichen und bekannte Symbole als Befehl
	private static function escape(string $text): string {
		$out = '';
		foreach (mb_str_split($text) as $ch) {
			if (isset(self::SYMBOLS[$ch])) {
				$cmd = self::SYMBOLS[$ch];
				$out .= $cmd . (ctype_alpha(substr($cmd, -1)) ? ' ' : '');
			} else {
				$out .= $ch;
			}
		}
		return $out;
	}

	// Text in \text{}/\mathrm{}: nur TeX-Sonderzeichen maskieren
	private static function escapeText(string $text): string {
		$map = ['\\' => '\textbackslash{}', '{' => '\{', '}' => '\}', '#' => '\#', '$' => '\$', '%' => '\%', '&' => '\&', '_' => '\_', '^' => '\^{}', '~' => '\~{}'];
		return strtr(str_replace(["\r", "\n", "\t"], ' ', $text), $map);
	}

	// Mehrfache Leerzeichen zusammenfassen, keine vor „}“/„^“/„_“ und nach „{“/„(“
	private static function squash(string $tex): string {
		$tex = Pcre::replace('/ {2,}+/', ' ', $tex);
		$tex = Pcre::replace('/(?<!\\\\) ++(?=[}^_])/', '', $tex);
		return Pcre::replace('/(?<=[{(]) ++/', '', $tex);
	}
}
