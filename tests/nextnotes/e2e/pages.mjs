// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// M3c1 (Plan M3 Teilung M3c, SPEC §1.2 Seitenbündel, §4.2, §4.6, §11.1 P13, §11.2 B33/B34): Unterseiten Revisionen und
// Anhänge, Notiz-Info, Kanban in #right_col, Navigation über die Knöpfe der Notiz und zurück; Notiztitel mit HTML-Nutzlast
// erscheint überall als Text; je Abschnitt 0 CSP/pageerror/Konsolenfehler/≥400.
import { runSpec } from './lib/harness.mjs';
import { apiClient } from './lib/api.mjs';

const RUN = `${process.argv[3]}${Date.now().toString(36)}`;
const EVIL = `"><img src=x onerror="window.__xss=1"><b>Fett</b> ${RUN}`;
// Nutzlasten aus xss.mjs (M3a2): Ordnername ohne / \ : * ? " < > |, Workspace-Name kollidiert mit ID, Dateiname
const EVIL_FOLDER = "O'Brien'); window.__xss=6; {{7+7}} ${7+7}";
const EVIL_WS = 'right_col';
// ohne " (Playwright-multipart maskiert " im Dateinamen nicht → PHP verwirft die Datei; curl-Probe mit " ok)
const EVIL_FILE = `<svg onload=window.__xss=7>&amp;'x' ${RUN}.txt`;

// Kein ausgeführtes/eingefügtes Markup aus dem Titel; Titeltext als Text vorhanden
async function xssState(page) {
	return page.evaluate((evil) => ({
		xss: window.__xss ?? null,
		onerrorImgs: [...document.querySelectorAll('img')].filter((i) => i.hasAttribute('onerror')).length,
		boldFett: [...document.querySelectorAll('b')].filter((b) => b.textContent === 'Fett').length,
		textFound: document.body.innerText.includes(evil.trim()) || [...document.querySelectorAll('*')].some((e) => e.textContent === evil),
	}), EVIL);
}

// Element-/Attributzählung wie xss.mjs, begrenzt auf root (Selektor)
async function dom(page, root) {
	return page.evaluate((sel) => {
		const r = document.querySelector(sel) ?? document;
		const count = (s) => r.querySelectorAll(s).length;
		const ids = {};
		for (const e of document.querySelectorAll('[id]')) ids[e.id] = (ids[e.id] ?? 0) + 1;
		return {
			img: count('img'), b: count('b'), svg: count('svg'), script: count('script'), form: count('form'), iframe: count('iframe'),
			embed: count('embed, object'), dataAction: count('[data-action]'),
			onAttr: [...r.querySelectorAll('*')].filter((e) => [...e.attributes].some((a) => /^on/i.test(a.name))).length,
			jsHref: count('a[href^="javascript" i]'),
			dupIds: Object.entries(ids).filter(([, n]) => n > 1).map(([k, n]) => `${k}×${n}`).sort().join(','),
			rightCol: document.querySelectorAll('#right_col').length,
			islands: [...document.querySelectorAll('script[type="application/json"]')].filter((s) => { try { JSON.parse(s.textContent); return false; } catch { return true; } }).length,
			xss: window.__xss ?? null,
		};
	}, root);
}

