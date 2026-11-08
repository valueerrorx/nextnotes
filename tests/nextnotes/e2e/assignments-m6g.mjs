// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// M6g1 (Plan M6 Done M6g, Done-Tabelle 3/6/8): Lehrkraft L legt über das Notiz-Menü eine Aufgabe aus einer Notiz mit Anhang an, teilt aus;
// S1 gibt ab (API), S2 verpasst die Frist; L sieht Status-Chips, liest die Abgabe im Lesemodus, gibt Feedback/Punkte und zurück,
// öffnet S2 erneut, lädt das ZIP. Icon-Leiste mit „Aufgaben“; Fremder ohne Eintrag. 0 CSP/Konsole.
// M6g2: Vorlagen-Banner (Entwurf); S1 in der Oberfläche: „Meine Aufgaben“, Kopie öffnen, Banner, schreiben, abgeben → nur lesen,
// zurückziehen und erneut abgeben, Feedback „Zurückgegeben von“ nach Rückgabe; S2 sieht S1 nie; verwaistes Team mit Löschdatum, Lösch-Dialog.
// M6i: Seite folgt dem Team-Workspace (Chip statt Team-Filter, Team fest im Dialog), privat nur Hinweis ohne Leisten-Eintrag, Banner-Link mit Workspace,
// verwaistes Team: S1 sieht die Aufgabe im eigenen Workspace unter „ohne Team-Workspace“.
// M6i-fix: Wechsel Team → privat → Team ohne Reload (Leisten-Eintrag, Link, Austeilen-Menü), „Neue Aufgabe“ per Klick bis in die Liste, kein Austeilen-Knopf nach vollständigem Austeilen.
// M7h: S2 tritt aus → sieht Aufgabe und Abgabe nicht mehr (404), L sieht sie markiert weiter.
import { runSpec } from './lib/harness.mjs';
import { apiClient } from './lib/api.mjs';
import { createTeam, ensureUser, orphanTeam, removeTeamMember, setAssignmentDue } from './lib/docker.mjs';

const RUN = `${process.argv[3]}${Date.now().toString(36)}`;
const A = process.env.NN_USER;
const B = process.env.NN_USER2;
const C = `nns2${process.argv[3]}`;

