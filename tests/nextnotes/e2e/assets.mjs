// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// M3a1 (Plan M3 r2 §2 Done 2–4, SPEC §4.3, §4.6, §13 M3 Krit. 2/3): Asset-Laden, Hell/Dunkel, Theme-Wechsel, i18n de/en,
// KaTeX-Fonts, Adapter (fetch/XHR/window.open + requesttoken), 401-Reload mit Schleifenschutz.
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { runSpec, REPO_ROOT, APP_SEGMENT } from './lib/harness.mjs';

// Probeschlüssel aus den Upstream-Wörterbüchern: erster Text ohne Platzhalter, der sich in de und en unterscheidet
function pickI18nKey() {
	const de = JSON.parse(readFileSync(join(REPO_ROOT, 'src/i18n/de.json'), 'utf8'));
	const en = JSON.parse(readFileSync(join(REPO_ROOT, 'src/i18n/en.json'), 'utf8'));
	const walk = (d, e, prefix) => {
		for (const k of Object.keys(e)) {
			const key = prefix ? `${prefix}.${k}` : k;
			if (typeof e[k] === 'object' && e[k] && typeof d?.[k] === 'object') {
				const r = walk(d[k], e[k], key);
				if (r) return r;
			} else if (typeof e[k] === 'string' && typeof d?.[k] === 'string' && d[k] !== e[k] && !/[{}%<]/.test(e[k] + d[k]) && e[k].length > 3) {
				return { key, de: d[k], en: e[k] };
			}
		}
		return null;
	};
	return walk(de, en, '');
}

function luminance(rgb) {
	const m = rgb.match(/[\d.]+/g)?.map(Number) ?? [0, 0, 0];
	return (0.2126 * m[0] + 0.7152 * m[1] + 0.0722 * m[2]) / 255;
}

async function themeState(page) {
	return page.evaluate(() => {
		const probe = document.createElement('div');
		probe.style.backgroundColor = 'var(--color-main-background)';
		document.body.append(probe);
		const expected = getComputedStyle(probe).backgroundColor;
		probe.remove();
		const app = document.querySelector('#app-content');
		return {
			dataTheme: document.documentElement.getAttribute('data-theme'),
			ready: document.documentElement.classList.contains('nextnotes-ready'),
			bodyThemes: document.body.getAttribute('data-themes'),
			expected,
			actual: app ? getComputedStyle(app).backgroundColor : null,
			visibility: app ? getComputedStyle(app).visibility : null,
		};
	});
}

async function setNcLanguage(ctx, page, lang) {
	const auth = Buffer.from(`${process.env.NN_USER}:${process.env.NN_PASS}`).toString('base64');
	const resp = await page.request.put(`${ctx.base}/ocs/v2.php/cloud/users/${encodeURIComponent(process.env.NN_USER)}?format=json`, {
		headers: { 'OCS-APIRequest': 'true', Authorization: `Basic ${auth}` },
		form: { key: 'language', value: lang },
	});
	return resp.status();
}

