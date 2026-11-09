// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// M6-wsbind (ADR 0021): Aufgabe gehört dem Team-Workspace, in dem sie angelegt wurde. Liste und ?assignment= nur dort (Lehrkraft, Schüler),
// nicht im zweiten Workspace desselben Teams. Verwaistes Team (L1): owner_uid und Schüler sehen die Aufgabe im eigenen Workspace, nicht im
// noch lesbaren Team-Workspace. Lupe bei > 200 Notizen: zuletzt geänderte zuerst, Hinweis mit Filter. Lösch-Dialog nennt die Aufgaben
// (mit Ziel „zieht mit um“, letzter Workspace „löscht auch“), Löschen über die Oberfläche entfernt die Aufgabe. 0 CSP/Konsole.
import { runSpec } from './lib/harness.mjs';
import { apiClient } from './lib/api.mjs';
import { createTeam, orphanTeam } from './lib/docker.mjs';

const RUN = `${process.argv[3]}${Date.now().toString(36)}`;
const A = process.env.NN_USER;
const B = process.env.NN_USER2;
const due = () => Math.floor(Date.now() / 1000) + 7200;

await runSpec(import.meta.url, async (ctx, page, context, browser) => {
	const api = apiClient(ctx, page.request);
	const teamId = createTeam(ctx.base, A, `Bind ${RUN}`, { [B]: 1 });
	const TWA = `Kurs A ${RUN}`;
	const TWB = `Kurs B ${RUN}`;
	await api.must('POST', '/workspaces', { name: TWA, team: teamId });
	await api.must('POST', '/workspaces', { name: TWB, team: teamId });
	const srcA = await api.note(TWA, `Blatt A ${RUN}`, null, '<p>a</p>');
	const aid = (await api.must('POST', '/assignments', { workspace: TWA, title: `Aufgabe A ${RUN}`, due_at: due(), source_note_id: srcA })).assignment.id;
	await api.must('POST', `/assignments/${aid}/distribute`);
	// zweites Team für L1 (verwaist)
	const team2 = createTeam(ctx.base, A, `Bind2 ${RUN}`, { [B]: 1 });
	const TW2 = `Kurs O ${RUN}`;
	await api.must('POST', '/workspaces', { name: TW2, team: team2 });
	const src2 = await api.note(TW2, `Blatt O ${RUN}`, null, '<p>o</p>');
	const oid = (await api.must('POST', '/assignments', { workspace: TW2, title: `Aufgabe O ${RUN}`, due_at: due(), source_note_id: src2 })).assignment.id;
	await api.must('POST', `/assignments/${oid}/distribute`);

	const ctxB = await browser.newContext({ viewport: { width: 1400, height: 900 }, locale: 'de-DE' });
	await ctx.attach(ctxB);
	const pb = await ctxB.newPage();
	await ctx.login(pb, B, process.env.NN_PASS2);

	const listed = async (p, query, id) => {
		await p.goto(ctx.appUrl(`assignments.php?${query}`), { waitUntil: 'networkidle' });
		await p.locator('#nnAsgList[aria-busy="false"]').waitFor({ state: 'attached', timeout: 10000 });
		return await p.locator(`.nn-asg-item[data-assignment-id="${id}"]`).count() === 1;
	};
	const opened = async (p, query, id) => {
		await p.goto(ctx.appUrl(`assignments.php?${query}&assignment=${id}`), { waitUntil: 'networkidle' });
		await p.locator('#nnAsgList[aria-busy="false"]').waitFor({ state: 'attached', timeout: 10000 });
		return await p.locator('#nnAsgDetail:not([hidden])').isVisible().catch(() => false);
	};
	const wq = (ws) => `workspace=${encodeURIComponent(ws)}`;
	// eigener Workspace ausdrücklich (ohne ?workspace= gilt der zuletzt geöffnete)
	const ownA = wq(((await api.must('GET', '/workspaces')).workspaces ?? []).find((w) => w.team === null).name);
	const ownB = wq(((await apiClient(ctx, ctxB.request, B, process.env.NN_PASS2).must('GET', '/workspaces')).workspaces ?? []).find((w) => w.team === null).name);

	// ---------- 1. Liste je Workspace (Lehrkraft, Schüler) ----------
	let m = ctx.mark();
	ctx.ok(await listed(page, wq(TWA), aid), 'L: Aufgabe in A gelistet');
	ctx.ok(!(await listed(page, wq(TWB), aid)), 'L: Aufgabe nicht in B');
	ctx.ok(!(await opened(page, wq(TWB), aid)), 'L: ?assignment= in B öffnet nicht');
	ctx.ok(await opened(page, wq(TWA), aid), 'L: ?assignment= in A öffnet');
	await ctx.screenshot(page, 'liste-a');
	ctx.ok(await listed(pb, wq(TWA), aid), 'S: Aufgabe in A gelistet');
	ctx.ok(!(await listed(pb, wq(TWB), aid)), 'S: Aufgabe nicht in B');
	ctx.ok(!(await listed(pb, ownB, aid)), 'S: aktive Aufgabe nicht im eigenen Workspace');
	ctx.assertClean('Liste je Workspace', m);

	// ---------- 2. L1: verwaistes Team → eigener Workspace, nicht der (für L noch lesbare) Team-Workspace ----------
	orphanTeam(ctx.base, A, team2);
	m = ctx.mark();
	ctx.ok(!(await listed(page, wq(TW2), oid)), 'L: verwaiste Aufgabe nicht im Team-Workspace');
	ctx.ok(!(await opened(page, wq(TW2), oid)), 'L: ?assignment= im Team-Workspace öffnet nicht');
	ctx.ok(await listed(page, ownA, oid), 'L (owner_uid): verwaiste Aufgabe im eigenen Workspace');
	ctx.ok(await page.locator('.nn-asg-section-title').isVisible().catch(() => false), 'L: Abschnitt „nicht mehr aktiv“');
	ctx.ok(!(await listed(page, ownA, aid)), 'L: aktive Aufgabe nicht im eigenen Workspace');
	ctx.ok(await opened(page, ownA, oid), 'L: ?assignment= im eigenen Workspace öffnet');
	ctx.ok(await listed(pb, ownB, oid), 'S: verwaiste Aufgabe im eigenen Workspace');
	await ctx.screenshot(pb, 'verwaist-schueler');
	ctx.assertClean('verwaist', m);

	// ---------- 3. Lupe: > 200 Notizen, zuletzt geänderte zuerst; Hinweis mit 1-Zeichen-Filter ----------
	const folder = await api.folder(TWB, `Ordner ${RUN}`);
	await api.note(TWB, `Alt im Ordner ${RUN}`, folder, '<p>alt</p>');
	for (let i = 0; i < 205; i += 1) await api.note(TWB, `Füll ${i} ${RUN}`, null, '<p>f</p>');
	await new Promise((r) => setTimeout(r, 1100));
	const newest = `Neueste ${RUN}`;
	await api.note(TWB, newest, folder, '<p>neu</p>');
	m = ctx.mark();
	await page.goto(ctx.appUrl(`assignments.php?${wq(TWB)}`), { waitUntil: 'networkidle' });
	await page.locator('#nnAsgNew').click();
	await page.locator('#nnAsgCreateOk').waitFor({ state: 'visible', timeout: 10000 });
	await page.locator('#nnAsgCreateShowAll').click();
	await page.locator('.nn-asg-source-result').first().waitFor({ timeout: 15000 });
	const first = await page.locator('.nn-asg-source-result .nn-asg-source-title').first().innerText();
	ctx.ok(first === newest, 'Lupe: zuletzt geänderte Notiz zuerst (auch im Ordner)', first);
	const more = await page.locator('.nn-asg-source-more').innerText();
	ctx.ok(/50 von 207/.test(more), 'Hinweis ungefiltert mit Server-Gesamtzahl', more);
	await page.locator('#nnAsgCreateSearch').fill('F');
	await page.locator('.nn-asg-source-more').filter({ hasText: /200/ }).waitFor({ timeout: 10000 });
	const filtered = await page.locator('.nn-asg-source-more').innerText();
	ctx.ok(/Nur die 200 zuletzt geänderten Notizen durchsucht/.test(filtered), 'Hinweis mit Filter: nur geladene durchsucht', filtered);
	await ctx.screenshot(page, 'lupe-200');
	await page.keyboard.press('Escape');
	await page.keyboard.press('Escape');
	ctx.assertClean('Lupe', m);

	// ---------- 4. Lösch-Dialog: Aufgabenzahl, mit Ziel „zieht mit um“, letzter Workspace „löscht auch“ ----------
	const openDelete = async (ws) => {
		await page.goto(ctx.appUrl('settings.php#section=settings-workspaces-section-grid'), { waitUntil: 'networkidle' });
		const row = page.locator(`.workspace-list li.ws-row[data-ws="${ws}"]`);
		await row.scrollIntoViewIfNeeded();
		await row.locator('.ws-actions-toggle').click();
		await row.locator('.btn-delete').click();
		await page.locator('#deleteModal').waitFor({ state: 'visible', timeout: 5000 });
		return await page.locator('#nnDeleteTeamInfo').innerText();
	};
	m = ctx.mark();
	let info = await openDelete(TWA);
	ctx.ok(/Die Aufgabe zieht mit um/.test(info), 'mit Ziel: Aufgabe zieht mit um', info);
	await ctx.screenshot(page, 'loeschen-mit-ziel');
	await api.must('DELETE', `/workspaces/${encodeURIComponent(TWB)}`, { target: TWA });
	info = await openDelete(TWA);
	ctx.ok(/Löscht auch 1 Aufgabe samt aller Abgaben endgültig/.test(info), 'letzter Workspace: nennt die Aufgabe', info);
	await ctx.screenshot(page, 'loeschen-letzter');
	await page.locator('#confirmDeleteInput').fill(TWA);
	const del = page.waitForResponse((r) => new URL(r.url()).pathname.endsWith('/workspaces.php') && r.request().method() === 'POST');
	await page.locator('#confirmDeleteBtn').click();
	ctx.ok((await del).status() === 200, 'Löschen über die Oberfläche → 200');
	await page.waitForLoadState('networkidle');
	ctx.ok((await api.call('GET', `/assignments/${aid}`)).status === 404, 'Aufgabe mit dem letzten Workspace gelöscht');
	ctx.assertClean('Lösch-Dialog', m);
	await ctxB.close();
});
