// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// Anti-Flicker (M4h, UI-nav ADR 0019, UI-nav-mobil Zusatz 7): nur der Inhalt wartet bis html.nextnotes-ready. Die Inhaltsflächen
// (#right_pane, .nn-page-main, #outline-panel) bleiben deckend (opacity 1), ihre Kinder sind opacity 0; Unterseiten ganz #nextnotes-root.
// inert steht im Template und fällt bei DOMContentLoaded, Rückfall CSS/JS nach 2 s ab Navigationsstart. Icon-Leiste und Seitenleiste sofort.
// A) Normale Ladezeit: Phase „Inhalt im DOM, opacity 0“ bis „nextnotes-ready“ < 1 s je Seite, danach opacity 1 und nicht inert.
// B) nextnotes-late.js künstlich verzögert (DOMContentLoaded/ready warten auf defer-Skripte), je index und tasks.php:
//    B1 vor 2 s: Inhalt opacity 0 + inert, kein Fokus (Tab, focus()), kein Klick; Leisten sichtbar, nicht inert, per Klick/Tab erreichbar.
//    B2 nach 2 s ohne ready: Rückfall, Inhalt opacity 1 und nicht inert (geprüft 2,8 s nach Erscheinen: JS-Timer startet mit dem Bootstrap).
// Gegenprobe: AF_STRIP=css entfernt die CSS-Regel (Antwort umgeschrieben), AF_STRIP=html das inert im Template → B muss rot werden.
import { runSpec } from './lib/harness.mjs';
import { apiClient } from './lib/api.mjs';

const PAGES = ['', '', '', 'diary.php', 'tasks.php', 'dashboard.php', 'settings.php', 'trash.php', 'list_tags.php', 'notes_manager.php'];
const DELAY_MS = 4500;
const WAITING = '#nextnotes-root > :is(#right_pane, .nn-page-main, #outline-panel), #nextnotes-root:is(.nextnotes-subpage, .nextnotes-excalidraw)';
const STRIP = process.env.AF_STRIP || '';

const INIT = (sel) => {
	const s = { seen: null, opacityAtSeen: null, ready: null, clicks: [], focus: [] };
	window.__nnFlicker = s;
	const check = () => {
		if (s.seen === null) {
			const el = document.querySelector(sel);
			if (el) { s.seen = performance.now(); s.opacityAtSeen = getComputedStyle(el).opacity; }
		}
		if (s.inertAt === undefined && document.querySelector(sel)?.inert) s.inertAt = performance.now();
		if (s.ready === null && document.documentElement.classList.contains('nextnotes-ready')) s.ready = performance.now();
	};
	new MutationObserver(check).observe(document, { childList: true, subtree: true, attributes: true, attributeFilter: ['class', 'inert'] });
	const hidden = (t) => {
		if (!(t instanceof Element)) return false;
		const w = [...document.querySelectorAll(sel)].find((el) => el.contains(t));
		return !!(w && (getComputedStyle(w).opacity === '0' || !document.documentElement.classList.contains('nextnotes-ready')) && !s.released);
	};
	const d = (t) => t.tagName + (t.id ? '#' + t.id : '');
	window.addEventListener('click', (e) => { if (hidden(e.target)) s.clicks.push(d(e.target)); }, true);
	window.addEventListener('focusin', (e) => { if (hidden(e.target)) s.focus.push(d(e.target)); }, true);
};

// Zustand der wartenden Inhaltsbereiche und der Leisten
const STATE = (sel) => {
	const vis = (el) => { for (let e = el; e; e = e.parentElement) if (getComputedStyle(e).opacity === '0') return false; return true; };
	// Fläche = Inhaltsfläche (#right_pane, .nn-page-main, #outline-panel) mit wartenden Kindern; sonst ganze Unterseite
	const waiting = [...document.querySelectorAll(sel)].map((el) => {
		const surface = el.id !== 'nextnotes-root';
		// nur gerenderte Kinder (display none: Upstream-Elemente mit eigener opacity 0, z. B. #outlineMobileBackdrop)
		const kids = surface ? [...el.children].filter((k) => k.getClientRects().length > 0).map((k) => getComputedStyle(k).opacity) : [];
		const op = getComputedStyle(el).opacity;
		const shown = el.getClientRects().length > 0 && getComputedStyle(el).display !== 'none';
		return {
			d: el.tagName + (el.id ? '#' + el.id : '') + '.' + String(el.className).split(' ')[0],
			surface, op, kids, pe: getComputedStyle(el).pointerEvents, inert: !!el.closest('[inert]'),
			hidden: !shown || op === '0' || (kids.length > 0 && kids.every((o) => o === '0')),
			allVisible: op === '1' && kids.every((o) => o === '1'),
			shown,
		};
	});
	const bars = ['#icon_sidebar', '#left_col', '#header'].map((q) => document.querySelector(q)).filter(Boolean).map((el) => {
		const r = el.getBoundingClientRect();
		return { d: '#' + el.id, visible: vis(el) && r.width > 0 && r.height > 0, inert: !!el.closest('[inert]') };
	});
	return { ready: document.documentElement.classList.contains('nextnotes-ready'), waiting, bars, t: performance.now() - (window.__nnFlicker?.seen ?? 0) };
};

