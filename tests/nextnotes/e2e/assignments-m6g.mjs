// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// M6g1 (Plan M6 Done M6g, Done-Tabelle 3/6/8): Lehrkraft L legt über das Notiz-Menü eine Aufgabe aus einer Notiz mit Anhang an, teilt aus;
// S1 gibt ab (API), S2 verpasst die Frist; L sieht Status-Chips, liest die Abgabe im Lesemodus, gibt Feedback/Punkte und zurück,
// öffnet S2 erneut, lädt das ZIP. Icon-Leiste mit „Aufgaben (Team)“; Fremder ohne Eintrag. 0 CSP/Konsole.
import { runSpec } from './lib/harness.mjs';
import { apiClient } from './lib/api.mjs';
import { createTeam, ensureUser, setAssignmentDue } from './lib/docker.mjs';

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
	ctx.ok(/Aufgaben \(Team\)|Assignments/.test(railHtml), 'Leisten-Text „Aufgaben (Team)“', railHtml.slice(0, 200));
	await page.locator('#nnAsgCreateOk').waitFor({ state: 'visible', timeout: 10000 });
	ctx.ok((await page.locator('.nn-asg-source-title').innerText().catch(() => '')).includes(`Blatt ${RUN}`), 'Notiz vorausgewählt');
	ctx.ok(await page.locator('#nnAsgCreateTeam').inputValue() === teamId, 'Team vorausgewählt');
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

	// ---------- 2. Austeilen ----------
	m = ctx.mark();
	await page.getByRole('button', { name: /^(Austeilen|Hand out)$/ }).first().click();
	const dist = page.waitForResponse((r) => r.url().includes(`/assignments/${aid}/distribute`));
	await page.locator('#nnAsgDistributeOk').click();
	ctx.ok((await dist).status() === 200, 'Austeilen → 200');
	await page.locator('.nn-asg-table tbody tr').nth(1).waitFor({ timeout: 10000 });
	ctx.ok(await page.locator('.nn-asg-table tbody tr').count() === 2, 'zwei Abgaben (S1, S2)');
	ctx.ok(await page.locator('.nn-asg-table .nn-asg-chip-assigned').count() === 2, 'Chips „Zugewiesen“');
	await ctx.screenshot(page, 'distributed');
	ctx.assertClean('Austeilen', m);

	// ---------- 3. S1 gibt ab, S2 verpasst (Frist per Test-Hook in die Vergangenheit) ----------
	const subs = (await api.must('GET', `/assignments/${aid}/submissions`)).submissions;
	const s1 = subs.find((s) => s.student_uid === B);
	const s2 = subs.find((s) => s.student_uid === C);
	await apiB.must('POST', `/submissions/${s1.id}/submit`);
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
