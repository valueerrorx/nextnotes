// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// M4c (M4-Plan Done M4c 1, 2, 6, 7): Notizmenü „Herunterladen“/„Drucken“ sichtbar, Export-Modal je Typ, Download mit Dateiname;
// Druckansicht rendert KaTeX/Mermaid, poznotePrintReady erfüllt, erlaubter Embed (Stub-Host) lädt, frame-src wie index.php?note=;
// Header Content-Disposition/nosniff; 0 CSP-Verletzungen, keine 501.
import { runSpec } from './lib/harness.mjs';
import { execFileSync } from 'node:child_process';
import { apiClient } from './lib/api.mjs';
import { ncContainer } from './lib/docker.mjs';

const RUN = `${process.argv[3]}${Date.now().toString(36)}`;
const HOST = 'embed.nn-test.example';

await runSpec(import.meta.url, async (ctx, page, context) => {
	const api = apiClient(ctx, page.request);
	const occ = (...a) => execFileSync('docker', ['exec', '-u', 'www-data', ncContainer(ctx.base), 'php', 'occ', ...a], { encoding: 'utf8' }).trim();
	await context.route(/^https:\/\/([a-z0-9-]+\.)*embed\.nn-test\.example\//, (route) => route.fulfill({
		status: 200, contentType: 'text/html', body: '<!doctype html><title>stub</title><p id="stub">stub</p>' }));
	// Druckdialog im Test nicht öffnen, nur Aufruf merken (gilt auch im Popup)
	await context.addInitScript(() => { window.print = () => { window.__nnPrinted = true; }; });
	const hits = [];
	page.on('response', (r) => { if (r.status() === 501) hits.push(`${r.request().method()} ${new URL(r.url()).pathname}`); });
	page.on('dialog', (d) => (d.type() === 'beforeunload' ? d.accept() : d.dismiss()).catch(() => {}));

	occ('config:app:set', 'nextnotes', 'allowed_iframe_domains', `--value=${JSON.stringify([HOST, 'youtube.com'])}`, '--type=array');
	try {
		await page.waitForTimeout(4000); // AppConfig-Lokalcache des Webservers
		const WS = await api.workspace(`Export ${RUN}`);
		const src = `https://${HOST}/v/${RUN}`;
		const md = (await api.must('POST', '/notes', { heading: `Druck MD ${RUN}`, type: 'markdown', workspace: WS,
			content: `# Kopf ${RUN}\n\nFormel $x^2$ und\n\n$$\\frac{a}{b}$$\n\n\`\`\`mermaid\ngraph TD; A-->B\n\`\`\`\n\n<iframe src="${src}"></iframe>\n` })).note.id;
		const rt = await api.note(WS, `Export RT ${RUN}`, null, `<p>Rich ${RUN}</p>`);
		const open = async (id) => {
			await page.goto(ctx.appUrl(`index.php?workspace=${encodeURIComponent(WS)}&note=${id}`), { waitUntil: 'networkidle' });
			await page.locator(`#entry${id}`).waitFor({ state: 'attached', timeout: 10000 });
			await page.waitForTimeout(400);
		};
		const menu = async (id) => {
			const card = page.locator(`#note${id}`);
			await card.locator('[data-action="toggle-mobile-toolbar-menu"]').click();
			return card.locator('.mobile-toolbar-menu');
		};

		// 1. Menüpunkte und Export-Modal (Markdown-Notiz: Markdown sichtbar, JSON nicht)
		const m1 = ctx.mark();
		await open(md);
		const card = page.locator(`#note${md}`);
		// Upstream utilities.css: Werkzeugleisten-Knopf ab 800 px verborgen, Menüpunkt löst ihn aus
		ctx.ok(await card.locator('.btn-download[data-action="show-export-modal"]').count() === 1, 'Werkzeugleiste: Herunterladen vorhanden');
		let dd = await menu(md);
		ctx.ok(await dd.locator('[data-selector=".btn-download"]').isVisible(), 'Menü: Herunterladen sichtbar');
		ctx.ok(await dd.locator('[data-action="print-note"]').isVisible(), 'Menü: Drucken sichtbar');
		await ctx.screenshot(page, 'menu');
		await dd.locator('[data-selector=".btn-download"]').click();
		const modal = page.locator('#exportModal');
		await modal.waitFor({ state: 'visible', timeout: 5000 });
		ctx.ok(await modal.locator('.export-option-markdown').isVisible() && !(await modal.locator('.export-option-json').isVisible()), 'Modal MD: Markdown ja, JSON nein');
		await ctx.screenshot(page, 'modal');
		let dl = page.waitForEvent('download', { timeout: 10000 });
		await modal.locator('.export-option-markdown').click();
		ctx.ok((await dl).suggestedFilename() === `Druck MD ${RUN}.md`, 'Download Markdown: Dateiname', (await dl).suggestedFilename());
		ctx.assertClean('Menü/Modal', m1);

		// 2. Rich-Text-Notiz: Modal ohne Markdown, HTML-Download
		const m2 = ctx.mark();
		await open(rt);
		await (await menu(rt)).locator('[data-selector=".btn-download"]').click();
		await modal.waitFor({ state: 'visible', timeout: 5000 });
		ctx.ok(!(await modal.locator('.export-option-markdown').isVisible()), 'Modal RT: kein Markdown');
		dl = page.waitForEvent('download', { timeout: 10000 });
		await modal.locator('.export-option-html').click();
		ctx.ok((await dl).suggestedFilename() === `Export RT ${RUN}.html`, 'Download HTML: Dateiname', (await dl).suggestedFilename());
		ctx.assertClean('RT-Download', m2);

		// 3. Druckansicht aus dem Menü: KaTeX, Mermaid, Embed, poznotePrintReady, Druck aufgerufen
		const m3 = ctx.mark();
		await open(md);
		const indexCsp = (await page.request.get(ctx.appUrl(`index.php?workspace=${encodeURIComponent(WS)}&note=${md}`))).headers()['content-security-policy'] ?? '';
		dd = await menu(md);
		const popupP = page.waitForEvent('popup', { timeout: 10000 });
		await dd.locator('[data-action="print-note"]').click();
		const popup = await popupP;
		await popup.waitForLoadState('load');
		const ready = await popup.evaluate(() => Promise.race([Promise.resolve(window.poznotePrintReady).then(() => true), new Promise((r) => setTimeout(() => r(false), 15000))]));
		ctx.ok(ready, 'poznotePrintReady erfüllt');
		ctx.ok(await popup.locator('.noteentry .katex').count() >= 2, 'KaTeX gerendert (inline + Block)');
		ctx.ok(await popup.locator('.noteentry .mermaid svg').count() === 1, 'Mermaid gerendert');
		let stub = '';
		for (let i = 0; i < 20 && stub !== 'stub'; i++) {
			const f = popup.frames().find((x) => x.url().startsWith(`https://${HOST}/`));
			stub = f ? await f.locator('#stub').innerText({ timeout: 500 }).catch(() => '') : '';
			if (stub !== 'stub') await popup.waitForTimeout(500);
		}
		ctx.ok(stub === 'stub', 'Embed in der Druckansicht geladen');
		await popup.waitForTimeout(600);
		ctx.ok(await popup.evaluate(() => window.__nnPrinted === true), 'Druck nach poznotePrintReady aufgerufen');
		const printResp = await page.request.get(popup.url());
		const frameSrc = (csp) => (csp.match(/(?:^|;)\s*frame-src ([^;]*)/)?.[1] ?? '').trim();
		const printCsp = printResp.headers()['content-security-policy'] ?? '';
		ctx.ok(frameSrc(printCsp) !== '' && frameSrc(printCsp) === frameSrc(indexCsp) && frameSrc(printCsp).includes(`https://${HOST}`), 'frame-src wie index.php?note=', `${frameSrc(printCsp)} | ${frameSrc(indexCsp)}`);
		ctx.ok(!printResp.headers()['content-disposition'] && /nosniff/.test(printResp.headers()['x-content-type-options'] ?? ''), 'Druckansicht inline, nosniff');
		await ctx.screenshot(popup, 'print');
		await popup.close();
		ctx.assertClean('Druckansicht', m3);

		// 4. Header (Done 6): attachment + nosniff, inline nur für format=html
		for (const [q, name] of [[`id=${md}&format=markdown&disposition=inline`, `Druck MD ${RUN}.md`], [`id=${rt}&format=html_embedded&disposition=inline`, `Export RT ${RUN}.html`], [`id=${rt}`, `Export RT ${RUN}.html`]]) {
			const r = await page.request.get(ctx.appUrl(`api_export_note.php?${q}`));
			const h = r.headers();
			ctx.ok(r.status() === 200 && (h['content-disposition'] ?? '').startsWith('attachment') && h['content-disposition'].includes(name) && /nosniff/.test(h['x-content-type-options'] ?? ''),
				`Header ${q}: attachment, Name, nosniff`, `${r.status()} ${h['content-disposition']}`);
		}
		ctx.ok(hits.length === 0, 'keine 501', hits.join(' | '));
	} finally {
		occ('config:app:delete', 'nextnotes', 'allowed_iframe_domains');
	}
});
