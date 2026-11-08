// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// M6b (Plan M6 §2.6, Done M6b 1–4): Lehrkraft kennzeichnet einen Ordner über das Ordner-Menü (409 als Meldung), Symbol auch geerbt;
// Schüler: kein Kennzeichnen, keine Schreibaktionen im Zweig, Editor/Tasklist nur lesen ohne Sperr-Request, Kanban ohne Drag,
// Drop auf Material ohne Request, Duplikat landet im privaten Workspace mit Hinweis und Link (Q1); Tasks-Übersicht Kästchen aus (P34);
// privater Workspace ohne Material-Eintrag. 0 CSP/Konsole.
import { runSpec } from './lib/harness.mjs';
import { apiClient } from './lib/api.mjs';
import { createTeam } from './lib/docker.mjs';
import { assertReadonlyView } from './lib/readonly.mjs';

const RUN = `${process.argv[3]}${Date.now().toString(36)}`;
const A = process.env.NN_USER;
const B = process.env.NN_USER2;

await runSpec(import.meta.url, async (ctx, page, context, browser) => {
	const api = apiClient(ctx, page.request);
	const ctxB = await browser.newContext({ viewport: { width: 1400, height: 900 }, locale: 'de-DE' });
	await ctx.attach(ctxB);
	const pb = await ctxB.newPage();
	await ctx.login(pb, B, process.env.NN_PASS2);
	const apiB = apiClient(ctx, ctxB.request, B, process.env.NN_PASS2);
	const privateB = await apiB.defaultWorkspace();
	const privateA = await api.defaultWorkspace();

	// ---------- Daten: Team (A Lehrkraft, B Mitglied), Team-Workspace, Ordner M/U, Notizen ----------
	const teamId = createTeam(ctx.base, A, `Klasse ${RUN}`, { [B]: 1 });
	const TW = `Material ${RUN}`;
	await api.must('POST', '/workspaces', { name: TW, team: teamId });
	const M = await api.folder(TW, `Material ${RUN}`);
	const U = (await api.must('POST', '/folders', { folder_name: `Teil ${RUN}`, parent_folder_id: M, workspace: TW })).folder_id;
	const n1 = await api.note(TW, `Blatt ${RUN}`, M, '<p>Arbeitsblatt</p>');
	const tl = (await api.must('POST', '/notes', { heading: `Liste ${RUN}`, type: 'tasklist', workspace: TW, folder_id: U,
		content: JSON.stringify([{ id: 1, text: 'Lesen', completed: false, important: false, noteId: 0 }]) })).note.id;
	const sN = await apiB.note(TW, `Eigene ${RUN}`, null, '<p>meins</p>');
	const folderOf = async (id) => Number((await api.must('GET', `/notes/${id}?workspace=${encodeURIComponent(TW)}`)).note.folder_id) || null;
	ctx.ok(await folderOf(tl) === U, 'Tasklist liegt in M/U');
	const wsUrl = (key, extra = '') => ctx.appUrl(`index.php?workspace=${encodeURIComponent(key)}${extra}`);
	const header = (p, id) => p.locator(`#left_col .folder-header[data-folder-id="${id}"]`).first();
	const openFolderMenu = async (p, id) => {
		await header(p, id).locator('.folder-toggle').first().hover();
		await header(p, id).locator('.folder-actions-toggle').first().click();
		await p.waitForSelector('#folder-actions-menu.show', { timeout: 5000 });
	};
	const closeMenus = async (p) => { await p.keyboard.press('Escape'); await p.mouse.click(1300, 850); };
	const visible = (p, sel) => p.locator(sel).first().isVisible().catch(() => false);

	// ---------- 1. Lehrkraft kennzeichnet über das Menü ----------
	await page.goto(wsUrl(TW), { waitUntil: 'networkidle' });
	await ctx.selfTest(page);
	const m1 = ctx.mark();
	ctx.ok(await header(page, M).locator('.nn-material-mark').count() === 0, 'vorher kein Symbol');
	await openFolderMenu(page, M);
	const item = page.locator('#folder-actions-menu .nn-material-item');
	ctx.ok(await item.isVisible().catch(() => false), 'L: Menüeintrag sichtbar');
	ctx.ok(/Als Material kennzeichnen|Mark as material/.test(await item.innerText().catch(() => '')), 'L: „Als Material kennzeichnen“', await item.innerText().catch(() => ''));
	await ctx.screenshot(page, 'menu-teacher');
	const put = page.waitForResponse((r) => r.url().includes(`/folders/${M}/material`) && r.request().method() === 'PUT');
	await item.click();
	const putResp = await put;
	ctx.ok(putResp.status() === 200, 'PUT material → 200', String(putResp.status()));
	ctx.ok(JSON.parse(putResp.request().postData() || '{}').material === true, 'PUT sendet material: true');
	const markM = header(page, M).locator('.nn-material-mark').first();
	ctx.ok(await markM.waitFor({ state: 'visible', timeout: 8000 }).then(() => true, () => false), 'Symbol am Ordner M');
	ctx.ok(/Material/.test(await markM.getAttribute('title') || ''), 'Tooltip nennt Material', await markM.getAttribute('title'));
	const expand = async (p, id, child) => {
		if (!(await header(p, child).isVisible().catch(() => false))) await header(p, id).locator('.folder-toggle').first().click();
	};
	await expand(page, M, U);
	const markU = header(page, U).locator('.nn-material-mark.nn-material-inherited').first();
	ctx.ok(await markU.waitFor({ state: 'attached', timeout: 5000 }).then(() => true, () => false), 'Unterordner U: geerbtes Symbol');
	const opacity = (l) => l.evaluate((e) => Number(getComputedStyle(e).opacity));
	ctx.ok(await opacity(markU) < await opacity(markM), 'geerbtes Symbol gedimmt');
	await ctx.screenshot(page, 'marked-teacher');
	// L sieht alles bearbeitbar
	ctx.ok(await header(page, M).getAttribute('data-nn-write') === '1', 'L: M schreibbar');
	await openFolderMenu(page, M);
	ctx.ok(/entfernen|Remove/.test(await item.innerText().catch(() => '')), 'L: Menü bietet „Kennzeichen entfernen“');
	ctx.ok(await visible(page, '#folder-actions-menu [data-action="rename-folder"]'), 'L: Umbenennen sichtbar');
	await closeMenus(page);
	// geerbt: kein Eintrag; veralteter Baum → 409 als Meldung
	await openFolderMenu(page, U);
	ctx.ok(await item.count() === 0, 'L: an geerbtem Ordner kein Kennzeichnen-Eintrag');
	await closeMenus(page);
	await page.evaluate((id) => document.querySelectorAll(`.folder-actions-toggle[data-folder-id="${id}"]`).forEach((t) => t.setAttribute('data-nn-material', '')), U);
	const free409 = ctx.allow('status', /\/material/, 'erwartete 409 already_material (veralteter Baum)');
	const free409c = ctx.allow('console', /409|Conflict/, 'Browser meldet die erwartete 409');
	await openFolderMenu(page, U);
	const conflict = page.waitForResponse((r) => r.url().includes(`/folders/${U}/material`));
	await item.click();
	ctx.ok((await conflict).status() === 409, 'Unterordner kennzeichnen → 409');
	const alertBox = page.locator('.alert-modal, .modal-alert').filter({ hasText: /übergeordneter|parent/ }).first();
	ctx.ok(await alertBox.waitFor({ state: 'visible', timeout: 5000 }).then(() => true, () => false), '409 als Meldung „übergeordneter Ordner“');
	await ctx.screenshot(page, 'conflict');
	await page.locator('.alert-modal-button, .modal-alert .btn').first().click().catch(() => null);
	free409();
	free409c();
	ctx.assertClean('Lehrkraft kennzeichnet', m1);

	// ---------- 2. Schüler: Symbol, keine Schreibaktionen ----------
	const locks = [];
	pb.on('request', (r) => { if (r.method() === 'POST' && /\/lock(\?|$)/.test(r.url())) locks.push(r.url()); });
	await pb.goto(wsUrl(TW), { waitUntil: 'networkidle' });
	const m2 = ctx.mark();
	ctx.ok(await header(pb, M).locator('.nn-material-mark').first().isVisible().catch(() => false), 'S: Symbol am Ordner M');
	ctx.ok(await header(pb, M).getAttribute('data-nn-write') === '0', 'S: M nicht schreibbar');
	ctx.ok(await header(pb, M).locator('.nn-folder-quick[data-action="create-note-in-folder"]').count() === 0, 'S: kein „Hier erstellen“ an M');
	const dragCancelled = (sel) => pb.locator(sel).first().evaluate((el) => {
		const ev = new DragEvent('dragstart', { bubbles: true, cancelable: true, dataTransfer: new DataTransfer() });
		el.dispatchEvent(ev);
		return ev.defaultPrevented;
	});
	ctx.ok(await dragCancelled(`#left_col .folder-header[data-folder-id="${M}"] > .folder-toggle`), 'S: Ziehen von M abgebrochen');
	ctx.ok(await dragCancelled(`#left_col a[data-note-id="${n1}"]`), 'S: Ziehen der Material-Notiz abgebrochen');
	ctx.ok(!(await dragCancelled(`#left_col a[data-note-id="${sN}"]`)), 'S: eigene Notiz ziehbar');
	await expand(pb, M, U);
	await openFolderMenu(pb, M);
	ctx.ok(await pb.locator('#folder-actions-menu .nn-material-item').count() === 0, 'S: kein Kennzeichnen-Eintrag');
	for (const action of ['create-note-in-folder', 'rename-folder', 'delete-folder', 'move-entire-folder', 'duplicate-folder']) {
		ctx.ok(!(await visible(pb, `#folder-actions-menu [data-action="${action}"]`)), `S: Ordner-Menü ohne ${action}`);
	}
	ctx.ok(await visible(pb, '#folder-actions-menu [data-action="open-kanban-view"]'), 'S: Kanban bleibt');
	await ctx.screenshot(pb, 'menu-student');
	await closeMenus(pb);
	// eigener Ordner außerhalb: Menü vollständig
	ctx.ok(await pb.locator(`.note-actions-toggle[data-note-id="${sN}"]`).first().getAttribute('data-nn-write') === '1', 'S: eigene Notiz schreibbar');
	ctx.assertClean('Schüler Baum', m2);

	// ---------- 3. Schüler öffnet Material-Notiz: nur lesen ----------
	await pb.goto(wsUrl(TW, `&note=${n1}`), { waitUntil: 'networkidle' });
	const m3 = ctx.mark();
	const entry = pb.locator(`#entry${n1}`);
	ctx.ok(await entry.getAttribute('data-nextnotes-readonly') === 'true', 'S: Editor data-nextnotes-readonly');
	ctx.ok(await entry.getAttribute('contenteditable') === 'false', 'S: Editor nicht editierbar');
	ctx.ok(await entry.getAttribute('data-nn-material-copy') === '1', 'S: Duplikat-Kennzeichen am Editor');
	ctx.ok(locks.length === 0, 'S: kein Sperr-Request', locks.join(' '));
	ctx.ok(await pb.locator('.note-edit-lock-banner').count() === 0, 'S: kein Sperr-Banner');
	// UI-fein 1: voll lesbar, Kennzeichen, Lesewerkzeuge frei; Suchen ohne Ersetzen und ohne Schreib-Request
	await assertReadonlyView(ctx, pb, n1, { material: true });
	await ctx.screenshot(pb, 'readonly-student');
	const mS = ctx.mark();
	await pb.evaluate((id) => window.openSearchReplaceModal(String(id)), n1);
	await pb.locator(`#searchInput${n1}`).fill('Arbeits');
	await pb.waitForTimeout(400);
	ctx.ok(/1/.test(await pb.locator(`#searchCount${n1}`).innerText().catch(() => '')), 'S: Suche findet Treffer', await pb.locator(`#searchCount${n1}`).innerText().catch(() => ''));
	ctx.ok(await pb.locator(`#replaceInput${n1}`).isDisabled() && await pb.locator(`#searchToggleReplaceBtn${n1}`).isDisabled(), 'S: Ersetzen gesperrt');
	await pb.locator(`#searchCloseBtn${n1}`).click();
	await pb.waitForTimeout(500);
	const sWrites = ctx.requests.slice(mS.q).filter((r) => r.method !== 'GET' && r.url.includes(`/notes/${n1}`));
	ctx.ok(sWrites.length === 0, 'S: Suche ohne Schreib-Request', sWrites.map((r) => `${r.method} ${r.url}`).join(' '));
	ctx.ok(await pb.locator(`#entry${n1} .search-replace-highlight`).count() === 0, 'S: Treffer-Markierung nach Schließen entfernt');
	// M6c-fix: Glocke und Duplizieren aktiv, Schreibwerkzeuge gesperrt; Erinnerung als Schüler setzen und löschen
	const tb = pb.locator(`#note${n1} .note-edit-toolbar`);
	const usable = (sel) => tb.locator(sel).first().evaluate((b) => !b.disabled && getComputedStyle(b).pointerEvents !== 'none');
	ctx.ok(await usable('.btn-reminder'), 'S: Glocke aktiv');
	ctx.ok(await usable('.btn-duplicate'), 'S: Duplizieren in der Leiste aktiv');
	for (const sel of ['.btn-bold', '.btn-trash', '.btn-move', '.btn-attachment']) {
		ctx.ok(!(await usable(sel)), `S: ${sel} gesperrt`);
	}
	await tb.locator('.btn-reminder').click();
	ctx.ok(await pb.locator('#reminderModal').isVisible(), 'S: Erinnerungs-Dialog offen');
	const when = new Date(Date.now() + 86400000);
	const pad = (x) => String(x).padStart(2, '0');
	// Feld ist versteckt (eigene Datums-/Zeitknöpfe): Wert direkt setzen
	await pb.locator('#reminderDateInput').evaluate((i, v) => { i.value = v; i.dispatchEvent(new Event('input', { bubbles: true })); i.dispatchEvent(new Event('change', { bubbles: true })); },
		`${when.getFullYear()}-${pad(when.getMonth() + 1)}-${pad(when.getDate())}T${pad(when.getHours())}:${pad(when.getMinutes())}`);
	const setRem = pb.waitForResponse((r) => r.url().includes(`/notes/${n1}/reminder`) && r.request().method() === 'POST');
	await pb.locator('#reminderSaveBtn').click();
	ctx.ok((await setRem).status() === 200, 'S: Erinnerung gesetzt → 200');
	ctx.ok(await tb.locator('.btn-reminder.has-reminder').count() === 1, 'S: Glocke markiert');
	ctx.ok(!!(await apiB.must('GET', `/notes/${n1}/reminder`)).reminder_at, 'S: Erinnerung gespeichert');
	await tb.locator('.btn-reminder').click();
	const delRem = pb.waitForResponse((r) => r.url().includes(`/notes/${n1}/reminder`) && r.request().method() === 'DELETE');
	await pb.locator('#reminderRemoveBtn').click();
	ctx.ok((await delRem).status() === 200, 'S: Erinnerung gelöscht → 200');
	ctx.ok((await apiB.must('GET', `/notes/${n1}/reminder`)).reminder_at === null, 'S: keine Erinnerung mehr');
	await ctx.screenshot(pb, 'reminder-student');
	// Notiz-Menü: Duplizieren bleibt, Schreibaktionen weg
	const openNoteMenu = async (p, id) => {
		await p.locator(`#left_col a[data-note-id="${id}"]`).first().hover();
		await p.locator(`#left_col .note-actions-toggle[data-note-id="${id}"]`).first().click();
	};
	await openNoteMenu(pb, n1);
	await pb.waitForSelector('#note-actions-menu.show', { timeout: 5000 });
	for (const action of ['rename-note', 'delete-note', 'show-move-folder-dialog', 'cut-note', 'toggle-favorite']) {
		ctx.ok(!(await visible(pb, `#note-actions-menu [data-action="${action}"]`)), `S: Notiz-Menü ohne ${action}`);
	}
	ctx.ok(await visible(pb, '#note-actions-menu [data-action="duplicate-note"]'), 'S: Duplizieren sichtbar');
	await ctx.screenshot(pb, 'note-menu-student');

	// ---------- 3a. Duplikat (Q1): Kopie privat, Hinweis mit Link, Ansicht bleibt ----------
	const dup = pb.waitForResponse((r) => r.url().includes(`/notes/${n1}/duplicate`) && r.request().method() === 'POST');
	await pb.locator('#note-actions-menu [data-action="duplicate-note"]').first().click();
	const dupResp = await dup;
	ctx.ok(dupResp.status() === 200, 'S: Duplikat → 200', String(dupResp.status()));
	const dupBody = await dupResp.json().catch(() => ({}));
	ctx.ok(!('folder_id' in JSON.parse(dupResp.request().postData() || '{}')), 'S: Duplikat ohne folder_id');
	ctx.ok(dupBody.workspace === privateB, 'Kopie im privaten Workspace', JSON.stringify(dupBody));
	const notice = pb.locator('.nn-copy-notice');
	ctx.ok(await notice.waitFor({ state: 'visible', timeout: 5000 }).then(() => true, () => false), 'Hinweis sichtbar');
	ctx.ok((await notice.innerText().catch(() => '')).includes(privateB), 'Hinweis nennt den privaten Workspace');
	const href = await notice.locator('a').getAttribute('href');
	ctx.ok(href?.includes(`note=${dupBody.id}`) && href.includes(`workspace=${encodeURIComponent(privateB)}`), 'Link auf die Kopie', href);
	ctx.ok(new URL(pb.url()).searchParams.get('workspace') === TW, 'Ansicht bleibt im Team-Workspace');
	ctx.ok(await pb.locator(`#left_col [data-note-id="${dupBody.id}"]`).count() === 0, 'Kopie nicht in der Team-Liste');
	const copy = (await apiB.must('GET', `/notes/${dupBody.id}?workspace=${encodeURIComponent(privateB)}`)).note;
	ctx.ok(copy && !copy.folder_id, 'Kopie in der Wurzel des privaten Workspace', JSON.stringify(copy?.folder_id));
	await ctx.screenshot(pb, 'copy-notice');
	await Promise.all([pb.waitForLoadState('networkidle'), notice.locator('a').click()]);
	ctx.ok(new URL(pb.url()).searchParams.get('workspace') === privateB, 'Link öffnet den privaten Workspace');
	const copyEntry = pb.locator(`#entry${dupBody.id}`);
	ctx.ok(await copyEntry.waitFor({ state: 'visible', timeout: 8000 }).then(() => true, () => false)
		&& await copyEntry.getAttribute('data-nextnotes-readonly') === null, 'Kopie bearbeitbar (kein Nur-Lesen)');
	ctx.assertClean('Schüler Notiz/Duplikat', m3);

	// ---------- 4. Tasklist nur lesen, Kanban ohne Drag, Drop abgelehnt ----------
	locks.length = 0; // die eigene Kopie durfte sperren
	await pb.goto(wsUrl(TW, `&note=${tl}`), { waitUntil: 'networkidle' });
	const m4 = ctx.mark();
	const input = pb.locator(`#task-input-${tl}`);
	ctx.ok(await pb.locator(`#tasks-list-${tl} .task-item, #tasks-list-${tl} [data-task-id]`).count() > 0, 'S: Tasklist gerendert');
	ctx.ok(await input.count() === 0 || !(await input.isVisible()), 'S: Tasklist ohne Eingabefeld');
	const boxes = pb.locator(`#entry${tl} input[type="checkbox"]`);
	ctx.ok(await boxes.count() > 0 && await boxes.first().isDisabled(), 'S: Tasklist-Kästchen deaktiviert');
	// nur Sperren der Material-Tasklist zählen: ein später Lock der eigenen Kopie (Schritt 3) ist erlaubt
	ctx.ok(!locks.some((u) => u.includes(`/notes/${tl}/lock`)), 'S: auch hier kein Sperr-Request', locks.join(' '));
	await ctx.screenshot(pb, 'tasklist-student');
	await pb.goto(ctx.appUrl(`index.php?kanban=${M}&workspace=${encodeURIComponent(TW)}`), { waitUntil: 'networkidle' });
	const card = pb.locator(`.kanban-card[data-note-id="${n1}"]`).first();
	ctx.ok(await card.waitFor({ state: 'attached', timeout: 8000 }).then(() => true, () => false), 'S: Kanban zeigt die Karte');
	ctx.ok(await card.getAttribute('draggable') === 'false', 'S: Kanban-Karte nicht ziehbar');
	ctx.ok(await pb.locator('.kanban-add-card-btn').count() === 0, 'S: Kanban ohne Anlegen-Knopf');
	await pb.goto(wsUrl(TW), { waitUntil: 'networkidle' });
	const before = ctx.mark();
	await pb.locator(`#left_col a[data-note-id="${sN}"]`).first().dragTo(header(pb, M).locator('.folder-toggle').first());
	await pb.waitForTimeout(800);
	const writes = ctx.requests.slice(before.q).filter((r) => r.method !== 'GET' && /\/notes\/|move|folder/.test(r.url));
	ctx.ok(writes.length === 0, 'S: Drop auf Material ohne Request', writes.map((r) => `${r.method} ${r.url}`).join(' '));
	ctx.ok(await folderOf(sN) === null, 'S: eigene Notiz bleibt in der Wurzel');
	ctx.assertClean('Schüler Tasklist/Kanban/Drop', m4);

	// ---------- 5. Tasks-Übersicht: Kästchen aus (P34) ----------
	await pb.goto(ctx.appUrl(`tasks.php?workspace=${encodeURIComponent(TW)}`), { waitUntil: 'networkidle' });
	const m5 = ctx.mark();
	const taskBox = pb.locator('.tasks-task-checkbox').first();
	ctx.ok(await taskBox.waitFor({ state: 'attached', timeout: 8000 }).then(() => true, () => false), 'Tasks-Übersicht zeigt die Liste');
	ctx.ok(await taskBox.isDisabled(), 'S: Kästchen in der Übersicht deaktiviert');
	const tasksRaw = await apiB.must('GET', `/tasks?workspace=${encodeURIComponent(TW)}`);
	ctx.ok(tasksRaw.notes?.find((n) => n.id === tl)?.readonly === true, 'GET /tasks: readonly für S');
	const tasksA = await api.must('GET', `/tasks?workspace=${encodeURIComponent(TW)}`);
	ctx.ok(tasksA.notes?.find((n) => n.id === tl)?.readonly === false, 'GET /tasks: L schreibt');
	ctx.assertClean('Tasks-Übersicht', m5);

	// ---------- 6. Lehrkraft: Notiz bearbeitbar, Duplikat bleibt im Ordner; privat ohne Material ----------
	await page.goto(wsUrl(TW, `&note=${n1}`), { waitUntil: 'networkidle' });
	const m6 = ctx.mark();
	ctx.ok(await page.locator(`#entry${n1}`).getAttribute('contenteditable') === 'true', 'L: Material-Notiz bearbeitbar');
	ctx.ok(await page.locator(`#entry${n1}`).getAttribute('data-nn-material-copy') === null, 'L: kein Q1-Kennzeichen');
	const dupL = page.waitForResponse((r) => r.url().includes(`/notes/${n1}/duplicate`) && r.request().method() === 'POST');
	await openNoteMenu(page, n1);
	await page.waitForSelector('#note-actions-menu.show', { timeout: 5000 });
	ctx.ok(await visible(page, '#note-actions-menu [data-action="rename-note"]'), 'L: Umbenennen sichtbar');
	await page.locator('#note-actions-menu [data-action="duplicate-note"]').first().click();
	const dupLBody = await (await dupL).json().catch(() => ({}));
	ctx.ok(!dupLBody.workspace && await folderOf(dupLBody.id) === M, 'L: Duplikat im Material-Ordner', JSON.stringify(dupLBody));
	ctx.ok(await page.locator('.nn-copy-notice').count() === 0, 'L: kein Q1-Hinweis');
	const pf = await api.folder(privateA, `Privat ${RUN}`);
	await page.goto(wsUrl(privateA), { waitUntil: 'networkidle' });
	ctx.ok(await page.locator('#left_col .nn-material-mark').count() === 0, 'privat: kein Symbol');
	await openFolderMenu(page, pf);
	ctx.ok(await page.locator('#folder-actions-menu .nn-material-item').count() === 0, 'privat: kein Material-Eintrag');
	ctx.ok(await visible(page, '#folder-actions-menu [data-action="rename-folder"]'), 'privat: Menü vollständig');
	await closeMenus(page);
	// Entfernen gibt sofort Schreibrecht
	await api.must('PUT', `/folders/${M}/material`, { material: false });
	await pb.goto(wsUrl(TW, `&note=${n1}`), { waitUntil: 'networkidle' });
	ctx.ok(await pb.locator(`#entry${n1}`).getAttribute('contenteditable') === 'true', 'nach Entfernen: S schreibt');
	ctx.ok(await header(pb, M).locator('.nn-material-mark').count() === 0, 'nach Entfernen: kein Symbol');
	ctx.assertClean('Lehrkraft/privat', m6);
	await ctxB.close();
});
