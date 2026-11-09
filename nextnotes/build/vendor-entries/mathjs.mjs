// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// Rechenblock (M8c, M8-Plan §4): eingeschränktes math.js 15.2.0 (Apache-2.0) als Global NextNotesMath = {check, run, plotFn}.
// Drei Schichten: (1) nur Whitelist-Factories, (2) gesicherte parse/compile/evaluate, danach Stubs, (3) AST-Prüfung je Zeile.
// Benutzerfunktionen legt nicht math.js an, sondern ein Wrapper mit Budget (Aufrufe, Tiefe, Frist).
import {
	create, addDependencies, subtractDependencies, multiplyDependencies, divideDependencies, powDependencies, unaryMinusDependencies,
	unaryPlusDependencies, modDependencies, absDependencies, sqrtDependencies, cbrtDependencies, nthRootDependencies, expDependencies,
	logDependencies, log10Dependencies, log2Dependencies, roundDependencies, floorDependencies, ceilDependencies, fixDependencies,
	signDependencies, gcdDependencies, lcmDependencies, factorialDependencies, combinationsDependencies, permutationsDependencies,
	meanDependencies, medianDependencies, stdDependencies, varianceDependencies, sumDependencies, hypotDependencies, sinDependencies,
	cosDependencies, tanDependencies, asinDependencies, acosDependencies, atanDependencies, atan2Dependencies, piDependencies, eDependencies,
	equalDependencies, unequalDependencies, smallerDependencies, largerDependencies, smallerEqDependencies, largerEqDependencies,
	andDependencies, orDependencies, notDependencies, xorDependencies, unitDependencies, toDependencies, numberDependencies,
	evaluateDependencies, formatDependencies, parseDependencies, compileDependencies,
} from 'mathjs';

// Ohne min/max (sonst liest der Parser „20 min“ als Funktion), ohne Matrix-Erzeuger, Zufall, BigNumber-/Bruch-/Komplex-Typ
const math = create({
	addDependencies, subtractDependencies, multiplyDependencies, divideDependencies, powDependencies, unaryMinusDependencies,
	unaryPlusDependencies, modDependencies, absDependencies, sqrtDependencies, cbrtDependencies, nthRootDependencies, expDependencies,
	logDependencies, log10Dependencies, log2Dependencies, roundDependencies, floorDependencies, ceilDependencies, fixDependencies,
	signDependencies, gcdDependencies, lcmDependencies, factorialDependencies, combinationsDependencies, permutationsDependencies,
	meanDependencies, medianDependencies, stdDependencies, varianceDependencies, sumDependencies, hypotDependencies, sinDependencies,
	cosDependencies, tanDependencies, asinDependencies, acosDependencies, atanDependencies, atan2Dependencies, piDependencies, eDependencies,
	equalDependencies, unequalDependencies, smallerDependencies, largerDependencies, smallerEqDependencies, largerEqDependencies,
	andDependencies, orDependencies, notDependencies, xorDependencies, unitDependencies, toDependencies, numberDependencies,
	evaluateDependencies, formatDependencies, parseDependencies, compileDependencies,
}, { number: 'number', predictable: true });

// Alle Mitglieder jetzt erzeugen (lazy Factories), damit keine später einen Stub als Abhängigkeit bekommt
for (const k of Object.keys(math)) void math[k];

// (2) Eigene Referenzen nur im Modul, danach ein einziger Import mit Stubs und gedeckelten Funktionen
const parse = math.parse, compile = math.compile, evaluate = math.evaluate, format = math.format, Unit = math.Unit;
const CAP = 10000;
class CalcError extends Error {
	constructor(code, arg) { super(code + (arg ? ': ' + arg : '')); this.code = code; this.arg = arg || ''; }
}
function stub(name) { return function () { throw new CalcError('blocked', name); }; }
function capped(fn) {
	return function (...args) {
		for (const a of args) if (typeof a === 'number' && Math.abs(a) > CAP) throw new CalcError('big', String(CAP));
		return fn(...args);
	};
}
const STUBS = ['import', 'createUnit', 'reviver', 'replacer', 'evaluate', 'parse', 'compile', 'config', 'typed', 'chain', 'simplify',
	'derivative', 'resolve', 'matrix', 'help', 'create', 'factory'];
const imports = { factorial: capped(math.factorial), combinations: capped(math.combinations), permutations: capped(math.permutations) };
for (const n of STUBS) imports[n] = stub(n);
math.import(imports, { override: true });

