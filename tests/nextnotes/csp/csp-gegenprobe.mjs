// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// Tester-Gegenprobe Done M1.4: unabhängig von dev/nextcloud/scripts/check-csp.mjs.
// 1) App-Seite hat einen CSP-Header, 2) ohne Eingriff 0 Verletzungen,
// 3) jede absichtlich provozierte Verletzung (externes Bild, Inline-Script, eval) wird erkannt.
// Aufruf: node tests/nextnotes/csp/csp-gegenprobe.mjs <base-url> <user> <pass> [pfad]
// playwright-core wird aus dev/nextcloud/node_modules geladen (PW_CORE= überschreibt).
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const here = path.dirname(fileURLToPath(import.meta.url));
const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PW_CORE ?? path.join(here, '../../../dev/nextcloud/node_modules/playwright-core'));

const [base, user, pass, appPath = '/index.php/apps/nextnotes/'] = process.argv.slice(2);
if (!base || !user || !pass) {
	console.error('Aufruf: csp-gegenprobe.mjs <base-url> <user> <pass> [pfad]');
	process.exit(2);
}

let fail = 0;
const check = (ok, msg) => { console.log(`${ok ? 'PASS' : 'FAIL'} ${msg}`); if (!ok) fail++; };

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM ?? '/usr/bin/chromium', headless: true });
try {
	const ctx = await browser.newContext();
	const page = await ctx.newPage();
	const seen = [];
	page.on('console', (m) => { if (/Content[- ]Security[- ]Policy/i.test(m.text())) seen.push(m.text()); });

	await page.goto(`${base}/index.php/login`);
	await page.fill('input[name="user"]', user);
	await page.fill('input[name="password"]', pass);
	await Promise.all([page.waitForURL((u) => !u.pathname.includes('/login'), { timeout: 30000 }), page.click('button[type="submit"]')]);

	const bad = [];
	page.on('response', (r) => { if (r.status() >= 400) bad.push(`${r.status()} ${r.url()}`); });
	const resp = await page.goto(`${base}${appPath}`, { waitUntil: 'networkidle' });
	const csp = (await resp.allHeaders())['content-security-policy'] ?? '';
	check(resp.status() === 200, `HTTP ${resp.status()} ${appPath}`);
	check(/script-src/.test(csp), `CSP-Header mit script-src: ${csp.match(/script-src[^;]*/)?.[0] ?? 'fehlt'}`);
	check(await page.locator('#nextnotes-root').count() === 1, '#nextnotes-root vorhanden');
	await page.waitForTimeout(1000);
	if (bad.length) console.log(`INFO Antworten >=400 beim Laden: ${bad.join(', ')}`);
	check(seen.length === 0, `ohne Eingriff 0 CSP-Konsolenmeldungen (${seen.length})${seen.length ? '\n  ' + seen.join('\n  ') : ''}`);

	const provoke = async (name, fn) => {
		seen.length = 0;
		await page.evaluate(fn).catch(() => {});
		await page.waitForTimeout(1000);
		check(seen.length > 0, `Provokation „${name}“ erkannt (${seen.length} Meldung(en))`);
	};
	await provoke('externes Bild', () => { const i = new Image(); i.src = 'https://gegenprobe.invalid/x.png'; document.body.append(i); });
	// String-Code (CDP-evaluate selbst ist von CSP ausgenommen, setTimeout-String nicht)
	await provoke('setTimeout-String (unsafe-eval)', () => { setTimeout('window.__nnEval=1', 0); });
	check(await page.evaluate(() => window.__nnEval) === undefined, 'String-Code wurde blockiert');

	// Parser-eingefügtes Inline-Script ohne Nonce (DOM-Einfügung wäre wegen strict-dynamic erlaubt)
	await page.route(`${base}${appPath}`, async (route) => {
		const r = await route.fetch();
		const body = (await r.text()).replace('</body>', '<script>window.__nnInline=1</script></body>');
		await route.fulfill({ response: r, body });
	});
	seen.length = 0;
	await page.goto(`${base}${appPath}`, { waitUntil: 'networkidle' });
	await page.waitForTimeout(1000);
	check(seen.length > 0, `Provokation „Inline-Script im HTML“ erkannt (${seen.length} Meldung(en))`);
	check(await page.evaluate(() => window.__nnInline) === undefined, 'Inline-Script wurde blockiert');
	await page.unroute(`${base}${appPath}`);
} finally {
	await browser.close();
}
console.log(fail ? `${fail} Prüfung(en) FAIL` : 'Gegenprobe CSP OK');
process.exit(fail ? 1 : 0);
