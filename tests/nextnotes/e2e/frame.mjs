// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// M3a2 (Plan M3 §2 Done M3a2 1, 2, 5; SPEC §4.3, §4.4, §4.6, §11.2 B27–B29): Hauptansicht-Rahmen im NC-Layout, Navigation
// (Ordnerklick, Workspace-Wechsel), ?note=/?workspace= inkl. Fremdzugriff, GET ohne Seiteneffekt, Abmeldung → Login,
// XHR-Header je einmal, App-CSS trifft keine NC-Oberfläche, NC-Kern-CSS verfälscht keine App-Formularelemente,
// keine durch den Build ungültig gewordenen CSS-Regeln, Hell/Dunkel-Regeln greifen.
import { runSpec } from './lib/harness.mjs';
import { apiClient } from './lib/api.mjs';
import { occ } from './lib/docker.mjs';

const RUN = `${process.argv[3]}${Date.now().toString(36)}`;

function luminance(rgb) {
	const m = rgb.match(/[\d.]+/g)?.map(Number) ?? [0, 0, 0];
	return (0.2126 * m[0] + 0.7152 * m[1] + 0.0722 * m[2]) / 255;
}

// Browser: Regeln aus App-Stylesheets (rekursiv durch @media/@supports), die auf Elemente der NC-Oberfläche passen
async function appRulesOnNcChrome(page) {
	return page.evaluate(() => {
		const chrome = [...document.querySelectorAll('#header, #header *, .v-popper__popper, .v-popper__popper *, .modal-mask, .modal-mask *, .toastify, .toastify *, #body-user > .header-menu__wrapper *')];
		const out = [];
		const walk = (rules, sheet) => {
			for (const r of rules) {
				if (r instanceof CSSStyleRule) {
					let hits;
					try { hits = chrome.filter((el) => el.matches(r.selectorText)); } catch { continue; }
					if (hits.length) {
						const el = hits[0];
						const tag = `${el.tagName.toLowerCase()}${el.id ? `#${el.id}` : ''}${el.className && typeof el.className === 'string' ? `.${el.className.trim().split(/\s+/).slice(0, 2).join('.')}` : ''}`;
						out.push(`${sheet}: ${r.selectorText.slice(0, 140)} → ${hits.length}× (${tag})`);
					}
				} else if (r.cssRules) {
					if (r instanceof CSSMediaRule && !window.matchMedia(r.conditionText ?? r.media.mediaText).matches) continue;
					walk(r.cssRules, sheet);
				}
			}
		};
		for (const s of document.styleSheets) {
			if (!s.href || !/\/nextnotes\/css\//.test(s.href)) continue;
			walk(s.cssRules, s.href.split('/nextnotes/')[1].split('?')[0]);
		}
		return out;
	});
}

// Browser: CSS-Regeln, die erst durch die Spezifitäts-Hebung (ADR 0017) ungültig wurden (Regel-Verlust)
async function droppedRules(page) {
	return page.evaluate(async () => {
		const out = { checked: 0, dropped: [], droppedAlsoUnbumped: 0 };
		const parses = (css) => { const s = new CSSStyleSheet(); try { s.replaceSync(css); } catch { return -1; } return s.cssRules.length; };
		for (const link of document.querySelectorAll('link[rel=stylesheet]')) {
			if (!/\/nextnotes\/css\/(?!vendor)/.test(link.href)) continue;
			const text = await (await window.__nnRawFetch(link.href)).text();
			// Regeln auf oberster Ebene und in @media einzeln prüfen: Selektor + leerer Block
			const sels = [];
			let i = 0; let depth = 0; let start = 0;
			const stack = [];
			while (i < text.length) {
				const c = text[i];
				if (c === '/' && text[i + 1] === '*') { i = text.indexOf('*/', i + 2) + 2; if (i < 2) break; continue; }
				if (c === '"' || c === "'") { i++; while (i < text.length && text[i] !== c) i += text[i] === '\\' ? 2 : 1; i++; continue; }
				if (c === '{') {
					const prelude = text.slice(start, i).trim();
					stack.push(prelude);
					if (!prelude.startsWith('@') && !stack.slice(0, -1).some((p) => /^@(keyframes|font-face)/i.test(p))) sels.push(prelude);
					depth++; start = i + 1;
				} else if (c === '}') { stack.pop(); depth--; start = i + 1; } else if (c === ';') { start = i + 1; }
				i++;
			}
			const file = link.href.split('/nextnotes/')[1].split('?')[0];
			for (const sel of sels) {
				out.checked++;
				if (parses(`${sel}{}`) === 1) continue;
				const unbumped = sel.replaceAll(':not(#nn-0)', '').replaceAll(':is(#nextnotes-root,#nextnotes-root *)', '').replace(/:where\(:not\(:is\(#header[^)]*\) \*\):not\(#header[^)]*\)\)/g, '');
				if (parses(`${unbumped}{}`) !== 1) { out.droppedAlsoUnbumped++; continue; }
				out.dropped.push(`${file}: ${sel.slice(0, 200)}`);
			}
		}
		return out;
	});
}

// Browser: Einfluss des NC-Kern-CSS auf App-Formularelemente (Vergleich mit abgeschaltetem server.css)
async function ncCoreInfluence(page) {
	return page.evaluate(async () => {
		const props = ['background-color', 'background-image', 'min-height', 'height', 'width', 'padding-top', 'padding-left', 'margin-top', 'margin-left',
			'border-top-width', 'border-top-style', 'border-top-color', 'border-radius', 'font-weight', 'font-size', 'line-height', 'color', 'cursor', 'box-shadow', 'outline-style'];
		const els = [...document.querySelectorAll('#nextnotes-root button, #nextnotes-root input:not([type=hidden]), #nextnotes-root select, #nextnotes-root textarea')]
			.filter((e) => e.getClientRects().length > 0);
		const snap = () => els.map((e) => { const cs = getComputedStyle(e); return Object.fromEntries(props.map((p) => [p, cs.getPropertyValue(p)])); });
		if (document.activeElement && document.activeElement !== document.body) document.activeElement.blur();
		const before = snap();
		const core = [...document.styleSheets].filter((s) => s.href && /\/core\/css\/(server|apps|styles|inputs|buttons|global|fixes|mobile|header|icons)/.test(s.href));
		core.forEach((s) => { s.disabled = true; });
		const after = snap();
		core.forEach((s) => { s.disabled = false; });
		// font-size/line-height/width: geerbte Schriftgröße von NC-body (kein Formular-Eingriff) → nur Info
		const soft = ['font-size', 'line-height', 'width'];
		const diffs = [];
		const softDiffs = [];
		els.forEach((e, k) => {
			const name = `${e.tagName.toLowerCase()}${e.id ? `#${e.id}` : ''}.${[...e.classList].slice(0, 2).join('.')}`;
			const d = props.filter((p) => !soft.includes(p) && before[k][p] !== after[k][p]).map((p) => `${p}: ${after[k][p]} → ${before[k][p]}`);
			const sd = soft.filter((p) => before[k][p] !== after[k][p]).map((p) => `${p}: ${after[k][p]} → ${before[k][p]}`);
			if (d.length) diffs.push(`${name}: ${d.join('; ')}`);
			if (sd.length) softDiffs.push(`${name}: ${sd.join('; ')}`);
		});
		return { elements: els.length, coreSheets: core.map((s) => s.href.replace(/^.*\/core\//, 'core/').split('?')[0]), diffs, softDiffs };
	});
}

await runSpec(import.meta.url, async (ctx, page, context, browser) => {
	const api = apiClient(ctx, page.request);
	// NC-Kern (nicht App): Kontaktmenü fragt user_status eines Kontos ohne gesetzten Status ab → 404
	ctx.allow('status', /404 GET .*\/ocs\/v2\.php\/apps\/user_status\/api\/v1\/statuses\//, 'NC-Kern user_status: Konto ohne Status (nicht App)');
	ctx.allow('console', /status of 404 .*\/apps\/user_status\/api\/v1\/statuses\//, 'NC-Kern user_status (Browser-Log)');
	const WS = await api.defaultWorkspace();
	ctx.info(`Standard-Workspace: „${WS}“ (Name nach Sprache beim ersten Zugriff)`);
	ctx.ok(['Notizen', 'Notes'].includes(WS), 'Standard-Workspace vorhanden', WS);
	await api.must('PUT', '/settings/last_opened_workspace', { value: WS }); // Ausgangslage unabhängig von Vorläufen
	occ(ctx.base, 'notification:generate', process.env.NN_USER, `Regel-Scan ${RUN}`); // NC-Benachrichtigung im Kopf für den Regel-Scan
	const WS2 = `Zweit ${RUN}`;
	await api.workspace(WS2);
	const fA = `Ordner ${RUN}`;
	const fDeep = await api.folderPath(WS, `${fA}/Unter ${RUN}/Tief ${RUN}`);
	const fTwin = await api.folder(WS, `Tief ${RUN}`); // gleichnamiger Ordner auf oberster Ebene (B27)
	const nDeep = await api.note(WS, `Tiefe Notiz ${RUN}`, fDeep);
	await api.note(WS, `Wurzelnotiz ${RUN}`);
	await api.note(WS, `Zwilling ${RUN}`, fTwin);
	// > 3 Notizen im Workspace, sonst öffnet Upstream alle Ordner (folders_display.php, ≤ 3 Notizen)
	await api.note(WS, `Füller 1 ${RUN}`);
	await api.note(WS, `Füller 2 ${RUN}`);
	await api.note(WS2, `Zweitnotiz ${RUN}`);

	// XHR-/fetch-Header je API-Request (Adapter §4.3.7)
	const hdrProblems = [];
	let hdrChecked = 0;
	const hdrPending = [];
	context.on('request', (req) => {
		if (!/\/apps\/nextnotes\/(index\.php\/)?api\/v1\//.test(new URL(req.url()).pathname) && !/\/index\.php\/apps\/nextnotes\/api\/v1\//.test(req.url())) return;
		// <img>/<audio> laden Anhänge nativ (Cookie, GET ohne CSRF-Bedarf); Header setzt nur der Adapter für fetch/XHR
		if (['image', 'media'].includes(req.resourceType())) return;
		hdrPending.push(req.headersArray().then((h) => {
			hdrChecked++;
			const tok = h.filter((x) => x.name.toLowerCase() === 'requesttoken');
			const xrw = h.filter((x) => x.name.toLowerCase() === 'x-requested-with');
			const bad = [];
			if (tok.length !== 1 || tok[0].value.includes(',')) bad.push(`requesttoken ${JSON.stringify(tok.map((x) => x.value.slice(0, 12)))}`);
			if (req.resourceType() === 'xhr' && (xrw.length !== 1 || xrw[0].value !== 'XMLHttpRequest')) bad.push(`X-Requested-With ${JSON.stringify(xrw.map((x) => x.value))}`);
			if (bad.length) hdrProblems.push(`${req.method()} ${req.url().replace(/^.*api\/v1\//, '')} [${req.resourceType()}]: ${bad.join(', ')}`);
		}).catch(() => {}));
	});

	// 1. Seite im NC-Rahmen
	let m = ctx.mark();
	for (const [label, url] of [['/apps/nextnotes/', ctx.appUrl('')], ['/apps/nextnotes/index.php', ctx.appUrl('index.php')]]) {
		const r = await page.goto(url, { waitUntil: 'networkidle' });
		ctx.ok(r?.status() === 200, `${label} HTTP 200`, `HTTP ${r?.status()}`);
	}
	await page.waitForTimeout(400);
	const frame = await page.evaluate(({ fA, RUN }) => {
		const vis = (sel) => { const e = document.querySelector(sel); if (!e) return false; const r = e.getBoundingClientRect(); return r.width > 0 && r.height > 0 && getComputedStyle(e).visibility !== 'hidden'; };
		const rc = document.querySelector('#right_col');
		return {
			root: document.querySelectorAll('#nextnotes-root').length,
			ncHeader: vis('#header'),
			ncHeaderTop: document.querySelector('#header')?.getBoundingClientRect().top,
			rootBelowHeader: (document.querySelector('#nextnotes-root')?.getBoundingClientRect().top ?? 0) >= (document.querySelector('#header')?.getBoundingClientRect().bottom ?? 999) - 1,
			iconSidebar: vis('#icon_sidebar'),
			logo: vis('#iconSidebarHomeBtn'),
			leftCol: vis('#left_col'),
			wsTitle: document.querySelector('.workspace-title-text')?.textContent.trim(),
			folder: [...document.querySelectorAll('#left_col .folder-name')].some((e) => e.textContent.trim() === fA),
			rootNote: [...document.querySelectorAll('#left_col .note-title')].some((e) => e.textContent.trim() === `Wurzelnotiz ${RUN}`),
			search: vis('#unified-search'),
			rightPane: vis('#right_pane'),
			rightColChildren: rc ? rc.children.length : -1,
			rightColText: rc ? rc.textContent.trim().slice(0, 80) : null,
			unifiedSearchIds: [...document.querySelectorAll('[id="unified-search"]')].map((e) => `${e.tagName}${e.closest('#nextnotes-root') ? '@app' : '@outside'}`),
			ncSearchInHeader: !!document.querySelector('#header .unified-search-menu, #header [class*="unified-search"], #header .header-menu'),
			ready: document.documentElement.classList.contains('nextnotes-ready'),
		};
	}, { fA, RUN });
	ctx.info(JSON.stringify(frame));
	ctx.ok(frame.root === 1, 'genau ein #nextnotes-root');
	ctx.ok(frame.ncHeader && frame.rootBelowHeader, 'NC-Kopf sichtbar, App darunter');
	ctx.ok(frame.iconSidebar && frame.logo, 'Icon-Leiste mit Logo sichtbar');
	ctx.ok(frame.leftCol && frame.wsTitle === WS, `Workspace-Auswahl zeigt „${WS}“`, frame.wsTitle);
	ctx.ok(frame.folder, 'Ordnerbaum enthält angelegten Ordner');
	ctx.ok(frame.rootNote, 'Notizliste enthält angelegte Notiz');
	ctx.ok(frame.search, 'App-Suchfeld sichtbar');
	ctx.ok(frame.rightPane && frame.rightColChildren === 0, 'rechte Spalte leer', `${frame.rightColChildren} Kinder: ${frame.rightColText}`);
	ctx.ok(frame.unifiedSearchIds.length === 1 && frame.unifiedSearchIds[0] === 'INPUT@app', 'P8: genau ein #unified-search, liegt in #nextnotes-root', frame.unifiedSearchIds.join(','));
	ctx.ok(frame.ready, 'html.nextnotes-ready gesetzt');
	await ctx.screenshot(page, 'index');
	ctx.assertClean('Seitenaufbau', m);

	// 1b. Theme: Hintergrund, Dunkel-Regeln greifen (:where(html[data-theme=dark]) aus dem Build)
	const th = await page.evaluate(() => {
		const probe = document.createElement('div');
		probe.style.backgroundColor = 'var(--color-main-background)';
		document.body.append(probe);
		const expected = getComputedStyle(probe).backgroundColor;
		probe.remove();
		const q = (s, p) => { const e = document.querySelector(s); return e ? getComputedStyle(e).getPropertyValue(p) : null; };
		return {
			dataTheme: document.documentElement.getAttribute('data-theme'), expected,
			appBg: q('#nextnotes-root > #right_pane', 'background-color'), // UI-nav: #app-content transparent, Inhaltsfläche deckend
			 leftBg: q('#left_col', 'background-color'),
			noteColor: q('#left_col .note-title', 'color'), folderColor: q('#left_col .folder-name', 'color'),
			inputBg: q('#unified-search', 'background-color'), inputColor: q('#unified-search', 'color'),
		};
	});
	ctx.info(`Theme: ${JSON.stringify(th)}`);
	ctx.ok(th.dataTheme === ctx.theme, `html[data-theme]=${ctx.theme}`, th.dataTheme);
	ctx.ok(th.appBg === th.expected, 'Inhaltsfläche #right_pane = --color-main-background', `${th.appBg} vs ${th.expected}`);
	const dark = ctx.theme === 'dark';
	for (const [k, v] of [['leftBg', th.leftBg], ['inputBg', th.inputBg]]) {
		if (/rgba\(0, 0, 0, 0\)|transparent/.test(v)) { ctx.info(`${k} transparent`); continue; }
		ctx.ok(dark ? luminance(v) < 0.35 : luminance(v) > 0.65, `${k} passt zum Theme (${ctx.theme})`, v);
	}
	for (const [k, v] of [['noteColor', th.noteColor], ['folderColor', th.folderColor], ['inputColor', th.inputColor]]) {
		ctx.ok(dark ? luminance(v) > 0.55 : luminance(v) < 0.45, `${k} lesbar (${ctx.theme})`, v);
	}

	// 2. CSS: Regelverlust durch Build, App-Regeln auf NC-Oberfläche, NC-Kern auf App-Formularen
	const dr = await droppedRules(page);
	ctx.info(`CSS-Selektoren geprüft: ${dr.checked}, schon ohne Hebung ungültig: ${dr.droppedAlsoUnbumped}`);
	ctx.ok(dr.dropped.length === 0, 'keine CSS-Regel durch Spezifitäts-Hebung ungültig', `${dr.dropped.length}: ${dr.dropped.slice(0, 6).join(' | ')}`);
	// Scrollbalken-Regeln (Speccritic-Befund M3a2: fielen durch Zusatz hinter Pseudo-Element weg) im CSSOM vorhanden
	const sb = await page.evaluate(() => {
		const sels = [];
		const walk = (rules) => { for (const r of rules) { if (r instanceof CSSStyleRule) sels.push(r.selectorText); else if (r.cssRules) walk(r.cssRules); } };
		for (const st of document.styleSheets) if (st.href && /\/nextnotes\/css\//.test(st.href)) walk(st.cssRules);
		const sb = sels.filter((x) => x.includes('::-webkit-scrollbar'));
		const has = (re) => sb.some((x) => re.test(x));
		return {
			total: sb.length,
			codeBlockHover: has(/\.code-block(:not\(#nn-0\))?::-webkit-scrollbar-thumb:hover/),
			codeBlockHorizontal: has(/pre\.code-block(:not\(#nn-0\))?::-webkit-scrollbar-track:horizontal/),
			rightColHover: has(/#right_col(:not\(#nn-0\))?::-webkit-scrollbar-thumb:hover/),
			kanbanHover: has(/\.kanban-board(:not\(#nn-0\))?::-webkit-scrollbar-thumb:hover/),
			kanbanTrack: has(/\.kanban-board(:not\(#nn-0\))?::-webkit-scrollbar-track/),
			darkCodeHover: has(/html\[data-theme="?dark"?\] [^,]*\.code-block[^,]*::-webkit-scrollbar-thumb:hover/),
			afterPseudo: sb.filter((x) => /::-webkit-scrollbar[a-z-]*(:[a-z-]+)*:(not\(#nn-0\)|is\(#nextnotes-root|where\(:not\(:is\(#header)/.test(x)).length,
			sample: sb.filter((x) => /code-block|right_col|kanban/.test(x)).slice(0, 4),
		};
	});
	ctx.info(`Scrollbalken-Regeln im CSSOM: ${JSON.stringify(sb)}`);
	for (const k of ['codeBlockHover', 'codeBlockHorizontal', 'rightColHover', 'kanbanHover', 'kanbanTrack', 'darkCodeHover']) ctx.ok(sb[k], `Scrollbalken-Regel vorhanden: ${k}`);
	ctx.ok(sb.afterPseudo === 0, 'kein Spezifitäts-Zusatz hinter Pseudo-Element im CSSOM', `${sb.afterPseudo}`);
	let hits = await appRulesOnNcChrome(page);
	ctx.ok(hits.length === 0, 'Regel-Scan: 0 App-Regeln auf NC-Kopf', hits.slice(0, 8).join(' | '));
	// NC-Benutzermenü und weitere Kopf-Popover öffnen
	const triggers = await page.locator('#header .header-menu__trigger, #header #user-menu button, #header .app-menu-entry__link, #header button[aria-haspopup]').all();
	ctx.info(`NC-Kopf-Auslöser: ${triggers.length}`);
	let opened = 0;
	for (const t of triggers) {
		if (!(await t.isVisible())) continue;
		const label = (await t.getAttribute('aria-label')) ?? (await t.textContent())?.trim().slice(0, 30);
		const mm = ctx.mark();
		await t.click().catch(() => {});
		await page.waitForTimeout(500);
		hits = await appRulesOnNcChrome(page);
		const menuVisible = await page.evaluate(() => [...document.querySelectorAll('#header .header-menu__content, .v-popper__popper, #header-menu-user-menu, .header-menu__wrapper')].some((e) => e.getBoundingClientRect().height > 0));
		opened += menuVisible ? 1 : 0;
		ctx.ok(hits.length === 0, `Regel-Scan nach Öffnen „${label}“: 0 App-Regeln auf NC-Oberfläche (Menü offen: ${menuVisible})`, hits.slice(0, 8).join(' | '));
		if (/user|benutzer|einstellungen|settings/i.test(String(label))) await ctx.screenshot(page, 'nc-user-menu');
		await page.keyboard.press('Escape');
		await page.waitForTimeout(250);
		if (page.url().includes('/apps/nextnotes/') === false) await page.goto(ctx.appUrl('index.php'), { waitUntil: 'networkidle' });
		ctx.assertClean(`NC-Menü „${label}“`, mm);
	}
	ctx.ok(opened >= 1, 'mindestens ein NC-Kopfmenü geöffnet', `${opened}`);
	const userMenuLink = await page.evaluate(async () => {
		document.querySelector('#user-menu button, #user-menu .header-menu__trigger')?.click();
		await new Promise((r) => setTimeout(r, 500));
		const a = document.querySelector('#header-menu-user-menu a, #user-menu a[href*="settings"], .user-menu a');
		const cs = a ? getComputedStyle(a) : null;
		const ref = document.createElement('a'); ref.href = '#'; document.querySelector('#header')?.append(ref);
		const out = a ? { color: cs.color, textDeco: cs.textDecorationLine, font: cs.fontFamily.slice(0, 40), text: a.textContent.trim().slice(0, 30) } : null;
		ref.remove();
		document.querySelector('#user-menu button, #user-menu .header-menu__trigger')?.click();
		return out;
	});
	ctx.info(`NC-Benutzermenü-Link: ${JSON.stringify(userMenuLink)}`);
	const infl = await ncCoreInfluence(page);
	ctx.info(`App-Formularelemente sichtbar: ${infl.elements}, NC-Kern-Stylesheets: ${infl.coreSheets.join(', ')}`);
	ctx.ok(infl.coreSheets.length > 0, 'NC-Kern-CSS gefunden (Vergleich aussagekräftig)');
	if (infl.softDiffs.length) ctx.info(`NC-Kern wirkt nur über geerbte Schrift (Info): ${infl.softDiffs.join(' | ')}`);
	ctx.ok(infl.diffs.length === 0, 'NC-Kern-CSS (Hintergrund, Rand, Höhe, Padding, Margin, Gewicht, Schatten) verändert keine sichtbaren App-Formularelemente', `${infl.diffs.length}: ${infl.diffs.slice(0, 6).join(' | ')}`);

	// 3. Navigation: Ordnerklick
	m = ctx.mark();
	const folderHeader = page.locator('#left_col .folder-name', { hasText: fA }).first();
	const contentId = await folderHeader.getAttribute('data-folder-dom-id');
	const wasOpen = await page.locator(`[id="${contentId}"]`).isVisible();
	await folderHeader.click();
	await page.waitForTimeout(500);
	const nowOpen = await page.locator(`[id="${contentId}"]`).isVisible();
	ctx.ok(wasOpen !== nowOpen, `Ordnerklick schaltet Ordner um (${wasOpen} → ${nowOpen})`);
	if (!nowOpen) { await folderHeader.click(); await page.waitForTimeout(400); }
	ctx.ok(await page.locator(`[id="${contentId}"] .folder-name`, { hasText: `Unter ${RUN}` }).isVisible(), 'Unterordner nach Öffnen sichtbar');
	await page.locator('#left_col .folder-name', { hasText: `Unter ${RUN}` }).first().click();
	await page.waitForTimeout(400);
	await page.reload({ waitUntil: 'networkidle' });
	ctx.assertClean('Ordnerklick', m);

	// 3b. B27: ?note= öffnet Ahnenkette per ID, gleichnamiger Ordner bleibt zu
	// eigener Kontext ohne localStorage: Upstream merkt zugeklappte Ordner (folder_<id> = closed) aus Schritt 3
	m = ctx.mark();
	const ctxN = await browser.newContext({ viewport: { width: 1400, height: 900 }, locale: 'de-DE' });
	await ctx.attach(ctxN);
	const pageN = await ctxN.newPage();
	await ctx.login(pageN);
	const rawB27 = await (await pageN.request.get(ctx.appUrl(`index.php?note=${nDeep}`))).text();
	const srvOpen = (id) => new RegExp(`class="folder-content" id="folder-${id}" style="display: block`).test(rawB27);
	ctx.info(`B27 Server-HTML: Ordner-ID offen ${[fDeep, fTwin].map((id) => `${id}=${srvOpen(id)}`).join(', ')}`);
	ctx.ok(srvOpen(fDeep) && !srvOpen(fTwin), 'B27 (Server): Notizordner offen, gleichnamiger Ordner zu');
	let r = await pageN.goto(ctx.appUrl(`index.php?workspace=${encodeURIComponent(WS)}&note=${nDeep}`), { waitUntil: 'networkidle' });
	ctx.ok(r?.status() === 200, '?note=<eigene> HTTP 200', `HTTP ${r?.status()}`);
	const b27 = await pageN.evaluate(({ RUN, fTwin, nDeep }) => {
		const openOf = (name) => [...document.querySelectorAll('#left_col .folder-name')].filter((e) => e.textContent.trim() === name)
			.map((e) => { const c = document.getElementById(e.dataset.folderDomId); return { id: e.closest('[data-folder-id]')?.dataset.folderId, open: c ? getComputedStyle(c).display !== 'none' : null }; });
		return {
			a: openOf(`Ordner ${RUN}`), u: openOf(`Unter ${RUN}`), t: openOf(`Tief ${RUN}`), fTwin,
			selected: [...document.querySelectorAll('#left_col a.selected-note')].map((a) => a.dataset.noteId),
			nDeep: String(nDeep),
			currentNote: document.getElementById('current-note-data')?.textContent,
		};
	}, { RUN, fTwin, nDeep });
	ctx.info(`B27: ${JSON.stringify(b27)}`);
	ctx.ok(b27.a[0]?.open && b27.u[0]?.open, 'B27: Ahnen der Notiz geöffnet');
	ctx.ok(b27.t.length === 2 && b27.t.find((x) => x.id === `folder-${fTwin}`)?.open === false && b27.t.find((x) => x.id !== `folder-${fTwin}`)?.open === true,
		'B27: Notizordner offen, gleichnamiger Ordner zu');
	ctx.ok(b27.selected.length === 1 && b27.selected[0] === b27.nDeep, 'Notiz in Liste markiert', b27.selected.join(','));
	ctx.ok(await pageN.locator(`#right_col #entry${nDeep}`).count() === 1, 'rechte Spalte zeigt die Notiz (M3b1)');
	ctx.assertClean('?note=', m);
	await ctxN.close();

	// 4. Workspace-Wechsel über das Menü
	m = ctx.mark();
	await page.goto(ctx.appUrl('index.php'), { waitUntil: 'networkidle' });
	await page.click('.sidebar-title');
	await page.waitForTimeout(400);
	ctx.ok(await page.locator('#workspaceMenu').isVisible(), 'Workspace-Menü offen');
	const item = page.locator(`#workspaceMenu .workspace-menu-item[data-workspace-name="${WS2}"]`);
	ctx.ok(await item.count() === 1, `Workspace-Menü listet „${WS2}“`);
	await ctx.screenshot(page, 'workspace-menu');
	await Promise.all([page.waitForURL((u) => u.searchParams.get('workspace') === WS2, { timeout: 15000 }), item.click()]);
	await page.waitForFunction((n) => document.querySelector('.workspace-title-text')?.textContent.trim() === n, WS2, { timeout: 15000 }).catch(() => {});
	await page.waitForLoadState('networkidle');
	const sw = await page.evaluate((RUN) => ({
		title: document.querySelector('.workspace-title-text')?.textContent.trim(),
		notes: [...document.querySelectorAll('#left_col .note-title')].map((e) => e.textContent.trim()).filter((t) => t.includes(RUN)),
	}), RUN);
	ctx.ok(sw.title === WS2, 'nach Wechsel Titel = neuer Workspace', sw.title);
	ctx.ok(sw.notes.includes(`Zweitnotiz ${RUN}`) && !sw.notes.includes(`Wurzelnotiz ${RUN}`), 'Notizliste zeigt nur Notizen des neuen Workspace', sw.notes.join(', '));
	await page.waitForTimeout(600);
	const last = await api.setting('last_opened_workspace');
	ctx.info(`last_opened_workspace nach Wechsel im Frontend: ${JSON.stringify(last.body?.value)}`);
	// zurück per Menü
	await page.click('.sidebar-title');
	await page.waitForTimeout(300);
	await Promise.all([page.waitForURL((u) => u.searchParams.get('workspace') === WS, { timeout: 15000 }), page.locator(`#workspaceMenu .workspace-menu-item[data-workspace-name="${WS}"]`).click()]);
	await page.waitForFunction((n) => document.querySelector('.workspace-title-text')?.textContent.trim() === n, WS, { timeout: 15000 }).catch(() => {});
	await page.waitForLoadState('networkidle');
	await page.waitForTimeout(800);
	ctx.ok(await page.locator('.workspace-title-text').textContent() === WS, 'Rückwechsel ok');
	ctx.assertClean('Workspace-Wechsel', m);

	// 5. B28: GET index.php ohne Seiteneffekt (rohe GETs ohne JS; Seite vorher verlassen, keine späten Frontend-PUTs)
	await page.goto('about:blank');
	await page.waitForTimeout(500);
	const before = (await api.setting('last_opened_workspace')).body?.value;
	const defBefore = (await api.setting('default_workspace')).body?.value;
	for (const q of [`workspace=${encodeURIComponent(WS2)}`, 'workspace=Gibt%20es%20nicht', `note=${nDeep}`, `workspace=${encodeURIComponent(WS2)}&folder=x&open_folder=y`]) {
		const rr = await page.request.get(ctx.appUrl(`index.php?${q}`), { failOnStatusCode: false });
		ctx.ok(rr.status() === 200, `roher GET ?${q} HTTP 200`, `HTTP ${rr.status()}`);
	}
	const after = (await api.setting('last_opened_workspace')).body?.value;
	const defAfter = (await api.setting('default_workspace')).body?.value;
	ctx.ok(before === after && defBefore === defAfter, 'B28: GET index.php schreibt kein Workspace-Setting', `last ${before} → ${after}, default ${defBefore} → ${defAfter}`);

	// 6. ?workspace= unbekannt → Standard; ?note= ungültig → NC-404
	m = ctx.mark();
	await api.must('PUT', '/settings/last_opened_workspace', { value: WS });
	r = await page.goto(ctx.appUrl('index.php?workspace=Gibt%20es%20nicht'), { waitUntil: 'networkidle' });
	ctx.ok(r?.status() === 200 && (await page.locator('.workspace-title-text').textContent()) === WS, '?workspace=unbekannt → 200, Standard-Workspace', `HTTP ${r?.status()}`);
	ctx.ok(!page.url().includes('Gibt'), 'unbekannter Schlüssel per replaceState aus URL entfernt', page.url());
	ctx.assertClean('?workspace=unbekannt', m);
	for (const bad of ['abc', '0', '99999999', '-1', '1e3', `${nDeep}%20x`, '%3Cscript%3E']) {
		const undo = ctx.allow('status', /404 GET .*index\.php\?note=/, 'erwartete NC-404 für ungültige ?note=');
		const undoC = ctx.allow('console', /status of 404 .*index\.php\?note=/, 'Browser-Log zur erwarteten NC-404');
		const rr = await page.goto(ctx.appUrl(`index.php?note=${bad}`), { waitUntil: 'load' });
		const body = await page.content();
		ctx.ok(rr?.status() === 404 && !body.includes('nextnotes-root') && !body.includes(RUN), `?note=${bad} → NC-404 ohne App-Inhalt`, `HTTP ${rr?.status()}`);
		undo(); undoC();
	}
	await ctx.screenshot(page, 'note-404');

	// 7. Fremdzugriff: bob
	const ctxB = await browser.newContext({ viewport: { width: 1400, height: 900 }, locale: 'de-DE' });
	await ctx.attach(ctxB);
	const pageB = await ctxB.newPage();
	await ctx.login(pageB, process.env.NN_USER2, process.env.NN_PASS2);
	m = ctx.mark();
	const undo404 = ctx.allow('status', new RegExp(`404 GET .*index\\.php\\?note=${nDeep}$`), 'erwartete NC-404 bei fremder Notiz');
	const undoUs = ctx.allow('status', /404 GET .*\/ocs\/v2\.php\/apps\/user_status\/api\/v1\/statuses\//, 'NC-Kern user_status: Konto ohne gesetzten Status (nicht App)');
	const undoUsC = ctx.allow('console', /status of 404 .*\/apps\/user_status\/api\/v1\/statuses\//, 'NC-Kern user_status (Browser-Log)');
	r = await pageB.goto(ctx.appUrl(`index.php?note=${nDeep}`), { waitUntil: 'load' });
	let bodyB = await pageB.content();
	ctx.ok(r?.status() === 404 && !bodyB.includes(RUN) && !bodyB.includes('nextnotes-root'), 'bob ?note=<alice> → 404, kein Titel/Inhalt', `HTTP ${r?.status()}`);
	undo404();
	// Ausgangslage unabhängig von Vorläufen (z. B. dashboard-ws merkt einen Team-Workspace): fremder Schlüssel → Start wie ohne Parameter
	const apiB = apiClient(ctx, pageB.request, process.env.NN_USER2, process.env.NN_PASS2);
	const wsB = await apiB.defaultWorkspace();
	await apiB.must('PUT', '/settings/last_opened_workspace', { value: wsB });
	r = await pageB.goto(ctx.appUrl(`index.php?workspace=${encodeURIComponent(WS2)}`), { waitUntil: 'networkidle' });
	bodyB = await pageB.content();
	const titleB = await pageB.locator('.workspace-title-text').textContent();
	ctx.ok(r?.status() === 200 && titleB === wsB && !bodyB.includes(RUN), 'bob ?workspace=<alice> → 200 eigener Standard, kein A-Marker', `HTTP ${r?.status()} ${titleB}`);
	r = await pageB.goto(ctx.appUrl(`index.php?workspace=${encodeURIComponent(WS2)}&note=${nDeep}`), { waitUntil: 'load' });
	ctx.ok(r?.status() === 404, 'bob ?workspace=<A>&note=<A> → 404', `HTTP ${r?.status()}`);
	for (const e of ctx.events.slice(m.e)) if (['status', 'console'].includes(e.kind) && /index\.php\?(workspace=.*&)?note=/.test(e.text) && /404/.test(e.text)) e.allowed = 'erwartete 404';
	undoUs(); undoUsC();
	ctx.assertClean('Fremdzugriff bob', m);
	await ctxB.close();

	// 8. Abgemeldet → Login
	const anon = await browser.newContext();
	const ap = await anon.newPage();
	const ar = await ap.goto(ctx.appUrl(`index.php?workspace=${encodeURIComponent(WS)}`), { waitUntil: 'load' });
	ctx.ok(new URL(ap.url()).pathname.endsWith('/login') && new URL(ap.url()).searchParams.get('redirect_url')?.includes('nextnotes'), 'abgemeldet → NC-Login mit Rücksprung', `${ar?.status()} ${ap.url()}`);
	const raw = await anon.request.get(ctx.appUrl('index.php'), { maxRedirects: 0, failOnStatusCode: false, headers: { Accept: 'text/html' } });
	ctx.ok([302, 303].includes(raw.status()) && /login/.test(raw.headers().location ?? ''), 'abgemeldet: Redirect-Status auf Login', `${raw.status()} ${raw.headers().location}`);
	await anon.close();

	// 9. Header je API-Request
	// headersArray() geschlossener Kontexte (bob, abgemeldet) kann offen bleiben: höchstens 10 s warten
	await Promise.race([Promise.all(hdrPending), new Promise((r) => setTimeout(r, 10000))]);
	ctx.ok(hdrChecked > 0, `API-Requests mit Headerprüfung: ${hdrChecked}`);
	ctx.ok(hdrProblems.length === 0, 'requesttoken und X-Requested-With (XHR) je genau einmal', hdrProblems.slice(0, 6).join(' | '));
	ctx.assertClean('gesamter Lauf');
});
