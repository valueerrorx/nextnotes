// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// screenshots.mjs <base-url>: App-Store-Screenshots (M7c, M7-shots) auf einer eigenen Testinstanz mit Beispieldaten (Deutsch, Team „Klasse 4a“, erfundene Namen).
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

// Wegwerf-Passwort nur für die frische Screenshot-Instanz
const PASS = 'Screenshot-Demo-2026!';
const T = { uid: 'nn-lehrer', name: 'Thomas' };
const S = [{ uid: 'nn-lena', name: 'Lena Huber' }, { uid: 'nn-jonas', name: 'Jonas Gruber' }, { uid: 'nn-mia', name: 'Mia Wagner' }];
for (const u of [T, ...S]) {
	ensureUser(base, u.uid, u.name, PASS);
	occ(base, 'user:setting', u.uid, 'core', 'lang', 'de');
	occ(base, 'user:setting', u.uid, 'core', 'locale', 'de_AT');
}
for (const a of ['firstrunwizard', 'recommendations']) { try { occ(base, 'app:disable', a); } catch { /* fehlt */ } }

// REST mit Basic-Auth (wie tests/nextnotes/e2e/lib/api.mjs, ohne Spec-Gerüst)
async function call(user, method, url, body, type) {
	const r = await fetch(`${base}/index.php/apps/nextnotes${url}`, {
		method,
		headers: { Authorization: `Basic ${Buffer.from(`${user}:${PASS}`).toString('base64')}`, 'OCS-APIRequest': 'true', Accept: 'application/json', ...(type ? { 'Content-Type': type } : {}) },
		body,
	});
	const data = await r.json().catch(() => null);
	if (r.status >= 300) throw new Error(`${method} ${url} → ${r.status} ${JSON.stringify(data).slice(0, 300)}`);
	return data;
}
const api = (user, method, path, data) => call(user, method, `/api/v1${path}`, data ? JSON.stringify(data) : undefined, data ? 'application/json' : undefined);
const folder = async (u, ws, name) => (await api(u, 'POST', '/folders', { folder_name: name, workspace: ws })).folder_id;
const note = async (u, ws, heading, content, folderId, extra = {}) => (await api(u, 'POST', '/notes', { heading, content, workspace: ws, ...(folderId ? { folder_id: folderId } : {}), ...extra })).note.id;
const math = (tex, block) => block ? `<div class="math-block" data-math="${tex}" contenteditable="false">${tex}</div>` : `<span class="math-inline" data-math="${tex}" contenteditable="false">${tex}</span>`;

const PRIV = (await api(T.uid, 'GET', '/workspaces')).workspaces.map((w) => w.name ?? w)[0];
const TW = 'Klasse 4a';
const ids = (await api(T.uid, 'GET', '/workspaces')).workspaces.some((w) => w.name === TW) ? await lookup() : await seed();
const { planId, listId, sheetId, drawId, asgId } = ids;

// Excalidraw-Tafelbild: Daten per API (jeder Lauf), Vorschau erzeugt der Editor beim Speichern im Browser
let elSeed = 1;
const el = (type, x, y, w, h, extra = {}) => ({
	id: `el${elSeed}`, type, x: x + 200, y: y + 130, width: w, height: h, angle: 0, strokeColor: '#1e1e1e', backgroundColor: 'transparent', fillStyle: 'solid',
	strokeWidth: 2, strokeStyle: 'solid', roughness: 1, opacity: 100, groupIds: [], frameId: null, roundness: null, seed: elSeed++, version: 1,
	versionNonce: elSeed, isDeleted: false, boundElements: null, updated: 1, link: null, locked: false, ...extra,
});
const label = (x, y, text) => el('text', x, y, 60, 35, { text, originalText: text, fontSize: 28, fontFamily: 1, textAlign: 'center', verticalAlign: 'top', containerId: null, lineHeight: 1.25, autoResize: true });
const rows = [[1, '1'], [2, '1/2'], [4, '1/4']];
const colors = ['#ffc9c9', '#a5d8ff', '#b2f2bb'];
const elements = [];
rows.forEach(([n, txt], r) => {
	const w = 480 / n;
	for (let i = 0; i < n; i++) elements.push(el('rectangle', 100 + i * w, 60 + r * 120, w, 80, { backgroundColor: i === 0 ? colors[r] : 'transparent', fillStyle: 'hachure', roundness: { type: 3 } }));
	elements.push(label(10, 80 + r * 120, txt));
});
elements.push(el('ellipse', 680, 80, 200, 200, { backgroundColor: '#ffec99', fillStyle: 'hachure' }));
elements.push(el('line', 780, 80, 0, 200, { points: [[0, 0], [0, 200]] }));
elements.push(el('line', 680, 180, 200, 0, { points: [[0, 0], [200, 0]] }));
elements.push(label(900, 160, '4/4'));
const diagram = JSON.stringify({ type: 'excalidraw', version: 2, source: 'nextnotes', elements, appState: { viewBackgroundColor: '#ffffff', gridSize: null }, files: {} });
const form = new FormData();
for (const [k, v] of Object.entries({ action: 'save_full', note_id: String(drawId), workspace: TW, diagram_data: diagram })) form.append(k, v);
await call(T.uid, 'POST', '/api_save_excalidraw.php', form);

