// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// M4e (M4-Plan Done M4e 1, 4, 5): sechs Listen-Seiten mit Icon-Leiste; je Seite 200, Hauptaktion, 0 CSP/pageerror/Konsolenfehler/≥ 400,
// ≤ 8 API-Requests beim Laden, keine Teilen-/Offline-/Konten-Elemente. Dunkel: Laden + Hauptaktion ohne Schreibaktionen am Ende.
import { runSpec } from './lib/harness.mjs';
import { apiClient } from './lib/api.mjs';

const RUN = `${process.argv[3]}${Date.now().toString(36)}`;
const TAG = `m4etag${RUN}`;
const STRIPPED = ['[data-action="share-folder"]', '[data-action="offline-folder"]', 'option[value="keep-offline"]', '#iconSidebarSharesBtn',
	'#iconSidebarOfflineBtn', '#iconSidebarLogoutBtn', '#iconSidebarThemeToggleBtn', '.share-state-shared'];

await runSpec(import.meta.url, async (ctx, page) => {
	const api = apiClient(ctx, page.request);
	const WS = await api.workspace(`Seiten ${RUN}`);
	const wq = `workspace=${encodeURIComponent(WS)}`;
	const F = await api.folder(WS, `Ordner ${RUN}`);
	const sub = (await api.must('POST', '/folders', { folder_name: `Unter ${RUN}`, parent_folder_id: F, workspace: WS })).folder_id;
	const n1 = await api.note(WS, `Notiz ${RUN}`, F, `<p>inhalt ${RUN}</p>`);
	await api.must('PUT', `/notes/${n1}/tags`, { tags: [TAG], workspace: WS });
	const md = (await api.must('POST', '/notes', { heading: `MD ${RUN}`, type: 'markdown', content: `# Titel ${RUN}\n\n**fett**`, workspace: WS, folder_id: sub })).note.id;
	const auth = `Basic ${Buffer.from(`${process.env.NN_USER}:${process.env.NN_PASS}`).toString('base64')}`;
	const up = await page.request.post(`${ctx.base}/index.php/apps/nextnotes/api/v1/notes/${n1}/attachments`, {
		headers: { Authorization: auth, 'OCS-APIRequest': 'true' }, multipart: { file: { name: `datei-${RUN}.txt`, mimeType: 'text/plain', buffer: Buffer.from(`anhang-${RUN}`) } }, failOnStatusCode: false });
	ctx.ok(up.status() === 201, 'Anhang hochgeladen', String(up.status()));
	const trashed = [];
	for (const [h, c] of [[`Weg ${RUN}`, `<p>geloescht ${RUN}</p>`], [`Weg2 ${RUN}`, '<p>zwei</p>']]) {
		const id = await api.note(WS, h, null, c);
		await api.must('DELETE', `/notes/${id}?${wq}`);
		trashed.push(id);
	}
	const tasks = JSON.stringify([{ id: 1, text: `Aufgabe ${RUN} https://example.org`, completed: true }]);
	const tl = (await api.must('POST', '/notes', { heading: `Liste ${RUN}`, type: 'tasklist', content: tasks, workspace: WS })).note.id;
	await api.must('DELETE', `/notes/${tl}?${wq}`);

	// WCAG-Kontrast aus berechneten Farben (rgb()/color(srgb …), Hintergrund ggf. von Vorfahren)
	const contrastOf = (sel) => page.evaluate((selector) => {
		const el = document.querySelector(selector);
		if (!el) return null;
		// Canvas normalisiert jede CSS-Farbe (rgb, color(srgb), oklab …) auf sRGB-Bytes
		const cv = document.createElement('canvas').getContext('2d', { willReadFrequently: true });
		const parse = (c) => {
			cv.clearRect(0, 0, 1, 1);
			cv.fillStyle = 'rgba(0, 0, 0, 0)';
			cv.fillStyle = c;
			cv.fillRect(0, 0, 1, 1);
			const d = cv.getImageData(0, 0, 1, 1).data;
			return [d[0] / 255, d[1] / 255, d[2] / 255, d[3] / 255];
		};
		const lum = ([r, g, b]) => [r, g, b].map((v) => (v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4)).reduce((a, v, i) => a + v * [0.2126, 0.7152, 0.0722][i], 0);
		let bg = null;
		for (let e = el; e && !bg; e = e.parentElement) {
			const c = parse(getComputedStyle(e).backgroundColor);
			if (c && c[3] > 0.5) bg = c;
		}
		const fg = parse(getComputedStyle(el).color);
		if (!fg || !bg) return null;
		const [a, b] = [lum(fg), lum(bg)].sort((x, y) => y - x);
		return { ratio: Math.round(((a + 0.05) / (b + 0.05)) * 100) / 100, fg: getComputedStyle(el).color, bg: getComputedStyle(el).backgroundColor };
	}, sel);

	// Papierkorb mit mehr als 50 Notizen (Mehr laden)
	const WSM = await api.workspace(`Viel ${RUN}`);
	const wqm = `workspace=${encodeURIComponent(WSM)}`;
	for (let i = 0; i < 55; i++) {
		const id = await api.note(WSM, `Viel ${RUN} ${String(i).padStart(2, '0')}`, null, `<p>v${i}</p>`);
		await api.must('DELETE', `/notes/${id}?${wqm}`);
	}

	const apiCalls = (since) => ctx.requests.slice(since.q).filter((r) => /\/apps\/nextnotes\/api\/v1\//.test(r.url) && r.type !== 'document').length;
	const open = async (path, label) => {
		const m = ctx.mark();
		const resp = await page.goto(ctx.appUrl(`${path}${path.includes('?') ? '&' : '?'}${wq}`), { waitUntil: 'networkidle' });
		ctx.ok(resp?.status() === 200, `${label}: 200`, String(resp?.status()));
		const ttfb = await page.evaluate(() => { const n = performance.getEntriesByType('navigation')[0]; return n ? Math.round(n.responseStart - n.requestStart) : -1; });
		ctx.info(`${label}: TTFB ${ttfb} ms, ${apiCalls(m)} API-Requests`);
		ctx.ok(apiCalls(m) <= 8, `${label}: ≤ 8 API-Requests`, String(apiCalls(m)));
		ctx.ok(await page.locator('#icon_sidebar #iconSidebarTrashBtn').isVisible(), `${label}: Icon-Leiste sichtbar`);
		for (const sel of STRIPPED) ctx.ok((await page.locator(sel).count()) === 0, `${label}: gestrichen ${sel}`);
		const title = page.locator('.poznote-page-title-workspace-name');
		ctx.ok((await title.count()) === 0 || (await title.first().innerText()) === WS, `${label}: Workspace im Titel`);
		return m;
	};

	// Hauptansicht: Icon-Leiste mit den neuen Einträgen
	let m = ctx.mark();
	await page.goto(ctx.appUrl(`index.php?${wq}`), { waitUntil: 'networkidle' });
	await ctx.selfTest(page);
	// UI-rail: kein Erstellen-Eintrag mehr (Erstellen über „+“), Dashboard/Notizen neu
	ctx.ok(await page.locator('#icon_sidebar #iconSidebarCreateBtn').count() === 0, 'Hauptansicht: kein Erstellen-Eintrag');
	for (const id of ['iconSidebarDashboardBtn', 'iconSidebarHomeBtn', 'iconSidebarNotesBtn', 'iconSidebarFoldersBtn', 'iconSidebarTagsBtn', 'iconSidebarAttachmentsBtn', 'iconSidebarTrashBtn']) {
		ctx.ok(await page.locator(`#icon_sidebar #${id}`).isVisible(), `Hauptansicht: ${id} sichtbar`);
	}
	await Promise.all([page.waitForURL(/trash\.php/), page.locator('#iconSidebarTrashBtn').click()]);
	ctx.ok(new URL(page.url()).searchParams.get('workspace') === WS, 'Icon-Leiste → Papierkorb behält den Workspace', page.url());
	ctx.assertClean('Hauptansicht/Icon-Leiste', m);

	// 1. Papierkorb: Liste, Vorschau, Suche (POST), Wiederherstellen
	m = await open('trash.php', 'Papierkorb');
	ctx.ok(await page.locator(`#note${trashed[0]} .css-title`).innerText() === `Weg ${RUN}`, 'Papierkorb: Notiz gelistet');
	ctx.ok((await page.locator(`#note${trashed[0]} .noteentry`).innerText()).includes(`geloescht ${RUN}`), 'Papierkorb: Vorschau');
	ctx.ok(await page.locator(`#note${tl} .task-item.completed a[href="https://example.org"]`).count() === 1, 'Papierkorb: Aufgabenliste mit Link');
	ctx.ok(await page.locator('.trash-sort-option.is-active').count() === 1, 'Papierkorb: Sortierung');
	await ctx.screenshot(page, 'trash');
	await page.fill('#searchInput', 'Weg2');
	await Promise.all([page.waitForLoadState('networkidle'), page.waitForURL((u) => u.searchParams.get('search') === 'Weg2'), page.press('#searchInput', 'Enter')]);
	ctx.ok(!page.url().includes('requesttoken'), 'Suche: kein requesttoken in der URL', page.url());
	ctx.ok(await page.locator('.trash-notecard').count() === 1 && await page.locator(`#note${trashed[1]}`).count() === 1, 'Suche (POST): nur Treffer');
	await Promise.all([page.waitForURL((u) => !u.searchParams.has('search')), page.locator('#clearTrashSearchBtn').click()]);
	await page.waitForLoadState('networkidle');
	ctx.ok(await page.locator('.trash-notecard').count() === 3, 'Suche zurücksetzen: alle wieder da (B59)', String(await page.locator('.trash-notecard').count()));
	if (ctx.theme === 'light') {
		await page.locator(`#note${trashed[0]} .trash-restore-btn`).click();
		const restored = page.waitForResponse((r) => r.request().method() === 'POST' && r.url().includes(`/notes/${trashed[0]}/restore`));
		await page.locator('#restoreConfirmModal .btn-primary').click();
		ctx.ok((await restored).status() === 200, 'Wiederherstellen: 200');
		ctx.ok(await page.locator(`#note${trashed[0]}`).isHidden(), 'Wiederherstellen: Karte ohne Reload entfernt');
		const back = await api.must('GET', `/notes/${trashed[0]}?${wq}`);
		ctx.ok(back?.note?.id === trashed[0], 'Wiederherstellen: Notiz wieder erreichbar');
	}
	ctx.assertClean('Papierkorb', m);

	// 1b. Mehr laden (M4e-fix): nächste Karten in-place, Suche und Sortierung bleiben; H3 Gefahr-Knöpfe ≥ 4.5:1
	m = ctx.mark();
	await page.goto(ctx.appUrl(`trash.php?sort=created&search=${encodeURIComponent(`Viel ${RUN}`)}&${wqm}`), { waitUntil: 'networkidle' });
	ctx.ok(await page.locator('.trash-notecard').count() === 50, 'Mehr laden: zuerst 50', String(await page.locator('.trash-notecard').count()));
	const more = page.locator('.trash-load-more-btn');
	ctx.ok(await more.isVisible() && (await more.innerText()).includes('5'), 'Mehr laden: Knopf mit Rest', await more.innerText().catch(() => ''));
	const urlBefore = page.url();
	await page.evaluate(() => { window.__nnStay = 1; });
	const frag = page.waitForResponse((r) => r.url().includes('trash.php') && r.request().headers()['x-poznote-fragment'] === 'trash_cards');
	await more.click();
	ctx.ok((await frag).status() === 200, 'Mehr laden: Fragment 200');
	await page.waitForFunction(() => document.querySelectorAll('.trash-notecard').length === 55, null, { timeout: 10000 }).catch(() => {});
	ctx.ok(await page.locator('.trash-notecard').count() === 55, 'Mehr laden: 55 Karten', String(await page.locator('.trash-notecard').count()));
	ctx.ok(await page.locator('.trash-load-more-btn').count() === 0, 'Mehr laden: Knopf weg am Ende');
	ctx.ok(await page.evaluate(() => window.__nnStay === 1) && page.url() === urlBefore, 'Mehr laden: ohne Neuladen, URL unverändert', page.url());
	const heads = await page.locator('.trash-notecard .css-title').allInnerTexts();
	ctx.ok(heads.every((h, i) => h === `Viel ${RUN} ${String(54 - i).padStart(2, '0')}`), 'Mehr laden: Sortierung (Anlage, neueste zuerst) durchgehend', heads.slice(48, 52).join(' | '));
	ctx.ok(new Set(heads).size === 55, 'Mehr laden: keine doppelten Karten');
	ctx.ok(await page.locator('#searchInput').inputValue() === `Viel ${RUN}`, 'Mehr laden: Suchbegriff bleibt');
	for (const [sel, label] of [['#emptyTrashBtn', 'Papierkorb leeren']]) {
		const c = await contrastOf(sel);
		ctx.info(`H3 ${ctx.theme} ${label}: ${JSON.stringify(c)}`);
		ctx.ok(c && c.ratio >= 4.5, `H3 ${ctx.theme}: ${label} Kontrast ≥ 4.5`, JSON.stringify(c));
		await page.hover(sel);
		await page.waitForTimeout(250);
		const h = await contrastOf(sel);
		ctx.ok(h && h.ratio >= 4.5, `H3 ${ctx.theme}: ${label} (hover) Kontrast ≥ 4.5`, JSON.stringify(h));
	}
	await page.locator('#emptyTrashBtn').click();
	await page.locator('#emptyTrashConfirmModal .btn-danger').waitFor({ state: 'visible' });
	const mc = await contrastOf('#emptyTrashConfirmModal .btn-danger');
	ctx.info(`H3 ${ctx.theme} Dialog: ${JSON.stringify(mc)}`);
	ctx.ok(mc && mc.ratio >= 4.5, `H3 ${ctx.theme}: Dialog-Gefahrknopf Kontrast ≥ 4.5`, JSON.stringify(mc));
	await page.locator('#emptyTrashConfirmModal .btn-cancel').click();
	if (ctx.theme === 'light') {
		const fg = (await contrastOf('#emptyTrashBtn'))?.fg;
		ctx.ok(fg === 'rgb(255, 255, 255)', 'H3 hell: Schrift weiß wie bisher', fg);
	}
	await ctx.screenshot(page, 'trash-more');
	ctx.assertClean('Mehr laden', m);

	// 2. Tags: Liste mit Anzahl, Klick filtert die Hauptansicht
	m = await open('list_tags.php', 'Tags');
	const tagItem = page.locator(`.tag-item[data-tag="${TAG}"]`);
	ctx.ok(await tagItem.isVisible(), 'Tags: Tag gelistet');
	ctx.ok((await tagItem.innerText()).includes('(1)'), 'Tags: Anzahl');
	ctx.ok(await page.locator('.tag-item[data-tag="speed-dial"]').count() === 0, 'Tags: speed-dial ausgeblendet');
	await ctx.screenshot(page, 'tags');
	await Promise.all([page.waitForURL(/index\.php/), tagItem.click()]);
	await page.waitForLoadState('networkidle');
	ctx.ok(new URL(page.url()).searchParams.get('tags_search') === TAG, 'Tag-Klick: Hauptansicht mit Tag-Filter', page.url());
	ctx.ok(await page.locator('#left_col a.links_arbo_left').count() >= 1, 'Tag-Klick: gefilterte Liste');
	ctx.assertClean('Tags', m);

	// 3. Ordner: Baum mit Unterordner, Anzahl, Ordner öffnen → Kanban der Hauptansicht
	m = await open('list_folders.php', 'Ordner');
	const row = page.locator(`.folder-item[data-folder-id="${F}"]`);
	ctx.ok(await row.isVisible() && await page.locator(`.folder-item[data-folder-id="${sub}"][data-depth="1"]`).isVisible(), 'Ordner: Baum mit Unterordner');
	ctx.ok(await row.locator('.folder-list-menu-btn').getAttribute('data-note-count') === '2', 'Ordner: Anzahl inkl. Unterordner');
	await row.locator('.folder-list-menu-btn').click();
	ctx.ok(await page.locator('#folderRowActionsMenu [data-action="download-folder"]').isVisible(), 'Ordner: Menü mit Herunterladen');
	await page.keyboard.press('Escape');
	await ctx.screenshot(page, 'folders');
	// Ordner-Aktionen ohne Neuladen (P24): umbenennen, Favorit, löschen
	await page.evaluate(() => { window.__nnStay = 1; });
	const stays = () => page.evaluate(() => window.__nnStay === 1);
	const fragOk = () => page.waitForResponse((r) => r.url().includes('list_folders.php') && r.request().headers()['x-poznote-fragment'] === 'folders_list');
	let fr = fragOk();
	await row.locator('.folder-inline-action-btn[data-action="rename-folder"]').click();
	await page.locator('#editFolderName').fill(`Neu ${RUN}`);
	await page.locator('#editFolderModal [data-action="save-folder-name"]').click();
	ctx.ok((await fr).status() === 200, 'Ordner umbenennen: Fragment 200');
	await page.waitForFunction((t) => document.querySelector('#foldersList').innerText.includes(t), `Neu ${RUN}`, { timeout: 10000 }).catch(() => {});
	ctx.ok((await row.locator('.folder-name-text').innerText()).includes(`Neu ${RUN}`) && await stays(), 'Ordner umbenennen: in-place ohne Neuladen');
	fr = fragOk();
	await row.locator('.folder-inline-action-btn[data-action="favorite-folder"]').click();
	await fr;
	await page.waitForTimeout(200);
	ctx.ok(await row.locator('.folder-inline-action-btn[data-action="favorite-folder"].is-active').count() === 1 && await stays(), 'Ordner Favorit: in-place ohne Neuladen');
	const empty = await api.folder(WS, `Leer ${RUN}`);
	fr = fragOk();
	await page.evaluate(() => window.refreshFolderListPage());
	await fr;
	const emptyRow = page.locator(`.folder-item[data-folder-id="${empty}"]`);
	ctx.ok(await emptyRow.isVisible(), 'Ordner: neuer Ordner nach Aktualisieren sichtbar');
	await emptyRow.locator('.folder-list-menu-btn').click();
	fr = fragOk();
	await page.locator('#folderRowActionsMenu [data-action="delete-folder"]').click();
	await fr;
	await page.waitForTimeout(200);
	ctx.ok(await emptyRow.count() === 0 && await stays(), 'Ordner löschen: in-place ohne Neuladen');
	await page.fill('#filterInput', `Unter ${RUN}`);
	ctx.ok(await page.locator(`.folder-item[data-folder-id="${sub}"]`).isVisible() && await page.locator(`.folder-item[data-folder-id="${F}"]`).isVisible(), 'Ordner: Filter nach Neuaufbau (Zeile + Vorfahr)');
	await page.fill('#filterInput', '');
	await page.locator('.poznote-page-title-name').click();
	await Promise.all([page.waitForURL(/index\.php\?.*kanban=/), row.locator('.folder-name-text').click()]);
	await page.waitForLoadState('networkidle');
	ctx.ok(new URL(page.url()).searchParams.get('kanban') === String(F), 'Ordner öffnen: Kanban', page.url());
	ctx.assertClean('Ordner', m);

	// 4. Anhänge: Liste lädt, Download liefert die Datei
	m = await open('attachments_list.php', 'Anhänge');
	const link = page.locator('[data-attachment-id]').first();
	await link.waitFor({ timeout: 10000 });
	ctx.ok((await page.locator('#attachmentsContainer').innerText()).includes(`datei-${RUN}.txt`), 'Anhänge: Datei gelistet');
	await ctx.screenshot(page, 'attachments');
	const popupP = page.context().waitForEvent('page', { timeout: 10000 }).catch(() => null);
	const dlResp = page.context().waitForEvent('response', { predicate: (r) => /\/api\/v1\/notes\/\d+\/attachments\/\d+/.test(r.url()), timeout: 10000 });
	await link.click();
	const att = await dlResp;
	ctx.ok(att.status() === 200 && (await att.text()).includes(`anhang-${RUN}`), 'Anhänge: Download', String(att.status()));
	const popup = await popupP;
	if (popup) await popup.close();
	ctx.assertClean('Anhänge', m);

	// 5. Notizverwaltung: Liste lädt, Filter
	m = await open('notes_manager.php', 'Notizen');
	await page.waitForSelector('#nmNotesContainer a', { timeout: 10000 });
	const all = await page.locator('#nmNotesContainer a').count();
	ctx.ok(all >= 2, 'Notizen: Liste lädt', String(all));
	await page.fill('#nmFilterInput', `MD ${RUN}`);
	await page.waitForTimeout(400);
	const visible = await page.locator('#nmNotesContainer a:visible').count();
	ctx.ok(visible >= 1 && visible < all, 'Notizen: Filter', `${visible}/${all}`);
	await ctx.screenshot(page, 'notes-manager');
	ctx.assertClean('Notizen', m);

	// 6. Erstellen (UI-rail: Erstellen-Seite entfernt): create.php leitet auf die Hauptansicht, „+“-Menü bietet alle Typen, Notiz öffnet den Editor
	m = await open('create.php', 'Erstellen → Hauptansicht');
	ctx.ok(new URL(page.url()).pathname.endsWith('/index.php') && await page.locator('#left_col').count() === 1, 'create.php → Hauptansicht', page.url());
	await page.click('#sidebarCreateBtn');
	await page.locator('#create-menu').waitFor({ state: 'visible', timeout: 5000 });
	const types = await page.$$eval('#create-menu .create-note-option[data-type]', (els) => els.filter((e) => e.getClientRects().length).map((e) => e.dataset.type));
	ctx.ok(['html', 'markdown', 'list', 'diary', 'folder'].every((t) => types.includes(t)), '„+“-Menü: Notiz, Markdown, Aufgabenliste, Tagebuch, Ordner', types.join(' '));
	await ctx.screenshot(page, 'create');
	await Promise.all([page.waitForLoadState('networkidle'), page.click('#create-menu [data-type="html"]')]);
	await page.waitForTimeout(1200);
	const entry = page.locator('#right_col .noteentry');
	ctx.ok(await entry.count() === 1 && await entry.first().getAttribute('contenteditable') === 'true', 'Erstellen: Editor der neuen Notiz offen', page.url());
	ctx.assertClean('Erstellen', m);

	// 7. XSS (Done M4e 3, Nutzlasten aus pages.mjs/xss.mjs): gleiche Element-/Attributzahl Basis vs. Nutzlast auf allen sechs Seiten (nur Hell)
	if (ctx.theme !== 'light') return;
	const EVIL = `"><img src=x onerror="window.__xss=1"><b>Fett</b> ${RUN}`;
	const EVIL_FOLDER = "O'Brien'); window.__xss=6; {{7+7}} ${7+7}";
	const EVIL_TAG = `t');window.__xss=8;//&amp;{{7*7}}${RUN}`;
	const setup = async (ws, title, folder, tag, file) => {
		await api.workspace(ws);
		const fid = await api.folder(ws, folder);
		const nid = await api.note(ws, title, fid, `<p>Inhalt ${RUN}</p>`);
		const tr = await api.call('PUT', `/notes/${nid}/tags`, { tags: [tag], workspace: ws });
		ctx.ok(tr.status === 200, `XSS-Daten ${ws}: Tag gespeichert`, `${tr.status} ${JSON.stringify(tr.body).slice(0, 120)}`);
		const del = await api.note(ws, title, null, `<p>${title.replace(/</g, '&lt;')}</p>`);
		await api.must('DELETE', `/notes/${del}?workspace=${encodeURIComponent(ws)}`);
		const x = await page.request.post(ctx.appUrl(`api/v1/notes/${nid}/attachments?workspace=${encodeURIComponent(ws)}`), {
			headers: { Authorization: auth, 'OCS-APIRequest': 'true' }, multipart: { file: { name: file, mimeType: 'text/plain', buffer: Buffer.from('x') } }, failOnStatusCode: false });
		ctx.ok(x.status() === 201, `XSS-Daten ${ws}: Anhang`, String(x.status()));
		return `workspace=${encodeURIComponent(ws)}`;
	};
	// right_col kann aus früheren Läufen Daten tragen; frisch anlegen, damit Basis und Nutzlast gleich aufgebaut sind
	await api.call('DELETE', '/workspaces/right_col');
	const baseQ = await setup(`Basis ${RUN}`, `Basis ${RUN}`, `Board ${RUN}`, `basis${RUN}`, `anhang-${RUN}.txt`);
	const evilQ = await setup('right_col', EVIL, `${EVIL_FOLDER} ${RUN}`, EVIL_TAG, `<svg onload=window.__xss=7>&amp;'x' ${RUN}.txt`);
	const dom = () => page.evaluate(() => {
		const count = (s) => document.querySelectorAll(s).length;
		const ids = {};
		for (const e of document.querySelectorAll('[id]')) ids[e.id] = (ids[e.id] ?? 0) + 1;
		return {
			img: count('img'), b: count('b'), svg: count('svg'), script: count('script'), form: count('form'), iframe: count('iframe'), embed: count('embed, object'),
			dataAction: count('[data-action]'), onAttr: [...document.querySelectorAll('*')].filter((e) => [...e.attributes].some((a) => /^on/i.test(a.name))).length,
			jsHref: count('a[href^="javascript" i]'), dupIds: Object.entries(ids).filter(([, n]) => n > 1).map(([k, n]) => `${k}×${n}`).sort().join(','),
			rightCol: count('#right_col'),
			islands: [...document.querySelectorAll('script[type="application/json"]')].filter((s) => { try { JSON.parse(s.textContent); return false; } catch { return true; } }).length,
			xss: window.__xss ?? null,
		};
	});
	const ready = { 'trash.php': '.trash-notecard', 'list_tags.php': '.tag-item', 'list_folders.php': '.folder-item', 'attachments_list.php': '[data-attachment-id]', 'notes_manager.php': '#nmNotesContainer a' };
	for (const [path, sel] of Object.entries(ready)) {
		const m0 = ctx.mark();
		const load = async (q) => { await page.goto(ctx.appUrl(`${path}?${q}`), { waitUntil: 'networkidle' }); await page.waitForSelector(sel, { timeout: 10000 }).catch(() => {}); await page.waitForTimeout(300); return dom(); };
		const base = await load(baseQ);
		const evil = await load(evilQ);
		for (const k of ['img', 'b', 'svg', 'script', 'form', 'iframe', 'embed', 'dataAction', 'onAttr', 'jsHref', 'dupIds', 'rightCol']) {
			ctx.ok(evil[k] === base[k], `XSS ${path}: gleiche Anzahl ${k}`, `Basis ${base[k]}, Nutzlast ${evil[k]}`);
		}
		ctx.ok(evil.islands === 0 && evil.xss === null, `XSS ${path}: JSON-Inseln parsebar, keine Ausführung`, JSON.stringify(evil));
		ctx.assertClean(`XSS ${path}`, m0);
	}
	await page.goto(ctx.appUrl(`list_folders.php?${evilQ}`), { waitUntil: 'networkidle' });
	ctx.ok((await page.locator('#foldersList').innerText()).includes(EVIL_FOLDER), 'XSS: Ordnername als Text');
	await page.goto(ctx.appUrl(`trash.php?${evilQ}`), { waitUntil: 'networkidle' });
	ctx.ok((await page.locator('.trash-notecard .css-title').first().innerText()) === EVIL, 'XSS: Titel im Papierkorb als Text');
});
