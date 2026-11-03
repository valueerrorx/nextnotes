// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// M3-fix1 (SPEC §4.3 Punkt 8, ADR 0005, P7): Rückkehr in denselben Tab von Anhang-Seite (PDF-Upload) und Excalidraw-Editor
// → sofort bearbeitbar, gleiche Editor-Sitzung, auch wenn ein später Request der alten Seite die Sperre zurückholt;
// E: Duplikat über eine NC-Seite ohne Adapter (Dateien) → beide öffnen die Notiz → genau einer bearbeitbar; F: Zurück-Knopf/bfcache; Gegenprobe: per window.open kopierter Tab → eigene Sitzung, Nur-Lesen.
import { runSpec } from './lib/harness.mjs';
import { apiClient } from './lib/api.mjs';

const RUN = `${process.argv[3]}${Date.now().toString(36)}`;
const PDF = Buffer.from('%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[]/Count 0>>endobj\n'
	+ 'trailer<</Root 1 0 R>>\n%%EOF\n');

await runSpec(import.meta.url, async (ctx, page, context) => {
	const api = apiClient(ctx, page.request);
	const WS = await api.workspace(`Lock ${RUN}`);
	const id = await api.note(WS, `Rückkehr ${RUN}`, null, `<p>Start ${RUN}</p>`);
	const noteUrl = ctx.appUrl(`index.php?workspace=${encodeURIComponent(WS)}&note=${id}`);
	const entry = `#entry${id}`;
	const content = async () => (await api.must('GET', `/notes/${id}`)).note?.content ?? '';
	const sid = (p) => p.evaluate(() => sessionStorage.getItem('poznote_editor_session_id'));
	const waitFor = async (fn, ms = 8000) => {
		for (const end = Date.now() + ms; Date.now() < end; await page.waitForTimeout(300)) if (await fn()) return true;
		return fn();
	};
	// bearbeitbar = contenteditable true und kein Sperr-Banner (vor dem Fix: Banner bis Ablauf der eigenen Sperre, 90 s)
	const editable = async (p) => (await p.locator(entry).getAttribute('contenteditable')) === 'true'
		&& (await p.locator('.note-edit-lock-banner').count()) === 0;
	// wie ein später Heartbeat/Notfall-PATCH der verlassenen Seite nach deren pagehide-Freigabe: Sperre wieder für s0
	const lateLock = async (label, s) => {
		const st = await page.evaluate(async ([n, sess]) => (await fetch(`/api/v1/notes/${n}/lock`, { method: 'POST',
			headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ editor_session_id: sess }) })).status, [id, s]);
		ctx.ok(st === 200, `${label}: Sperre der alten Seite liegt wieder vor (HTTP ${st})`);
	};
	const typeAndSave = async (label, text) => {
		await page.locator(entry).click();
		await page.keyboard.press('End');
		await page.keyboard.type(` ${text}`);
		ctx.ok(await waitFor(async () => (await content()).includes(text)), `${label}: Eingabe gespeichert`);
	};

	await page.goto(noteUrl, { waitUntil: 'networkidle' });
	await ctx.selfTest(page);
	await page.waitForSelector(entry);
	ctx.ok(await waitFor(() => editable(page), 5000), 'Start: Notiz bearbeitbar');
	const s0 = await sid(page);
	ctx.ok(!!s0, 'Start: Editor-Sitzung gesetzt', String(s0));

	// A. Anhang-Seite: PDF hochladen, zurück zur Notiz → sofort bearbeitbar
	{
		const m = ctx.mark();
		const nav = page.waitForURL((u) => u.pathname.endsWith('/attachments.php'), { timeout: 15000 }).then(() => true, () => false);
		await page.evaluate((n) => window.showAttachmentDialog(n), id);
		ctx.ok(await nav, 'A: Anhang-Seite geöffnet', page.url());
		await page.waitForLoadState('networkidle');
		await page.setInputFiles('#attachmentFile', { name: `a${RUN}.pdf`, mimeType: 'application/pdf', buffer: PDF });
		const up = page.waitForResponse((r) => r.request().method() === 'POST' && /\/attachments(\?|$)/.test(r.url()), { timeout: 15000 });
		await page.click('#uploadBtn');
		const r = await up;
		ctx.ok(r.status() === 201, `A: PDF hochgeladen (HTTP ${r.status()})`);
		await page.waitForTimeout(800);
		await lateLock('A', s0);
		const back = page.waitForURL((u) => u.pathname.endsWith('/index.php') && u.searchParams.get('note') === String(id), { timeout: 15000 }).then(() => true, () => false);
		await page.click('.poznote-back-to-note-btn');
		ctx.ok(await back, 'A: zurück zur Notiz');
		await page.waitForLoadState('networkidle');
		ctx.ok(await waitFor(() => editable(page), 3000), 'A: nach Rückkehr sofort bearbeitbar (kein Banner)');
		ctx.ok(await sid(page) === s0, 'A: gleiche Editor-Sitzung im selben Tab');
		await typeAndSave('A', `nachAnhang${RUN}`);
		ctx.assertClean('A Anhang-Seite', m);
	}

	// B. Excalidraw-Editor öffnen, zeichnen, „Speichern und schließen“ → sofort bearbeitbar
	{
		const m = ctx.mark();
		await page.goto(ctx.appUrl(`excalidraw_editor.php?note_id=${id}&diagram_id=excalidraw-lr${RUN}&workspace=${encodeURIComponent(WS)}`), { waitUntil: 'networkidle' });
		ctx.ok(await page.waitForSelector('.excalidraw canvas', { timeout: 20000 }).then(() => true, () => false), 'B: Zeichenfläche geladen');
		await page.waitForTimeout(1500);
		await page.click('[data-testid="toolbar-rectangle"]', { force: true });
		await page.mouse.move(500, 400);
		await page.mouse.down();
		await page.mouse.move(650, 520, { steps: 6 });
		await page.mouse.up();
		await page.waitForTimeout(600);
		await lateLock('B', s0);
		const back = page.waitForURL((u) => u.pathname.endsWith('/index.php') && u.searchParams.get('note') === String(id), { timeout: 20000 }).then(() => true, () => false);
		await page.click('#saveAndExitBtn');
		ctx.ok(await back, 'B: Speichern und schließen kehrt zur Notiz zurück');
		await page.waitForLoadState('networkidle');
		ctx.ok(await waitFor(() => editable(page), 3000), 'B: nach Excalidraw sofort bearbeitbar (kein Banner)');
		ctx.ok(await sid(page) === s0, 'B: gleiche Editor-Sitzung im selben Tab');
		ctx.ok((await content()).includes(`excalidraw-lr${RUN}`), 'B: Zeichnung gespeichert');
		await typeAndSave('B', `nachZeichnung${RUN}`);
		ctx.assertClean('B Excalidraw speichern', m);
	}

	// C. Excalidraw öffnen und ohne Speichern schließen → sofort bearbeitbar
	{
		const m = ctx.mark();
		await page.goto(ctx.appUrl(`excalidraw_editor.php?note_id=${id}&diagram_id=excalidraw-lr${RUN}&workspace=${encodeURIComponent(WS)}`), { waitUntil: 'networkidle' });
		ctx.ok(await page.waitForSelector('.excalidraw canvas', { timeout: 20000 }).then(() => true, () => false), 'C: Zeichenfläche geladen');
		await page.waitForTimeout(1000);
		await lateLock('C', s0);
		const back = page.waitForURL((u) => u.pathname.endsWith('/index.php') && u.searchParams.get('note') === String(id), { timeout: 20000 }).then(() => true, () => false);
		await page.click('#cancelBtn');
		ctx.ok(await back, 'C: Schließen kehrt zur Notiz zurück');
		await page.waitForLoadState('networkidle');
		ctx.ok(await waitFor(() => editable(page), 3000), 'C: nach Schließen sofort bearbeitbar (kein Banner)');
		ctx.ok(await sid(page) === s0, 'C: gleiche Editor-Sitzung im selben Tab');
		ctx.assertClean('C Excalidraw schließen', m);
	}

	// D. Gegenprobe P7: kopierter Tab (window.open, sessionStorage kopiert) → eigene Sitzung, Nur-Lesen; Tab 1 bleibt bearbeitbar
	{
		const m = ctx.mark();
		const off = [ctx.allow('status', new RegExp(`409 POST .*/notes/${id}/lock`), 'Gegenprobe: zweiter Tab erhält 409'),
			ctx.allow('console', new RegExp(`status of 409 .*/notes/${id}/lock`), 'Gegenprobe: Browser protokolliert die 409')];
		const [t2] = await Promise.all([context.waitForEvent('page'), page.evaluate(() => window.open(location.href))]);
		await t2.waitForLoadState('networkidle');
		await t2.waitForSelector(entry);
		await t2.waitForTimeout(1500);
		const s2 = await sid(t2);
		ctx.ok(!!s2 && s2 !== s0, 'D: kopierter Tab hat eigene Editor-Sitzung', `${s0} / ${s2}`);
		ctx.ok((await t2.locator(entry).getAttribute('contenteditable')) === 'false', 'D: kopierter Tab Nur-Lesen');
		ctx.ok((await t2.locator('.note-edit-lock-banner').count()) === 1, 'D: kopierter Tab zeigt Banner');
		ctx.ok(await sid(page) === s0 && await editable(page), 'D: Tab 1 behält Sitzung und bleibt bearbeitbar');
		// Navigation im kopierten Tab behält dessen Sitzung (kein Rückfall auf die von Tab 1)
		await t2.goto(t2.url(), { waitUntil: 'networkidle' });
		await t2.waitForTimeout(1500);
		ctx.ok(await sid(t2) === s2, 'D: Navigation im kopierten Tab behält dessen Sitzung');
		ctx.ok((await t2.locator(entry).getAttribute('contenteditable')) === 'false', 'D: kopierter Tab nach Navigation weiter Nur-Lesen');
		await t2.close();
		off.forEach((f) => f());
		ctx.assertClean('D Gegenprobe', m);
	}
	// E. Tab 1 Notiz → Dateien-App (ohne Adapter) → Kopie von sessionStorage (wie „Tab duplizieren“) → beide öffnen die Notiz
	{
		const m = ctx.mark();
		const off = [ctx.allow('status', new RegExp(`409 POST .*/notes/${id}/lock`), 'E: Tab ohne Sperre erhält 409'),
			ctx.allow('console', new RegExp(`status of 409 .*/notes/${id}/lock`), 'E: Browser protokolliert die 409'),
			ctx.allow('status', /^412 POST .*\/apps\/text\/session\//, 'E: NC-Text (Dateien-Seite) beim Verlassen, nicht Next Notes'),
			ctx.allow('console', /status of 412 .*\/apps\/text\/session\//, 'E: NC-Text (Dateien-Seite) beim Verlassen')];
		const filesUrl = `${ctx.base}/index.php/apps/files/`;
		const toFiles = async (p) => { await p.goto(filesUrl, { waitUntil: 'load' }); await p.waitForTimeout(1000); };
		const ro = async (p) => (await p.locator(entry).getAttribute('contenteditable')) === 'false';
		await page.goto(noteUrl, { waitUntil: 'networkidle' });
		ctx.ok(await waitFor(() => editable(page), 5000), 'E: Tab 1 bearbeitbar');
		const x = await sid(page);
		await toFiles(page);
		ctx.ok(await sid(page) === x, 'E: auf der Dateien-Seite liegt die Sitzung im sessionStorage');
		const t2 = await context.newPage();
		await toFiles(t2);
		await t2.evaluate((v) => sessionStorage.setItem('poznote_editor_session_id', v), x);
		// E1: Tab 1 kehrt zuerst zurück, dann öffnet die Kopie
		await page.goto(noteUrl, { waitUntil: 'networkidle' });
		await t2.goto(noteUrl, { waitUntil: 'networkidle' });
		await t2.waitForTimeout(1500);
		ctx.ok(await sid(page) === x, 'E1: Tab 1 behält seine Sitzung');
		ctx.ok(await sid(t2) !== x, 'E1: Kopie erhält eine eigene Sitzung', String(await sid(t2)));
		ctx.ok(await editable(page) && await ro(t2), 'E1: genau einer bearbeitbar (Tab 1), Kopie Nur-Lesen');
		// E2: beide über die Dateien-Seite, Kopie erneut mit gleicher Sitzung, beide öffnen gleichzeitig
		await toFiles(page);
		await toFiles(t2);
		const y = await sid(page);
		await t2.evaluate((v) => sessionStorage.setItem('poznote_editor_session_id', v), y);
		await Promise.all([page.goto(noteUrl, { waitUntil: 'networkidle' }), t2.goto(noteUrl, { waitUntil: 'networkidle' })]);
		await page.waitForTimeout(2000);
		const [a, b] = [await sid(page), await sid(t2)];
		ctx.ok(a !== b, 'E2: gleichzeitig geöffnet → verschiedene Sitzungen', `${a} / ${b}`);
		const ed = [await editable(page), await editable(t2)].filter(Boolean).length;
		const rd = [await ro(page), await ro(t2)].filter(Boolean).length;
		ctx.ok(ed === 1 && rd === 1, `E2: genau einer bearbeitbar (${ed} bearbeitbar, ${rd} Nur-Lesen)`);
		await t2.close();
		off.forEach((f) => f());
		ctx.assertClean('E Duplikat über Dateien-Seite', m);
	}
	// F. Zurück-Knopf (bfcache möglich): Seite gibt den Web Lock bei pagehide frei und holt ihn bei Rückkehr neu
	{
		const m = ctx.mark();
		await page.goto(noteUrl, { waitUntil: 'networkidle' });
		ctx.ok(await waitFor(() => editable(page), 5000), 'F: Notiz bearbeitbar');
		const x = await sid(page);
		await page.evaluate(() => { window.__nnRestored = false; addEventListener('pageshow', (e) => { if (e.persisted) window.__nnRestored = true; }); });
		await page.evaluate((n) => window.showAttachmentDialog(n), id);
		await page.waitForURL((u) => u.pathname.endsWith('/attachments.php'), { timeout: 15000 });
		await page.waitForLoadState('networkidle');
		ctx.ok(await sid(page) === x, 'F: Anhang-Seite übernimmt die Sitzung (Notizseite hält keinen Lock mehr)');
		await page.goBack({ waitUntil: 'networkidle' });
		await page.waitForTimeout(1000);
		ctx.info(`F: Rückkehr aus bfcache: ${await page.evaluate(() => window.__nnRestored === true)}`);
		ctx.ok(await waitFor(() => editable(page), 3000) && await sid(page) === x, 'F: nach Zurück sofort bearbeitbar, gleiche Sitzung');
		ctx.assertClean('F Zurück', m);
	}
});
