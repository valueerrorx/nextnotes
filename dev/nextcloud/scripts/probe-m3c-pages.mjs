// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// Nur Tests: Browser-Probe M3c1 – Revisionen, Anhänge, Kanban, Notiz-Info; CSP, Konsole, pageerror, Antworten ≥ 400.
// Aufruf: node scripts/probe-m3c-pages.mjs <base-url> <user> <pass> <note-id> <folder-id> [light|dark]
import { chromium } from 'playwright-core';

const [base, user, pass, noteId, folderId, theme = 'light'] = process.argv.slice(2);
if (!folderId) {
	console.error('Aufruf: probe-m3c-pages.mjs <base-url> <user> <pass> <note-id> <folder-id> [light|dark]');
	process.exit(2);
}
const app = `${base}/index.php/apps/nextnotes`;
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM ?? '/usr/bin/chromium', headless: true });
let failed = false;
const ok = (cond, label, detail = '') => {
	console.log(`${cond ? 'ok  ' : 'FAIL'} ${label}${cond || !detail ? '' : ` – ${detail}`}`);
	if (!cond) failed = true;
};
try {
	const context = await browser.newContext({ colorScheme: theme === 'dark' ? 'dark' : 'light' });
	const events = [];
	await context.exposeFunction('__nnCsp', (v) => events.push(`csp ${v}`));
	await context.addInitScript(() => document.addEventListener('securitypolicyviolation', (e) => window.__nnCsp(`${e.violatedDirective} ${e.blockedURI}`)));
	const page = await context.newPage();
	page.on('console', (m) => { if (m.type() === 'error') events.push(`console ${m.text()}`); });
	page.on('pageerror', (e) => events.push(`pageerror ${e.message}`));
	page.on('response', (r) => { if (r.status() >= 400) events.push(`http ${r.status()} ${r.url()}`); });

	await page.goto(`${base}/index.php/login`);
	await page.fill('input[name="user"]', user);
	await page.fill('input[name="password"]', pass);
	await Promise.all([page.waitForURL((u) => !u.pathname.includes('/login'), { timeout: 30000 }), page.click('button[type="submit"]')]);

	const visit = async (label, path, ready) => {
		events.length = 0;
		const resp = await page.goto(`${app}/${path}`, { waitUntil: 'networkidle' });
		const found = await page.waitForSelector(ready, { timeout: 10000 }).then(() => true, () => false);
		ok(resp?.status() === 200 && found, `${label}: HTTP ${resp?.status()}, ${ready} vorhanden`);
		const bg = await page.evaluate(() => getComputedStyle(document.querySelector('#nextnotes-root') ?? document.body).backgroundColor);
		console.log(`     Hintergrund #nextnotes-root: ${bg}`);
		await page.screenshot({ path: `${process.env.OUT ?? '/tmp'}/probe-m3c-${label}-${theme}.png` }).catch(() => {});
		return found;
	};
	const clean = (label) => ok(events.length === 0, `${label}: 0 CSP/Konsole/pageerror/≥400`, events.slice(0, 6).join(' | '));

	await visit('revisions', `revisions.php?note_id=${noteId}`, '#revisionsList .revisions-item, #revisionsList [role="option"], #revisionsEmpty:not([hidden])');
	clean('revisions');
	await visit('attachments', `attachments.php?note_id=${noteId}`, '#attachmentsList .attachment-card, #attachmentsList [data-action="download"]');
	clean('attachments');

	await visit('index', `index.php?note=${noteId}`, `#entry${noteId}`);
	clean('index');
	events.length = 0;
	await page.click(`[data-action="show-note-info"][data-note-id="${noteId}"]`);
	const infoOk = await page.waitForFunction(() => {
		const v = document.querySelector('#noteInfoModal [data-note-info="title"]');
		return v && v.textContent.trim() !== '' && !/…|\.\.\./.test(v.textContent);
	}, null, { timeout: 10000 }).then(() => true, () => false);
	ok(infoOk, 'Notiz-Info: Dialog gefüllt');
	clean('Notiz-Info');
	await page.click('#noteInfoModal [data-action="close-modal"]').catch(() => {});

	events.length = 0;
	await page.evaluate((fid) => window.openKanbanView(fid), folderId);
	const board = await page.waitForSelector('#kanban-view-container .kanban-card', { timeout: 10000 }).then(() => true, () => false);
	ok(board, 'Kanban: Board mit Karten in #right_col');
	await page.screenshot({ path: `${process.env.OUT ?? '/tmp'}/probe-m3c-kanban-${theme}.png` }).catch(() => {});
	clean('Kanban');

	events.length = 0;
	await page.goto(`${app}/index.php?note=${noteId}`, { waitUntil: 'networkidle' });
	const nav = page.waitForURL((u) => u.pathname.endsWith('/revisions.php'), { timeout: 10000 }).then(() => true, () => false);
	await page.evaluate((id) => document.querySelector(`.btn-snapshot[data-note-id="${id}"]`)?.click(), noteId);
	ok(await nav, 'Revisionen-Knopf öffnet revisions.php');
	events.length = 0;
	await page.goto(`${app}/index.php?note=${noteId}`, { waitUntil: 'networkidle' });
	const nav2 = page.waitForURL((u) => u.pathname.endsWith('/attachments.php'), { timeout: 10000 }).then(() => true, () => false);
	await page.evaluate((id) => document.querySelector(`.btn-attachment[data-note-id="${id}"]`)?.click(), noteId);
	ok(await nav2, 'Büroklammer öffnet attachments.php');
	await page.waitForLoadState('networkidle');
	clean('Navigation');
	// Sperre der Notiz freigeben (pagehide), sonst 409 beim nächsten Lauf innerhalb der TTL
	await page.goto('about:blank').catch(() => {});
} finally {
	await browser.close();
}
process.exit(failed ? 1 : 0);
