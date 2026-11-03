// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// Gemeinsames Gerüst der Playwright-Specs (M3-Plan §3.2). Aufruf je Spec: node <spec>.mjs <base-url> <light|dark>
// Umgebung: NN_USER/NN_PASS (NN_USER2/NN_PASS2), PW_CORE (playwright-core), CHROMIUM. Gesetzt von dev/nextcloud/run-e2e.sh.
//
// Überwacht je Browser-Kontext: securitypolicyviolation, pageerror, Konsolenfehler, same-origin-Antworten >= 400,
// Requests auf /api/v1/… oder /audio_player.php ohne App-Präfix, Requests auf …/system/i18n.
// Jede Abweichung ist ein Fehler, außer sie steht mit Begründung in ctx.allow().
import { mkdirSync } from 'node:fs';
import { dirname, join, basename } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
export const OUT_DIR = join(HERE, '..', 'out');
export const REPO_ROOT = join(HERE, '..', '..', '..', '..');
export const APP_SEGMENT = '/apps/nextnotes/';

const pw = await import(pathToFileURL(join(process.env.PW_CORE ?? join(REPO_ROOT, 'dev/nextcloud/node_modules/playwright-core'), 'index.mjs')).href);

/** Pfad ohne App-Präfix, der zu Upstream gehört (Krit. 3) */
export function isUnprefixedUpstreamPath(pathname) {
	// Fremde App-/OCS-Routen (z. B. /ocs/v2.php/apps/dashboard/api/v1/…) gehören nicht zu Upstream
	if (pathname.includes(APP_SEGMENT) || /\/apps\/[^/]+\//.test(pathname)) return false;
	return /(^|\/)api\/v1\//.test(pathname) || /\/audio_player\.php$/.test(pathname);
}

class Ctx {
	constructor(base, theme, specName) {
		this.base = base.replace(/\/$/, '');
		this.theme = theme;
		this.spec = specName;
		this.failures = [];
		this.passes = 0;
		this.allowRules = [];
		this.events = []; // {kind, text, url?, status?}
		this.requests = []; // {method, url, type, page}
		this.responses = []; // {method, url, status, type, size?}
	}

	ok(cond, msg, detail = '') {
		if (cond) {
			this.passes++;
			console.log(`  PASS ${msg}`);
		} else {
			this.failures.push(`${msg}${detail ? ` – ${detail}` : ''}`);
			console.log(`  FAIL ${msg}${detail ? ` – ${detail}` : ''}`);
		}
		return cond;
	}

	info(msg) { console.log(`  INFO ${msg}`); }

	/** Erwartete Abweichung zulassen: kind in csp|pageerror|console|status|unprefixed|i18n, re auf Text/URL, Begründung Pflicht */
	allow(kind, re, reason) {
		if (!reason) throw new Error('allow() ohne Begründung');
		const rule = { kind, re, reason };
		this.allowRules.push(rule);
		return () => { this.allowRules = this.allowRules.filter((r) => r !== rule); };
	}

	record(kind, text, extra = {}) {
		const allowed = this.allowRules.find((r) => r.kind === kind && r.re.test(text));
		this.events.push({ kind, text, allowed: allowed?.reason, ...extra });
	}

	/** Index für spätere Auswertung eines Abschnitts */
	mark() { return { e: this.events.length, q: this.requests.length, r: this.responses.length }; }

	/** Unerwartete Ereignisse seit Marke (bzw. seit Start) */
	problems(since = { e: 0 }) { return this.events.slice(since.e).filter((e) => !e.allowed); }

	/** Prüft, dass seit Marke nichts Unerlaubtes passiert ist (je Art eine Zeile) */
	assertClean(label, since = { e: 0 }) {
		const bad = this.problems(since);
		for (const kind of ['csp', 'pageerror', 'console', 'status', 'unprefixed', 'i18n']) {
			const list = bad.filter((e) => e.kind === kind);
			this.ok(list.length === 0, `${label}: 0 × ${kind}`, list.slice(0, 5).map((e) => e.text).join(' | '));
		}
	}

	async attach(context) {
		const self = this;
		await context.exposeBinding('__nnCsp', (_src, v) => self.record('csp', v));
		await context.addInitScript(() => {
			// Vor allen Seitenskripten: unveränderte Browser-Funktionen für Selbsttests sichern
			window.__nnRawFetch = window.fetch.bind(window);
			document.addEventListener('securitypolicyviolation', (e) => {
				window.__nnCsp(`${e.violatedDirective} ${e.blockedURI} @${e.sourceFile}:${e.lineNumber}`);
			});
		});
		const origin = new URL(this.base).origin;
		const hook = (page) => {
			page.on('pageerror', (err) => self.record('pageerror', String(err?.stack ?? err)));
			page.on('console', (msg) => {
				if (msg.type() === 'error') {
					const loc = msg.location();
					self.record('console', `${msg.text()} @${loc?.url ?? ''}`);
				}
			});
		};
		context.pages().forEach(hook);
		context.on('page', hook);
		context.on('request', (req) => {
			const url = new URL(req.url());
			self.requests.push({ method: req.method(), url: req.url(), type: req.resourceType() });
			if (url.origin !== origin) return;
			if (isUnprefixedUpstreamPath(url.pathname)) self.record('unprefixed', `${req.method()} ${req.url()}`);
			if (/\/system\/i18n(\/|$)/.test(url.pathname)) self.record('i18n', `${req.method()} ${req.url()}`);
		});
		context.on('response', (resp) => {
			const req = resp.request();
			const url = new URL(resp.url());
			self.responses.push({ method: req.method(), url: resp.url(), status: resp.status(), type: req.resourceType(), resp });
			if (url.origin === origin && resp.status() >= 400) self.record('status', `${resp.status()} ${req.method()} ${resp.url()}`, { status: resp.status() });
		});
	}

	async login(page, user = process.env.NN_USER, pass = process.env.NN_PASS) {
		await page.goto(`${this.base}/index.php/login`);
		await page.fill('input[name="user"]', user);
		await page.fill('input[name="password"]', pass);
		await Promise.all([
			page.waitForURL((u) => !u.pathname.includes('/login'), { timeout: 30000 }),
			page.click('button[type="submit"]'),
		]);
	}

	/** App-URL (Apache ohne pretty URLs: /index.php/apps/nextnotes/…) */
	appUrl(path = '') { return `${this.base}/index.php/apps/nextnotes/${path.replace(/^\//, '')}`; }

	async screenshot(page, name) {
		mkdirSync(OUT_DIR, { recursive: true });
		await page.screenshot({ path: join(OUT_DIR, `${this.spec}-${name}-${this.theme}.png`), fullPage: false });
	}

	/** Selbsttests der Detektoren (M3-Plan §3.2): CSP-Verletzung und Request ohne Präfix müssen erkannt werden */
	async selfTest(page) {
		const m = this.mark();
		await page.evaluate(() => { const i = new Image(); i.src = 'https://csp-selftest.invalid/x.png'; document.body.append(i); });
		const st = await page.evaluate(async () => (await window.__nnRawFetch('/api/v1/nn-selftest')).status);
		await page.waitForTimeout(800);
		const ev = this.events.slice(m.e);
		this.ok(ev.some((e) => e.kind === 'csp' && e.text.includes('csp-selftest.invalid')), 'Selbsttest: künstliche CSP-Verletzung erkannt');
		this.ok(ev.some((e) => e.kind === 'unprefixed' && e.text.includes('/api/v1/nn-selftest')), 'Selbsttest: Request ohne App-Präfix erkannt');
		this.ok(st === 404 && ev.some((e) => e.kind === 'status' && e.status === 404 && e.text.includes('nn-selftest')), 'Selbsttest: 404 erkannt', `HTTP ${st}`);
		// Selbsttest-Ereignisse nicht als Fehler werten
		for (const e of ev) e.allowed = 'Selbsttest';
	}
}

/**
 * Startet Browser, Kontext mit Überwachung, meldet an und ruft fn(ctx, page, context, browser).
 * Exit 0 nur ohne FAIL. Ungefangene Ausnahme = FAIL.
 */
export async function runSpec(specUrl, fn) {
	const [base, theme] = process.argv.slice(2);
	if (!base || !['light', 'dark'].includes(theme)) {
		console.error('Aufruf: node <spec>.mjs <base-url> <light|dark>');
		process.exit(2);
	}
	const name = basename(fileURLToPath(specUrl), '.mjs');
	const ctx = new Ctx(base, theme, name);
	console.log(`spec ${name} (${theme}) gegen ${base}`);
	const browser = await pw.chromium.launch({ executablePath: process.env.CHROMIUM ?? '/usr/bin/chromium', headless: true });
	try {
		const context = await browser.newContext({ viewport: { width: 1400, height: 900 }, locale: 'de-DE' });
		await ctx.attach(context);
		const page = await context.newPage();
		await ctx.login(page);
		await fn(ctx, page, context, browser);
	} catch (err) {
		ctx.failures.push(`Ausnahme: ${err?.stack ?? err}`);
		console.log(`  FAIL Ausnahme: ${err?.stack ?? err}`);
	} finally {
		await browser.close();
	}
	const allowed = ctx.events.filter((e) => e.allowed && e.allowed !== 'Selbsttest');
	for (const e of allowed) console.log(`  ERLAUBT ${e.kind}: ${e.text} (${e.allowed})`);
	if (ctx.failures.length > 0) {
		console.log(`spec ${name} (${theme}): ROT – ${ctx.failures.length} Fehler, ${ctx.passes} ok`);
		process.exit(1);
	}
	console.log(`spec ${name} (${theme}): grün – ${ctx.passes} Prüfungen`);
	process.exit(0);
}