// (3) Aufrufbare Funktionen (abschließend), Operatoren, Vergleiche, Konstanten
function frozen(list) { return Object.freeze(Object.assign(Object.create(null), Object.fromEntries(list.map((n) => [n, true])))); }
const has = (set, k) => Object.hasOwn(set, k);
const CALLS = frozen(['abs', 'sqrt', 'cbrt', 'nthRoot', 'exp', 'log', 'log10', 'log2', 'round', 'floor', 'ceil', 'fix', 'sign', 'gcd', 'lcm', 'mod',
	'pow', 'hypot', 'factorial', 'combinations', 'permutations', 'mean', 'median', 'std', 'variance', 'sum', 'sin', 'cos', 'tan', 'asin', 'acos',
	'atan', 'atan2']);
const OPS = frozen(['add', 'subtract', 'multiply', 'divide', 'pow', 'mod', 'unaryMinus', 'unaryPlus', 'factorial', 'equal', 'unequal', 'smaller',
	'larger', 'smallerEq', 'largerEq', 'and', 'or', 'not', 'xor', 'to']);
const RELS = frozen(['equal', 'unequal', 'smaller', 'larger', 'smallerEq', 'largerEq']);
const CONSTS = frozen(['pi', 'e']);

// Grenzen: Quelltext, Auswertung (Zeilen), Zeichenlauf (Graph)
const LIMITS = Object.freeze({ src: 20000, lines: 200, line: 500, out: 200 });
const EVAL = Object.freeze({ calls: 10000, depth: 32, ms: 150 });
const PLOT = Object.freeze({ calls: 50000, depth: 32, ms: 100 });
const HEAVY = new CalcError('heavy');
const now = () => performance.now();
let budget = null; // laufendes Budget (Auswertung oder Zeichenlauf), synchron
let onCall = null; // Test-Hook (nur mit NEXTNOTES_MATH_TEST)

function newBudget(lim) { return { calls: 0, depth: 0, deadline: now() + lim.ms, lim, dead: false }; }

// Name für Zuweisung/Parameter: nicht im math.js-Namensraum, keine Funktion/Konstante der Listen, noch nicht vergeben
function checkName(name, env) {
	if (typeof name !== 'string' || name === '' || name in math || has(CALLS, name) || has(CONSTS, name)) throw new CalcError('reserved', String(name));
	if (env.defined.has(name)) throw new CalcError('defined', name);
}

// AST-Prüfung eines Ausdrucks; env = {defined: Map(name → 'var'|'fn'), params: Set|null, assigned: Set|null}
// assigned: alle im Block zugewiesenen Namen; sie sind nie Einheit, vor ihrer Zuweisung also „unbekannt“
function walk(n, env, depth) {
	if (!n || typeof n !== 'object' || depth > 256) throw new CalcError('syntax');
	switch (n.type) {
		case 'ConstantNode':
			if (typeof n.value !== 'number' && typeof n.value !== 'boolean') throw new CalcError(typeof n.value === 'string' ? 'string' : 'syntax');
			return;
		case 'SymbolNode': {
			const name = n.name;
			if (env.params && env.params.has(name)) return;
			if (env.defined.get(name) === 'var' || has(CONSTS, name)) return;
			if (!env.defined.has(name) && !(name in math) && !(env.assigned && env.assigned.has(name)) && Unit.isValuelessUnit(name)) return;
			throw new CalcError(env.defined.has(name) || name in math ? 'notAllowed' : 'unknown', String(name));
		}
		case 'ParenthesisNode':
			walk(n.content, env, depth + 1);
			return;
		case 'OperatorNode':
			if (!has(OPS, n.fn)) throw new CalcError('notAllowed', String(n.op));
			for (const a of n.args) walk(a, env, depth + 1);
			return;
		case 'ConditionalNode':
			walk(n.condition, env, depth + 1);
			walk(n.trueExpr, env, depth + 1);
			walk(n.falseExpr, env, depth + 1);
			return;
		case 'RelationalNode':
			for (const c of n.conditionals) if (!has(RELS, c)) throw new CalcError('notAllowed', String(c));
			for (const p of n.params) walk(p, env, depth + 1);
			return;
		case 'FunctionNode': {
			// Aufrufziel nur ein SymbolNode aus der Aufrufliste oder eine früher definierte Funktion (nicht die SymbolNode-Regel)
			const fn = n.fn;
			if (!fn || fn.type !== 'SymbolNode' || n.optional) throw new CalcError('notAllowed', 'call');
			if (!has(CALLS, fn.name) && env.defined.get(fn.name) !== 'fn') {
				throw new CalcError(fn.name in math || env.defined.has(fn.name) || (env.params && env.params.has(fn.name)) ? 'notAllowed' : 'unknown', String(fn.name));
			}
			for (const a of n.args) walk(a, env, depth + 1);
			return;
		}
		default:
			throw new CalcError('notAllowed', String(n.type));
	}
}

