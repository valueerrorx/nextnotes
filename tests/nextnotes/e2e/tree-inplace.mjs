// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// M3-fix2 (P18, B49): Ordner-Aktionen der linken Spalte ohne Seiten-Reload. Markierung auf window überlebt keinen Reload;
// offene Notiz bleibt offen, aufgeklappter Ordner bleibt offen, Klick/Notiz-Öffnen funktionieren nach dem Neuaufbau.
import { runSpec } from './lib/harness.mjs';
import { apiClient } from './lib/api.mjs';

const RUN = `${process.argv[3]}${Date.now().toString(36)}`;

await runSpec(import.meta.url, async (ctx, page) => {
	const api = apiClient(ctx, page.request);
	const WS = await api.workspace(`Baum ${RUN}`);
	const A = await api.folder(WS, `A ${RUN}`);
	const B = await api.folder(WS, `B ${RUN}`);
	const D = await api.folder(WS, `D ${RUN}`);
	const E = await api.folder(WS, `E ${RUN}`);
	const M = await api.folder(WS, `M ${RUN}`);
	const id = await api.note(WS, `Offen ${RUN}`, A, `<p>Inhalt ${RUN}</p>`);
	const other = await api.note(WS, `Zweite ${RUN}`, B, `<p>Zweite ${RUN}</p>`);
	await api.note(WS, `Leeren ${RUN}`, E, '<p>e</p>');
	const G = await api.folder(WS, `G ${RUN}`);
	await api.note(WS, `Archiv ${RUN}`, G, '<p>g</p>');
	const H = await api.folder(WS, `H ${RUN}`);
	const J = await api.folder(WS, `J ${RUN}`);
	const j1 = await api.note(WS, `J1 ${RUN}`, J, '<p>j1</p>');
	const j2 = await api.note(WS, `J2 ${RUN}`, J, '<p>j2</p>');
	let openId = id;
	await page.goto(ctx.appUrl(`index.php?workspace=${encodeURIComponent(WS)}&note=${id}`), { waitUntil: 'networkidle' });
	await ctx.selfTest(page);
	await page.waitForSelector(`#entry${id}`);
	await page.evaluate(() => { window.__nnNoReload = 1; });
	let navs = 0;
	page.on('domcontentloaded', () => { navs++; });

	const header = (fid) => page.locator(`#left_col .folder-header[data-folder-key="folder_${fid}"]`);
	const waitFor = async (fn, ms = 8000) => {
		for (const end = Date.now() + ms; Date.now() < end; await page.waitForTimeout(200)) if (await fn()) return true;
		return fn();
	};
	const still = async (label) => {
		ctx.ok(await page.evaluate(() => window.__nnNoReload === 1) && navs === 0, `${label}: kein Seiten-Reload`, `navs=${navs}`);
		ctx.ok(await page.locator(`#entry${openId}`).isVisible(), `${label}: offene Notiz bleibt offen`);
		ctx.ok(await page.locator(`#folder-${A}`).isVisible(), `${label}: aufgeklappter Ordner A bleibt offen`);
		ctx.ok((await page.locator('.alert-modal-overlay.show').count()) === 0, `${label}: kein Dialog bleibt stehen`);
	};
	const typeInline = async (name) => {
		const input = page.locator('#left_col .tree-inline-input');
		await input.waitFor({ timeout: 5000 });
		await input.fill(name);
		await input.press('Enter');
	};
	const fragmentReq = (r) => r.request().method() === 'GET' && r.request().headers()['x-poznote-fragment'] === 'left_col';

	// 1. Ordner anlegen (Wurzel), erscheint links; Klick klappt ihn auf
	{
		const m = ctx.mark();
		const name = `Neu ${RUN}`;
		const frag = page.waitForResponse(fragmentReq, { timeout: 10000 });
		await page.evaluate(() => window.newFolder());
		await typeInline(name);
		const r = await frag;
		ctx.ok(r.status() === 200, `Anlegen: Fragment left_col geladen (HTTP ${r.status()})`);
		ctx.ok(!(await r.text()).includes('id="right_col"'), 'Anlegen: Fragment enthält nur die linke Spalte');
		const row = page.locator(`#left_col .folder-header[data-folder="${name}"]`);
		ctx.ok(await waitFor(async () => (await row.count()) === 1), 'Anlegen: neuer Ordner erscheint links');
		await still('Anlegen');
		const fid = await row.getAttribute('data-folder-id');
		const content = page.locator(`#folder-${fid}`);
		const before = await content.evaluate((el) => el.style.display);
		await row.locator('.folder-name').click();
		ctx.ok(await waitFor(async () => (await content.evaluate((el) => el.style.display)) !== before), 'Anlegen: Klick auf neuen Ordner schaltet ihn um', `vorher ${before}`);
		await still('Anlegen/Klick');
		ctx.assertClean('Anlegen', m);
	}

	// 2. Unterordner in A
	{
		const m = ctx.mark();
		const name = `Unter ${RUN}`;
		await page.evaluate((k) => window.createSubfolder(k), `folder_${A}`);
		await typeInline(name);
		const row = page.locator(`#folder-${A} .folder-header[data-folder="${name}"]`);
		ctx.ok(await waitFor(async () => (await row.count()) === 1), 'Unterordner: erscheint in A');
		await still('Unterordner');
		ctx.assertClean('Unterordner', m);
	}

	// 3. Ordner umbenennen (inline)
	{
		const m = ctx.mark();
		const name = `B2 ${RUN}`;
		await page.evaluate(([f, n]) => window.editFolderName(f, n), [B, `B ${RUN}`]);
		await typeInline(name);
		ctx.ok(await waitFor(async () => (await header(B).getAttribute('data-folder')) === name), 'Umbenennen: neuer Name links');
		await still('Umbenennen');
		ctx.assertClean('Umbenennen', m);
	}

	// 4. Ordner verschieben (Drag&Drop-Pfad) und umsortieren
	{
		const m = ctx.mark();
		await page.evaluate(([f, p]) => window.moveFolderToParent(f, p), [M, B]);
		ctx.ok(await waitFor(async () => (await page.locator(`#folder-${B} .folder-header[data-folder-key="folder_${M}"]`).count()) === 1), 'Verschieben: M liegt in B');
		await still('Verschieben');
		await page.evaluate(([f, t]) => window.moveFolderBesideTarget(f, t, 'before'), [D, A]);
		ctx.ok(await waitFor(async () => page.evaluate(([d, a]) => {
			const keys = [...document.querySelectorAll('#left_col .folder-header[data-folder-key]')].map((h) => h.dataset.folderKey);
			return keys.indexOf(`folder_${d}`) !== -1 && keys.indexOf(`folder_${d}`) < keys.indexOf(`folder_${a}`);
		}, [D, A])), 'Umsortieren: D vor A');
		await still('Umsortieren');
		ctx.assertClean('Verschieben', m);
	}

	// 5. Ordner leeren und löschen
	{
		const m = ctx.mark();
		await page.evaluate(([f, n]) => window.executeEmptyFolder(f, n), [E, `E ${RUN}`]);
		ctx.ok(await waitFor(async () => (await page.locator(`#folder-${E} .links_arbo_left`).count()) === 0), 'Leeren: Notiz aus E entfernt');
		await still('Leeren');
		await page.evaluate(([f, n]) => window.executeDeleteFolderOperation(f, n), [D, `D ${RUN}`]);
		ctx.ok(await waitFor(async () => (await header(D).count()) === 0), 'Löschen: D verschwindet links');
		await still('Löschen');
		ctx.assertClean('Löschen', m);
	}

	// 6. offene Notiz inline umbenennen
	{
		const m = ctx.mark();
		const name = `Offen2 ${RUN}`;
		await page.evaluate(([n, t]) => window.PoznoteInlineTreeEdit.renameNote(n, t), [id, `Offen ${RUN}`]);
		await typeInline(name);
		ctx.ok(await waitFor(async () => (await page.locator(`#left_col .links_arbo_left[data-note-db-id="${id}"]`).first().innerText()).includes(name)), 'Notiz umbenennen: neuer Titel links');
		await still('Notiz umbenennen');
		ctx.assertClean('Notiz umbenennen', m);
	}

	// 7. Klick-Delegation nach Neuaufbau: andere Notiz öffnen (AJAX, ohne Reload)
	{
		const m = ctx.mark();
		await page.locator(`#left_col .links_arbo_left[data-note-db-id="${other}"]`).first().click();
		ctx.ok(await waitFor(async () => (await page.locator(`#entry${other}`).count()) === 1), 'Notiz-Klick: zweite Notiz geöffnet');
		ctx.ok(await page.evaluate(() => window.__nnNoReload === 1) && navs === 0, 'Notiz-Klick: kein Seiten-Reload');
		ctx.assertClean('Notiz-Klick', m);
		openId = other;
	}

	// 8. Ordner archivieren (Bestätigungsdialog), offene Notiz liegt nicht darin → In-place
	{
		const m = ctx.mark();
		await page.evaluate(([f, n]) => window.archiveFolder(f, n), [G, `G ${RUN}`]);
		await page.locator('#confirmButton').click();
		ctx.ok(await waitFor(async () => (await header(G).count()) === 0), 'Archivieren: G verschwindet links');
		await still('Archivieren');
		ctx.ok(await page.locator('#confirmModal').evaluate((el) => getComputedStyle(el).display === 'none'), 'Archivieren: Bestätigungsdialog geschlossen');
		ctx.assertClean('Archivieren', m);
	}

	// 9. Ordner per Modal in Unterordner A verschieben (executeMoveFolderToSubfolder)
	{
		const m = ctx.mark();
		await page.evaluate(([f, n]) => window.showMoveEntireFolderDialog(f, n), [H, `H ${RUN}`]);
		const sel = page.locator('#moveFolderTargetSelect');
		ctx.ok(await waitFor(async () => (await sel.locator(`option[value="${A}"]`).count()) === 1), 'Modal-Verschieben: Ziel A wählbar');
		await sel.selectOption(String(A));
		await page.locator('#moveFolderModal .btn-primary').click();
		ctx.ok(await waitFor(async () => (await page.locator(`#folder-${A} .folder-header[data-folder-key="folder_${H}"]`).count()) === 1), 'Modal-Verschieben: H liegt in A');
		ctx.ok(await page.locator('#moveFolderModal').evaluate((el) => getComputedStyle(el).display === 'none'), 'Modal-Verschieben: Dialog geschlossen');
		await still('Modal-Verschieben');
		ctx.assertClean('Modal-Verschieben', m);
	}

	// 10. alle Notizen aus J nach A verschieben (executeMoveAllFiles)
	{
		const m = ctx.mark();
		await page.evaluate(([f, n]) => window.showMoveFolderFilesDialog(f, n), [J, `J ${RUN}`]);
		const sel = page.locator('#moveFolderFilesTargetSelect');
		ctx.ok(await waitFor(async () => (await sel.locator(`option[value="${A}"]`).count()) === 1), 'Alle verschieben: Ziel A wählbar');
		await sel.selectOption(String(A));
		const btn = page.locator('#moveFolderFilesModal .btn-primary');
		ctx.ok(await waitFor(async () => btn.isEnabled()), 'Alle verschieben: Button aktiv');
		await btn.click();
		const inA = async (n) => (await page.locator(`#folder-${A} .links_arbo_left[data-note-db-id="${n}"]`).count()) >= 1;
		ctx.ok(await waitFor(async () => (await inA(j1)) && (await inA(j2))), 'Alle verschieben: J1, J2 liegen in A');
		ctx.ok((await page.locator(`#folder-${J} .links_arbo_left`).count()) === 0, 'Alle verschieben: J leer');
		ctx.ok(await page.locator('#moveFolderFilesModal').evaluate((el) => getComputedStyle(el).display === 'none'), 'Alle verschieben: Dialog geschlossen');
		await still('Alle verschieben');
		ctx.assertClean('Alle verschieben', m);
	}
	await ctx.screenshot(page, 'ende');

	// 11. Ordner B mit der offenen Notiz löschen → Reload-Zweig; danach Notiz nicht mehr offen, Seite intakt
	{
		const m = ctx.mark();
		ctx.ok(await page.evaluate((o) => String(window.noteid) === String(o), other), 'Löschen offen: window.noteid ist die offene Notiz');
		const nav = page.waitForEvent('domcontentloaded', { timeout: 15000 });
		await page.evaluate(([f, n]) => window.executeDeleteFolderOperation(f, n), [B, `B2 ${RUN}`]);
		await nav;
		await page.waitForLoadState('networkidle');
		ctx.ok(navs >= 1 && (await page.evaluate(() => window.__nnNoReload)) === undefined, 'Löschen offen: Seite neu geladen', `navs=${navs}`);
		ctx.ok((await page.locator(`#entry${other}`).count()) === 0, 'Löschen offen: gelöschte Notiz nicht mehr offen', page.url());
		ctx.ok(await page.locator('#left_col').isVisible(), 'Löschen offen: linke Spalte da');
		ctx.ok((await header(B).count()) === 0, 'Löschen offen: B verschwindet links');
		ctx.ok((await header(A).count()) === 1, 'Löschen offen: A noch da');
		ctx.ok((await page.locator('.alert-modal-overlay.show').count()) === 0, 'Löschen offen: kein Dialog bleibt stehen');
		// Seite bleibt bedienbar: Notiz aus A per Klick öffnen
		const link = page.locator(`#left_col .links_arbo_left[data-note-db-id="${id}"]`).first();
		if (!(await link.isVisible())) await header(A).locator('.folder-name').first().click();
		await link.click();
		ctx.ok(await waitFor(async () => (await page.locator(`#entry${id}`).count()) === 1), 'Löschen offen: danach Notiz aus A öffnenbar');
		ctx.assertClean('Löschen offen', m);
		await ctx.screenshot(page, 'nach-reload');
	}
});
