// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// Nur Tests: Browser-Probe M3c2 – Slash-Befehl Excalidraw, Editor-Seite, Zeichnen + Speichern, Vorschau in der Notiz,
// Lesefreigabe (Ansicht ohne Speichern); CSP, Konsole, pageerror, Antworten ≥ 400.
// Aufruf: node scripts/probe-m3c2-excalidraw.mjs <base-url> <user> <pass> <note-id> [light|dark] [reader-user reader-pass]
import { chromium } from 'playwright-core';

const [base, user, pass, noteId, theme = 'light', reader, readerPass] = process.argv.slice(2);
if (!noteId) {
	console.error('Aufruf: probe-m3c2-excalidraw.mjs <base-url> <user> <pass> <note-id> [light|dark] [reader-user reader-pass]');
	process.exit(2);
}
const app = `${base}/index.php/apps/nextnotes`;
const out = process.env.OUT ?? '/tmp';
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM ?? '/usr/bin/chromium', headless: true });
let failed = false;
const ok = (cond, label, detail = '') => {
	console.log(`${cond ? 'ok  ' : 'FAIL'} ${label}${cond || !detail ? '' : ` – ${detail}`}`);
	if (!cond) failed = true;
};

async function session(u, p) {
	const context = await browser.newContext({ colorScheme: theme === 'dark' ? 'dark' : 'light', viewport: { width: 1280, height: 900 } });
	const events = [];
	await context.exposeFunction('__nnCsp', (v) => events.push(`csp ${v}`));
	await context.addInitScript(() => document.addEventListener('securitypolicyviolation', (e) => window.__nnCsp(`${e.violatedDirective} ${e.blockedURI}`)));
	const page = await context.newPage();
	page.on('console', (m) => { if (m.type() === 'error') events.push(`console ${m.text()}`); });
	page.on('pageerror', (e) => events.push(`pageerror ${e.message}`));
	page.on('response', (r) => { if (r.status() >= 400) events.push(`http ${r.status()} ${r.url()}`); });
	page.on('dialog', (d) => { events.push(`dialog ${d.message()}`); d.dismiss().catch(() => {}); });
	await page.goto(`${base}/index.php/login`);
	await page.fill('input[name="user"]', u);
	await page.fill('input[name="password"]', p);
	await Promise.all([page.waitForURL((x) => !x.pathname.includes('/login'), { timeout: 30000 }), page.click('button[type="submit"]')]);
	const clean = (label) => { ok(events.length === 0, `${label}: 0 CSP/Konsole/pageerror/≥400`, events.slice(0, 6).join(' | ')); events.length = 0; };
	return { context, page, events, clean };
}

