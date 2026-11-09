// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// screenshots.mjs <base-url>: App-Store-Screenshots (M7c) auf einer eigenen Testinstanz mit Beispieldaten (erfundene Namen, Englisch).
// Nie gegen Demo/gftest (Guard in tests/nextnotes/e2e/lib/docker.mjs). Ausgabe SHOT_OUT (Standard nextnotes/screenshots/), je Datei ≤ 2 MiB.
// Erwartet eine frische Instanz mit aktivierter App und Teams (circles); PW_CORE, CHROMIUM wie run-e2e.sh.
import { mkdirSync, statSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { createTeam, ensureUser, occ } from '../../../tests/nextnotes/e2e/lib/docker.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
const REPO = join(HERE, '..', '..', '..');
const base = (process.argv[2] || '').replace(/\/$/, '');
if (!/^http:\/\/(localhost|127\.0\.0\.1):\d+$/.test(base)) { console.error('Aufruf: screenshots.mjs http://localhost:<port>'); process.exit(2); }
const OUT = process.env.SHOT_OUT || join(REPO, 'nextnotes', 'screenshots');
const pw = await import(pathToFileURL(join(process.env.PW_CORE ?? join(REPO, 'dev/nextcloud/node_modules/playwright-core'), 'index.mjs')).href);

const PASS = 'Screenshot-Demo-2026!';
const T = { uid: 'nn-teacher', name: 'Alex Teacher' };
const S = [{ uid: 'nn-student1', name: 'Sam Student' }, { uid: 'nn-student2', name: 'Robin Student' }, { uid: 'nn-student3', name: 'Kim Student' }];
for (const u of [T, ...S]) {
	ensureUser(base, u.uid, u.name, PASS);
	occ(base, 'user:setting', u.uid, 'core', 'lang', 'en');
	occ(base, 'user:setting', u.uid, 'core', 'locale', 'en_US');
}
for (const a of ['firstrunwizard', 'recommendations']) { try { occ(base, 'app:disable', a); } catch { /* fehlt */ } }

// REST mit Basic-Auth (wie tests/nextnotes/e2e/lib/api.mjs, ohne Spec-Gerüst)
async function api(user, method, path, data) {
	const r = await fetch(`${base}/index.php/apps/nextnotes/api/v1${path}`, {
		method,
		headers: { Authorization: `Basic ${Buffer.from(`${user}:${PASS}`).toString('base64')}`, 'OCS-APIRequest': 'true', Accept: 'application/json', ...(data ? { 'Content-Type': 'application/json' } : {}) },
		body: data ? JSON.stringify(data) : undefined,
	});
	const body = await r.json().catch(() => null);
	if (r.status >= 300) throw new Error(`${method} ${path} → ${r.status} ${JSON.stringify(body).slice(0, 300)}`);
	return body;
}
const folder = async (u, ws, name) => (await api(u, 'POST', '/folders', { folder_name: name, workspace: ws })).folder_id;
const note = async (u, ws, heading, content, folderId) => (await api(u, 'POST', '/notes', { heading, content, workspace: ws, ...(folderId ? { folder_id: folderId } : {}) })).note.id;

// ---------- Beispieldaten ----------
const PRIV = (await api(T.uid, 'GET', '/workspaces')).workspaces.map((w) => w.name ?? w).find((n) => n === 'Notes') ?? 'Notes';
const fProj = await folder(T.uid, PRIV, 'Projects');
const fClass = await folder(T.uid, PRIV, 'Lesson planning');
await folder(T.uid, PRIV, 'Reading list');
const planId = await note(T.uid, PRIV, 'School garden project', `
<h2>Goals</h2>
<p>Plan a small school garden with <strong>class 9b</strong> and document growth over the term.</p>
<p>We choose plants that grow fast in spring, build two raised beds and measure plant height every week.</p>
<h2>Timeline</h2>
<table><thead><tr><th>Week</th><th>Task</th><th>Who</th></tr></thead>
<tbody><tr><td>1</td><td>Soil test and bed layout</td><td>Group A</td></tr><tr><td>2</td><td>Sowing radishes and beans</td><td>Group B</td></tr><tr><td>3–8</td><td>Weekly measurements</td><td>All</td></tr></tbody></table>
<h2>Materials</h2>
<p>Seeds, compost, two pallets, a folding ruler and a watering can per group.</p>
<blockquote>Tip: photos of each bed go into the attachments of this note.</blockquote>`, fProj);
await note(T.uid, PRIV, 'Parent evening – agenda', '<h2>Agenda</h2><p>Welcome, class trip in June, questions.</p>', fClass);
await note(T.uid, PRIV, 'Ideas for next term', '<p>Short experiments, a podcast project, a visit to the botanical garden.</p>', fProj);

const teamId = createTeam(base, T.uid, 'Biology 9b', Object.fromEntries(S.map((s) => [s.uid, 1])));
const TW = 'Biology 9b';
await api(T.uid, 'POST', '/workspaces', { name: TW, team: teamId });
const fMat = await folder(T.uid, TW, 'Material');
await api(T.uid, 'PUT', `/folders/${fMat}/material`, { material: true });
const cellId = await note(T.uid, TW, 'Cell structure', `
<h2>Plant cell</h2>
<p>Every plant cell has a <strong>cell wall</strong>, a <strong>cell membrane</strong>, <strong>cytoplasm</strong>, a <strong>nucleus</strong> and <strong>chloroplasts</strong>.</p>
<table><thead><tr><th>Part</th><th>Function</th></tr></thead><tbody>
<tr><td>Cell wall</td><td>Shape and protection</td></tr><tr><td>Chloroplast</td><td>Photosynthesis</td></tr><tr><td>Vacuole</td><td>Storage of water and nutrients</td></tr></tbody></table>
<p>Compare with an animal cell: which parts are missing?</p>`, fMat);
const sheetId = await note(T.uid, TW, 'Worksheet: photosynthesis', `
<h2>Photosynthesis</h2>
<p>6 CO<sub>2</sub> + 6 H<sub>2</sub>O → C<sub>6</sub>H<sub>12</sub>O<sub>6</sub> + 6 O<sub>2</sub></p>
<p><strong>1.</strong> Name the two products of photosynthesis.</p><p><strong>2.</strong> Explain why plants need light.</p><p><strong>3.</strong> Describe the experiment with the water plant.</p>`, fMat);
const fLess = await folder(T.uid, TW, 'Lessons');
await note(T.uid, TW, 'Lesson 1 – Microscope basics', '<p>Handling the microscope, first slides.</p>', fLess);

const due = Math.floor(Date.now() / 1000) + 3 * 86400;
const asg = (await api(T.uid, 'POST', '/assignments', { workspace: TW, title: 'Photosynthesis worksheet', due_at: due, source_note_id: sheetId, max_points: 10 })).assignment;
await api(T.uid, 'POST', `/assignments/${asg.id}/distribute`, {});
const subs = (await api(T.uid, 'GET', `/assignments/${asg.id}/submissions`)).submissions;
const sub = (uid) => subs.find((s) => s.student_uid === uid);
for (const [i, s] of S.slice(0, 2).entries()) {
	const x = sub(s.uid);
	await api(s.uid, 'PATCH', `/notes/${x.note_id}`, { content: `<h2>Photosynthesis</h2><p><strong>1.</strong> Glucose and oxygen.</p><p><strong>2.</strong> Light provides the energy for the reaction.</p><p><strong>3.</strong> ${i === 0 ? 'Bubbles of oxygen rise when the lamp is close.' : 'More light, more bubbles.'}</p>` });
	await api(s.uid, 'POST', `/submissions/${x.id}/submit`, {});
}
await api(T.uid, 'POST', `/submissions/${sub(S[0].uid).id}/return`, { feedback: 'Well explained, please add units next time.', points: 9 });

// ---------- Aufnahmen ----------
mkdirSync(OUT, { recursive: true });
const shots = [
	{ file: 'notes-light', theme: 'light', path: `index.php?workspace=${encodeURIComponent(PRIV)}&note=${planId}` },
	{ file: 'notes-dark', theme: 'dark', path: `index.php?workspace=${encodeURIComponent(PRIV)}&note=${planId}` },
	{ file: 'team-workspace-light', theme: 'light', path: `index.php?workspace=${encodeURIComponent(TW)}&note=${cellId}` },
	{ file: 'assignments-light', theme: 'light', path: `assignments.php?workspace=${encodeURIComponent(TW)}&assignment=${asg.id}` },
];
const browser = await pw.chromium.launch({ executablePath: process.env.CHROMIUM ?? '/usr/bin/chromium', headless: true });
let fail = 0;
try {
	for (const s of shots) {
		occ(base, 'user:setting', T.uid, 'theming', 'enabled-themes', `["${s.theme}"]`);
		const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'en-US', colorScheme: s.theme });
		const page = await context.newPage();
		await page.goto(`${base}/index.php/login`);
		await page.fill('input[name="user"]', T.uid);
		await page.fill('input[name="password"]', PASS);
		await Promise.all([page.waitForURL((u) => !u.pathname.includes('/login'), { timeout: 30000 }), page.click('button[type="submit"]')]);
		await page.goto(`${base}/index.php/apps/nextnotes/${s.path}`, { waitUntil: 'networkidle' });
		// Ordner im Baum aufklappen, Anti-Flicker-Freigabe und Formeln abwarten
		await page.evaluate(() => document.querySelectorAll('#left_col .folder-header .folder-toggle[aria-expanded="false"]').forEach((t) => t.click())).catch(() => {});
		await page.waitForTimeout(2500);
		const file = join(OUT, `${s.file}.png`);
		await page.screenshot({ path: file, fullPage: false });
		const size = statSync(file).size;
		console.log(`${s.file}.png: ${(size / 1024).toFixed(0)} KiB`);
		if (size > 2 * 1024 * 1024) { console.error(`FEHLER: ${s.file}.png größer als 2 MiB`); fail = 1; }
		await context.close();
	}
} finally {
	await browser.close();
}
process.exit(fail);
