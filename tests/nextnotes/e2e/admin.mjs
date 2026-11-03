// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// M4a (M4-Plan §1.4, SPEC §11.2 B41, §11.1 P17): Admin-Formular nur für Admins, Fehlerliste, Normalisierung;
// erlaubter Embed-Host (lokaler Stub, kein Netz) lädt in HTML- und Markdown-Notiz ohne CSP-Verletzung; JS-Prüfung exakt (P17).
import { execFileSync } from 'node:child_process';
import { runSpec } from './lib/harness.mjs';
import { apiClient } from './lib/api.mjs';
import { ncContainer } from './lib/docker.mjs';

const RUN = `${process.argv[3]}${Date.now().toString(36)}`;
const HOST = 'embed.nn-test.example';
const BAD = ['ftp://x', 'a.com:8080', '*.a.com', '1.2.3.4', '[::1]', 'com', 'localhost'];

await runSpec(import.meta.url, async (ctx, page, context, browser) => {
	const occ = (...a) => execFileSync('docker', ['exec', '-u', 'www-data', ncContainer(ctx.base), 'php', 'occ', ...a], { encoding: 'utf8' }).trim();
	// fehlender Schlüssel: occ config:app:get endet mit 1
	const stored = () => { try { return occ('config:app:get', 'nextnotes', 'allowed_iframe_domains'); } catch { return ''; } };
	const api = apiClient(ctx, page.request);
	// Stub-Host statt Netz: jede Anfrage an HOST bzw. Subdomains beantwortet der Test selbst
	const stub = (c) => c.route(/^https:\/\/([a-z0-9-]+\.)*embed\.nn-test\.example\//, (route) => route.fulfill({
		status: 200, contentType: 'text/html', body: '<!doctype html><title>stub</title><p id="stub">stub</p>' }));
	await stub(context);

	// A. Nicht-Admin: Abschnitt nicht sichtbar, POST → 403
	{
		const m = ctx.mark();
		const off = ctx.allow('status', /^403 GET .*\/settings\/admin\/nextnotes$/, 'erwartet: Verwaltung für Nicht-Admin gesperrt');
		const offc = ctx.allow('console', /status of 403 .*\/settings\/admin\/nextnotes$/, 'erwartet: Browser meldet 403 der Verwaltung');
		await page.goto(`${ctx.base}/index.php/settings/admin/nextnotes`, { waitUntil: 'networkidle' });
		ctx.ok(!(await page.$('#nextnotes-admin')), 'A: Formular für Nicht-Admin nicht vorhanden');
		const r = await page.request.post(`${ctx.base}/index.php/apps/nextnotes/admin/settings`, {
			headers: { Authorization: `Basic ${Buffer.from(`${process.env.NN_USER}:${process.env.NN_PASS}`).toString('base64')}`, 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' },
			data: JSON.stringify({ allowed_iframe_domains: [HOST] }), failOnStatusCode: false });
		ctx.ok(r.status() === 403, `A: Nicht-Admin POST → 403 (HTTP ${r.status()})`);
		off(); offc();
		ctx.assertClean('A Nicht-Admin', m);
	}

	// B. Admin: Fehlerliste, nichts gespeichert; dann gültige Liste normalisiert
	{
		const c2 = await browser.newContext({ viewport: { width: 1400, height: 900 }, locale: 'de-DE' });
		await ctx.attach(c2);
		const p2 = await c2.newPage();
		await ctx.login(p2, 'admin', 'admin');
		const m = ctx.mark();
		await p2.goto(`${ctx.base}/index.php/settings/admin/nextnotes`, { waitUntil: 'networkidle' });
		ctx.ok(!!(await p2.$('#nextnotes-admin')), 'B: Formular in der NC-Verwaltung sichtbar');
		const hint = await p2.locator('#nextnotes-admin').innerText();
		ctx.ok(/youtube-nocookie\.com/.test(hint) && /1 GiB/.test(hint), 'B: Hinweise (youtube-nocookie, 1 GiB) vorhanden');
		ctx.ok(/(dauerhaft entfernt|removed permanently)/.test(hint), 'B: Hinweis Sanitizer entfernt nicht gelistete iframes dauerhaft');
		const before = stored();
		await p2.fill('#nextnotes-embed-domains', ['vimeo.com', ...BAD].join('\n'));
		const r1 = p2.waitForResponse((r) => r.url().endsWith('/admin/settings'));
		const off = ctx.allow('status', /^400 POST .*\/apps\/nextnotes\/admin\/settings$/, 'erwartet: ungültige Einträge');
		const off2 = ctx.allow('console', /status of 400 .*\/admin\/settings/, 'erwartet: Browser meldet 400');
		await p2.click('#nextnotes-admin-save');
		ctx.ok((await r1).status() === 400, 'B: ungültige Einträge → 400');
		await p2.waitForFunction(() => document.querySelectorAll('#nextnotes-admin-errors li').length > 0, null, { timeout: 5000 }).catch(() => {});
		const errs = await p2.$$eval('#nextnotes-admin-errors li', (l) => l.map((e) => e.textContent));
		ctx.ok(errs.length === 7 && BAD.every((b, i) => errs[i].startsWith(`${b}: `)), `B: Liste nennt alle sieben (${errs.length})`, errs.join(' | '));
		ctx.ok(stored() === before, 'B: gespeicherte Liste unverändert');
		off(); off2();
		await p2.fill('#nextnotes-embed-domains', `HTTPS://${HOST.toUpperCase()}/embed/x\nyoutube.com.`);
		const r2 = p2.waitForResponse((r) => r.url().endsWith('/admin/settings'));
		await p2.click('#nextnotes-admin-save');
		ctx.ok((await r2).status() === 200, 'B: gültige Liste → 200');
		await p2.waitForTimeout(300);
		ctx.ok(await p2.inputValue('#nextnotes-embed-domains') === `${HOST}\nyoutube.com`, 'B: Feld zeigt normalisierte Liste', await p2.inputValue('#nextnotes-embed-domains'));
		ctx.ok(JSON.parse(stored() || '[]').join(',') === `${HOST},youtube.com`, 'B: gespeichert normalisiert');
		await ctx.screenshot(p2, 'form');
		ctx.assertClean('B Admin', m);
		await c2.close();
	}

	// C. B41: gelisteter Host lädt (HTML- und Markdown-Notiz), Subdomain nicht; 0 CSP
	try {
		await page.waitForTimeout(4000); // AppConfig-Lokalcache des Webservers
		const WS = await api.workspace(`Embed ${RUN}`);
		const src = `https://${HOST}/v/${RUN}`;
		const html = await api.note(WS, `Embed ${RUN}`, null, `<p>Video</p><iframe src="${src}"></iframe><iframe src="https://sub.${HOST}/x"></iframe>`);
		const saved = (await api.must('GET', `/notes/${html}`)).note.content;
		ctx.ok(saved.includes(src) && !saved.includes(`sub.${HOST}`), 'C: Sanitizer behält gelisteten Host, entfernt Subdomain', saved);
		const md = (await api.must('POST', '/notes', { heading: `MD ${RUN}`, type: 'markdown', workspace: WS, content: `Text\n\n<iframe src="${src}"></iframe>\n` })).note.id;
		for (const [label, id] of [['HTML', html], ['Markdown', md]]) {
			const m = ctx.mark();
			const resp = await page.goto(ctx.appUrl(`index.php?workspace=${encodeURIComponent(WS)}&note=${id}`), { waitUntil: 'networkidle' });
			const csp = resp.headers()['content-security-policy'] ?? '';
			const fs = (csp.match(/(?:^|;)\s*frame-src ([^;]*)/)?.[1] ?? '').trim().split(/\s+/);
			ctx.ok(fs.includes(`https://${HOST}`) && !fs.some((s) => s.includes('*')), `C ${label}: frame-src enthält https://${HOST}, kein *`, fs.join(' '));
			let stubText = '';
			for (let i = 0; i < 20 && stubText !== 'stub'; i++) {
				const frame = page.frames().find((f) => f.url().startsWith(`https://${HOST}/`));
				stubText = frame ? await frame.locator('#stub').innerText({ timeout: 500 }).catch(() => '') : '';
				if (stubText !== 'stub') await page.waitForTimeout(500);
			}
			ctx.ok(stubText === 'stub', `C ${label}: iframe vom Stub-Host geladen`);
			await ctx.screenshot(page, `embed-${label.toLowerCase()}`);
			ctx.assertClean(`C ${label}`, m);
		}
		// P17: JS-Prüfungen exakt wie Server und CSP
		const js = await page.evaluate((h) => ({
			md: [_mdIsAllowedIframeSrc(`https://${h}/x`), _mdIsAllowedIframeSrc(`https://sub.${h}/x`), _mdIsAllowedIframeSrc('https://www.youtube.com/x'), _mdIsAllowedIframeSrc('https://youtube.com/x')],
			paste: [isTrustedEmbedSrc(`https://${h}/x`), isTrustedEmbedSrc(`https://sub.${h}/x`), isTrustedEmbedSrc('https://www.youtube.com/x')],
		}), HOST);
		ctx.ok(JSON.stringify(js.md) === '[true,false,false,true]', `C: markdown-parser.js exakt (P17) ${JSON.stringify(js.md)}`);
		ctx.ok(JSON.stringify(js.paste) === '[true,false,false]', `C: Einfügen exakt nach Seitenliste (P17) ${JSON.stringify(js.paste)}`);
	} finally {
		occ('config:app:delete', 'nextnotes', 'allowed_iframe_domains');
	}
});
