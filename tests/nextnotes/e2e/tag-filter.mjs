// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// M3-fix3 (P19, B50, B51): Speed Dial setzt keinen sichtbaren Tag; Tag-Filter an/aus ohne Seiten-Reload,
// Workspace und offene Notiz bleiben, Zurück folgt dem Filter.
import { runSpec } from './lib/harness.mjs';
import { apiClient } from './lib/api.mjs';

const RUN = `${process.argv[3]}${Date.now().toString(36)}`;
const TAG = `thema${RUN}`;

await runSpec(import.meta.url, async (ctx, page) => {
	const api = apiClient(ctx, page.request);
	const WS = await api.workspace(`Tagfilter ${RUN}`);
	const wq = `workspace=${encodeURIComponent(WS)}`;
	const id = await api.note(WS, `Mit Tag ${RUN}`, null, `<p>a ${RUN}</p>`);
	const other = await api.note(WS, `Ohne Tag ${RUN}`, null, `<p>b ${RUN}</p>`);
	const noteTags = async () => String((await api.must('GET', `/notes/${id}?${wq}`)).note?.tags ?? '');
	const waitFor = async (fn, ms = 8000) => {
		for (const end = Date.now() + ms; Date.now() < end; await page.waitForTimeout(200)) if (await fn()) return true;
		return fn();
	};
	const inList = (nid) => page.evaluate((n) => [...document.querySelectorAll('#left_col a.links_arbo_left')]
		.some((a) => new URL(a.href, location.href).searchParams.get('note') === String(n)), nid);
	const params = () => new URL(page.url()).searchParams;
	const fragmentReq = (r) => r.request().method() === 'GET' && r.request().headers()['x-poznote-fragment'] === 'left_col';

	// interner Tag speed-dial (Altdaten) vor dem Laden setzen: Abschnitt 1 prüft ihn ohne Reload
	const sdPut = (await api.call('PUT', `/notes/${id}/tags`, { tags: ['speed-dial'], workspace: WS })).status;
	await page.goto(ctx.appUrl(`index.php?${wq}&note=${id}`), { waitUntil: 'networkidle' });
	await ctx.selfTest(page);
	await page.waitForSelector(`#entry${id}`);
	await page.evaluate(() => { window.__nnNoReload = 1; });
	let navs = 0;
	page.on('domcontentloaded', () => { navs++; });
	const still = async (label) => {
		ctx.ok(await page.evaluate(() => window.__nnNoReload === 1) && navs === 0, `${label}: kein Seiten-Reload`, `navs=${navs}`);
		ctx.ok(params().get('workspace') === WS && params().get('note') === String(id), `${label}: Workspace und Notiz in der URL`, page.url());
		ctx.ok(await page.locator(`#entry${id}`).isVisible(), `${label}: offene Notiz bleibt offen`);
	};

	// 1. Interner Tag speed-dial (Menüpunkt seit M6g1 entfernt; Altdaten tragen ihn noch): per API gesetzt, kein sichtbarer Tag, nicht in GET /tags
	let m = ctx.mark();
	ctx.ok(sdPut === 200, 'Speed Dial: Tags gespeichert (API, Altdaten)');
	ctx.ok(/speed-dial/.test(await noteTags()), 'Speed Dial: Notiz trägt den internen Tag');
	ctx.ok(await waitFor(async () => (await page.locator(`#entry${id}`).count()) === 1 && (await page.locator('.clickable-tag-wrapper[data-internal-tag]').count()) === 1), 'Speed Dial: Tag-Element vorhanden (versteckt)');
	ctx.ok(!(await page.locator('.clickable-tag[data-tag="speed-dial"]').isVisible()), 'Speed Dial: Tag „speed-dial“ nicht sichtbar');
	ctx.ok(!(await page.locator('.editable-tags-list').first().innerText()).includes('speed'), 'Speed Dial: kein Speed-Dial-Text im Tag-Bereich');
	ctx.ok(!(await api.must('GET', `/tags?${wq}`)).tags.some((t) => t.toLowerCase() === 'speed-dial'), 'GET /tags: speed-dial ausgeblendet');
	ctx.assertClean('Speed Dial', m);

	// 2. Sichtbaren Tag hinzufügen: speed-dial bleibt beim Speichern erhalten
	m = ctx.mark();
	const input = page.locator('.editable-tags-container .tag-input').first();
	await input.click();
	await input.fill(TAG);
	await input.press('Enter');
	ctx.ok(await waitFor(async () => { const t = await noteTags(); return t.includes(TAG) && /speed-dial/.test(t); }, 10000), 'Tag hinzufügen: speed-dial bleibt gespeichert', await noteTags());
	ctx.ok(await page.locator(`.clickable-tag[data-tag="${TAG}"]`).isVisible(), 'Tag hinzufügen: neuer Tag sichtbar');
	ctx.ok(await inList(other), 'Vorher: andere Notiz in der Liste');
	ctx.assertClean('Tag hinzufügen', m);

	// 3. Filter an: nur die linke Spalte, URL mit tags_search, Notiz bleibt
	m = ctx.mark();
	let frag = page.waitForResponse(fragmentReq, { timeout: 10000 });
	await page.locator(`.clickable-tag[data-tag="${TAG}"]`).click();
	ctx.ok((await frag).status() === 200, 'Filter an: Fragment left_col geladen');
	ctx.ok(await waitFor(async () => !(await inList(other)) && (await inList(id))), 'Filter an: Liste gefiltert');
	ctx.ok(params().get('tags_search') === TAG, 'Filter an: tags_search in der URL', page.url());
	await still('Filter an');
	ctx.assertClean('Filter an', m);

	// 4. Filter aus (zweiter Klick): Workspace und Notiz bleiben, keine Startseite
	m = ctx.mark();
	frag = page.waitForResponse(fragmentReq, { timeout: 10000 });
	await page.locator(`.clickable-tag[data-tag="${TAG}"]`).click();
	ctx.ok((await frag).status() === 200, 'Filter aus: Fragment left_col geladen');
	ctx.ok(await waitFor(async () => inList(other)), 'Filter aus: ganze Liste zurück');
	ctx.ok(!params().has('tags_search'), 'Filter aus: tags_search entfernt', page.url());
	ctx.ok((await page.locator('.clickable-tag-wrapper.tag-highlight').count()) === 0, 'Filter aus: keine Tag-Hervorhebung');
	await still('Filter aus');
	ctx.assertClean('Filter aus', m);

	// 5. Zurück: Filter wieder an, Vor: wieder aus, jeweils ohne Reload
	m = ctx.mark();
	frag = page.waitForResponse(fragmentReq, { timeout: 10000 });
	await page.evaluate(() => history.back());
	await frag;
	ctx.ok(await waitFor(async () => params().get('tags_search') === TAG && !(await inList(other))), 'Zurück: Filter wieder aktiv');
	await still('Zurück');
	frag = page.waitForResponse(fragmentReq, { timeout: 10000 });
	await page.evaluate(() => history.forward());
	await frag;
	ctx.ok(await waitFor(async () => !params().has('tags_search') && (await inList(other))), 'Vor: Filter wieder aus');
	await still('Vor');
	ctx.assertClean('Zurück/Vor', m);
	await ctx.screenshot(page, 'filter');
});
