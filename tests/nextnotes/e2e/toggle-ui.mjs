// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// UI-toggle / UI-sidebar (SPEC §4.3 Nr. 31, 32): Aufklappbereich über das Slash-Menü, volle Breite, blasse Fläche mit Innenabstand,
// Auf-/Zuklappen, nach Neuladen zu (wie Upstream), Markdown-Vorschau gleich; Seitenleiste 248 px, Suchfeld „Suchen…“. 0 CSP/Konsole.
import { runSpec } from './lib/harness.mjs';
import { apiClient } from './lib/api.mjs';

const RUN = `${process.argv[3]}${Date.now().toString(36)}`;
const MD = '# Md\n\n<details class="toggle-block" open>\n<summary class="toggle-header">Md-Titel</summary>\n\nMd-Inhalt\n\n</details>\n\nnach\n';

await runSpec(import.meta.url, async (ctx, page) => {
	const api = apiClient(ctx, page.request);
	const WS = await api.workspace(`Toggle ${RUN}`);
	const id = await api.note(WS, `Toggle ${RUN}`, null, '<p>vor</p>');
	const content = async (n) => (await api.must('GET', `/notes/${n}`)).note?.content ?? '';
	const waitFor = async (fn, ms = 8000) => {
		for (const end = Date.now() + ms; Date.now() < end; await page.waitForTimeout(300)) if (await fn()) return true;
		return fn();
	};
	const geo = (sel) => page.locator(sel).first().evaluate((el) => {
		const cs = getComputedStyle(el);
		const col = el.closest('.noteentry, .markdown-preview') ?? el.parentElement;
		const ccs = getComputedStyle(col);
		const colW = col.getBoundingClientRect().width - parseFloat(ccs.paddingLeft) - parseFloat(ccs.paddingRight);
		const c = el.querySelector(':scope > .toggle-content') ?? el.querySelector(':scope > :not(summary)');
		const s = el.querySelector(':scope > summary');
		const r = el.getBoundingClientRect();
		const cr = c?.getBoundingClientRect();
		const sr = s.getBoundingClientRect();
		const ccs2 = c ? getComputedStyle(c) : null;
		return { open: el.open, w: r.width, colW, h: r.height, bg: cs.backgroundColor, radius: parseFloat(cs.borderTopLeftRadius),
			pl: parseFloat(cs.paddingLeft), pr: parseFloat(cs.paddingRight), pb: parseFloat(cs.paddingBottom),
			bottomGap: cr ? r.bottom - cr.bottom : null, indent: cr ? cr.left + parseFloat(ccs2.paddingLeft) - r.left : null, titleLeft: sr.left - r.left,
			cBorder: ccs2 ? ccs2.borderTopWidth : null, cBg: ccs2 ? ccs2.backgroundColor : null, cW: cr?.width ?? 0 };
	});
	const looks = (g, label) => {
		ctx.ok(g.w >= 0.9 * g.colW, `${label}: Breite ≥ 90 % der Notizspalte`, `${g.w}/${g.colW}`);
		ctx.ok(g.bg !== 'rgba(0, 0, 0, 0)' && g.bg !== 'transparent', `${label}: blasser Hintergrund`, g.bg);
		ctx.ok(g.radius >= 8, `${label}: abgerundet`, `${g.radius}`);
		ctx.ok(g.pl >= 10 && g.pr >= 12, `${label}: Innenabstand links/rechts`, JSON.stringify(g));
	};
	const looksOpen = (g, label) => {
		ctx.ok(g.open && g.pb >= 10 && g.bottomGap >= 10, `${label}: offen, Innenabstand unten`, JSON.stringify(g));
		ctx.ok(g.indent >= g.titleLeft + 12, `${label}: Inhalt unter dem Titeltext eingerückt`, `${g.indent} vs ${g.titleLeft}`);
		ctx.ok(g.cW >= 0.8 * g.w, `${label}: Inhalt volle Breite`, `${g.cW}/${g.w}`);
	};

	const m = ctx.mark();
	// gespeicherte Breite aus früheren Specs nicht übernehmen
	await page.goto(ctx.appUrl('index.php'), { waitUntil: 'networkidle' });
	await page.evaluate(() => { try { localStorage.removeItem('leftColWidth'); localStorage.removeItem('sidebarCollapsed'); } catch {} });
	await page.goto(ctx.appUrl(`index.php?workspace=${encodeURIComponent(WS)}&note=${id}`), { waitUntil: 'networkidle' });

	const lw = await page.locator('#left_col').evaluate((el) => el.getBoundingClientRect().width);
	ctx.ok(Math.abs(lw - 248) <= 2, 'Seitenleiste Standardbreite 248 px', `${lw}`);
	const ph = await page.locator('#left_col input[id^="unified-search"]:visible').getAttribute('placeholder').catch(() => null);
	ctx.ok(ph === 'Suchen…', 'Suchfeld-Platzhalter „Suchen…“', String(ph));

	// 1. Einfügen wie ein Benutzer: /, Einfügen → Aufklappbereich, Titel, Enter, Inhalt
	await page.locator(`#entry${id}`).click();
	await page.keyboard.press('Control+End');
	await page.keyboard.press('Enter');
	await page.keyboard.type('/');
	await page.locator('.slash-command-menu').first().waitFor({ state: 'visible', timeout: 3000 });
	await page.locator('.slash-command-menu [data-command-id="insert"]').hover();
	const item = page.locator('.slash-command-submenu [data-submenu-id="toggle"]').first();
	ctx.ok((await item.innerText()).includes('Aufklappbereich'), 'Slash-Menü: „Aufklappbereich“', await item.innerText());
	await item.click();
	await page.waitForTimeout(300);
	await page.keyboard.type(`Lösung ${RUN}`);
	await page.keyboard.press('Enter');
	await page.keyboard.type(`Inhalt ${RUN}`);
	const sel = `#entry${id} details.toggle-block`;
	const dom = await page.locator(sel).evaluate((el) => ({ n: el.querySelectorAll('summary').length, title: el.querySelector('summary').textContent,
		body: el.querySelector('.toggle-content')?.textContent ?? '' }));
	ctx.ok(dom.n === 1 && dom.title === `Lösung ${RUN}`, 'Titel ersetzt den Platzhalter, ein <summary>', JSON.stringify(dom));
	ctx.ok(dom.body.includes(`Inhalt ${RUN}`), 'Enter im Titel springt in den Inhalt', JSON.stringify(dom));
	const g1 = await geo(sel);
	looks(g1, 'eingefügt');
	looksOpen(g1, 'eingefügt');
	ctx.ok(g1.cBorder === '0px' && g1.cBg === 'rgba(0, 0, 0, 0)', 'Inhalt ohne Eingabefeld-Rahmen (NC-Kern)', `${g1.cBorder} ${g1.cBg}`);
	await ctx.screenshot(page, 'toggle-open');
	ctx.ok(await waitFor(async () => (await content(id)).includes(`Inhalt ${RUN}`)), 'Autosave speichert den Bereich');

	// 2. Neuladen: Upstream klappt beim Laden zu; Klick auf Titel öffnet, erneut schließt
	await page.reload({ waitUntil: 'networkidle' });
	await page.waitForSelector(sel);
	const g2 = await geo(sel);
	ctx.ok(!g2.open && g2.h < 60, 'nach Neuladen zugeklappt, nur Titelzeile', JSON.stringify(g2));
	looks(g2, 'zugeklappt');
	await ctx.screenshot(page, 'toggle-closed');
	await page.locator(`${sel} > summary`).click();
	await page.waitForTimeout(200);
	const g3 = await geo(sel);
	ctx.ok(g3.open, 'Klick auf Titel klappt auf', JSON.stringify(g3));
	looksOpen(g3, 'aufgeklappt');
	ctx.ok((await page.locator(`${sel} .toggle-content`).innerText()).includes(`Inhalt ${RUN}`), 'Inhalt nach Neuladen da');
	await page.locator(`${sel} > summary`).click({ position: { x: 4, y: 10 } });
	await page.waitForTimeout(200);
	ctx.ok(!(await geo(sel)).open, 'Klick auf Pfeil klappt zu');

	// 3. Markdown-Vorschau: gleiche Optik
	const md = (await api.must('POST', '/notes', { heading: `Toggle Md ${RUN}`, workspace: WS, type: 'markdown', content: MD })).note.id;
	await page.goto(ctx.appUrl(`index.php?workspace=${encodeURIComponent(WS)}&note=${md}`), { waitUntil: 'networkidle' });
	const msel = `#note${md} details.toggle-block, #entry${md} details.toggle-block`;
	await page.waitForSelector(msel, { timeout: 8000 });
	const g4 = await geo(msel);
	looks(g4, 'Markdown');
	await page.locator(msel).first().locator(':scope > summary').click();
	await page.waitForTimeout(200);
	const g5 = await geo(msel);
	looksOpen(g5, 'Markdown');
	await ctx.screenshot(page, 'toggle-markdown');

	const texts = await page.evaluate(() => window.t ? [window.t('slash_menu.toggle'), window.t('slash_menu.toggle_placeholder'),
		window.t('slash_menu.toggle_content_placeholder')] : []);
	ctx.ok(texts[0] === 'Aufklappbereich' && texts[1] === 'Titel' && /^Inhalt/.test(texts[2] ?? ''), 'Deutsche Texte des Aufklappbereichs', JSON.stringify(texts));
	ctx.assertClean('Aufklappbereich', m);
});
