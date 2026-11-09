// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// M8c (SPEC §4.7.10, M8-Plan §4): Rechenblock einfügen (Werkzeugleiste, /Rechnen), rechnen, Neuladen, abgelehnte Ausdrücke,
// Aufwandsgrenzen im Browser (Zähler f20, Tiefe g60, 200 Zeilen; Long Tasks), Graph bei Inhaber und Leser (f6 gezeichnet,
// f7 Laufbudget, f40 Tiefe), Hell/Dunkel, Tastatur-Isolation (R1), Sicherheit, Markdown/Suche, Druck, Austeilen, Mobil/Tablet.
// 0 CSP/Konsole je Abschnitt. Rechenkern ohne Browser: tests/nextnotes/node/calc-engine.mjs
import { runSpec, KEY_DOC_END } from './lib/harness.mjs';
import { apiClient } from './lib/api.mjs';
import { createTeam, share } from './lib/docker.mjs';

const RUN = `${process.argv[3]}${Date.now().toString(36)}`;
const A = process.env.NN_USER;
const B = process.env.NN_USER2;

const esc = (s) => s.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
// Block-HTML wie der Client (Ergebnisse optional)
function calcHtml(src, outs = [], graph = 1, extra = '') {
	const lines = src.split('\n').map((l, i) => `<p class="nn-calc-line"><span class="nn-calc-in">${esc(l)}</span> <span class="nn-calc-out">${esc(outs[i] ?? '')}</span></p>`).join('');
	return `<div class="nn-calc" data-nn-calc="${esc(JSON.stringify({ v: 1, src, graph }).replace(/}$/, extra + '}'))}" contenteditable="false">${lines}</div>`;
}
// Kette mit zwei Parametern, nur die letzte Funktion hat einen (einzige Kurve im Graphen): Aufrufe von fN = 2^(N+1) − 1
function chain2(n) {
	const out = ['h0(x; y) = x + 1'];
	for (let i = 1; i < n; i++) out.push(`h${i}(x; y) = h${i - 1}(x; y) + h${i - 1}(x; y)`);
	out.push(`f${n}(x) = h${n - 1}(x; 0) + h${n - 1}(x; 0)`);
	return out.join('\n');
}
// Review M8c r1 (a7): schwerer Block, je Block ~250 ms Rechenzeit (Auswertung + Vorprüfung)
function heavyBlock() {
	const h = 'permutations(10000;10000)';
	const L = [h];
	while ((L.join('+') + '+' + h).length < 480) L.push(h);
	return ['p(x)=' + L.join('+') + '+x', 'q(x)=' + Array.from({ length: 30 }, () => 'p(x)').join('+'), 'q(1)+q(1)', 'q(1)+q(1)', 'q(1)+q(1)', 'k(x)=q(x)'].join('\n');
}
const fchain = (n) => ['f0(x) = x + 1', ...Array.from({ length: n }, (_, i) => `f${i + 1}(x) = f${i}(x) + f${i}(x)`)].join('\n');
const gchain = (n) => ['g1(x) = x', ...Array.from({ length: n - 1 }, (_, i) => `g${i + 2}(x) = g${i + 1}(x)`)].join('\n');

