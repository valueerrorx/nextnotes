// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// M8c (M8-Plan §4, Done 1–3a): Rechenkern des Rechenblocks ohne Browser, gegen das gebaute Bündel js/vendor/mathjs/mathjs.min.js.
// Sperren (AST, Aufrufliste, Stubs), Budgets (Zähler, Tiefe, Frist; Zeichenlauf), Angriffsfälle mit Messwerten.
// Aufruf: make -C nextnotes build && node --test tests/nextnotes/node/calc-engine.mjs
import { readFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';
import test from 'node:test';
import assert from 'node:assert/strict';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..', '..');
const APP = join(ROOT, 'nextnotes');
globalThis.NEXTNOTES_MATH_TEST = true;
vm.runInThisContext(readFileSync(join(APP, 'js/vendor/mathjs/mathjs.min.js'), 'utf8'));
const M = vm.runInThisContext('NextNotesMath');
const T = M._test;
const MEASURE = [];

function timed(src, opts) {
	const t = performance.now();
	const r = M.run(src, opts);
	return { r, ms: performance.now() - t };
}
const lines = (src) => M.run(src).lines;
const last = (src) => { const l = lines(src); return l[l.length - 1]; };
const text = (line) => (line.res ? line.res.text ?? String(line.res.value) : null);
const code = (line) => (line.err ? line.err.code : null);
const chain = (n, body) => Array.from({ length: n + 1 }, (_, i) => (i === 0 ? 'f0(x) = x + 1' : body(i))).join('\n');
const fchain = (n) => chain(n, (i) => `f${i}(x) = f${i - 1}(x) + f${i - 1}(x)`);
const gchain = (n) => ['g1(x) = x', ...Array.from({ length: n - 1 }, (_, i) => `g${i + 2}(x) = g${i + 1}(x)`)].join('\n');
function note(name, ms, result) { MEASURE.push(`${name}: ${ms.toFixed(1)} ms → ${result}`); }

test('Done 1: Grundrechnungen, Einheiten, Dezimalkomma', () => {
	const l = lines('a = 3\nb = a^2 + 1\n5 km / 20 min to km/h\nsin(30 deg)\n3,5 * 2\n1/3 + 1/6');
	assert.deepEqual(l.map(text), ['3', '10', '15 km/h', '0.5', '7', '0.5']);
	assert.equal(text(last('x = 2\nf(x) = x^2 - 2\nf(3)')), '7', 'Parameter verdeckt Variable');
	assert.equal(last('# nur Kommentar').empty, true);
	assert.equal(text(last('2 * 3 # Kommentar, mit Komma')), '6');
	assert.equal(text(last('round(2,567; 2)')), '2.57');
	assert.equal(code(last('round(2, 2)')), 'comma');
	assert.equal(text(last('1 < 2')), 'true');
	assert.equal(text(last('t = 4\n2 t')), '8', 'Name verdeckt Einheit t');
	// M8c-fix: ein im Block zugewiesener Name ist im ganzen Block nie Einheit; vor der Zuweisung „unbekannt“, nicht rückwirkend
	const u = lines('f(x) = x * b\nf(2)\nb = 3\nf(2)');
	assert.deepEqual([u[0].err, code(u[1]), text(u[2]), code(u[3])], [{ code: 'unknown', arg: 'b' }, 'unknown', '3', 'unknown'], JSON.stringify(u));
	assert.deepEqual(lines('5 m\nm = 2\n5 m').map((l) => text(l) ?? code(l)), ['unknown', '2', '10']);
	assert.deepEqual(lines('m = 5 m').map(code), ['unknown'], 'eigener Name rechts der Zuweisung');
	assert.deepEqual(lines('5 h\nh(x) = 2 x\nh(3)').map((l) => text(l) ?? code(l) ?? (l.fn ? 'fn' : '')), ['unknown', 'fn', '6'], 'Funktionsname h (Einheit Stunde)');
	assert.equal(text(last('f(x) = x * b\nf(2)')), '2 b', 'nie zugewiesen: Einheit');
	assert.equal(M.run('f(x) = x * b\nb = 3', { fnsOnly: true }).lines[0].err.code, 'unknown', 'Leser wie Inhaber (Zuweisung nach der letzten Funktion)');
});

test('Done 2: abgelehnt mit Fehlerzeile, Instanz unverändert', () => {
	const bad = ['import({x:1})', 'evaluate("2+2")', 'parse("1")', 'compile("1")', 'config({number:"BigNumber"})', 'createUnit("foo")', 'unit("5 m")',
		'format(1)', '[1,2,3]', 'a.b', 'x = evaluate', 'sin = 3', 'evaluate = 1', 'f(x) = f(x-1)'];
	for (const src of bad) assert.ok(code(last(src)), `${src} → ${JSON.stringify(last(src))}`);
	// config aus dem Ausdruck hat nichts geändert
	assert.equal(code(last('config({number:"BigNumber"})')), 'notAllowed');
	assert.equal(typeof T.evaluate('0.1 + 0.2'), 'number');
	assert.equal(last('0.1 + 0.2').res.value, 0.1 + 0.2);
	// wechselseitige Rekursion über Neudefinition
	assert.equal(code(last('f(x) = x\ng(x) = f(x)\nf(x) = g(x)')), 'defined');
	assert.equal(code(lines('g(x) = h(x)\nh(x) = g(x)')[0]), 'unknown');
	for (const src of ['combinations(1e9; 5e8)', '170000!', 'factorial(170000)', 'permutations(1e9; 5)']) {
		const { r, ms } = timed(src);
		note(src, ms, code(r.lines[0]));
		assert.equal(code(r.lines[0]), 'big');
		assert.ok(ms <= 50, `${src} ${ms} ms`);
	}
	// gedeckelte Grenzfälle bleiben schnell
	for (const src of ['combinations(10000; 5000)', '10000!', 'permutations(10000; 10000)']) {
		const { r, ms } = timed(src);
		note(src, ms, text(r.lines[0]) ?? code(r.lines[0]));
		assert.ok(ms <= 50, `${src} ${ms} ms`);
	}
});

test('Done 2a: Aufrufziel nur SymbolNode der Aufrufliste', () => {
	assert.equal(text(last('f(x) = x + 1\nf(1)(2)')), '4', 'implizite Multiplikation f(1)·2');
	assert.equal(code(last('(sin)(1)')), 'notAllowed');
	const one = { type: 'ConstantNode', value: 1 };
	const sin = { type: 'SymbolNode', name: 'sin' };
	assert.throws(() => M.check({ type: 'FunctionNode', fn: { type: 'ParenthesisNode', content: sin }, args: [one] }));
	assert.throws(() => M.check({ type: 'FunctionNode', fn: { type: 'FunctionNode', fn: sin, args: [one] }, args: [one] }));
	// echte Knoten aus dem gesicherten Parser, Ziel umgebaut
	const n = T.parse('sin(1)');
	n.fn = T.parse('(sin)');
	assert.throws(() => M.check(n));
	const m = T.parse('sin(1)');
	m.fn = T.parse('sin(1)');
	assert.throws(() => M.check(m));
	assert.equal(M.check(T.parse('sin(1)')), 'expr');
	// nicht aufrufbar, obwohl im Bündel
	for (const f of ['evaluate', 'parse', 'compile', 'import', 'config', 'createUnit', 'unit', 'number', 'format', 'to', 'typed', 'chain', 'reviver',
		'replacer', 'help', 'add', 'xor', 'zeros', 'identity', 'map', 'apply', 'subset', 'matrix', 'concat', 'gamma', 'size', 'typeOf', 'isNaN']) {
		assert.equal(code(last(`${f}(1)`)), f in T.math ? 'notAllowed' : 'unknown', f);
	}
});

test('Done 2b: Stubs greifen auch ohne check, gesicherte Referenzen funktionieren', () => {
	for (const src of ['evaluate("1")', 'parse("1")', 'compile("1")', 'import({})', 'config({})', 'createUnit("foo")', 'simplify("x")', 'derivative("x", "x")',
		'resolve(1)', 'matrix([1])', 'typed(1)', 'chain(1)', 'help(1)', 'create(1)', 'factory(1)']) {
		// chain/help sind in math.js aus Ausdrücken ohnehin nicht erreichbar (Undefined function)
		assert.throws(() => T.evaluate(src), (e) => /^blocked|^Undefined function/.test(e.message), src);
		assert.throws(() => T.parse(src).compile().evaluate(), (e) => /^blocked|^Undefined function/.test(e.message), src);
	}
	assert.equal(T.evaluate('1+2'), 3);
	assert.equal(T.parse('2*3').compile().evaluate(), 6);
	assert.equal(T.compile('2+5').evaluate(), 7);
});

test('Done 3: Zähler (f0…f20), Tiefe (g1…g60), Frist (Test-Hook)', () => {
	{
		const { r, ms } = timed(fchain(20) + '\nf20(1)\n1 + 1');
		const l = r.lines;
		note('f20(1) (Zähler)', ms, code(l[21]));
		assert.equal(code(l[21]), 'heavy');
		assert.equal(l[22].skip, true);
		assert.ok(ms <= 250, `${ms} ms`);
	}
	{
		let calls = 0;
		T.setOnCall(() => { calls++; });
		const { r, ms } = timed(gchain(60) + '\ng60(1)');
		T.setOnCall(null);
		note('g60(1) (Tiefe)', ms, `${code(r.lines[60])}, ${calls} Aufrufe`);
		assert.equal(code(r.lines[60]), 'heavy');
		assert.ok(calls <= 33 && calls < T.EVAL.calls, `${calls} Aufrufe`);
		assert.equal(text(last(gchain(31) + '\ng31(1)')), '1', 'Tiefe 31 erlaubt');
	}
	{
		let calls = 0;
		T.setOnCall(() => { calls++; const t = performance.now(); while (performance.now() - t < 2); });
		const { r, ms } = timed('h(x) = x\n' + Array.from({ length: 150 }, (_, i) => `y${i} = h(${i})`).join('\n'));
		T.setOnCall(null);
		const errAt = r.lines.findIndex((l) => code(l) === 'heavy');
		note('Frist (2 ms je Aufruf)', ms, `heavy in Zeile ${errAt}, ${calls} Aufrufe`);
		assert.ok(errAt > 0 && calls < T.EVAL.calls, `${errAt} ${calls}`);
		assert.ok(r.lines.slice(errAt + 1).every((l) => l.skip));
		assert.ok(ms <= 250, `${ms} ms`);
	}
	{
		const { r, ms } = timed(Array.from({ length: 200 }, (_, i) => `a${i} = ${i} * 2`).join('\n'));
		note('200 leichte Zeilen', ms, `${r.lines.filter((l) => l.res).length} berechnet`);
		assert.equal(r.lines.filter((l) => l.res).length, 200);
		assert.ok(ms <= 300);
	}
	{
		// eingebaute Funktionen am Deckel (je Zeile ≤ 100 Zeichen, 200 Zeilen ≤ 20 000 Zeichen)
		const heavy = 'sum(combinations(9999;4999);combinations(9999;4999);permutations(9999;9999);10000!)';
		const { r, ms } = timed(Array.from({ length: 200 }, () => heavy).join('\n'));
		note('200 schwere Zeilen (Deckel)', ms, `${r.lines.filter((l) => l.res).length} berechnet, ${r.lines.filter((l) => l.skip).length} nicht berechnet`);
		assert.equal(r.lines.length, 200);
		assert.ok(ms <= 300, `${ms} ms`);
		// Benutzerfunktionen: zweite Zeile sprengt den Zähler, Rest nicht berechnet
		const u = timed(fchain(12) + '\n' + Array.from({ length: 186 }, (_, i) => `y${i} = f12(${i})`).join('\n'));
		const ul = u.r.lines.slice(13);
		note('186 schwere Zeilen (f12)', u.ms, `${ul.filter((l) => l.res).length} berechnet, ${ul.filter((l) => code(l) === 'heavy').length} Aufwand, ${ul.filter((l) => l.skip).length} nicht berechnet`);
		assert.equal(code(ul[1]), 'heavy');
		assert.ok(ul.slice(2).every((l) => l.skip));
		assert.ok(u.ms <= 300, `${u.ms} ms`);
	}
});

// Zeichenlauf wie function-plot: n synchrone Aufrufe, danach Microtask
async function drawRun(fn, n) {
	const t = performance.now();
	let ok = 0;
	for (let i = 0; i < n; i++) if (!Number.isNaN(fn({ x: -10 + (20 * i) / (n - 1) }))) ok++;
	const ms = performance.now() - t;
	await Promise.resolve();
	return { ok, ms };
}

test('Done 3a: Graph – f6 gezeichnet, f7 durch Laufbudget, f40 durch Tiefe', async () => {
	const src = fchain(40);
	const fns = M.run(src, { fnsOnly: true }).fns;
	const by = (name) => fns.find((f) => f.name === name);
	{
		let dead = 0;
		const [fn] = M.plotFn([by('f6')], () => dead++);
		assert.ok(fn);
		const { ok, ms } = await drawRun(fn, 300);
		note('Graph f6 (38 100 Aufrufe)', ms, `${ok}/300 Punkte, dead ${dead}`);
		assert.equal(ok, 300);
		assert.equal(dead, 0);
		assert.ok(ms < 100, `${ms} ms`);
	}
	{
		let dead = 0;
		const [fn] = M.plotFn([by('f7')], () => dead++);
		assert.ok(fn, 'f7 besteht die Vorprüfung bei x = 0');
		const { ok, ms } = await drawRun(fn, 300);
		await Promise.resolve();
		note('Graph f7 (76 500 Aufrufe)', ms, `${ok}/300 Punkte, dead ${dead}`);
		assert.ok(ok < 300 && ok >= 190, `${ok}`);
		assert.equal(dead, 1);
		const again = await drawRun(fn, 300);
		assert.equal(again.ok, 0, 'nach Abbruch keine weiteren Läufe');
	}
	{
		const fn = M.plotFn([by('f40')], () => {});
		note('Graph f40', 0, fn ? 'gezeichnet' : 'Vorprüfung: Tiefe');
		assert.equal(fn, null);
		assert.equal(M.plotFn([by('f1'), by('f40')], () => {}), null, 'eine zu tiefe Funktion verhindert den Graphen');
	}
	{
		// Vorprüfung ist nur Heuristik: billig bei x = 0, teuer sonst → Laufbudget
		const r = M.run(fchain(12) + '\nk(x) = x == 0 ? 1 : f12(x)', { fnsOnly: true });
		let dead = 0;
		const [fn] = M.plotFn([r.fns.find((f) => f.name === 'k')], () => dead++);
		assert.ok(fn);
		const { ok, ms } = await drawRun(fn, 300);
		note('Graph k (x≠0 teuer)', ms, `${ok}/300 Punkte, dead ${dead}`);
		assert.equal(dead, 1);
		assert.ok(ms < 150, `${ms} ms`);
	}
	assert.equal(M.plotFn([{ name: 'h', params: ['a', 'b'], call() {} }]), null, 'nur Funktionen mit einem Parameter');
	{
		// Budget gilt je Lauf über alle Funktionen: 2 × f6 (76 200 Aufrufe) → Abbruch
		let dead = 0;
		const fns2 = M.plotFn([by('f6'), by('f6')], () => dead++);
		const t = performance.now();
		let ok = 0;
		for (const fn of fns2) for (let i = 0; i < 300; i++) if (!Number.isNaN(fn({ x: i / 30 }))) ok++;
		await Promise.resolve();
		note('Graph f6 + f6 (ein Lauf)', performance.now() - t, `${ok}/600 Punkte, dead ${dead}`);
		assert.equal(dead, 1);
		assert.ok(ok < 600);
	}
	{
		// M8c-fix: Vorprüfung mit einem Budget für alle Funktionen: 5 Funktionen à 90 ms → abgelehnt, ≤ 300 ms
		const r = M.run('a(x) = x\nb(x) = x\nc(x) = x\nd(x) = x\ng(x) = x', { fnsOnly: true });
		T.setOnCall(() => { const t = performance.now(); while (performance.now() - t < 90); });
		const t = performance.now();
		const res = M.plotFn(r.fns, () => {});
		const ms = performance.now() - t;
		T.setOnCall(null);
		note('Vorprüfung 5 × 90 ms', ms, res ? 'gezeichnet' : 'abgelehnt');
		assert.equal(res, null);
		assert.ok(ms <= 300, `${ms} ms`);
	}
});

test('Angriffsfälle: Konstruktoren, Prototypen, Namensraum', () => {
	const cases = {
		'constructor': 'notAllowed', '__proto__': 'notAllowed', 'constructor = 1': 'reserved', '__proto__ = 1': 'reserved', 'toString(1)': 'notAllowed',
		'x = import': 'notAllowed', 'createUnit("a")': 'notAllowed', 'createUnit(1)': 'notAllowed', 'reviver(1)': 'notAllowed', 'evaluate(1)': 'notAllowed',
		'f(x) = evaluate': 'notAllowed', 'f(evaluate) = 1': 'reserved', 'f(x, x) = 1': 'comma', 'f(x; x) = 1': 'syntax', 'hasOwnProperty(1)': 'notAllowed',
		'Unit': 'notAllowed', 'math': 'unknown', 'sin.constructor': 'notAllowed', 'sin["constructor"]': 'notAllowed', 'a = {}': 'notAllowed',
		'cos = 1': 'reserved', 'f(x) = x\nf = 2': 'defined', 'f(x) = x\nf': 'notAllowed', 'f(x) = x\ny = f': 'notAllowed', 'f(g) = g(1)': 'notAllowed',
		'f(x) = x\ng(f) = 1': 'defined', 'pi = 3': 'reserved', 'e = 3': 'reserved', 'x = 1\nx = 2': 'defined',
		'f?.(1)': 'notAllowed', 'x\'': 'notAllowed', '1 & 2': 'notAllowed', '1 | 2': 'notAllowed', '1 << 2': 'notAllowed', '~1': 'notAllowed',
		'null': 'syntax', 'i': 'unknown',
	};
	for (const [src, want] of Object.entries(cases)) {
		const t = performance.now();
		const got = code(last(src));
		note(src.replace('\n', '⏎'), performance.now() - t, got);
		assert.equal(got, want, src);
	}
});

test('Angriffsfälle: Matrix, Bereich, Rekursion, Zeichenketten, Einheiten, Unicode, Kommentare', () => {
	const cases = {
		'zeros(1e8)': 'notAllowed', '1:1e9': 'notAllowed', 'x = 1:1e9': 'notAllowed', '[1:1e9]': 'notAllowed', 'sum(1:1e9)': 'notAllowed',
		'f(x) = f(x)': 'unknown', 'f(x) = g(x)': 'unknown', '"abc"': 'string', '"a" + "b"': 'string', 'x = "1,5"': 'string',
		'5 m + 3 s': 'math', 'unit(1)': 'notAllowed', '5 m to s': 'math', '1 to 2': 'math',
		'a = 1; b = 2': 'syntax', 'a = 1\\nb': 'syntax',
	};
	for (const [src, want] of Object.entries(cases)) {
		const t = performance.now();
		const got = code(last(src));
		note(src, performance.now() - t, got);
		assert.equal(got, want, src);
	}
	assert.equal(text(last('α = 2\nα^2')), '4');
	assert.equal(last('undefined').empty, true, 'undefined = leere Zeile');
	assert.deepEqual([text(last('NaN')), text(last('Infinity'))], ['NaN', 'Infinity'], 'Zahl-Literale des Parsers');
	assert.equal(text(last('größe = 3\ngröße * 2')), '6');
	assert.equal(code(last('ｓin(1)')), 'syntax', 'Vollbreite ist kein Name');
	assert.equal(text(last('(1 m)^30')), '1 m^30');
	assert.equal(text(last('2 # sin(1); evaluate("x")')), '2', 'Kommentar wird nicht ausgewertet');
	assert.equal(lines('1\n\n2').length, 3);
	assert.ok(lines('1\r\n2')[0].err || lines('1\r\n2')[0].res, 'CR am Zeilenende');
	// Ergebnisgröße: nur Zahl, Wahrheitswert, Einheit; Text gekürzt
	const big = timed('(1 m)^999999999');
	note('(1 m)^999999999', big.ms, text(big.r.lines[0]) ?? code(big.r.lines[0]));
	assert.ok((text(big.r.lines[0]) || '').length <= M.LIMITS.out);
	const nest = '('.repeat(240) + '1' + ')'.repeat(240);
	const deep = timed(nest);
	note('240 Klammern', deep.ms, text(deep.r.lines[0]) ?? code(deep.r.lines[0]));
	assert.ok(deep.ms < 100);
	const calls = 'sin('.repeat(80) + '1' + ')'.repeat(80);
	const deepCalls = timed(calls);
	note('80 verschachtelte sin', deepCalls.ms, text(deepCalls.r.lines[0]) ?? code(deepCalls.r.lines[0]));
});

test('Grenzen: Zeilen, Länge, Quelltext', () => {
	assert.equal(code(last('1+'.repeat(250) + '1')), 'long');
	assert.equal(M.run(Array(201).fill('1').join('\n')).tooLong, true);
	assert.equal(M.run('1'.repeat(20001)).tooLong, true);
	assert.equal(M.run(Array(200).fill('1').join('\n')).lines.length, 200);
	const r = M.run('a = 1\nf(x) = a * x\ng(x) = 2\n3 + 4', { fnsOnly: true });
	assert.deepEqual(r.fns.map((f) => f.name), ['f', 'g']);
	assert.equal(r.lines[3].skip, true, 'Leser: nach der letzten Funktion nichts berechnet');
});

test('Messwerte', () => {
	console.log(MEASURE.join('\n'));
});
