// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// M4h: Anti-Flicker-Regel #app-content (opacity 0 bis html.nextnotes-ready, CSS-Rückfall 2 s).
// A) Normale Ladezeit: Phase „#app-content im DOM, opacity 0“ bis „nextnotes-ready“ < 1 s je Seite.
// B) Info: Skript künstlich verzögert (Phase verlängert) → können Tab/Klick Elemente in unsichtbarem #app-content erreichen?
//    Mit opacity (statt visibility) ja; gemeldet als INFO, nicht rot (Anforderung: kein Fokus/Klick ODER Phase < 1 s).
import { runSpec } from './lib/harness.mjs';

const PAGES = ['', '', '', 'diary.php', 'tasks.php', 'dashboard.php', 'settings.php', 'trash.php', 'list_tags.php', 'notes_manager.php'];
const DELAY_MS = 1500;

const INIT = () => {
	const s = { seen: null, opacityAtSeen: null, ready: null, dcl: null, clicks: [], focus: [] };
	window.__nnFlicker = s;
	const check = () => {
		if (s.seen === null) {
			const el = document.getElementById('app-content');
			if (el) { s.seen = performance.now(); s.opacityAtSeen = getComputedStyle(el).opacity; }
		}
		if (s.ready === null && document.documentElement.classList.contains('nextnotes-ready')) s.ready = performance.now();
	};
	new MutationObserver(check).observe(document, { childList: true, subtree: true, attributes: true, attributeFilter: ['class'] });
	document.addEventListener('DOMContentLoaded', () => { s.dcl = performance.now(); check(); }, true);
	const hidden = (t) => {
		const ac = document.getElementById('app-content');
		return !!(ac && t instanceof Node && ac.contains(t) && getComputedStyle(ac).opacity === '0');
	};
	window.addEventListener('click', (e) => { if (hidden(e.target)) s.clicks.push(e.target.tagName + (e.target.id ? '#' + e.target.id : '')); }, true);
	window.addEventListener('focusin', (e) => { if (hidden(e.target)) s.focus.push(e.target.tagName + (e.target.id ? '#' + e.target.id : '')); }, true);
};

runSpec(import.meta.url, async (ctx, page, context) => {
	await context.addInitScript(INIT);
	// ab hier prüfen: die NC-Startseite nach der Anmeldung (Empfehlungs-Widget, Vorschau 404) gehört nicht zur App
	const m0 = ctx.mark();

	// A) Phase je Seite
	let indexUrl = null;
	for (const p of PAGES) {
		await page.goto(ctx.appUrl(p), { waitUntil: 'load' });
		await page.waitForFunction(() => window.__nnFlicker?.ready !== null, null, { timeout: 15000 }).catch(() => {});
		indexUrl ??= page.url();
		const s = await page.evaluate(() => window.__nnFlicker);
		const phase = s.seen !== null && s.ready !== null ? Math.round(s.ready - s.seen) : null;
		const op = await page.evaluate(() => getComputedStyle(document.getElementById('app-content')).opacity);
		ctx.ok(phase !== null && phase < 1000, `A ${p || 'index'}: Phase opacity 0 < 1 s`, `Phase ${phase} ms, opacity bei Erscheinen ${s.opacityAtSeen}, danach ${op}`);
		console.log(`  INFO A ${p || 'index'}: seen ${Math.round(s.seen)} ms, ready ${Math.round(s.ready)} ms, Phase ${phase} ms`);
		ctx.ok(op === '1', `A ${p || 'index'}: nach Start opacity 1`, `opacity ${op}`);
	}

	// B) verlängerte Phase: nextnotes-late/-app um DELAY_MS verzögern (DOMContentLoaded wartet auf defer-Skripte)
	let hits = 0;
	await page.route(/\/nextnotes\/js\/nextnotes-late\.js/, async (route) => { hits++; await new Promise((r) => setTimeout(r, DELAY_MS)); await route.continue(); });
	console.log(`  INFO B URL ${indexUrl}`);
	const nav = page.goto(indexUrl, { waitUntil: 'load' });
	await page.waitForFunction(() => {
		const el = document.getElementById('app-content');
		return el && document.querySelector('#app-content .note-title, #app-content button, #app-content a') && getComputedStyle(el).opacity === '0';
	}, null, { timeout: 10000, polling: 20 });
	const t0 = Date.now();
	for (let i = 0; i < 15; i++) await page.keyboard.press('Tab');
	const target = await page.evaluate(() => {
		const el = [...document.querySelectorAll('#app-content button, #app-content [data-action]:not(a)')].find((e) => { const r = e.getBoundingClientRect(); return r.width > 4 && r.height > 4 && r.top > 0 && r.top < innerHeight; });
		if (!el) return null;
		const r = el.getBoundingClientRect();
		return { x: r.left + r.width / 2, y: r.top + r.height / 2, d: el.tagName + (el.id ? '#' + el.id : '') + (el.className ? '.' + String(el.className).split(' ')[0] : '') };
	});
	let navs = 0;
	page.on('framenavigated', (f) => { if (f === page.mainFrame()) navs++; });
	const pre = await page.evaluate(() => ({ focus: [...window.__nnFlicker.focus], active: document.activeElement?.tagName + (document.activeElement?.id ? '#' + document.activeElement.id : ''), op: getComputedStyle(document.getElementById('app-content')).opacity }));
	console.log(`  INFO B nach 15× Tab: activeElement ${pre.active}, opacity ${pre.op}, Fokus in unsichtbarem #app-content ${pre.focus.length} (${pre.focus.slice(0, 5).join(', ')})`);
	if (target) await page.mouse.click(target.x, target.y);
	const stillHidden = await page.evaluate(() => getComputedStyle(document.getElementById('app-content')).opacity === '0').catch(() => 'Navigation');
	console.log(`  INFO B Navigationen nach Klick: ${navs}, verzögerte Skriptabrufe: ${hits}`);
	const elapsed = Date.now() - t0;
	await nav;
	const s = await page.evaluate(() => window.__nnFlicker);
	console.log(`  INFO B (Skript +${DELAY_MS} ms): Phase ${Math.round(s.ready - s.seen)} ms; Interaktion in ${elapsed} ms, danach noch opacity 0: ${stillHidden}`);
	console.log(`  INFO B Fokus in unsichtbarem #app-content: ${s.focus.length} (${s.focus.slice(0, 5).join(', ')})`);
	console.log(`  INFO B Klick in unsichtbarem #app-content: ${s.clicks.length} (${s.clicks.join(', ')}) Ziel ${target?.d ?? '-'}`);
	await page.unroute(/\/nextnotes\/js\/nextnotes-late\.js/);
	ctx.ok(true, 'B ausgeführt (Information)');
	ctx.assertClean('anti-flicker gesamt', m0);
});
