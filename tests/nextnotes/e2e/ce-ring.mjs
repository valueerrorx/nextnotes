// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// UI-cering (SPEC §4.3 Nr. 23): NC-Kern (inputs.scss) gibt div[contenteditable=true] bei :hover/:focus/:active einen Ring
// (box-shadow 0 0 0 2px Hintergrundfarbe) wie einem Eingabefeld. Im App-Bereich (Notiz-Editor, Aufklappbereich, Tabelle,
// Aufgabenliste, Kanban) darf er nicht greifen; echte Eingabefelder behalten einen sichtbaren Fokus. 0 CSP/Konsole.
import { runSpec } from './lib/harness.mjs';
import { apiClient } from './lib/api.mjs';

const RUN = `${process.argv[3]}${Date.now().toString(36)}`;
const HTML = '<p>Absatz</p><details class="toggle-block" open><summary class="toggle-header">Titel</summary><div class="toggle-content"><p>Inhalt</p></div></details>'
	+ '<table><tbody><tr><td>a</td><td>b</td></tr></tbody></table><p>Ende</p>';

await runSpec(import.meta.url, async (ctx, page) => {
	const api = apiClient(ctx, page.request);
	const WS = await api.workspace(`Ring ${RUN}`);
	const F = await api.folder(WS, `Brett ${RUN}`);
	const html = await api.note(WS, `Ring ${RUN}`, F, HTML);
	const tasks = JSON.stringify([{ id: 1, text: 'Lesen', completed: false, important: false, noteId: 0 }]);
	const tl = (await api.must('POST', '/notes', { heading: `Liste ${RUN}`, type: 'tasklist', content: tasks, workspace: WS, folder_id: F })).note.id;
	const md = (await api.must('POST', '/notes', { heading: `Md ${RUN}`, type: 'markdown', content: '# Md\n\nText', workspace: WS, folder_id: F })).note.id;
	const m = ctx.mark();

	// Alle sichtbaren editierbaren Elemente im App-Bereich: Hover und Fokus, danach Ring/Rahmen ablesen
	const probe = async (label, need = true) => {
		const n = await page.evaluate(() => {
			const els = [...document.querySelectorAll('#nextnotes-root [contenteditable="true"]')].filter((e) => e.getBoundingClientRect().width > 0 && !e.closest('.excalidraw'));
			els.forEach((e, i) => e.setAttribute('data-nn-ring', String(i)));
			return els.length;
		});
		const bad = [];
		let divs = 0;
		for (let i = 0; i < n; i++) {
			const loc = page.locator(`[data-nn-ring="${i}"]`);
			const info = await loc.evaluate((e) => ({ tag: e.tagName, cls: String(e.className).slice(0, 40), id: e.id }));
			if (info.tag === 'DIV') divs++;
			for (const how of ['hover', 'focus']) {
				if (how === 'hover') await loc.hover({ force: true }).catch(() => {}); else await loc.focus().catch(() => {});
				await page.waitForTimeout(60);
				const s = await loc.evaluate((e) => ({ shadow: getComputedStyle(e).boxShadow, state: e.matches(':hover') || e.matches(':focus') }));
				if (s.state && s.shadow !== 'none') bad.push(`${info.tag}#${info.id}.${info.cls} ${how}: ${s.shadow}`);
			}
		}
		ctx.ok(!need || divs > 0, `${label}: ${n} editierbare Elemente geprüft (${divs} div)`);
		ctx.ok(bad.length === 0, `${label}: kein NC-Ring (box-shadow) bei Hover/Fokus`, bad.slice(0, 6).join(' | '));
	};

	await page.goto(ctx.appUrl(`index.php?workspace=${encodeURIComponent(WS)}&note=${html}`), { waitUntil: 'networkidle' });
	await page.locator(`#entry${html}`).waitFor({ timeout: 10000 });
	// Aufklappbereich ist nach dem Laden zu (wie Upstream): öffnen
	await page.locator(`#entry${html} details.toggle-block > summary`).click();
	await page.locator(`#entry${html} details.toggle-block .toggle-content`).waitFor({ timeout: 10000 });
	await probe('Notiz (Editor, Aufklappbereich, Tabelle)');
	// Ziel der Kern-Regel: der Editor selbst; Aufklappbereich-Inhalt liegt darin und bleibt ohne Ring
	ctx.ok(await page.locator(`#entry${html}`).evaluate((e) => e.matches('div[contenteditable="true"]')), 'Editor ist div[contenteditable=true] (Ziel der Kern-Regel)');
	const tc = page.locator(`#entry${html} .toggle-content`);
	await tc.click();
	ctx.ok(await tc.evaluate((e) => getComputedStyle(e).boxShadow) === 'none' && await page.locator(`#entry${html}`).evaluate((e) => getComputedStyle(e).boxShadow) === 'none',
		'Klick in den Aufklappbereich: Inhalt und Editor ohne Ring');
	await ctx.screenshot(page, 'note');

	await page.goto(ctx.appUrl(`index.php?workspace=${encodeURIComponent(WS)}&note=${tl}`), { waitUntil: 'networkidle' });
	await page.waitForTimeout(500);
	const taskText = page.locator(`#note${tl} .task-text`).first();
	if (await taskText.count()) await taskText.click().catch(() => {});
	await page.waitForTimeout(300);
	await probe('Aufgabenliste (Eingaben sind input, Editor contenteditable=false)', false);

	await page.goto(ctx.appUrl(`index.php?workspace=${encodeURIComponent(WS)}&note=${md}`), { waitUntil: 'networkidle' });
	await page.waitForTimeout(500);
	// Bearbeiten-Modus (CodeMirror: .cm-content ist div[contenteditable=true]); Ansicht durchschalten bis der Editor da ist
	for (let i = 0; i < 3 && !(await page.locator(`#note${md} .cm-content`).isVisible().catch(() => false)); i++) {
		await page.locator(`#note${md} .markdown-view-mode-btn`).first().click().catch(() => {});
		await page.waitForTimeout(500);
	}
	await probe('Markdown-Notiz (CodeMirror)');

	await page.goto(ctx.appUrl(`index.php?workspace=${encodeURIComponent(WS)}&kanban=${F}`), { waitUntil: 'networkidle' });
	await page.locator('#right_pane .kanban-board .kanban-column').first().waitFor({ state: 'visible', timeout: 10000 });
	const kb = await page.evaluate(() => document.querySelectorAll('#right_pane .kanban-board [contenteditable="true"]').length);
	if (kb > 0) await probe('Kanban', false);
	else ctx.ok(true, 'Kanban: keine editierbaren Elemente');

	ctx.assertClean('ce-ring', m);
});
