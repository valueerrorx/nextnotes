// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// M5e (Plan M5 §3 M5e, SPEC §13 M5 Krit. 1/2): A legt über „Neuer Workspace“ einen Team-Workspace an, schreibt eine Notiz;
// B (Mitglied) sieht Workspace und Notiz in der Hauptansicht, öffnet sie, findet sie im Team-Arbeitsbereich (Teams-OCS);
// A entfernt B aus dem Team → B sieht beim nächsten Request nichts mehr (ohne Job). 0 CSP/Konsole/≥ 400 bis zum Austritt.
import { runSpec } from './lib/harness.mjs';
import { apiClient } from './lib/api.mjs';
import { createTeam, dropTeams, removeTeamMember } from './lib/docker.mjs';

const RUN = `${process.argv[3]}${Date.now().toString(36)}`;
const A = process.env.NN_USER;
const B = process.env.NN_USER2;

await runSpec(import.meta.url, async (ctx, page, context, browser) => {
	const api = apiClient(ctx, page.request);
	const teamName = `Smoke ${RUN}`;
	const teamId = createTeam(ctx.base, A, teamName, { [B]: 1 });
	ctx.ok(/^[A-Za-z0-9]+$/.test(teamId), 'Team angelegt', teamId);
	const start = await api.defaultWorkspace();

	// A: Team-Workspace über die Oberfläche
	await page.goto(ctx.appUrl(`index.php?workspace=${encodeURIComponent(start)}`), { waitUntil: 'networkidle' });
	await ctx.selfTest(page);
	const m = ctx.mark();
	await page.locator('.sidebar-title').first().click({ force: true });
	await page.locator('#workspaceMenu [data-action="new-workspace"]').first().click();
	await page.waitForSelector('#createWorkspaceModal', { state: 'visible', timeout: 5000 });
	await page.locator(`#createWorkspaceTeamSelect option[value="${teamId}"]`).waitFor({ state: 'attached', timeout: 5000 });
	const TW = `TeamWS ${RUN}`;
	await page.fill('#createWorkspaceInput', TW);
	await page.selectOption('#createWorkspaceTeamSelect', teamId);
	const created = page.waitForResponse((r) => r.url().includes('/api/v1/workspaces') && r.request().method() === 'POST');
	await page.click('#confirmCreateWorkspaceBtn');
	ctx.ok((await created).status() === 201, 'A: Team-Workspace → 201');
	const note = await api.note(TW, `Teamnotiz ${RUN}`, null, `<p>Inhalt ${RUN}</p>`);
	await page.goto(ctx.appUrl(`index.php?workspace=${encodeURIComponent(TW)}`), { waitUntil: 'networkidle' });
	ctx.ok(await page.locator(`#left_col [data-note-id="${note}"], #left_col a[href*="note=${note}"]`).first()
		.waitFor({ state: 'visible', timeout: 10000 }).then(() => true, () => false), 'A: Notiz in der Liste');
	ctx.assertClean('A Team-Workspace', m);

	// B: liest in der Hauptansicht und im Team-Arbeitsbereich
	const ctxB = await browser.newContext({ viewport: { width: 1400, height: 900 }, locale: 'de-DE' });
	await ctx.attach(ctxB);
	const pb = await ctxB.newPage();
	await ctx.login(pb, B, process.env.NN_PASS2);
	const apiB = apiClient(ctx, ctxB.request, B, process.env.NN_PASS2);
	const mb = ctx.mark();
	const wsB = ((await apiB.must('GET', '/workspaces')).workspaces ?? []).find((w) => w.name === TW);
	ctx.ok(wsB?.team === teamName, 'B: Team-Workspace in GET /workspaces', JSON.stringify(wsB));
	await pb.goto(ctx.appUrl(`index.php?workspace=${encodeURIComponent(TW)}&note=${note}`), { waitUntil: 'networkidle' });
	ctx.ok(await pb.locator(`#left_col [data-note-id="${note}"], #left_col a[href*="note=${note}"]`).first()
		.waitFor({ state: 'visible', timeout: 10000 }).then(() => true, () => false), 'B: Notiz in der Liste');
	ctx.ok((await pb.locator('#right_col').innerText()).includes(`Inhalt ${RUN}`), 'B: Notiz geöffnet');
	await ctx.screenshot(pb, 'member');
	const res = await ctxB.request.get(`${ctx.base}/ocs/v2.php/teams/${teamId}/resources?format=json`, {
		headers: { Authorization: `Basic ${Buffer.from(`${B}:${process.env.NN_PASS2}`).toString('base64')}`, 'OCS-APIRequest': 'true' },
	});
	const items = ((await res.json().catch(() => ({})))?.ocs?.data?.resources ?? []).filter((r) => r.provider?.id === 'nextnotes');
	ctx.ok(items.some((r) => r.label === TW), 'B: Team-Arbeitsbereich enthält den Workspace', JSON.stringify(items));
	ctx.assertClean('B liest', mb);

	// Austritt: nächster Request ohne Job → weg
	removeTeamMember(ctx.base, A, teamId, B);
	ctx.ok((await apiB.call('GET', `/notes/${note}`)).status === 404, 'B nach Austritt: GET /notes → 404');
	ctx.ok(!((await apiB.must('GET', '/workspaces')).workspaces ?? []).some((w) => w.name === TW), 'B nach Austritt: Workspace nicht mehr gelistet');
	const gone = await pb.goto(ctx.appUrl(`index.php?workspace=${encodeURIComponent(TW)}&note=${note}`), { waitUntil: 'networkidle' });
	ctx.ok(gone?.status() === 404, 'B nach Austritt: Direkt-URL → 404', String(gone?.status()));
	ctx.ok(!(await pb.content()).includes(`Inhalt ${RUN}`), 'B nach Austritt: kein Inhalt');
	await pb.goto(ctx.appUrl(`index.php?workspace=${encodeURIComponent(TW)}`), { waitUntil: 'networkidle' });
	ctx.ok(await pb.locator(`#left_col [data-note-id="${note}"]`).count() === 0, 'B nach Austritt: Hauptansicht ohne Notiz');
	await ctxB.close();
	// folgende Specs sehen den Team-Workspace nicht
	dropTeams(ctx.base, A, [teamId]);
});
