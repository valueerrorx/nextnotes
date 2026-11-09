// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// M8c-fix (Review M8c r1, Blocker 1): Rechenwarteschlange der Blöcke (js/nextnotes-blocks.js) ohne Browser.
// Jeder Schritt eine eigene Task, Folgeschritte vor dem nächsten Block, Seitenbudget für automatische Schritte,
// Nutzer-Schritte immer, neues Budget nach leerer Schlange; mit dem Rechenkern: viele schwere Rechenblöcke, keine Task > 300 ms.
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
globalThis.window = globalThis;
globalThis.addEventListener = noop;
globalThis.document = { addEventListener: noop, querySelectorAll: () => [], documentElement: {} };
globalThis.MutationObserver = class { observe() {} };
vm.runInThisContext(readFileSync(join(APP, 'js/vendor/mathjs/mathjs.min.js'), 'utf8'));
const M = vm.runInThisContext('NextNotesMath');
vm.runInThisContext(readFileSync(join(APP, 'js/nextnotes-blocks.js'), 'utf8'));
const { queue, PAGE_MS } = window.NextNotesBlocks;

const busy = (ms) => { const t = performance.now(); while (performance.now() - t < ms); };
const tick = () => new Promise((r) => setTimeout(r, 0));
async function idle() {
	// Schlange leer und Budget zurückgesetzt (ein Takt nach dem letzten Schritt)
	const done = new Promise((r) => queue({ user: true, run: r, over: noop }));
	await done;
	for (let i = 0; i < 3; i++) await tick();
}

test('jeder Schritt in einer eigenen Task, Folgeschritte vor dem nächsten Block', async () => {
	await idle();
	const log = [];
	queue({ run: () => { log.push('A'); setTimeout(() => log.push('timer'), 0); queue({ run: () => log.push('A2'), over: noop }); }, over: noop });
	queue({ run: () => log.push('B'), over: noop });
	await idle();
	assert.deepEqual(log, ['A', 'timer', 'A2', 'B']);
});

test('Seitenbudget: automatische Schritte darüber → over(), Nutzer-Schritte immer, Fehler stoppen die Schlange nicht', async () => {
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
	// Neue Seite / Moduswechsel: Schlange war leer → neues Budget
	let again = false;
	queue({ run: () => { again = true; }, over: noop });
	await idle();
	assert.equal(again, true);
});

test('Rechenblöcke mit schweren Ausdrücken (Review a7): keine Task > 300 ms, Summe ≈ Seitenbudget', async () => {
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
