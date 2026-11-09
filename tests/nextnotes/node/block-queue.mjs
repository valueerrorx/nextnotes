// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// M8c-fix (Review M8c r1, Blocker 1): Rechenwarteschlange der Blöcke (js/nextnotes-blocks.js) ohne Browser.
// Jeder Schritt eine eigene Task, Folgeschritte vor dem nächsten Block, Seitenbudget für automatische Schritte,
// Nutzer-Schritte immer, Budget je Notiz (M8-nach: neu nur bei Notizwechsel oder Klick, nicht bei leerer Schlange oder Sperrwechsel);
// mit dem Rechenkern: viele schwere Rechenblöcke, keine Task > 300 ms.
// Aufruf: make -C nextnotes build && node --test tests/nextnotes/node/block-queue.mjs
import { readFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';
import test from 'node:test';
import assert from 'node:assert/strict';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..', '..');
const APP = join(ROOT, 'nextnotes');
// Minimale Browser-Umgebung für das Laden von nextnotes-blocks.js (Hauptkontext, keine Notiz im DOM)
const noop = () => {};
let entries = []; // angezeigte Notizen (.noteentry)
const el = () => ({ addEventListener(t, fn) { (this.on ||= {})[t] = fn; }, setAttribute: noop, remove: noop, appendChild: noop });
globalThis.window = globalThis;
globalThis.addEventListener = noop;
globalThis.document = { addEventListener: noop, querySelectorAll: (s) => (s === '.noteentry' ? entries : []), documentElement: {}, createElement: el };
globalThis.MutationObserver = class { observe() {} disconnect() {} takeRecords() { return []; } };
window.poznoteAssetUrl = (p) => p;
window.poznoteEnsureScript = window.poznoteEnsureMathjs = window.poznoteEnsureFunctionPlot = () => Promise.resolve();
vm.runInThisContext(readFileSync(join(APP, 'js/vendor/mathjs/mathjs.min.js'), 'utf8'));
const M = vm.runInThisContext('NextNotesMath');
vm.runInThisContext(readFileSync(join(APP, 'js/nextnotes-blocks.js'), 'utf8'));
const { queue, scan, PAGE_MS } = window.NextNotesBlocks;

// Notiz mit Rechenblöcken (Graph, also auch lesend eingehängt); edit = Sperre bei mir
function note(id, hosts = []) {
	const entry = { nodeType: 1, isContentEditable: true, hasAttribute: () => false, querySelectorAll: () => hosts,
		getAttribute: (a) => ({ 'data-note-type': 'note', 'data-note-id': String(id) })[a] ?? null };
	for (const h of hosts) h.closest = (s) => (s === '.noteentry' ? entry : null);
	return entry;
}
function calcHost() {
	return { nodeType: 1, isConnected: true, shadowRoot: null, parentElement: { closest: () => null },
		hasAttribute: (a) => a === 'data-nn-calc', getAttribute: (a) => (a === 'data-nn-calc' ? '{"src":"κ(x)=x","graph":1}' : null),
		setAttribute: noop, replaceChildren: noop,
		attachShadow() { this.shadowRoot = { replaceChildren: (...c) => { this.ui = c; }, contains: () => false }; return this.shadowRoot; } };
}
function open(...list) { entries = list; scan(); }

const busy = (ms) => { const t = performance.now(); while (performance.now() - t < ms); };
const tick = () => new Promise((r) => setTimeout(r, 0));
async function idle() {
	// Schlange leer und Budget zurückgesetzt (ein Takt nach dem letzten Schritt)
	const done = new Promise((r) => queue({ user: true, run: r, over: noop }));
	await done;
	for (let i = 0; i < 3; i++) await tick();
}

test('jeder Schritt in einer eigenen Task, Folgeschritte vor dem nächsten Block', async () => {
	open(note(1));
	await idle();
	const log = [];
	queue({ run: () => { log.push('A'); setTimeout(() => log.push('timer'), 0); queue({ run: () => log.push('A2'), over: noop }); }, over: noop });
	queue({ run: () => log.push('B'), over: noop });
	await idle();
	assert.deepEqual(log, ['A', 'timer', 'A2', 'B']);
});

test('Seitenbudget: automatische Schritte darüber → over(), Nutzer-Schritte immer, Fehler stoppen die Schlange nicht', async () => {
	open(note(2));
	await idle();
	const ran = [], over = [];
	for (let i = 0; i < 6; i++) queue({ run: () => { ran.push(i); busy(300); }, over: () => over.push(i) });
	queue({ run: () => { throw new Error('Testfehler'); }, over: () => { throw new Error('Testfehler'); } });
	queue({ user: true, run: () => ran.push('user'), over: () => over.push('user') });
	const err = console.error;
	console.error = noop;
	await idle();
	console.error = err;
	assert.equal(PAGE_MS, 1000);
	assert.deepEqual(ran, [0, 1, 2, 3, 'user'], `über ${PAGE_MS} ms: Rest als Fallback`);
	assert.deepEqual(over, [4, 5]);
	// Leere Schlange und dieselbe Notiz (auch neu eingelesen): kein neues Budget
	const again = [];
	queue({ run: () => again.push('leer'), over: noop });
	await idle();
	open(note(2));
	queue({ run: () => again.push('dieselbe'), over: noop });
	await idle();
	// Notizwechsel (auch über eine kurz leere Seite): neues Budget
	open();
	open(note(3));
	queue({ run: () => again.push('neu'), over: noop });
	await idle();
	assert.deepEqual(again, ['neu']);
});

test('Sperrwechsel alle 3 s über 12 s: kein neues Budget, Klick und Notizwechsel schon (Review M8c r2, Hinweis 1)', async () => {
	let ran = 0, over = 0, work = 0;
	window.NextNotesCalc = { mount(box, o) {
		busy(100); work += 100;
		o.queue(() => { busy(100); work += 100; ran++; }, () => over++);
		return { destroy: noop, focus: noop, handleEvent: noop, fallback: () => ({}) };
	} };
	const hosts = Array.from({ length: 4 }, calcHost);
	const entry = note(4, hosts);
	open(entry);
	await idle();
	await idle();
	assert.equal(work, 800, 'erste Anzeige: 4 Blöcke gerechnet');
	// note-edit-lock prüft alle 3 s: Modus edit ↔ view, jeder Block wird neu eingehängt
	for (let t = 3; t <= 12; t += 3) {
		entry.isContentEditable = !entry.isContentEditable;
		scan();
		await idle();
		await idle();
	}
	assert.ok(work <= PAGE_MS + 200, `12 s Sperrwechsel: ${work} ms automatisch gerechnet`);
	const button = hosts[3].ui?.find((c) => c.on?.click);
	assert.ok(button, 'über dem Budget: Knopf statt UI');
	// Klick: dieser Block rechnet, die Seite bekommt neues Budget
	const before = work;
	button.on.click({ stopPropagation: noop });
	await idle();
	await idle();
	assert.equal(work - before, 200, 'Klick rechnet den Block');
	const after = work;
	entry.isContentEditable = !entry.isContentEditable;
	scan();
	await idle();
	await idle();
	assert.ok(work - after >= 200, 'nach dem Klick: wieder Budget');
	// Notizwechsel: neues Budget
	const other = note(5, Array.from({ length: 2 }, calcHost));
	const w = work;
	open(other);
	await idle();
	await idle();
	assert.equal(work - w, 400, 'andere Notiz: neues Budget');
});

test('Rechenblöcke mit schweren Ausdrücken (Review a7): keine Task > 300 ms, Summe ≈ Seitenbudget', async () => {
	open(note(6));
	await idle();
	const h = 'permutations(10000;10000)';
	const L = [h];
	while ((L.join('+') + '+' + h).length < 480) L.push(h);
	const src = ['p(x)=' + L.join('+') + '+x', 'q(x)=' + Array.from({ length: 30 }, () => 'p(x)').join('+'), 'q(1)+q(1)', 'q(1)+q(1)', 'q(1)+q(1)', 'k(x)=q(x)'].join('\n');
	const tasks = [];
	let mounted = 0, fallback = 0;
	const timed = (fn) => () => { const t = performance.now(); fn(); tasks.push(performance.now() - t); };
	// wie nextnotes-calc.js: Einhängen (Auswertung), Vorprüfung, Zeichenlauf je als eigener Schritt
	for (let i = 0; i < 12; i++) {
		queue({
			over: () => fallback++,
			run: timed(() => {
				mounted++;
				const fns = M.run(src).fns.filter((f) => f.params.length === 1).slice(0, 5);
				queue({ over: noop, run: timed(() => {
					const calls = M.plotFn(fns, noop);
					if (calls) queue({ over: noop, run: timed(() => { for (const fn of calls) for (let x = 0; x < 300; x++) fn({ x }); }) });
				}) });
			}),
		});
	}
	await idle();
	const max = Math.max(...tasks), sum = tasks.reduce((a, b) => a + b, 0);
	console.log(`12 Blöcke: ${mounted} gerechnet, ${fallback} Fallback, ${tasks.length} Tasks, max ${max.toFixed(0)} ms, Summe ${sum.toFixed(0)} ms`);
	assert.ok(max <= 300, `max ${max} ms`);
	assert.ok(sum <= PAGE_MS + 300, `Summe ${sum} ms`);
	assert.ok(mounted >= 1 && fallback >= 1 && mounted + fallback === 12);
});

test('Funktionsdefinition mit Unicode-Namen lädt den Graphen vorab (Review M8c r2, Hinweis 1)', async () => {
	const loaded = [];
	window.poznoteEnsureFunctionPlot = () => { loaded.push(1); return Promise.resolve(); };
	window.NextNotesCalc = { mount: () => ({ destroy: noop, focus: noop, handleEvent: noop, fallback: () => ({}) }) };
	const h = calcHost();
	open(note(7, [h]));
	await idle();
	assert.equal(loaded.length, 1, 'κ(x)= erkannt');
	window.poznoteEnsureFunctionPlot = () => Promise.resolve();
	open();
});