// Erneuter Lauf (nur Aufnahmen): IDs der Beispieldaten über Titel
async function lookup() {
	const byTitle = async (ws, heading) => (await api(T.uid, 'GET', `/notes?workspace=${encodeURIComponent(ws)}`)).notes.find((n) => n.heading === heading).id;
	const asgs = (await api(T.uid, 'GET', `/assignments?workspace=${encodeURIComponent(TW)}`)).assignments;
	return { planId: await byTitle(PRIV, 'Mathematik: Brüche (4a)'), listId: await byTitle(PRIV, 'Vorbereitung Elternabend'), sheetId: await byTitle(TW, 'Arbeitsblatt Brüche'), drawId: await byTitle(TW, 'Tafelbild Bruchteile'),
		asgId: asgs.find((a) => a.title === 'Arbeitsblatt Brüche').id };
}

async function seed() {
	// ---------- Beispieldaten: privater Bereich der Lehrkraft ----------
	const fPlan = await folder(T.uid, PRIV, 'Unterrichtsplanung');
	const fEltern = await folder(T.uid, PRIV, 'Elternarbeit');
	await folder(T.uid, PRIV, 'Ideen');
	const planId = await note(T.uid, PRIV, 'Mathematik: Brüche (4a)', `
<h2>Ziele der Einheit</h2>
<p>Die Kinder verstehen Brüche als <strong>Teile eines Ganzen</strong>, vergleichen einfache Brüche und addieren Brüche mit gleichem Nenner.</p>
<h2>Ablauf</h2>
<table><thead><tr><th>Woche</th><th>Thema</th><th>Material</th></tr></thead><tbody>
<tr><td>1</td><td>Halbe, Viertel, Achtel</td><td>Papierkreise, Tafelbild</td></tr>
<tr><td>2</td><td>Brüche vergleichen</td><td>Bruchstreifen</td></tr>
<tr><td>3</td><td>Gleichnamige Brüche addieren</td><td>Arbeitsblatt Brüche</td></tr>
<tr><td>4</td><td>Wiederholung und Lernzielkontrolle</td><td>Lernkartei</td></tr></tbody></table>
<h2>Merksatz</h2>
<p>Bei gleichem Nenner werden nur die Zähler addiert:</p>
${math('\\frac{1}{4} + \\frac{2}{4} = \\frac{3}{4}', true)}
<blockquote>Tipp: Pizza- und Schokoladen-Beispiele funktionieren immer.</blockquote>`, fPlan, { tags: ['Mathe', 'Planung'] });
	await note(T.uid, PRIV, 'Sachunterricht: Wasserkreislauf', '<h2>Stundenbild</h2><p>Verdunstung, Wolkenbildung, Niederschlag – Versuch mit Glasschüssel und Frischhaltefolie.</p>', fPlan, { tags: ['Sachunterricht'] });
	await note(T.uid, PRIV, 'Elternabend – Tagesordnung', '<h2>Tagesordnung</h2><ol><li>Begrüßung</li><li>Lesewoche im März</li><li>Wandertag im Juni</li><li>Allfälliges</li></ol>', fEltern, { tags: ['Eltern'] });
	const tasks = [
		['Einladungen austeilen', true], ['Raum reservieren', true], ['Präsentation Lesewoche vorbereiten', false],
		['Anmeldeliste Wandertag drucken', false], ['Kaffee und Kekse besorgen', false],
	].map(([text, completed], i) => ({ id: i + 1, text, completed }));
	const listId = await note(T.uid, PRIV, 'Vorbereitung Elternabend', JSON.stringify(tasks), fEltern, { type: 'tasklist', tags: ['Eltern'] });

	// ---------- Team „Klasse 4a“ mit Team-Workspace, Material und Aufgaben ----------
	const teamId = createTeam(base, T.uid, 'Klasse 4a', Object.fromEntries(S.map((s) => [s.uid, 1])));
	await api(T.uid, 'POST', '/workspaces', { name: TW, team: teamId });
	const fMat = await folder(T.uid, TW, 'Material');
	await api(T.uid, 'PUT', `/folders/${fMat}/material`, { material: true });
	const sheetId = await note(T.uid, TW, 'Arbeitsblatt Brüche', `
<h2>Brüche addieren</h2>
<p>Bringe die Brüche auf einen gemeinsamen Nenner und addiere die Zähler.</p>
${math('\\frac{1}{2} + \\frac{1}{4} = \\; ? \\qquad\\qquad \\frac{2}{3} + \\frac{1}{6} = \\; ?', true)}
<p><strong>Textaufgabe:</strong> Lisa isst ein Viertel einer Pizza, Ben die Hälfte. Wie viel ist übrig?</p>
<h2>Bruchteile</h2>
<table><thead><tr><th>Bruch</th><th>Teile</th><th>Beispiel</th></tr></thead><tbody>
<tr><td>${math('\\dfrac{1}{2}')}</td><td>2</td><td>halbe Tafel Schokolade</td></tr>
<tr><td>${math('\\dfrac{1}{4}')}</td><td>4</td><td>Viertelstunde</td></tr>
<tr><td>${math('\\dfrac{1}{8}')}</td><td>8</td><td>ein Pizzastück</td></tr></tbody></table>`, fMat, { tags: ['Mathe'] });
	const readId = await note(T.uid, TW, 'Leseliste Deutsch', '<p>Bitte bis zu den Ferien lesen:</p><ol><li>Die Vorstadtkrokodile</li><li>Das doppelte Lottchen</li><li>Ronja Räubertochter</li></ol><p>Kopiere dir die Liste mit „Duplizieren“ in deinen eigenen Bereich.</p>', fMat, { tags: ['Deutsch'] });
	const drawId = await note(T.uid, TW, 'Tafelbild Bruchteile', '<p>Ein Ganzes, zwei Halbe, vier Viertel.</p>', fMat, { tags: ['Mathe'] });
	const fStd = await folder(T.uid, TW, 'Stundenbilder');
	await note(T.uid, TW, 'Wasserkreislauf – Versuch', '<p>Glasschüssel, warmes Wasser, Frischhaltefolie, Eiswürfel.</p>', fStd, { tags: ['Sachunterricht'] });
	await note(T.uid, TW, 'Wochenplan KW 46', '<p>Mathe: Brüche, Deutsch: Leseliste, Sachunterricht: Wasser.</p>', fStd);

	const day = 86400;
	const now = Math.floor(Date.now() / 1000);
	const asgRead = (await api(T.uid, 'POST', '/assignments', { workspace: TW, title: 'Buchvorstellung vorbereiten', due_at: now + 2 * day, source_note_id: readId })).assignment;
	await api(T.uid, 'POST', `/assignments/${asgRead.id}/distribute`, {});
	const asg = (await api(T.uid, 'POST', '/assignments', { workspace: TW, title: 'Arbeitsblatt Brüche', due_at: now + 6 * day, source_note_id: sheetId, max_points: 10 })).assignment;
	await api(T.uid, 'POST', `/assignments/${asg.id}/distribute`, {});
	const subs = (await api(T.uid, 'GET', `/assignments/${asg.id}/submissions`)).submissions;
	const sub = (uid) => subs.find((s) => s.student_uid === uid);
	const answers = ['Gemeinsamer Nenner 4: 2/4 + 1/4 = 3/4.', 'Gemeinsamer Nenner 6: 4/6 + 1/6 = 5/6.', 'Ein Viertel der Pizza ist übrig.'];
	for (const s of S.slice(0, 2)) {
		const x = sub(s.uid);
		await api(s.uid, 'PATCH', `/notes/${x.note_id}`, { content: `<h2>Brüche addieren</h2>${answers.map((a, i) => `<p><strong>${i + 1}.</strong> ${a}</p>`).join('')}` });
		await api(s.uid, 'POST', `/submissions/${x.id}/submit`, {});
	}
	await api(T.uid, 'POST', `/submissions/${sub(S[0].uid).id}/return`, { feedback: 'Sehr gut gerechnet! Schreib beim nächsten Mal den Rechenweg dazu.', points: 9 });
	return { planId, listId, sheetId, drawId, asgId: asg.id };
}

