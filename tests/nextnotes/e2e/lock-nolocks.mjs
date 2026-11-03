// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// M3-fix4 (3), B53, ADR 0005: ohne Web Locks (kein Secure Context, hier per addInitScript entfernt) bekommt jede Seite eine neue
// Editor-Sitzung. Später Heartbeat/Notfall-PATCH der verlassenen Seite legt die Sperre nicht neu an → Rückkehr sofort bearbeitbar.
// Gegenprobe: zweiter Tab bleibt Nur-Lesen.
import { runSpec } from './lib/harness.mjs';
import { apiClient } from './lib/api.mjs';

const RUN = `${process.argv[3]}${Date.now().toString(36)}`;

await runSpec(import.meta.url, async (ctx, page, context) => {
	await context.addInitScript(() => { try { delete Navigator.prototype.locks; } catch (e) { /* egal */ } });
	const api = apiClient(ctx, page.request);
	const WS = await api.workspace(`NoLocks ${RUN}`);
	const id = await api.note(WS, `Ohne Locks ${RUN}`, null, `<p>Start ${RUN}</p>`);
	const noteUrl = ctx.appUrl(`index.php?workspace=${encodeURIComponent(WS)}&note=${id}`);
	const entry = `#entry${id}`;
	const sid = (p) => p.evaluate(() => sessionStorage.getItem('poznote_editor_session_id'));
	const waitFor = async (fn, ms = 8000) => {
		for (const end = Date.now() + ms; Date.now() < end; await page.waitForTimeout(250)) if (await fn()) return true;
		return fn();
	};
	const editable = async (p) => (await p.locator(entry).getAttribute('contenteditable')) === 'true'
		&& (await p.locator('.note-edit-lock-banner').count()) === 0;
	const ro = async (p) => (await p.locator(entry).getAttribute('contenteditable')) !== 'true';
	const req = (p, method, path, sess, body) => p.evaluate(async ([m, u, s, b]) => {
		const r = await fetch(u, { method: m, headers: { 'Content-Type': 'application/json', 'X-Editor-Session-ID': s }, body: b ? JSON.stringify(b) : undefined });
		let j = null; try { j = await r.json(); } catch (e) { /* leer */ }
		return { status: r.status, body: j };
	}, [method, path, sess, body ?? null]);

	const m = ctx.mark();
	await page.goto(noteUrl, { waitUntil: 'networkidle' });
	ctx.ok(await page.evaluate(() => !navigator.locks), 'Web Locks entfernt');
	ctx.ok(await waitFor(() => editable(page), 5000), 'Start: bearbeitbar');
	const s0 = await sid(page);

	for (const round of [1, 2]) {
		await page.evaluate((n) => window.showAttachmentDialog(n), id);
		await page.waitForURL((u) => u.pathname.endsWith('/attachments.php'), { timeout: 15000 });
		await page.waitForLoadState('networkidle');
		// verlassene Seite: später Heartbeat und Notfall-Speichern mit der alten Sitzung nach deren Freigabe
		const hb = await req(page, 'POST', `/api/v1/notes/${id}/lock/heartbeat`, s0, { editor_session_id: s0 });
		ctx.ok(hb.status === 200 && hb.body?.lock === null, `R${round}: später Heartbeat → 200 ohne Sperre`, JSON.stringify(hb));
		const pt = await req(page, 'PATCH', `/api/v1/notes/${id}`, s0, { content: `<p>Notfall ${round} ${RUN}</p>` });
		ctx.ok(pt.status === 200, `R${round}: später Notfall-PATCH gespeichert`, String(pt.status));
		const st = await req(page, 'GET', `/api/v1/notes/${id}/lock`, s0);
		ctx.ok(st.body?.lock === null, `R${round}: keine Sperre neu angelegt`, JSON.stringify(st.body));
		await page.click('.poznote-back-to-note-btn');
		await page.waitForURL((u) => u.pathname.endsWith('/index.php'), { timeout: 15000 });
		await page.waitForLoadState('networkidle');
		ctx.ok(await waitFor(() => editable(page), 3000), `R${round}: nach Rückkehr sofort bearbeitbar`);
		ctx.info(`R${round}: Sitzung ${await sid(page)} (vorher ${s0})`);
	}

	// Eingabe nach Rückkehr wird gespeichert
	await page.locator(entry).click();
	await page.keyboard.press('End');
	await page.keyboard.type(` zurück${RUN}`);
	ctx.ok(await waitFor(async () => String((await api.must('GET', `/notes/${id}`)).note?.content ?? '').includes(`zurück${RUN}`)), 'Eingabe nach Rückkehr gespeichert');

	// Gegenprobe: zweiter Tab desselben Kontos → Nur-Lesen; sein Heartbeat → 409
	const t2 = await context.newPage();
	await t2.goto(noteUrl, { waitUntil: 'networkidle' });
	await t2.waitForTimeout(1500);
	ctx.ok(await ro(t2), 'Gegenprobe: zweiter Tab Nur-Lesen');
	ctx.ok(await editable(page), 'Gegenprobe: erster Tab bleibt bearbeitbar');
	const s2 = await sid(t2);
	const hb2 = await req(t2, 'POST', `/api/v1/notes/${id}/lock/heartbeat`, s2, { editor_session_id: s2 });
	ctx.ok(hb2.status === 409, 'Gegenprobe: Heartbeat des zweiten Tabs → 409', String(hb2.status));
	await t2.close();
	// Zugelassen: 409 der Gegenprobe (Erwerb und Heartbeat des zweiten Tabs)
	for (const e of ctx.problems(m)) if (['status', 'console'].includes(e.kind) && /\b409\b/.test(e.text) && /\/lock/.test(e.text)) e.allowed = 'Gegenprobe zweiter Tab';
	ctx.assertClean('ohne Web Locks', m);
});
