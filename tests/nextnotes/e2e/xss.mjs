// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// M3a2 (Plan M3 §2 Done M3a2 4, SPEC §4.6 Ausgaberegel): DOM-/XSS-Injektion über Notiztitel, Ordner- und Workspace-Namen.
// Vergleich Workspace mit harmlosen Namen vs. gleich aufgebautem Workspace mit Nutzlasten: gleiche Anzahl img/b/svg/script/
// form/[data-action]/on…-Attribute, IDs eindeutig, Text sichtbar als Text, JSON-Inseln parsebar, keine Ausführung.
import { runSpec } from './lib/harness.mjs';
import { apiClient } from './lib/api.mjs';

const RUN = `${process.argv[3]}${Date.now().toString(36)}`;

const EVIL_TITLES = [
	'"><img src=x onerror="window.__xss=1" data-action=delete-note>',
	'</script><b>x</b><script>window.__xss=2</script>',
	'"><svg onload=window.__xss=3>',
	"O'Brien'); window.__xss=4;//",
	'<div id="right_col">fake</div>',
	'<form id="unified-search-form"></form>',
	'right_col',
	'javascript:window.__xss=5',
	'  </script><!-- {{7*7}} ${7*7}',
];
// Ordnernamen: / \ : * ? " < > | sind verboten (InputValidator), ' & { } $ erlaubt
const EVIL_FOLDERS = ["O'Brien'); window.__xss=6;", 'right_col', 'unified-search-form', '&lt;b&gt;x&amp;', '{{7+7}} ${7+7}'];
// Workspace-Namen: nur Buchstaben, Ziffern, Leerzeichen, _ und - (Namensregel); Kollisionen mit IDs und JS-Objektschlüsseln
const EVIL_WS = ['right_col', 'unified-search-form', '__proto__', 'constructor', 'toString'];

async function dom(page) {
	return page.evaluate(() => {
		const root = document;
		const count = (s) => root.querySelectorAll(s).length;
		const islands = [...document.querySelectorAll('script[type="application/json"]')].map((s) => {
			try { JSON.parse(s.textContent); return null; } catch (e) { return `${s.id}: ${e.message}`; }
		}).filter(Boolean);
		const ids = {};
		for (const e of document.querySelectorAll('[id]')) ids[e.id] = (ids[e.id] ?? 0) + 1;
		const rc = document.querySelector('#right_col')?.getBoundingClientRect();
		const lc = document.querySelector('#left_col')?.getBoundingClientRect();
		return {
			img: count('img'), b: count('b'), svg: count('svg'), script: count('script'), form: count('form'), iframe: count('iframe'),
			dataAction: count('[data-action]'), deleteAction: count('[data-action="delete-note"]:not(.note-actions-menu-item)'),
			onAttr: [...document.querySelectorAll('*')].filter((e) => [...e.attributes].some((a) => /^on/i.test(a.name))).length,
			jsHref: count('a[href^="javascript" i]'),
			dupIds: Object.entries(ids).filter(([, n]) => n > 1).map(([k, n]) => `${k}×${n}`),
			rightCol: count('#right_col'), searchForm: count('#unified-search-form'),
			islands, xss: window.__xss ?? null,
			rightRect: rc ? [Math.round(rc.left), Math.round(rc.width)] : null, leftRect: lc ? [Math.round(lc.left), Math.round(lc.width)] : null,
			titles: [...document.querySelectorAll('#left_col .note-title')].map((e) => e.textContent.trim()),
			folders: [...document.querySelectorAll('#left_col .folder-name')].map((e) => e.textContent.trim()),
			wsTitle: document.querySelector('.workspace-title-text')?.textContent,
		};
	});
}