// ---------- Aufnahmen ----------
mkdirSync(OUT, { recursive: true });
const q = (ws) => `workspace=${encodeURIComponent(ws)}`;
const shots = [
	{ file: 'excalidraw-light', theme: 'light', path: `excalidraw_editor.php?${q(TW)}&note_id=${drawId}`, prepare: async (page) => {
		// Editor zeichnet die Daten, Speichern erzeugt die Vorschau für die Notiz
		await page.waitForSelector('.excalidraw canvas', { timeout: 30000 });
		await page.waitForTimeout(2000);
	}, after: async (page) => {
		// Speichern erst nach einer Änderung aktiv: alles verschieben und zurück
		await page.mouse.click(1300, 700);
		await page.keyboard.press('ControlOrMeta+a');
		await page.keyboard.press('ArrowRight');
		await page.keyboard.press('ArrowLeft');
		const saved = page.waitForResponse((r) => r.url().includes('api_save_excalidraw.php'), { timeout: 30000 });
		await page.click('#saveBtn');
		if ((await saved).status() !== 200) throw new Error('Excalidraw-Vorschau nicht gespeichert');
	} },
	{ file: 'notes-light', theme: 'light', path: `index.php?${q(PRIV)}&note=${planId}` },
	{ file: 'notes-dark', theme: 'dark', path: `index.php?${q(PRIV)}&note=${listId}` },
	{ file: 'team-workspace-light', theme: 'light', path: `index.php?${q(TW)}&note=${drawId}` },
	{ file: 'team-workspace-dark', theme: 'dark', path: `index.php?${q(TW)}&note=${sheetId}` },
	{ file: 'dashboard-light', theme: 'light', path: `dashboard.php?${q(TW)}` },
	{ file: 'assignments-light', theme: 'light', path: `assignments.php?${q(TW)}` },
	{ file: 'assignment-detail-light', theme: 'light', path: `assignments.php?${q(TW)}&assignment=${asgId}` },
	{ file: 'assignment-detail-dark', theme: 'dark', path: `assignments.php?${q(TW)}&assignment=${asgId}` },
];
const only = process.env.SHOT_ONLY ? process.env.SHOT_ONLY.split(',') : null;
const browser = await pw.chromium.launch({ executablePath: process.env.CHROMIUM ?? '/usr/bin/chromium', headless: true });
let fail = 0;
try {
	for (const s of shots) {
		if (only && !only.includes(s.file)) continue;
		occ(base, 'user:setting', T.uid, 'theming', 'enabled-themes', `["${s.theme}"]`);
		const context = await browser.newContext({ viewport: { width: 1400, height: 900 }, locale: 'de-AT', colorScheme: s.theme });
		// breitere Notizliste, damit Ordner- und Notiztitel nicht abgeschnitten werden
		await context.addInitScript(() => localStorage.setItem('leftColWidth', '360'));
		const page = await context.newPage();
		await page.goto(`${base}/index.php/login`);
		await page.fill('input[name="user"]', T.uid);
		await page.fill('input[name="password"]', PASS);
		await Promise.all([page.waitForURL((u) => !u.pathname.includes('/login'), { timeout: 30000 }), page.click('button[type="submit"]')]);
		await page.goto(`${base}/index.php/apps/nextnotes/${s.path}`, { waitUntil: 'networkidle' });
		if (s.prepare) await s.prepare(page);
		// Ordner im Baum aufklappen, Anti-Flicker-Freigabe, Formeln und Bilder abwarten
		await page.evaluate(() => document.querySelectorAll('#left_col .folder-header .folder-toggle[aria-expanded="false"]').forEach((t) => t.click())).catch(() => {});
		await page.mouse.move(0, 899);
		await page.waitForTimeout(2500);
		const file = join(OUT, `${s.file}.png`);
		await page.screenshot({ path: file, fullPage: false });
		if (s.after) await s.after(page);
		const size = statSync(file).size;
		console.log(`${s.file}.png: ${(size / 1024).toFixed(0)} KiB`);
		if (size > 2 * 1024 * 1024) { console.error(`FEHLER: ${s.file}.png größer als 2 MiB`); fail = 1; }
		await context.close();
	}
} finally {
	await browser.close();
}
process.exit(fail);
