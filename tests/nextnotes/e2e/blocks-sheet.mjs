// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// M8b (SPEC §4.7, M8-Plan §3): Tabellenblock einfügen (Werkzeugleiste, /Tabelle rechnen), rechnen, Neuladen, Bezüge verschieben,
// Zirkelbezug, Whitelist (#NAME?), 46 Funktionen gegen LibreOffice-Referenz, HEUTE, Grenzen, Tastatur und Isolation (R1),
// Shadow DOM + Fallback-Neuerzeugung (§1.1), Leser (Lesefreigabe inkl. Formel, Material), fremde Sperre, Sicherheit,
// Austeilen, Markdown/Suche, Druck, Mobil/Tablet. 0 CSP/Konsole je Abschnitt.
// M8b-fix: gesperrte Funktionen (VERSION, BEREICH.VERSCHIEBEN), WAHR/FALSCH, Platzhalter, Fehlerpfad beim Einhängen (H1),
// Zellgrenze nach Bezugsverschiebung (H2), Ablegen auf dem Block (H3). Engine-Grenzen ohne Browser: tests/nextnotes/node/sheet-engine.mjs
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { runSpec, REPO_ROOT } from './lib/harness.mjs';
import { apiClient } from './lib/api.mjs';
import { createTeam, share } from './lib/docker.mjs';

const RUN = `${process.argv[3]}${Date.now().toString(36)}`;
const A = process.env.NN_USER;
const B = process.env.NN_USER2;
const FIX = JSON.parse(readFileSync(join(REPO_ROOT, 'tests/nextnotes/fixtures/sheet/functions.json'), 'utf8'));

const empty = (rows, cols) => Array.from({ length: rows }, () => new Array(cols).fill(null));
const esc = (s) => s.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
// Block-HTML wie der Client (Fallback hier nur Text)
function sheetHtml(data, fallback = []) {
	const rows = Array.from({ length: data.rows }, (_, r) => `<tr>${Array.from({ length: data.cols }, (_, c) => `<td>${esc(String(fallback[r]?.[c] ?? ''))}</td>`).join('')}</tr>`).join('');
	return `<div class="nn-sheet" data-nn-sheet="${esc(JSON.stringify(data))}" contenteditable="false"><table class="nn-sheet-table"><tbody>${rows}</tbody></table></div>`;
}