await runSpec(import.meta.url, async (ctx, page) => {
	const api = apiClient(ctx, page.request);
	await api.must('PUT', '/settings/last_opened_workspace', { value: await api.defaultWorkspace() });
	const BASE = `XssBase ${RUN}`;
	const EVIL = `XssEvil ${RUN}`;
	await api.workspace(BASE);
	await api.workspace(EVIL);
	const evilFolderIds = [];
	for (let i = 0; i < EVIL_FOLDERS.length; i++) {
		await api.folder(BASE, `Ordner ${i}`);
		evilFolderIds.push(await api.folder(EVIL, EVIL_FOLDERS[i]));
	}
	// Titel in den ersten (evil) Ordner und auf oberste Ebene
	const baseFolder = (await api.must('GET', `/folders?workspace=${encodeURIComponent(BASE)}`));
	const baseFolderId = (baseFolder.folders ?? []).find((f) => f.name === 'Ordner 0')?.id;
	for (let i = 0; i < EVIL_TITLES.length; i++) {
		await api.note(BASE, `Titel ${i}`);
		await api.note(BASE, `Titel ${i}b`, baseFolderId);
		await api.note(EVIL, EVIL_TITLES[i]);
		await api.note(EVIL, EVIL_TITLES[i], evilFolderIds[0]);
	}
	const wsStatus = [];
	for (const w of EVIL_WS) {
		const r = await api.call('POST', '/workspaces', { name: w });
		wsStatus.push(`${w}:${r.status}`);
		if (r.status < 300 || r.status === 409) await api.note(w, `In ${w} ${RUN}`).catch((e) => ctx.info(`Notiz in ${w}: ${e.message}`));
	}
	ctx.info(`Workspaces angelegt: ${wsStatus.join(', ')}`);
	const okWs = wsStatus.filter((s) => /:(20\d|409)$/.test(s)).map((s) => s.split(':')[0]);
	ctx.ok(okWs.includes('right_col') && okWs.includes('unified-search-form'), 'Workspaces right_col, unified-search-form angelegt');

	const load = async (ws) => {
		const r = await page.goto(ctx.appUrl(`index.php?workspace=${encodeURIComponent(ws)}`), { waitUntil: 'networkidle' });
		await page.waitForTimeout(400);
		// alle Ordner öffnen (Titel im Ordner rendern)
		await page.evaluate(() => document.querySelectorAll('#left_col .folder-content').forEach((c) => { c.style.display = 'block'; }));
		return r?.status();
	};
	let m = ctx.mark();
	ctx.ok(await load(BASE) === 200, 'Basis-Workspace HTTP 200');
	const base = await dom(page);
	ctx.ok(await load(EVIL) === 200, 'Nutzlast-Workspace HTTP 200');
	const evil = await dom(page);
	await ctx.screenshot(page, 'evil');
	ctx.info(`Basis: ${JSON.stringify({ ...base, titles: base.titles.length, folders: base.folders.length })}`);
	ctx.info(`Nutzlast: ${JSON.stringify({ ...evil, titles: evil.titles.length, folders: evil.folders.length })}`);
	for (const k of ['img', 'b', 'svg', 'script', 'form', 'iframe', 'dataAction', 'deleteAction', 'onAttr', 'jsHref', 'rightCol', 'searchForm']) {
		ctx.ok(evil[k] === base[k], `gleiche Anzahl ${k}`, `Basis ${base[k]}, Nutzlast ${evil[k]}`);
	}
	ctx.ok(evil.rightCol === 1 && evil.searchForm === 1, '#right_col und #unified-search-form genau einmal');
	ctx.ok(evil.dupIds.length === base.dupIds.length, 'keine zusätzlichen doppelten IDs', `Basis ${base.dupIds.join(',')} / Nutzlast ${evil.dupIds.join(',')}`);
	ctx.ok(evil.islands.length === 0, 'JSON-Inseln parsebar', evil.islands.join(' | '));
	ctx.ok(evil.xss === null, 'keine Ausführung (window.__xss)', String(evil.xss));
	ctx.ok(JSON.stringify(evil.rightRect) === JSON.stringify(base.rightRect) && JSON.stringify(evil.leftRect) === JSON.stringify(base.leftRect), 'Layout unverändert (Spaltenlage)', `${JSON.stringify(base.rightRect)} vs ${JSON.stringify(evil.rightRect)}`);
	const norm = (s) => s.replace(/\s+/g, ' ').trim();
	const missingT = EVIL_TITLES.filter((t) => evil.titles.filter((x) => norm(x) === norm(t)).length < 2);
	ctx.ok(missingT.length === 0, 'alle Nutzlast-Titel als Text sichtbar (Liste + Ordner)', missingT.join(' | '));
	const missingF = EVIL_FOLDERS.filter((f) => !evil.folders.includes(f));
	ctx.ok(missingF.length === 0, 'alle Ordnernamen als Text sichtbar', missingF.join(' | '));
	ctx.assertClean('Laden', m);

	// Menüs auf Nutzlast-Zeilen öffnen (Upstream liest data-note-title/data-folder-name)
	m = ctx.mark();
	const noteRow = page.locator('#left_col .note-list-item').first();
	await noteRow.hover().catch(() => {});
	await noteRow.locator('.note-actions-toggle').click({ force: true }).catch((e) => ctx.info(`Notizmenü: ${e.message.split('\n')[0]}`));
	await page.waitForTimeout(300);
	await page.keyboard.press('Escape');
	const folderRow = page.locator(`#left_col .folder-toggle[data-folder-id="${evilFolderIds[0]}"]`).first();
	await folderRow.hover().catch(() => {});
	await folderRow.locator('.folder-actions-toggle').click({ force: true }).catch((e) => ctx.info(`Ordnermenü: ${e.message.split('\n')[0]}`));
	await page.waitForTimeout(300);
	await page.keyboard.press('Escape');
	await page.click('.sidebar-title');
	await page.waitForTimeout(400);
	const menuNames = await page.locator('#workspaceMenu .workspace-menu-item[data-workspace-name]').evaluateAll((els) => els.map((e) => e.getAttribute('data-workspace-name')));
	ctx.info(`Workspace-Menü: ${menuNames.join(' | ')}`);
	const missingW = okWs.filter((w) => !menuNames.includes(w));
	ctx.ok(missingW.length === 0, 'Workspace-Menü listet Namen wie right_col/__proto__/constructor als Text', missingW.join(', '));
	const after = await dom(page);
	ctx.ok(after.xss === null && after.onAttr === evil.onAttr && after.img === evil.img, 'nach Menüs: keine Ausführung, keine neuen Elemente/Handler', `xss ${after.xss}, on ${after.onAttr}, img ${after.img}`);
	ctx.assertClean('Menüs', m);

	// Workspace-Namen als Ziel
	for (const w of okWs) {
		m = ctx.mark();
		const st = await load(w);
		const d = await dom(page);
		ctx.ok(st === 200 && d.wsTitle === w && d.rightCol === 1 && d.searchForm === 1 && d.islands.length === 0 && d.xss === null,
			`?workspace=${w}: Titel als Text, Layout intakt`, `HTTP ${st}, Titel ${d.wsTitle}, right_col ${d.rightCol}, form ${d.searchForm}, Inseln ${d.islands.join(',')}`);
		ctx.ok(d.titles.includes(`In ${w} ${RUN}`), `?workspace=${w}: eigene Notiz sichtbar`);
		ctx.assertClean(`Workspace ${w}`, m);
	}
	await load('right_col');
	await ctx.screenshot(page, 'ws-right_col');
	ctx.assertClean('gesamter Lauf');
});
