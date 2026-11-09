// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// UI-treeicon (SPEC §4.3 Nr. 33): Ordner-/Notiz-Icons im Baum der Hauptansicht behalten ihre Größe bei langen Namen,
// Namen enden mit Auslassung, nichts ragt aus der Zeile. 1400 px und schmale Leiste. 0 CSP/Konsole.
import { runSpec } from './lib/harness.mjs';
import { apiClient } from './lib/api.mjs';

const RUN = `${process.argv[3]}${Date.now().toString(36)}`;
const LONG = 'Ein sehr langer Ordnername für die Oberstufe Mathematik Analysis und Stochastik';

await runSpec(import.meta.url, async (ctx, page) => {
	const api = apiClient(ctx, page.request);
	const WS = await api.workspace(`Baum ${RUN}`);
	const shortF = await api.folder(WS, `Kurz ${RUN}`);
	const longF = await api.folder(WS, `${LONG} ${RUN}`);
	await api.note(WS, `Notiz kurz ${RUN}`, shortF);
	const longN = await api.note(WS, `Eine Notiz mit einem ausgesprochen langen Titel über Integralrechnung ${RUN}`, longF);
	const m = ctx.mark();
	await page.goto(ctx.appUrl('index.php'), { waitUntil: 'networkidle' });
	await page.evaluate(() => { try { localStorage.removeItem('leftColWidth'); } catch {} });

	const rowGeo = (fid) => page.locator(`#left_col .folder-header[data-folder-key="folder_${fid}"] > .folder-toggle`).evaluate((row) => {
		const icon = row.querySelector('.folder-icon');
		const name = row.querySelector('.folder-name');
		const r = row.getBoundingClientRect();
		const ir = icon?.getBoundingClientRect();
		const nr = name?.getBoundingClientRect();
		return { iconW: ir?.width ?? 0, iconH: ir?.height ?? 0, iconVis: !!icon && getComputedStyle(icon).display !== 'none' && ir.width > 0,
			nameRight: nr?.right ?? 0, rowRight: r.right, ellipsis: name ? name.scrollWidth > name.clientWidth : false };
	});
	const noteIcon = (id) => page.locator(`#left_col a.links_arbo_left[data-note-id="${id}"] .note-icon, #left_col a.links_arbo_left[href*="note=${id}"] .note-icon`).first()
		.evaluate((el) => el.getBoundingClientRect().width).catch(() => -1);

	for (const width of [null, 210]) {
		await page.goto(ctx.appUrl(`index.php?workspace=${encodeURIComponent(WS)}&note=${longN}`), { waitUntil: 'networkidle' });
		if (width) {
			await page.evaluate((w) => document.documentElement.style.setProperty('--left-col-width', `${w}px`), width);
			await page.waitForTimeout(200);
		}
		const label = width ? `Leiste ${width} px` : 'Standardbreite';
		const s = await rowGeo(shortF);
		const l = await rowGeo(longF);
		ctx.ok(l.iconVis, `${label}: Icon des langen Ordners sichtbar`, JSON.stringify(l));
		ctx.ok(Math.abs(l.iconW - s.iconW) <= 0.5 && Math.abs(l.iconH - s.iconH) <= 0.5, `${label}: Icon gleich groß wie beim kurzen Namen`, `${l.iconW}x${l.iconH} vs ${s.iconW}x${s.iconH}`);
		ctx.ok(l.ellipsis && l.nameRight <= l.rowRight + 0.5, `${label}: langer Name gekürzt, ragt nicht heraus`, JSON.stringify(l));
		const ni = await noteIcon(longN);
		ctx.ok(ni < 0 || ni >= s.iconW - 0.5, `${label}: Notiz-Icon nicht geschrumpft`, `${ni}`);
		await ctx.screenshot(page, width ? 'narrow' : 'default');
	}
	ctx.assertClean('Baum', m);
});
