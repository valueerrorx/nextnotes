// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// UI-nav-mobil: 390×844 – Icon-Leiste neben dem Inhalt auf allen Unterseiten, unteres Ende sichtbar, Notiz/Kanban öffnen und zurück,
// Leiste einklappen; Desktop: Einklapp-Pfeil in der Leistenfläche.
import { runSpec } from './lib/harness.mjs';
import { apiClient } from './lib/api.mjs';

const GEO = () => {
	const vis = (e) => { if (!e) return null; const c = getComputedStyle(e); if (c.display === 'none' || c.visibility === 'hidden') return null; const r = e.getBoundingClientRect(); return r.width && r.height ? { l: r.left, t: r.top, r: r.right, b: r.bottom, w: r.width, h: r.height } : null; };
	const q = (s) => vis(document.querySelector(s));
	return { rail: q('#icon_sidebar'), toggle: q('#iconSidebarToggle'), main: q('#nextnotes-root > .nn-page-main'), left: q('#nextnotes-root > #left_col'), pane: q('#nextnotes-root > #right_pane'), root: q('#nextnotes-root'),
		vw: innerWidth, vh: innerHeight, sw: document.documentElement.scrollWidth, noteOpen: document.body.classList.contains('note-open') };
};
const overlap = (a, b) => (a && b ? Math.max(0, Math.min(a.r, b.r) - Math.max(a.l, b.l)) * Math.max(0, Math.min(a.b, b.b) - Math.max(a.t, b.t)) : 0);
const inside = (a, b) => !!(a && b && a.l >= b.l - 0.5 && a.r <= b.r + 0.5 && a.t >= b.t - 0.5 && a.b <= b.b + 0.5);

