// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// Nur Tests: echter Browser-Login, App-Seite öffnen, CSP-Verletzungen zählen (Done M1.4).
// Aufruf: node scripts/check-csp.mjs <base-url> <user> <pass> <pfad>…
import { chromium } from 'playwright-core';

const [base, user, pass, ...paths] = process.argv.slice(2);
if (!base || !user || !pass || paths.length === 0) {
	console.error('Aufruf: check-csp.mjs <base-url> <user> <pass> <pfad>…');
	process.exit(2);
}

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM ?? '/usr/bin/chromium', headless: true });
let failed = false;
try {
	const context = await browser.newContext();
	const violations = [];
	await context.exposeFunction('__nnCsp', (v) => violations.push(v));
	await context.addInitScript(() => {
		document.addEventListener('securitypolicyviolation', (e) => {
			window.__nnCsp(`${e.violatedDirective} ${e.blockedURI}`);
		});
	});
	const page = await context.newPage();
	page.on('console', (msg) => {
		if (msg.type() === 'error' && /Content[- ]Security[- ]Policy/i.test(msg.text())) {
			violations.push(`console: ${msg.text()}`);
		}
	});

	await page.goto(`${base}/index.php/login`);
	await page.fill('input[name="user"]', user);
	await page.fill('input[name="password"]', pass);
	await Promise.all([
		page.waitForURL((u) => !u.pathname.includes('/login'), { timeout: 30000 }),
		page.click('button[type="submit"]'),
	]);

	for (const path of paths) {
		violations.length = 0;
		const resp = await page.goto(`${base}${path}`, { waitUntil: 'networkidle' });
		const root = await page.locator('#nextnotes-root').count();
		const status = resp?.status();
		if (status !== 200 || root !== 1) {
			console.error(`FEHLER ${path}: HTTP ${status}, #nextnotes-root ${root}`);
			failed = true;
		}
		if (violations.length > 0) {
			console.error(`FEHLER ${path}: ${violations.length} CSP-Verletzung(en)\n  ${violations.join('\n  ')}`);
			failed = true;
		} else {
			console.log(`${path}: 0 CSP-Verletzungen`);
		}
	}

	// Selbsttest: der Detektor muss eine absichtlich verbotene Quelle melden
	violations.length = 0;
	await page.evaluate(() => { const i = new Image(); i.src = 'https://csp-selftest.invalid/x.png'; document.body.append(i); });
	await page.waitForTimeout(1000);
	if (violations.length === 0) {
		console.error('FEHLER: CSP-Selbsttest meldet keine Verletzung, Detektor unwirksam');
		failed = true;
	}
} finally {
	await browser.close();
}
process.exit(failed ? 1 : 0);
