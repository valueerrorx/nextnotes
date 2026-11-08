// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// M6h Smoke (Plan M6 §3 M6h): Material kennzeichnen (L), S liest nur (Q1 Duplikat in den privaten Workspace), Aufgabe aus Material (Q2 Kopie),
// Austeilen in der Oberfläche, S öffnet „Meine Aufgaben“ und gibt ab (Q3: Aufgaben-Workspace nicht in der Workspace-Liste),
// L erhält keine Meldung je Abgabe (Q4), L gibt mit Feedback zurück, S sieht „Zurückgegeben von <Name>“ (Q5). 0 CSP/Konsole je Abschnitt.
import { runSpec } from './lib/harness.mjs';
import { apiClient } from './lib/api.mjs';
import { createTeam } from './lib/docker.mjs';

const RUN = `${process.argv[3]}${Date.now().toString(36)}`;
const A = process.env.NN_USER;
const B = process.env.NN_USER2;

await runSpec(import.meta.url, async (ctx, page, context, browser) => {
	const api = apiClient(ctx, page.request);
	const ctxB = await browser.newContext({ viewport: { width: 1400, height: 900 }, locale: 'de-DE' });
	await ctx.attach(ctxB);
	const apiB = apiClient(ctx, ctxB.request, B, process.env.NN_PASS2);
	const ocs = async (c, user, pass, path) => {
		const r = await c.request.get(`${ctx.base}/ocs/v2.php/${path}${path.includes('?') ? '&' : '?'}format=json`, {
			headers: { Authorization: `Basic ${Buffer.from(`${user}:${pass}`).toString('base64')}`, 'OCS-APIRequest': 'true' }, failOnStatusCode: false });
		return r.ok() ? (await r.json()).ocs.data : null;
	};
	const appNotes = async (c, user, pass) => ((await ocs(c, user, pass, 'apps/notifications/api/v2/notifications')) ?? []).filter((n) => n.app === 'nextnotes');

	// Daten: Team (L Owner, S Mitglied), Team-Workspace, Ordner M mit Notiz
	const teamId = createTeam(ctx.base, A, `Smoke ${RUN}`, { [B]: 1 });
	const TW = `Smoke ${RUN}`;
	await api.must('POST', '/workspaces', { name: TW, team: teamId });
	const M = (await api.must('POST', '/folders', { folder_name: `Material ${RUN}`, workspace: TW })).folder_id;
	const src = await api.note(TW, `Blatt ${RUN}`, M, `<p>Arbeitsblatt ${RUN}</p>`);

	// 1. L kennzeichnet M als Material (Ordnermenü)
	let m = ctx.mark();
	await page.goto(ctx.appUrl(`index.php?workspace=${encodeURIComponent(TW)}`), { waitUntil: 'networkidle' });
	await ctx.selfTest(page);
	const header = page.locator(`#left_col .folder-header[data-folder-id="${M}"]`).first();
	await header.locator('.folder-toggle').first().hover();
	await header.locator('.folder-actions-toggle').first().click();
	await page.waitForSelector('#folder-actions-menu.show', { timeout: 5000 });
	const item = page.locator('#folder-actions-menu .nn-material-item');
	await item.waitFor({ state: 'visible', timeout: 5000 });
	const put = page.waitForResponse((r) => r.url().includes(`/folders/${M}/material`) && r.request().method() === 'PUT');
	await item.click();
	ctx.ok((await put).status() === 200, 'L: Ordner als Material gekennzeichnet');
	await header.locator('.nn-material-mark').first().waitFor({ state: 'visible', timeout: 8000 }).catch(() => {});
	ctx.ok(await header.locator('.nn-material-mark').count() >= 1, 'L: Material-Symbol im Baum');
	await ctx.screenshot(page, 'material');
	ctx.assertClean('Material kennzeichnen', m);

	// 2. S liest Material nur; Q1 Duplikat landet im privaten Workspace
	const pb = await ctxB.newPage();
	await ctx.login(pb, B, process.env.NN_PASS2);
	m = ctx.mark();
	await pb.goto(ctx.appUrl(`index.php?workspace=${encodeURIComponent(TW)}&note=${src}`), { waitUntil: 'networkidle' });
	ctx.ok(await pb.locator(`#entry${src}`).getAttribute('data-nextnotes-readonly') === 'true', 'S: Material nur lesen');
	ctx.ok((await apiB.call('PATCH', `/notes/${src}`, { content: '<p>x</p>' })).status === 403, 'S: Schreiben ins Material 403');
	const dup = await apiB.must('POST', `/notes/${src}/duplicate`, {});
	ctx.ok(dup.workspace && dup.workspace !== TW, 'Q1: Duplikat im privaten Workspace', JSON.stringify(dup).slice(0, 200));
	ctx.assertClean('S Material', m);

	// 3. Q2: Aufgabe aus Material (L), Austeilen in der Oberfläche
	m = ctx.mark();
	const a = (await api.must('POST', '/assignments', { team: teamId, title: `Aufgabe ${RUN}`, due_at: Math.floor(Date.now() / 1000) + 7200, source_note_id: src, max_points: 10 })).assignment;
	await page.goto(ctx.appUrl(`assignments.php?assignment=${a.id}`), { waitUntil: 'networkidle' });
	await page.getByRole('button', { name: /^(Austeilen|Hand out)$/ }).first().click();
	const dist = page.waitForResponse((r) => r.url().includes(`/assignments/${a.id}/distribute`));
	await page.locator('#nnAsgDistributeOk').click();
	ctx.ok((await dist).status() === 200, 'L: Austeilen → 200');
	await page.locator('.nn-asg-table tbody tr').first().waitFor({ timeout: 10000 });
	const sub = (await api.must('GET', `/assignments/${a.id}/submissions`)).submissions.find((s) => s.student_uid === B);
	ctx.ok(!!sub, 'Abgabe für S angelegt');
	const copy = (await apiB.must('GET', `/notes/${sub.note_id}`)).note;
	ctx.ok(String(copy.content).includes(`Arbeitsblatt ${RUN}`) && sub.note_id !== src, 'Q2: S erhält eine Kopie des Materials');
	ctx.assertClean('Austeilen', m);

	// 4. S: Meine Aufgaben → Kopie → schreiben → abgeben; Q3 Aufgaben-Workspace nicht in der Liste
	m = ctx.mark();
	await pb.goto(ctx.appUrl('assignments.php'), { waitUntil: 'networkidle' });
	const row = pb.locator(`.nn-asg-item[data-assignment-id="${a.id}"]`);
	await row.waitFor({ timeout: 10000 });
	await row.locator('.nn-asg-item-main').click();
	await Promise.all([pb.waitForURL(/index\.php\?note=/, { timeout: 15000 }), pb.locator('.nn-asg-open-copy').click()]);
	await pb.waitForLoadState('networkidle');
	ctx.ok(await pb.locator('.nn-asg-banner[data-nn-asg-kind="submission"]').isVisible().catch(() => false), 'S: Banner in der Kopie');
	ctx.ok(!(await apiB.workspaces()).some((w) => /^Aufgaben/.test(w)), 'Q3: Aufgaben-Workspace nicht in GET /workspaces', (await apiB.workspaces()).join(', '));
	const lBefore = (await appNotes(page, A, process.env.NN_PASS)).length;
	ctx.ok((await apiB.call('POST', `/submissions/${sub.id}/submit`)).status === 200, 'S: abgegeben');
	await pb.reload({ waitUntil: 'networkidle' });
	ctx.ok(await pb.locator(`#entry${sub.note_id}`).getAttribute('data-nextnotes-readonly') === 'true', 'S: nach Abgabe nur lesen');
	await ctx.screenshot(pb, 'submitted');
	ctx.assertClean('S abgeben', m);
	ctx.ok((await appNotes(page, A, process.env.NN_PASS)).length === lBefore, 'Q4: keine Meldung je Abgabe an die Lehrkraft');

	// 5. L gibt mit Feedback zurück; Q5 S sieht „Zurückgegeben von <Name>“ und eine Meldung
	m = ctx.mark();
	await api.must('POST', `/submissions/${sub.id}/return`, { feedback: `Gut ${RUN}`, points: 9 });
	const lName = (await ocs(page, A, process.env.NN_PASS, 'cloud/user'))?.displayname ?? A;
	await pb.goto(ctx.appUrl(`assignments.php?assignment=${a.id}`), { waitUntil: 'networkidle' });
	const fb = pb.locator('.nn-asg-student-feedback');
	await fb.waitFor({ state: 'visible', timeout: 10000 });
	const text = await fb.innerText();
	ctx.ok(/Zurückgegeben von/.test(text) && text.includes(lName) && text.includes(`Gut ${RUN}`), 'Q5: „Zurückgegeben von <Name>“ mit Feedback', text);
	ctx.ok((await appNotes(pb, B, process.env.NN_PASS2)).some((n) => n.object_type !== undefined && JSON.stringify(n).includes(lName)), 'Q5: Meldung an S nennt die Lehrkraft');
	await ctx.screenshot(pb, 'feedback');
	ctx.assertClean('Rückgabe', m);

	await api.call('DELETE', `/assignments/${a.id}`, { confirm: true });
	await ctxB.close();
});
