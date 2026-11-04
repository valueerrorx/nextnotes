// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// M4d (M4-Plan Done M4d, Testart): Ordner-Menü „Ordner herunterladen“ sichtbar und lädt ZIP; „alle Notizen“ (startDownload),
// strukturiert und Anhänge laden ZIP (Download-Ereignis, Dateiname, Inhalt); 0 CSP/Konsolenfehler; Fehlerseite bei Link-Klick (M4d-fix). Dunkel: nur Menü-Sichtbarkeit.
import { runSpec } from './lib/harness.mjs';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { apiClient } from './lib/api.mjs';

const RUN = `${process.argv[3]}${Date.now().toString(36)}`;

await runSpec(import.meta.url, async (ctx, page) => {
	const api = apiClient(ctx, page.request);
	const auth = `Basic ${Buffer.from(`${process.env.NN_USER}:${process.env.NN_PASS}`).toString('base64')}`;
	const WS = await api.workspace(`Zip ${RUN}`);
	const F = await api.folder(WS, `Ordner ${RUN}`);
	const html = await api.note(WS, `Html ${RUN}`, F, `<p>inhalt-${RUN}</p>`);
	const md = (await api.must('POST', '/notes', { heading: `MD ${RUN}`, type: 'markdown', content: `# md-${RUN}\n`, workspace: WS, folder_id: F })).note.id;
	const up = await page.request.post(`${ctx.base}/index.php/apps/nextnotes/api/v1/notes/${md}/attachments`, {
		headers: { Authorization: auth, 'OCS-APIRequest': 'true' }, multipart: { file: { name: 'a.txt', mimeType: 'text/plain', buffer: Buffer.from(`anhang-${RUN}`) } }, failOnStatusCode: false });
	ctx.ok(up.status() === 201, 'Anhang hochgeladen', String(up.status()));
	const hits = [];
	page.on('response', (r) => { if (r.status() === 501 || r.status() >= 500) hits.push(`${r.status()} ${new URL(r.url()).pathname}`); });

	await page.goto(ctx.appUrl(`index.php?workspace=${encodeURIComponent(WS)}&note=${html}`), { waitUntil: 'networkidle' });
	await ctx.selfTest(page);
	await page.locator(`#entry${html}`).waitFor({ state: 'attached', timeout: 10000 });
	const dir = mkdtempSync(join(tmpdir(), 'nn-zip-'));
	const entriesOf = (file) => execFileSync('unzip', ['-Z1', file], { encoding: 'utf8' }).split('\n').filter(Boolean);
	const textOf = (file) => execFileSync('unzip', ['-p', file], { encoding: 'utf8', maxBuffer: 1 << 26 });
	const save = async (dlP, name) => {
		const dl = await dlP;
		const file = join(dir, name);
		await dl.saveAs(file);
		return { name: dl.suggestedFilename(), file };
	};
	// 1. Ordner-Menü: Eintrag sichtbar (Hell und Dunkel)
	const m1 = ctx.mark();
	const row = page.locator(`#left_col .folder-header[data-folder-key="folder_${F}"] > .folder-toggle`).first();
	await row.hover();
	await row.locator('[data-action="toggle-folder-actions-menu"]').first().click();
	const item = page.locator('#folder-actions-menu [data-action="download-folder"]');
	ctx.ok(await item.isVisible(), 'Ordner-Menü: „Ordner herunterladen“ sichtbar');
	await ctx.screenshot(page, 'folder-menu');
	ctx.assertClean('Ordner-Menü', m1);
	if (ctx.theme === 'dark') {
		rmSync(dir, { recursive: true, force: true });
		return;
	}
	// Klick lädt <Ordner>_export.zip
	const m2 = ctx.mark();
	const dlFolder = page.waitForEvent('download', { timeout: 15000 });
	await page.locator('#folder-actions-menu [data-action="download-folder"]').click();
	const folder = await save(dlFolder, 'folder.zip');
	ctx.ok(folder.name === `Ordner ${RUN}_export.zip`, 'Ordner: Dateiname', folder.name);
	const fe = entriesOf(folder.file);
	ctx.ok(fe.includes('README.md') && fe.includes(`Html ${RUN}_${html}.html`) && fe.includes(`MD ${RUN}_${md}.md`) && fe.some((e) => /^attachments\/\d+\.txt$/.test(e)), 'Ordner: Aufbau wie Upstream', fe.join(','));
	ctx.ok(textOf(folder.file).includes(`anhang-${RUN}`) && textOf(folder.file).includes(`inhalt-${RUN}`), 'Ordner: Inhalt und Anhang');
	ctx.assertClean('Ordner-Download', m2);

	// 2. Alle Notizen (window.startDownload wie Upstream), strukturiert und Anhänge über Links
	const m3 = ctx.mark();
	let dl = page.waitForEvent('download', { timeout: 15000 });
	await page.evaluate(() => window.startDownload());
	const entries = await save(dl, 'entries.zip');
	ctx.ok(entries.name === 'poznote_notes_export.zip', 'Alle: Dateiname', entries.name);
	const ee = entriesOf(entries.file);
	ctx.ok(ee.includes('index.html') && ee.includes(`${html}.html`) && ee.includes(`${md}.md`), 'Alle: <id>.html|md und index.html', ee.length + ' Einträge');
	const link = async (href) => page.evaluate((h) => { const a = document.createElement('a'); a.href = h; a.download = ''; document.body.appendChild(a); a.click(); a.remove(); }, href);
	dl = page.waitForEvent('download', { timeout: 15000 });
	await link(`api_export_structured.php?workspace=${encodeURIComponent(WS)}`);
	const st = await save(dl, 'structured.zip');
	ctx.ok(/^poznote_structured_Zip_.+_\d{4}-\d{2}-\d{2}_\d{6}\.zip$/.test(st.name), 'Strukturiert: Dateiname', st.name);
	const se = entriesOf(st.file);
	ctx.ok(se.includes(`Ordner ${RUN}/Html ${RUN}.html`) && se.includes(`Ordner ${RUN}/MD ${RUN}.md`) && se.some((e) => e.startsWith('attachments/')), 'Strukturiert: Ordnerpfade und Anhänge', se.join(','));
	dl = page.waitForEvent('download', { timeout: 15000 });
	await link('api_export_attachments.php');
	const at = await save(dl, 'attachments.zip');
	const ae = entriesOf(at.file);
	ctx.ok(at.name === 'poznote_attachments_export.zip' && ae.includes('poznote_attachments_metadata.json') && ae.some((e) => /^files\/\d+\.txt$/.test(e)), 'Anhänge: Datei, Manifest', ae.join(','));
	ctx.assertClean('ZIP-Downloads', m3);
	ctx.ok(hits.length === 0, 'keine 5xx', hits.join(' | '));

	// 3. M4d-fix: Link auf fehlenden Ordner → NC-Fehlerseite mit 404 und Zurück-Link; fetch bekommt JSON
	const json = await page.evaluate(async () => { const r = await fetch('api_export_folder.php?folder_id=999999999'); return [r.status, (await r.text()).slice(0, 200)]; });
	ctx.ok(json[0] === 404 && json[1].includes('"success":false'), 'fetch: 404 JSON', json.join(' '));
	const nav = await page.goto(ctx.appUrl('api_export_folder.php?folder_id=999999999'));
	ctx.ok(nav.status() === 404, 'Link: Status 404', String(nav.status()));
	const box = page.locator('#nextnotes-export-error[data-status="404"]');
	ctx.ok(await box.isVisible(), 'Link: Fehlerseite sichtbar');
	await ctx.screenshot(page, 'export-error');
	await box.locator('a.button').click();
	await page.waitForURL(/\/apps\/nextnotes\/?(\?.*)?$/, { timeout: 15000 });
	ctx.ok(true, 'Zurück-Link führt in die App');
	rmSync(dir, { recursive: true, force: true });
});
