// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// M4a Done 4: Workspace-Menü → „Neuer Workspace“ → Name + Tags (mit ') → Seite wechselt auf den neuen Workspace;
// Name mit < oder ' → Fehlermeldung im Dialog, nichts angelegt (Namensregel Upstream/B20: Buchstaben, Ziffern, Leerzeichen, - _). 0 CSP/Konsole.
import { runSpec } from './lib/harness.mjs';
import { apiClient } from './lib/api.mjs';

const RUN = `${process.argv[3]}${Date.now().toString(36)}`;

await runSpec(import.meta.url, async (ctx, page) => {
	const api = apiClient(ctx, page.request);
	const start = await api.defaultWorkspace();
	const openDialog = async () => {
		await page.locator('.sidebar-title').first().click({ force: true });
		const item = page.locator('#workspaceMenu [data-action="new-workspace"]').first();
		ctx.ok(await item.waitFor({ state: 'visible', timeout: 5000 }).then(() => true, () => false), 'Menüeintrag „Neuer Workspace“ sichtbar');
		await item.click();
		await page.waitForSelector('#createWorkspaceModal', { state: 'visible', timeout: 5000 });
	};

	await page.goto(ctx.appUrl(`index.php?workspace=${encodeURIComponent(start)}`), { waitUntil: 'networkidle' });
	await ctx.selfTest(page);
	const m = ctx.mark();

	// ungültige Namen: Meldung im Dialog, kein Request, nichts angelegt
	for (const bad of [`<b>${RUN}`, `O'Brien ${RUN}`]) {
		await openDialog();
		const posts = ctx.requests.length;
		await page.fill('#createWorkspaceInput', bad);
		await page.click('#confirmCreateWorkspaceBtn');
		await page.waitForTimeout(500);
		const err = await page.locator('#createWorkspaceError').innerText();
		ctx.ok(err.trim() !== '' && await page.locator('#createWorkspaceModal').isVisible(), `Name „${bad}“: Fehlermeldung im Dialog`, err);
		ctx.ok(!ctx.requests.slice(posts).some((r) => r.method === 'POST' && r.url.includes('/api/v1/workspaces')), `Name „${bad}“: kein POST`);
		await page.click('#createWorkspaceModal [data-action="close-modal"]');
	}
	const names = await api.workspaces();
	ctx.ok(!names.some((n) => n.includes(RUN)), 'ungültige Namen: nichts angelegt');

	// gültiger Name mit Umlaut, Tags mit Apostroph
	const name = `Projekt Ä ${RUN}`;
	await openDialog();
	await page.fill('#createWorkspaceInput', name);
	await page.fill('#createWorkspaceTagsInput', "Schule, O'Neill");
	const resp = page.waitForResponse((r) => r.url().includes('/api/v1/workspaces') && r.request().method() === 'POST');
	await page.click('#confirmCreateWorkspaceBtn');
	ctx.ok((await resp).status() === 201, 'Anlegen → 201');
	const switched = await page.waitForFunction((n) => new URL(location.href).searchParams.get('workspace') === n
		&& document.querySelector('#left_col .workspace-title-text')?.textContent.trim() === n, name, { timeout: 10000 }).then(() => true, () => false);
	ctx.ok(switched, 'Seite wechselt auf den neuen Workspace');
	ctx.ok(!(await page.locator('#createWorkspaceModal').isVisible()), 'Dialog geschlossen');
	const ws = ((await api.must('GET', '/workspaces')).workspaces ?? []).find((w) => w.name === name);
	ctx.ok(JSON.stringify(ws?.tags ?? []) === JSON.stringify(['Schule', "O'Neill"]), `Tags gespeichert (${JSON.stringify(ws?.tags)})`);
	await page.reload({ waitUntil: 'networkidle' });
	ctx.ok((await page.locator('#left_col .workspace-title-text').innerText()).trim() === name, 'nach Reload im neuen Workspace');
	await ctx.screenshot(page, 'created');
	ctx.assertClean('Neuer Workspace', m);
});