await runSpec(import.meta.url, async (ctx, page) => {
	const api = apiClient(ctx, page.request);
	const WS = await api.defaultWorkspace();
	const ws = encodeURIComponent(WS);
	const fname = `Mobil ${Date.now()}`;
	const f = await api.folder(WS, fname);
	await api.note(WS, 'Mobile Notiz', f, '<p>Ein Absatz Text.</p>');
	await page.evaluate(() => localStorage.removeItem('iconSidebarCollapsed'));

	// Desktop: Pfeil in der Leistenfläche, ein- und ausklappen
	await page.setViewportSize({ width: 1400, height: 900 });
	await page.goto(ctx.appUrl(`tasks.php?workspace=${ws}`), { waitUntil: 'networkidle' });
	let g = await page.evaluate(GEO);
	ctx.ok(inside(g.toggle, g.rail), 'Desktop: Pfeil innerhalb der Icon-Leiste', JSON.stringify([g.toggle, g.rail]));
	ctx.ok(overlap(g.toggle, g.main) === 0, 'Desktop: Pfeil nicht über dem Inhalt');
	ctx.ok(g.rail && g.root && g.rail.b <= g.root.b + 0.5, 'Desktop: Leiste endet in der App');
	await ctx.screenshot(page, 'desktop-toggle');
	await page.click('#iconSidebarToggle');
	g = await page.evaluate(GEO);
	ctx.ok(!g.rail && !!g.toggle, 'Desktop: eingeklappt – Leiste weg, Pfeil sichtbar');
	ctx.ok(g.toggle && g.root && inside(g.toggle, g.root), 'Desktop: eingeklappt – Pfeil im App-Bereich');
	await ctx.screenshot(page, 'desktop-collapsed');
	await page.click('#iconSidebarToggle');
	g = await page.evaluate(GEO);
	ctx.ok(!!g.rail && inside(g.toggle, g.rail), 'Desktop: ausgeklappt – Pfeil wieder in der Leiste');

	// Mobil: alle Unterseiten
	await page.setViewportSize({ width: 390, height: 844 });
	const pages = ['dashboard.php', 'tasks.php', 'diary.php', 'graph.php', 'speed_dial.php', 'notes_manager.php', 'list_folders.php', 'list_tags.php', 'attachments_list.php', 'trash.php', 'create.php', 'settings.php', 'mentions.php'];
	for (const p of pages) {
		const resp = await page.goto(ctx.appUrl(`${p}?workspace=${ws}`), { waitUntil: 'networkidle' });
		if (p === 'mentions.php' && resp && resp.status() !== 200) { ctx.info(`${p}: ${resp.status()} (kein geteilter Workspace), übersprungen`); continue; }
		await page.waitForTimeout(150);
		g = await page.evaluate(GEO);
		ctx.ok(!!g.rail && !!g.main, `${p}: Leiste und Inhalt sichtbar`);
		ctx.ok(overlap(g.rail, g.main) === 0, `${p}: keine Überlappung Leiste/Inhalt`, JSON.stringify([g.rail, g.main]));
		ctx.ok(g.main && g.main.b <= g.vh + 0.5 && g.rail.b <= g.vh + 0.5, `${p}: unteres Ende sichtbar`, `${g.main?.b}/${g.rail?.b}/${g.vh}`);
		ctx.ok(g.sw <= g.vw + 1, `${p}: kein horizontaler Überlauf`, `${g.sw}/${g.vw}`);
		ctx.ok(inside(g.toggle, g.rail), `${p}: Pfeil in der Leiste`);
		await ctx.screenshot(page, p.replace('.php', ''));
	}

	// Leiste mobil einklappen: Inhalt volle Breite
	await page.goto(ctx.appUrl(`tasks.php?workspace=${ws}`), { waitUntil: 'networkidle' });
	await page.click('#iconSidebarToggle');
	g = await page.evaluate(GEO);
	ctx.ok(!g.rail && g.main && Math.abs(g.main.l - g.root.l) < 1 && Math.abs(g.main.r - g.root.r) < 1, 'mobil eingeklappt: Inhalt volle Breite');
	await ctx.screenshot(page, 'tasks-collapsed');
	await page.click('#iconSidebarToggle');
	g = await page.evaluate(GEO);
	ctx.ok(!!g.rail && overlap(g.rail, g.main) === 0, 'mobil ausgeklappt: Leiste zurück, keine Überlappung');

	// Hauptansicht: Liste → Notiz → zurück
	await page.goto(ctx.appUrl(`index.php?workspace=${ws}`), { waitUntil: 'networkidle' });
	g = await page.evaluate(GEO);
	ctx.ok(!!g.rail && !!g.left && !g.pane, 'Liste: Leiste und Seitenleiste, Notizbereich verborgen');
	ctx.ok(overlap(g.rail, g.left) === 0, 'Liste: keine Überlappung Leiste/Seitenleiste', JSON.stringify([g.rail, g.left]));
	ctx.ok(g.left && g.left.b <= g.vh + 0.5, 'Liste: unteres Ende sichtbar', `${g.left?.b}/${g.vh}`);
	ctx.ok(g.sw <= g.vw + 1, 'Liste: kein horizontaler Überlauf');
	await ctx.screenshot(page, 'list');
	const link = page.locator('#left_col a.links_arbo_left', { hasText: 'Mobile Notiz' }).first();
	if (!(await link.isVisible())) await page.locator('#left_col .folder-toggle', { hasText: fname }).first().click();
	await link.waitFor({ state: 'visible', timeout: 5000 });
	await link.click();
	await page.waitForFunction(() => document.body.classList.contains('note-open'), null, { timeout: 5000 }).catch(() => {});
	await page.waitForTimeout(300);
	g = await page.evaluate(GEO);
	ctx.ok(g.noteOpen && !!g.pane && !g.left && !g.rail, 'Notiz: Editor statt Liste', JSON.stringify(g));
	ctx.ok(g.pane && g.pane.l >= 0 && g.pane.r <= g.vw + 0.5 && g.pane.w > 300 && g.pane.b <= g.vh + 0.5, 'Notiz: Notizbereich ganz im Bild', JSON.stringify(g.pane));
	ctx.ok(await page.locator('#right_pane .noteentry').first().isVisible(), 'Notiz: Editor sichtbar');
	ctx.ok(await page.locator('#right_pane .noteentry', { hasText: 'Ein Absatz Text.' }).count() === 1, 'Notiz: Inhalt geladen');
	await ctx.screenshot(page, 'note');
	await page.locator('#right_pane .mobile-home-btn').first().click();
	await page.waitForTimeout(400);
	g = await page.evaluate(GEO);
	ctx.ok(!g.noteOpen && !!g.left && !!g.rail && !g.pane, 'Zurück: Liste wieder sichtbar');
	ctx.ok(await link.isVisible(), 'Zurück: Notiz-Link sichtbar (Ordner offen)');

	// Kanban aus der Ordnerzeile
	const row = page.locator('#left_col .folder-toggle', { hasText: fname }).first();
	await row.hover();
	await row.locator('.nn-folder-quick[data-action="open-kanban-view"]').click();
	await page.waitForTimeout(800);
	g = await page.evaluate(GEO);
	ctx.ok(g.noteOpen && !!g.pane && !g.left, 'Kanban: Board statt Liste');
	ctx.ok(await page.locator('#right_pane .kanban-board').first().isVisible(), 'Kanban: Board sichtbar');
	ctx.ok(await page.locator('#right_pane .kanban-board .kanban-card-title', { hasText: 'Mobile Notiz' }).first().isVisible(), 'Kanban: Karte der Notiz sichtbar');
	await ctx.screenshot(page, 'kanban');
	await page.locator('#right_pane .kanban-mobile-home').click();
	await page.waitForTimeout(400);
	g = await page.evaluate(GEO);
	ctx.ok(!g.noteOpen && !!g.left && !g.pane, 'Kanban: Zurück-Knopf zeigt die Liste');
	// Browser-Zurück aus der Notiz
	await link.click();
	await page.waitForFunction(() => document.body.classList.contains('note-open'), null, { timeout: 5000 }).catch(() => {});
	await page.waitForTimeout(300);
	// Verlauf: Notiz → Kanban → Liste
	for (let i = 0; i < 3 && /[?&](note|kanban)=/.test(page.url()); i++) { await page.goBack(); await page.waitForTimeout(500); }
	g = await page.evaluate(GEO);
	ctx.ok(!g.noteOpen && !!g.left && !g.pane, 'Browser-Zurück: Liste statt Notiz', page.url());
	ctx.assertClean('mobil');
	await page.evaluate(() => localStorage.removeItem('iconSidebarCollapsed'));
});
