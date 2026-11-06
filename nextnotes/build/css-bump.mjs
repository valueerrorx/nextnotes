// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// Upstream-CSS gegen NC-Kern-CSS (ADR 0017): jede Regel +1 ID-Stelle, Reihenfolge untereinander bleibt.
// Selektoren mit Klasse/ID (außer an html/body/:root) global per :not(#nn-0); reine Element-Selektoren
// gelten ohne Zusatz-Spezifität außerhalb der NC-Oberfläche und als gehobene Kopie in #nextnotes-root.
export const BUMP_GLOBAL = ':not(#nn-0)';
export const BUMP_SCOPED = ':is(#nextnotes-root,#nextnotes-root *)';
// NC-Oberfläche außerhalb der App (Kopf, Popover, Dialoge, Toasts): reine Element-Selektoren wirken dort nicht.
// Ein Vorfahren-Durchlauf (:is(…) *) statt vier: `*`-Regeln kosteten sonst je Stilberechnung ~100 ms (M4i-fix)
export const NOT_NC_CHROME = ':where(:not(:is(#header,.v-popper__popper,.modal-mask,.toastify) *):not(#header,.v-popper__popper,.modal-mask,.toastify))';
// Pseudo-Element am Ende samt nachfolgenden Pseudo-Klassen (z. B. ::-webkit-scrollbar-thumb:hover); Zusatz muss davor
const PSEUDO_ARG = '(?:\\([^()]*(?:\\([^()]*\\)[^()]*)*\\))?';
const PSEUDO_EL = `(?:::[a-z-]+${PSEUDO_ARG}|:(?:before|after|first-line|first-letter)(?![a-z-]))`;
const PSEUDO_TAIL = new RegExp(`(${PSEUDO_EL}(?::{1,2}[a-z-]+${PSEUDO_ARG})*)$`, 'i');
// Zusatz hinter einem Pseudo-Element (ungültiger Selektor, Browser verwirft die ganze Regel)
const BUMP_AFTER_PSEUDO = new RegExp(`${PSEUDO_EL}(?::{1,2}[a-z-]+${PSEUDO_ARG})*?:(?:not\\(#nn-0\\)|is\\(#nextnotes-root|where\\(:not\\(:is\\(#header)`, 'gi');

// Text bis zu einem Zeichen aus stops auf Ebene 0 (Klammern, Strings, Kommentare beachtet)
function scanTo(css, i, stops) {
	let depth = 0;
	while (i < css.length) {
		const c = css[i];
		if (c === '/' && css[i + 1] === '*') { const e = css.indexOf('*/', i + 2); i = e < 0 ? css.length : e + 2; continue; }
		if (c === '"' || c === "'") { i++; while (i < css.length && css[i] !== c) i += css[i] === '\\' ? 2 : 1; i++; continue; }
		if (c === '(' || c === '[') depth++;
		else if (c === ')' || c === ']') depth--;
		else if (depth === 0 && stops.includes(c)) return i;
		i++;
	}
	return i;
}

// Ende des Blocks ab '{' (inklusive '}')
function blockEnd(css, i) {
	let depth = 0;
	while (i < css.length) {
		const j = scanTo(css, i, '{}');
		if (j >= css.length) return j;
		depth += css[j] === '{' ? 1 : -1;
		i = j + 1;
		if (depth === 0) return i;
	}
	return i;
}

function splitList(sel) {
	const out = [];
	let i = 0;
	while (i <= sel.length) {
		const j = scanTo(sel, i, ',');
		out.push(sel.slice(i, j));
		i = j + 1;
	}
	return out;
}

function withBump(sel, bump) {
	const t = sel.trim();
	const m = PSEUDO_TAIL.exec(t);
	const head = m ? t.slice(0, m.index) : t;
	const tail = m ? m[1] : '';
	return head + (head === '' || /[\s>+~]$/.test(head) ? '*' : '') + bump + tail;
}

function bumpSelector(sel) {
	const t = sel.replace(/\/\*[\s\S]*?\*\//g, '').trim();
	if (t === '') return [];
	// Klasse/ID außerhalb führender html/body/:root-Teile?
	const rest = t.replace(/^:(?:where|is)\(((?:html|body|:root)[^()]*)\)/i, '$1').replace(/^(?:\s*(?:html|body|:root)(?:[.#[:][^\s>+~]*)?\s*[>+~]?\s*)+/i, '');
	if (rest === '') return [t];
	// ::highlight(name): Namen setzt nur App-Code, NC-Kern stylt sie nicht; ohne Zusatz keine Prüfung je Element (M4i-fix)
	if (/^::highlight\([^()]*\)$/i.test(rest.trim())) return [t];
	const specific = /[.#]|\[(?:class|id|data-)/i.test(rest.replace(/\[(?!class|id|data-)[^\]]*\]|"[^"]*"|'[^']*'/gi, ''));
	return specific ? [withBump(t, BUMP_GLOBAL)] : [withBump(t, NOT_NC_CHROME), withBump(t, BUMP_SCOPED)];
}

// Regeln durchlaufen; onRule(selektoren) liefert die neue Selektorliste
export function mapRules(css, onRule) {
	let out = '';
	let i = 0;
	while (i < css.length) {
		const j = scanTo(css, i, '{};');
		if (j >= css.length) { out += css.slice(i); break; }
		const prelude = css.slice(i, j);
		if (css[j] === ';' || css[j] === '}') { out += css.slice(i, j + 1); i = j + 1; continue; }
		const at = /^\s*(?:\/\*[\s\S]*?\*\/\s*)*@([a-z-]+)/i.exec(prelude);
		const end = blockEnd(css, j);
		if (at) {
			out += /^(media|supports|container|layer|document|scope)$/i.test(at[1])
				? prelude + '{' + mapRules(css.slice(j + 1, end - 1), onRule) + '}'
				: css.slice(i, end);
		} else {
			const lead = /^\s*(?:\/\*[\s\S]*?\*\/\s*)*/.exec(prelude)[0];
			out += lead + onRule(splitList(prelude.slice(lead.length))) + css.slice(j, end);
		}
		i = end;
	}
	return out;
}

export function bumpSpecificity(css) {
	return mapRules(css, (sels) => sels.flatMap(bumpSelector).join(','));
}

// Zahl der Stilregeln (auch in @media usw.)
export function countRules(css) {
	let n = 0;
	mapRules(css, (sels) => { n++; return sels.join(','); });
	return n;
}

// Fehlerliste: Zusatz hinter Pseudo-Element, Regelzahl vor/nach ungleich
export function checkBumped(before, after) {
	const errors = [...new Set(after.match(BUMP_AFTER_PSEUDO) ?? [])].map((s) => `Zusatz hinter Pseudo-Element: ${s}`);
	const a = countRules(before);
	const b = countRules(after);
	if (a !== b) errors.push(`Regelzahl ${a} vor, ${b} nach Spezifitäts-Zusatz`);
	return errors;
}

export function findBumpAfterPseudo(css) {
	return [...new Set(css.match(BUMP_AFTER_PSEUDO) ?? [])];
}