// Anweisung einer Zeile: Zuweisung an Symbol, Funktionsdefinition oder Ausdruck
function check(node, defined, assigned) {
	const env = { defined: defined || new Map(), params: null, assigned: assigned || null };
	if (node && node.type === 'AssignmentNode') {
		if (!node.object || node.object.type !== 'SymbolNode' || node.index) throw new CalcError('notAllowed', 'assign');
		checkName(node.object.name, env);
		walk(node.value, env, 0);
		return 'var';
	}
	if (node && node.type === 'FunctionAssignmentNode') {
		checkName(node.name, env);
		const params = new Set();
		// Parameter dürfen Variablen verdecken (lokal), aber keine Funktion und keinen reservierten Namen
		for (const p of node.params) {
			if (typeof p !== 'string' || p in math || has(CALLS, p) || has(CONSTS, p)) throw new CalcError('reserved', String(p));
			if (params.has(p) || p === node.name || env.defined.get(p) === 'fn') throw new CalcError('defined', p);
			params.add(p);
		}
		walk(node.expr, { defined: env.defined, params, assigned: env.assigned }, 0);
		return 'fn';
	}
	walk(node, env, 0);
	return 'expr';
}

// Dezimalkomma und ; (wie Tabellenblock): , zwischen Ziffern → ., ; → , ; anderes , → Fehler; ab # Kommentar unverändert
function prep(line) {
	let out = '';
	for (let i = 0; i < line.length; i++) {
		const c = line[i];
		if (c === '#') return out + line.slice(i);
		if (c === ',') {
			if (!(/[0-9]/.test(line[i - 1] || '') && /[0-9]/.test(line[i + 1] || ''))) throw new CalcError('comma');
			out += '.';
		} else out += c === ';' ? ',' : c;
	}
	return out;
}

// Gültigkeitsbereich eines Funktionsrumpfs: Parameter vor den Namen der Auswertung, nur lesend
class FnScope {
	constructor(parent, names, values) { this.p = parent; this.n = names; this.v = values; }
	has(k) { return this.n.includes(k) || this.p.has(k); }
	get(k) { const i = this.n.indexOf(k); return i >= 0 ? this.v[i] : this.p.get(k); }
	set() { throw new CalcError('notAllowed', 'assign'); }
	keys() { return new Set([...this.n, ...this.p.keys()]).values(); }
}

// Wrapper einer Benutzerfunktion: Budget prüfen (Zähler, Tiefe, Frist), Tiefe auch bei Fehler zurück
function userFn(name, params, code, scope) {
	return function (...args) {
		const b = budget;
		if (!b || b.dead) throw HEAVY;
		if (++b.calls > b.lim.calls || b.depth >= b.lim.depth || now() > b.deadline) { b.dead = true; throw HEAVY; }
		if (args.length !== params.length) throw new CalcError('args', name);
		if (onCall) onCall();
		b.depth++;
		try {
			return code.evaluate(new FnScope(scope, params, args));
		} finally {
			b.depth--;
		}
	};
}

function errorOf(e) {
	if (e instanceof CalcError) return { code: e.code, arg: e.arg };
	if (e instanceof RangeError) return { code: 'heavy', arg: '' }; // Stapelüberlauf
	return { code: 'math', arg: String((e && e.message) || e).replace(/\s+/g, ' ').slice(0, LIMITS.out) };
}

// Ergebnis → {value, text} mit Dezimalpunkt (Anzeige setzt die UI); nur Zahl, Wahrheitswert, Einheit
const FMT = { precision: 10, lowerExp: -9, upperExp: 15 };
function result(v) {
	if (typeof v === 'boolean') return { kind: 'bool', value: v };
	let text;
	if (typeof v === 'number') text = format(v, FMT);
	else if (v instanceof Unit) text = format(v, FMT).replace(/ \/ /g, '/');
	else throw new CalcError('type');
	if (text.length > LIMITS.out) throw new CalcError('type');
	return typeof v === 'number' ? { kind: 'num', value: v, text } : { kind: 'unit', text };
}

/**
 * Alle Zeilen auswerten (eine Auswertung = ein Budget). opts.fnsOnly: nur bis zur letzten Funktionsdefinition (Leser, Graph).
 * Rückgabe {lines: [{empty}|{fn, label}|{res}|{err:{code,arg}}|{skip}], fns: [{name, params, call}]}
 */
