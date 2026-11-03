// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// M3b2 (SPEC §4.3.11, §4.5, §11.1 P2/P5/P12, §11.2 B22, ADR 0012): Anhänge (Zeile, Drop-Upload, Löschen, Header),
// PDF-iFrame, Audio-Player, Mermaid/KaTeX, Aufgaben-Aktionen (data-action statt onclick), Such-POST mit Token,
// requesttoken nie in der URL, Such-Parameter-XSS, Tag-Klick mit '; Fremdzugriff 404, Lesefreigabe 403.
// Je Abschnitt 0 CSP/pageerror/Konsole.
import { runSpec } from './lib/harness.mjs';
import { apiClient } from './lib/api.mjs';
import { shareRead } from './lib/docker.mjs';

const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAQAAAAECAYAAACp8Z5+AAAAEklEQVR4nGP4z8DwHxkzEC4AAGd3D/G4R5fdAAAAAElFTkSuQmCC', 'base64');
const PDF = Buffer.from('%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n');
const SVG = Buffer.from('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
function wav() {
	const n = 800, b = Buffer.alloc(44 + n);
	b.write('RIFF', 0); b.writeUInt32LE(36 + n, 4); b.write('WAVEfmt ', 8); b.writeUInt32LE(16, 16); b.writeUInt16LE(1, 20); b.writeUInt16LE(1, 22);
	b.writeUInt32LE(8000, 24); b.writeUInt32LE(8000, 28); b.writeUInt16LE(1, 32); b.writeUInt16LE(8, 34); b.write('data', 36); b.writeUInt32LE(n, 40); b.fill(128, 44);
	return b;
}
const RUN = Date.now().toString(36);
const ids = (page) => page.locator('#left_col a[data-note-id]').evaluateAll((els) => [...new Set(els.map((e) => e.dataset.noteId))]);

await runSpec(import.meta.url, async (ctx, page, context, browser) => {
	const api0 = apiClient(ctx, page.request);
	// SQLite: 503 bei Schreibkonflikt (SPEC M2d) → wiederholen
	const retry = async (fn) => { for (let i = 0; ; i++) { try { return await fn(); } catch (e) { if (i > 4 || !/503/.test(e.message)) throw e; await page.waitForTimeout(1500); } } };
	const api = { ...api0, note: (...a) => retry(() => api0.note(...a)), must: (...a) => retry(() => api0.must(...a)) };
	const basic = (u, p) => `Basic ${Buffer.from(`${u}:${p}`).toString('base64')}`;
	const attUrl = (n, a = '') => `${ctx.base}/index.php/apps/nextnotes/api/v1/notes/${n}/attachments${a === '' ? '' : `/${a}`}`;
	const upload = async (req, auth, id, name, mimeType, buffer, ws) => req.post(attUrl(id) + (ws ? `?workspace=${encodeURIComponent(ws)}` : ''), {
		headers: { Authorization: auth, 'OCS-APIRequest': 'true' }, multipart: { file: { name, mimeType, buffer } }, failOnStatusCode: false });
	const aliceAuth = basic(process.env.NN_USER, process.env.NN_PASS);
	const up = async (id, name, mime, buf) => {
		const r = await retry(async () => { const x = await upload(page.request, aliceAuth, id, name, mime, buf); if (x.status() === 503) throw new Error('503'); return x; });
		const b = await r.json();
		if (r.status() !== 201) throw new Error(`upload ${r.status()} ${JSON.stringify(b)}`);
		return b.attachment_id;
	};
	const WS = await api.defaultWorkspace();
	await api.must('PUT', '/settings/last_opened_workspace', { value: WS });
	await api.must('PUT', '/settings/attachment_previews_in_note', { value: '0' });
	const open = async (id, extra = '') => {
		await page.goto(ctx.appUrl(`index.php?workspace=${encodeURIComponent(WS)}&note=${id}${extra}`), { waitUntil: 'networkidle' });
		await page.waitForTimeout(700);
	};
	const waitFor = async (fn, ms = 8000) => {
		for (const end = Date.now() + ms; Date.now() < end; await page.waitForTimeout(250)) if (await fn()) return true;
		return fn();
	};
	const content = async (id) => (await api.must('GET', `/notes/${id}`)).note?.content ?? '';
	const tasksOf = async (id) => { try { return JSON.parse(await content(id)); } catch { return []; } };

	// A. Bild per Drop in HTML-Notiz → Upload, Autosave, Reload
	{
		const id = await api.note(WS, `Bild ${RUN}`, null, '<p>Start</p>');
		await open(id);
		const m = ctx.mark();
		await page.click(`#entry${id} p`);
		await page.evaluate(async ({ id, b64 }) => {
			const bin = atob(b64); const u = new Uint8Array(bin.length); for (let i = 0; i < bin.length; i++) u[i] = bin.charCodeAt(i);
			const dt = new DataTransfer(); dt.items.add(new File([u], 'pixel.png', { type: 'image/png' }));
			const entry = document.getElementById('entry' + id);
			const r = entry.getBoundingClientRect();
			for (const type of ['dragenter', 'dragover', 'drop']) entry.dispatchEvent(new DragEvent(type, { bubbles: true, cancelable: true, dataTransfer: dt, clientX: r.left + 20, clientY: r.top + 10 }));
		}, { id, b64: PNG.toString('base64') });
		const loaded = await waitFor(() => page.evaluate((id) => { const i = document.querySelector(`#entry${id} img`); return !!i && i.complete && i.naturalWidth > 0 && !/^(blob|data):/.test(i.src); }, id), 10000);
		const src = await page.evaluate((id) => document.querySelector(`#entry${id} img`)?.getAttribute('src') ?? '', id);
		ctx.ok(loaded && src.includes('/apps/nextnotes/api/v1/notes/'), 'A: Bild nach Drop geladen, URL mit App-Präfix (P12)', src);
		ctx.ok(await waitFor(async () => /\/api\/v1\/notes\/\d+\/attachments\/\d+/.test(await content(id)), 12000), 'A: Bild-Verweis gespeichert');
		await open(id);
		ctx.ok(await page.evaluate((id) => (document.querySelector(`#entry${id} img`)?.naturalWidth ?? 0) > 0, id), 'A: Bild nach Reload geladen');
		ctx.assertClean('A Bild', m);
	}

	// B. Anhangzeile (Dateiname-XSS), Öffnen, Löschen über das Anhang-Menü, Weiterschreiben ohne 409-Verlust
	const pdfNote = await api.note(WS, `Anhang ${RUN}`, null, '<p>Mit Anhängen</p>');
	const pdfId = await up(pdfNote, 'doku.pdf', 'application/pdf', PDF);
	{
		const txtId = await up(pdfNote, "notiz l'été.txt", 'text/plain', Buffer.from('hallo'));
		await up(pdfNote, "<img src=x onerror=window.__nnX=1>'.txt", 'text/plain', Buffer.from('x'));
		await api.must('PATCH', `/notes/${pdfNote}`, { content: `<p>Link: <a href="/api/v1/notes/${pdfNote}/attachments/${txtId}">datei</a></p>`, workspace: WS });
		await open(pdfNote);
		const m = ctx.mark();
		const links = await page.locator(`#note${pdfNote} .note-attachments-row .attachment-link`).allInnerTexts();
		ctx.ok(links.includes('doku.pdf') && links.includes("notiz l'été.txt") && links.length === 3, 'B: Anhangzeile mit allen Dateien', JSON.stringify(links));
		ctx.ok(await page.locator(`#note${pdfNote} .note-attachments-row img`).count() === 0 && !(await page.evaluate(() => window.__nnX)), 'B: Dateiname-XSS wirkungslos');
		const [popup] = await Promise.all([page.waitForEvent('popup'), page.click(`#note${pdfNote} .attachment-link[data-attachment-id="${pdfId}"]`)]);
		await popup.waitForLoadState().catch(() => {});
		ctx.ok(popup.url().includes(`/apps/nextnotes/api/v1/notes/${pdfNote}/attachments/${pdfId}`), 'B: Klick öffnet Anhang mit App-Präfix', popup.url());
		await popup.close();
		await page.click(`#entry${pdfNote} a`, { button: 'right' });
		await page.waitForTimeout(800);
		const del = page.locator('[data-action="delete"], .note-attachment-menu [data-action*="delete"]').first();
		ctx.ok(await del.count() === 1, 'B: Anhang-Menü mit Löschen');
		if (await del.count()) {
			await del.click();
			await page.waitForTimeout(500);
			const confirm = page.locator('.modal-alert-confirm, #customConfirmModal .btn-danger, .poznote-modal .btn-danger, button:has-text("Löschen"):visible').first();
			if (await confirm.count()) await confirm.click();
			ctx.ok(await waitFor(async () => !(await api.must('GET', `/notes/${pdfNote}/attachments`)).attachments.some((a) => String(a.id) === String(txtId)), 8000), 'B: Anhang gelöscht');
			await page.waitForTimeout(4000);
			ctx.ok(!(await content(pdfNote)).includes(`attachments/${txtId}`), 'B: Verweis aus Inhalt entfernt');
			await page.click(`#entry${pdfNote}`);
			await page.keyboard.type(' weiter');
			ctx.ok(await waitFor(async () => (await content(pdfNote)).includes('weiter'), 10000), 'B: Weiterschreiben nach Löschen gespeichert');
		}
		ctx.assertClean('B Anhangzeile', m);
	}

	// C. Auslieferung (B22): Content-Type, nosniff, CSP sandbox (außer PDF), SVG/HTML als Download
	{
		const hid = await api.note(WS, `Header ${RUN}`, null, '<p>h</p>');
		const files = {
			png: await up(hid, 'p.png', 'image/png', PNG),
			svg: await up(hid, 'x.svg', 'image/svg+xml', SVG),
			html: await up(hid, 'x.html', 'text/html', Buffer.from('<script>alert(1)</script>')),
			txt: await up(hid, 't.txt', 'text/plain', Buffer.from('t')),
			pdf: await up(hid, 'd.pdf', 'application/pdf', PDF),
		};
		const h = {};
		for (const [k, aid] of Object.entries(files)) h[k] = (await page.request.get(attUrl(hid, aid), { failOnStatusCode: false })).headers();
		const csp = (k) => h[k]['content-security-policy'] ?? '';
		const disp = (k) => h[k]['content-disposition'] ?? '';
		for (const k of Object.keys(files)) ctx.ok(/^nosniff(, nosniff)*$/.test(h[k]['x-content-type-options'] ?? ''), `C: ${k} nosniff`, h[k]['x-content-type-options']);
		for (const k of ['png', 'svg', 'html', 'txt']) ctx.ok(/sandbox/.test(csp(k)) && /default-src 'none'/.test(csp(k)) && /frame-ancestors 'self'/.test(csp(k)), `C: ${k} CSP sandbox`, csp(k));
		ctx.ok(/frame-ancestors 'self'/.test(csp('pdf')) && !/sandbox/.test(csp('pdf')), "C: PDF CSP frame-ancestors 'self' ohne sandbox", csp('pdf'));
		ctx.ok(/^attachment/.test(disp('svg')), 'C: SVG als Download', disp('svg'));
		ctx.ok(/^attachment/.test(disp('html')), 'C: HTML als Download', disp('html'));
		ctx.ok(/^inline/.test(disp('png')) && /^image\/png/.test(h.png['content-type']), 'C: PNG inline image/png', `${disp('png')} ${h.png['content-type']}`);
		ctx.ok(/^inline/.test(disp('pdf')) && /^application\/pdf/.test(h.pdf['content-type']), 'C: PDF inline application/pdf', `${disp('pdf')} ${h.pdf['content-type']}`);
		ctx.ok(/^text\/plain/.test(h.txt['content-type']), 'C: Text als text/plain', h.txt['content-type']);
	}

	// D. PDF-Vorschau im iFrame der Notiz
	{
		await api.must('PUT', '/settings/attachment_previews_in_note', { value: '1' });
		const m = ctx.mark();
		const respP = page.waitForResponse((r) => r.url().includes(`/attachments/${pdfId}`) && r.request().resourceType() === 'document', { timeout: 15000 }).catch(() => null);
		await open(pdfNote);
		const resp = await respP;
		ctx.ok(!!resp && resp.status() === 200, 'D: PDF-iFrame lädt (200)', resp ? String(resp.status()) : 'keine Antwort');
		ctx.ok(await page.locator(`#attachment-previews-${pdfNote} iframe.note-attachment-preview-frame`).count() === 1, 'D: Vorschau-iFrame vorhanden');
		await page.waitForTimeout(800);
		ctx.assertClean('D PDF', m);
		await api.must('PUT', '/settings/attachment_previews_in_note', { value: '0' });
	}

	// E. Audio-Player (eigene Seite im iFrame), unbekannte ID → 404, Parameter-XSS
	let audioNote, audioId;
	{
		audioNote = await api.note(WS, `Audio ${RUN}`, null, '<p>Audio</p>');
		audioId = await up(audioNote, 'ton.wav', 'audio/wav', wav());
		await api.must('PATCH', `/notes/${audioNote}`, { content: `<p>x</p><iframe class="note-audio-embed" contenteditable="false" data-is-audio="true" src="/audio_player.php?note=${audioNote}&amp;attachment=${audioId}"></iframe>`, workspace: WS });
		const m = ctx.mark();
		await open(audioNote);
		const fr = page.frames().find((f) => f.url().includes('audio_player.php'));
		ctx.ok(!!fr && fr.url().includes('/apps/nextnotes/audio_player.php'), 'E: Audio-iFrame mit App-Präfix', fr?.url() ?? '');
		ctx.ok(!!fr && (await fr.locator('audio').count()) === 1, 'E: <audio> im Player');
		ctx.assertClean('E Audio', m);
		for (const q of [`note=${audioNote}&attachment=999999`, `note=999999&attachment=${audioId}`, `note[]=1&attachment=${audioId}`]) {
			const st = await page.request.get(ctx.appUrl(`audio_player.php?${q}`), { failOnStatusCode: false });
			ctx.ok(st.status() === 404, `E: audio_player.php?${q} → 404`, String(st.status()));
		}
		const x = await page.request.get(ctx.appUrl(`audio_player.php?note=${audioNote}&attachment=${audioId}&workspace=${encodeURIComponent('"><script>alert(1)</script>')}`), { failOnStatusCode: false });
		const body = await x.text();
		ctx.ok(!body.includes('<script>alert(1)') && !/"><script/.test(body), 'E: workspace-Parameter escaped', `${x.status()}`);
	}

	// F. Mermaid/KaTeX in Markdown, erst bei Bedarf geladen
	{
		const id = (await api.must('POST', '/notes', { heading: `MD ${RUN}`, content: '# T\n\n```mermaid\ngraph TD; A-->B;\n```\n\n$$E=mc^2$$\n', type: 'markdown', workspace: WS })).note.id;
		await page.goto(ctx.appUrl(`index.php?workspace=${encodeURIComponent(WS)}`), { waitUntil: 'networkidle' });
		const before = ctx.requests.filter((r) => /js\/vendor\/(mermaid|katex)/.test(r.url)).length;
		ctx.ok(before === 0, 'F: kein Mermaid/KaTeX-Request vor Bedarf', String(before));
		const m = ctx.mark();
		await open(id);
		ctx.ok(await waitFor(() => page.locator(`#note${id} .mermaid svg`).count().then((c) => c > 0), 10000), 'F: Mermaid-SVG');
		ctx.ok(await waitFor(() => page.locator(`#note${id} .katex`).count().then((c) => c > 0), 8000), 'F: KaTeX gerendert');
		ctx.assertClean('F Mermaid/KaTeX', m);
	}

	// G. Aufgabenliste (P2: data-action statt Inline-Handler)
	{
		const tasks = JSON.stringify([{ id: 1, text: 'Erste Aufgabe', completed: false }, { id: 2, text: 'Zweite https://example.org', completed: false }]);
		const id = (await api.must('POST', '/notes', { heading: `Tasks ${RUN}`, content: tasks, type: 'tasklist', workspace: WS })).note.id;
		const m = ctx.mark();
		await open(id);
		ctx.ok(await page.locator(`#entry${id} [onclick], #entry${id} [onchange]`).count() === 0, 'G: keine Inline-Handler im Aufgaben-HTML');
		await page.locator(`#entry${id} .task-item[data-task-id="1"] .task-checkbox`).click();
		ctx.ok(await waitFor(async () => (await tasksOf(id)).find((t) => String(t.id) === '1')?.completed === true, 10000), 'G: Checkbox speichert');
		await page.locator(`#entry${id} .task-item[data-task-id="2"] .task-important-btn`).click();
		ctx.ok(await waitFor(async () => (await tasksOf(id)).find((t) => String(t.id) === '2')?.important === true, 10000), 'G: Wichtig-Knopf');
		const [pop] = await Promise.all([page.waitForEvent('popup', { timeout: 5000 }).catch(() => null), page.locator(`#entry${id} .task-item[data-task-id="2"] a[data-task-url]`).click()]);
		ctx.ok(!!pop, 'G: Link öffnet neues Fenster');
		if (pop) await pop.close();
		await page.locator(`#entry${id} .task-item[data-task-id="1"] .task-delete-btn`).click();
		ctx.ok(await waitFor(async () => !(await tasksOf(id)).some((t) => String(t.id) === '1'), 10000), 'G: Löschen-Knopf');
		ctx.assertClean('G Aufgaben', m);
	}

	// H. Suche: POST mit requesttoken, URL ohne Token, Reload, nativer Submit, ohne Token 412
	{
		const hit = await api.note(WS, `Suchziel ${RUN}`, null, `<p>zebra${RUN}</p>`);
		await page.goto(ctx.appUrl(`index.php?workspace=${encodeURIComponent(WS)}`), { waitUntil: 'networkidle' });
		const m = ctx.mark();
		await page.fill('#unified-search', `zebra${RUN}`);
		const [post] = await Promise.all([page.waitForResponse((r) => r.request().method() === 'POST' && new URL(r.url()).pathname.endsWith('/index.php')), page.press('#unified-search', 'Enter')]);
		ctx.ok(post.status() === 200 && (post.request().postData() ?? '').includes('requesttoken='), 'H: Such-POST 200 mit requesttoken', String(post.status()));
		await page.waitForTimeout(800);
		ctx.ok(!page.url().includes('requesttoken'), 'H: requesttoken nicht in der Adresszeile', page.url());
		ctx.ok(JSON.stringify(await ids(page)) === JSON.stringify([String(hit)]), 'H: Liste zeigt nur den Treffer', JSON.stringify(await ids(page)));
		ctx.ok(await page.locator(`#note${hit}`).count() === 1, 'H: Treffer rechts geöffnet');
		await page.reload({ waitUntil: 'networkidle' });
		ctx.ok(JSON.stringify(await ids(page)) === JSON.stringify([String(hit)]), 'H: Reload behält Suche', page.url());
		ctx.assertClean('H Suche', m);
		await page.goto(ctx.appUrl(`index.php?workspace=${encodeURIComponent(WS)}`), { waitUntil: 'networkidle' });
		await page.fill('#unified-search', `zebra${RUN}`);
		await page.evaluate(() => { document.getElementById('search-notes-hidden').value = document.getElementById('unified-search').value; });
		const [nav] = await Promise.all([page.waitForNavigation(), page.evaluate(() => document.getElementById('unified-search-form').submit())]);
		ctx.ok(nav?.status() === 200 && (nav.request().postData() ?? '').includes('requesttoken='), 'H: nativer Submit 200 mit requesttoken', String(nav?.status()));
		ctx.ok(JSON.stringify(await ids(page)) === JSON.stringify([String(hit)]) && !page.url().includes('requesttoken'), 'H: nativer Submit zeigt Treffer, URL ohne Token', page.url());
		const noTok = await page.request.post(ctx.appUrl('index.php'), { form: { unified_search: 'x', search_combined: '1', workspace: WS }, failOnStatusCode: false });
		ctx.ok(noTok.status() === 412, 'H: POST ohne requesttoken → 412', String(noTok.status()));
		const badTok = await page.request.post(ctx.appUrl('index.php'), { form: { unified_search: 'x', search_combined: '1', workspace: WS, requesttoken: 'falsch' }, failOnStatusCode: false });
		ctx.ok(badTok.status() === 412, 'H: POST mit falschem requesttoken → 412', String(badTok.status()));
		ctx.ok(!ctx.requests.some((r) => r.url.includes('requesttoken=')), 'H: requesttoken in keiner Request-URL', ctx.requests.find((r) => r.url.includes('requesttoken='))?.url ?? '');
	}

	// I. XSS über Such-Parameter (GET)
	{
		const m = ctx.mark();
		await page.goto(ctx.appUrl(`index.php?workspace=${encodeURIComponent(WS)}&search=${encodeURIComponent('"><img src=x onerror=window.__nnY=1>')}&tags_search=${encodeURIComponent("'><b>t</b>")}&created_from=${encodeURIComponent('"><i>x</i>')}`), { waitUntil: 'networkidle' });
		ctx.ok(await page.locator('#unified-search-form img, #unified-search-form b, #unified-search-form i:not([class])').count() === 0 && !(await page.evaluate(() => window.__nnY)), 'I: Such-Parameter escaped');
		ctx.ok((await page.inputValue('#unified-search')).includes('<img'), 'I: Suchfeld als Text vorbelegt');
		ctx.assertClean('I XSS Suche', m);
	}

	// J. Tag-Klick mit ' filtert
	{
		const t1 = await api.note(WS, `Tag1 ${RUN}`, null, '<p>t</p>');
		await api.note(WS, `Tag2 ${RUN}`, null, '<p>t</p>');
		await api.must('PATCH', `/notes/${t1}`, { tags: `l'été${RUN}`, workspace: WS });
		await open(t1);
		const m = ctx.mark();
		const tag = page.locator(`#note${t1} .name_tags .clickable-tag, #note${t1} .name_tags [data-tag]`).first();
		ctx.ok(await tag.count() === 1, 'J: Tag klickbar');
		// P19: Tag-Klick baut nur #left_col neu (kein Seiten-Reload)
		await Promise.all([page.waitForResponse((r) => r.request().headers()['x-poznote-fragment'] === 'left_col'), tag.click()]);
		await page.waitForFunction((id) => [...document.querySelectorAll('#left_col a[data-note-id]')].every((a) => a.dataset.noteId === id), String(t1), { timeout: 5000 }).catch(() => {});
		const got = await ids(page);
		ctx.ok(new URL(page.url()).searchParams.get('tags_search') === `l'été${RUN}` && JSON.stringify(got) === JSON.stringify([String(t1)]), 'J: Tag-Klick filtert auf die Notiz', `${page.url()} ${JSON.stringify(got)}`);
		ctx.ok(await page.locator(`#left_col a.selected-note[data-note-id="${t1}"]`).count() >= 1, 'J: Notiz im Baum markiert (selected-note)');
		ctx.ok(!page.url().includes('requesttoken'), 'J: URL ohne requesttoken', page.url());
		ctx.assertClean('J Tag-Klick', m);
	}

	// L. Mehrwort/Prozent über das Suchfeld, Klick in der Liste, Aufgabe per Enter ohne Navigation
	{
		const w1 = await api.note(WS, `Wort ${RUN}`, null, `<p>kiwi${RUN} 50% mango${RUN}</p>`);
		await api.note(WS, `Wort2 ${RUN}`, null, `<p>kiwi${RUN} 500 nur</p>`);
		const w3 = await api.note(WS, `Wort3 ${RUN}`, null, `<p>mango${RUN} kiwi${RUN} rest</p>`);
		const searchUi = async (term) => {
			await page.goto(ctx.appUrl(`index.php?workspace=${encodeURIComponent(WS)}`), { waitUntil: 'networkidle' });
			await page.fill('#unified-search', term);
			await Promise.all([page.waitForNavigation(), page.press('#unified-search', 'Enter')]);
			await page.waitForLoadState('networkidle');
			return (await ids(page)).sort();
		};
		const m = ctx.mark();
		ctx.ok(JSON.stringify(await searchUi(`mango${RUN} kiwi${RUN}`)) === JSON.stringify([String(w1), String(w3)].sort()), 'L: Mehrwort = UND');
		ctx.ok(JSON.stringify(await searchUi(`kiwi${RUN} 50%`)) === JSON.stringify([String(w1)]), 'L: % wörtlich');
		await page.goto(ctx.appUrl(`index.php?workspace=${encodeURIComponent(WS)}&note=${w1}`), { waitUntil: 'networkidle' });
		const other = page.locator(`#left_col a[data-note-id="${w3}"]`).first();
		await other.click();
		ctx.ok(await waitFor(() => page.locator(`#note${w3}`).count().then((c) => c === 1), 8000), 'L: Klick in der Liste öffnet Notiz');
		ctx.assertClean('L Suche/Liste', m);
		const tl = (await api.must('POST', '/notes', { heading: `TL ${RUN}`, content: '[]', type: 'tasklist', workspace: WS })).note.id;
		await open(tl);
		const m2 = ctx.mark();
		const url = page.url();
		let navs = 0;
		const onNav = (f) => { if (f === page.mainFrame()) navs++; };
		page.on('framenavigated', onNav);
		await page.fill(`#task-input-${tl}`, 'Neue Aufgabe');
		await page.press(`#task-input-${tl}`, 'Enter');
		ctx.ok(await waitFor(async () => (await tasksOf(tl)).some((t) => t.text === 'Neue Aufgabe'), 10000), 'L: Enter legt Aufgabe an');
		page.off('framenavigated', onNav);
		ctx.ok(navs === 0 && page.url() === url, 'L: keine Navigation bei Enter', `${navs} ${page.url()}`);
		ctx.assertClean('L Aufgabe Enter', m2);
	}

	// K. Fremdzugriff (bob): fremder Anhang/Audio-Player → 404; Lesefreigabe: lesen ja, Upload/Löschen 403
	{
		const bctx = await browser.newContext({ viewport: { width: 1400, height: 900 }, locale: 'de-DE' });
		const pb = await bctx.newPage();
		await ctx.login(pb, process.env.NN_USER2, process.env.NN_PASS2);
		const bobAuth = basic(process.env.NN_USER2, process.env.NN_PASS2);
		const bapi = apiClient(ctx, bctx.request, process.env.NN_USER2, process.env.NN_PASS2);
		const g1 = await pb.request.get(attUrl(audioNote, audioId), { failOnStatusCode: false });
		ctx.ok(g1.status() === 404, 'K: fremder Anhang (Sitzung) → 404', String(g1.status()));
		const g2 = await pb.request.get(ctx.appUrl(`audio_player.php?note=${audioNote}&attachment=${audioId}`), { failOnStatusCode: false });
		ctx.ok(g2.status() === 404, 'K: fremder Audio-Player → 404', String(g2.status()));
		const g3 = await bapi.call('GET', `/notes/${audioNote}/attachments`);
		ctx.ok(g3.status === 404, 'K: fremde Anhangliste → 404', String(g3.status));
		const u1 = await upload(bctx.request, bobAuth, audioNote, 'b.txt', 'text/plain', Buffer.from('b'));
		ctx.ok(u1.status() === 404, 'K: Upload in fremde Notiz → 404', String(u1.status()));
		const d1 = await bapi.call('DELETE', `/notes/${audioNote}/attachments/${audioId}`);
		ctx.ok(d1.status === 404 || d1.status === 403, 'K: Löschen fremder Anhang → 403/404', String(d1.status));
		// Lesefreigabe
		const roWs = `AS-RO ${RUN}`;
		await api.workspace(roWs);
		const roNote = await api.note(roWs, `RO ${RUN}`, null, '<p>ro</p>');
		const roAtt = await up(roNote, 'r.txt', 'text/plain', Buffer.from('r'));
		shareRead(ctx.base, process.env.NN_USER, roWs, process.env.NN_USER2);
		const r1 = await pb.request.get(attUrl(roNote, roAtt) + `?workspace=${encodeURIComponent(roWs)}`, { failOnStatusCode: false });
		ctx.ok(r1.status() === 200, 'K: Lesefreigabe Anhang lesbar → 200', String(r1.status()));
		const r2 = await upload(bctx.request, bobAuth, roNote, 'b.txt', 'text/plain', Buffer.from('b'), roWs);
		ctx.ok(r2.status() === 403, 'K: Lesefreigabe Upload → 403', String(r2.status()));
		const r3 = await bapi.call('DELETE', `/notes/${roNote}/attachments/${roAtt}?workspace=${encodeURIComponent(roWs)}`);
		ctx.ok(r3.status === 403, 'K: Lesefreigabe Löschen → 403', String(r3.status));
		ctx.ok((await api.must('GET', `/notes/${roNote}/attachments`)).attachments.length === 1, 'K: Anhang unverändert');
		await bctx.close();
	}
});