try {
	const { context, page, events, clean } = await session(user, pass);
	await page.goto(`${app}/index.php?note=${noteId}`, { waitUntil: 'networkidle' });
	const entry = `#entry${noteId}`;
	await page.waitForSelector(entry);
	events.length = 0;

	// Slash-Befehl: Menüpunkt sichtbar, öffnet den Editor
	// Cursor ans Ende des Notiztexts (ein Klick träfe bei wiederholten Läufen ein Diagramm)
	await page.evaluate((sel) => {
		const el = document.querySelector(sel);
		el.focus();
		const r = document.createRange();
		r.selectNodeContents(el);
		r.collapse(false);
		getSelection().removeAllRanges();
		getSelection().addRange(r);
	}, entry);
	await page.keyboard.press('Enter');
	await page.keyboard.type('/excali');
	// erst Enter, wenn der Filter nur noch Excalidraw zeigt und es ausgewählt ist
	const item = await page.waitForFunction(() => {
		const menu = document.querySelector('.slash-command-menu.show');
		const sel = menu?.querySelector('.selected');
		return !!menu && menu.innerText.trim() === 'Excalidraw' && !!sel && /excalidraw/i.test(sel.textContent || '');
	}, null, { timeout: 5000 }).then(() => true, () => false);
	ok(item, 'Slash-Menü zeigt Excalidraw');
	const nav = page.waitForURL((u) => u.pathname.endsWith('/excalidraw_editor.php'), { timeout: 15000 }).then(() => true, () => false);
	await page.keyboard.press('Enter');
	const opened = await nav;
	const popup = opened ? '' : await page.evaluate(() => [...document.querySelectorAll('.modal, [role="dialog"], .pz-toast, [class*="popup"], [class*="alert"]')]
		.filter((e) => e.offsetParent !== null).map((e) => e.innerText.trim().slice(0, 160)).filter(Boolean).join(' | '));
	ok(opened, 'Slash-Befehl öffnet excalidraw_editor.php', `URL ${page.url()} Meldung: ${popup} Ereignisse: ${events.slice(0, 4).join(' | ')}`);
	clean('Notiz vor dem Editor');

	// Editor: Zeichenfläche, Theme, Zeichnen, Speichern und zurück
	const canvas = await page.waitForSelector('.excalidraw canvas.interactive, .excalidraw canvas', { timeout: 20000 }).then(() => true, () => false);
	ok(canvas, 'Editor: Zeichenfläche geladen');
	await page.waitForTimeout(1500);
	const info = await page.evaluate(() => {
		const tb = document.querySelector('.poznote-toolbar')?.getBoundingClientRect();
		const hd = document.querySelector('#header')?.getBoundingClientRect();
		return { dark: document.querySelector('.excalidraw')?.classList.contains('theme--dark') ?? null, toolbarTop: tb?.top, headerBottom: hd?.bottom, appH: document.getElementById('app')?.getBoundingClientRect().height };
	});
	ok(info.dark === (theme === 'dark'), `Editor: Theme ${theme} (theme--dark=${info.dark})`);
	ok(info.toolbarTop >= (info.headerBottom ?? 0) - 1 && info.appH > 300, `Editor: Leiste unter dem NC-Kopf (Leiste ${info.toolbarTop}, Kopf ${info.headerBottom}, Fläche ${info.appH})`);
	await page.screenshot({ path: `${out}/probe-m3c2-editor-${theme}.png` }).catch(() => {});
	clean('Editor geladen');
	await page.click('[data-testid="toolbar-rectangle"]', { force: true });
	await page.mouse.move(500, 400);
	await page.mouse.down();
	await page.mouse.move(700, 550, { steps: 8 });
	await page.mouse.up();
	await page.waitForTimeout(800);
	const back = page.waitForURL((u) => u.pathname.endsWith('/index.php') && u.searchParams.get('note') === String(noteId), { timeout: 20000 }).then(() => true, () => false);
	await page.click('#saveAndExitBtn');
	ok(await back, 'Speichern und schließen kehrt zur Notiz zurück');
	await page.waitForLoadState('networkidle');
	const img = await page.waitForFunction((sel) => {
		const i = document.querySelector(`${sel} .excalidraw-container img`);
		return i && i.complete && i.naturalWidth > 0;
	}, entry, { timeout: 15000 }).then(() => true, () => false);
	ok(img, 'Notiz zeigt die Vorschau des Diagramms');
	const drawn = await page.evaluate((sel) => {
		const box = [...document.querySelectorAll(`${sel} .excalidraw-container`)].pop();
		const data = JSON.parse(box?.getAttribute('data-excalidraw') || '{}');
		return (data.elements || []).filter((e) => e.type === 'rectangle' && !e.isDeleted).length;
	}, entry);
	ok(drawn === 1, `gespeichertes Diagramm enthält das Rechteck (${drawn})`);
	await page.screenshot({ path: `${out}/probe-m3c2-note-${theme}.png` }).catch(() => {});
	clean('Notiz nach dem Speichern');
	// pagehide gibt die Sperre frei (sonst blockiert sie den nächsten Lauf 90 s)
	await page.goto('about:blank');
	await context.close();

	// Lesefreigabe: nur ansehen
	if (reader) {
		const r = await session(reader, readerPass);
		const diagram = await (async () => {
			await r.page.goto(`${app}/index.php?note=${noteId}`, { waitUntil: 'networkidle' });
			return r.page.evaluate((sel) => document.querySelector(`${sel} .excalidraw-container`)?.id ?? '', entry);
		})();
		r.events.length = 0;
		const resp = await r.page.goto(`${app}/excalidraw_editor.php?note_id=${noteId}&diagram_id=${encodeURIComponent(diagram)}`, { waitUntil: 'networkidle' });
		await r.page.waitForSelector('.excalidraw canvas', { timeout: 20000 }).catch(() => {});
		await r.page.waitForTimeout(1500);
		const ro = await r.page.evaluate(() => ({
			save: !!document.getElementById('saveBtn') || !!document.getElementById('saveAndExitBtn'),
			tools: !!document.querySelector('.App-toolbar'),
			view: !!document.querySelector('.excalidraw.excalidraw--view-mode'),
		}));
		ok(resp?.status() === 200 && diagram !== '', `Lesefreigabe: Editor HTTP ${resp?.status()} (Diagramm ${diagram})`);
		ok(!ro.save && (ro.view || !ro.tools), `Lesefreigabe: ohne Speichern, Ansichtsmodus (view=${ro.view}, Werkzeuge=${ro.tools})`);
		await r.page.screenshot({ path: `${out}/probe-m3c2-readonly-${theme}.png` }).catch(() => {});
		r.clean('Lesefreigabe Editor');
		await r.page.goto('about:blank');
		await r.context.close();
	}
} finally {
	await browser.close();
}
console.log(`probe-m3c2-excalidraw (${theme}): ${failed ? 'ROT' : 'grün'}`);
process.exit(failed ? 1 : 0);
