// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// M9b (Plan M9 §9 M9b): Einstellungen-Karte, Auswahl (eigene vorausgewählt), Start, Job per occ, Fortschritt und Ergebnis; Mobil 390 px.
// M9d: importierte Seite mit Bild (sichtbar) und PDF (Anhang herunterladbar).
// Gegen den Mock (Profil msmock, dev/nextcloud/msmock.sh up); Autorisierung per page.route wie onenote-connect.mjs.
import { execFileSync } from 'node:child_process';
import { runSpec } from './lib/harness.mjs';
import { ncContainer } from './lib/docker.mjs';
import { apiClient } from './lib/api.mjs';

const RUN = `${process.argv[3]}${Date.now().toString(36)}`;
const TENANT = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
const CLIENT = '11111111-2222-3333-4444-555555555555';
const SECRET = `E2E-Secret-${RUN}`;
const CODE = `mock.e2e-${RUN}`.replace(/[^A-Za-z0-9._-]/g, '');

await runSpec(import.meta.url, async (ctx, page, context) => {
	const nc = ncContainer(ctx.base);
	const sh = (cmd) => execFileSync('docker', ['exec', '-u', 'www-data', nc, 'sh', '-c', cmd], { encoding: 'utf8' }).trim();
	const occ = (...a) => execFileSync('docker', ['exec', '-u', 'www-data', nc, 'php', 'occ', ...a], { encoding: 'utf8' }).trim();
	if (!sh('curl -fsS --max-time 5 "http://ms-mock/__ctl?op=reset" || echo down').includes('ok')) throw new Error('Mock nicht erreichbar: dev/nextcloud/msmock.sh <env> up');
	const TAG = RUN.toLowerCase().replace(/[^a-z0-9]/g, '').slice(-12);
	sh(`curl -fsS --max-time 5 "http://ms-mock/__ctl?op=set&pages=3&res=png,pdf&res_n=1&tag=${TAG}" >/dev/null`);
	occ('config:app:set', 'nextnotes', 'onenote_authority', `--value=${TENANT}`);
	occ('config:app:set', 'nextnotes', 'onenote_client_id', `--value=${CLIENT}`);
	occ('config:app:set', 'nextnotes', 'onenote_secret', `--value=${SECRET}`, '--lazy', '--sensitive');
	occ('config:app:set', 'nextnotes', 'onenote_teams', '--value=false', '--type=boolean');
	await page.waitForTimeout(4000); // AppConfig-Lokalcache des Webservers

	await context.route(/^https:\/\/login\.microsoftonline\.com\//, (route) => {
		const u = new URL(route.request().url());
		const back = new URL(u.searchParams.get('redirect_uri'));
		back.searchParams.set('state', u.searchParams.get('state') ?? '');
		back.searchParams.set('code', CODE);
		return route.fulfill({ status: 302, headers: { Location: back.toString() } });
	});
	const view = ctx.appUrl('onenote.php');

	// offenen Import früherer Läufe abbrechen
	await page.goto(view, { waitUntil: 'networkidle' });
	if (await page.locator('#nextnotes-onenote button[data-action="cancel"]').count()) {
		await page.click('#nextnotes-onenote button[data-action="cancel"]');
		await page.waitForLoadState('networkidle');
	}

	// A. Einstellungen → Workspaces: Karte „Import aus OneNote“
	{
		const m = ctx.mark();
		await page.goto(ctx.appUrl('settings.php'), { waitUntil: 'networkidle' });
		const href = await page.getAttribute('#onenote-import-card', 'href');
		ctx.ok(/\/onenote\.php$/.test(href ?? ''), 'A: Karte verlinkt die Import-Ansicht', href);
		ctx.assertClean('A Karte', m);
	}

	// B. Verbinden → Auswahl: eigenes Notizbuch vorausgewählt, Abschnitte aufklappbar, Start
	let importId;
	{
		const m = ctx.mark();
		await page.goto(view, { waitUntil: 'networkidle' });
		await page.click('#nextnotes-onenote button[data-action="connect"]');
		await page.waitForURL(/onenote\.php\?import=\d+$/, { timeout: 15000 });
		importId = new URL(page.url()).searchParams.get('import');
		await page.waitForSelector('#nextnotes-onenote-tree li.nn-onenote-notebook', { timeout: 15000 });
		ctx.ok(await page.isChecked('#nextnotes-onenote-tree li[data-notebook="nb1"] > .nn-onenote-check input'), 'B: eigenes Notizbuch vorausgewählt');
		ctx.ok(await page.isHidden('#nextnotes-onenote-team-hint'), 'B: kein Team-Hinweis ohne Team-Notizbücher');
		await page.click('#nextnotes-onenote-tree li[data-notebook="nb1"] .nn-onenote-toggle');
		ctx.ok(await page.locator('#nextnotes-onenote-tree .nn-onenote-items li').count() === 2, 'B: Abschnitt und Gruppe der ersten Ebene');
		ctx.ok(await page.isDisabled('#nextnotes-onenote-tree .nn-onenote-items input >> nth=0'), 'B: ganzes Notizbuch sperrt Einzelauswahl');
		// abwählen → Start gesperrt; Abschnitt wählen → Teilauswahl
		await page.click('#nextnotes-onenote-tree li[data-notebook="nb1"] > .nn-onenote-check label');
		ctx.ok(await page.isDisabled('#nextnotes-onenote button[data-action="start"]'), 'B: ohne Auswahl kein Start');
		await page.click('#nextnotes-onenote-tree .nn-onenote-items li[data-item="g1"] label');
		ctx.ok(await page.evaluate(() => document.querySelector('#nextnotes-onenote-tree li[data-notebook="nb1"] > .nn-onenote-check input').indeterminate), 'B: Teilauswahl angezeigt');
		await page.click('#nextnotes-onenote-tree li[data-notebook="nb1"] > .nn-onenote-check label');
		await ctx.screenshot(page, 'select');
		await page.setViewportSize({ width: 390, height: 844 });
		const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
		ctx.ok(overflow <= 1, 'B: mobil 390 px ohne waagerechtes Scrollen', String(overflow));
		await ctx.screenshot(page, 'select-mobile');
		await page.setViewportSize({ width: 1400, height: 900 });
		const started = page.waitForResponse((r) => r.url().endsWith(`/imports/${importId}/start`));
		await page.click('#nextnotes-onenote button[data-action="start"]');
		ctx.ok((await started).status() === 200, 'B: Start 200');
		await page.waitForLoadState('networkidle');
		ctx.ok(await page.getAttribute('#nextnotes-onenote-status', 'data-status') === 'queued', 'B: Status wartet auf den Lauf');
		ctx.ok(await page.isVisible('#nextnotes-onenote-progress'), 'B: Fortschritt sichtbar');
		await ctx.screenshot(page, 'queued');
		ctx.assertClean('B Auswahl', m);
	}

	// C. Job ohne Sitzung (occ) → fertig mit Zählern
	{
		const m = ctx.mark();
		const job = occ('background-job:list').split('\n').find((l) => l.includes('OneNoteImportJob'))?.split('|')[1]?.trim();
		ctx.ok(/^\d+$/.test(job ?? ''), 'C: Job registriert', job);
		occ('background-job:execute', job, '--force-execute');
		await page.reload({ waitUntil: 'networkidle' });
		ctx.ok(await page.getAttribute('#nextnotes-onenote-status', 'data-status') === 'done', 'C: Import fertig');
		const done = Number(await page.innerText('[data-field="pages_done"]')) + Number(await page.innerText('[data-field="pages_skipped"]'));
		ctx.ok(done === 5, 'C: 5 Seiten importiert bzw. aus früherem Lauf übersprungen', String(done));
		await ctx.screenshot(page, 'done');
		await page.goto(ctx.appUrl('index.php'), { waitUntil: 'networkidle' });
		ctx.ok((await page.content()).includes('Mock-Buch'), 'C: neuer Workspace in der App');
		ctx.assertClean('C Job', m);
	}

	// E. M9d: Seite mit Bild (geladen) und PDF-Anhang (Download 200, PDF)
	{
		const m = ctx.mark();
		const api = apiClient(ctx, page.request);
		const found = (await api.must('GET', `/notes?search=${encodeURIComponent(`Inhalt p${TAG}-0`)}`)).notes ?? [];
		ctx.ok(found.length === 1, 'E: importierte Seite gefunden', JSON.stringify(found).slice(0, 160));
		const n = found[0] ?? {};
		const bad = [];
		page.on('response', (r) => { if (/\/attachments\/\d+/.test(r.url()) && r.status() >= 400) bad.push(`${r.status()} ${r.url()}`); });
		await page.goto(ctx.appUrl(`index.php?workspace=${encodeURIComponent(n.workspace)}&note=${n.id}`), { waitUntil: 'networkidle' });
		const img = page.locator(`#entry${n.id} img[src*="/attachments/"]`).first();
		await img.waitFor({ state: 'visible', timeout: 10000 }).catch(() => {});
		const loaded = (await img.count()) ? await img.evaluate((el) => el.complete && el.naturalWidth === 40) : false;
		ctx.ok(loaded, 'E: Bild sichtbar und geladen (40 px breit)');
		const link = page.locator(`#entry${n.id} a[href*="/attachments/"]`).first();
		ctx.ok((await link.count()) === 1 && (await link.innerText()).includes('Handout.pdf'), 'E: PDF als Link mit Dateinamen');
		const href = (await link.count()) ? await link.getAttribute('href') : '';
		const res = await page.request.get(new URL(href || 'about:blank', page.url()).toString());
		const body = await res.body();
		ctx.ok(res.status() === 200 && body.subarray(0, 5).toString() === '%PDF-', 'E: Anhang herunterladbar', `${res.status()} ${href}`);
		ctx.ok(bad.length === 0, 'E: kein 4xx auf Anhänge', bad.join(' | '));
		await ctx.screenshot(page, 'resources');
		ctx.assertClean('E Ressourcen', m);
	}

	// D. nextcloud.log ohne Token, Code, Secret
	const log = sh('cat "$(php occ config:system:get datadirectory)/nextcloud.log" 2>/dev/null || true');
	for (const needle of ['msmock-access-', 'msmock-refresh-', CODE, SECRET, 'eyJ', 'AADSTS']) {
		ctx.ok(!log.includes(needle), `D: Log ohne ${needle.slice(0, 16)}`);
	}
	occ('config:app:delete', 'nextnotes', 'onenote_secret');
});