runSpec(import.meta.url, async (ctx, page, context) => {
	await context.addInitScript(INIT, WAITING);
	if (STRIP === 'css') {
		ctx.info('Gegenprobe: Anti-Flicker-CSS-Regel entfernt');
		await context.route(/\/nextnotes\/css\/nextnotes-layout\.css/, async (route) => {
			const r = await route.fetch();
			const body = (await r.text()).replace(/opacity:\s*0;\s*pointer-events:\s*none;\s*animation:\s*nextnotes-reveal[^;]*;/, '');
			await route.fulfill({ response: r, body });
		});
	} else if (STRIP === 'html') {
		ctx.info('Gegenprobe: inert im Template entfernt');
		await context.route(/\/apps\/nextnotes\//, async (route) => {
			if (route.request().resourceType() !== 'document') return route.continue();
			const r = await route.fetch();
			await route.fulfill({ response: r, body: (await r.text()).replace(/(<(?:div|main|aside)\b[^>]*?)\s+inert>/g, '$1>') });
		});
	}
	const api = apiClient(ctx, page.request);
	const WS = await api.defaultWorkspace();
	const note = await api.note(WS, `Anti-Flicker ${Date.now()}`, null, '<p>Text</p><p><a href="https://example.org">Link</a></p>');
	const m0 = ctx.mark();

	// A) Phase je Seite
	for (const p of PAGES) {
		await page.goto(ctx.appUrl(p), { waitUntil: 'load' });
		await page.waitForFunction(() => window.__nnFlicker?.ready !== null, null, { timeout: 15000 }).catch(() => {});
		const s = await page.evaluate(() => window.__nnFlicker);
		const phase = s.seen !== null && s.ready !== null ? Math.round(s.ready - s.seen) : null;
		ctx.ok(phase !== null && phase < 1000, `A ${p || 'index'}: Phase bis ready < 1 s`, `Phase ${phase} ms, opacity bei Erscheinen ${s.opacityAtSeen}`);
		await page.waitForTimeout(200); // Übergang 120 ms
		const st = await page.evaluate(STATE, WAITING);
		const bad = st.waiting.filter((w) => !w.allVisible || w.inert || w.pe === 'none');
		ctx.ok(st.waiting.some((w) => w.shown && w.kids.length + (w.surface ? 0 : 1) > 0) && bad.length === 0, `A ${p || 'index'}: nach Start Inhalt sichtbar, bedienbar`, JSON.stringify(st.waiting));
	}

	// B) verlängerte Phase je Seitentyp
	for (const p of ['', 'tasks.php']) {
		const label = `B ${p || 'index'}`;
		const url = ctx.appUrl(p || `index.php?workspace=${encodeURIComponent(WS)}&note=${note}`);
		const re = /\/nextnotes\/js\/nextnotes-late\.js/;
		await page.route(re, async (route) => { await new Promise((r) => setTimeout(r, DELAY_MS)); await route.continue().catch(() => {}); });
		const nav = page.goto(url, { waitUntil: 'load', timeout: 30000 });
		await page.waitForFunction((sel) => document.querySelector(sel) && document.querySelector('#icon_sidebar .icon-sidebar-btn')
			&& document.querySelector(`:is(${sel}) :is(button, a, input, [tabindex])`), WAITING, { timeout: 10000, polling: 20 });
		await page.waitForTimeout(150);

		// B1 vor dem Rückfall
		const st = await page.evaluate(STATE, WAITING);
		ctx.info(`${label} t=${Math.round(st.t)} ms ready=${st.ready} waiting=${JSON.stringify(st.waiting)} bars=${JSON.stringify(st.bars)}`);
		ctx.ok(!st.ready && st.t < 1700, `${label}: Messung in der Wartephase (vor ready, vor 2 s)`, `ready ${st.ready}, t ${Math.round(st.t)} ms`);
		ctx.ok(st.waiting.length > 0 && st.waiting.every((w) => w.hidden), `${label}: Inhalt unsichtbar (Kinder opacity 0)`, JSON.stringify(st.waiting));
		ctx.ok(st.waiting.some((w) => w.surface && w.shown) && st.waiting.filter((w) => w.surface).every((w) => w.op === '1'), `${label}: Inhaltsfläche selbst deckend (opacity 1)`, JSON.stringify(st.waiting));
		ctx.ok(st.waiting.length > 0 && st.waiting.every((w) => w.inert), `${label}: Inhalt inert`, JSON.stringify(st.waiting));
		const rail = st.bars.find((b) => b.d === '#icon_sidebar');
		ctx.ok(rail?.visible && !rail.inert, `${label}: Icon-Leiste sofort sichtbar, nicht inert`, JSON.stringify(rail));
		if (!p) {
			const side = st.bars.find((b) => b.d === '#left_col');
			ctx.ok(side?.visible && !side.inert, `${label}: Seitenleiste sofort sichtbar, nicht inert`, JSON.stringify(side));
		}

		// programmatischer Fokus und Trefferprüfung auf Inhalt bzw. Leiste
		const probe = await page.evaluate((sel) => {
			const inContent = (el) => [...document.querySelectorAll(sel)].some((w) => w.contains(el));
			const visibleIn = (root, q) => [...root.querySelectorAll(q)].find((e) => { const r = e.getBoundingClientRect(); return r.width > 4 && r.height > 4 && r.top >= 0 && r.bottom <= innerHeight && r.left >= 0 && r.right <= innerWidth; });
			const ctl = [...document.querySelectorAll(sel)].map((w) => visibleIn(w, 'button, a[href], input, [tabindex]:not([tabindex="-1"])')).find(Boolean);
			let focusOk = null, hitContent = null, pt = null;
			if (ctl) {
				ctl.focus();
				focusOk = document.activeElement !== ctl;
				const r = ctl.getBoundingClientRect();
				pt = { x: r.left + r.width / 2, y: r.top + r.height / 2, d: ctl.tagName + (ctl.id ? '#' + ctl.id : '') };
				const hit = document.elementFromPoint(pt.x, pt.y);
				hitContent = !!hit && inContent(hit);
			}
			const btn = visibleIn(document.getElementById('icon_sidebar'), '.icon-sidebar-btn');
			let railHit = null;
			if (btn) { const r = btn.getBoundingClientRect(); const h = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2); railHit = !!h && (btn === h || btn.contains(h)); }
			return { focusOk, hitContent, pt, railHit };
		}, WAITING);
		ctx.ok(probe.pt !== null, `${label}: Bedienelement im wartenden Inhalt gefunden`, JSON.stringify(probe));
		ctx.ok(probe.focusOk === true, `${label}: focus() auf Inhalt wirkungslos`, JSON.stringify(probe));
		ctx.ok(probe.hitContent === false, `${label}: Klickpunkt trifft nicht den Inhalt`, JSON.stringify(probe));
		ctx.ok(probe.railHit === true, `${label}: Klickpunkt Icon-Leiste trifft die Schaltfläche`, JSON.stringify(probe));

		// Tastatur: Fokus nie im Inhalt, aber Leisten erreichbar
		await page.evaluate(() => document.activeElement?.blur());
		const reached = new Set();
		for (let i = 0; i < 25; i++) {
			await page.keyboard.press('Tab');
			reached.add(await page.evaluate(() => {
				const a = document.activeElement;
				return a?.closest('#icon_sidebar') ? 'rail' : a?.closest('#left_col') ? 'sidebar' : a?.closest('#header') ? 'header' : 'other';
			}));
		}
		const mid = await page.evaluate(STATE, WAITING);
		ctx.ok(!mid.ready, `${label}: Tab-Lauf in der Wartephase`, `t ${Math.round(mid.t)} ms`);
		ctx.ok(reached.has('rail'), `${label}: Icon-Leiste per Tab erreichbar`, [...reached].join(','));
		if (probe.pt) await page.mouse.click(probe.pt.x, probe.pt.y);
		const s1 = await page.evaluate(() => window.__nnFlicker);
		ctx.ok(s1.focus.length === 0, `${label}: kein Fokus im wartenden Inhalt`, s1.focus.slice(0, 5).join(', '));
		ctx.ok(s1.clicks.length === 0, `${label}: kein Klick im wartenden Inhalt`, s1.clicks.join(', '));

		// B2 Rückfall nach 2 s (ready noch aus)
		await page.waitForFunction(() => performance.now() - window.__nnFlicker.seen > 2800, null, { timeout: 5000, polling: 50 });
		const fb = await page.evaluate(STATE, WAITING);
		ctx.info(`${label} inert gesetzt ${Math.round(s1.inertAt - s1.seen)} ms nach Erscheinen des Inhalts (Bootstrap-Start; Rückfall-Timer ab dort)`);
		ctx.info(`${label} Rückfall t=${Math.round(fb.t)} ms ready=${fb.ready} waiting=${JSON.stringify(fb.waiting)}`);
		ctx.ok(!fb.ready, `${label}: Rückfall-Messung vor ready`, `t ${Math.round(fb.t)} ms`);
		// nicht gerenderte Bereiche (z. B. eingeklappte Gliederung, display none) laufen keine Animation; nur sichtbare zählen
		ctx.ok(fb.waiting.some((w) => w.shown) && fb.waiting.filter((w) => w.shown).every((w) => w.allVisible && !w.inert && w.pe !== 'none'), `${label}: Rückfall nach 2 s sichtbar und bedienbar`, JSON.stringify(fb.waiting));

		await nav;
		await page.unroute(re);
	}
	ctx.assertClean('anti-flicker gesamt', m0);
});