await runSpec(import.meta.url, async (ctx, page, context, browser) => {
	ensureUser(ctx.base, C, 'Sam Zwei', process.env.NN_PASS2);
	const api = apiClient(ctx, page.request);
	// eigene Kontexte ohne L-Sitzungscookie (sonst gilt die Sitzung statt Basic-Auth)
	const ctxB = await browser.newContext();
	const ctxC = await browser.newContext();
	const apiB = apiClient(ctx, ctxB.request, B, process.env.NN_PASS2);
	const apiC = apiClient(ctx, ctxC.request, C, process.env.NN_PASS2);
	const auth = `Basic ${Buffer.from(`${A}:${process.env.NN_PASS}`).toString('base64')}`;

	// ---------- Daten: Team (L Owner, S1/S2 Mitglieder), Team-Workspace, Notiz mit Anhang ----------
	const teamId = createTeam(ctx.base, A, `Klasse ${RUN}`, { [B]: 1, [C]: 1 });
	const TW = `Kurs ${RUN}`;
	await api.must('POST', '/workspaces', { name: TW, team: teamId });
	const src = await api.note(TW, `Blatt ${RUN}`, null, `<p>Arbeitsblatt ${RUN}</p>`);
	const up = await page.request.post(`${ctx.base}/index.php/apps/nextnotes/api/v1/notes/${src}/attachments`, {
		headers: { Authorization: auth, 'OCS-APIRequest': 'true' }, multipart: { file: { name: 'blatt.txt', mimeType: 'text/plain', buffer: Buffer.from(`anhang-${RUN}`) } }, failOnStatusCode: false });
	ctx.ok(up.status() === 201, 'Anhang hochgeladen', String(up.status()));

	// ---------- 1. Notiz-Menü „Als Aufgabe austeilen…“ → Erstellen-Dialog mit Vorauswahl ----------
	await page.goto(ctx.appUrl(`index.php?workspace=${encodeURIComponent(TW)}&note=${src}`), { waitUntil: 'networkidle' });
	await ctx.selfTest(page);
	let m = ctx.mark();
	await page.locator(`#left_col a[data-note-id="${src}"]`).first().hover();
	await page.locator(`#left_col .note-actions-toggle[data-note-id="${src}"]`).first().click();
	await page.waitForSelector('#note-actions-menu.show', { timeout: 5000 });
	const assignItem = page.locator('#note-actions-menu .nn-assign-item');
	ctx.ok(await assignItem.isVisible().catch(() => false), 'L: Menüeintrag „Als Aufgabe austeilen…“');
	ctx.ok(/Als Aufgabe austeilen|Hand out as assignment/.test(await assignItem.innerText().catch(() => '')), 'L: Text', await assignItem.innerText().catch(() => ''));
	await Promise.all([page.waitForURL(/assignments\.php/, { timeout: 15000 }), assignItem.click()]);
	await page.waitForLoadState('networkidle');
	ctx.ok(await page.locator('#iconSidebarAssignmentsBtn[aria-current="page"]').count() === 1, 'Icon-Leiste: Aufgaben aktiv');
	const railHtml = await page.locator('#iconSidebarAssignmentsBtn').evaluate((e) => e.outerHTML);
	ctx.ok(/Aufgaben|Assignments/.test(railHtml) && !/\(Team\)/.test(railHtml), 'Leisten-Text „Aufgaben“', railHtml.slice(0, 200));
	await page.locator('#nnAsgCreateOk').waitFor({ state: 'visible', timeout: 10000 });
	ctx.ok((await page.locator('.nn-asg-source-title').innerText().catch(() => '')).includes(`Blatt ${RUN}`), 'Notiz vorausgewählt');
	// M6i: Team fest = Team des Workspace, Kopf mit Workspace-Chip statt Team-Filter
	ctx.ok(await page.locator('#nnAsgCreateTeam').count() === 0 && await page.locator('#nnAsgTeam').count() === 0, 'kein Team-Feld, kein Team-Filter');
	ctx.ok((await page.locator('#poznotePageTitleWorkspaceBtn').innerText().catch(() => '')).includes(TW), 'Workspace-Chip zeigt den Team-Workspace');
	await page.locator('.nn-asg-storage').filter({ hasText: /×|students|Schüler/ }).waitFor({ timeout: 10000 }).catch(() => {});
	ctx.ok(/\d/.test(await page.locator('.nn-asg-storage').innerText()), 'Speicher-Anzeige', await page.locator('.nn-asg-storage').innerText());
	await page.locator('#nnAsgCreateTitle').fill(`Aufgabe ${RUN}`);
	await page.locator('#nnAsgCreatePoints').fill('10');
	await ctx.screenshot(page, 'create');
	const created = page.waitForResponse((r) => /\/api\/v1\/assignments$/.test(new URL(r.url()).pathname) && r.request().method() === 'POST');
	await page.locator('#nnAsgCreateOk').click();
	const cr = await created;
	ctx.ok(cr.status() === 201, 'POST /assignments → 201', String(cr.status()));
	const aid = (await cr.json()).assignment.id;
	await page.locator('.nn-asg-detail-title').waitFor({ state: 'visible', timeout: 10000 });
	ctx.ok(new URL(page.url()).searchParams.get('assignment') === String(aid), 'URL ?assignment=');
	ctx.assertClean('Anlegen', m);

	// ---------- 1b. Vorlage im Editor: Banner „Vorlage – Entwurf“, bearbeitbar ----------
	m = ctx.mark();
	const tplHref = await page.locator('.nn-asg-actions a[href*="index.php?note="]').first().getAttribute('href');
	await page.goto(new URL(tplHref, page.url()).href, { waitUntil: 'networkidle' });
	const tplBanner = page.locator('.nn-asg-banner[data-nn-asg-kind="template"]');
	ctx.ok(await tplBanner.isVisible().catch(() => false), 'Vorlage: Banner sichtbar');
	ctx.ok(/Vorlage – Entwurf/.test(await tplBanner.innerText().catch(() => '')), 'Vorlage: „Vorlage – Entwurf“', await tplBanner.innerText().catch(() => ''));
	ctx.ok(await page.locator('.noteentry[data-nextnotes-readonly]').count() === 0, 'Vorlage: bearbeitbar (Entwurf)');
	await ctx.screenshot(page, 'template-banner');
	await tplBanner.locator('.nn-asg-banner-link').click();
	await page.locator('.nn-asg-detail-title').waitFor({ state: 'visible', timeout: 10000 });
	ctx.assertClean('Vorlage', m);

	// ---------- 2. Austeilen ----------
	m = ctx.mark();
	await page.getByRole('button', { name: /^(Austeilen|Hand out)$/ }).first().click();
	const dist = page.waitForResponse((r) => r.url().includes(`/assignments/${aid}/distribute`));
	await page.locator('#nnAsgDistributeOk').click();
	ctx.ok((await dist).status() === 200, 'Austeilen → 200');
	await page.locator('.nn-asg-table tbody tr').nth(1).waitFor({ timeout: 10000 });
	ctx.ok(await page.locator('.nn-asg-table tbody tr').count() === 2, 'zwei Abgaben (S1, S2)');
	ctx.ok(await page.locator('.nn-asg-table .nn-asg-chip-assigned').count() === 2, 'Chips „Zugewiesen“');
	// M6i-fix: alle ausgeteilt → kein Austeilen-Knopf mehr (Nachzügler bekommen ihre Kopie selbst)
	ctx.ok(await page.locator('.nn-asg-actions .nn-asg-distribute').count() === 0, 'nach dem Austeilen kein Austeilen-Knopf');
	await ctx.screenshot(page, 'distributed');
	ctx.assertClean('Austeilen', m);

	const subs = (await api.must('GET', `/assignments/${aid}/submissions`)).submissions;
	const s1 = subs.find((s) => s.student_uid === B);
	const s2 = subs.find((s) => s.student_uid === C);

	// ---------- 2b. S1 in der Oberfläche: Meine Aufgaben → Kopie → Banner → schreiben → abgeben → nur lesen ----------
	const ctxS1 = await browser.newContext({ viewport: { width: 1400, height: 900 }, locale: 'de-DE' });
	await ctx.attach(ctxS1);
	const p1 = await ctxS1.newPage();
	await ctx.login(p1, B, process.env.NN_PASS2);
	m = ctx.mark();
	// M6i: privater Workspace – kein Leisten-Eintrag, nur Hinweis, Aufgabe des Teams nicht gelistet
	await p1.goto(ctx.appUrl('assignments.php'), { waitUntil: 'networkidle' });
	ctx.ok(await p1.locator('#iconSidebarAssignmentsBtn').count() === 0, 'S1 privat: kein Leisten-Eintrag');
	ctx.ok(await p1.locator('.nn-asg-no-team').isVisible().catch(() => false), 'S1 privat: Hinweis „Aufgaben gibt es in Team-Workspaces.“');
	await p1.locator('#nnAsgList[aria-busy="false"]').waitFor({ state: 'attached', timeout: 10000 });
	ctx.ok(await p1.locator(`.nn-asg-item[data-assignment-id="${aid}"]`).count() === 0, 'S1 privat: Aufgabe des Teams nicht gelistet');
	await p1.goto(ctx.appUrl(`index.php?workspace=${encodeURIComponent(TW)}`), { waitUntil: 'networkidle' });
	await Promise.all([p1.waitForURL(/assignments\.php/, { timeout: 15000 }), p1.locator('#iconSidebarAssignmentsBtn').click()]);
	await p1.waitForLoadState('networkidle');
	ctx.ok(new URL(p1.url()).searchParams.get('workspace') === TW, 'S1: Leisten-Eintrag im Team-Workspace führt zu dessen Aufgaben');
	ctx.ok(await p1.locator('#nnAsgNew').count() === 0, 'S1: kein „Neue Aufgabe“');
	const s1Row = p1.locator(`.nn-asg-item[data-assignment-id="${aid}"]`);
	await s1Row.waitFor({ timeout: 10000 });
	ctx.ok(await s1Row.locator('.nn-asg-chip-assigned').count() === 1, 'S1: Chip „Zugewiesen“');
	ctx.ok(/Fällig/.test(await s1Row.innerText()), 'S1: Frist sichtbar', await s1Row.innerText());
	await ctx.screenshot(p1, 'student-list');
	await s1Row.locator('.nn-asg-item-main').click();
	await p1.locator('.nn-asg-open-copy').waitFor({ state: 'visible', timeout: 10000 });
	ctx.ok(/Bearbeiten/.test(await p1.locator('.nn-asg-open-copy').innerText()), 'S1: „Bearbeiten“');
	ctx.ok(await p1.locator('.nn-asg-handin').count() === 1, 'S1: Abgeben auf der Aufgaben-Seite');
	ctx.ok(await p1.locator('.nn-asg-student-feedback').count() === 0, 'S1: kein Feedback vor Rückgabe');
	await ctx.screenshot(p1, 'student-detail');
	await Promise.all([p1.waitForURL(/index\.php\?note=/, { timeout: 15000 }), p1.locator('.nn-asg-open-copy').click()]);
	await p1.waitForLoadState('networkidle');
	const banner = p1.locator('.nn-asg-banner[data-nn-asg-kind="submission"]');
	ctx.ok(await banner.isVisible().catch(() => false), 'S1: Banner im Editor');
	ctx.ok((await banner.innerText()).includes(`Aufgabe ${RUN}`) && /Fällig/.test(await banner.innerText()), 'S1: Banner mit Titel und Frist', await banner.innerText());
	ctx.ok(!(await p1.locator('#iconSidebarAssignmentsBtn').isVisible()), 'S1: Schüler-Workspace ohne Aufgaben-Eintrag (M6i)');
	ctx.ok(new URL(await banner.locator('.nn-asg-banner-link').getAttribute('href'), p1.url()).searchParams.get('workspace') === TW, 'S1: Banner-Link mit Team-Workspace');
	const entry = p1.locator(`#entry${s1.note_id}`);
	await entry.click();
	await p1.keyboard.press('End');
	await p1.keyboard.type(` Lösung ${RUN}`);
	await ctx.screenshot(p1, 'student-banner');
	await banner.locator('.nn-asg-submit').click();
	await p1.locator('.alert-modal-button.primary').last().waitFor({ state: 'visible', timeout: 5000 });
	await Promise.all([p1.waitForEvent('load', { timeout: 20000 }), p1.locator('.alert-modal-button.primary').last().click()]);
	await p1.waitForLoadState('networkidle');
	ctx.ok(await p1.locator('.noteentry[data-nextnotes-readonly]').count() === 1, 'S1: nach Abgabe nur lesen');
	ctx.ok(/Abgegeben am/.test(await p1.locator('.nn-asg-banner').innerText()), 'S1: Banner „Abgegeben am … – nur lesen“', await p1.locator('.nn-asg-banner').innerText());
	ctx.ok(await p1.locator('.nn-asg-banner .nn-asg-unsubmit').count() === 1, 'S1: „Abgabe zurückziehen“ im Banner');
	const saved = await apiB.must('GET', `/notes/${s1.note_id}`);
	ctx.ok(String(saved.note?.content ?? '').includes(`Lösung ${RUN}`), 'S1: Änderung vor dem Abgeben gespeichert');
	ctx.ok((await apiB.must('GET', `/submissions/${s1.id}`)).submission.status === 'submitted', 'S1: Status abgegeben');
	await ctx.screenshot(p1, 'student-submitted');
	ctx.assertClean('S1 abgeben', m);

	// zurückziehen und erneut abgeben (Aufgaben-Seite)
	m = ctx.mark();
	await p1.goto(ctx.appUrl(`assignments.php?workspace=${encodeURIComponent(TW)}&assignment=${aid}`), { waitUntil: 'networkidle' });
	await p1.locator('.nn-asg-withdraw').click();
	await p1.locator('#nnAsgWithdrawOk').click();
	await p1.locator('.nn-asg-handin').waitFor({ state: 'visible', timeout: 10000 });
	ctx.ok((await apiB.must('GET', `/submissions/${s1.id}`)).submission.status === 'assigned', 'S1: zurückgezogen');
	await p1.locator('.nn-asg-handin').click();
	await p1.locator('#nnAsgHandInOk').click();
	await p1.locator('.nn-asg-withdraw').waitFor({ state: 'visible', timeout: 10000 });
	ctx.ok((await apiB.must('GET', `/submissions/${s1.id}`)).submission.status === 'submitted', 'S1: erneut abgegeben');
	ctx.assertClean('S1 zurückziehen', m);

	// S2 sieht S1 nie
	ctx.ok((await apiC.call('GET', `/submissions/${s1.id}`)).status === 404, 'S2: Abgabe S1 → 404');
	ctx.ok((await apiC.call('GET', `/notes/${s1.note_id}`)).status === 404, 'S2: Kopie S1 → 404');
	const listC = (await apiC.must('GET', '/assignments')).assignments;
	// Abgabe-IDs aus der Liste (ein Vergleich auf "id":N träfe auch Aufgaben-/Team-IDs mit gleicher Zahl)
	const subIds = [];
	JSON.stringify(listC, (k, v) => { if (k === 'submission' && v?.id) subIds.push(v.id); if (k === 'submissions' && Array.isArray(v)) subIds.push(...v.map((x) => x.id)); return v; });
	ctx.ok(listC.length === 1 && listC[0].submission?.id === s2.id && !subIds.includes(s1.id) && !JSON.stringify(listC).includes(`Lösung ${RUN}`), 'S2: nur eigene Abgabe', JSON.stringify(listC).slice(0, 400));

	// ---------- 3. S2 verpasst (Frist per Test-Hook in die Vergangenheit) ----------
	ctx.ok((await apiC.call('GET', `/assignments/${aid}/export`)).status === 403, 'S2: kein Lehrkraft-ZIP (403, Aufgabe sichtbar)');
	setAssignmentDue(ctx.base, aid, Math.floor(Date.now() / 1000) - 60);
	m = ctx.mark();
	await page.reload({ waitUntil: 'networkidle' });
	await page.locator('.nn-asg-table').waitFor({ timeout: 10000 });
	ctx.ok(await page.locator('.nn-asg-table .nn-asg-chip-submitted').count() === 1, 'Chip „Abgegeben“ (S1)');
	ctx.ok(await page.locator('.nn-asg-table .nn-asg-chip-missed').count() === 1, 'Chip „Verpasst“ (S2)');
	ctx.ok(await page.locator('.nn-asg-due-passed').count() >= 1, 'Frist abgelaufen hervorgehoben');
	await ctx.screenshot(page, 'submissions');
	ctx.assertClean('Abgaben', m);

	// ---------- 4. Lesemodus S1: Druckansicht, Feedback + Punkte, zurückgeben ----------
	m = ctx.mark();
	const rowOf = (name) => page.locator('.nn-asg-table tbody tr').filter({ has: page.locator('.nn-asg-sub-open', { hasText: name }) });
	await rowOf(s1.student_name).locator('.nn-asg-sub-open').click();
	const frame = page.frameLocator('.nn-asg-frame');
	ctx.ok(await frame.locator('body').filter({ hasText: `Arbeitsblatt ${RUN}` }).waitFor({ timeout: 15000 }).then(() => true, () => false), 'Lesemodus: Kopie sichtbar');
	await page.locator('.nn-asg-feedback-text').fill(`Gut gemacht ${RUN}`);
	await page.locator('.nn-asg-feedback input[type="number"]').fill('8');
	await ctx.screenshot(page, 'review');
	const ret = page.waitForResponse((r) => r.url().includes(`/submissions/${s1.id}/return`));
	await page.getByRole('button', { name: /^(Zurückgeben|Return)$/ }).click();
	ctx.ok((await ret).status() === 200, 'Rückgabe → 200');
	await page.locator('.nn-asg-review-head .nn-asg-chip-returned').waitFor({ timeout: 10000 });
	const back = await apiB.must('GET', `/submissions/${s1.id}`);
	ctx.ok(back.submission.feedback === `Gut gemacht ${RUN}` && back.submission.points === 8, 'S1 sieht Feedback und Punkte', JSON.stringify(back.submission).slice(0, 200));
	ctx.assertClean('Rückgabe', m);

	// S1: Feedback mit Name der Lehrkraft (Q5) auf der Aufgaben-Seite, Banner „Zurückgegeben“
	m = ctx.mark();
	await p1.goto(ctx.appUrl(`assignments.php?workspace=${encodeURIComponent(TW)}&assignment=${aid}`), { waitUntil: 'networkidle' });
	const fbBox = p1.locator('.nn-asg-student-feedback');
	await fbBox.waitFor({ state: 'visible', timeout: 10000 });
	ctx.ok((await fbBox.innerText()).includes(`Gut gemacht ${RUN}`), 'S1: Feedback sichtbar');
	ctx.ok(/Zurückgegeben von/.test(await fbBox.innerText()) && /8/.test(await fbBox.innerText()), 'S1: „Zurückgegeben von“ und Punkte', await fbBox.innerText());
	ctx.ok(/Ansehen/.test(await p1.locator('.nn-asg-open-copy').innerText()) && await p1.locator('.nn-asg-handin').count() === 0, 'S1: nur Ansehen');
	await ctx.screenshot(p1, 'student-feedback');
	await p1.goto(ctx.appUrl(`index.php?note=${s1.note_id}`), { waitUntil: 'networkidle' });
	ctx.ok(/Zurückgegeben/.test(await p1.locator('.nn-asg-banner').innerText()), 'S1: Banner „Zurückgegeben“');
	ctx.assertClean('S1 Feedback', m);

	// ---------- 5. S2 (verpasst) erneut öffnen ----------
	m = ctx.mark();
	await page.locator('.nn-asg-review-nav .nn-asg-back').click();
	await rowOf(s2.student_name).locator('.nn-asg-sub-open').click();
	await page.locator('.nn-asg-review-head .nn-asg-chip-missed').waitFor({ timeout: 10000 });
	await page.getByRole('button', { name: /^(Wieder öffnen|Reopen)$/ }).first().click();
	const reo = page.waitForResponse((r) => r.url().includes(`/submissions/${s2.id}/reopen`));
	const tomorrow = new Date(Date.now() + 86400000);
	const pad = (v) => String(v).padStart(2, '0');
	await page.locator('#nnAsgReopenDue').fill(`${tomorrow.getFullYear()}-${pad(tomorrow.getMonth() + 1)}-${pad(tomorrow.getDate())}T${pad(tomorrow.getHours())}:${pad(tomorrow.getMinutes())}`);
	await page.locator('#nnAsgReopenOk').click();
	ctx.ok((await reo).status() === 200, 'Erneut öffnen → 200');
	const s2now = (await apiC.must('GET', `/submissions/${s2.id}`)).submission;
	ctx.ok(s2now.status !== 'missed', 'S2 wieder offen', s2now.status);
	ctx.assertClean('Erneut öffnen', m);

	// ---------- 6. ZIP ----------
	const zipHref = await page.locator(`a[href*="assignments/${aid}/export"]`).first().getAttribute('href').catch(() => null);
	ctx.ok(!!zipHref, 'ZIP-Link vorhanden');
	const zip = await page.request.get(new URL(zipHref ?? `api/v1/assignments/${aid}/export`, page.url()).href, { failOnStatusCode: false });
	ctx.ok(zip.status() === 200 && /application\/zip/.test(zip.headers()['content-type'] ?? ''), 'ZIP → 200 application/zip', String(zip.status()));
	const bytes = (await zip.body()).toString('latin1');
	ctx.ok(bytes.startsWith('PK') && bytes.includes('status.csv') && bytes.includes('feedback.txt') && bytes.includes('attachments/'), 'ZIP: status.csv, feedback.txt, Anhänge');

	// ---------- 7b. M6i-fix: Wechsel Team → privat → Team ohne Reload; Leisten-Eintrag, Austeilen-Menü, „Neue Aufgabe“ ----------
	m = ctx.mark();
	const PW = `Privat ${RUN}`;
	await api.must('POST', '/workspaces', { name: PW });
	await page.goto(ctx.appUrl(`index.php?workspace=${encodeURIComponent(TW)}`), { waitUntil: 'networkidle' });
	const railBtn = page.locator('#iconSidebarAssignmentsBtn');
	const railWs = async () => new URL(await railBtn.getAttribute('href'), page.url()).searchParams.get('workspace');
	const switchTo = async (ws, shown) => {
		await page.click('.sidebar-title');
		await page.locator(`#workspaceMenu .workspace-menu-item[data-workspace-name="${ws}"]`).waitFor({ state: 'visible', timeout: 5000 });
		const loads = [];
		const onLoad = () => loads.push(1);
		page.on('load', onLoad);
		await page.locator(`#workspaceMenu .workspace-menu-item[data-workspace-name="${ws}"]`).click();
		await page.waitForFunction((n) => document.querySelector('#left_col .workspace-title-text')?.textContent.trim() === n, ws, { timeout: 15000 });
		await railBtn.waitFor({ state: shown ? 'visible' : 'hidden', timeout: 5000 }).catch(() => {});
		page.off('load', onLoad);
		ctx.ok(loads.length === 0, `Wechsel nach „${ws}“ ohne Neuladen`);
	};
	ctx.ok(await railBtn.isVisible() && await railWs() === TW, 'Team-Workspace: Leisten-Eintrag mit Team-Workspace', String(await railWs()));
	await switchTo(PW, false);
	ctx.ok(!(await railBtn.isVisible()), 'nach Wechsel privat: Leisten-Eintrag weg');
	ctx.ok(await page.evaluate(() => document.body.getAttribute('data-nn-teacher')) === '0', 'nach Wechsel privat: kein Austeilen (data-nn-teacher)');
	await switchTo(TW, true);
	ctx.ok(await railBtn.isVisible() && await railWs() === TW, 'zurück im Team-Workspace: Eintrag wieder da, Link mit Team-Workspace', String(await railWs()));
	ctx.ok(await page.evaluate(() => document.body.getAttribute('data-nn-teacher')) === '1', 'zurück im Team-Workspace: Austeilen (data-nn-teacher)');
	const swNote = page.locator(`#left_col .note-actions-toggle[data-note-id="${src}"]`).first();
	await page.locator(`#left_col a[data-note-id="${src}"]`).first().hover();
	await swNote.click();
	await page.waitForSelector('#note-actions-menu.show', { timeout: 5000 });
	ctx.ok(await page.locator('#note-actions-menu .nn-assign-item').isVisible().catch(() => false), 'zurück im Team-Workspace: „Als Aufgabe austeilen…“ im Notiz-Menü');
	await page.keyboard.press('Escape');
	await ctx.screenshot(page, 'switch-back-team');
	// „Neue Aufgabe“ per Klick: Dialog, Anlegen, Eintrag in der Liste
	await Promise.all([page.waitForURL(/assignments\.php/, { timeout: 15000 }), railBtn.click()]);
	await page.waitForLoadState('networkidle');
	ctx.ok(new URL(page.url()).searchParams.get('workspace') === TW, 'Leisten-Eintrag nach Wechsel führt zum Team-Workspace', page.url());
	const newBtn = page.locator('#nnAsgNew');
	ctx.ok(await newBtn.isVisible().catch(() => false), '„Neue Aufgabe“ sichtbar');
	await newBtn.click();
	ctx.ok(await page.locator('#nnAsgCreateOk').waitFor({ state: 'visible', timeout: 5000 }).then(() => true, () => false), '„Neue Aufgabe“ öffnet den Dialog');
	await page.locator('#nnAsgCreateTitle').fill(`Zweite ${RUN}`);
	await page.locator('#nnAsgCreateSearch').fill(`Blatt ${RUN}`);
	await page.locator('.nn-asg-source-result').first().click({ timeout: 10000 });
	const created2 = page.waitForResponse((r) => /\/api\/v1\/assignments$/.test(new URL(r.url()).pathname) && r.request().method() === 'POST');
	await page.locator('#nnAsgCreateOk').click();
	const cr2 = await created2;
	ctx.ok(cr2.status() === 201, '„Neue Aufgabe“: POST → 201', String(cr2.status()));
	const aid2 = (await cr2.json()).assignment?.id;
	await page.locator('.nn-asg-detail-title').waitFor({ state: 'visible', timeout: 10000 });
	await page.locator('.nn-asg-back').first().click();
	ctx.ok(await page.locator(`.nn-asg-item[data-assignment-id="${aid2}"]`).waitFor({ timeout: 10000 }).then(() => true, () => false), '„Neue Aufgabe“: erscheint in der Liste');
	ctx.assertClean('Wechsel ohne Reload / Neue Aufgabe', m);

	// ---------- 7c. M7h: S2 tritt aus → keine Aufgabe, keine Abgabe (404); L sieht die Abgabe markiert ----------
	removeTeamMember(ctx.base, A, teamId, C);
	ctx.ok(!(await apiC.must('GET', '/assignments')).assignments.some((a) => a.id === aid), 'S2 ausgetreten: Aufgabe nicht in der Liste');
	ctx.ok((await apiC.call('GET', `/assignments/${aid}`)).status === 404, 'S2 ausgetreten: Aufgabe 404');
	ctx.ok((await apiC.call('GET', `/submissions/${s2.id}`)).status === 404, 'S2 ausgetreten: eigene Abgabe 404');
	ctx.ok((await apiC.call('GET', `/notes/${s2.note_id}`)).status === 404, 'S2 ausgetreten: eigene Kopie 404');
	const leftRow = (await api.must('GET', `/assignments/${aid}/submissions`)).submissions.find((x) => x.id === s2.id);
	ctx.ok(!!leftRow && !!leftRow.left_team_at, 'L: Abgabe von S2 bleibt, markiert ausgetreten', JSON.stringify(leftRow ?? null).slice(0, 200));

	// ---------- 8. Verwaistes Team: Löschdatum, nur Export/Löschen; Lösch-Dialog mit Zahl und „Erst exportieren“ ----------
	m = ctx.mark();
	orphanTeam(ctx.base, A, teamId);
	// L ist created_by des Team-Workspace und liest ihn weiter
	await page.goto(ctx.appUrl(`assignments.php?workspace=${encodeURIComponent(TW)}`), { waitUntil: 'networkidle' });
	const orphRow = page.locator(`.nn-asg-item[data-assignment-id="${aid}"]`);
	await orphRow.waitFor({ timeout: 10000 });
	ctx.ok(/Team gelöscht/.test(await orphRow.innerText()) && /Lade sie vorher herunter/.test(await orphRow.innerText()), 'L: verwaist mit Löschdatum', await orphRow.innerText());
	await orphRow.locator('.nn-asg-item-main').click();
	await page.locator('.nn-asg-delete').waitFor({ state: 'visible', timeout: 10000 });
	ctx.ok(await page.getByRole('button', { name: /^(Austeilen|Hand out|Frist ändern|Aufgabe schließen)$/ }).count() === 0, 'L: verwaist ohne Austeilen/Frist/Schließen');
	ctx.ok(await page.locator(`a[href*="assignments/${aid}/export"]`).count() >= 1, 'L: verwaist mit Export');
	// S1 liest den Team-Workspace nicht mehr: Aufgabe unter „ohne Team-Workspace“ im eigenen Workspace (M6i)
	await p1.goto(ctx.appUrl('assignments.php'), { waitUntil: 'networkidle' });
	const s1Orph = p1.locator(`.nn-asg-item[data-assignment-id="${aid}"]`);
	await s1Orph.waitFor({ timeout: 10000 });
	ctx.ok(/Deine Abgabe wird am/.test(await s1Orph.innerText()), 'S1: Hinweis Löschdatum', await s1Orph.innerText());
	ctx.ok(await p1.locator('.nn-asg-section-title').isVisible().catch(() => false), 'S1: Abschnitt „ohne Team-Workspace“');
	await ctx.screenshot(p1, 'student-orphaned');
	await page.locator('.nn-asg-delete').click();
	const delModal = page.locator('.nn-asg-modal');
	await delModal.waitFor({ state: 'visible', timeout: 5000 });
	ctx.ok(/2 Abgaben/.test(await delModal.innerText()), 'Lösch-Dialog nennt 2 Abgaben', await delModal.innerText());
	const firstHref = await page.locator('#nnAsgExportFirst').getAttribute('href').catch(() => null);
	ctx.ok(!!firstHref && firstHref.includes(`assignments/${aid}/export`), 'Lösch-Dialog: „Erst exportieren“');
	const firstZip = await page.request.get(new URL(firstHref ?? 'x', page.url()).href, { failOnStatusCode: false });
	ctx.ok(firstZip.status() === 200, '„Erst exportieren“ lädt das ZIP', String(firstZip.status()));
	await ctx.screenshot(page, 'delete-dialog');
	const del = page.waitForResponse((r) => r.url().includes(`/assignments/${aid}`) && r.request().method() === 'DELETE');
	await page.locator('#nnAsgDeleteOk').click();
	ctx.ok((await del).status() === 200, 'Löschen → 200');
	await page.locator('.nn-asg-list:not([hidden])').waitFor({ timeout: 10000 });
	await page.locator(`.nn-asg-item[data-assignment-id="${aid}"]`).waitFor({ state: 'detached', timeout: 10000 }).catch(() => {});
	ctx.ok(await page.locator(`.nn-asg-item[data-assignment-id="${aid}"]`).count() === 0, 'Aufgabe aus der Liste');
	ctx.ok((await apiB.call('GET', `/submissions/${s1.id}`)).status === 404, 'S1: Abgabe gelöscht');
	ctx.assertClean('Verwaist/Löschen', m);
	await ctxS1.close();

	// ---------- 7. Fremder: kein Leisten-Eintrag, Aufgabe nicht sichtbar ----------
	const ctxN = await browser.newContext({ viewport: { width: 1400, height: 900 }, locale: 'de-DE' });
	await ctx.attach(ctxN);
	const pn = await ctxN.newPage();
	const N = `nnfremd${process.argv[3]}`;
	ensureUser(ctx.base, N, 'Fremd', process.env.NN_PASS2);
	await ctx.login(pn, N, process.env.NN_PASS2);
	const m7 = ctx.mark();
	ctx.allow('status', new RegExp(`404 GET .*/api/v1/assignments/${aid}`), 'Fremder: Aufgabe 404 (erwartet)');
	ctx.allow('console', /status of 404/, 'Fremder: Browser meldet die erwartete 404');
	await pn.goto(ctx.appUrl(`assignments.php?assignment=${aid}`), { waitUntil: 'networkidle' });
	ctx.ok(await pn.locator('#iconSidebarAssignmentsBtn').count() === 0, 'Fremder: kein Leisten-Eintrag');
	ctx.ok(!(await pn.content()).includes(`Aufgabe ${RUN}`), 'Fremder: Aufgabe nicht sichtbar');
	ctx.assertClean('Fremder', m7);
	await ctxN.close();
});
