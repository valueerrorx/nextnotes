// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// UI-fein 4 (Thomas 2026-11-08): Ansichten linksbündig wie NC-Apps. Je Seite (1400 px und mobil 390 px): Seitentitel beginnt an der linken Kante
// der Inhaltsfläche plus Innenabstand --nn-view-pad, Titel und Container nicht zentriert, kein waagerechtes Überlaufen; Hauptansicht: Werkzeugleiste links.
import { runSpec } from './lib/harness.mjs';
import { apiClient } from './lib/api.mjs';

await runSpec(import.meta.url, async (ctx, page) => {
	const api = apiClient(ctx, page.request);
	const ws = await api.defaultWorkspace();
	const n = await api.note(ws, `Links ${Date.now().toString(36)}`, null, '<p>Text</p>');
	const wq = `workspace=${encodeURIComponent(ws)}`;
	const fname = `Board ${Date.now().toString(36)}`;
	const fid = await api.folder(ws, fname);
	await api.note(ws, `Karte ${Date.now().toString(36)}`, fid, '<p>k</p>');
	const pages = ['settings.php', 'dashboard.php', 'tasks.php', 'diary.php', 'speed_dial.php', 'mentions.php', 'notes_manager.php', 'trash.php',
		'attachments_list.php', 'list_tags.php', 'list_folders.php', 'graph.php', 'create.php', `revisions.php?note_id=${n}`, `attachments.php?note_id=${n}`];
	for (const width of [1400, 390]) {
		await page.setViewportSize({ width, height: 900 });
		const m = ctx.mark();
		for (const p of pages) {
			await page.goto(ctx.appUrl(`${p}${p.includes('?') ? '&' : '?'}${wq}`), { waitUntil: 'networkidle' });
			const r = await page.evaluate(() => {
				const root = document.getElementById('nextnotes-root');
				const main = root.querySelector(':scope > .nn-page-main') ?? root;
				// Unterseiten ohne Leiste: Zurück-Knopf statt Seitentitel; Innenabstand 16–24 px (Upstream-Raster der Verlaufsseite 24 px)
				const sub = root.classList.contains('nextnotes-subpage');
				const title = sub ? root.querySelector('.poznote-back-to-note-btn') : main.querySelector('.poznote-page-title');
				const pad = sub ? null : parseFloat(getComputedStyle(main).paddingLeft);
				const cs = title ? getComputedStyle(sub ? title.parentElement : title) : null;
				const first = (sub ? title : title?.firstElementChild)?.getBoundingClientRect();
				return { sub, pad, mainLeft: main.getBoundingClientRect().left, titleLeft: first?.left ?? null, jc: cs?.justifyContent, ta: cs?.textAlign,
					overflow: main.scrollWidth - main.clientWidth,
					over: [...main.querySelectorAll('*')].filter((e) => e.getBoundingClientRect().right > main.getBoundingClientRect().left + main.clientWidth + 0.5)
						.slice(0, 4).map((e) => `${e.tagName}.${String(e.className).split(' ')[0]}`).join(' ') };
			});
			const label = `${width} ${p.split('?')[0]}`;
			if (r.titleLeft === null) { ctx.ok(false, `${label}: Seitentitel vorhanden`); continue; }
			const dx = Math.round(r.titleLeft - r.mainLeft);
			ctx.ok(r.sub ? dx >= 12 && dx <= 28 : r.pad >= 12 && Math.abs(dx - r.pad) <= 4, `${label}: Titel links an Inhaltsfläche + Innenabstand`, `dx=${dx} pad=${r.pad}`);
			ctx.ok(r.jc !== 'center' && r.ta !== 'center', `${label}: Titel nicht zentriert`, `${r.jc}/${r.ta}`);
			ctx.ok(r.overflow <= 1, `${label}: kein waagerechtes Überlaufen`, `${r.overflow} ${r.over}`);
			if (width === 1400 && ['settings.php', 'dashboard.php', 'tasks.php'].includes(p)) await ctx.screenshot(page, p.replace('.php', ''));
		}
		// Hauptansicht: Werkzeugleiste und Notiz links
		await page.goto(ctx.appUrl(`index.php?${wq}&note=${n}`), { waitUntil: 'networkidle' });
		if (width === 390) await page.waitForTimeout(300);
		const idx = await page.evaluate((id) => {
			const tb = document.querySelector(`#note${id} .note-edit-toolbar`);
			const btn = [...tb.querySelectorAll('.toolbar-btn')].find((b) => b.offsetParent !== null);
			const entry = document.getElementById(`entry${id}`);
			return { jc: getComputedStyle(tb).justifyContent, btn: btn.getBoundingClientRect().left, tb: tb.getBoundingClientRect().left, entry: entry.getBoundingClientRect().left };
		}, n);
		ctx.ok(idx.jc !== 'center' && idx.btn - idx.tb <= 16, `${width} Hauptansicht: Werkzeugleiste links`, JSON.stringify(idx));
		ctx.ok(Math.abs(idx.btn - idx.entry) <= 40, `${width} Hauptansicht: Werkzeugleiste fluchtet mit dem Notiztext`, JSON.stringify(idx));
		if (width === 1400) {
			await ctx.screenshot(page, 'index');
			// Kanban aus der Ordnerzeile: Titel und erste Spalte links
			const row = page.locator('#left_col .folder-toggle', { hasText: fname }).first();
			await row.hover();
			await row.locator('.nn-folder-quick[data-action="open-kanban-view"]').click();
			await page.locator('#right_pane .kanban-board .kanban-column').first().waitFor({ state: 'visible', timeout: 8000 });
			const kb = await page.evaluate(() => {
				const pane = document.getElementById('right_pane').getBoundingClientRect().left;
				const t = document.querySelector('#right_pane .kanban-title').getBoundingClientRect().left;
				const c = document.querySelector('#right_pane .kanban-board .kanban-column').getBoundingClientRect().left;
				return { title: Math.round(t - pane), column: Math.round(c - pane) };
			});
			ctx.ok(kb.title <= 80 && Math.abs(kb.title - kb.column) <= 4, 'Kanban: Titel links, fluchtet mit der ersten Spalte', JSON.stringify(kb));
			await ctx.screenshot(page, 'kanban');
		}
		ctx.assertClean(`Seiten ${width}`, m);
	}
});
