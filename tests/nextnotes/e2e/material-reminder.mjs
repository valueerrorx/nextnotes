// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// UI-nav-mobil Punkt 5: Schüler setzt in einer Material-Tasklist die eigene Aufgaben-Erinnerung (Notiz-Ansicht und Aufgaben-Übersicht);
// Datum/Uhrzeit/Wiederholung/Entfernen gesperrt, kein Schreiben der Aufgabe, nur /task-reminder. Kandidat für material-m6b.mjs.
import { runSpec } from './lib/harness.mjs';
import { apiClient } from './lib/api.mjs';
import { createTeam } from './lib/docker.mjs';

const RUN = `${process.argv[3]}${Date.now().toString(36)}`;
const A = process.env.NN_USER;
const B = process.env.NN_USER2;

await runSpec(import.meta.url, async (ctx, page, context, browser) => {
	const api = apiClient(ctx, page.request);
	const ctxB = await browser.newContext({ viewport: { width: 1400, height: 900 }, locale: 'de-DE' });
	await ctx.attach(ctxB);
	const pb = await ctxB.newPage();
	await ctx.login(pb, B, process.env.NN_PASS2);
	const teamId = createTeam(ctx.base, A, `Klasse ${RUN}`, { [B]: 1 });
	const TW = `Material ${RUN}`;
	await api.must('POST', '/workspaces', { name: TW, team: teamId });
	const M = await api.folder(TW, `Material ${RUN}`);
	await api.must('PUT', `/folders/${M}/material`, { material: true });
	const d = new Date(Date.now() + 3 * 86400000);
	const pad = (x) => String(x).padStart(2, '0');
	const due = `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T10:00`;
	const tl = (await api.must('POST', '/notes', { heading: `Liste ${RUN}`, type: 'tasklist', workspace: TW, folder_id: M,
		content: JSON.stringify([{ id: 1, text: 'Lesen', completed: false, important: false, noteId: 0, dueAt: due }, { id: 2, text: 'Ohne Termin', completed: false, important: false, noteId: 0 }]) })).note.id;
	const writes = (since) => ctx.requests.slice(since.q).filter((r) => r.method !== 'GET' && r.url.includes(`/notes/${tl}`) && !/task-reminder|beacon/.test(r.url));
	const modal = pb.locator('#taskDueModal');

	const check = async (where) => {
		ctx.ok(await modal.isVisible(), `${where}: Fälligkeits-Dialog offen`);
		ctx.ok(await modal.evaluate((m) => m.classList.contains('nn-reminder-only')), `${where}: nur Erinnerung`);
		for (const id of ['taskDueDateBtn', 'taskDueTimeBtn', 'taskDueRepeatSelect']) ctx.ok(await pb.locator(`#${id}`).isDisabled(), `${where}: #${id} gesperrt`);
		ctx.ok(!(await pb.locator('#taskDueRemoveBtn').isVisible()), `${where}: „Termin entfernen“ verborgen`);
	};
	const setRemind = async (on) => {
		const box = pb.locator('#taskDueRemindInput');
		if ((await box.isChecked()) !== on) await pb.locator('#taskDueRemindOption .toggle-switch').click();
		const resp = pb.waitForResponse((r) => r.url().includes(`/notes/${tl}/task-reminder`) && r.request().method() === (on ? 'POST' : 'DELETE'));
		await pb.locator('#taskDueSaveBtn').click();
		return (await resp).status();
	};

	// Notiz-Ansicht
	await pb.goto(ctx.appUrl(`index.php?workspace=${encodeURIComponent(TW)}&note=${tl}`), { waitUntil: 'networkidle' });
	const m1 = ctx.mark();
	ctx.ok(await pb.locator(`#entry${tl}[data-nextnotes-readonly]`).count() === 1, 'S: Tasklist nur lesen');
	const chip = pb.locator(`#entry${tl} .task-due-chip`).first();
	ctx.ok(await chip.isEnabled(), 'S: Termin-Chip bedienbar');
	ctx.info(await chip.evaluate((c) => { const o = []; for (let e = c; e && e.id !== 'note' + c.dataset.noteId; e = e.parentElement) o.push(`${e.tagName}.${e.className.toString().slice(0, 30)} op=${getComputedStyle(e).opacity} pe=${getComputedStyle(e).pointerEvents}`); return o.join(' | '); }));
	ctx.ok(await pb.locator(`#entry${tl} .task-due-btn:visible, #entry${tl} .task-delete-btn:visible, #entry${tl} .task-menu-btn:visible`).count() === 0, 'S: übrige Aufgaben-Knöpfe verborgen');
	await chip.click();
	await check('Notiz');
	ctx.ok(await setRemind(true) === 200, 'Notiz: Erinnerung gesetzt → 200');
	ctx.ok(!(await modal.isVisible()), 'Notiz: Dialog geschlossen');
	await chip.click();
	ctx.ok(await setRemind(false) === 200, 'Notiz: Erinnerung gelöscht → 200');
	ctx.ok(writes(m1).length === 0, 'Notiz: kein Schreib-Request an die Aufgabe', writes(m1).map((r) => `${r.method} ${r.url}`).join(' '));
	await ctx.screenshot(pb, 'student-note');
	ctx.assertClean('Notiz', m1);

	// Aufgaben-Übersicht
	await pb.goto(ctx.appUrl(`tasks.php?workspace=${encodeURIComponent(TW)}`), { waitUntil: 'networkidle' });
	const m2 = ctx.mark();
	const chip2 = pb.locator('.tasks-task-item .task-due-chip').first();
	await chip2.waitFor({ state: 'visible', timeout: 8000 });
	ctx.ok(await pb.locator('.tasks-task-item .tasks-task-due-btn:visible').count() === 0, 'Übersicht: kein „Termin setzen“ ohne Schreibrecht');
	await chip2.click();
	await check('Übersicht');
	ctx.ok(await setRemind(true) === 200, 'Übersicht: Erinnerung gesetzt → 200');
	ctx.ok(writes(m2).length === 0, 'Übersicht: kein Schreib-Request an die Aufgabe', writes(m2).map((r) => `${r.method} ${r.url}`).join(' '));
	await ctx.screenshot(pb, 'student-tasks');
	ctx.assertClean('Übersicht', m2);

	// Lehrkraft: voller Dialog
	await page.goto(ctx.appUrl(`index.php?workspace=${encodeURIComponent(TW)}&note=${tl}`), { waitUntil: 'networkidle' });
	await page.locator(`#entry${tl} .task-due-chip`).first().click();
	ctx.ok(await page.locator('#taskDueModal').isVisible() && !(await page.locator('#taskDueModal').evaluate((m) => m.classList.contains('nn-reminder-only'))), 'L: voller Dialog');
	ctx.ok(await page.locator('#taskDueDateBtn').isEnabled(), 'L: Datum änderbar');
	await ctxB.close();
});
