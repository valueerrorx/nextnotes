// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// M9a (Plan M9 §9 M9a, ADR 0020): Admin-Karte OneNote-Import und Verbinden/Callback gegen den Mock (Profil msmock, dev/nextcloud/msmock.sh up).
// Der Browser erreicht login.microsoftonline.com nie: page.route beantwortet die Autorisierung mit einem 302 auf den Callback (Code für den Mock);
// den Code löst Nextcloud serverseitig beim Mock ein. Danach Log-Suche nach Token, Code und Secret (Done 6).
import { execFileSync } from 'node:child_process';
import { runSpec } from './lib/harness.mjs';
import { ncContainer } from './lib/docker.mjs';

const RUN = `${process.argv[3]}${Date.now().toString(36)}`;
const TENANT = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
const CLIENT = '11111111-2222-3333-4444-555555555555';
const SECRET = `E2E-Secret-${RUN}`;
const CODE = `mock.e2e-${RUN}`.replace(/[^A-Za-z0-9._-]/g, '');

await runSpec(import.meta.url, async (ctx, page, context, browser) => {
	const nc = ncContainer(ctx.base);
	const sh = (cmd) => execFileSync('docker', ['exec', '-u', 'www-data', nc, 'sh', '-c', cmd], { encoding: 'utf8' }).trim();
	const occ = (...a) => execFileSync('docker', ['exec', '-u', 'www-data', nc, 'php', 'occ', ...a], { encoding: 'utf8' }).trim();
	const mockUp = sh('curl -fsS --max-time 5 "http://ms-mock/__ctl?op=reset" || echo down');
	if (!mockUp.includes('ok')) throw new Error('Mock nicht erreichbar: dev/nextcloud/msmock.sh <env> up');

	// Autorisierung: nie ins Netz; Antwort wie Microsoft (302 auf redirect_uri mit code/state bzw. error)
	let authorizeMode = 'ok';
	const seen = [];
	await context.route(/^https:\/\/login\.microsoftonline\.com\//, (route) => {
		const u = new URL(route.request().url());
		seen.push(u);
		const back = new URL(u.searchParams.get('redirect_uri'));
		back.searchParams.set('state', u.searchParams.get('state') ?? '');
		if (authorizeMode === 'ok') back.searchParams.set('code', CODE);
		else { back.searchParams.set('error', 'access_denied'); back.searchParams.set('error_description', 'AADSTS-E2E-DESCRIPTION'); }
		return route.fulfill({ status: 302, headers: { Location: back.toString() } });
	});

	// A. Admin-Karte: Werte speichern, Secret nie im HTML, Redirect-URI sichtbar, common deaktiviert den Team-Schalter
	{
		const c2 = await browser.newContext({ viewport: { width: 1400, height: 900 }, locale: 'de-DE' });
		await ctx.attach(c2);
		const p2 = await c2.newPage();
		await ctx.login(p2, 'admin', 'admin');
		const m = ctx.mark();
		await p2.goto(`${ctx.base}/index.php/settings/admin/nextnotes`, { waitUntil: 'networkidle' });
		const redirect = await p2.inputValue('#nextnotes-onenote-redirect');
		ctx.ok(/\/apps\/nextnotes\/onenote\/callback$/.test(redirect), 'A: Redirect-URI angezeigt', redirect);
		await p2.fill('#nextnotes-onenote-authority', 'common');
		ctx.ok(await p2.isDisabled('#nextnotes-onenote-teams') && await p2.isVisible('#nextnotes-onenote-teams-hint'), 'A: common deaktiviert Team-Schalter mit Hinweis');
		await p2.fill('#nextnotes-onenote-authority', 'keine gültige authority');
		const bad = p2.waitForResponse((r) => r.url().endsWith('/admin/settings'));
		const off = ctx.allow('status', /^400 POST .*\/apps\/nextnotes\/admin\/settings$/, 'erwartet: ungültige Authority');
		const off2 = ctx.allow('console', /status of 400 .*\/admin\/settings/, 'erwartet: Browser meldet 400');
		await p2.click('#nextnotes-admin-save');
		ctx.ok((await bad).status() === 400, 'A: ungültige Authority → 400');
		await p2.waitForTimeout(500);
		off(); off2();
		await p2.fill('#nextnotes-onenote-authority', TENANT);
		ctx.ok(!(await p2.isDisabled('#nextnotes-onenote-teams')), 'A: Tenant-ID: Team-Schalter aktiv');
		await p2.fill('#nextnotes-onenote-client', CLIENT);
		await p2.fill('#nextnotes-onenote-secret', SECRET);
		const ok = p2.waitForResponse((r) => r.url().endsWith('/admin/settings'));
		await p2.click('#nextnotes-admin-save');
		const res = await ok;
		ctx.ok(res.status() === 200, 'A: gespeichert');
		ctx.ok(!(await res.text()).includes(SECRET), 'A: Antwort ohne Secret');
		await p2.waitForTimeout(300);
		ctx.ok(await p2.inputValue('#nextnotes-onenote-secret') === '', 'A: Secret-Feld nach dem Speichern leer');
		await p2.reload({ waitUntil: 'networkidle' });
		ctx.ok(!(await p2.content()).includes(SECRET), 'A: Secret nicht im HTML');
		ctx.ok(await p2.getAttribute('#nextnotes-onenote-secret-state', 'data-set') === '1', 'A: Anzeige „gesetzt“');
		await ctx.screenshot(p2, 'admin');
		ctx.assertClean('A Admin', m);
		await c2.close();
	}

	await page.waitForTimeout(4000); // AppConfig-Lokalcache des Webservers
	const view = ctx.appUrl('onenote.php');

	// B. Verbinden → (Mock) → Callback → Status „angemeldet“; Abbrechen
	{
		const m = ctx.mark();
		await page.goto(view, { waitUntil: 'networkidle' });
		await page.click('#nextnotes-onenote button[data-action="connect"]');
		await page.waitForURL(/onenote\.php\?import=\d+$/, { timeout: 15000 });
		await page.waitForLoadState('networkidle');
		const auth = seen.at(-1);
		ctx.ok(auth?.pathname === `/${TENANT}/oauth2/v2.0/authorize`, 'B: Top-Level-Navigation zur Autorisierung', auth?.href);
		ctx.ok(auth?.searchParams.get('code_challenge_method') === 'S256' && auth?.searchParams.get('response_mode') === 'query', 'B: PKCE S256, response_mode=query');
		ctx.ok(auth?.searchParams.get('scope') === 'openid offline_access Notes.Read', 'B: Schalter aus → Basis-Scopes', auth?.searchParams.get('scope'));
		ctx.ok(await page.getAttribute('#nextnotes-onenote-status', 'data-status') === 'select', 'B: Status select nach dem Callback');
		ctx.ok(!page.url().includes('code='), 'B: Code nicht in der Adresse (303)');
		await ctx.screenshot(page, 'connected');
		await page.click('#nextnotes-onenote button[data-action="cancel"]');
		await page.waitForFunction(() => document.getElementById('nextnotes-onenote-status')?.dataset.status === 'cancelled', null, { timeout: 10000 }).catch(() => {});
		await page.waitForLoadState('networkidle');
		ctx.ok(await page.getAttribute('#nextnotes-onenote-status', 'data-status') === 'cancelled', 'B: abgebrochen, neu verbinden möglich');
		ctx.assertClean('B Verbinden', m);
	}

	// C. Abbruch bei Microsoft (error=access_denied mit gültigem state) → feste Meldung, keine Beschreibung
	{
		const m = ctx.mark();
		authorizeMode = 'denied';
		await page.click('#nextnotes-onenote button[data-action="connect"]');
		await page.waitForURL(/onenote\.php\?msg=cancelled$/, { timeout: 15000 }).catch(async (e) => {
			throw new Error(`${e.message}\nURL ${page.url()} Fehler „${await page.innerText('#nextnotes-onenote-error').catch(() => '?')}“`);
		});
		const text = await page.innerText('#nextnotes-onenote-message');
		ctx.ok(/abgebrochen|cancelled/i.test(text), 'C: Meldung „Anmeldung abgebrochen“', text);
		ctx.ok(!(await page.content()).includes('AADSTS'), 'C: error_description nicht angezeigt');
		await ctx.screenshot(page, 'denied');
		ctx.assertClean('C Abbruch', m);
	}

	// D. Callback ohne gültigen state → 404
	{
		const off = ctx.allow('status', /^404 GET .*\/onenote\/callback\?/, 'erwartet: ungültiger state');
		const off2 = ctx.allow('console', /status of 404/, 'erwartet: Browser meldet 404');
		const r = await page.goto(ctx.appUrl('onenote/callback?state=falsch&error=access_denied'));
		ctx.ok(r.status() === 404, `D: falscher state → 404 (${r.status()})`);
		off(); off2();
	}

	// E. Done 6: nextcloud.log ohne Token, ID-Token, Code und Secret
	const log = sh('cat "$(php occ config:system:get datadirectory)/nextcloud.log" 2>/dev/null || true');
	for (const needle of ['msmock-access-', 'msmock-refresh-', CODE, SECRET, 'eyJ', 'AADSTS']) {
		ctx.ok(!log.includes(needle), `E: Log ohne ${needle.slice(0, 16)}`);
	}
	occ('config:app:delete', 'nextnotes', 'onenote_secret');
});