await runSpec(import.meta.url, async (ctx, page, context, browser) => {
	const api = apiClient(ctx, page.request);
	const content = async (id, c = api) => (await c.must('GET', `/notes/${id}`)).note?.content ?? '';
	const waitFor = async (fn, ms = 10000) => {
		for (const end = Date.now() + ms; Date.now() < end; await page.waitForTimeout(250)) if (await fn()) return true;
		return fn();
	};
	const hfRequests = (since) => ctx.requests.slice(since.q).filter((r) => /hyperformula|nextnotes-sheet/.test(r.url));
	const sheetJson = (html) => {
		const m = /data-nn-sheet="([^"]*)"/.exec(html) ?? /data-nn-sheet='([^']*)'/.exec(html);
		if (!m) return null;
		return JSON.parse(m[1].replace(/&quot;/g, '"').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&'));
	};
	// Zellen des Rasters (Shadow DOM; Locator durchdringt offene Shadow Roots)
	const grid = (p, noteId) => p.locator(`#entry${noteId} .nn-sheet`).first();
	const cellText = (p, noteId, a) => p.evaluate(([n, addr]) => {
		const host = document.querySelector(`#entry${n} .nn-sheet`);
		const td = host?.shadowRoot?.querySelector(`td[data-r="${Number(addr.slice(1)) - 1}"][data-c="${addr.charCodeAt(0) - 65}"]`);
		return td ? { text: td.textContent, title: td.title, cls: td.className } : null;
	}, [noteId, a]);
	const live = (p, noteId) => p.waitForFunction((n) => {
		const ui = document.querySelector(`#entry${n} .nn-sheet`)?.shadowRoot?.querySelector('.nn-block-ui');
		return ui && !ui.hidden && ui.querySelector('.nn-sheet-grid');
	}, noteId, { timeout: 15000 }).then(() => true, () => false);
	const isStatic = (p, noteId) => p.evaluate((n) => {
		const host = document.querySelector(`#entry${n} .nn-sheet`);
		if (!host) return false;
		const table = host.querySelector(':scope > table.nn-sheet-table');
		return !host.shadowRoot?.querySelector('.nn-sheet-ui') && !!table && table.getBoundingClientRect().height > 0;
	}, noteId);
	const focusSink = (p) => p.waitForFunction(() => {
		let a = document.activeElement;
		while (a && a.shadowRoot && a.shadowRoot.activeElement) a = a.shadowRoot.activeElement;
		return a && a.classList && a.classList.contains('nn-sheet-sink');
	}, null, { timeout: 5000 }).then(() => true, () => false);
	const clickCell = async (p, noteId, a) => {
		await p.locator(`#entry${noteId} .nn-sheet td[data-r="${Number(a.slice(1)) - 1}"][data-c="${a.charCodeAt(0) - 65}"]`).first().click();
		await focusSink(p);
	};
	// vor dem Verlassen: Autosave (3 s Verzögerung) abwarten, sonst beforeunload-Dialog
	const go = async (p, url) => {
		await p.waitForFunction(() => !(window.hasUnsavedChanges && window.hasUnsavedChanges(window.noteid)), null, { timeout: 20000 }).catch(() => {});
		await p.goto(url, { waitUntil: 'networkidle' });
	};
	const typeCell = async (p, text, key = 'Enter') => { await p.keyboard.type(text); await p.keyboard.press(key); };
	const menuItem = async (p, noteId, label) => {
		await p.locator(`#entry${noteId} .nn-sheet .nn-sheet-menu-btn`).first().click();
		await p.locator(`#entry${noteId} .nn-sheet .nn-sheet-menu-item`, { hasText: label }).first().click();
	};
	const visibleMenus = (p) => p.evaluate(() => [...document.querySelectorAll('.slash-command-menu, .emoji-autocomplete-menu, .mention-autocomplete-menu, .nn-blocks-menu')]
		.filter((el) => el.getClientRects().length > 0 && getComputedStyle(el).visibility !== 'hidden' && getComputedStyle(el).display !== 'none').map((el) => el.className));

	const WS = await api.workspace(`Tabelle ${RUN}`);
	const id = await api.note(WS, `Rechnen ${RUN}`, null, `<p>Start ${RUN}</p><p>Zweiter Absatz</p>`);
	const noteUrl = ctx.appUrl(`index.php?workspace=${encodeURIComponent(WS)}&note=${id}`);
	const entry = page.locator(`#entry${id}`);

	// 1. Laden ohne Engine; Werkzeugleiste → Tabelle rechnen (5×5), 2 / 3,5 / =SUMME(A1:A2) → 5,5
	let m = ctx.mark();
	await go(page, noteUrl);
	await ctx.selfTest(page);
	ctx.ok(hfRequests(m).length === 0, 'Notiz ohne Tabelle lädt keine Engine', hfRequests(m).map((r) => r.url).join(' | '));
	await entry.locator('p').first().click();
	await page.keyboard.press('End');
	await page.locator(`#note${id} .btn-nn-blocks`).click();
	const menuText = await page.locator('.nn-blocks-menu').innerText();
	ctx.ok(/Tabelle rechnen/.test(menuText), 'Werkzeugleisten-Menü: „Tabelle rechnen“', menuText.replace(/\s+/g, ' '));
	await page.locator('.nn-blocks-menu [data-nn-block="nn-sheet"]').click();
	ctx.ok(await live(page, id), 'Tabellenblock eingehängt (Shadow DOM)');
	ctx.ok(hfRequests(m).some((r) => /hyperformula\.min\.js/.test(r.url)), 'HyperFormula lädt beim ersten schreibbaren Block');
	ctx.ok(await focusSink(page), 'Fokus im Raster (A1) nach dem Einfügen');
	const shape = await grid(page, id).evaluate((h) => ({ rows: h.shadowRoot.querySelectorAll('tbody tr').length, cols: h.shadowRoot.querySelectorAll('tbody tr:first-child td').length, top: h.parentElement.classList.contains('noteentry') }));
	ctx.ok(shape.rows === 5 && shape.cols === 5 && shape.top, 'Done 1: 5×5 auf oberster Ebene', JSON.stringify(shape));
	await typeCell(page, '2');
	await typeCell(page, '3,5');
	await typeCell(page, '=SUMME(A1:A2)');
	ctx.ok((await cellText(page, id, 'A3'))?.text === '5,5', 'Done 1: =SUMME(A1:A2) → 5,5', JSON.stringify(await cellText(page, id, 'A3')));
	ctx.ok(await waitFor(async () => (sheetJson(await content(id))?.cells?.[2]?.[0]) === '=SUMME(A1:A2)'), 'gespeichert');
	const saved = await content(id);
	const j = sheetJson(saved);
	ctx.ok(j && j.v === 1 && j.lang === 'deDE' && j.rows === 5 && j.cols === 5 && j.cells[0][0] === 2 && j.cells[1][0] === 3.5 && j.cells[2][0] === '=SUMME(A1:A2)', 'Done 1: JSON nach Vertrag (Zahlen als JSON-Zahl)', JSON.stringify(j?.cells?.slice(0, 3)));
	ctx.ok(/<div class="nn-sheet" data-nn-sheet=.* contenteditable="false"><table class="nn-sheet-table"><tbody><tr><td class="nn-num">2<\/td>/.test(saved) && saved.includes('<td class="nn-num">5,5</td>'), 'Done 1: Fallback nach Vertrag (Ergebnis, nn-num)', saved.slice(0, 300));
	ctx.ok(!/nn-sheet-ui|nn-sheet-grid|<input|<textarea/.test(saved), 'Laufzeit-UI nie im gespeicherten Inhalt');
	await ctx.screenshot(page, 'eingefuegt');
	ctx.assertClean('Einfügen Werkzeugleiste', m);

	// /Tabelle rechnen über das Slash-Menü
	m = ctx.mark();
	await entry.locator('p', { hasText: 'Zweiter Absatz' }).click();
	await page.keyboard.press('End');
	await page.keyboard.press('Enter');
	// Leerzeichen schließt das Upstream-Slash-Menü: Suche „/Tabelle“, Eintrag „Tabelle rechnen“ wählen
	await page.keyboard.type('/Tabelle');
	await page.locator('.slash-command-menu').first().waitFor({ state: 'visible', timeout: 5000 });
	await page.waitForTimeout(400);
	const slash = await page.locator('.slash-command-menu').first().innerText();
	ctx.ok(/Tabelle rechnen/.test(slash), 'Slash-Suche „/Tabelle“ zeigt „Tabelle rechnen“', slash.replace(/\s+/g, ' ').slice(0, 120));
	await page.locator('.slash-command-menu .slash-command-item', { hasText: 'Tabelle rechnen' }).first().click();
	ctx.ok(await waitFor(async () => (await entry.locator('.nn-sheet').count()) === 2), 'Done 1: /Tabelle rechnen fügt einen zweiten Block ein');
	ctx.ok(await waitFor(async () => ((await content(id)).match(/data-nn-sheet=/g) ?? []).length === 2), 'zweiter Block gespeichert');
	ctx.assertClean('Einfügen Slash', m);

	// Neuladen identisch
	m = ctx.mark();
	await go(page, noteUrl);
	ctx.ok(await live(page, id), 'Neuladen: Block wieder eingehängt');
	ctx.ok((await cellText(page, id, 'A3'))?.text === '5,5' && (await cellText(page, id, 'A2'))?.text === '3,5', 'Done 1: Neuladen identisch');
	ctx.assertClean('Neuladen', m);

	// 2. Bezüge verschieben, Zirkelbezug, Whitelist
	m = ctx.mark();
	await clickCell(page, id, 'A1');
	await menuItem(page, id, 'Zeile oberhalb einfügen');
	await clickCell(page, id, 'A4');
	const fx = page.locator(`#entry${id} .nn-sheet .nn-sheet-fx`).first();
	ctx.ok(await fx.inputValue() === '=SUMME(A2:A3)' && (await cellText(page, id, 'A4'))?.text === '5,5', 'Done 2: Zeile oberhalb → =SUMME(A2:A3)', await fx.inputValue());
	await clickCell(page, id, 'C2');
	await typeCell(page, '7', 'Tab');
	await typeCell(page, '=C2*2');
	await clickCell(page, id, 'B1');
	await menuItem(page, id, 'Spalte löschen');
	await clickCell(page, id, 'C2');
	ctx.ok(await fx.inputValue() === '=B2*2' && (await cellText(page, id, 'C2'))?.text === '14', 'Done 2: Spalte löschen → =B2*2', await fx.inputValue());
	await clickCell(page, id, 'D1');
	await typeCell(page, '=D2');
	await typeCell(page, '=D1');
	const cyc = await cellText(page, id, 'D1');
	ctx.ok(cyc?.text === '#CYCLE!' && /Zirkelbezug/.test(cyc.title) && /nn-err/.test(cyc.cls), 'Done 2: Zirkelbezug #CYCLE! mit deutschem Tooltip', JSON.stringify(cyc));
	await clickCell(page, id, 'C3');
	for (const f of ['=HYPERLINK("x")', '=INDIREKT("A1")', '=ZUFALLSZAHL()']) await typeCell(page, f);
	const names = await Promise.all(['C3', 'C4', 'C5'].map((a) => cellText(page, id, a)));
	ctx.ok(names.every((c) => c?.text === '#NAME?'), 'Done 2: HYPERLINK/INDIREKT/ZUFALLSZAHL → #NAME?', names.map((c) => c?.text).join(','));
	ctx.ok(/unbekannt/i.test(names[0]?.title ?? ''), '#NAME? mit deutschem Klartext', names[0]?.title);
	// M8b-fix: geschützte HF-Funktionen gesperrt, WAHR() rechnet; gespeichert bleibt der eingegebene Text
	await clickCell(page, id, 'D3');
	for (const f of ['=VERSION()', '=WAHR()', '=SUMME(BEREICH.VERSCHIEBEN(A1;0;0;1000000;1))']) await typeCell(page, f);
	const locked = await Promise.all(['D3', 'D4', 'D5'].map((a) => cellText(page, id, a)));
	ctx.ok(locked.map((c) => c?.text).join(',') === '#NAME?,WAHR,#NAME?', 'M8b-fix: VERSION/BEREICH.VERSCHIEBEN → #NAME?, WAHR() rechnet', locked.map((c) => c?.text).join(','));
	ctx.ok(await waitFor(async () => sheetJson(await content(id))?.cells?.[4]?.[3] === '=SUMME(BEREICH.VERSCHIEBEN(A1;0;0;1000000;1))'), 'M8b-fix: gesperrte Formel als Text gespeichert');
	// M8b-fix2: ' oder ! außerhalb einer Zeichenkette (Blattbezug) → Bearbeiten abgelehnt mit Hinweis, Zelle unverändert
	await clickCell(page, id, 'D3');
	await typeCell(page, `=SUMME('x"'!B2;VERSION();"")`);
	const refHint = await grid(page, id).evaluate((h) => h.shadowRoot.querySelector('.nn-sheet-msg').textContent);
	ctx.ok(/Apostroph/.test(refHint) && (await cellText(page, id, 'D3'))?.text === '#NAME?' && sheetJson(await content(id))?.cells?.[2]?.[3] === '=VERSION()', "M8b-fix2: ' außerhalb von Zeichenketten abgelehnt (Hinweis, Zelle bleibt)", refHint);
	const probe = await page.evaluate(() => {
		const t0 = performance.now();
		const cells = Array.from({ length: 5 }, (_, i) => ['a'.repeat(1000), i ? null : '=ZÄHLENWENN(A1:A5;"*a*a*a*b")']);
		const v = window.NextNotesSheet.evaluate(JSON.stringify({ v: 1, lang: 'deDE', rows: 5, cols: 2, cells, fmt: {} }));
		return { v: v[0][1], ms: performance.now() - t0, names: window.NextNotesSheet.registeredNames() };
	});
	ctx.ok(probe.v === 0 && probe.ms < 200, 'M8b-fix: ZÄHLENWENN "*a*a*a*b" ohne Platzhalter < 200 ms (Browser)', `${probe.v} / ${Math.round(probe.ms)} ms`);
	await ctx.screenshot(page, 'bezuege');
	ctx.assertClean('Bezüge/Zirkel/Whitelist', m);

	// 3. 46 Funktionen gegen LibreOffice, HEUTE
	m = ctx.mark();
	const rows = FIX.cases.length + 2;
	const cells = empty(rows, 5);
	FIX.data.forEach((r, i) => { cells[i][0] = r[0]; cells[i][1] = r[1]; cells[i][2] = r[2]; });
	FIX.cases.forEach((c, i) => { cells[i][4] = c.de; });
	cells[rows - 2][4] = '=HEUTE()';
	cells[rows - 1][4] = '=HEUTE()-HEUTE()';
	const fixData = { v: 1, lang: 'deDE', rows, cols: 5, cells, fmt: {} };
	const fixId = await api.note(WS, `Referenz ${RUN}`, null, sheetHtml(fixData));
	await go(page, ctx.appUrl(`index.php?workspace=${encodeURIComponent(WS)}&note=${fixId}`));
	ctx.ok(await live(page, fixId), 'Referenzblatt eingehängt');
	const names47 = await page.evaluate(() => window.NextNotesSheet.functionNames());
	ctx.ok(names47.length === 49 && ['MODUS.EINF', 'ACHSENABSCHNITT', 'WAHR', 'FALSCH'].every((n) => names47.includes(n)) && !names47.includes('VERSION') && !names47.includes('BEREICH.VERSCHIEBEN'), 'Whitelist: 49 erreichbare Namen (47 + WAHR/FALSCH), ohne VERSION/BEREICH.VERSCHIEBEN', names47.join(' '));
	const registered = await page.evaluate(() => window.NextNotesSheet.registeredNames());
	ctx.ok(registered.length === 51 && registered.includes('VERSION') && registered.includes("'NN_GESPERRT_OFFSET") && !registered.includes('BEREICH.VERSCHIEBEN'), 'registriert: 49 + VERSION + OFFSET intern umbenannt', registered.filter((n) => !names47.includes(n)).join(' '));
	const vals = await page.evaluate((json) => window.NextNotesSheet.evaluate(json), JSON.stringify(fixData));
	const bad = FIX.cases.filter((c, i) => {
		const v = vals[i][4];
		return typeof c.expected === 'number' ? !(typeof v === 'number' && Math.abs(v - c.expected) <= 1e-9 * Math.max(1, Math.abs(c.expected))) : v !== c.expected;
	}).map((c) => `${c.de}=${JSON.stringify(vals[FIX.cases.indexOf(c)][4])}≠${c.expected}`);
	ctx.ok(FIX.cases.length === 46 && bad.length === 0, 'Done 3: 46 Funktionen = LibreOffice-Referenz (1e-9)', bad.join(' | '));
	const today = await page.evaluate(() => { const d = new Date(); const p = (n) => String(n).padStart(2, '0'); return `${p(d.getDate())}.${p(d.getMonth() + 1)}.${d.getFullYear()}`; });
	const heute = await cellText(page, fixId, `E${rows - 1}`);
	ctx.ok(heute?.text === today, 'Done 3: =HEUTE() zeigt das lokale Datum', `${heute?.text} / ${today}`);
	ctx.ok((await cellText(page, fixId, `E${rows}`))?.text === '0', 'Done 3: =HEUTE()-HEUTE() = 0');
	ctx.assertClean('Referenzblatt', m);

	// 4. Grenzen: 50×26 voll → kein Anlegen; TSV 60×30 kappt mit Hinweis
	m = ctx.mark();
	const fullId = await api.note(WS, `Voll ${RUN}`, null, sheetHtml({ v: 1, lang: 'deDE', rows: 50, cols: 26, cells: empty(50, 26), fmt: {} }));
	await go(page, ctx.appUrl(`index.php?workspace=${encodeURIComponent(WS)}&note=${fullId}`));
	ctx.ok(await live(page, fullId), 'volles Blatt eingehängt');
	const limits = await grid(page, fullId).evaluate((h) => {
		const r = h.shadowRoot;
		return { row: r.querySelector('.nn-sheet-addrow').disabled, col: r.querySelector('.nn-sheet-addcol').disabled };
	});
	await page.locator(`#entry${fullId} .nn-sheet .nn-sheet-menu-btn`).click();
	const menuDisabled = await grid(page, fullId).evaluate((h) => [...h.shadowRoot.querySelectorAll('.nn-sheet-menu-item')].filter((b) => /einfügen/.test(b.textContent)).every((b) => b.disabled));
	ctx.ok(limits.row && limits.col && menuDisabled, 'Done 4: 51. Zeile/27. Spalte nicht anlegbar', JSON.stringify(limits));
	await page.keyboard.press('Escape');
	const tsvId = await api.note(WS, `TSV ${RUN}`, null, sheetHtml({ v: 1, lang: 'deDE', rows: 5, cols: 5, cells: empty(5, 5), fmt: {} }));
	await go(page, ctx.appUrl(`index.php?workspace=${encodeURIComponent(WS)}&note=${tsvId}`));
	ctx.ok(await live(page, tsvId), 'TSV-Blatt eingehängt');
	await clickCell(page, tsvId, 'A1');
	const tsv = Array.from({ length: 60 }, (_, r) => Array.from({ length: 30 }, (_, c) => (r === 0 && c === 0 ? '"a\tb"' : String(r * 30 + c))).join('\t')).join('\n');
	await page.evaluate(([n, text]) => {
		const sink = document.querySelector(`#entry${n} .nn-sheet`).shadowRoot.querySelector('.nn-sheet-sink');
		const dt = new DataTransfer();
		dt.setData('text/plain', text);
		sink.dispatchEvent(new ClipboardEvent('paste', { clipboardData: dt, bubbles: true, composed: true, cancelable: true }));
	}, [tsvId, tsv]);
	const pasted = await waitFor(async () => { const s = sheetJson(await content(tsvId)); return s && s.rows === 50 && s.cols === 26; });
	const ps = sheetJson(await content(tsvId));
	ctx.ok(pasted && ps.cells[0][0] === 'a\tb' && ps.cells[49][25] === 49 * 30 + 25 && ps.cells[1][0] === 30, 'Done 4: TSV 60×30 → 50×26 (Anführungszeichen, Zahlen)', `${ps?.rows}×${ps?.cols}`);
	const hint = await grid(page, tsvId).evaluate((h) => h.shadowRoot.querySelector('.nn-sheet-msg').textContent);
	ctx.ok(/gekürzt/.test(hint), 'Done 4: Hinweis beim Kappen', hint);
	// Review M8a 2: Client-Grenze 256 KiB – 30×26 Zellen à 900 Zeichen werden abgelehnt, gespeichert bleibt das alte Blatt
	await clickCell(page, tsvId, 'A1');
	const big = Array.from({ length: 30 }, () => Array.from({ length: 26 }, () => 'x'.repeat(900)).join('\t')).join('\n');
	await page.evaluate(([n, text]) => {
		const sink = document.querySelector(`#entry${n} .nn-sheet`).shadowRoot.querySelector('.nn-sheet-sink');
		const dt = new DataTransfer();
		dt.setData('text/plain', text);
		sink.dispatchEvent(new ClipboardEvent('paste', { clipboardData: dt, bubbles: true, composed: true, cancelable: true }));
	}, [tsvId, big]);
	await page.waitForTimeout(800);
	const bigHint = await grid(page, tsvId).evaluate((h) => h.shadowRoot.querySelector('.nn-sheet-msg').textContent);
	const after = await grid(page, tsvId).evaluate((h) => h.getAttribute('data-nn-sheet').length);
	ctx.ok(/zu groß/.test(bigHint) && after < 262144 && JSON.parse(await grid(page, tsvId).getAttribute('data-nn-sheet')).cells[0][0] === 'a\tb', 'Review M8a 2: Tabelle > 256 KiB abgelehnt (Hinweis, Block unverändert)', `${bigHint} / ${after}`);
	ctx.assertClean('Grenzen', m);

	// Review M8a 2: Formel > 10 000 Zeichen wird mit Hinweis abgelehnt, die alte Formel bleibt
	m = ctx.mark();
	const longId = await api.note(WS, `Lang ${RUN}`, null, '<p>Formel <span class="math-inline" data-math="x^2" contenteditable="false">x^2</span></p>');
	await go(page, ctx.appUrl(`index.php?workspace=${encodeURIComponent(WS)}&note=${longId}`));
	await page.evaluate(() => {
		window.__nnNotes = [];
		// Upstream-Meldung (showNotificationPopup → modalAlert.alert) mitschreiben statt als Dialog zeigen
		window.modalAlert.alert = (t) => { window.__nnNotes.push(String(t)); };
	});
	const pop = page.locator('.nn-formula-popover');
	await page.locator(`#entry${longId} [data-math]`).click();
	await pop.waitFor({ state: 'visible', timeout: 15000 });
	await pop.locator('math-field').evaluate((f) => { f.value = 'x+'.repeat(5001); });
	await pop.locator('.nn-formula-apply').click();
	await page.waitForTimeout(500);
	const notes = await page.evaluate(() => window.__nnNotes);
	ctx.ok(notes.some((t) => /zu lang/.test(t)) && await page.locator(`#entry${longId} [data-math="x^2"]`).count() === 1, 'Review M8a 2: Formel > 10 000 Zeichen abgelehnt (Hinweis, alte Formel bleibt)', notes.join(' | '));
	if (await pop.count()) { await page.keyboard.press('Escape'); await pop.waitFor({ state: 'detached', timeout: 5000 }).catch(() => {}); }
	ctx.assertClean('Formel-Grenze', m);

	// Review M8b H2: Zeile oberhalb einfügen verlängert =A9+A9… (999 Zeichen) auf 1 332 → abgelehnt mit Hinweis, Block unverändert
	m = ctx.mark();
	const refs = `=${Array(333).fill('A9').join('+')}`;
	const h2Cells = empty(10, 2);
	h2Cells[8][0] = 1;
	h2Cells[0][1] = refs;
	const h2Id = await api.note(WS, `Verschieben ${RUN}`, null, sheetHtml({ v: 1, lang: 'deDE', rows: 10, cols: 2, cells: h2Cells, fmt: {} }));
	await go(page, ctx.appUrl(`index.php?workspace=${encodeURIComponent(WS)}&note=${h2Id}`));
	ctx.ok(await live(page, h2Id), 'H2-Blatt eingehängt');
	ctx.ok(refs.length === 999 && (await cellText(page, h2Id, 'B1'))?.text === '333', 'H2: Formel mit 999 Zeichen rechnet (nach Verschiebung 1 332)');
	await clickCell(page, h2Id, 'A1');
	await menuItem(page, h2Id, 'Zeile oberhalb einfügen');
	await page.waitForTimeout(500);
	const h2 = await grid(page, h2Id).evaluate((h) => ({ msg: h.shadowRoot.querySelector('.nn-sheet-msg').textContent, json: JSON.parse(h.getAttribute('data-nn-sheet')), rows: h.shadowRoot.querySelectorAll('tbody tr').length }));
	ctx.ok(/1\.000 Zeichen/.test(h2.msg) && h2.json.rows === 10 && h2.rows === 10 && h2.json.cells[0][1] === refs, 'Review M8b H2: Zelle > 1 000 Zeichen nach Verschiebung abgelehnt (Hinweis, Block unverändert)', `${h2.msg} / ${h2.json.rows}`);
	ctx.ok(sheetJson(await content(h2Id))?.cells?.[0]?.[1] === refs, 'H2: gespeichert unverändert');
	ctx.assertClean('Zellgrenze', m);

	// 5. Tastatur und Isolation (R1)
	m = ctx.mark();
	const kbId = await api.note(WS, `Tasten ${RUN}`, null, `<p>Davor ${RUN}</p>${sheetHtml({ v: 1, lang: 'deDE', rows: 5, cols: 5, cells: empty(5, 5), fmt: {} })}<p>Danach ${RUN}</p>`);
	await go(page, ctx.appUrl(`index.php?workspace=${encodeURIComponent(WS)}&note=${kbId}`));
	ctx.ok(await live(page, kbId), 'Tastatur-Blatt eingehängt');
	const kbEntry = page.locator(`#entry${kbId}`);
	const outside = () => kbEntry.evaluate((e) => [...e.childNodes].filter((n) => !(n.nodeType === 1 && n.classList.contains('nn-sheet'))).map((n) => n.textContent).join('|'));
	const before = await outside();
	await clickCell(page, kbId, 'A1');
	await typeCell(page, '1', 'Tab');
	await typeCell(page, '2', 'Tab');
	await page.keyboard.press('Shift+Tab');
	await page.keyboard.press('ArrowDown');
	await typeCell(page, '=A1+B1');
	ctx.ok((await cellText(page, kbId, 'B2'))?.text === '3', 'Tab, Shift+Tab, Pfeil, Enter', JSON.stringify(await cellText(page, kbId, 'B2')));
	await page.keyboard.press('ArrowUp');
	await page.keyboard.press('F2');
	await page.keyboard.press('End');
	await page.keyboard.type('*10');
	await page.keyboard.press('Escape');
	ctx.ok((await cellText(page, kbId, 'B2'))?.text === '3', 'F2 + Esc bricht ab');
	await page.keyboard.press('Delete');
	ctx.ok((await cellText(page, kbId, 'B2'))?.text === '', 'Entf leert die Zelle');
	await page.keyboard.press('Control+z');
	ctx.ok((await cellText(page, kbId, 'B2'))?.text === '3', 'Strg+Z im Block');
	await page.keyboard.press('Control+y');
	ctx.ok((await cellText(page, kbId, 'B2'))?.text === '', 'Strg+Y im Block');
	await page.keyboard.press('Control+z');
	// Kopieren als TSV (Bereich)
	await clickCell(page, kbId, 'A1');
	await page.keyboard.press('Shift+ArrowRight');
	await page.keyboard.press('Shift+ArrowDown');
	const copied = await page.evaluate((n) => {
		const sink = document.querySelector(`#entry${n} .nn-sheet`).shadowRoot.querySelector('.nn-sheet-sink');
		const dt = new DataTransfer();
		sink.dispatchEvent(new ClipboardEvent('copy', { clipboardData: dt, bubbles: true, composed: true, cancelable: true }));
		return dt.getData('text/plain');
	}, kbId);
	ctx.ok(copied === '1\t2\n\t=A1+B1', 'Strg+C als TSV (Rohinhalt)', JSON.stringify(copied));
	// Formatknöpfe
	await clickCell(page, kbId, 'B2');
	await page.locator(`#entry${kbId} .nn-sheet .nn-sheet-bold`).click();
	await page.locator(`#entry${kbId} .nn-sheet .nn-sheet-more`).click();
	await page.locator(`#entry${kbId} .nn-sheet .nn-sheet-more`).click();
	const fmtCell = await cellText(page, kbId, 'B2');
	ctx.ok(fmtCell?.text === '3,00' && /nn-bold/.test(fmtCell.cls), 'Fett, Dezimalstellen +', JSON.stringify(fmtCell));
	await page.locator(`#entry${kbId} .nn-sheet .nn-sheet-pct`).click();
	ctx.ok((await cellText(page, kbId, 'B2'))?.text === '300,00\u00a0%', 'Prozent', (await cellText(page, kbId, 'B2'))?.text);
	ctx.ok(await waitFor(async () => (await content(kbId)).includes('<strong>300,00\u00a0%</strong>') || (await content(kbId)).includes('<strong>300,00&nbsp;%</strong>')), 'Format im Fallback (fett, Prozent)');
	// Isolation: Zeichen, die Upstream-Menüs öffnen, in Zelle und Formelzeile
	await clickCell(page, kbId, 'C3');
	for (const s of ['/', ':', '@', '- ', '[ ]']) { await page.keyboard.type(s); await page.waitForTimeout(250); }
	ctx.ok((await visibleMenus(page)).length === 0, 'Done 5: kein Upstream-Menü beim Tippen in der Zelle', (await visibleMenus(page)).join(','));
	await page.keyboard.press('Enter');
	ctx.ok((await cellText(page, kbId, 'C3'))?.text === '/:@- [ ]', 'Zeichen landen in der Zelle', (await cellText(page, kbId, 'C3'))?.text);
	await page.locator(`#entry${kbId} .nn-sheet .nn-sheet-fx`).click();
	for (const s of ['/', ':', '@', '- ', '[ ]']) { await page.keyboard.type(s); await page.waitForTimeout(250); }
	ctx.ok((await visibleMenus(page)).length === 0, 'Done 5: kein Upstream-Menü in der Formelzeile', (await visibleMenus(page)).join(','));
	await page.keyboard.press('Tab');
	await page.keyboard.press('Control+z');
	await page.keyboard.press('Enter');
	await page.waitForTimeout(500);
	ctx.ok(await outside() === before, 'Done 5: Notiz außerhalb des Blocks unverändert', `${before} → ${await outside()}`);
	ctx.ok(await kbEntry.evaluate((e) => e.querySelectorAll(':scope > p').length) === 2, 'Done 5: keine Absätze/Listen/Checklisten entstanden');
	await ctx.screenshot(page, 'tastatur');
	ctx.assertClean('Tastatur', m);

	// 6. Shadow DOM: Fremdänderung am Fallback wird aus dem Block neu erzeugt; Light DOM ohne UI
	m = ctx.mark();
	const regen = await page.evaluate(async (n) => {
		const host = document.querySelector(`#entry${n} .nn-sheet`);
		const td = host.querySelector('td');
		td.textContent = 'FREMD';
		host.querySelector('tbody').appendChild(document.createElement('tr'));
		await new Promise((r) => setTimeout(r, 100));
		return { fremd: host.textContent.includes('FREMD'), rows: host.querySelectorAll('tr').length, ui: !!host.querySelector('.nn-sheet-ui'), slot: !!host.shadowRoot.querySelector('slot') };
	}, kbId);
	ctx.ok(!regen.fremd && regen.rows === 5 && !regen.ui && !regen.slot, '§1.1: Fallback aus dem Block neu erzeugt, UI nur im Shadow Root', JSON.stringify(regen));
	// wie Suchen/Ersetzen: Upstream speichert danach; gespeichert wird der neu erzeugte Fallback
	await kbEntry.evaluate((e) => e.dispatchEvent(new Event('input', { bubbles: true })));
	ctx.ok(await waitFor(async () => { const c = await content(kbId); return !c.includes('FREMD') && (c.match(/<tr>/g) ?? []).length === 5; }), '§1.1: gespeichert ohne Fremdänderung');
	await page.waitForTimeout(1500);
	ctx.assertClean('Fallback', m);

	// Review M8b H3: Datei auf dem Block ablegen – kein Upstream-Bildeinfügen am Host, Browser öffnet die Datei nicht (dropEffect none)
	m = ctx.mark();
	const drop = await page.evaluate(async (n) => {
		const entryEl = document.querySelector(`#entry${n}`);
		const host = entryEl.querySelector('.nn-sheet');
		const calls = [];
		const orig = window.handleImageFilesAndInsert;
		window.handleImageFilesAndInsert = (...a) => { calls.push(a[1] === entryEl); };
		const fire = (target, type) => {
			const dt = new DataTransfer();
			dt.items.add(new File([new Uint8Array([137, 80, 78, 71])], 'bild.png', { type: 'image/png' }));
			const r = target.getBoundingClientRect();
			const ev = new DragEvent(type, { dataTransfer: dt, bubbles: true, composed: true, cancelable: true, clientX: r.left + 5, clientY: r.top + 5 });
			target.dispatchEvent(ev);
			return { prevented: ev.defaultPrevented, effect: dt.dropEffect };
		};
		const before = host.outerHTML;
		const td = host.shadowRoot.querySelector('td[data-r="0"][data-c="0"]');
		const over = fire(td, 'dragover');
		const onBlock = fire(td, 'drop');
		const blockCalls = calls.length;
		// Gegenprobe: Ablegen auf einem Absatz derselben Notiz erreicht den Upstream-Handler
		fire(entryEl.querySelector(':scope > p'), 'drop');
		window.handleImageFilesAndInsert = orig;
		await new Promise((r) => setTimeout(r, 300));
		return { over, onBlock, blockCalls, paraCalls: calls.length - blockCalls, same: host.outerHTML === before, imgs: entryEl.querySelectorAll('img').length, cls: entryEl.classList.contains('drag-over'), fn: typeof orig };
	}, kbId);
	ctx.ok(drop.fn === 'function' && drop.over.prevented && drop.over.effect === 'none' && drop.onBlock.prevented && drop.blockCalls === 0 && drop.same && drop.imgs === 0 && !drop.cls, 'Review M8b H3: Ablegen auf dem Block abgelehnt (kein Upstream-Einfügen, dropEffect none)', JSON.stringify(drop));
	ctx.ok(drop.paraCalls === 1, 'H3 Gegenprobe: Ablegen neben dem Block erreicht den Upstream-Handler', JSON.stringify(drop));
	ctx.assertClean('Ablegen', m);

	// Review M8b H1: wirft das Modul beim Einhängen, bleibt der statische Fallback sichtbar (Hook: mount wirft; eigene Seite)
	m = ctx.mark();
	ctx.allow('console', /nextnotes: Block.*nn-test-mount/, 'H1: absichtlich geworfener Fehler');
	const h1Id = await api.note(WS, `Hook ${RUN}`, null, sheetHtml({ v: 1, lang: 'deDE', rows: 2, cols: 2, cells: [[2, '=A1*3'], [null, null]], fmt: {} }, [['2', '6']]));
	const hp = await context.newPage();
	await hp.route(/\/js\/nextnotes-sheet\.js/, async (route) => {
		const res = await route.fetch();
		await route.fulfill({ response: res, body: `${await res.text()}\n;window.NextNotesSheet.mount = function () { throw new Error('nn-test-mount'); };` });
	});
	await hp.addInitScript(() => {
		window.__nnNotes = [];
		Object.defineProperty(window, 'modalAlert', {
			configurable: true,
			set(v) { v.alert = (t) => { window.__nnNotes.push(String(t)); }; window.__nnModal = v; },
			get() { return window.__nnModal; },
		});
	});
	await hp.goto(ctx.appUrl(`index.php?workspace=${encodeURIComponent(WS)}&note=${h1Id}`), { waitUntil: 'networkidle' });
	await hp.waitForFunction(() => window.__nnNotes.length > 0, null, { timeout: 15000 }).catch(() => {});
	const h1 = await hp.evaluate((n) => ({ notes: window.__nnNotes, shadow: !!document.querySelector(`#entry${n} .nn-sheet`).shadowRoot, edit: document.querySelector(`#entry${n}`).getAttribute('contenteditable') }), h1Id);
	ctx.ok(await isStatic(hp, h1Id) && !h1.shadow && h1.edit === 'true', 'Review M8b H1: Fehler beim Einhängen → Fallback bleibt sichtbar, kein Shadow Root', JSON.stringify(h1));
	ctx.ok(h1.notes.some((t) => /nicht geladen/.test(t)), 'H1: Hinweis „konnte nicht geladen werden“', h1.notes.join(' | '));
	await hp.close();
	ctx.assertClean('Einhängen-Fehler', m);

	// 7. Sicherheit: Markup im Zelltext, manipuliertes JSON, Verschachtelung
	m = ctx.mark();
	const dialogs = [];
	page.on('dialog', (d) => { dialogs.push(d.message()); d.dismiss().catch(() => {}); });
	const xss = { v: 1, lang: 'deDE', rows: 1, cols: 3, cells: [['<img src=x onerror=alert(1)>', 'javascript:alert(1)', 'data:text/html,x']], fmt: {} };
	const secId = await api.note(WS, `Sicher ${RUN}`, null, sheetHtml(xss, [xss.cells[0]])
		+ `<div data-nn-sheet="${esc(JSON.stringify({ ...xss, rows: 51 }))}"><table><tbody><tr><td>kaputt51</td></tr></tbody></table></div>`
		+ `<div data-nn-sheet="${esc(JSON.stringify(xss).replace('"fmt":{}', '"fmt":{},"x":1'))}"><table><tbody><tr><td>zusatz</td></tr></tbody></table></div>`
		+ `<div data-nn-sheet="${esc(JSON.stringify(xss).replace('"rows":1', '"rows":1e999'))}"><table><tbody><tr><td>unendlich</td></tr></tbody></table></div>`
		+ `<div data-nn-sheet="${esc(JSON.stringify(xss))}"><table><tbody><tr><td>ohneklasse<div data-nn-sheet="${esc(JSON.stringify(xss))}">innen</div><span data-math="x">f</span></td></tr></tbody></table></div>`);
	const sec = await content(secId);
	ctx.ok((sec.match(/data-nn-sheet=/g) ?? []).length === 2 && sec.includes('kaputt51') && sec.includes('zusatz') && sec.includes('unendlich'), 'Done 7: manipuliertes JSON → Attribut entfernt, Tabelle bleibt', String((sec.match(/data-nn-sheet=/g) ?? []).length));
	ctx.ok(!sec.includes('innen') && !/data-math/.test(sec) && /<div class="nn-sheet" data-nn-sheet=[^>]*><table><tbody><tr><td>ohneklasse<\/td>/.test(sec), 'Done 7: ohne Klasse erkannt, Block/Formel im Fallback entfernt', sec.slice(-400));
	const secJson = sheetJson(sec);
	ctx.ok(secJson?.cells[0][1] === 'javascript:alert(1)' && secJson.cells[0][2] === 'data:text/html,x', 'Done 7: Zelltext javascript:/data: behält den Block');
	await go(page, ctx.appUrl(`index.php?workspace=${encodeURIComponent(WS)}&note=${secId}`));
	ctx.ok(await live(page, secId), 'Sicherheitsblatt eingehängt');
	const shown = await cellText(page, secId, 'A1');
	const imgs = await page.evaluate((n) => { const h = document.querySelector(`#entry${n} .nn-sheet`); return h.querySelectorAll('img').length + h.shadowRoot.querySelectorAll('img').length; }, secId);
	ctx.ok(shown?.text === '<img src=x onerror=alert(1)>' && imgs === 0 && dialogs.length === 0, 'Done 7: Markup nur als Text (Editor, Fallback)', JSON.stringify(shown));
	ctx.assertClean('Sicherheit', m);

	// 8. Markdown-Umwandlung und Suche
	m = ctx.mark();
	const mdOut = (await api.must('POST', '/convert-html', { html: await content(id) }))?.markdown ?? '';
	ctx.ok(/\| 2 \|/.test(mdOut) && /\| 5,5 \|/.test(mdOut) && !/SUMME|data-nn-sheet|"cells"/.test(mdOut), 'Done 9: Markdown-Tabelle mit Ergebnissen, ohne Formeln', mdOut.slice(0, 300));
	const search = await api.must('GET', `/notes/search?q=${encodeURIComponent('SUMME')}&workspace=${encodeURIComponent(WS)}`).catch(() => null);
	const hitsFormula = JSON.stringify(search ?? {}).includes(`Rechnen ${RUN}`);
	const search2 = await api.must('GET', `/notes/search?q=${encodeURIComponent('5,5')}&workspace=${encodeURIComponent(WS)}`).catch(() => null);
	ctx.ok(!hitsFormula && JSON.stringify(search2 ?? {}).includes(`Rechnen ${RUN}`), 'Done 9: Suche findet Ergebnis, nicht Formeltext');
	ctx.assertClean('Markdown/Suche', m);

	// 9. Druckansicht: statische Tabelle ohne Engine
	m = ctx.mark();
	await go(page, 'about:blank');
	await page.goto(ctx.appUrl(`api_export_note.php?id=${id}&format=html&disposition=inline`));
	await page.waitForFunction(() => window.poznotePrintReady, null, { timeout: 15000 });
	ctx.ok(await page.locator('#nextnotes-root .nn-sheet-table td.nn-num', { hasText: '5,5' }).count() === 1, 'Druckansicht: statische Tabelle mit Ergebnis');
	ctx.ok(hfRequests(m).length === 0, 'Druckansicht lädt keine Engine');
	await ctx.screenshot(page, 'druck');
	ctx.assertClean('Druck', m);

	// 10. Leser: Lesefreigabe (Formel + Tabelle), Material, fremde Sperre, data-nextnotes-readonly; Austeilen
	m = ctx.mark();
	const RW = `Lesen ${RUN}`;
	await api.workspace(RW);
	const BOTH = `<p>Formel <span class="math-inline" data-math="\\sqrt{2}">x</span></p>${sheetHtml({ v: 1, lang: 'deDE', rows: 2, cols: 2, cells: [[2, '=A1*3'], [null, null]], fmt: {} }, [['2', '6']])}`;
	const readId = await api.note(RW, `Lesbar ${RUN}`, null, BOTH);
	share(ctx.base, A, RW, B, 'read');
	const ctxB = await browser.newContext({ viewport: { width: 1400, height: 900 }, locale: 'de-DE' });
	await ctx.attach(ctxB);
	const apiB = apiClient(ctx, ctxB.request, B, process.env.NN_PASS2);
	const pb = await ctxB.newPage();
	await ctx.login(pb, B, process.env.NN_PASS2);
	const mB = ctx.mark();
	await go(pb, ctx.appUrl(`index.php?note=${readId}`));
	await pb.waitForFunction((n) => document.querySelector(`#entry${n} [data-math] .katex`), readId, { timeout: 15000 }).catch(() => {});
	ctx.ok(await pb.locator(`#entry${readId}`).getAttribute('data-nextnotes-readonly') === 'true', 'Lesefreigabe: nur lesen');
	ctx.ok(await isStatic(pb, readId), 'Done 6: Lesefreigabe – Tabelle statisch');
	await pb.locator(`#entry${readId} [data-math]`).first().click();
	await pb.waitForTimeout(800);
	ctx.ok(await pb.locator('.nn-formula-popover').count() === 0 && await pb.locator(`#entry${readId} [data-math] .katex`).count() === 1, 'Review M8a 1: Lesefreigabe – Formel sichtbar, kein Popover');
	ctx.ok(hfRequests(mB).length === 0, 'Done 6: Leser lädt keine Engine (Netzwerk-Log)', hfRequests(mB).map((r) => r.url).join(' | '));
	await ctx.screenshot(pb, 'leser');

	const teamId = createTeam(ctx.base, A, `Tabelle ${RUN}`, { [B]: 1 });
	const TW = `Tabelle T ${RUN}`;
	await api.must('POST', '/workspaces', { name: TW, team: teamId });
	const M = (await api.must('POST', '/folders', { folder_name: `Material ${RUN}`, workspace: TW })).folder_id;
	const mat = await api.note(TW, `Mat ${RUN}`, M, BOTH);
	await api.must('PUT', `/folders/${M}/material`, { material: true });
	const shared = await api.note(TW, `Gemeinsam ${RUN}`, null, BOTH);
	const mM = ctx.mark();
	await go(pb, ctx.appUrl(`index.php?workspace=${encodeURIComponent(TW)}&note=${mat}`));
	ctx.ok(await isStatic(pb, mat) && hfRequests(mM).length === 0, 'Done 6: Material – statisch, keine Engine');

	// Sperre: A öffnet „Gemeinsam“, B sieht Banner und statischen Block; A verlässt → B bearbeitet ohne Neuladen
	ctx.allow('status', new RegExp(`409 POST .*/notes/${shared}/lock`), 'Sperrtest: B erhält 409');
	ctx.allow('console', /status of 409/, 'Sperrtest: 409 als Netzwerkfehler');
	await go(page, ctx.appUrl(`index.php?workspace=${encodeURIComponent(TW)}&note=${shared}`));
	ctx.ok(await live(page, shared), 'A: Block bearbeitbar');
	await page.waitForTimeout(800);
	const mL = ctx.mark();
	await go(pb, ctx.appUrl(`index.php?workspace=${encodeURIComponent(TW)}&note=${shared}`));
	const banner = await pb.locator('.note-edit-lock-banner').first().waitFor({ state: 'visible', timeout: 10000 }).then(() => true, () => false);
	ctx.ok(banner && await isStatic(pb, shared), 'Done 6: B sieht Banner und statischen Block');
	ctx.ok(hfRequests(mL).length === 0, 'Done 6: fremde Sperre – keine Engine');
	await go(page, noteUrl);
	const free = await (async () => {
		for (const end = Date.now() + 20000; Date.now() < end; await pb.waitForTimeout(500)) {
			if ((await pb.locator(`#entry${shared}`).getAttribute('contenteditable')) === 'true' && await pb.locator('.note-edit-lock-banner').count() === 0) return true;
		}
		return false;
	})();
	ctx.ok(free && await live(pb, shared), 'Done 6: Sperre frei → B bearbeitet ohne Neuladen');
	// data-nextnotes-readonly mit erzwungenem contenteditable=true bleibt statisch
	await go(pb, ctx.appUrl(`index.php?workspace=${encodeURIComponent(TW)}&note=${mat}`));
	await pb.evaluate((n) => document.querySelector(`#entry${n}`).setAttribute('contenteditable', 'true'), mat);
	await pb.waitForTimeout(800);
	ctx.ok(await isStatic(pb, mat), 'Done 6: data-nextnotes-readonly + contenteditable=true → statisch');
	ctx.assertClean('Leser/Sperre', m);

	// Austeilen: Kopie mit identischem Attribut; Schüler ändert → Vorlage unverändert
	m = ctx.mark();
	const asg = (await api.must('POST', '/assignments', { team: teamId, title: `Tabelle ${RUN}`, due_at: Math.floor(Date.now() / 1000) + 7200, source_note_id: shared })).assignment;
	await api.must('POST', `/assignments/${asg.id}/distribute`, {});
	const sub = (await api.must('GET', `/assignments/${asg.id}/submissions`)).submissions.find((s) => s.student_uid === B);
	const src = await content(shared);
	const copy = sub ? await content(sub.note_id, apiB) : '';
	ctx.ok(!!sub && JSON.stringify(sheetJson(copy)) === JSON.stringify(sheetJson(src)), 'Done 8: Kopie enthält identisches Attribut');
	await go(pb, ctx.appUrl(`index.php?note=${sub?.note_id}`));
	ctx.ok(await live(pb, sub?.note_id), 'Schüler: Block bearbeitbar');
	await clickCell(pb, sub?.note_id, 'A1');
	await typeCell(pb, '5');
	const subSaved = await waitFor(async () => sheetJson(await content(sub.note_id, apiB))?.cells?.[0]?.[0] === 5);
	ctx.ok(subSaved, 'Schüler-Änderung gespeichert', subSaved ? '' : `${await pb.evaluate((n) => document.querySelector(`#entry${n} .nn-sheet`)?.getAttribute('data-nn-sheet'), sub?.note_id)} / ${(await content(sub.note_id, apiB)).slice(0, 200)}`);
	if (!subSaved) await ctx.screenshot(pb, 'schueler');
	ctx.ok(sheetJson(await content(shared))?.cells?.[0]?.[0] === 2, 'Done 8: Vorlage unverändert');
	// B verlässt die Abgabe vor dem Löschen (sonst pollt die Seite den gelöschten Workspace → 404)
	await go(pb, 'about:blank');
	await api.call('DELETE', `/assignments/${asg.id}`, { confirm: true });
	ctx.assertClean('Austeilen', m);
	await ctxB.close();

	// 11. Mobil 390 px und Tablet: Antippen wählt, zweites Antippen bearbeitet in der Formelzeile; Knöpfe ≥ 34 px
	await go(page, 'about:blank');
	await page.waitForTimeout(1000);
	for (const [label, vp] of [['mobil', { width: 390, height: 844 }], ['tablet', { width: 820, height: 1180 }]]) {
		m = ctx.mark();
		const mc = await browser.newContext({ viewport: vp, locale: 'de-DE', isMobile: label === 'mobil', hasTouch: true });
		await ctx.attach(mc);
		const mp = await mc.newPage();
		await ctx.login(mp, A, process.env.NN_PASS);
		await go(mp, ctx.appUrl(`index.php?workspace=${encodeURIComponent(WS)}&note=${kbId}`));
		ctx.ok(await live(mp, kbId), `${label}: Block eingehängt`);
		const row = label === 'mobil' ? 3 : 4;
		const td = mp.locator(`#entry${kbId} .nn-sheet td[data-r="${row}"][data-c="0"]`);
		await td.tap();
		await mp.waitForTimeout(300);
		await td.tap();
		const inBar = await mp.waitForFunction(() => {
			let a = document.activeElement;
			while (a && a.shadowRoot && a.shadowRoot.activeElement) a = a.shadowRoot.activeElement;
			return a && a.classList.contains('nn-sheet-fx');
		}, null, { timeout: 5000 }).then(() => true, () => false);
		ctx.ok(inBar, `${label}: zweites Antippen bearbeitet in der Formelzeile`);
		await mp.keyboard.type('42');
		await mp.keyboard.press('Enter');
		ctx.ok((await cellText(mp, kbId, `A${row + 1}`))?.text === '42', `${label}: Eingabe über die Formelzeile`, JSON.stringify(await cellText(mp, kbId, `A${row + 1}`)));
		const geo = await grid(mp, kbId).evaluate((h) => {
			const r = h.shadowRoot;
			const btns = [...r.querySelectorAll('.nn-sheet-btn')].filter((b) => b.getClientRects().length > 0);
			return { small: btns.filter((b) => b.getBoundingClientRect().height < 34).length, pageScroll: document.scrollingElement.scrollWidth > window.innerWidth + 1, minCell: Math.min(...[...r.querySelectorAll('td')].map((t) => t.getBoundingClientRect().width)) };
		});
		ctx.ok(geo.small === 0 && !geo.pageScroll && geo.minCell >= 63, `${label}: Knöpfe ≥ 34 px, Zellen ≥ 64 px, Seite scrollt nicht waagrecht`, JSON.stringify(geo));
		await ctx.screenshot(mp, `sheet-${label}`);
		await mc.close();
		ctx.assertClean(label, m);
	}
	ctx.assertClean('gesamter Lauf');
});
