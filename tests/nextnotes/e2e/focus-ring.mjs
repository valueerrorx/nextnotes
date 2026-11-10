// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// UI-taskfocus (SPEC §4.3 Nr. 23): App-Textfelder zeigen beim Fokus einen sichtbaren Rahmen im NC-Stil
// (Rahmen + 1-px-Ring in --color-main-text, Kontrast ≥ 3:1 zum Hintergrund). Aufgabenliste (.task-input per Tastatur
// und Klick, Bearbeiten-Feld), Suchfeld der Seitenleiste, Eingabe-Dialog. 0 CSP/Konsole.
import { runSpec } from './lib/harness.mjs';
import { apiClient } from './lib/api.mjs';

const RUN = `${process.argv[3]}${Date.now().toString(36)}`;

await runSpec(import.meta.url, async (ctx, page) => {
	const api = apiClient(ctx, page.request);
	const WS = await api.workspace(`Fokus ${RUN}`);
	const F = await api.folder(WS, `Ordner ${RUN}`);
	const tasks = JSON.stringify([{ id: 1, text: 'Lesen', completed: false, important: false, noteId: 0 }]);
	const tl = (await api.must('POST', '/notes', { heading: `Liste ${RUN}`, type: 'tasklist', content: tasks, workspace: WS, folder_id: F })).note.id;
	const m = ctx.mark();

	// Stil ablesen (nach Übergang), Ring-/Rahmenfarbe gegen Seitenhintergrund (WCAG-Kontrast)
	const style = (loc) => loc.evaluate((e) => {
		const s = getComputedStyle(e);
		return { shadow: s.boxShadow, outline: `${s.outlineStyle} ${s.outlineWidth} ${s.outlineColor}`, border: `${s.borderTopWidth} ${s.borderTopColor}` };
	});
	// Unfokussierter Zustand; holt ein Dialog den Fokus zurück (Fokusfalle), dann eine unfokussierte Kopie daneben
	const unfocused = (loc) => loc.evaluate((e) => {
		let t = e;
		if (e.matches(':focus')) {
			t = e.cloneNode(false);
			t.removeAttribute('id');
			e.after(t);
		}
		const s = getComputedStyle(t);
		const v = { shadow: s.boxShadow, outline: `${s.outlineStyle} ${s.outlineWidth} ${s.outlineColor}`, border: `${s.borderTopWidth} ${s.borderTopColor}` };
		if (t !== e) t.remove();
		return v;
	});
	const contrast = async (loc) => loc.evaluate((e) => {
		const rgba = (c) => (c.match(/[\d.]+/g) || []).map(Number);
		const rgb = (c) => rgba(c).slice(0, 3);
		const lum = ([r, g, b]) => [r, g, b].map((v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; })
			.reduce((a, v, i) => a + v * [0.2126, 0.7152, 0.0722][i], 0);
		const probe = document.createElement('div');
		probe.style.color = 'var(--color-main-background)';
		document.body.append(probe);
		const bg = rgb(getComputedStyle(probe).color);
		probe.remove();
		const ratio = (col) => { const [a, b] = [lum(col), lum(bg)].sort((x, y) => y - x); return (a + 0.05) / (b + 0.05); };
		const s = getComputedStyle(e);
		// Ring: erste Farbe im box-shadow, nur deckend zählt
		const ringCol = (s.boxShadow.match(/rgba?\([^)]*\)/) || [''])[0];
		const ringA = rgba(ringCol);
		const ring = ringCol && (ringA.length < 4 || ringA[3] === 1) ? ratio(ringA.slice(0, 3)) : 0;
		return { border: ratio(rgb(s.borderTopColor)), ring };
	});
	const check = async (label, loc, focus) => {
		// Fokus auf einen Hilfsknopf daneben (Dialoge holen den Fokus nach blur() zurück)
		await loc.evaluate((e) => { const b = document.createElement('button'); b.id = 'nn-fr-tmp'; e.parentElement.append(b); b.focus(); });
		await page.waitForTimeout(400);
		const before = await unfocused(loc);
		await focus();
		await page.waitForTimeout(400);
		await page.evaluate(() => document.getElementById('nn-fr-tmp')?.remove());
		ctx.ok(await loc.evaluate((e) => e === document.activeElement), `${label}: hat den Fokus`);
		const after = await style(loc);
		ctx.ok(after.shadow !== before.shadow && after.border !== before.border, `${label}: Fokus sichtbar (Rahmen und Ring ändern sich)`,
			`vorher ${JSON.stringify(before)} nachher ${JSON.stringify(after)}`);
		const c = await contrast(loc);
		ctx.ok(c.border >= 3 && c.ring >= 3, `${label}: Rahmen und Ring deckend, Kontrast zum Hintergrund ≥ 3:1 (${c.border.toFixed(1)} / ${c.ring.toFixed(1)})`);
	};

	await page.goto(ctx.appUrl(`index.php?workspace=${encodeURIComponent(WS)}&note=${tl}`), { waitUntil: 'networkidle' });
	const input = page.locator(`#note${tl} .task-input`).first();
	await input.waitFor({ state: 'visible', timeout: 10000 });
	await check('Aufgabe hinzufügen (Tastatur)', input, async () => {
		// Fokus vor das Feld (Titel) setzen, dann per Tab weiter bis zum Eingabefeld
		await page.locator(`#note${tl} .css-title, #inp${tl}`).first().focus().catch(() => {});
		for (let i = 0; i < 15 && !(await input.evaluate((e) => e === document.activeElement)); i++) await page.keyboard.press('Tab');
	});
	ctx.ok(await input.evaluate((e) => e.matches(':focus-visible')), 'Aufgabe hinzufügen (Tastatur): :focus-visible');
	await check('Aufgabe hinzufügen (Klick)', input, () => input.click());
	await ctx.screenshot(page, 'task-input');

	// Bearbeiten-Feld der Aufgabe (Klick auf den Text öffnet es)
	await page.locator(`#note${tl} .task-text`).first().click();
	const edit = page.locator('#taskEditTextarea, .task-edit-input').first();
	await edit.waitFor({ state: 'visible', timeout: 10000 });
	await check('Aufgabe bearbeiten', edit, () => edit.focus());
	await page.keyboard.press('Escape');
	await page.waitForTimeout(300);

	const search = page.locator('#nextnotes-root .searchbar-input').first();
	await check('Suchfeld Seitenleiste', search, () => search.click());

	// Eingabe-Dialog (ui.js; Kanban, Ordner u. a.)
	await page.evaluate(() => window.showInputModal('Test', 'x', '', () => {}));
	const modalInput = page.locator('#inputModalInput');
	await modalInput.waitFor({ state: 'visible', timeout: 10000 });
	await check('Eingabe-Dialog', modalInput, () => modalInput.click());
	await page.keyboard.press('Escape');
	await page.evaluate(() => { const d = document.getElementById('inputModal'); if (d) d.style.display = 'none'; });

	ctx.assertClean('focus-ring', m);
});