await runSpec(import.meta.url, async (ctx, page, context) => {
	const theme = ctx.theme;
	const other = theme === 'light' ? 'dark' : 'light';

	// 1. Seitenaufbau
	let m = ctx.mark();
	const resp = await page.goto(ctx.appUrl(''), { waitUntil: 'networkidle' });
	ctx.ok(resp?.status() === 200, 'App-Seite angemeldet HTTP 200', `HTTP ${resp?.status()}`);
	ctx.ok(await page.locator('#nextnotes-root').count() === 1, '#nextnotes-root vorhanden');
	const resp2 = await page.goto(ctx.appUrl('index.php'), { waitUntil: 'networkidle' });
	ctx.ok(resp2?.status() === 200, 'index.php HTTP 200', `HTTP ${resp2?.status()}`);
	await page.waitForTimeout(500);
	ctx.assertClean('Seitenaufbau', m);
	await ctx.selfTest(page);

	// 2. Alle eingebundenen App-Skripte/Stylesheets geladen (200), Reihenfolge SPEC §1.4
	const assets = await page.evaluate(() => [
		...[...document.querySelectorAll('script[src]')].map((s) => ({ kind: 'js', url: s.src, defer: s.defer })),
		...[...document.querySelectorAll('link[rel="stylesheet"][href]')].map((l) => ({ kind: 'css', url: l.href })),
	].filter((a) => a.url.includes('/nextnotes/')));
	const statusOf = (url) => ctx.responses.filter((r) => r.url === url).map((r) => r.status);
	const bad = assets.filter((a) => !statusOf(a.url).includes(200) && !statusOf(a.url).includes(304));
	ctx.ok(assets.length >= 15 && bad.length === 0, `App-Assets geladen (${assets.filter((a) => a.kind === 'js').length} JS, ${assets.filter((a) => a.kind === 'css').length} CSS)`, bad.map((a) => `${a.url} ${statusOf(a.url)}`).join(' | '));
	ctx.ok(assets.filter((a) => a.kind === 'js').every((a) => a.defer), 'alle App-Skripte defer');
	const js = assets.filter((a) => a.kind === 'js').map((a) => new URL(a.url).pathname);
	const idx = (re) => js.findIndex((p) => re.test(p));
	const order = [/nextnotes-i18n-de\.js$/, /nextnotes-bootstrap\.js$/, /nextnotes-head\.js$/, /codemirror/, /lazy-libs\.js$/, /highlight\.min\.js$/, /nextnotes-app\.js$/, /nextnotes-late\.js$/].map(idx);
	ctx.ok(order.every((v, i) => v >= 0 && (i === 0 || v > order[i - 1])), 'Skriptreihenfolge i18n → bootstrap → head → CodeMirror/lazy-libs/highlight → app → late', JSON.stringify(order));
	const css = assets.filter((a) => a.kind === 'css').map((a) => new URL(a.url).pathname.split('/').pop().replace(/\.css$/, ''));
	ctx.ok(css.slice(-2).join(',') === 'nextnotes-tokens,nextnotes-layout' && css.includes('nextnotes-core') && css.includes('nextnotes-dark'), 'CSS core … dark, tokens + layout zuletzt', css.join(','));
	ctx.info(`Asset-Pfad: ${new URL(assets[0]?.url ?? ctx.base).pathname.replace(/[^/]*$/, '')}`);
	ctx.ok(!assets.some((a) => /theme-init|session-guard|pwa|offline|login-page|two-factor/.test(a.url)), 'keine gestrichenen Skripte eingebunden');

	// 3. Hell/Dunkel (Krit. 2)
	let st = await themeState(page);
	ctx.ok(st.ready, 'html.nextnotes-ready gesetzt');
	ctx.ok(st.visibility === 'visible', '#app-content sichtbar', st.visibility);
	ctx.ok(st.dataTheme === theme, `html[data-theme] = ${theme}`, `${st.dataTheme} (body data-themes=${st.bodyThemes})`);
	ctx.ok(st.actual === st.expected, '#app-content-Hintergrund = --color-main-background', `${st.actual} vs ${st.expected}`);
	ctx.ok(theme === 'dark' ? luminance(st.actual ?? '') < 0.4 : luminance(st.actual ?? '') > 0.6, `Hintergrund passt zu ${theme}`, st.actual);
	await ctx.screenshot(page, 'index');

	// 4. Theme-Wechsel ohne Reload (Body-Attribute wie NC-Theming)
	let navs = 0;
	const onNav = (f) => { if (f === page.mainFrame()) navs++; };
	page.on('framenavigated', onNav);
	const switchTo = (to) => page.evaluate((t) => {
		for (const a of [...document.body.attributes]) if (a.name.startsWith('data-theme-')) document.body.removeAttribute(a.name);
		document.body.setAttribute('data-themes', t);
		document.body.setAttribute(`data-theme-${t}`, '');
	}, to);
	await switchTo(other);
	await page.waitForFunction((t) => document.documentElement.getAttribute('data-theme') === t, other, { timeout: 3000 }).catch(() => {});
	st = await themeState(page);
	ctx.ok(st.dataTheme === other, `Theme-Wechsel → ${other} ohne Reload übernommen`, String(st.dataTheme));
	ctx.ok(st.actual === st.expected, `nach Wechsel: Hintergrund = --color-main-background`, `${st.actual} vs ${st.expected}`);
	await ctx.screenshot(page, `switched-to-${other}`);
	await switchTo(theme);
	await page.waitForFunction((t) => document.documentElement.getAttribute('data-theme') === t, theme, { timeout: 3000 }).catch(() => {});
	ctx.ok(await page.evaluate(() => document.documentElement.getAttribute('data-theme')) === theme, `Rückwechsel → ${theme}`);
	page.off('framenavigated', onNav);
	ctx.ok(navs === 0, 'Theme-Wechsel ohne Navigation', `${navs} Navigationen`);

	// 4b. Schrift folgt NC (ADR 0016): Upstream-Klasse .noteentry erbt --font-face; Legasthenie-Schrift (OpenDyslexic)
	const fontState = () => page.evaluate(() => {
		const root = document.querySelector('#nextnotes-root');
		const wrap = document.createElement('div');
		wrap.className = 'noteentry';
		wrap.innerHTML = '<p>Probe</p>';
		root.append(wrap);
		const p = wrap.querySelector('p');
		const r = {
			fontFace: getComputedStyle(document.body).getPropertyValue('--font-face').trim(),
			p: getComputedStyle(p).fontFamily,
			root: getComputedStyle(root).fontFamily,
		};
		wrap.remove();
		return r;
	});
	const norm = (f) => f.replace(/["']/g, '').replace(/\s*,\s*/g, ',').trim();
	let fs = await fontState();
	ctx.ok(fs.fontFace !== '' && norm(fs.p).startsWith(norm(fs.fontFace).split(',')[0]), '.noteentry p: font-family beginnt mit --font-face' + ` (${fs.p.slice(0, 40)})`, `p="${fs.p}" --font-face="${fs.fontFace}"`);
	ctx.ok(!/\bInter\b/.test(fs.p) || /\bInter\b/.test(fs.fontFace), '.noteentry p: kein Inter außer über --font-face', fs.p);
	ctx.ok(!ctx.responses.some((r) => /webfonts\/|Inter[^/]*\.woff/i.test(r.url)), 'keine Inter-Webfont-Requests');
	const ocsTheme = (method, id) => page.request.fetch(`${ctx.base}/ocs/v2.php/apps/theming/api/v1/theme/${id}/enable?format=json`, {
		method, headers: { 'OCS-APIRequest': 'true', Authorization: `Basic ${Buffer.from(`${process.env.NN_USER}:${process.env.NN_PASS}`).toString('base64')}` },
	}).then((r) => r.status());
	try {
		const s = await ocsTheme('PUT', 'opendyslexic');
		ctx.ok(s === 200, 'Theme opendyslexic aktiviert (OCS)', `HTTP ${s}`);
		m = ctx.mark();
		await page.goto(ctx.appUrl('index.php'), { waitUntil: 'networkidle' });
		fs = await fontState();
		const themesAttr = await page.evaluate(() => document.body.getAttribute('data-themes'));
		ctx.ok(/OpenDyslexic/i.test(fs.root) && /OpenDyslexic/i.test(fs.p), `OpenDyslexic in #nextnotes-root und .noteentry p (${fs.p.slice(0, 40)})`, `themes=${themesAttr} root="${fs.root}" p="${fs.p}"`);
		ctx.ok(await page.evaluate(() => document.documentElement.getAttribute('data-theme')) === theme, `mit opendyslexic weiterhin data-theme=${theme}`);
		await ctx.screenshot(page, 'opendyslexic');
		ctx.assertClean('Seitenaufbau opendyslexic', m);
	} finally {
		const s = await page.request.fetch(`${ctx.base}/ocs/v2.php/apps/theming/api/v1/theme/opendyslexic?format=json`, {
			method: 'DELETE', headers: { 'OCS-APIRequest': 'true', Authorization: `Basic ${Buffer.from(`${process.env.NN_USER}:${process.env.NN_PASS}`).toString('base64')}` },
		}).then((r) => r.status());
		ctx.ok(s === 200, 'Theme opendyslexic deaktiviert', `HTTP ${s}`);
		await page.goto(ctx.appUrl('index.php'), { waitUntil: 'networkidle' });
	}

	// 5. i18n Deutsch (NC-Sprache de, gesetzt von run-e2e.sh)
	const probe = pickI18nKey();
	ctx.ok(!!probe, 'i18n-Probeschlüssel gefunden', JSON.stringify(probe));
	const deText = await page.evaluate((k) => (typeof window.t === 'function' ? window.t(k) : null), probe.key);
	ctx.ok(deText === probe.de, `t('${probe.key}') deutsch`, `${deText} (erwartet ${probe.de})`);

	// 6. KaTeX: Probeformel rendern, Fonts müssen laden (200)
	m = ctx.mark();
	const katex = await page.evaluate(async () => {
		await window.poznoteEnsureKatex();
		const el = document.createElement('div');
		el.id = 'nn-katex-probe';
		document.querySelector('#nextnotes-root').append(el);
		window.katex.render('\\int_0^1 x^2\\,dx = \\frac{1}{3} \\mathbb{R} \\mathcal{L}', el);
		await document.fonts.ready;
		await new Promise((r) => setTimeout(r, 300));
		return { katex: !!el.querySelector('.katex'), main: document.fonts.check('16px KaTeX_Main') };
	});
	await page.waitForTimeout(500);
	const fontResp = ctx.responses.slice(m.r).filter((r) => /katex.*\.(woff2?|ttf)(\?|$)/i.test(r.url));
	const katexJs = ctx.responses.slice(m.r).filter((r) => /katex\.min\.js/.test(r.url));
	ctx.ok(katex.katex, 'KaTeX-Formel gerendert (.katex)');
	ctx.ok(katexJs.length === 1 && katexJs[0].status === 200 && katexJs[0].url.includes('/nextnotes/js/vendor/katex/'), 'katex.min.js lazy vom App-Pfad', katexJs.map((r) => `${r.status} ${r.url}`).join(' | '));
	ctx.ok(fontResp.length > 0 && fontResp.every((r) => r.status === 200 && r.url.includes('/nextnotes/css/vendor/katex/fonts/')), `KaTeX-Fonts geladen (${fontResp.length})`, fontResp.map((r) => `${r.status} ${r.url}`).join(' | '));
	ctx.ok(katex.main, 'Font KaTeX_Main verfügbar');
	await ctx.screenshot(page, 'katex');
	ctx.assertClean('KaTeX', m);

	// 7. Adapter: fetch/XHR mit und ohne führenden Slash → App-Pfad + gültiger requesttoken (200 statt 412)
	m = ctx.mark();
	const headToken = await page.evaluate(() => document.head.dataset.requesttoken);
	const ad = await page.evaluate(async () => {
		const out = {};
		out.fetchAbs = (await fetch('/api/v1/notes')).status;
		out.fetchRel = (await fetch('api/v1/notes')).status;
		out.xhr = await new Promise((res) => {
			const x = new XMLHttpRequest();
			x.open('GET', '/api/v1/notes');
			x.onloadend = () => res(x.status);
			x.send();
		});
		out.xhrRel = await new Promise((res) => {
			const x = new XMLHttpRequest();
			x.open('GET', 'api/v1/notes');
			x.onloadend = () => res(x.status);
			x.send();
		});
		return out;
	});
	await page.waitForTimeout(300);
	const noteReqs = ctx.requests.slice(m.q).filter((r) => /\/api\/v1\/notes$/.test(new URL(r.url).pathname));
	ctx.ok(Object.values(ad).every((s) => s === 200), 'fetch/XHR /api/v1/notes und api/v1/notes → 200', JSON.stringify(ad));
	ctx.ok(noteReqs.length === 4 && noteReqs.every((r) => new URL(r.url).pathname.includes(`${APP_SEGMENT}api/v1/notes`)), '4 Requests auf App-Pfad', noteReqs.map((r) => r.url).join(' | '));
	// Header prüfen über Playwright-Request-Objekte
	const hdrs = await Promise.all(ctx.responses.slice(m.r).filter((r) => r.url.includes(`${APP_SEGMENT}api/v1/notes`)).map((r) => r.resp.request().allHeaders()));
	const xrw = (h) => (h['x-requested-with'] ?? '').split(/,\s*/);
	if (hdrs.some((h) => xrw(h).length > 1)) ctx.info(`X-Requested-With mehrfach gesetzt (XHR: Adapter + NC-Kern): ${hdrs.map((h) => h['x-requested-with']).join(' | ')}`);
	ctx.ok(hdrs.length === 4 && hdrs.every((h) => h.requesttoken && h.requesttoken === headToken && xrw(h).every((v) => v === 'XMLHttpRequest')), 'Header requesttoken (= head data-requesttoken) und X-Requested-With', hdrs.map((h) => `${(h.requesttoken ?? '-').slice(0, 8)}…/${h['x-requested-with']}`).join(' '));

	// window.open → App-Pfad (Ziel im Test abgefangen, sonst NC-CSRF bei Top-Level-GET)
	await context.route('**/api/v1/nn-open-probe*', (route) => route.fulfill({ status: 200, contentType: 'text/plain', body: 'ok' }));
	const [popup] = await Promise.all([
		context.waitForEvent('page', { timeout: 10000 }),
		page.evaluate(() => { window.open('/api/v1/nn-open-probe?x=1', '_blank'); }),
	]);
	await popup.waitForLoadState().catch(() => {});
	const popupUrl = popup.url();
	ctx.ok(new URL(popupUrl).pathname.includes(`${APP_SEGMENT}api/v1/nn-open-probe`) && popupUrl.includes('x=1'), 'window.open(/api/v1/…) → App-Pfad', popupUrl);
	await popup.close();
	ctx.assertClean('Adapter', m);

	// 8. 401: genau ein Reload; zweiter 401 < 30 s → kein Reload, Fehlermeldung; nach > 30 s wieder Reload
	const allow401 = ctx.allow('status', /^401 .*nn-401-probe/, '401 im Test simuliert');
	const allow401c = ctx.allow('console', /401|Unauthorized/, 'Browser loggt simulierte 401 als Ladefehler');
	await context.route('**/api/v1/nn-401-probe*', (route) => route.fulfill({ status: 401, contentType: 'application/json', body: '{"message":"Current user is not logged in"}' }));
	let loads = 0;
	const onLoad = () => { loads++; };
	page.on('load', onLoad);
	await page.evaluate(() => { fetch('/api/v1/nn-401-probe'); });
	await page.waitForTimeout(4000);
	ctx.ok(loads === 1, '1. 401 → genau ein Reload', `${loads} Ladevorgänge`);
	await page.waitForLoadState('networkidle').catch(() => {});
	loads = 0;
	await page.evaluate(() => { window.__nnNotified = []; window.showNotificationPopup = (msg, type) => { window.__nnNotified.push([msg, type]); }; });
	await page.evaluate(() => { fetch('/api/v1/nn-401-probe'); });
	await page.waitForTimeout(4000);
	ctx.ok(loads === 0, '2. 401 innerhalb 30 s → kein Reload', `${loads} Ladevorgänge`);
	const note = await page.evaluate(() => window.__nnNotified);
	ctx.ok(Array.isArray(note) && note.length === 1 && note[0][1] === 'error', '2. 401 → Fehlermeldung (showNotificationPopup)', JSON.stringify(note));
	// XHR-Pfad ebenfalls geschützt (weiterhin im 30-s-Fenster)
	await page.evaluate(() => new Promise((res) => { const x = new XMLHttpRequest(); x.open('GET', '/api/v1/nn-401-probe'); x.onloadend = res; x.send(); }));
	await page.waitForTimeout(2000);
	ctx.ok(loads === 0, 'XHR-401 innerhalb 30 s → kein Reload', `${loads} Ladevorgänge`);
	// Fenster abgelaufen (Marker 31 s alt) → wieder genau ein Reload
	const keys = await page.evaluate(() => Object.keys(sessionStorage));
	ctx.info(`sessionStorage-Schlüssel: ${keys.join(',')}`);
	await page.evaluate((ks) => { for (const k of ks) { const v = Number(sessionStorage.getItem(k)); if (v > 1e12 && v <= Date.now()) sessionStorage.setItem(k, String(Date.now() - 31000)); } }, keys);
	await page.evaluate(() => { fetch('/api/v1/nn-401-probe'); });
	await page.waitForTimeout(4000);
	ctx.ok(loads === 1, '401 nach > 30 s → wieder ein Reload', `${loads} Ladevorgänge`);
	page.off('load', onLoad);
	await context.unroute('**/api/v1/nn-401-probe*');
	await page.waitForLoadState('networkidle').catch(() => {});
	allow401(); allow401c();

	// 9. i18n Englisch: NC-Sprache en (OCS, eigenes Konto), danach zurück auf de
	try {
		const s = await setNcLanguage(ctx, page, 'en');
		ctx.ok(s === 200, 'NC-Sprache → en (OCS)', `HTTP ${s}`);
		m = ctx.mark();
		await page.goto(ctx.appUrl('index.php'), { waitUntil: 'networkidle' });
		const enText = await page.evaluate((k) => window.t(k), probe.key);
		ctx.ok(enText === probe.en, `t('${probe.key}') englisch`, `${enText} (erwartet ${probe.en})`);
		const enScript = await page.evaluate(() => [...document.querySelectorAll('script[src]')].some((s) => /nextnotes-i18n-en\.js/.test(s.src)));
		ctx.ok(enScript, 'Wörterbuch nextnotes-i18n-en.js eingebunden');
		ctx.assertClean('Seitenaufbau en', m);
	} finally {
		const s = await setNcLanguage(ctx, page, 'de');
		ctx.ok(s === 200, 'NC-Sprache zurück → de', `HTTP ${s}`);
	}

	// Gesamtlauf
	ctx.assertClean('gesamter Lauf');
});
