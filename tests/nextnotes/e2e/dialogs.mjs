// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// M3-fix4 (4): Dialoge und Overlays beginnen unter der NC-Kopfleiste und passen in den sichtbaren Bereich (Inhalt scrollt intern).
// A: jedes .modal der Hauptansicht einzeln eingeblendet; B: real geöffnet („Aufgabe bearbeiten“, Hinweis, Bestätigung, Audio aufnehmen).
import { runSpec } from './lib/harness.mjs';
import { apiClient } from './lib/api.mjs';

const RUN = `${process.argv[3]}${Date.now().toString(36)}`;

// Kopfleisten-Unterkante, Overlay- und Inhaltsrechteck eines sichtbaren Dialogs
async function measure(page, overlaySel) {
	return page.evaluate((sel) => {
		const header = document.querySelector('#header');
		const hb = header ? header.getBoundingClientRect().bottom : 0;
		const ov = [...document.querySelectorAll(sel)].find((e) => e.getClientRects().length && getComputedStyle(e).display !== 'none');
		if (!ov) return null;
		const content = ov.querySelector('.modal-content, .alert-modal, .note-creation-loading-dialog') ?? ov;
		const o = ov.getBoundingClientRect();
		const c = content.getBoundingClientRect();
		return { hb, oTop: o.top, cTop: c.top, cBottom: c.bottom, vh: innerHeight, id: ov.id || ov.className };
	}, overlaySel);
}

function check(ctx, label, r) {
	if (!r) return ctx.ok(false, `${label}: Dialog sichtbar`);
	ctx.ok(r.hb > 0 && r.oTop >= r.hb - 0.5, `${label}: Hintergrund ab Kopfleiste (${Math.round(r.oTop)} ≥ ${Math.round(r.hb)})`);
	ctx.ok(r.cTop >= r.hb + 8, `${label}: Inhalt mit Abstand unter Kopfleiste (${Math.round(r.cTop)})`);
	ctx.ok(r.cBottom <= r.vh + 0.5, `${label}: Inhalt endet im Fenster (${Math.round(r.cBottom)} ≤ ${r.vh})`);
	return true;
}

await runSpec(import.meta.url, async (ctx, page) => {
	const api = apiClient(ctx, page.request);
	const WS = await api.defaultWorkspace();
	const tasks = JSON.stringify([{ id: 1, text: `Aufgabe ${RUN}`, completed: false }]);
	const tl = (await api.must('POST', '/notes', { heading: `Dialoge ${RUN}`, content: tasks, type: 'tasklist', workspace: WS })).note.id;
	const nid = await api.note(WS, `Dialognotiz ${RUN}`, null, '<p>x</p>');
	const open = async (id) => {
		await page.goto(ctx.appUrl(`index.php?workspace=${encodeURIComponent(WS)}&note=${id}`), { waitUntil: 'networkidle' });
		await page.waitForTimeout(400);
	};
	const m = ctx.mark();

	// A. jedes .modal einzeln; ein sehr hoher Inhalt prüft den internen Scroll
	await open(nid);
	const ids = await page.evaluate(() => [...document.querySelectorAll('.modal[id]')].map((e) => e.id));
	ctx.info(`A: ${ids.length} Dialoge: ${ids.join(', ')}`);
	ctx.ok(ids.length >= 20, 'A: Dialoge der Hauptansicht vorhanden', String(ids.length));
	for (const id of ids) {
		await page.evaluate((i) => {
			const el = document.getElementById(i);
			el.style.display = 'flex';
			const c = el.querySelector('.modal-content');
			if (c) { const filler = document.createElement('div'); filler.className = 'nn-test-filler'; filler.style.height = '2000px'; c.appendChild(filler); }
		}, id);
		const r = await measure(page, `#${id}`);
		check(ctx, `A #${id}`, r);
		await page.evaluate((i) => {
			const el = document.getElementById(i);
			el.style.display = '';
			el.querySelectorAll('.nn-test-filler').forEach((f) => f.remove());
		}, id);
	}

	// B1. „Aufgabe bearbeiten“ über die Aufgabe der Liste
	await open(tl);
	await page.locator(`#entry${tl} .task-item[data-task-id="1"] .task-text`).click();
	await page.waitForTimeout(600);
	const te = await measure(page, '.modal');
	ctx.info(`B1: geöffnet ${te?.id}`);
	check(ctx, 'B1 Aufgabe bearbeiten', te);
	await page.keyboard.press('Escape');
	await page.waitForTimeout(300);

	// B2. Hinweis und Bestätigung (modal-alerts.js)
	await open(nid);
	await page.evaluate(() => { window.modalAlert.alert('Hinweis'); });
	await page.waitForTimeout(500);
	check(ctx, 'B2 Hinweis', await measure(page, '.alert-modal-overlay'));
	await page.keyboard.press('Escape');
	await page.evaluate(() => document.querySelectorAll('.alert-modal-overlay').forEach((e) => e.remove()));
	await page.evaluate(() => { window.modalAlert.confirm('Wirklich?'); });
	await page.waitForTimeout(500);
	check(ctx, 'B2 Bestätigung', await measure(page, '.alert-modal-overlay'));
	await ctx.screenshot(page, 'dialog-confirm');
	await page.evaluate(() => document.querySelectorAll('.alert-modal-overlay').forEach((e) => e.remove()));

	// B3. Audio aufnehmen über Rechtsklick → Einfügen
	await open(nid);
	const box = await page.locator(`#entry${nid}`).boundingBox();
	await page.mouse.click(box.x + 40, box.y + 10, { button: 'right' });
	await page.locator('.slash-command-menu [data-command-id="insert"]').hover();
	await page.locator('.slash-command-submenu [data-submenu-id="record-audio"]').click();
	await page.waitForTimeout(500);
	check(ctx, 'B3 Audio aufnehmen', await measure(page, '#dictateModal'));
	await ctx.screenshot(page, 'dialog-audio');
	await page.locator('#dictateCancelBtn').click();
	ctx.assertClean('Dialoge', m);
});
