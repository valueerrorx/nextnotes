// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// UI-nav: Seitenleiste/Icon-Leiste im Stil der NC-Navigation (Files), weiche Seitenwechsel (View Transition, Vorladen), Anti-Flicker nur Inhalt.
// A) Berechnete Farben/Maße der aktiven und der Hover-Zeile = Files-Navigation (gleiches Theme)
// B) View-Transition-Regel vorhanden; Klick in der Icon-Leiste läuft als View Transition; Kopf- und Icon-Leiste eigene Gruppen ohne Animation
//    (UI-nav-mobil Zusatz 7), nur die Wurzel (Inhalt) blendet; reduzierte Bewegung: keine
// C) Vorladen nur bei Hover/Fokus, nur App-Seiten; Navigation nutzt den Vorabruf
// D) Anti-Flicker: Leisten sofort sichtbar und bedienbar, Inhaltsfläche deckend, nur ihre Kinder warten (opacity 0); nach dem Start alles sichtbar
// Je Abschnitt 0 CSP/pageerror/Konsolenfehler/≥ 400.
import { runSpec } from './lib/harness.mjs';
import { apiClient } from './lib/api.mjs';

const RUN = `${process.argv[3]}${Date.now().toString(36)}`;

const INIT = () => {
	const s = { vt: null, groups: [], names: null, first: null, ready: null };
	window.__nnNav = s;
	window.addEventListener('pagereveal', (e) => {
		s.vt = !!e.viewTransition;
		if (e.viewTransition) e.viewTransition.ready.then(() => {
			s.groups = document.getAnimations().map((a) => a.effect?.pseudoElement).filter(Boolean);
			const n = (id) => { const el = document.getElementById(id); return el ? getComputedStyle(el).viewTransitionName : null; };
			s.names = { header: n('header'), rail: n('icon_sidebar') };
		}).catch(() => {});
	});
	const content = () => document.querySelector('#nextnotes-root > #right_pane, #nextnotes-root > .nn-page-main');
	const check = () => {
		const rail = document.getElementById('icon_sidebar');
		const main = content();
		if (s.first === null && rail && main) {
			s.first = {
				app: getComputedStyle(document.getElementById('app-content')).opacity,
				rail: getComputedStyle(rail).opacity,
				railInert: !!rail.closest('[inert]'),
				left: document.getElementById('left_col') ? getComputedStyle(document.getElementById('left_col')).opacity : null,
				main: getComputedStyle(main).opacity,
				kids: main.firstElementChild ? getComputedStyle(main.firstElementChild).opacity : null,
				mainInert: main.inert,
				ready: document.documentElement.classList.contains('nextnotes-ready'),
			};
		}
		if (s.ready === null && document.documentElement.classList.contains('nextnotes-ready')) s.ready = performance.now();
	};
	new MutationObserver(check).observe(document, { childList: true, subtree: true, attributes: true, attributeFilter: ['class'] });
};

const STYLE = (sel, hover) => {
	const el = document.querySelector(sel);
	if (!el) return null;
	const s = getComputedStyle(el);
	return { bg: s.backgroundColor, color: s.color, radius: s.borderRadius, minHeight: s.minHeight, hover };
};

