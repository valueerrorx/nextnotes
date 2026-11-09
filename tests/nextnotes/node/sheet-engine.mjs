// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// M8b-fix (Review M8b r1, SPEC §4.7.7): Engine-Konfiguration des Tabellenblocks ohne Browser.
// Gesperrte Funktionen (VERSION, BEREICH.VERSCHIEBEN, englische Namen), WAHR/FALSCH, Platzhalter-ReDoS,
// Matrix-Literale, Klammertiefe, Rechenbudget (< 1 s), LibreOffice-Fixture.
// M8b-fix2 (Review M8b r2): ' und ! außerhalb von Zeichenketten sperren die Formel, Blattgröße als zweite Linie.
// Aufruf: make -C nextnotes build && node tests/nextnotes/node/sheet-engine.mjs
import { readFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';
import test from 'node:test';
import assert from 'node:assert/strict';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..', '..');
const APP = join(ROOT, 'nextnotes');
// Hauptkontext (ein vm-Kontext verlangsamt globale Zugriffe um ein Mehrfaches)
globalThis.window = {};
vm.runInThisContext(readFileSync(join(APP, 'js/vendor/hyperformula/hyperformula.min.js'), 'utf8'));
window.NextNotesHyperFormula = vm.runInThisContext('NextNotesHyperFormula');
vm.runInThisContext(readFileSync(join(APP, 'js-src/nextnotes-sheet.js'), 'utf8'));
const S = window.NextNotesSheet;
const H = window.NextNotesHyperFormula.HyperFormula;
const FIX = JSON.parse(readFileSync(join(ROOT, 'tests/nextnotes/fixtures/sheet/functions.json'), 'utf8'));

// Blattgröße der Engine vor destroy() mitschreiben
let dims = null;
const build = H.buildFromArray.bind(H);
H.buildFromArray = (cells, cfg) => {
	const hf = build(cells, cfg);
	const destroy = hf.destroy.bind(hf);
	hf.destroy = () => { dims = hf.getSheetDimensions(0); destroy(); };
	return hf;
};
const sheet = (cells) => JSON.stringify({ v: 1, lang: 'deDE', rows: cells.length, cols: cells[0].length, cells, fmt: {} });
function run(cells) {
	const t = performance.now();
	const out = S.evaluate(sheet(cells));
	return { out, ms: performance.now() - t, dims };
}
const one = (f) => run([[f]]).out[0][0];
const grid = (fn) => Array.from({ length: 50 }, (_, r) => Array.from({ length: 26 }, (_, c) => fn(r, c)));
const NAME = { error: '#NAME?' };

const ALLOWED = ['ABRUNDEN', 'ABS', 'ACHSENABSCHNITT', 'ANZAHL', 'ANZAHL2', 'ARCCOS', 'ARCSIN', 'ARCTAN', 'AUFRUNDEN', 'BINOM.VERT', 'BOGENMASS',
	'COS', 'EXP', 'FALSCH', 'GANZZAHL', 'GRAD', 'HEUTE', 'KORREL', 'LN', 'LOG', 'LOG10', 'MAX', 'MEDIAN', 'MIN', 'MITTELWERT', 'MODUS.EINF', 'NICHT',
	'NORM.INV', 'NORM.VERT', 'ODER', 'PI', 'POTENZ', 'QUARTILE.INKL', 'REST', 'RUNDEN', 'SIN', 'STABW.N', 'STABW.S', 'STEIGUNG', 'SUMME', 'SUMMEWENN',
	'TAN', 'UND', 'VAR.P', 'VAR.S', 'WAHR', 'WENN', 'WURZEL', 'ZÄHLENWENN'];

test('erreichbar sind genau die 49 Whitelist-Namen', () => {
	assert.deepEqual(S.functionNames(), ALLOWED);
	assert.deepEqual(S.registeredNames(), [...ALLOWED, "'NN_GESPERRT_OFFSET", 'VERSION'].sort());
});

test('jede registrierte, nicht freigegebene Funktion → #NAME? (deDE und englische IDs)', () => {
	const de = S.registeredNames().filter((n) => !ALLOWED.includes(n));
	const en = H.getRegisteredFunctionNames('enGB').filter((n) => !ALLOWED.includes(n));
	const all = [...new Set([...de, ...en, 'BEREICH.VERSCHIEBEN', 'OFFSET', 'VERSION', 'SUM', 'TRUE', 'IF', ...Object.values(window.NextNotesHyperFormula.deDE.functions).filter((n) => n && !ALLOWED.includes(n))])];
	assert.ok(all.length > 300, `${all.length} Namen geprüft`);
	const bad = all.filter((n) => JSON.stringify(one(`=${n}(1)`)) !== JSON.stringify(NAME));
	assert.deepEqual(bad, []);
	// Varianten: klein, Leerraum vor „(“, Regex-Punkt im Lexer-Muster, verschachtelt, nach Zeichenkette
	for (const f of ['=version()', '=VERSION ()', '=BEREICH VERSCHIEBEN(A1;0;0;1;1)', '=bereich.verschieben(A1;0;0;1;1)', '=SUMME(1;OFFSET(A1;0;0))', '="a"&VERSION()', '=1+BEREICH.VERSCHIEBEN(A1;0;0)']) {
		assert.deepEqual(one(f), NAME, f);
	}
});

test('BEREICH.VERSCHIEBEN mit 1 000 000 Zeilen: #NAME? < 200 ms, Blattgröße unverändert', () => {
	const r = run([['=SUMME(BEREICH.VERSCHIEBEN(A1;0;0;1000000;1))', 1], [2, 3]]);
	assert.deepEqual(r.out[0][0], NAME);
	assert.ok(r.ms < 200, `${r.ms} ms`);
	assert.deepEqual(r.dims, { width: 2, height: 2 });
});

// Review M8b r2 Blocker 1: Blattname in '…' mit " versteckte Aufrufe vor der Prüfung (Messskripte p2–p4)
const fast = (cells, label) => {
	const r = run(cells);
	assert.ok(r.ms < 200, `${label}: ${r.ms} ms`);
	assert.deepEqual(r.dims, { width: cells[0].length, height: cells.length }, label);
	return r.out;
};
test("r2: Blattname '…\"' mit versteckter Funktion → #NAME? < 200 ms, Blattgröße unverändert", () => {
	for (const n of ['1000000', '100000000']) {
		for (const f of [`=SUMME('x"'!B2;'NN_GESPERRT_OFFSET(B2;0;0;${n};1);"")`, `=SUMME('x"'!B2;NN_GESPERRT_OFFSET(B2;0;0;${n};1);"")`,
			`=SUMME('x"'!B2;BEREICH.VERSCHIEBEN(B2;0;0;${n};1);"")`]) {
			assert.deepEqual(fast([[f, 1], [2, 3]], f)[0][0], NAME, f);
		}
	}
	for (const f of [`=SUMME('x"'!B2;VERSION();"")`, `=WENN(1;'x"'!B2;"")&VERSION()`]) assert.deepEqual(fast([[f, 1], [2, 3]], f)[0][0], NAME, f);
	assert.equal(S.calls(`=SUMME('x"'!B2;VERSION();"")`), null);
});
test("r2: Budget mit '…\"' (520 × MODUS.EINF über 130 Bereiche) → #NAME? < 200 ms", () => {
	const rng = Array(130).fill('A1:Z30').join(';');
	const f = `=WENN(0;'x"'!B2;MODUS.EINF(${rng};""))`;
	const out = fast(grid((r, c) => (r >= 30 && (r - 30) * 26 + c < 520 ? f : r * c + 0.5)), 'p3');
	assert.ok(out.slice(30).flat().every((v) => JSON.stringify(v) === JSON.stringify(NAME)));
});
test('r2: Blattbezüge (Sheet1!) → #NAME?, auch als Budget-Umgehung', () => {
	for (const f of ['=SUMME(B1:Sheet1!B2)', '=SUMME(Sheet1!B1:Sheet1!B2)', '=SUMME(Sheet1!B1:B2)', '=SUMME(B:Sheet1!B)', '=SUMME(2:Sheet1!2)', '=Sheet1!B2']) {
		assert.deepEqual(fast([[f, 1], [2, 3]], f)[0][0], NAME, f);
	}
	const f = `=MODUS.EINF(${Array(45).fill('Sheet1!A1:Sheet1!Z30').join(';')})`;
	const out = fast(grid((r, c) => (r >= 30 && (r - 30) * 26 + c < 520 ? f : r * c + 0.5)), 'p4');
	assert.deepEqual(out[30][0], NAME);
	assert.equal(one('=SUMME((B1:B2))'), 0);
});
test("' in jeder Form außerhalb von Zeichenketten gesperrt; Unicode-Anführungszeichen bleiben Fehler", () => {
	for (const f of ["=SUMME(''!B2)", "=SUMME('x'''!B2)", "=''", "='", "=1+'2'", '="abc']) assert.deepEqual(one(f), NAME, f);
	// ’ ‘ ＇ ′ kennt der HF-Lexer nicht: Lexfehler oder (bei offener Zeichenkette) Sperre, nie ein Ergebnis der versteckten Funktion
	for (const q of ['’', '‘', '＇', '′']) {
		for (const f of [`=SUMME(${q}x"${q}!B2;VERSION();"")`, `=SUMME(${q}x"${q}!B2;VERSION();")`, `=SUMME(${q}x"${q}!B2;BEREICH.VERSCHIEBEN(B2;0;0;1000000;1);")`]) {
			const v = fast([[f, 1], [2, 3]], f)[0][0];
			assert.ok(v && ['#NAME?', '#ERROR!'].includes(v.error), `${f}: ${JSON.stringify(v)}`);
		}
	}
});
test("' und ! in Zeichenketten und Fehlerwerte bleiben erlaubt", () => {
	assert.deepEqual(run([['="Hallo!"', '=WENN(1;"it\'s";"x")&"!"', '="\'x\'!B2"']]).out[0], ['Hallo!', "it's!", "'x'!B2"]);
	// #BEZUG! entsteht beim Löschen von Zeilen (getCellFormula) und muss #BEZUG! bleiben
	assert.deepEqual(one('=#BEZUG!+1'), { error: '#BEZUG!' });
	assert.deepEqual(one('=SUMME(#BEZUG!)'), { error: '#BEZUG!' });
});
test('interner Name für BEREICH.VERSCHIEBEN ist untippbar (enthält \'), jede Schreibweise → #NAME?', () => {
	const off = S.registeredNames().find((n) => /OFFSET/.test(n));
	assert.ok(off.startsWith("'"), off);
	for (const f of ['=NN_GESPERRT_OFFSET(B2;0;0;1000000;1)', '=nn_gesperrt_offset(B2;0;0;1000000;1)', '=Nn_Gesperrt_Offset(B2;0;0;1000000;1)',
		"='nn_gesperrt_offset(B2;0;0;1000000;1)", "=SUMME(1;'NN_GESPERRT_OFFSET(B2;0;0;1000000;1))", '=SUMME(B2:NN_GESPERRT_OFFSET(B2;0;0;1000000;1))']) {
		assert.deepEqual(fast([[f, 1], [2, 3]], f)[0][0], NAME, f);
	}
});
test('zweite Linie: Blatt über maxRows/maxColumns → Engine verworfen (evaluate null)', () => {
	const real = H.buildFromArray;
	let destroyed = 0;
	H.buildFromArray = (cells, cfg) => {
		const hf = real(cells, cfg);
		const destroy = hf.destroy.bind(hf);
		hf.getSheetDimensions = () => ({ width: 2, height: 1000001 });
		hf.destroy = () => { destroyed++; destroy(); };
		return hf;
	};
	try {
		assert.equal(S.evaluate(sheet([[1, 2]])), null);
		assert.equal(destroyed, 1);
	} finally {
		H.buildFromArray = real;
	}
	assert.deepEqual(run([[1, '=A1*2']]).out[0], [1, 2]);
});

test('WAHR() und FALSCH() rechnen', () => {
	assert.deepEqual(run([['=WAHR()', '=FALSCH()', '=WENN(WAHR();1;2)', '=UND(WAHR();NICHT(FALSCH()))']]).out[0], [true, false, 1, true]);
});

test('Zeichenketten sind keine Funktionsaufrufe', () => {
	assert.deepEqual(run([['=WENN(1;"VERSION(1)";"x")', '="BEREICH.VERSCHIEBEN("&"x"']]).out[0], ['VERSION(1)', 'BEREICH.VERSCHIEBEN(x']);
});

test('ZÄHLENWENN ohne Platzhalter: "*a*a*a*b" auf 5 × 1000 a < 200 ms', () => {
	const cells = Array.from({ length: 5 }, (_, i) => ['a'.repeat(1000), i ? null : '=ZÄHLENWENN(A1:A5;"*a*a*a*b")']);
	const r = run(cells);
	assert.equal(r.out[0][1], 0);
	assert.ok(r.ms < 200, `${r.ms} ms`);
	// * und ? sind normale Zeichen
	assert.deepEqual(run([['x*', '=ZÄHLENWENN(A1:A3;"x*")', '=SUMMEWENN(A1:A3;"?";D1:D3)', 1], ['xy', null, null, 5], ['?', null, null, 7]]).out[0].slice(1, 3), [1, 7]);
});

test('Matrix-Literal und Klammertiefe > 64 → #NAME?, Blatt wächst nicht', () => {
	const r = run([[`={${Array(400).fill(1).join(';')}}`, `={${Array(200).fill(1).join('|')}}`]]);
	assert.deepEqual(r.out[0], [NAME, NAME]);
	assert.deepEqual(r.dims, { width: 2, height: 1 });
	assert.equal(one(`=${'('.repeat(64)}1${')'.repeat(64)}`), 1);
	assert.deepEqual(one(`=${'('.repeat(65)}1${')'.repeat(65)}`), NAME);
	assert.deepEqual(one(`=${'('.repeat(490)}1${')'.repeat(490)}`), NAME);
	assert.deepEqual(one(`=${'WENN(1;'.repeat(200)}1${')'.repeat(200)}`), NAME);
});

test('Bereiche: A:A, 2:2 rechnen; über 50 Zeilen kein Wachstum', () => {
	const r = run([['=SUMME(B:B)', 1], ['=SUMME(B2:B2)+SUMME(1:1)', 2], ['=SUMME(B1:B1000000)', 3]]);
	assert.equal(r.out[0][0], 6);
	assert.equal(r.out[1][0], 9);
	assert.deepEqual(r.out[2][0], NAME);
	assert.deepEqual(r.dims, { width: 2, height: 3 });
});

test('Rechenbudget: volles Blatt mit schweren Formeln < 1 s, gewöhnliche Blätter unberührt', () => {
	const data = (r, c) => ((r * 26 + c) % 97) + 0.5;
	const many = (fn) => { let s = `=${fn}(`; const p = []; while ((s + [...p, 'A1:Z25'].join(';') + ')').length < 1000) p.push('A1:Z25'); return `${s + p.join(';')})`; };
	for (const fn of ['SUMME', 'MEDIAN', 'MODUS.EINF', 'STABW.S', 'ANZAHL2', 'QUARTILE.INKL']) {
		const f = fn === 'QUARTILE.INKL' ? '=QUARTILE.INKL(A1:Z25;1)' : many(fn);
		const r = run(grid((row, c) => (row < 25 ? data(row, c) : f)));
		assert.ok(r.ms < 1000, `${fn}: ${r.ms} ms`);
		assert.ok(r.out.flat().some((v) => v && v.error === '#NAME?'), `${fn}: Budget greift`);
	}
	const corr = run(grid((row, c) => (row < 25 ? data(row, c) : `=KORREL(A1:M25;N1:Z25)+ZÄHLENWENN(A1:Z25;${String.fromCharCode(65 + c)}${row - 24})`)));
	assert.ok(corr.ms < 1000, `KORREL/ZÄHLENWENN: ${corr.ms} ms`);
	// Notentabelle: 45 Schüler × 20 Noten, Zeilenschnitt, je Spalte 5 Kennzahlen, Gesamtstatistik
	const col = (c) => String.fromCharCode(65 + c);
	const stat = ['MITTELWERT', 'MEDIAN', 'STABW.S', 'MIN', 'MAX'];
	const marks = grid((row, c) => (row < 45 ? (c < 20 ? (row + c) % 6 + 1 : c === 20 ? `=MITTELWERT(A${row + 1}:T${row + 1})` : c === 21 ? `=U${row + 1}-MEDIAN(A1:T45)+MODUS.EINF(A1:T45)` : null)
		: c <= 20 ? `=${stat[row - 45]}(${col(c)}1:${col(c)}45)` : null));
	const m = run(marks);
	assert.deepEqual(m.out.flat().filter((v) => v && v.error), [], 'kein Fehler');
	assert.ok(m.ms < 1000, `${m.ms} ms`);
});

test('46 Funktionen = LibreOffice-Referenz (unverändert durch die Prüfung)', () => {
	const rows = FIX.cases.length;
	const cells = Array.from({ length: rows }, (_, i) => [FIX.data[i]?.[0] ?? null, FIX.data[i]?.[1] ?? null, FIX.data[i]?.[2] ?? null, null, FIX.cases[i].de]);
	const out = run(cells).out;
	const bad = FIX.cases.filter((c, i) => {
		const v = out[i][4];
		return typeof c.expected === 'number' ? !(typeof v === 'number' && Math.abs(v - c.expected) <= 1e-9 * Math.max(1, Math.abs(c.expected))) : v !== c.expected;
	}).map((c) => c.de);
	assert.deepEqual(bad, []);
});
