// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// M4g (M4-Plan Done M4g 1, 3): Dashboard Karte → Notiz, Graph-Knoten, Speed-Dial-Kacheln + eigenes Hintergrundbild, Erwähnungen als Empfänger
// (erledigt in-place), Hintergrund-Ebene der Hauptansicht unter der NC-Kopfleiste ohne Zusatz-Request; Tag speed-dial reserviert,
// Ordner → Speed Dial ohne Dialog und ohne 409 der offenen Notiz (P27, B52-Rest); je Seite 0 CSP/pageerror/Konsolenfehler/≥ 400, ≤ 8 API-Requests.
import { runSpec } from './lib/harness.mjs';
import { apiClient } from './lib/api.mjs';
import { shareRead } from './lib/docker.mjs';

const RUN = `${process.argv[3]}${Date.now().toString(36)}`;
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', 'base64');

await runSpec(import.meta.url, async (ctx, page) => {
	const api = apiClient(ctx, page.request);
	const WS = await api.workspace(`Ansichten ${RUN}`);
	const wq = `workspace=${encodeURIComponent(WS)}`;
	const fid = await api.folder(WS, `Ordner ${RUN}`);
	const card = await api.note(WS, `Karte ${RUN}`, null, `<p>Siehe [[Zweite ${RUN}]] und <a href="https://example.org/${RUN}">Beispiel</a></p>`);
	const second = await api.note(WS, `Zweite ${RUN}`, fid, '<p>zwei</p>');
	await api.must('PUT', `/notes/${card}/tags`, { tags: ['speed-dial'], workspace: WS });

	const apiCalls = (since) => ctx.requests.slice(since.q).filter((r) => /\/apps\/nextnotes\/(api\/v1\/|api_upload_background)/.test(r.url) && r.type !== 'document').length;
	const open = async (p, path, label) => {
		const m = ctx.mark();
		const resp = await p.goto(ctx.appUrl(`${path}${path.includes('?') ? '&' : '?'}${wq}`), { waitUntil: 'networkidle' });
		ctx.ok(resp?.status() === 200, `${label}: 200`, String(resp?.status()));
		ctx.info(`${label}: ${apiCalls(m)} API-Requests`);
		ctx.ok(apiCalls(m) <= 8, `${label}: ≤ 8 API-Requests`, String(apiCalls(m)));
		return m;
	};

	// 1. Dashboard: Karte sichtbar, Klick öffnet die Notiz
	let m = await open(page, 'dashboard.php', 'Dashboard');
	await ctx.selfTest(page);
	for (const id of ['iconSidebarDashboardBtn', 'iconSidebarGraphBtn', 'iconSidebarSpeedDialBtn']) ctx.ok(await page.locator(`#icon_sidebar #${id}`).isVisible(), `Icon-Leiste: ${id}`);
	const dash = page.locator(`.dash-note-card[data-note-id="${card}"] .dash-card-link`);
	await dash.waitFor({ state: 'visible', timeout: 15000 });
	await ctx.screenshot(page, 'dashboard');
	ctx.assertClean('Dashboard', m);
	m = ctx.mark();
	await Promise.all([page.waitForURL(new RegExp(`[?&]note=${card}`), { timeout: 20000 }), dash.click()]);
	await page.waitForLoadState('networkidle');
	ctx.ok(await page.locator(`#entry${card}`).count() === 1, 'Dashboard: Karte öffnet die Notiz');
	ctx.assertClean('Dashboard → Notiz', m);

	// 2. Graph: Knoten der Notizen
	m = await open(page, 'graph.php', 'Graph');
	await page.locator('.graph-node').first().waitFor({ state: 'attached', timeout: 15000 });
	const nodes = await page.locator('.graph-node').count();
	ctx.ok(nodes >= 2, 'Graph: Knoten sichtbar', String(nodes));
	await ctx.screenshot(page, 'graph');
	ctx.assertClean('Graph', m);

	// 3. Speed Dial: Kachel mit Link; eigenes Bild über den Dialog (hell)
	m = await open(page, 'speed_dial.php', 'Speed Dial');
	const tile = page.locator(`.speed-dial-tile[data-note-id="${card}"] .speed-dial-link`);
	ctx.ok(await tile.isVisible(), 'Speed Dial: Kachel sichtbar');
	ctx.ok((await tile.getAttribute('href')) === `https://example.org/${RUN}`, 'Speed Dial: Link der Notiz', await tile.getAttribute('href'));
	if (ctx.theme === 'light') {
		await page.locator('#edgeMenuSpeedDialBackground').click();
		await page.locator('#backgroundImageModal').waitFor({ state: 'visible', timeout: 10000 });
		await page.locator('#backgroundImageInput').setInputFiles({ name: 'sd.png', mimeType: 'image/png', buffer: PNG });
		const up = page.waitForResponse((r) => r.request().method() === 'POST' && r.url().includes('api_upload_background.php'));
		await Promise.all([page.waitForEvent('load', { timeout: 20000 }), page.locator('#saveBackgroundBtn').click()]);
		ctx.ok((await up).status() === 200, 'Speed Dial: Bild hochgeladen');
		await page.waitForLoadState('networkidle');
		ctx.ok(await page.locator('.speed-dial-background').count() === 1, 'Speed Dial: Bild nach dem Speichern');
	}
	await ctx.screenshot(page, 'speed-dial');
	ctx.assertClean('Speed Dial', m);

	// 4. Hauptansicht: Hintergrund-Ebene unter der NC-Kopfleiste, Status kommt mit der Seite
	const up = await page.request.fetch(ctx.appUrl('api_upload_background.php'), {
		method: 'POST', headers: { requesttoken: await page.evaluate(() => document.head.dataset.requesttoken || '') },
		multipart: { background: { name: 'bg.png', mimeType: 'image/png', buffer: PNG }, workspace: WS, scope: 'background' },
	});
	ctx.ok(up.status() === 200, 'Hintergrund hochgeladen (API)', String(up.status()));
	m = await open(page, `index.php?note=${second}`, 'Hauptansicht mit Hintergrund');
	const layer = page.locator('#poznote-background-layer');
	await layer.waitFor({ state: 'attached', timeout: 10000 });
	const geo = await page.evaluate(() => {
		const l = document.getElementById('poznote-background-layer').getBoundingClientRect();
		const h = document.getElementById('header')?.getBoundingClientRect();
		return { top: l.top, headerBottom: h ? h.bottom : 0 };
	});
	ctx.ok(geo.top >= geo.headerBottom - 1, 'Hintergrund-Ebene beginnt unter der Kopfleiste', JSON.stringify(geo));
	const bgStatus = ctx.requests.slice(m.q).filter((r) => r.url.includes('api_upload_background.php') && !r.url.includes('image=1'));
	ctx.ok(bgStatus.length === 0, 'Hauptansicht: kein Status-Request (config)', bgStatus.map((r) => r.url).join(','));
	await ctx.screenshot(page, 'index-background');
	ctx.assertClean('Hauptansicht mit Hintergrund', m);

	// 5. Ordner → Speed Dial ohne Dialog, offene Notiz speichert danach ohne 409 (B52-Rest); eingetippter Tag abgewiesen (P27)
	if (ctx.theme === 'light') {
		m = ctx.mark();
		const refreshed = page.waitForResponse((r) => r.request().method() === 'GET' && new URL(r.url()).pathname.endsWith(`/notes/${second}`));
		const tagged = page.waitForResponse((r) => r.request().method() === 'POST' && r.url().includes(`/folders/${fid}/tags`));
		await page.evaluate(([id, name]) => window.showTagFolderNotesDialog(id, name, 1, 'speed-dial'), [fid, `Ordner ${RUN}`]);
		ctx.ok((await tagged).status() === 200, 'Ordner → Speed Dial: direkt gesendet');
		await refreshed;
		ctx.ok(!(await page.locator('#tagFolderNotesModal').isVisible()), 'Ordner → Speed Dial: kein Dialog');
		ctx.ok(String((await api.must('GET', `/notes/${second}?${wq}`)).note.tags).includes('speed-dial'), 'Ordner → Speed Dial: Notiz getaggt');
		const saved = page.waitForResponse((r) => r.request().method() === 'PATCH' && r.url().includes(`/notes/${second}`), { timeout: 20000 });
		await page.locator(`#entry${second}`).click();
		await page.keyboard.type(' danach');
		const st = (await saved).status();
		ctx.ok(st === 200, 'offene Notiz: Speichern ohne 409', String(st));
		const input = page.locator(`#note${second} .tag-input`).first();
		if (await input.count()) {
			await input.click();
			await input.fill('speed-dial');
			await page.keyboard.press('Enter');
			await page.waitForTimeout(500);
			ctx.ok(await page.locator(`#note${second} .clickable-tag-wrapper:not([data-internal-tag]) [data-tag="speed-dial"]`).count() === 0, 'eingetippter Tag speed-dial nicht übernommen');
			ctx.ok(await page.locator('text=/reserviert|reserved/').count() >= 1, 'Hinweis: reserviert');
		} else {
			ctx.info('Tag-Eingabe nicht gefunden, Teilprüfung übersprungen');
		}
		ctx.assertClean('Ordner → Speed Dial', m);
	}

	// 6. Erwähnungen als Empfänger (Lesefreigabe): Liste, Zähler, erledigt ohne Reload
	{
		const wsName = `Team ${RUN}`;
		await api.workspace(wsName);
		const ment = await api.note(wsName, `Erwähnt ${RUN}`, null, `<p>Hallo @${process.env.NN_USER2}</p>`);
		shareRead(ctx.base, process.env.NN_USER, wsName, process.env.NN_USER2);
		const ctxB = await page.context().browser().newContext({ viewport: { width: 1400, height: 900 }, locale: 'de-DE' });
		await ctx.attach(ctxB);
		const pb = await ctxB.newPage();
		await ctx.login(pb, process.env.NN_USER2, process.env.NN_PASS2);
		m = ctx.mark();
		const resp = await pb.goto(ctx.appUrl('mentions.php'), { waitUntil: 'networkidle' });
		ctx.ok(resp?.status() === 200, 'Erwähnungen: 200');
		const item = pb.locator(`.mentions-item[data-note-id="${ment}"]`);
		ctx.ok(await item.isVisible(), 'Erwähnungen: Eintrag aus geteiltem Workspace');
		const badge = pb.locator('#iconSidebarMentionsBtn .mention-badge');
		const before = Number(await badge.textContent());
		ctx.ok(before >= 1, 'Icon-Leiste: Zähler', String(before));
		await ctx.screenshot(pb, 'mentions');
		const post = pb.waitForResponse((r) => r.request().method() === 'POST' && r.url().includes(`/${ment}/handled`));
		await item.locator('.mentions-handled-btn').click();
		ctx.ok((await post).status() === 200, 'Erwähnung erledigt: 200');
		await pb.waitForTimeout(500);
		const after = (await badge.isVisible()) ? Number(await badge.textContent()) : 0;
		ctx.ok(after === before - 1, 'Zähler in-place verringert', `${before} → ${after}`);
		ctx.assertClean('Erwähnungen', m);
		await ctxB.close();
	}
});