await runSpec(import.meta.url, async (ctx, page, context, browser) => {
	const api = apiClient(ctx, page.request);
	const content = async (id, c = api) => (await c.must('GET', `/notes/${id}`)).note?.content ?? '';
	const waitFor = async (fn, ms = 10000) => {
		for (const end = Date.now() + ms; Date.now() < end; await page.waitForTimeout(250)) if (await fn()) return true;
		return fn();
	};
	const libRequests = (since) => ctx.requests.slice(since.q).filter((r) => /mathjs|function-plot|nextnotes-calc/.test(r.url));
	const calcJson = (html) => {
		const m = /data-nn-calc="([^"]*)"/.exec(html) ?? /data-nn-calc='([^']*)'/.exec(html);
		if (!m) return null;
		return JSON.parse(m[1].replace(/&quot;/g, '"').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&'));
	};
	// Long Tasks je Seite (vor allen Skripten registriert)
	const watchLongTasks = (c) => c.addInitScript(() => {
		window.__nnLong = [];
		try {
			new PerformanceObserver((l) => { for (const e of l.getEntries()) window.__nnLong.push(Math.round(e.duration)); }).observe({ type: 'longtask', buffered: true });
		} catch (e) { /* ohne Long-Task-API */ }
	});
	await watchLongTasks(context);
	const longTasks = (p) => p.evaluate(() => window.__nnLong.splice(0));
	const live = (p, id) => p.waitForFunction((n) => {
		const ui = document.querySelector(`#entry${n} .nn-calc`)?.shadowRoot?.querySelector('.nn-block-ui');
		return ui && !ui.hidden && ui.querySelector('.nn-calc-src');
	}, id, { timeout: 15000 }).then(() => true, () => false);
	const outs = (p, id) => p.evaluate((n) => [...(document.querySelector(`#entry${n} .nn-calc`)?.shadowRoot?.querySelectorAll('.nn-calc-res .nn-calc-out') ?? [])].map((o) => o.textContent), id);
	const outsAfter = async (p, id, i, re, ms = 5000) => {
		let o = [];
		for (const end = Date.now() + ms; Date.now() < end; await p.waitForTimeout(100)) { o = await outs(p, id); if (re.test(o[i] ?? '')) return o; }
		return o;
	};
	const graph = (p, id) => p.evaluate((n) => {
		const r = document.querySelector(`#entry${n} .nn-calc`)?.shadowRoot;
		const plot = r?.querySelector('.nn-calc-plot');
		return { paths: r ? r.querySelectorAll('.nn-calc-chart path.line').length : 0, svg: !!r?.querySelector('.nn-calc-chart svg'), note: plot?.querySelector('.nn-calc-note')?.textContent ?? '', ta: !!r?.querySelector('textarea'), slot: !!r?.querySelector('slot') };
	}, id);
	const waitGraph = async (p, id, pred, ms = 15000) => {
		let g = null;
		for (const end = Date.now() + ms; Date.now() < end; await p.waitForTimeout(200)) { g = await graph(p, id); if (pred(g)) return g; }
		return g;
	};
	// Zustand von 12 Blöcken: eingehängt (UI), über dem Seitenbudget (Knopf), noch offen
	const blockStates = (p, id) => p.evaluate((n) => [...document.querySelectorAll(`#entry${n} .nn-calc`)].map((h) => {
		const r = h.shadowRoot;
		if (r?.querySelector('.nn-block-deferred')) return 'deferred';
		return r?.querySelector('.nn-block-ui') ? 'live' : 'open';
	}), id);
	const settled = async (p, id, ms = 20000) => {
		let st = [];
		for (const end = Date.now() + ms; Date.now() < end; await p.waitForTimeout(250)) { st = await blockStates(p, id); if (st.length && !st.includes('open')) break; }
		await p.waitForTimeout(800);
		return blockStates(p, id);
	};
	const ta = (p, id) => p.locator(`#entry${id} .nn-calc textarea.nn-calc-src`).first();
	const go = async (p, url) => {
		await p.waitForFunction(() => !(window.hasUnsavedChanges && window.hasUnsavedChanges(window.noteid)), null, { timeout: 20000 }).catch(() => {});
		await p.goto(url, { waitUntil: 'networkidle' });
	};
	const visibleMenus = (p) => p.evaluate(() => [...document.querySelectorAll('.slash-command-menu, .emoji-autocomplete-menu, .mention-autocomplete-menu, .nn-blocks-menu')]
		.filter((el) => el.getClientRects().length > 0 && getComputedStyle(el).visibility !== 'hidden' && getComputedStyle(el).display !== 'none').map((el) => el.className));

	const WS = await api.workspace(`Rechnen ${RUN}`);
	const id = await api.note(WS, `Rechnung ${RUN}`, null, `<p>Start ${RUN}</p><p>Zweiter Absatz</p>`);
	const url = (n, ws = WS) => ctx.appUrl(`index.php?workspace=${encodeURIComponent(ws)}&note=${n}`);
	const entry = page.locator(`#entry${id}`);

	// 1. Laden ohne Rechenkern; Werkzeugleiste → Rechnen; Done 1
	let m = ctx.mark();
	await go(page, url(id));
	await ctx.selfTest(page);
	ctx.ok(libRequests(m).length === 0, 'Notiz ohne Rechenblock lädt keinen Rechenkern', libRequests(m).map((r) => r.url).join(' | '));
	await entry.locator('p').first().click();
	await page.keyboard.press('End');
	await page.locator(`#note${id} .btn-nn-blocks`).click();
	const menuText = await page.locator('.nn-blocks-menu').innerText();
	ctx.ok(/Rechnen/.test(menuText), 'Werkzeugleisten-Menü: „Rechnen“', menuText.replace(/\s+/g, ' '));
	await page.locator('.nn-blocks-menu [data-nn-block="nn-calc"]').click();
	ctx.ok(await live(page, id), 'Rechenblock eingehängt (Shadow DOM)');
	ctx.ok(libRequests(m).some((r) => /mathjs\.min\.js/.test(r.url)) && !libRequests(m).some((r) => /function-plot/.test(r.url)), 'math.js lädt beim ersten Block, function-plot noch nicht');
	ctx.ok(await page.evaluate(() => { let a = document.activeElement; while (a?.shadowRoot?.activeElement) a = a.shadowRoot.activeElement; return a?.classList.contains('nn-calc-src'); }), 'Fokus im Textfeld nach dem Einfügen');
	await page.keyboard.type('a = 3\nb = a^2 + 1\n5 km / 20 min to km/h\nsin(30 deg)\n3,5 * 2\n1/3 + 1/6');
	const o1 = await outsAfter(page, id, 5, /1\/2/);
	ctx.ok(JSON.stringify(o1) === JSON.stringify(['3', '10', '15 km/h', '0,5 = 1/2', '7', '0,5 = 1/2']), 'Done 1: a = 3, b = 10, 15 km/h, sin(30 deg) = 0,5, 3,5·2 = 7, 1/3 + 1/6 = 0,5 = 1/2', JSON.stringify(o1));
	await page.keyboard.press('Enter');
	ctx.ok(await waitFor(async () => calcJson(await content(id))?.src?.startsWith('a = 3\nb = a^2 + 1')), 'Enter speichert');
	const saved = await content(id);
	const j = calcJson(saved);
	ctx.ok(j && j.v === 1 && j.graph === 1 && Object.keys(j).join(',') === 'v,src,graph', 'Done 1: JSON nach Vertrag', JSON.stringify(j));
	ctx.ok(/<div class="nn-calc" data-nn-calc=[^>]* contenteditable="false"><p class="nn-calc-line"><span class="nn-calc-in">a = 3<\/span> <span class="nn-calc-out">3<\/span><\/p>/.test(saved) && saved.includes('<span class="nn-calc-out">15 km/h</span>'), 'Done 1: Fallback nach Vertrag', saved.slice(0, 300));
	ctx.ok(!/nn-calc-ui|<textarea|nn-block-ui/.test(saved), 'Laufzeit-UI nie im gespeicherten Inhalt');
	await ctx.screenshot(page, 'calc-eingefuegt');
	ctx.assertClean('Einfügen Werkzeugleiste', m);

	// /Rechnen über das Slash-Menü, dann Neuladen identisch
	m = ctx.mark();
	await entry.locator('p', { hasText: 'Zweiter Absatz' }).click();
	await page.keyboard.press('End');
	await page.keyboard.press('Enter');
	await page.keyboard.type('/Rechnen');
	await page.locator('.slash-command-menu').first().waitFor({ state: 'visible', timeout: 5000 });
	await page.waitForTimeout(400);
	await page.locator('.slash-command-menu .slash-command-item', { hasText: /(^|› )Rechnen\s*$/ }).first().click();
	ctx.ok(await waitFor(async () => (await entry.locator('.nn-calc').count()) === 2), 'Done 1: /Rechnen fügt einen zweiten Block ein');
	ctx.ok(await waitFor(async () => ((await content(id)).match(/data-nn-calc=/g) ?? []).length === 2), 'zweiter Block gespeichert');
	await go(page, url(id));
	ctx.ok(await live(page, id), 'Neuladen: eingehängt');
	const o2 = await outs(page, id);
	ctx.ok(JSON.stringify(o2.slice(0, 6)) === JSON.stringify(o1), 'Done 1: Neuladen identisch', JSON.stringify(o2));
	ctx.assertClean('Slash/Neuladen', m);

	// 2. Abgelehnte Ausdrücke (Fehlerzeile), Instanz unverändert
	m = ctx.mark();
	const bad = ['import({x:1})', 'evaluate("2+2")', 'config({number:"BigNumber"})', 'createUnit("foo")', '[1,2,3]', 'a.b', 'sin = 3', 'f(x) = f(x-1)', 'zeros(1e8)', '1:1e9', '170000!', '0.1 + 0.2'];
	const badId = await api.note(WS, `Abgelehnt ${RUN}`, null, calcHtml(bad.join('\n')));
	await go(page, url(badId));
	ctx.ok(await live(page, badId), 'Abgelehnt: eingehängt');
	const ob = await outs(page, badId);
	ctx.ok(ob.slice(0, 11).every((t) => /^Fehler: /.test(t)) && ob[11] === '0,3 = 3/10', 'Done 2: alle abgelehnt, danach 0.1 + 0.2 weiter als Zahl', JSON.stringify(ob));
	ctx.assertClean('Abgelehnt', m);

	// 3. Aufwandsgrenzen im Browser (Zähler, Tiefe, 200 Zeilen); kein Long Task > 300 ms; Notiz bleibt bedienbar
	m = ctx.mark();
	const heavySrc = `${fchain(20)}\nf20(1)\n1 + 1`;
	const heavyId = await api.note(WS, `Aufwand ${RUN}`, null, `<p>Davor ${RUN}</p>${calcHtml(heavySrc)}<p>Danach</p>`);
	await go(page, url(heavyId));
	await longTasks(page);
	ctx.ok(await live(page, heavyId), 'Aufwand: eingehängt');
	const oh = await outs(page, heavyId);
	ctx.ok(oh[21] === 'Fehler: Rechenaufwand zu groß' && oh[22] === 'nicht berechnet', 'Done 3: f20(1) → „Rechenaufwand zu groß“, folgende Zeile „nicht berechnet“', JSON.stringify(oh.slice(20)));
	const tRun = await page.evaluate((s) => { const t = performance.now(); window.NextNotesMath.run(s); return performance.now() - t; }, heavySrc);
	ctx.ok(tRun <= 250, 'Done 3: Abbruch ≤ 250 ms ab Auswertungsbeginn', `${tRun.toFixed(1)} ms`);
	await page.locator(`#entry${heavyId} p`, { hasText: 'Danach' }).click();
	await page.keyboard.press('End');
	const tType = Date.now();
	await page.keyboard.type('X');
	ctx.ok(await page.locator(`#entry${heavyId} p`, { hasText: 'DanachX' }).count() === 1 && Date.now() - tType < 1000, 'Done 3: Notiz nimmt danach außerhalb des Blocks Text an', `${Date.now() - tType} ms`);
	const tDepth = await page.evaluate((s) => { const t = performance.now(); const r = window.NextNotesMath.run(s); return { ms: performance.now() - t, err: r.lines[60].err?.code }; }, `${gchain(60)}\ng60(1)`);
	ctx.ok(tDepth.err === 'heavy', 'Done 3: g60(1) → Tiefengrenze', JSON.stringify(tDepth));
	const lightId = await api.note(WS, `Leicht ${RUN}`, null, calcHtml(Array.from({ length: 200 }, (_, i) => `a${i} = ${i} * 2`).join('\n')));
	await go(page, url(lightId));
	await longTasks(page);
	ctx.ok(await live(page, lightId), '200 Zeilen: eingehängt');
	const ol = await outs(page, lightId);
	ctx.ok(ol.length === 200 && ol[199] === '398' && ol.every((t) => /^\d+$/.test(t)), 'Done 3: 200 leichte Zeilen alle berechnet', `${ol.length} / ${ol[199]}`);
	const lt = await longTasks(page);
	ctx.ok(lt.every((d) => d <= 300), 'Done 3: kein Long Task > 300 ms', JSON.stringify(lt));
	ctx.assertClean('Aufwand', m);

	// 4. Graph (Inhaber): f(x) = x^2 − 2; Ausblenden speichert graph 0; 0 CSP (kein script-src aus function-plot)
	m = ctx.mark();
	const gId = await api.note(WS, `Graph ${RUN}`, null, `<p>Graph ${RUN}</p>${calcHtml('f(x) = x^2 - 2\nf(3)', ['', '7'])}`);
	await go(page, url(gId));
	ctx.ok(await live(page, gId), 'Graph: eingehängt');
	const g1 = await waitGraph(page, gId, (g) => g.paths >= 1);
	ctx.ok(g1?.paths === 1 && g1.svg, 'Done 4: f(x) = x^2 − 2 zeichnet eine Kurve', JSON.stringify(g1));
	ctx.ok(libRequests(m).some((r) => /function-plot\.min\.js/.test(r.url)), 'function-plot lädt erst mit Funktion');
	await page.waitForTimeout(500);
	await ctx.screenshot(page, 'calc-graph');
	await page.locator(`#entry${gId} .nn-calc .nn-calc-toggle`).click();
	ctx.ok(await waitFor(async () => calcJson(await content(gId))?.graph === 0), 'Graph ausblenden speichert graph 0');
	ctx.ok((await graph(page, gId)).paths === 0, 'Graph ausgeblendet');
	await page.locator(`#entry${gId} .nn-calc .nn-calc-toggle`).click();
	ctx.ok(await waitFor(async () => calcJson(await content(gId))?.graph === 1) && (await waitGraph(page, gId, (g) => g.paths === 1))?.paths === 1, 'Graph einblenden speichert graph 1');
	ctx.ok((await content(gId)).includes('<p class="nn-calc-graph">Graph: f(x)</p>'), 'Fallback: Hinweiszeile „Graph: f(x)“');
	// M8c-fix: Funktion nur mit ±Infinity (function-plot 1.26.0 warf dabei) → Lücke, andere Kurve bleibt
	const infId = await api.note(WS, `Unendlich ${RUN}`, null, calcHtml('f(x) = 1/0\ng(x) = x'));
	await go(page, url(infId));
	const gInf = await waitGraph(page, infId, (g) => g.paths >= 1 || !!g.note);
	ctx.ok(gInf?.svg && !gInf.note, 'f(x) = 1/0 neben g(x) = x: Graph ohne Fehler', JSON.stringify(gInf));
	ctx.assertClean('Graph Inhaber', m);

	// 3a. Graph-Budget (Inhaber): f6 gezeichnet, f7 Laufbudget, f40 Tiefe; Gesten danach ohne weitere Läufe
	m = ctx.mark();
	const budget = {};
	for (const n of [6, 7, 40]) budget[n] = await api.note(WS, `Budget f${n} ${RUN}`, null, calcHtml(chain2(n)));
	await go(page, url(budget[6]));
	await longTasks(page);
	const g6 = await waitGraph(page, budget[6], (g) => g.paths >= 1 || !!g.note);
	ctx.ok(g6?.paths === 1 && !g6.note, 'Done 3a: f6 (38 100 Aufrufe) wird gezeichnet', JSON.stringify(g6));
	const box6 = await page.locator(`#entry${budget[6]} .nn-calc .nn-calc-chart svg`).first().boundingBox();
	if (box6) {
		await page.mouse.move(box6.x + box6.width / 2, box6.y + box6.height / 2);
		await page.mouse.wheel(0, -200);
		await page.waitForTimeout(400);
	}
	ctx.ok((await graph(page, budget[6])).paths === 1, 'f6: Zoom zeichnet weiter');
	await go(page, url(budget[7]));
	const g7 = await waitGraph(page, budget[7], (g) => !!g.note);
	ctx.ok(g7?.note === 'Graph zu aufwendig' && g7.paths === 0, 'Done 3a: f7 (76 500) besteht die Vorprüfung, Abbruch durch das Laufbudget → „Graph zu aufwendig“', JSON.stringify(g7));
	const plot7 = await page.locator(`#entry${budget[7]} .nn-calc .nn-calc-plot`).first().boundingBox();
	if (plot7) {
		await page.mouse.move(plot7.x + 20, plot7.y + 10);
		await page.mouse.wheel(0, -200);
		await page.mouse.down();
		await page.mouse.move(plot7.x + 80, plot7.y + 10);
		await page.mouse.up();
	}
	await page.waitForTimeout(400);
	ctx.ok((await graph(page, budget[7])).paths === 0, 'f7: Gesten lösen keine weiteren Läufe aus');
	await go(page, url(budget[40]));
	const g40 = await waitGraph(page, budget[40], (g) => !!g.note);
	ctx.ok(g40?.note === 'Graph zu aufwendig', 'Done 3a: f40 bricht an der Tiefe ab', JSON.stringify(g40));
	const lt3a = await longTasks(page);
	ctx.ok(lt3a.every((d) => d <= 300), 'Done 3a: kein Long Task > 300 ms (Inhaber)', JSON.stringify(lt3a));
	const tabOk = await page.evaluate(() => new Promise((r) => { const t = performance.now(); setTimeout(() => r(performance.now() - t), 0); }));
	ctx.ok(tabOk < 200, 'Done 3a: Tab bleibt bedienbar', `${tabOk.toFixed(0)} ms`);
	ctx.assertClean('Graph-Budget Inhaber', m);

	// 3b. Viele schwere Blöcke (Review M8c r1, Inhaber): je Schritt eine Task, Seitenbudget; Rest mit Knopf, Klick rechnet
	m = ctx.mark();
	const manyId = await api.note(WS, `Viele ${RUN}`, null, `<p>Viele ${RUN}</p>` + Array.from({ length: 12 }, () => calcHtml(heavyBlock())).join(''));
	await go(page, 'about:blank');
	await go(page, url(manyId));
	const stOwn = await settled(page, manyId);
	const ltOwn = await longTasks(page);
	ctx.ok(stOwn.length === 12 && stOwn.includes('live') && stOwn.includes('deferred') && !stOwn.includes('open'), 'Viele Blöcke (Inhaber): erste gerechnet, Rest über dem Seitenbudget mit Knopf', JSON.stringify(stOwn));
	ctx.ok(ltOwn.every((d) => d <= 300), `Viele Blöcke (Inhaber): kein Long Task > 300 ms (max ${Math.max(0, ...ltOwn)} ms, ${stOwn.filter((x) => x === 'live').length}/12 gerechnet)`, JSON.stringify(ltOwn));
	const lastIdx = stOwn.lastIndexOf('deferred');
	const btnText = await page.locator(`#entry${manyId} .nn-calc`).nth(lastIdx).locator('.nn-block-deferred').textContent();
	ctx.ok(btnText === 'Nicht berechnet. Zum Rechnen klicken.', 'Knopftext deutsch', btnText);
	await page.locator(`#entry${manyId} .nn-calc`).nth(lastIdx).locator('.nn-block-deferred').click();
	ctx.ok(await waitFor(async () => (await blockStates(page, manyId))[lastIdx] === 'live'), 'Klick rechnet den Block');
	const fbOwn = await page.evaluate((n) => document.querySelector(`#entry${n} .nn-calc`).getAttribute('data-nn-calc').length, manyId);
	ctx.ok(fbOwn > 600 && (await content(manyId)).match(/data-nn-calc=/g)?.length === 12, 'Inhalt unverändert');
	ctx.ok((await longTasks(page)).every((d) => d <= 300), 'Klick: kein Long Task > 300 ms');
	await ctx.screenshot(page, 'calc-viele');
	ctx.assertClean('Viele Blöcke Inhaber', m);

	// 5. Tastatur-Isolation (R1) im Textfeld
	m = ctx.mark();
	const kbId = await api.note(WS, `Tasten ${RUN}`, null, `<p>Davor ${RUN}</p>${calcHtml('1 + 1', ['2'])}<p>Danach ${RUN}</p>`);
	await go(page, url(kbId));
	ctx.ok(await live(page, kbId), 'Tastatur: eingehängt');
	const kbEntry = page.locator(`#entry${kbId}`);
	const outside = () => kbEntry.evaluate((e) => [...e.childNodes].filter((n) => !(n.nodeType === 1 && n.classList.contains('nn-calc'))).map((n) => n.textContent).join('|'));
	const before = await outside();
	await ta(page, kbId).click();
	await page.keyboard.press(KEY_DOC_END);
	await page.keyboard.press('Enter');
	for (const s of ['/', ':', '@', '- ', '[ ]']) { await page.keyboard.type(s); await page.waitForTimeout(250); }
	ctx.ok((await visibleMenus(page)).length === 0, 'Done 5: kein Upstream-Menü beim Tippen im Textfeld', (await visibleMenus(page)).join(','));
	await page.keyboard.press('Enter');
	await page.keyboard.type('2 * 21');
	await page.keyboard.press('Tab');
	ctx.ok(await waitFor(async () => calcJson(await content(kbId))?.src === '1 + 1\n/:@- [ ]\n2 * 21'), 'Done 5: Zeichen und Zeilen landen im Textfeld, Blur speichert', JSON.stringify(calcJson(await content(kbId))?.src));
	const ok5 = await outs(page, kbId);
	ctx.ok(/^Fehler: /.test(ok5[1]) && ok5[2] === '42', 'Fehlerzeile und Ergebnis', JSON.stringify(ok5));
	ctx.ok(await outside() === before && await kbEntry.evaluate((e) => e.querySelectorAll(':scope > p').length) === 2, 'Done 5: Notiz außerhalb des Blocks unverändert', `${before} → ${await outside()}`);
	ctx.assertClean('Tastatur', m);

	// 6. Sicherheit: Markup im Quelltext, manipuliertes JSON, ohne Klasse, Verschachtelung, Doppel-Attribut
	m = ctx.mark();
	const dialogs = [];
	page.on('dialog', (d) => { dialogs.push(d.message()); d.dismiss().catch(() => {}); });
	const xssSrc = '<script>alert(1)</script>\n<img src=x onerror=alert(1)>\n1 + 1';
	const sheet = esc(JSON.stringify({ v: 1, lang: 'deDE', rows: 1, cols: 1, cells: [['x']] }));
	const secId = await api.note(WS, `Sicher ${RUN}`, null, calcHtml(xssSrc, ['', '', '2'])
		+ calcHtml('zusatz', [], 1, ',"x":1')
		+ calcHtml('graph2', [], 2)
		+ calcHtml(Array.from({ length: 201 }, () => 'z201').join('\n'))
		+ `<div data-nn-calc="${esc('{"v":1e999,"src":"unendlich","graph":1}')}"><p>unendlich</p></div>`
		+ `<div data-nn-calc="${esc(JSON.stringify({ v: 1, src: 'ohneklasse', graph: 0 }))}"><p>ohneklasse</p><div data-nn-sheet="${sheet}"><table><tbody><tr><td>innen</td></tr></tbody></table></div></div>`
		+ `<div class="nn-sheet" data-nn-sheet="${sheet}"><table class="nn-sheet-table"><tbody><tr><td>tab${calcHtml('imfallback')}</td></tr></tbody></table></div>`
		+ `<div data-nn-sheet="${sheet}" data-nn-calc="${esc(JSON.stringify({ v: 1, src: 'doppelt', graph: 0 }))}"><p>doppelt</p></div>`);
	const sec = await content(secId);
	const hosts = sec.match(/data-nn-calc=/g) ?? [];
	ctx.ok(hosts.length === 2 && ['zusatz', 'graph2', 'z201', 'unendlich', 'doppelt'].every((t) => sec.includes(t)), 'Done 6: manipuliertes JSON (Zusatzschlüssel, graph 2, 201 Zeilen, v 1e999), Doppel-Attribut → Attribut entfernt, Text bleibt', String(hosts.length));
	ctx.ok(/<div class="nn-calc" data-nn-calc=(?:'[^']*|"[^"]*)ohneklasse/.test(sec) && !sec.includes('innen') && !sec.includes('imfallback') && (sec.match(/data-nn-sheet=/g) ?? []).length === 1, 'Done 6: ohne Klasse erkannt; Block im Fallback des anderen Blocks samt Inhalt entfernt (beide Richtungen)', sec.slice(-500));
	await go(page, url(secId));
	ctx.ok(await live(page, secId), 'Sicherheit: eingehängt');
	const sv = await page.evaluate((n) => {
		const h = document.querySelector(`#entry${n} .nn-calc`);
		return { val: h.shadowRoot.querySelector('textarea').value, imgs: h.querySelectorAll('img').length + h.shadowRoot.querySelectorAll('img').length, scripts: h.shadowRoot.querySelectorAll('script').length };
	}, secId);
	ctx.ok(sv.val.includes('<img src=x onerror=alert(1)>') && sv.imgs === 0 && sv.scripts === 0 && dialogs.length === 0, 'Done 6: <script>/<img onerror> nur als Text', JSON.stringify(sv));
	ctx.assertClean('Sicherheit', m);

	// 7. Markdown-Umwandlung, Suche, Druckansicht
	m = ctx.mark();
	const mdOut = (await api.must('POST', '/convert-html', { html: await content(id) }))?.markdown ?? '';
	ctx.ok(mdOut.includes('```\na = 3  # = 3\nb = a^2 + 1  # = 10\n5 km / 20 min to km/h  # = 15 km/h') && !/data-nn-calc|"src"/.test(mdOut), 'Done 7: Codeblock mit „# = Ergebnis“', mdOut.slice(0, 300));
	const tickId = await api.note(WS, `Zaun ${RUN}`, null, calcHtml('```\nx = `1`', ['Fehler: Eingabe nicht lesbar', 'Fehler: Eingabe nicht lesbar']) + '<p>Absatz danach</p>');
	const mdTick = (await api.must('POST', '/convert-html', { html: await content(tickId) }))?.markdown ?? '';
	ctx.ok(/^```\n``` +#/.test(mdTick) && /```\n\nAbsatz danach$/.test(mdTick), 'Done 7: ``` im Quelltext bleibt im Codeblock, Absatz bleibt Absatz', JSON.stringify(mdTick));
	const hit = async (q) => JSON.stringify(await api.must('GET', `/notes/search?q=${encodeURIComponent(q)}&workspace=${encodeURIComponent(WS)}`).catch(() => null)).includes(`Rechnung ${RUN}`);
	ctx.ok(await hit('km/h') && await hit('a^2'), 'Done 7: Suche findet Eingabe und Ergebnis');
	await go(page, 'about:blank');
	const mP = ctx.mark();
	await page.goto(ctx.appUrl(`api_export_note.php?id=${gId}&format=html&disposition=inline`));
	await page.waitForFunction(() => window.poznotePrintReady, null, { timeout: 15000 });
	ctx.ok(await page.locator('#nextnotes-root .nn-calc-out', { hasText: '7' }).count() >= 1 && await page.locator('#nextnotes-root .nn-calc-graph', { hasText: 'Graph: f(x)' }).count() === 1, 'Druckansicht: Zeilen + Hinweiszeile „Graph: f(x)“');
	ctx.ok(libRequests(mP).length === 0, 'Druckansicht lädt keinen Rechenkern');
	ctx.assertClean('Markdown/Suche/Druck', m);

	// 3a/4. Leser (Lesefreigabe): Fallback + Graph ohne Textfeld; f7/f40 auch beim Leser begrenzt; Austeilen
	m = ctx.mark();
	const RW = `Lesen ${RUN}`;
	await api.workspace(RW);
	const readG = await api.note(RW, `LeserGraph ${RUN}`, null, calcHtml('f(x) = x^2 - 2\nf(3)', ['', '7']));
	const read7 = await api.note(RW, `Leser f7 ${RUN}`, null, calcHtml(chain2(7)));
	const read40 = await api.note(RW, `Leser f40 ${RUN}`, null, calcHtml(chain2(40)));
	const readNo = await api.note(RW, `Leser ohne ${RUN}`, null, calcHtml('1 + 1', ['2'], 0));
	const readMany = await api.note(RW, `Leser viele ${RUN}`, null, Array.from({ length: 12 }, () => calcHtml(heavyBlock())).join(''));
	share(ctx.base, A, RW, B, 'read');
	const ctxB = await browser.newContext({ viewport: { width: 1400, height: 900 }, locale: 'de-DE' });
	await ctx.attach(ctxB);
	await watchLongTasks(ctxB);
	const apiB = apiClient(ctx, ctxB.request, B, process.env.NN_PASS2);
	const pb = await ctxB.newPage();
	await ctx.login(pb, B, process.env.NN_PASS2);
	const mB = ctx.mark();
	await go(pb, ctx.appUrl(`index.php?note=${readNo}`));
	await pb.waitForTimeout(800);
	ctx.ok(libRequests(mB).length === 0, 'Leser: Block ohne Graph lädt keinen Rechenkern', libRequests(mB).map((r) => r.url).join(' | '));
	await go(pb, ctx.appUrl(`index.php?note=${readG}`));
	const gr = await waitGraph(pb, readG, (g) => g.paths >= 1);
	const fbVisible = await pb.evaluate((n) => document.querySelector(`#entry${n} .nn-calc .nn-calc-line`)?.getBoundingClientRect().height > 0, readG);
	ctx.ok(gr?.paths === 1 && !gr.ta && gr.slot && fbVisible, 'Done 4: Leser sieht Fallback + Graph, ohne Textfeld', JSON.stringify(gr));
	await pb.waitForTimeout(400);
	await ctx.screenshot(pb, 'calc-leser');
	await longTasks(pb);
	await go(pb, ctx.appUrl(`index.php?note=${read7}`));
	const r7 = await waitGraph(pb, read7, (g) => !!g.note);
	await go(pb, ctx.appUrl(`index.php?note=${read40}`));
	const r40 = await waitGraph(pb, read40, (g) => !!g.note);
	ctx.ok(r7?.note === 'Graph zu aufwendig' && r40?.note === 'Graph zu aufwendig', 'Done 3a: Leser – f7 und f40 begrenzt', `${JSON.stringify(r7)} ${JSON.stringify(r40)}`);
	const ltB = await longTasks(pb);
	ctx.ok(ltB.every((d) => d <= 300), 'Done 3a: kein Long Task > 300 ms (Leser)', JSON.stringify(ltB));
	// Review M8c r1: 12 schwere Blöcke als Leser (z. B. Lehrkraft öffnet eine Abgabe)
	await go(pb, ctx.appUrl(`index.php?note=${readMany}`));
	const stRead = await settled(pb, readMany);
	const ltRead = await longTasks(pb);
	ctx.ok(stRead.length === 12 && stRead.includes('deferred') && !stRead.includes('open'), 'Viele Blöcke (Leser): Rest über dem Seitenbudget mit Knopf', JSON.stringify(stRead));
	ctx.ok(ltRead.every((d) => d <= 300), `Viele Blöcke (Leser): kein Long Task > 300 ms (max ${Math.max(0, ...ltRead)} ms, ${stRead.filter((x) => x === 'live').length}/12 gerechnet)`, JSON.stringify(ltRead));
	const notes = await pb.evaluate((n) => [...document.querySelectorAll(`#entry${n} .nn-calc`)].map((h) => h.shadowRoot?.querySelector('.nn-calc-note')?.textContent ?? ''), readMany);
	ctx.ok(notes.some((t) => t === 'Graph zu aufwendig'), 'Leser: gerechnete schwere Blöcke → „Graph zu aufwendig“', JSON.stringify(notes));
	ctx.assertClean('Leser', m);

	m = ctx.mark();
	const teamId = createTeam(ctx.base, A, `Rechnen ${RUN}`, { [B]: 1 });
	const TW = `Rechnen T ${RUN}`;
	await api.must('POST', '/workspaces', { name: TW, team: teamId });
	const shared = await api.note(TW, `Vorlage ${RUN}`, null, calcHtml('a = 2\na * 3', ['2', '6']));
	const asg = (await api.must('POST', '/assignments', { workspace: TW, title: `Rechnen ${RUN}`, due_at: Math.floor(Date.now() / 1000) + 7200, source_note_id: shared })).assignment;
	await api.must('POST', `/assignments/${asg.id}/distribute`, {});
	const sub = (await api.must('GET', `/assignments/${asg.id}/submissions`)).submissions.find((s) => s.student_uid === B);
	const copy = sub ? await content(sub.note_id, apiB) : '';
	ctx.ok(!!sub && JSON.stringify(calcJson(copy)) === JSON.stringify(calcJson(await content(shared))), 'Done 7: Austeilen – Kopie mit identischem Attribut');
	await go(pb, ctx.appUrl(`index.php?note=${sub?.note_id}`));
	ctx.ok(await live(pb, sub?.note_id), 'Schüler: Block bearbeitbar');
	await ta(pb, sub?.note_id).click();
	await pb.keyboard.press(KEY_DOC_END);
	await pb.keyboard.type('\na * 5');
	await pb.keyboard.press('Tab');
	ctx.ok(await waitFor(async () => calcJson(await content(sub.note_id, apiB))?.src === 'a = 2\na * 3\na * 5'), 'Schüler-Änderung gespeichert');
	ctx.ok(calcJson(await content(shared))?.src === 'a = 2\na * 3', 'Done 7: Vorlage unverändert');
	await go(pb, 'about:blank');
	await api.call('DELETE', `/assignments/${asg.id}`, { confirm: true });
	ctx.assertClean('Austeilen', m);
	await ctxB.close();

	// 8. Mobil 390 px und Tablet: eingehängt, Eingabe, Knöpfe ≥ 34 px, Seite scrollt nicht waagrecht
	await go(page, 'about:blank');
	for (const [label, vp] of [['mobil', { width: 390, height: 844 }], ['tablet', { width: 820, height: 1180 }]]) {
		m = ctx.mark();
		const mc = await browser.newContext({ viewport: vp, locale: 'de-DE', isMobile: label === 'mobil', hasTouch: true });
		await ctx.attach(mc);
		const mp = await mc.newPage();
		await ctx.login(mp, A, process.env.NN_PASS);
		await go(mp, url(gId));
		ctx.ok(await live(mp, gId), `${label}: Block eingehängt`);
		await ta(mp, gId).tap();
		await mp.keyboard.press(KEY_DOC_END);
		await mp.keyboard.type(label === 'mobil' ? '\n6 * 7' : '\n2^10');
		const want = label === 'mobil' ? '42' : '1024', at = label === 'mobil' ? 2 : 3;
		const om = await outsAfter(mp, gId, at, new RegExp(`^${want}$`));
		ctx.ok(om[at] === want, `${label}: Eingabe und Ergebnis`, JSON.stringify(om));
		const geo = await mp.locator(`#entry${gId} .nn-calc`).first().evaluate((h) => {
			const r = h.shadowRoot;
			const btns = [...r.querySelectorAll('.nn-calc-btn')].filter((b) => b.getClientRects().length > 0);
			return { btns: btns.length, small: btns.filter((b) => b.getBoundingClientRect().height < 34).length, pageScroll: document.scrollingElement.scrollWidth > window.innerWidth + 1, svg: !!r.querySelector('.nn-calc-chart svg') };
		});
		ctx.ok(geo.btns >= 1 && geo.small === 0 && !geo.pageScroll && geo.svg, `${label}: Knöpfe ≥ 34 px, Graph, Seite scrollt nicht waagrecht`, JSON.stringify(geo));
		await ctx.screenshot(mp, `calc-${label}`);
		await ta(mp, gId).evaluate((t) => t.blur());
		await mc.close();
		ctx.assertClean(label, m);
	}
	ctx.assertClean('gesamter Lauf');
});