function run(src, opts) {
	opts = opts || {};
	src = String(src);
	const lines = src.split('\n');
	if (Array.from(src).length > LIMITS.src || lines.length > LIMITS.lines) return { lines: [], fns: [], tooLong: true };
	const parsed = lines.map((line) => {
		if (Array.from(line).length > LIMITS.line) return { err: { code: 'long', arg: String(LIMITS.line) } };
		try {
			const node = parse(prep(line));
			if (node.type === 'ConstantNode' && node.value === undefined) return { empty: true };
			return { node };
		} catch (e) {
			return { err: e instanceof CalcError ? errorOf(e) : { code: 'syntax', arg: '' } };
		}
	});
	let last = parsed.length - 1;
	if (opts.fnsOnly) {
		while (last >= 0 && !(parsed[last].node && parsed[last].node.type === 'FunctionAssignmentNode')) last--;
	}
	// Namen aller Zuweisungen im Block vorab (auch nach der letzten Funktion): gleiche Bedeutung für Inhaber und Leser
	const assigned = new Set();
	for (const p of parsed) {
		const n = p.node;
		if (n && n.type === 'AssignmentNode' && n.object && n.object.type === 'SymbolNode') assigned.add(n.object.name);
		else if (n && n.type === 'FunctionAssignmentNode') assigned.add(n.name);
	}
	const defined = new Map();
	const scope = new Map();
	const fns = [];
	const out = [];
	const prev = budget;
	const b = budget = newBudget(EVAL);
	let stopped = false; // Budget erschöpft: erste offene Zeile zeigt den Grund, alle weiteren „nicht berechnet“
	try {
		for (let i = 0; i < parsed.length; i++) {
			const p = parsed[i];
			if (p.empty) { out.push(p); continue; }
			if (i > last) { out.push({ skip: true }); continue; }
			if (stopped) { out.push({ skip: true }); continue; }
			if (b.dead || now() > b.deadline) { stopped = b.dead = true; out.push({ err: { code: 'heavy', arg: '' } }); continue; }
			if (!p.node) { out.push(p); continue; }
			try {
				const kind = check(p.node, defined, assigned);
				const n = p.node;
				if (kind === 'fn') {
					const call = userFn(n.name, n.params.slice(), n.expr.compile(), scope);
					scope.set(n.name, call);
					defined.set(n.name, 'fn');
					fns.push({ name: n.name, params: n.params.slice(), call });
					out.push({ fn: true, label: n.name + '(' + n.params.join('; ') + ')' });
				} else {
					const v = (kind === 'var' ? n.value : n).compile().evaluate(scope);
					if (b.dead) throw HEAVY;
					const r = result(v);
					if (kind === 'var') { scope.set(n.object.name, v); defined.set(n.object.name, 'var'); }
					out.push({ res: r });
				}
			} catch (e) {
				if (b.dead || e === HEAVY || e instanceof RangeError) stopped = b.dead = true;
				out.push({ err: stopped ? { code: 'heavy', arg: '' } : errorOf(e) });
			}
		}
	} finally {
		budget = prev;
	}
	return { lines: out, fns };
}

/**
 * Funktionen für function-plot (fn als JS-Funktion, nie Text), gemeinsames Budget je Zeichenlauf über alle Funktionen:
 * der erste Aufruf ohne Lauf startet es, ein Microtask beendet den Lauf. Überschreitung → NaN bis Lauf-Ende, danach onDead()
 * und nur noch NaN. Vorprüfung (Heuristik): Probeaufruf je Funktion bei x = 0, alle unter einem Budget; sprengt er es → null.
 * Vorprüfung und Zeichenlauf gehören in getrennte Tasks (Aufrufer).
 */
function plotFn(list, onDead) {
	if (!Array.isArray(list) || list.length === 0 || list.some((f) => !f || f.params.length !== 1)) return null;
	const prev = budget;
	const probe = budget = newBudget(PLOT);
	try {
		for (const f of list) {
			try { f.call(0); } catch (e) { /* Wert egal, nur Budget */ }
			if (probe.dead) return null;
		}
	} finally {
		budget = prev;
	}
	let run = null;
	let dead = false;
	return list.map((f) => function (scope) {
		if (dead) return NaN;
		if (!run) {
			const r = run = newBudget(PLOT);
			queueMicrotask(() => {
				run = null;
				if (r.dead && !dead) { dead = true; if (onDead) onDead(); }
			});
		}
		if (run.dead) return NaN;
		const prevB = budget;
		budget = run;
		try {
			const v = f.call(scope && typeof scope.x === 'number' ? scope.x : NaN);
			return Number.isFinite(v) ? v : NaN; // ±Infinity bricht function-plot 1.26.0 (Lücke statt Wert)
		} catch (e) {
			return NaN;
		} finally {
			budget = prevB;
		}
	});
}

// Tests (tests/nextnotes/node/calc-engine.mjs): gesicherte Referenzen und Hook nur, wenn vor dem Laden gesetzt
const _test = globalThis.NEXTNOTES_MATH_TEST === true
	? Object.freeze({ parse, compile, evaluate, math, EVAL, PLOT, setOnCall(f) { onCall = f; } }) : undefined;
export { check, run, plotFn, LIMITS, _test };
