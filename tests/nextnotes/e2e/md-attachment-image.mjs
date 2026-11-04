// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// M4b (Nebenfix IndexViewService): Markdown-Notiz mit Anhang-Bild zeigt das Bild beim direkten Laden der Seite (kein 404).
// Weg: Rich-Text-Notiz mit Base64-Bild → Anhang → per API in Markdown umwandeln → Seite mit ?note=ID laden.
import { runSpec } from './lib/harness.mjs';
import { apiClient } from './lib/api.mjs';

const RUN = Date.now().toString(36);
const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

await runSpec(import.meta.url, async (ctx, page) => {
	const api = apiClient(ctx, page.request);
	const WS = await api.defaultWorkspace();
	const fid = await api.folder(WS, `MdBild ${RUN}`);
	const id = await api.note(WS, `MdBild ${RUN}`, fid, '<p>x</p>');
	await api.must('PATCH', `/notes/${id}`, { content: `<p>vor <img src="data:image/png;base64,${PNG}" alt="Pixel"> nach</p>` });
	const atts = (await api.must('GET', `/notes/${id}/attachments`)).attachments ?? [];
	ctx.ok(atts.length === 1, 'Base64-Bild als Anhang gespeichert', JSON.stringify(atts).slice(0, 160));
	await api.must('POST', `/notes/${id}/convert`, { target: 'markdown' });
	const n = (await api.must('GET', `/notes/${id}`)).note;
	ctx.ok(n.type === 'markdown' && /!\[Pixel\]\([^)]*\/attachments\/\d+\)/.test(String(n.content)), 'Notiz ist Markdown mit Anhang-Bild', String(n.content).slice(0, 200));

	const bad = [];
	page.on('response', (r) => { if (/\/attachments\/\d+/.test(r.url()) && r.status() >= 400) bad.push(`${r.status()} ${r.url()}`); });
	await page.goto(ctx.appUrl(`index.php?workspace=${encodeURIComponent(WS)}&note=${id}`), { waitUntil: 'networkidle' });
	const img = page.locator(`#entry${id} img[src*="/attachments/"]`).first();
	await img.waitFor({ state: 'attached', timeout: 10000 }).catch(() => {});
	ctx.ok(await img.count() === 1, 'Bild im Markdown-Rendering vorhanden');
	const src = (await img.count()) ? await img.getAttribute('src') : '';
	ctx.ok(/\/apps\/nextnotes\//.test(new URL(src || 'about:blank', page.url()).pathname), 'Bild-URL mit App-Basis', src);
	const loaded = (await img.count()) ? await img.evaluate((el) => el.complete && el.naturalWidth > 0) : false;
	ctx.ok(loaded, 'Bild geladen (naturalWidth > 0)', src);
	ctx.ok(bad.length === 0, 'kein 4xx auf Anhang-Bild', bad.join(' | '));
});