await runSpec(import.meta.url, async (ctx, page) => {
	// SQLite: „Database busy“ (503) beim Anlegen der Testdaten wiederholen (wie attachments-search.mjs)
	const retry = async (fn) => { for (let i = 0; ; i++) { try { return await fn(); } catch (e) { if (i > 4 || !/503/.test(e.message)) throw e; await page.waitForTimeout(1500); } } };
	const api0 = apiClient(ctx, page.request);
	const api = { ...api0 };
	for (const k of ['must', 'workspace', 'folder', 'note']) api[k] = (...a) => retry(() => api0[k](...a));
	const auth = `Basic ${Buffer.from(`${process.env.NN_USER}:${process.env.NN_PASS}`).toString('base64')}`;
	// gleich aufgebaute Daten: harmlos (Basis) und mit Nutzlasten
	const setup = async (ws, title, folder, file) => {
		await api.workspace(ws);
		const fid = await api.folder(ws, folder);
		const nid = await api.note(ws, title, fid, `<p>Inhalt ${RUN}</p>`);
		await api.note(ws, `Zweite Karte ${RUN}`, fid);
		await api.must('POST', `/notes/${nid}/snapshot`, { manual: true });
		const up = await retry(async () => { const x = await page.request.post(ctx.appUrl(`api/v1/notes/${nid}/attachments?workspace=${encodeURIComponent(ws)}`), {
			headers: { Authorization: auth, 'OCS-APIRequest': 'true' },
			multipart: { file: { name: file, mimeType: 'text/plain', buffer: Buffer.from(`Anhang ${RUN}\n`) } },
			failOnStatusCode: false,
		}); if (x.status() === 503) throw new Error('503'); return x; });
		ctx.ok(up.status() === 201, `Testdaten ${ws}: Anhang hochgeladen (HTTP ${up.status()})`, (await up.text().catch(() => "")).slice(0, 200));
		return { ws, fid, nid, wq: `workspace=${encodeURIComponent(ws)}` };
	};
	const B = await setup(`Seiten ${RUN}`, `Basis ${RUN}`, `Board ${RUN}`, `anhang-${RUN}.txt`);
	const E = await setup(EVIL_WS, EVIL, `${EVIL_FOLDER} ${RUN}`, EVIL_FILE);
	const WS = E.ws; const fid = E.fid; const nid = E.nid; const wq = E.wq;
	await api.must('PUT', '/settings/last_opened_workspace', { value: WS });

	// Element-Zählung Basis vs. Nutzlast je Seite (Done M3a2 4 auf M3c1-Seiten)
	const compare = async (label, load, root) => {
		const m0 = ctx.mark();
		await load(B);
		const base = await dom(page, root);
		await load(E);
		const evil = await dom(page, root);
		ctx.info(`${label} Basis ${JSON.stringify(base)} / Nutzlast ${JSON.stringify(evil)}`);
		for (const k of ['img', 'b', 'svg', 'script', 'form', 'iframe', 'embed', 'dataAction', 'onAttr', 'jsHref', 'dupIds', 'rightCol']) {
			ctx.ok(evil[k] === base[k], `${label}: gleiche Anzahl ${k}`, `Basis ${base[k]}, Nutzlast ${evil[k]}`);
		}
		ctx.ok(evil.islands === 0 && evil.xss === null, `${label}: JSON-Inseln parsebar, keine Ausführung`, JSON.stringify(evil));
		ctx.assertClean(`${label} Vergleich`, m0);
	};
	await compare('XSS revisions', async (d) => { await page.goto(ctx.appUrl(`revisions.php?note_id=${d.nid}&${d.wq}`), { waitUntil: 'networkidle' }); await page.waitForSelector('#revisionsList [role="option"], #revisionsList .revisions-item', { timeout: 10000 }).catch(() => {}); }, 'body');
	await compare('XSS attachments', async (d) => { await page.goto(ctx.appUrl(`attachments.php?note_id=${d.nid}&${d.wq}`), { waitUntil: 'networkidle' }); await page.waitForSelector('#attachmentsList .attachment-card, #attachmentsList [data-action="download"]', { timeout: 10000 }).catch(() => {}); }, 'body');
	await compare('XSS kanban', async (d) => {
		await page.goto(ctx.appUrl(`index.php?${d.wq}`), { waitUntil: 'networkidle' });
		await page.evaluate((f) => window.openKanbanView(f), d.fid);
		await page.waitForSelector('#kanban-view-container .kanban-card', { timeout: 10000 }).catch(() => {});
	}, '#kanban-view-container');
	const evilName = EVIL_FILE;
	await page.goto(ctx.appUrl(`attachments.php?note_id=${nid}&${wq}`), { waitUntil: 'networkidle' });
	const listText = await page.locator('#attachmentsList').innerText().catch(() => '');
	ctx.info(`Anhangliste (Nutzlast): ${listText.replace(/\s+/g, ' ').slice(0, 160)}`);
	// Server entfernt < > aus Dateinamen (M3b2), Anzeige kürzt; &amp; muss wörtlich als Text stehen
	ctx.ok(listText.includes("svg onload=window.__xss=7&amp;'x'"), 'Anhänge: Nutzlast-Dateiname als Text gelistet', evilName);
	await page.goto(ctx.appUrl(`index.php?${wq}`), { waitUntil: 'networkidle' });
	await page.evaluate((f) => window.openKanbanView(f), fid);
	await page.waitForSelector('#kanban-view-container .kanban-card', { timeout: 10000 }).catch(() => {});
	ctx.ok((await page.locator('#kanban-view-container').innerText().catch(() => '')).includes(EVIL_FOLDER), 'Kanban: Nutzlast-Ordnername als Text');

	const visit = async (label, path, ready) => {
		const resp = await page.goto(ctx.appUrl(path), { waitUntil: 'networkidle' });
		const found = await page.waitForSelector(ready, { timeout: 10000 }).then(() => true, () => false);
		ctx.ok(resp?.status() === 200, `${label}: HTTP 200`, `HTTP ${resp?.status()}`);
		ctx.ok(found, `${label}: ${ready} vorhanden`);
		await ctx.screenshot(page, label).catch(() => {});
	};
	const checkXss = async (label, expectText = true) => {
		const s = await xssState(page);
		ctx.ok(s.xss === null && s.onerrorImgs === 0 && s.boldFett === 0, `${label}: Titel nicht als HTML ausgeführt`, JSON.stringify(s));
		if (expectText) ctx.ok(s.textFound, `${label}: Titel als Text sichtbar`);
	};

	// Revisionen
	let m = ctx.mark();
	await visit('revisions', `revisions.php?note_id=${nid}&${wq}`, '#revisionsList .revisions-item, #revisionsList [role="option"]');
	const heading = await page.evaluate(() => JSON.parse(document.getElementById('revisions-config')?.textContent ?? '{}').noteHeading);
	ctx.ok(heading === EVIL, 'Revisionen: Konfiguration enthält Titel unverändert (JSON-Insel)');
	await checkXss('Revisionen', false);
	ctx.ok(await page.locator('script[src*="nextnotes-page-revisions"], link[href*="nextnotes-page-revisions"]').count() > 0, 'Revisionen: Seitenbündel geladen');
	ctx.assertClean('Revisionen', m);

	// Anhänge (P13: PDF-Vorschau iframe statt embed; hier Text-Anhang)
	m = ctx.mark();
	await visit('attachments', `attachments.php?note_id=${nid}&${wq}`, '#attachmentsList .attachment-card, #attachmentsList [data-action="download"]');
	ctx.ok(await page.locator('#uploadBtn').count() === 1, 'Anhänge: Hochladen für Besitzer');
		ctx.ok(await page.locator('embed, object').count() === 0, 'Anhänge: kein embed/object (P13)');
	await checkXss('Anhänge', false);
	ctx.assertClean('Anhänge', m);
	// PDF auf der Anhang-Seite (P13, CSP attachments: frame-src 'self'): gespeicherte PDF-Vorschau und Dateiauswahl-Vorschau
	m = ctx.mark();
	const PDF = Buffer.from('%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n');
	const pdfUp = await retry(async () => { const x = await page.request.post(ctx.appUrl(`api/v1/notes/${nid}/attachments?${wq}`), {
		headers: { Authorization: auth, 'OCS-APIRequest': 'true' }, multipart: { file: { name: 'doku.pdf', mimeType: 'application/pdf', buffer: PDF } }, failOnStatusCode: false,
	}); if (x.status() === 503) throw new Error('503'); return x; });
	ctx.ok(pdfUp.status() === 201, `PDF hochgeladen (HTTP ${pdfUp.status()})`);
	await page.goto(ctx.appUrl(`attachments.php?note_id=${nid}&${wq}`), { waitUntil: 'networkidle' });
	await page.locator('[data-action="preview-pdf"]').first().click();
	const frame = await page.waitForSelector('iframe[src*="attachments"]', { timeout: 10000 }).then(() => true, () => false);
	await page.waitForTimeout(800);
	ctx.ok(frame && await page.locator('embed, object').count() === 0, 'PDF-Vorschau: iframe statt embed (P13)');
	await ctx.screenshot(page, 'pdf-preview').catch(() => {});
	await page.keyboard.press('Escape').catch(() => {});
	ctx.assertClean('PDF-Vorschau', m);
	m = ctx.mark();
	await page.goto(ctx.appUrl(`attachments.php?note_id=${nid}&${wq}`), { waitUntil: 'networkidle' });
	await page.setInputFiles('#attachmentFile', { name: 'auswahl.pdf', mimeType: 'application/pdf', buffer: PDF });
	await page.waitForTimeout(1000);
	ctx.info(`Dateiauswahl-Vorschau: ${await page.locator('.pdf-preview').innerHTML().catch(() => '–')}`.slice(0, 200));
	ctx.ok(await page.locator('embed, object').count() === 0, 'PDF-Dateiauswahl: kein embed/object (P13, Befund 2)');
	ctx.assertClean('PDF-Dateiauswahl', m);

	// zurück zur Notiz
	m = ctx.mark();
	const back = page.waitForURL((u) => u.pathname.endsWith('/index.php') && u.searchParams.get('note') === String(nid), { timeout: 10000 }).then(() => true, () => false);
	await page.click('.poznote-back-to-note-btn');
	ctx.ok(await back, 'Anhänge: „Zurück zur Notiz“ öffnet index.php?note=');
	await page.waitForLoadState('networkidle');
	ctx.assertClean('Zurück', m);

	// Hauptansicht + Notiz-Info
	m = ctx.mark();
	await visit('index', `index.php?note=${nid}&${wq}`, `#entry${nid}`);
	await checkXss('Hauptansicht');
	const infoBtn = page.locator(`[data-action="show-note-info"][data-note-id="${nid}"]`);
	ctx.ok(await infoBtn.isVisible(), 'Info-Knopf in der Unterzeile sichtbar');
	await infoBtn.click();
	const infoOk = await page.waitForFunction(() => {
		const v = document.querySelector('#noteInfoModal [data-note-info="title"]');
		return v && v.textContent.trim() !== '' && !/…|\.\.\./.test(v.textContent);
	}, null, { timeout: 10000 }).then(() => true, () => false);
	ctx.ok(infoOk, 'Notiz-Info: Dialog gefüllt');
	const infoTitle = await page.locator('#noteInfoModal [data-note-info="title"]').textContent().catch(() => null);
	ctx.ok(infoTitle?.trim() === EVIL.trim(), 'Notiz-Info: Titel als Text', infoTitle ?? '');
	ctx.ok(!/full_path|\/var\/www|\.html\b/.test(await page.locator('#noteInfoModal').innerText()), 'Notiz-Info: kein Speicherpfad (B33)');
	await checkXss('Notiz-Info');
	await ctx.screenshot(page, 'note-info').catch(() => {});
	ctx.assertClean('Notiz-Info', m);
	await page.click('#noteInfoModal [data-action="close-modal"]').catch(() => page.keyboard.press('Escape'));

	// Kanban in #right_col
	m = ctx.mark();
	await page.evaluate((f) => window.openKanbanView(f), fid);
	const board = await page.waitForSelector('#kanban-view-container .kanban-card', { timeout: 10000 }).then(() => true, () => false);
	ctx.ok(board, 'Kanban: Board mit Karten in #right_col');
	const cards = await page.locator('#kanban-view-container .kanban-card').allInnerTexts();
	ctx.ok(cards.some((t) => t.includes(`Zweite Karte ${RUN}`)) && cards.length >= 2, 'Kanban: zweite Karte vorhanden');
	await checkXss('Kanban');
	await ctx.screenshot(page, 'kanban').catch(() => {});
	ctx.assertClean('Kanban', m);

	// Navigation über die Knöpfe der Notiz
	for (const [btn, target] of [['.btn-snapshot', 'revisions.php'], ['.btn-attachment', 'attachments.php']]) {
		m = ctx.mark();
		await page.goto(ctx.appUrl(`index.php?note=${nid}&${wq}`), { waitUntil: 'networkidle' });
		const nav = page.waitForURL((u) => u.pathname.endsWith(`/${target}`) && u.searchParams.get('note_id') === String(nid), { timeout: 10000 }).then(() => true, () => false);
		const b = page.locator(`${btn}[data-note-id="${nid}"]`).first();
		if (await b.isVisible()) await b.click();
		else await b.evaluate((e) => e.click());
		ctx.ok(await nav, `Knopf ${btn} öffnet ${target}?note_id=`);
		await page.waitForLoadState('networkidle');
		ctx.assertClean(`Navigation ${target}`, m);
	}
	// show_note_created=0: Unterzeile ohne Datum/Info-Knopf → Info über ⋮-Menü
	m = ctx.mark();
	await api.must('PUT', '/settings/show_note_created', { value: '0' });
	try {
		await page.goto(ctx.appUrl(`index.php?note=${nid}&${wq}`), { waitUntil: 'networkidle' });
		ctx.ok(!(await page.locator(`.note-sub-info-btn[data-note-id="${nid}"]`).isVisible().catch(() => false)), 'show_note_created=0: Info-Knopf der Unterzeile verborgen');
		await page.locator('[data-action="toggle-mobile-toolbar-menu"]').first().click();
		const item = page.locator(`.mobile-toolbar-menu [data-action="show-note-info"][data-note-id="${nid}"]`);
		ctx.ok(await item.isVisible().catch(() => false), '⋮-Menü: „Information“ sichtbar');
		await item.click();
		const ok2 = await page.waitForFunction(() => {
			const mdl = document.querySelector('#noteInfoModal');
			const v = mdl?.querySelector('[data-note-info="title"]');
			return mdl && getComputedStyle(mdl).display !== 'none' && v && v.textContent.trim() !== '' && !/…|\.\.\./.test(v.textContent);
		}, null, { timeout: 10000 }).then(() => true, () => false);
		ctx.ok(ok2, '⋮ → Information öffnet #noteInfoModal (gefüllt)');
		await ctx.screenshot(page, 'info-menu').catch(() => {});
		await page.click('#noteInfoModal [data-action="close-modal"]').catch(() => page.keyboard.press('Escape'));
	} finally {
		await api.must('PUT', '/settings/show_note_created', { value: '1' });
	}
	ctx.assertClean('Info über ⋮-Menü', m);

	// ⋮-Menü öffnen ohne Fehler; „Zu Speed Dial hinzufügen“ fügt hinzu (Befund 1: fehlende .speed-dial-state-off)
	m = ctx.mark();
	const noteTags = async () => String((await api.must('GET', `/notes/${nid}?${wq}`)).note?.tags ?? '');
	ctx.ok(!/speed-dial/.test(await noteTags()), 'Speed Dial: vorher nicht enthalten');
	await page.goto(ctx.appUrl(`index.php?note=${nid}&${wq}`), { waitUntil: 'networkidle' });
	await page.locator('[data-action="toggle-mobile-toolbar-menu"]').first().click();
	const sdOff = page.locator(`.mobile-toolbar-menu .speed-dial-state-off[data-note-id="${nid}"]`);
	const sdOn = page.locator(`.mobile-toolbar-menu .speed-dial-state-on[data-note-id="${nid}"]`);
	ctx.ok(await sdOff.isVisible().catch(() => false) && !(await sdOn.isVisible().catch(() => false)), '⋮-Menü: „Zu Speed Dial hinzufügen“ sichtbar, „Entfernen“ nicht');
	const put = page.waitForResponse((r) => r.request().method() === 'PUT' && /\/notes\/\d+\/tags/.test(r.url()), { timeout: 10000 }).catch(() => null);
	await sdOff.click();
	const putResp = await put;
	ctx.ok(putResp?.status() === 200, `Speed Dial: Tags gespeichert (HTTP ${putResp?.status()})`);
	ctx.ok(/speed-dial/.test(await noteTags()), 'Speed Dial: hinzugefügt (nicht entfernt)');
	ctx.assertClean('⋮-Menü Speed Dial', m);

	// Audio im Editor einfügen (P5: audio_player.php mit App-Präfix)
	m = ctx.mark();
	const wav = (() => { const n = 800, b = Buffer.alloc(44 + n); b.write('RIFF', 0); b.writeUInt32LE(36 + n, 4); b.write('WAVEfmt ', 8); b.writeUInt32LE(16, 16); b.writeUInt16LE(1, 20); b.writeUInt16LE(1, 22); b.writeUInt32LE(8000, 24); b.writeUInt32LE(8000, 28); b.writeUInt16LE(1, 32); b.writeUInt16LE(8, 34); b.write('data', 36); b.writeUInt32LE(n, 40); b.fill(128, 44); return b.toString('base64'); })();
	await page.goto(ctx.appUrl(`index.php?note=${nid}&${wq}`), { waitUntil: 'networkidle' });
	const audioCall = await page.evaluate(({ id, b64 }) => {
		if (typeof window.insertAudioFileWithContext !== 'function') return 'fehlt';
		const entry = document.getElementById(`entry${id}`);
		const bytes = Uint8Array.from(atob(b64), (c) => c.charCodeAt(0));
		entry.focus();
		const r = document.createRange(); r.selectNodeContents(entry); r.collapse(false);
		window.insertAudioFileWithContext({ noteEntry: entry, editableElement: entry, savedRange: r, file: new File([bytes], 'ton.wav', { type: 'audio/wav' }) });
		return 'ok';
	}, { id: nid, b64: wav });
	const audioFrame = await page.waitForSelector(`#entry${nid} iframe[src*="audio_player"], #entry${nid} iframe[data-audio-src], #entry${nid} audio`, { timeout: 10000 }).then(() => true, () => false);
	await page.waitForTimeout(1500);
	const audioSrc = await page.locator(`#entry${nid} iframe, #entry${nid} audio`).first().getAttribute('src').catch(() => null);
	ctx.info(`Audio eingefügt: ${audioCall}, src=${audioSrc}`);
	ctx.ok(audioCall === 'ok' && audioFrame, 'Audio: Player im Editor eingefügt');
	ctx.ok(!audioSrc || audioSrc.includes('/apps/nextnotes/'), 'Audio: Player-URL mit App-Präfix', audioSrc ?? '');
	ctx.assertClean('Audio einfügen', m);

	// Vorschaukarten bei attachment_previews_in_note=1 (P5: Vorschau-/Audio-URL mit App-Präfix)
	m = ctx.mark();
	await api.must('PUT', '/settings/attachment_previews_in_note', { value: '1' });
	try {
		await page.goto(ctx.appUrl(`index.php?note=${nid}&${wq}`), { waitUntil: 'networkidle' });
		const cards = await page.waitForSelector('.note-attachment-preview', { timeout: 10000 }).then(() => true, () => false);
		await page.waitForTimeout(1500);
		const srcs = await page.locator('.note-attachment-preview iframe, .note-attachment-preview img, .note-attachment-preview a').evaluateAll((els) => els.map((e) => e.getAttribute('src') || e.getAttribute('href')).filter(Boolean));
		ctx.info(`Vorschaukarten: ${await page.locator('.note-attachment-preview').count()} · ${srcs.join(' ').slice(0, 300)}`);
		ctx.ok(cards, 'Vorschaukarten: in der Notiz gerendert');
		ctx.ok(srcs.every((u) => !/^\/(api\/v1|audio_player\.php)/.test(u)), 'Vorschaukarten: keine URL ohne App-Präfix', srcs.join(' '));
		await ctx.screenshot(page, 'previews').catch(() => {});
	} finally {
		await api.must('PUT', '/settings/attachment_previews_in_note', { value: '0' });
	}
	ctx.assertClean('Vorschaukarten', m);

	// Notizsperre freigeben (pagehide)
	await page.goto('about:blank').catch(() => {});
	ctx.assertClean('gesamter Lauf');
});
