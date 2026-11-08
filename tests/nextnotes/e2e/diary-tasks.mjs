// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// M4f (M4-Plan Done M4f 1, 4, 5): Tagebuch „Eintrag für heute“ mit Vorlage im Datumsordner, Journal mit Einträgen über dem Render-Budget (P26),
// Mini-Kalender (P25: eine Anfrage, erst sichtbar), Aufgaben abhaken → nach Reload abgehakt; je Seite 0 CSP/pageerror/Konsolenfehler/≥ 400, ≤ 8 API-Requests.
import { runSpec } from './lib/harness.mjs';
import { apiClient } from './lib/api.mjs';

const RUN = `${process.argv[3]}${Date.now().toString(36)}`;

await runSpec(import.meta.url, async (ctx, page) => {
	const api = apiClient(ctx, page.request);
	const WS = await api.workspace(`Tagebuch ${RUN}`);
	const wq = `workspace=${encodeURIComponent(WS)}`;
	const tpl = await api.note(WS, `Vorlage ${RUN}`, null, `<p>Vorlage ${RUN}</p>`);
	// Workspace-ID ist über die API nicht sichtbar; Vorlage greift nur im eigenen Workspace, daher alle kleinen IDs
	const map = {};
	for (let i = 1; i <= 3000; i++) map[i] = tpl;
	await api.must('PUT', '/settings/diary_templates', { value: JSON.stringify(map) });
	const tasks = JSON.stringify([{ id: 1, text: `Aufgabe ${RUN}`, completed: false }, { id: 2, text: `Zweite ${RUN}`, completed: false }]);
	const tl = (await api.must('POST', '/notes', { heading: `Liste ${RUN}`, type: 'tasklist', content: tasks, workspace: WS })).note.id;

	const apiCalls = (since) => ctx.requests.slice(since.q).filter((r) => /\/apps\/nextnotes\/api\/v1\//.test(r.url) && r.type !== 'document').length;
	const open = async (path, label) => {
		const m = ctx.mark();
		const resp = await page.goto(ctx.appUrl(`${path}${path.includes('?') ? '&' : '?'}${wq}`), { waitUntil: 'networkidle' });
		ctx.ok(resp?.status() === 200, `${label}: 200`, String(resp?.status()));
		ctx.info(`${label}: ${apiCalls(m)} API-Requests`);
		ctx.ok(apiCalls(m) <= 8, `${label}: ≤ 8 API-Requests`, String(apiCalls(m)));
		return m;
	};

	// 1. Icon-Leiste: Aufgaben und Tagebuch
	let m = await open('index.php', 'Hauptansicht');
	await ctx.selfTest(page);
	for (const id of ['iconSidebarTasksBtn', 'iconSidebarDiaryBtn']) ctx.ok(await page.locator(`#icon_sidebar #${id}`).isVisible(), `Icon-Leiste: ${id}`);
	// Mini-Kalender: verborgen → keine Anfrage; einblenden → genau eine (mode=both)
	const byDate = () => ctx.requests.filter((r) => r.url.includes('/calendar/notes-by-date.php'));
	ctx.ok(await page.locator('#mini-calendar').count() === 1, 'Mini-Kalender vorhanden');
	ctx.ok(byDate().length === 0, 'Mini-Kalender verborgen: keine Anfrage (P25)', String(byDate().length));
	const counts = page.waitForResponse((r) => r.url().includes('/calendar/notes-by-date.php'));
	await page.locator('#mini-calendar [data-action="toggle"]').click();
	const cr = await counts;
	ctx.ok(cr.status() === 200 && cr.url().includes('mode=both'), 'Mini-Kalender: eine Anfrage mode=both', cr.url());
	await page.waitForTimeout(300);
	ctx.ok(byDate().length === 1, 'Mini-Kalender: genau eine Anfrage', String(byDate().length));
	const today = page.locator('#mini-calendar .mini-calendar-day-today');
	const onDate = page.waitForResponse((r) => r.url().includes('/calendar/notes-on-date.php'));
	await today.click();
	ctx.ok((await onDate).status() === 200, 'Kalendertag: notes-on-date 200');
	await page.locator('.calendar-notes-modal').waitFor({ state: 'visible', timeout: 10000 });
	ctx.ok(await page.locator(`.calendar-notes-modal .calendar-note-item[data-note-id="${tl}"]`).count() === 1, 'Kalendertag: heutige Notiz gelistet');
	await ctx.screenshot(page, 'calendar');
	await page.keyboard.press('Escape');
	ctx.assertClean('Hauptansicht/Kalender', m);
	// Nach Reload sichtbar: Zähler mit einer Anfrage. networkidle kann unter Last vor DOMContentLoaded (Kalender-Init) feuern
	// (> 500 ms Skriptausführung ohne Netz), daher auf die Antwort selbst warten (M4i, flaky M4g)
	const reloaded = page.waitForResponse((r) => r.url().includes('/calendar/notes-by-date.php'), { timeout: 20000 }).catch(() => null);
	m = await open('index.php', 'Hauptansicht mit Kalender');
	ctx.ok((await reloaded)?.status() === 200, 'Kalender nach Reload: Zähler geladen');
	await page.waitForTimeout(300);
	ctx.ok(byDate().length === 2, 'Kalender sichtbar nach Reload: eine weitere Anfrage', String(byDate().length));
	ctx.assertClean('Hauptansicht mit Kalender', m);

	// 2. Tagebuch: Eintrag für heute → Notiz mit Vorlage im Datumsordner
	m = await open('diary.php', 'Tagebuch');
	await ctx.screenshot(page, 'diary-empty');
	if (ctx.theme === 'light') {
		await Promise.all([page.waitForURL(/[?&]note=\d+/, { timeout: 20000 }), page.locator('#diaryTodayBtn').click()]);
		const id = Number(new URL(page.url()).searchParams.get('note'));
		await page.waitForLoadState('networkidle');
		const note = (await api.must('GET', `/notes/${id}?${wq}`)).note;
		ctx.ok(String(note.content ?? '').includes(`Vorlage ${RUN}`), 'Eintrag: Vorlage übernommen', String(note.content).slice(0, 120));
		const d = new Date();
		const iso = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
		const look = await api.must('GET', `/calendar/diary-entry.php?date=${iso}&${wq}`);
		ctx.ok(look.exists && look.id === id, 'Eintrag: diary-entry findet ihn', JSON.stringify(look).slice(0, 200));
		ctx.ok(note.folder === iso.slice(5, 7), 'Eintrag: im Monatsordner', `${note.folder} / ${look.folder}`);
		ctx.ok(await page.locator(`#note${id}`).count() >= 1 || page.url().includes(`note=${id}`), 'Eintrag: Notiz geöffnet');
		ctx.assertClean('Tagebuch: Eintrag für heute', m);
	} else {
		ctx.assertClean('Tagebuch', m);
	}

	// 3. Journal: zehn Einträge à 120 KiB (> 1 MiB je Anfrage) → alle ohne Fehler (truncated nachgeladen, P26)
	const look = await api.must('GET', `/calendar/diary-entry.php?date=2026-01-01&${wq}`);
	const big = `<p>${'Langer Tagebuchtext. '.repeat(6000)}</p>`;
	for (let i = 1; i <= 10; i++) {
		const day = `2026-01-${String(i).padStart(2, '0')}`;
		const l = await api.must('GET', `/calendar/diary-entry.php?date=${day}&${wq}`);
		if (!l.exists) await api.must('POST', '/notes', { heading: l.title, folder_name: l.folder, workspace: WS, content: big, created_date: day });
	}
	ctx.info(`Journal-Ordner ${look.folder}`);
	m = await open('diary.php', 'Tagebuch mit Einträgen');
	const entries = [];
	const onEntries = (r) => { if (r.url().includes('/diary/entries.php')) entries.push(r); };
	page.on('response', onEntries);
	await page.locator('#diaryJournalToggle').click();
	await page.waitForFunction(() => document.querySelectorAll('.diary-journal-entry').length > 0 && document.querySelectorAll('.diary-journal-body.is-loading').length === 0, null, { timeout: 30000 }).catch(() => {});
	page.off('response', onEntries);
	const bodies = await page.locator('.diary-journal-entry .diary-journal-body').count();
	const loading = await page.locator('.diary-journal-body.is-loading').count();
	const failed = await page.locator('.diary-journal-body').evaluateAll((els) => els.filter((e) => /konnte nicht geladen|could not load/i.test(e.textContent)).length);
	const truncated = (await Promise.all(entries.map((r) => r.json().catch(() => ({}))))).reduce((n, j) => n + (j.truncated?.length ?? 0), 0);
	ctx.info(`Journal: ${bodies} Einträge, ${entries.length} entries-Anfragen, ${truncated} truncated`);
	ctx.ok(bodies >= 10 && loading === 0 && failed === 0, 'Journal: alle Einträge geladen, keiner fehlerhaft', `${bodies}/${loading}/${failed}`);
	ctx.ok(truncated > 0 && entries.length >= 2, 'Journal: Budget greift, Rest nachgeladen (P26)', `${truncated} truncated, ${entries.length} Anfragen`);
	ctx.ok(entries.every((r) => r.status() === 200), 'Journal: entries 200');
	await ctx.screenshot(page, 'diary-journal');
	await page.locator('#diaryJournalToggle').click();
	ctx.assertClean('Journal', m);

	// 4. Aufgaben: abhaken → nach Reload abgehakt
	m = await open('tasks.php', 'Aufgaben');
	const box = page.locator(`xpath=//span[contains(@class,"tasks-task-text") and contains(., "Aufgabe ${RUN}")]/preceding-sibling::input[contains(@class,"tasks-task-checkbox")]`);
	await box.waitFor({ state: 'visible', timeout: 15000 });
	ctx.ok(!(await box.isChecked()), 'Aufgabe: offen');
	await ctx.screenshot(page, 'tasks');
	if (ctx.theme === 'light') {
		const saved = page.waitForResponse((r) => r.request().method() === 'PATCH' && r.url().includes(`/notes/${tl}`));
		await box.check();
		ctx.ok((await saved).status() === 200, 'Aufgabe abhaken: 200');
		await page.reload({ waitUntil: 'networkidle' });
		await box.waitFor({ state: 'visible', timeout: 15000 });
		ctx.ok(await box.isChecked(), 'Aufgabe: nach Reload abgehakt');
		const stored = JSON.parse((await api.must('GET', `/notes/${tl}?${wq}`)).note.content);
		ctx.ok(stored.find((t) => t.id === 1)?.completed === true && stored.find((t) => t.id === 2)?.completed === false, 'Aufgabe: nur diese gespeichert');
	}
	ctx.assertClean('Aufgaben', m);

	// 5. Erstellen (UI-rail: Erstellen-Seite entfernt): „+“-Menü der Hauptansicht bietet den Tagebuch-Eintrag
	m = await open('index.php', 'Hauptansicht');
	await page.click('#sidebarCreateBtn');
	await page.locator('#create-menu').waitFor({ state: 'visible', timeout: 5000 });
	ctx.ok(await page.locator('#create-menu .create-note-option[data-type="diary"]').isVisible(), '„+“-Menü: Tagebuch-Eintrag');
	await page.keyboard.press('Escape');
	ctx.assertClean('Erstellen', m);
});
