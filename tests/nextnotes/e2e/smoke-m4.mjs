// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// M4i (SPEC §13 M4.1, Plan M4i Krit. 1): jede Ansicht aus Inventur B §1.1 (Status übernehmen/ersetzen) öffnen und die Hauptaktion ausführen:
// Kanban Karte verschieben, Tasks abhaken, Dashboard Karte öffnen, Graph Knoten, Diary Eintrag für heute, Papierkorb wiederherstellen,
// Revisionen wiederherstellen, Excalidraw zeichnen + speichern + Vorschau; Speed Dial, Tags, Favoriten (Seitenleiste, B11), Notizverwaltung,
// Anhänge, Erwähnungen, Workspaces (Einstellungen), Ordner, Erstellen: Liste lädt. Je Ansicht 0 CSP/pageerror/Konsolenfehler/≥ 400.
// favorites.php und welcome.php sind nicht portiert (B11) und liefern 404 (StrippedRoutesTest).
import { runSpec } from './lib/harness.mjs';
import { apiClient } from './lib/api.mjs';

const RUN = `${process.argv[3]}${Date.now().toString(36)}`;

await runSpec(import.meta.url, async (ctx, page) => {
	const api = apiClient(ctx, page.request);
	const WS = await api.workspace(`Smoke ${RUN}`);
	const wq = `workspace=${encodeURIComponent(WS)}`;
	// Testdaten: Kanban (Ordner mit zwei Spalten), Aufgabenliste, Notiz mit Revision und Anhang, Papierkorb, Favorit, Tag, Speed Dial
	const board = await api.folder(WS, `Board ${RUN}`);
	const col1 = (await api.must('POST', '/folders', { folder_name: 'Offen', parent_folder_id: board, workspace: WS })).folder_id;
	const col2 = (await api.must('POST', '/folders', { folder_name: 'Erledigt', parent_folder_id: board, workspace: WS })).folder_id;
	const card = await api.note(WS, `Karte ${RUN}`, col1, `<p>Karte ${RUN}</p>`);
	const tasks = JSON.stringify([{ id: 1, text: `Aufgabe ${RUN}`, completed: false }]);
	const tl = (await api.must('POST', '/notes', { heading: `Liste ${RUN}`, type: 'tasklist', content: tasks, workspace: WS })).note.id;
	const note = await api.note(WS, `Notiz ${RUN}`, null, `<p>Version eins ${RUN}</p>`);
	await api.must('POST', `/notes/${note}/snapshot`, { manual: true });
	await api.must('PATCH', `/notes/${note}`, { content: `<p>Version zwei ${RUN}</p>`, workspace: WS });
	await api.must('PUT', `/notes/${note}/tags`, { tags: [`tag${RUN}`, 'speed-dial'], workspace: WS });
	await api.must('POST', `/notes/${note}/favorite`, {});
	const auth = `Basic ${Buffer.from(`${process.env.NN_USER}:${process.env.NN_PASS}`).toString('base64')}`;
	const up = await page.request.post(ctx.appUrl(`api/v1/notes/${note}/attachments`), {
		headers: { Authorization: auth, 'OCS-APIRequest': 'true' }, multipart: { file: { name: `datei-${RUN}.txt`, mimeType: 'text/plain', buffer: Buffer.from(`anhang-${RUN}`) } }, failOnStatusCode: false });
	ctx.ok(up.status() < 300, 'Testdaten: Anhang hochgeladen', String(up.status()));
	const gone = await api.note(WS, `Weg ${RUN}`, null, `<p>Papierkorb ${RUN}</p>`);
	await api.must('DELETE', `/notes/${gone}?${wq}`);
	// Verweis für den Graphen
	await api.note(WS, `Verweis ${RUN}`, null, `<p><a href="index.php?note=${note}">Notiz</a></p>`);
	const draw = await api.note(WS, `Zeichnung ${RUN}`, null, `<p>Zeichnung ${RUN}</p>`);

	const open = async (path, label) => {
		const m = ctx.mark();
		const resp = await page.goto(ctx.appUrl(`${path}${path.includes('?') ? '&' : '?'}${wq}`), { waitUntil: 'networkidle' });
		ctx.ok(resp?.status() === 200, `${label}: 200`, String(resp?.status()));
		return m;
	};
	const seen = async (selector, label, timeout = 15000) => {
		const ok = await page.locator(selector).first().waitFor({ state: 'visible', timeout }).then(() => true, () => false);
		ctx.ok(ok, label, selector);
		return ok;
	};

	// Hauptansicht (Notizen) + Favoriten über die Seitenleiste (B11)
	let m = await open('index.php', 'Hauptansicht');
	await ctx.selfTest(page);
	await seen(`#left_col [data-note-id="${note}"], #left_col a[href*="note=${note}"]`, 'Hauptansicht: Notiz in der Liste');
	ctx.ok(await page.locator(`.note-actions-toggle[data-note-id="${note}"][data-favorite="1"]`).count() >= 1, 'Favoriten: Notiz in der Seitenleiste als Favorit');
	ctx.assertClean('Hauptansicht', m);

	// Kanban: Karte in die zweite Spalte verschieben
	m = await open(`index.php?kanban=${board}`, 'Kanban');
	if (await seen(`#kanban-view-container .kanban-card[data-note-id="${card}"]`, 'Kanban: Karte sichtbar')) {
		const moved = page.waitForResponse((r) => r.request().method() !== 'GET' && r.url().includes(`/notes/${card}/`) && /folder|move/.test(r.url()), { timeout: 15000 }).catch(() => null);
		await page.locator(`.kanban-card[data-note-id="${card}"]`).dragTo(page.locator(`.kanban-column-content[data-folder-id="${col2}"]`));
		const r = await moved;
		ctx.ok(r?.status() === 200, 'Kanban: Verschieben gespeichert', String(r?.status()));
		const after = (await api.must('GET', `/notes/${card}?${wq}`)).note;
		ctx.ok(Number(after.folder_id) === col2, 'Kanban: Karte in Spalte 2', String(after.folder_id));
	}
	await ctx.screenshot(page, 'kanban');
	ctx.assertClean('Kanban', m);

	// Aufgaben: abhaken
	m = await open('tasks.php', 'Aufgaben');
	const box = page.locator(`xpath=//span[contains(@class,"tasks-task-text") and contains(., "Aufgabe ${RUN}")]/preceding-sibling::input[contains(@class,"tasks-task-checkbox")]`);
	if (await box.waitFor({ state: 'visible', timeout: 15000 }).then(() => true, () => false)) {
		const saved = page.waitForResponse((r) => r.request().method() === 'PATCH' && r.url().includes(`/notes/${tl}`));
		await box.check();
		ctx.ok((await saved).status() === 200, 'Aufgaben: abhaken gespeichert');
	} else ctx.ok(false, 'Aufgaben: Aufgabe sichtbar');
	ctx.assertClean('Aufgaben', m);

	// Dashboard: Karte öffnen
	m = await open('dashboard.php', 'Dashboard');
	const dash = page.locator(`.dash-note-card[data-note-id="${note}"] .dash-card-link`);
	if (await dash.waitFor({ state: 'visible', timeout: 15000 }).then(() => true, () => false)) {
		await Promise.all([page.waitForURL(new RegExp(`[?&]note=${note}`), { timeout: 20000 }), dash.click()]);
		await page.waitForLoadState('networkidle');
		ctx.ok(await page.locator(`#entry${note}`).count() === 1, 'Dashboard: Karte öffnet die Notiz');
	} else ctx.ok(false, 'Dashboard: Karte sichtbar');
	ctx.assertClean('Dashboard', m);

	// Graph: Knoten sichtbar
	m = await open('graph.php', 'Graph');
	await page.locator('.graph-node').first().waitFor({ state: 'attached', timeout: 15000 }).catch(() => {});
	ctx.ok(await page.locator('.graph-node').count() >= 2, 'Graph: Knoten sichtbar', String(await page.locator('.graph-node').count()));
	ctx.assertClean('Graph', m);

	// Tagebuch: Eintrag für heute anlegen
	m = await open('diary.php', 'Tagebuch');
	if (await seen('#diaryTodayBtn', 'Tagebuch: Knopf „Heute“')) {
		await Promise.all([page.waitForURL(/[?&]note=\d+/, { timeout: 20000 }), page.locator('#diaryTodayBtn').click()]);
		await page.waitForLoadState('networkidle');
		const id = Number(new URL(page.url()).searchParams.get('note'));
		ctx.ok(id > 0 && await page.locator(`#entry${id}`).count() === 1, 'Tagebuch: Eintrag für heute angelegt und geöffnet', page.url());
	}
	ctx.assertClean('Tagebuch', m);

	// Papierkorb: wiederherstellen
	m = await open('trash.php', 'Papierkorb');
	if (await seen(`#note${gone} .trash-restore-btn`, 'Papierkorb: Notiz gelistet')) {
		await page.locator(`#note${gone} .trash-restore-btn`).click();
		const restored = page.waitForResponse((r) => r.request().method() === 'POST' && r.url().includes(`/notes/${gone}/restore`));
		await page.locator('#restoreConfirmModal .btn-primary').click();
		ctx.ok((await restored).status() === 200, 'Papierkorb: wiederhergestellt');
	}
	ctx.assertClean('Papierkorb', m);

	// Revisionen: Version wiederherstellen
	m = await open(`revisions.php?note_id=${note}`, 'Revisionen');
	if (await seen('#revisionsList .revisions-item', 'Revisionen: Liste')) {
		await page.locator('#revisionsList .revisions-item').first().click();
		await page.locator('#revisionsActionsBtn').click();
		await page.locator('#revisionsRestoreBtn').click();
		const restored = page.waitForResponse((r) => r.request().method() === 'POST' && r.url().includes('/snapshot/restore'));
		await page.locator('.alert-modal-overlay .alert-modal-button.primary').click();
		ctx.ok((await restored).status() === 200, 'Revisionen: wiederhergestellt');
		await page.waitForURL(/index\.php/, { timeout: 15000 }).catch(() => {});
		await page.waitForLoadState('networkidle');
		const content = (await api.must('GET', `/notes/${note}?${wq}`)).note.content;
		ctx.ok(String(content).includes(`Version eins ${RUN}`), 'Revisionen: alter Inhalt zurück', String(content).slice(0, 80));
	}
	ctx.assertClean('Revisionen', m);

	// Excalidraw: zeichnen + speichern + Vorschau in der Notiz
	{
		const did = `excalidraw-smoke${RUN}`;
		m = await open(`excalidraw_editor.php?note_id=${draw}&diagram_id=${did}`, 'Excalidraw');
		// ADR 0012 Nachtrag M4a, Fall (b): pica-WASM wird blockiert, JS-Rückfall
		const off = ctx.allow('csp', /^script-src wasm-eval @.*\/js\/vendor\/excalidraw-dist\//, 'erwartet: pica-WASM blockiert (ADR 0012 Nachtrag M4a)');
		if (await page.waitForSelector('.excalidraw canvas', { timeout: 20000 }).then(() => true, () => false)) {
			await page.waitForTimeout(800);
			await page.click('[data-testid="toolbar-rectangle"]', { force: true });
			await page.mouse.move(500, 400);
			await page.mouse.down();
			await page.mouse.move(700, 550, { steps: 8 });
			await page.mouse.up();
			await page.waitForTimeout(800);
			const saved = page.waitForResponse((r) => r.url().includes('api_save_excalidraw.php'), { timeout: 30000 }).catch(() => null);
			await page.click('#saveBtn');
			ctx.ok((await saved)?.status() === 200, 'Excalidraw: gespeichert');
			await page.goto(ctx.appUrl(`index.php?${wq}&note=${draw}`), { waitUntil: 'networkidle' });
			const img = await page.waitForFunction((id) => {
				const i = document.querySelector(`#entry${id} .excalidraw-container img`);
				return i && i.complete && i.naturalWidth > 0;
			}, draw, { timeout: 15000 }).then(() => true, () => false);
			ctx.ok(img, 'Excalidraw: Vorschau in der Notiz');
		} else ctx.ok(false, 'Excalidraw: Editor geladen');
		ctx.assertClean('Excalidraw', m);
		off();
		await page.goto('about:blank'); // gibt die Sperre frei
	}

	// Listen-Ansichten: Inhalt lädt
	const lists = [
		['speed_dial.php', 'Speed Dial', `.speed-dial-tile[data-note-id="${note}"]`],
		['list_tags.php', 'Tags', `.tag-item[data-tag="tag${RUN}"]`],
		['list_folders.php', 'Ordner', `.folder-item[data-folder-id="${board}"]`],
		['notes_manager.php', 'Notizverwaltung', '#nmNotesContainer a'],
		['attachments_list.php', 'Anhänge (Workspace)', '[data-attachment-id]'],
		[`attachments.php?note_id=${note}`, 'Anhänge (Notiz)', '#uploadBtn'],
		['mentions.php', 'Erwähnungen', '#nextnotes-root'],
		['create.php', 'Erstellen', '#create-diary-entry-card'],
		['settings.php', 'Einstellungen / Workspaces', '#nextnotes-root'],
	];
	for (const [path, label, sel] of lists) {
		m = await open(path, label);
		await seen(sel, `${label}: Inhalt geladen`);
		ctx.assertClean(label, m);
	}
	// Workspaces-Liste in den Einstellungen (alte URL workspaces.php leitet dorthin)
	m = ctx.mark();
	await page.goto(ctx.appUrl(`workspaces.php?${wq}`), { waitUntil: 'networkidle' });
	ctx.ok(page.url().includes('settings.php'), 'workspaces.php → Einstellungen', page.url());
	await seen(`text=${WS}`, 'Workspaces: eigener Workspace gelistet');
	ctx.assertClean('Workspaces', m);
	await ctx.screenshot(page, 'workspaces');
});
