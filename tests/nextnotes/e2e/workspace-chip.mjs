// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// UI-ws (Thomas 2026-11-08): jede workspacegebundene Ansicht zeigt im Titel eine Workspace-Auswahl (NC-Sekundärknopf, links, Pfeil rechts);
// Klick öffnet das Workspace-Menü (GET api/v1/workspaces), Wahl lädt dieselbe Ansicht mit ?workspace= und zeigt deren Inhalte.
// Dashboard zeigt seinen Bereich (Scope-Knopf), Erwähnungen „Alle Arbeitsbereiche“ ohne Auswahl, Einstellungen keine Auswahl (Lead 2026-11-08).
// Kopfzeile: Mitten von Icon, Titeltext (Versalhöhe) und Chip je Ansicht ±1 px. Hauptansicht: Seitenleisten-Kopf gleiche Optik, links.
import { runSpec } from './lib/harness.mjs';
import { apiClient } from './lib/api.mjs';

const BOUND = ['list_tags.php', 'tasks.php', 'diary.php', 'notes_manager.php', 'trash.php', 'attachments_list.php',
	'list_folders.php', 'graph.php', 'create.php'];

await runSpec(import.meta.url, async (ctx, page) => {
	const api = apiClient(ctx, page.request);
	const id = Date.now().toString(36);
	const ws1 = await api.defaultWorkspace();
	const ws2 = await api.workspace(`Chip ${id}`);
	const note1 = `Eins ${id}`;
	const note2 = `Zwei ${id}`;
	const folder2 = `Ordner ${id}`;
	await api.note(ws1, note1, null, '<p>a</p>');
	const fid = await api.folder(ws2, folder2);
	await api.note(ws2, note2, fid, '<p>b</p>');
	const q = (ws) => `workspace=${encodeURIComponent(ws)}`;
	const transparent = (c) => !c || c === 'transparent' || /rgba\(0, 0, 0, 0\)/.test(c);
	const m = ctx.mark();
	// Mitten im Kopf: Icon-Box, Versalien des Titels (Schriftmetrik), Chip; chip null = Chip in eigener Zeile (schmal umgebrochen)
	const centers = () => page.evaluate(() => {
		const h = document.querySelector('#nextnotes-root > .nn-page-main .poznote-page-title');
		const name = h.querySelector('.poznote-page-title-name');
		const tn = [...name.childNodes].find((n) => n.nodeType === 3 && n.textContent.trim());
		const st = getComputedStyle(name);
		const c = document.createElement('canvas').getContext('2d');
		c.font = `${st.fontWeight} ${st.fontSize} ${st.fontFamily}`;
		const mt = c.measureText('H');
		const rg = document.createRange();
		rg.selectNodeContents(tn);
		const line = rg.getClientRects()[0];
		const mid = (b) => b.top + b.height / 2;
		const chipEl = h.querySelector('.poznote-page-title-workspace');
		const chip = chipEl?.getBoundingClientRect();
		const nb = name.getBoundingClientRect();
		return { icon: mid(name.querySelector('i').getBoundingClientRect()), text: line.top + mt.fontBoundingBoxAscent - mt.actualBoundingBoxAscent / 2,
			chip: chip && chip.top < nb.bottom ? mid(chip) : null };
	});
	const checkCenters = async (label) => {
		const c = await centers();
		const ok = Math.abs(c.icon - c.text) <= 1 && (c.chip === null || (Math.abs(c.chip - c.text) <= 1 && Math.abs(c.chip - c.icon) <= 1));
		ctx.ok(ok, `${label}: Icon, Titel und Chip vertikal mittig (±1 px)`, JSON.stringify(c));
	};

	// Optik und Lage des Chips auf jeder gebundenen Ansicht (1400 und 390 px)
	for (const width of [1400, 390]) {
		await page.setViewportSize({ width, height: 900 });
		for (const p of BOUND) {
			await page.goto(ctx.appUrl(`${p}?${q(ws1)}`), { waitUntil: 'networkidle' });
			const label = `${width} ${p}`;
			const r = await page.evaluate(() => {
				const main = document.querySelector('#nextnotes-root > .nn-page-main');
				const chip = main?.querySelector('.poznote-page-title [data-nn-ws-chip]');
				if (!chip) return null;
				const c = chip.getBoundingClientRect();
				const h = main.querySelector('.poznote-page-title-name').getBoundingClientRect();
				const a = main.getBoundingClientRect();
				const cs = getComputedStyle(chip);
				const chev = chip.querySelector('.poznote-page-title-workspace-chevron').getBoundingClientRect();
				return { text: chip.querySelector('.poznote-page-title-workspace-name').textContent.trim(), tag: chip.tagName, bg: cs.backgroundColor,
					radius: parseFloat(cs.borderTopLeftRadius), h: c.height, vis: c.width > 0 && c.height > 0, headLeft: Math.round(h.left - a.left),
					right: c.right <= a.right + 0.5, chevRight: c.right - chev.right, beside: c.left >= h.left, overflow: main.scrollWidth - main.clientWidth };
			});
			if (!ctx.ok(r !== null, `${label}: Workspace-Auswahl im Titel`)) continue;
			ctx.ok(r.vis && r.tag === 'BUTTON' && r.text === ws1, `${label}: zeigt aktiven Workspace als Knopf`, JSON.stringify(r));
			ctx.ok(!transparent(r.bg) && r.radius >= 6 && r.h >= 30, `${label}: NC-Knopf (Hintergrund, abgerundet, Klickfläche)`, `${r.bg} r=${r.radius} h=${r.h}`);
			ctx.ok(r.chevRight <= 14 && r.beside && r.right, `${label}: Pfeil rechts, Chip neben/unter dem Titel im Bereich`, JSON.stringify(r));
			ctx.ok(r.headLeft >= 14 && r.headLeft <= 22, `${label}: Kopf linksbündig (--nn-view-pad)`, `${r.headLeft}`);
			ctx.ok(r.overflow <= 1, `${label}: kein waagerechtes Überlaufen`, `${r.overflow}`);
			await checkCenters(label);
			if (width === 1400 && p === 'tasks.php') await ctx.screenshot(page, 'tasks');
		}
	}
	await page.setViewportSize({ width: 1400, height: 900 });

	// Menü: Klick öffnet, listet beide Workspaces, aktueller markiert; Escape schließt
	await page.goto(ctx.appUrl(`list_tags.php?${q(ws1)}`), { waitUntil: 'networkidle' });
	await page.click('[data-nn-ws-chip]');
	const menu = page.locator('#nnWorkspaceChipMenu');
	await menu.locator(`a[data-workspace="${ws2}"]`).waitFor({ state: 'visible', timeout: 8000 });
	const mi = await page.evaluate(([a, b]) => {
		const mn = document.getElementById('nnWorkspaceChipMenu');
		const btn = document.querySelector('[data-nn-ws-chip]');
		const r = mn.getBoundingClientRect();
		const cur = mn.querySelector('[aria-checked="true"]');
		return { names: [...mn.querySelectorAll('a[data-workspace]')].map((x) => x.dataset.workspace), cur: cur?.dataset.workspace, expanded: btn.getAttribute('aria-expanded'),
			below: r.top >= btn.getBoundingClientRect().bottom - 1, inView: r.right <= innerWidth && r.left >= 0, manage: !!mn.querySelector('.poznote-page-title-workspace-item-manage'),
			href: mn.querySelector(`a[data-workspace="${b}"]`).getAttribute('href'), a };
	}, [ws1, ws2]);
	ctx.ok(mi.names.includes(ws1) && mi.names.includes(ws2) && mi.cur === ws1 && mi.expanded === 'true', 'Menü listet Workspaces, aktueller markiert', JSON.stringify(mi));
	ctx.ok(mi.below && mi.inView && mi.manage, 'Menü unter dem Chip, im Fenster, mit „Arbeitsbereiche verwalten“', JSON.stringify(mi));
	await ctx.screenshot(page, 'menu');
	await page.keyboard.press('Escape');
	ctx.ok(!(await menu.isVisible()), 'Escape schließt das Menü');

	// Wechsel über den Chip bleibt in der Ansicht und zeigt die Inhalte des neuen Workspace
	const switchTo = async (p, ws) => {
		await page.goto(ctx.appUrl(`${p}?${q(ws1)}`), { waitUntil: 'networkidle' });
		await page.click('[data-nn-ws-chip]');
		await Promise.all([page.waitForURL((u) => u.searchParams.get('workspace') === ws, { timeout: 15000 }),
			menu.locator(`a[data-workspace="${ws}"]`).click()]);
		await page.waitForLoadState('networkidle');
		const u = new URL(page.url());
		const chip = (await page.locator('[data-nn-ws-chip] .poznote-page-title-workspace-name').textContent()).trim();
		ctx.ok(u.pathname.endsWith(`/${p}`) && chip === ws, `${p}: Wechsel bleibt in der Ansicht, Chip zeigt neuen Workspace`, `${u.pathname} ${chip}`);
	};
	await switchTo('notes_manager.php', ws2);
	await page.getByText(note2, { exact: true }).first().waitFor({ state: 'visible', timeout: 10000 }).catch(() => {});
	const nm = await page.evaluate(([a, b]) => ({ two: document.body.innerText.includes(b), one: document.body.innerText.includes(a) }), [note1, note2]);
	ctx.ok(nm.two && !nm.one, 'Notizen-Manager zeigt nach Wechsel die Notizen des neuen Workspace', JSON.stringify(nm));
	await switchTo('list_folders.php', ws2);
	ctx.ok(await page.locator('#foldersList .folder-item', { hasText: folder2 }).count() === 1, 'Ordner-Ansicht zeigt nach Wechsel den Ordner des neuen Workspace');
	await switchTo('tasks.php', ws2);
	const last = await api.setting('last_opened_workspace');
	ctx.info(`last_opened_workspace nach Wechsel: ${JSON.stringify(last.body).slice(0, 120)}`);

	// Dashboard: Bereich statt Workspace-Auswahl (Scope-Knopf öffnet den Bereichsdialog)
	await page.goto(ctx.appUrl(`dashboard.php?${q(ws1)}`), { waitUntil: 'networkidle' });
	const db = await page.evaluate(() => {
		const b = document.querySelector('.poznote-page-title .poznote-page-title-workspace');
		return b ? { action: b.dataset.action, chip: b.hasAttribute('data-nn-ws-chip'), text: b.textContent.trim(), bg: getComputedStyle(b).backgroundColor } : null;
	});
	ctx.ok(db && db.action === 'open-workspace-switcher-modal' && !db.chip && db.text.length > 0 && !transparent(db.bg), 'Dashboard: zeigt Bereich als Scope-Knopf (gleiche Optik)', JSON.stringify(db));
	await checkCenters('dashboard.php');

	// Erwähnungen: workspaceübergreifend, „Alle Arbeitsbereiche“ ohne Auswahl
	await page.goto(ctx.appUrl(`mentions.php?${q(ws1)}`), { waitUntil: 'networkidle' });
	const mt = await page.evaluate(() => {
		const s = document.querySelector('.poznote-page-title .poznote-page-title-workspace');
		return s ? { tag: s.tagName, text: s.textContent.trim(), chev: !!s.querySelector('.poznote-page-title-workspace-chevron') } : null;
	});
	ctx.ok(mt && mt.tag === 'SPAN' && mt.text === 'Alle Arbeitsbereiche' && !mt.chev, 'Erwähnungen: Bereich „Alle Arbeitsbereiche“ ohne Auswahl', JSON.stringify(mt));
	await checkCenters('mentions.php');

	// Einstellungen: gelten überwiegend für alle Workspaces, keine Auswahl im Titel
	await page.goto(ctx.appUrl(`settings.php?${q(ws1)}`), { waitUntil: 'networkidle' });
	ctx.ok(await page.locator('.poznote-page-title').count() === 1 && await page.locator('.poznote-page-title .poznote-page-title-workspace').count() === 0,
		'Einstellungen: Titel ohne Workspace-Auswahl');
	await checkCenters('settings.php');

	// Hauptansicht: Seitenleisten-Kopf links, volle Breite, Hintergrund, Pfeil rechts; Klick öffnet das Workspace-Menü
	for (const width of [1400, 390]) {
		await page.setViewportSize({ width, height: 900 });
		await page.goto(ctx.appUrl(`index.php?${q(ws1)}`), { waitUntil: 'networkidle' });
		const st = await page.evaluate(() => {
			const col = document.getElementById('left_col').getBoundingClientRect();
			const t = document.querySelector('#left_col .sidebar-title');
			const r = t.getBoundingClientRect();
			const txt = t.querySelector('.workspace-title-text').getBoundingClientRect();
			const chev = t.querySelector('.workspace-dropdown-icon').getBoundingClientRect();
			const search = document.querySelector('#left_col .searchbar-row, #left_col .searchbar-container, #left_col form')?.getBoundingClientRect();
			const cs = getComputedStyle(t);
			return { left: Math.round(r.left - col.left), textLeft: Math.round(txt.left - r.left), width: r.width, col: col.width, bg: cs.backgroundColor,
				radius: parseFloat(cs.borderTopLeftRadius), chevRight: Math.round(r.right - chev.right), searchLeft: search ? Math.round(search.left - r.left) : null };
		});
		const label = `${width} Hauptansicht`;
		ctx.ok(st.textLeft <= 40 && st.left <= 24, `${label}: Workspace-Auswahl linksbündig`, JSON.stringify(st));
		ctx.ok(st.width >= st.col * 0.6, `${label}: Auswahl fast volle Seitenleistenbreite`, JSON.stringify(st));
		ctx.ok(!transparent(st.bg) && st.radius >= 6 && st.chevRight <= 14, `${label}: NC-Knopf mit Pfeil rechts`, JSON.stringify(st));
		if (width === 1400) {
			await ctx.screenshot(page, 'index');
			await page.click('#left_col .sidebar-title');
			const item = page.locator('#workspaceMenu .workspace-menu-item[data-workspace-name]', { hasText: ws2 });
			await item.waitFor({ state: 'visible', timeout: 8000 });
			const mm = await page.evaluate(() => {
				const t = document.querySelector('#left_col .sidebar-title').getBoundingClientRect();
				const mn = document.getElementById('workspaceMenu').getBoundingClientRect();
				return { dl: Math.round(mn.left - t.left), below: mn.top >= t.bottom - 1 };
			});
			ctx.ok(mm.below && Math.abs(mm.dl) <= 4, 'Hauptansicht: Menü öffnet links unter der Auswahl', JSON.stringify(mm));
			await ctx.screenshot(page, 'index-menu');
			await item.click();
			await page.waitForFunction((w) => document.querySelector('#left_col .workspace-title-text')?.textContent.trim() === w, ws2, { timeout: 10000 });
			ctx.ok(true, 'Hauptansicht: Wahl im Menü wechselt den Workspace');
		}
	}
	ctx.assertClean('workspace-chip', m);
});