await runSpec(import.meta.url, async (ctx, page, context) => {
	await context.addInitScript(INIT);
	const api = apiClient(ctx, page.request);
	const WS = await api.workspace(`Nav ${RUN}`);
	const wq = `workspace=${encodeURIComponent(WS)}`;
	const folder = await api.folder(WS, `Ordner ${RUN}`);
	const note = await api.note(WS, `Notiz ${RUN}`, folder);
	await api.note(WS, `Zweite ${RUN}`, folder);

	// A) Files-Navigation als Referenz; Text-App der Files-README speichert beim Verlassen mit 412 (NC, nicht die App)
	const m0 = ctx.mark();
	ctx.allow('status', /\/apps\/text\/session\/[^/]+\/save/, 'Files-README (Text-App) beim Verlassen, NC-Kern');
	ctx.allow('console', /\/apps\/text\/session\/[^/]+\/save/, 'Files-README (Text-App) beim Verlassen, NC-Kern');
	await page.goto(`${ctx.base}/index.php/apps/files/`, { waitUntil: 'networkidle' });
	await page.locator('.app-navigation-entry.active').first().waitFor();
	const filesActive = await page.evaluate(STYLE, '.app-navigation-entry.active');
	const filesActiveText = await page.evaluate(() => getComputedStyle(document.querySelector('.app-navigation-entry.active .app-navigation-entry-link')).color);
	const filesFont = await page.evaluate(() => getComputedStyle(document.querySelector('.app-navigation-entry-link')).fontSize);
	await page.locator('.app-navigation-entry:not(.active) a').first().hover();
	await page.waitForTimeout(400);
	const filesHover = await page.evaluate(() => getComputedStyle([...document.querySelectorAll('.app-navigation-entry')].find((e) => e.matches(':hover'))).backgroundColor);
	ctx.info(`Files aktiv ${JSON.stringify(filesActive)}, Text ${filesActiveText}, Hover ${filesHover}, Schrift ${filesFont}`);
	const rest = ctx.mark();

	await page.goto(ctx.appUrl(`index.php?${wq}&note=${note}`), { waitUntil: 'networkidle' });
	const sel = '#left_col a.links_arbo_left.selected-note';
	await page.locator(sel).first().waitFor();
	const nnActive = await page.evaluate(STYLE, sel);
	const nnActiveText = await page.evaluate((s) => getComputedStyle(document.querySelector(`${s} .note-title`)).color, sel);
	const nnFont = await page.evaluate((s) => getComputedStyle(document.querySelector(`${s} .note-title`)).fontSize, sel);
	const railActive = await page.evaluate(STYLE, '#icon_sidebar [aria-current="page"]');
	const stripe = await page.evaluate((s) => { const b = getComputedStyle(document.querySelector(s), '::before'); return { w: b.width, bg: b.backgroundColor }; }, sel);
	const primary = await page.evaluate(() => { const d = document.createElement('div'); d.style.color = 'var(--color-primary-element)'; document.body.append(d); const c = getComputedStyle(d).color; d.remove(); return c; });
	ctx.info(`Next Notes aktiv ${JSON.stringify(nnActive)}, Text ${nnActiveText}, Schrift ${nnFont}, Leiste ${JSON.stringify(railActive)}, Streifen ${JSON.stringify(stripe)}`);
	ctx.ok(nnActive && nnActive.bg === filesActive.bg, 'A aktive Notiz: Hintergrund = Files', `${nnActive?.bg} / ${filesActive.bg}`);
	ctx.ok(nnActiveText === filesActiveText, 'A aktive Notiz: Textfarbe = Files', `${nnActiveText} / ${filesActiveText}`);
	ctx.ok(nnActive.radius === filesActive.radius && nnActive.minHeight === filesActive.minHeight, 'A aktive Notiz: Radius und Zeilenhöhe = Files',
		`${nnActive.radius}/${nnActive.minHeight} – ${filesActive.radius}/${filesActive.minHeight}`);
	ctx.ok(nnFont === filesFont, 'A Schriftgröße Baum = Files', `${nnFont} / ${filesFont}`);
	ctx.ok(stripe.w === '3px' && stripe.bg === primary, 'A Streifen links in Primärfarbe', JSON.stringify(stripe));
	ctx.ok(railActive && railActive.bg === filesActive.bg && railActive.radius === filesActive.radius, 'A Icon-Leiste aktiv = Files', JSON.stringify(railActive));
	const other = page.locator('#left_col a.links_arbo_left:not(.selected-note):visible').first();
	await other.hover();
	await page.waitForTimeout(400);
	const nnHover = await page.evaluate(() => getComputedStyle([...document.querySelectorAll('#left_col a.links_arbo_left')].find((e) => e.matches(':hover'))).backgroundColor);
	ctx.ok(nnHover === filesHover, 'A Hover-Zeile = Files', `${nnHover} / ${filesHover}`);
	const surfaces = await page.evaluate(() => ({
		rail: getComputedStyle(document.getElementById('icon_sidebar')).backgroundColor,
		left: getComputedStyle(document.getElementById('left_col')).backgroundColor,
		app: getComputedStyle(document.getElementById('app-content')).backgroundColor,
		blur: getComputedStyle(document.getElementById('content'), '::before').backgroundColor,
		main: getComputedStyle(document.getElementById('right_pane')).backgroundColor,
	}));
	const expect = await page.evaluate(() => {
		const probe = (v) => { const d = document.createElement('div'); d.style.backgroundColor = `var(${v})`; document.body.append(d); const c = getComputedStyle(d).backgroundColor; d.remove(); return c; };
		return { blur: probe('--color-main-background-blur'), main: probe('--color-main-background') };
	});
	ctx.ok(surfaces.rail === 'rgba(0, 0, 0, 0)' && surfaces.left === 'rgba(0, 0, 0, 0)' && surfaces.app === 'rgba(0, 0, 0, 0)', 'A Leisten transparent', JSON.stringify(surfaces));
	ctx.ok(surfaces.blur === expect.blur && surfaces.main === expect.main, 'A Blur-Fläche und Inhaltsfläche aus NC-Variablen', `${JSON.stringify(surfaces)} / ${JSON.stringify(expect)}`);
	const fits = await page.evaluate(() => { const c = document.getElementById('content').getBoundingClientRect(); const l = document.getElementById('left_col').getBoundingClientRect(); return Math.round(l.bottom) <= Math.round(c.bottom); });
	ctx.ok(fits, 'A Seitenleiste endet in #content (nicht abgeschnitten)');
	await ctx.screenshot(page, `ui-nav-index-${ctx.theme}`);
	ctx.assertClean('A Stil', rest);

	// B) View Transition
	const m1 = ctx.mark();
	const vtRule = await page.evaluate(() => {
		const walk = (rules) => [...rules].some((r) => r.constructor.name === 'CSSViewTransitionRule' ? r.navigation === 'auto' : r.cssRules ? walk(r.cssRules) : false);
		return [...document.styleSheets].some((s) => { try { return /nextnotes-layout/.test(s.href ?? '') && walk(s.cssRules); } catch { return false; } });
	});
	ctx.ok(vtRule, 'B @view-transition { navigation: auto } in nextnotes-layout.css');
	await page.locator('#iconSidebarTasksBtn').click();
	await page.waitForFunction(() => window.__nnNav?.ready !== null, null, { timeout: 10000 });
	await page.waitForTimeout(400);
	const vt = await page.evaluate(() => window.__nnNav);
	ctx.ok(vt.vt === true, 'B Icon-Leiste → Aufgaben als View Transition', JSON.stringify(vt));
	const groups = vt.groups.join(' ');
	ctx.ok(vt.names && vt.names.header === 'nn-header' && vt.names.rail === 'nn-rail', 'B Kopf- und Icon-Leiste eigene Gruppen im Übergang', JSON.stringify(vt.names));
	ctx.ok(!/nn-header|nn-rail/.test(groups), 'B Kopf- und Icon-Leiste stehen (keine Animation)', groups);
	ctx.ok(!/view-transition-group\(nn-sidebar\)/.test(groups), 'B Seitenleiste ohne Positions-Animation', groups);
	ctx.info(`B Seitenleiste nur alt (index → tasks): ${/view-transition-old\(nn-sidebar\)/.test(groups) ? 'blendet aus' : 'keine Animation'}`);
	ctx.ok(/view-transition-(old|new)\(root\)/.test(groups), 'B Inhalt (Wurzel) blendet', groups);
	const names = await page.evaluate(() => getComputedStyle(document.getElementById('icon_sidebar')).viewTransitionName);
	ctx.ok(names === 'none', 'B Name nur während des Übergangs (danach none)', names);
	await page.emulateMedia({ reducedMotion: 'reduce' });
	await page.locator('#iconSidebarDashboardBtn').click();
	await page.waitForFunction(() => window.__nnNav?.ready !== null, null, { timeout: 10000 });
	const vtReduced = await page.evaluate(() => window.__nnNav.vt);
	ctx.ok(vtReduced === false, 'B reduzierte Bewegung: keine View Transition', String(vtReduced));
	await page.emulateMedia({ reducedMotion: 'no-preference' });
	ctx.assertClean('B View Transition', m1);

	// C) Vorladen
	const m2 = ctx.mark();
	await page.goto(ctx.appUrl(`index.php?${wq}`), { waitUntil: 'networkidle' });
	ctx.ok(await page.evaluate(() => document.querySelectorAll('script[type="speculationrules"]').length === 0), 'C beim Laden keine Vorlade-Regel');
	await page.locator('#iconSidebarGraphBtn').hover();
	await page.waitForFunction(() => document.querySelectorAll('script[type="speculationrules"]').length > 0, null, { timeout: 3000 }).catch(() => {});
	const rules = await page.evaluate(() => [...document.querySelectorAll('script[type="speculationrules"]')].map((s) => JSON.parse(s.textContent)));
	const urls = rules.flatMap((r) => r.prefetch.flatMap((p) => p.urls));
	ctx.ok(urls.length === 1 && /\/apps\/nextnotes\/graph\.php\?/.test(urls[0]) && urls[0].startsWith(ctx.base), 'C Hover: Regel nur für graph.php (App, gleiche Origin)', urls.join(' '));
	ctx.ok(rules.every((r) => Object.keys(r).join() === 'prefetch'), 'C nur prefetch (kein prerender)', JSON.stringify(rules));
	await page.waitForTimeout(800);
	await page.locator('#iconSidebarGraphBtn').click();
	await page.waitForFunction(() => window.__nnNav?.ready !== null, null, { timeout: 10000 });
	const delivery = await page.evaluate(() => performance.getEntriesByType('navigation')[0].deliveryType);
	ctx.ok(delivery === 'navigational-prefetch', 'C Navigation aus dem Vorabruf', `deliveryType ${delivery}`);
	await page.locator('#iconSidebarTrashBtn').focus();
	await page.waitForFunction(() => document.querySelectorAll('script[type="speculationrules"]').length > 0, null, { timeout: 3000 }).catch(() => {});
	const focusUrls = await page.evaluate(() => [...document.querySelectorAll('script[type="speculationrules"]')].flatMap((s) => JSON.parse(s.textContent).prefetch.flatMap((p) => p.urls)));
	ctx.ok(focusUrls.length === 1 && /trash\.php/.test(focusUrls[0]), 'C Fokus: Regel für trash.php', focusUrls.join(' '));
	ctx.assertClean('C Vorladen', m2);

	// D) Anti-Flicker nur Inhalt
	const m3 = ctx.mark();
	for (const p of [`index.php?${wq}&note=${note}`, `tasks.php?${wq}`, `dashboard.php?${wq}`]) {
		await page.goto(ctx.appUrl(p), { waitUntil: 'load' });
		await page.waitForFunction(() => window.__nnNav?.ready !== null, null, { timeout: 10000 });
		await page.waitForTimeout(300);
		const s = await page.evaluate(() => window.__nnNav.first);
		ctx.ok(s && s.app === '1' && s.rail === '1' && !s.railInert && (s.left === null || s.left === '1'), `D ${p.split('?')[0]}: Leisten sofort sichtbar und bedienbar`, JSON.stringify(s));
		ctx.ok(s && s.main === '1', `D ${p.split('?')[0]}: Inhaltsfläche deckend ab dem ersten Bild`, JSON.stringify(s));
		ctx.ok(s && (s.ready || (s.kids === '0' && s.mainInert === true)), `D ${p.split('?')[0]}: Inhalt (Kinder) wartet unsichtbar und inert bis zum Start`, JSON.stringify(s));
		const after = await page.evaluate(() => { const m = document.querySelector('#nextnotes-root > #right_pane, #nextnotes-root > .nn-page-main'); return { op: getComputedStyle(m).opacity, inert: m.inert }; });
		ctx.ok(after.op === '1' && after.inert === false, `D ${p.split('?')[0]}: nach dem Start sichtbar`, JSON.stringify(after));
	}
	ctx.assertClean('D Anti-Flicker', m3);
	ctx.assertClean('gesamter Lauf', m0);
});
