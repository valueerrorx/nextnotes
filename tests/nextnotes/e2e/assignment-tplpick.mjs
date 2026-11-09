// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// UI-tplpick (Thomas 2026-11-09): Im Dialog „Neue Aufgabe“ zeigt die Lupe rechts im Suchfeld alle Notizen des gewählten Workspace
// ohne Tippen (zuletzt geändert zuerst, Ordner je Eintrag); nie Notizen aus anderem Workspace desselben Teams, privat oder Papierkorb.
// Tastatur (Pfeil-runter, Enter, Escape), Tippen filtert im selben Workspace, Anlegen sendet workspace; Server lehnt fremde Quelle ab. Mobil. 0 CSP/Konsole.
import { runSpec } from './lib/harness.mjs';
import { apiClient } from './lib/api.mjs';
import { createTeam } from './lib/docker.mjs';

const RUN = `${process.argv[3]}${Date.now().toString(36)}`;
const A = process.env.NN_USER;
const B = process.env.NN_USER2;

await runSpec(import.meta.url, async (ctx, page) => {
	const api = apiClient(ctx, page.request);
	const teamId = createTeam(ctx.base, A, `Tpl ${RUN}`, { [B]: 1 });
	const TW = `Kurs ${RUN}`;
	const TW2 = `Nachbar ${RUN}`;
	await api.must('POST', '/workspaces', { name: TW, team: teamId });
	await api.must('POST', '/workspaces', { name: TW2, team: teamId });
	const PW = `Privat ${RUN}`;
	await api.workspace(PW);
	const folder = await api.folder(TW, `Mappe ${RUN}`);
	const older = `Älter ${RUN}`;
	const newer = `Neuer ${RUN}`;
	await api.note(TW, older, folder, '<p>a</p>');
	const newerId = await api.note(TW, newer, null, '<p>b</p>');
	const sibling = await api.note(TW2, `Nebenan ${RUN}`, null, '<p>n</p>');
	await api.note(PW, `Eigen ${RUN}`, null, '<p>p</p>');
	const gone = await api.note(TW, `Weg ${RUN}`, null, '<p>w</p>');
	await api.must('DELETE', `/notes/${gone}`);
	// Server: Quelle aus anderem Workspace desselben Teams → 403 (vor der Oberfläche, damit der 403 nicht im Seitenabschnitt zählt)
	const denied = await api.call('POST', '/assignments', { team: teamId, title: 'x', due_at: Math.floor(Date.now() / 1000) + 3600, source_note_id: sibling, workspace: TW });
	ctx.ok(denied.status === 403 && denied.body?.code === 'source_not_allowed', 'Server: anderer Workspace desselben Teams → 403', `${denied.status} ${JSON.stringify(denied.body).slice(0, 120)}`);

	const titles = async () => page.locator('.nn-asg-source-result .nn-asg-source-title').allInnerTexts();
	const openDialog = async () => {
		await page.goto(ctx.appUrl(`assignments.php?workspace=${encodeURIComponent(TW)}`), { waitUntil: 'networkidle' });
		await page.locator('#nnAsgNew').click();
		await page.locator('#nnAsgCreateOk').waitFor({ state: 'visible', timeout: 10000 });
	};

	const m = ctx.mark();
	await openDialog();
	const lupe = page.locator('#nnAsgCreateShowAll');
	ctx.ok(await lupe.isVisible(), 'Lupe sichtbar');
	ctx.ok(/Alle Notizen anzeigen|Show all notes/.test(await lupe.getAttribute('aria-label') ?? ''), 'Lupe: aria-label', await lupe.getAttribute('aria-label'));
	const inBox = await page.evaluate(() => {
		const i = document.getElementById('nnAsgCreateSearch').getBoundingClientRect();
		const b = document.getElementById('nnAsgCreateShowAll').getBoundingClientRect();
		return b.right <= i.right + 1 && b.left > i.left + i.width / 2 && b.top >= i.top - 1 && b.bottom <= i.bottom + 1;
	});
	ctx.ok(inBox, 'Lupe rechts im Eingabefeld');
	const colors = await lupe.evaluate((b) => [getComputedStyle(b).color, getComputedStyle(document.querySelector('#nextnotes-root .nn-asg-modal .modal-content')).backgroundColor]);
	ctx.ok(colors[0] !== colors[1], 'Lupe: Farbe hebt sich vom Dialog ab', colors.join(' / '));

	// Lupe → alle Notizen des Workspace, ohne Tippen
	await lupe.click();
	await page.locator('.nn-asg-source-result').first().waitFor({ timeout: 10000 });
	let list = await titles();
	ctx.ok(list.includes(older) && list.includes(newer), 'Liste enthält Notizen des Workspace', list.join(' | '));
	ctx.ok(!list.some((x) => x.includes(`Nebenan ${RUN}`) || x.includes(`Eigen ${RUN}`) || x.includes(`Weg ${RUN}`)), 'nicht: anderer Workspace, privat, Papierkorb', list.join(' | '));
	ctx.ok(list.indexOf(newer) < list.indexOf(older), 'zuletzt geändert zuerst');
	const olderRow = page.locator('.nn-asg-source-result').filter({ hasText: older });
	ctx.ok((await olderRow.innerText()).includes(`Mappe ${RUN}`), 'Ordner im Eintrag');
	ctx.ok(await page.locator('#nnAsgCreateSearch').getAttribute('aria-expanded') === 'true', 'Combobox aria-expanded');
	await ctx.screenshot(page, 'lupe-liste');

	// Escape schließt nur die Liste, nicht den Dialog
	await page.locator('#nnAsgCreateSearch').focus();
	await page.keyboard.press('Escape');
	ctx.ok(await page.locator('.nn-asg-source-result').count() === 0 && await page.locator('#nnAsgCreateOk').isVisible(), 'Escape: Liste zu, Dialog offen');

	// Tastatur: Pfeil-runter im leeren Feld öffnet, Pfeil-runter fokussiert, Enter wählt
	await page.keyboard.press('ArrowDown');
	await page.locator('.nn-asg-source-result').first().waitFor({ timeout: 10000 });
	await page.keyboard.press('ArrowDown');
	const focused = await page.evaluate(() => document.activeElement?.querySelector('.nn-asg-source-title')?.textContent ?? '');
	ctx.ok(focused === newer, 'Pfeil-runter fokussiert den ersten Eintrag', focused);
	await page.keyboard.press('Enter');
	ctx.ok((await page.locator('.nn-asg-source-chosen .nn-asg-source-title').innerText()).includes(newer), 'Enter übernimmt die Vorlage');
	ctx.ok(await page.locator('#nnAsgCreateTitle').inputValue() === newer, 'Titel aus der Vorlage');

	// Tippen filtert im selben Workspace
	await page.locator('#nnAsgCreateSearch').fill(`Nebenan ${RUN}`);
	await page.locator('.nn-asg-source-results li').first().waitFor({ timeout: 10000 });
	ctx.ok(await page.locator('.nn-asg-source-result').count() === 0, 'Tippen: anderer Workspace nicht gefunden');
	await page.locator('#nnAsgCreateSearch').fill(`Älter ${RUN}`);
	await page.locator('.nn-asg-source-result').filter({ hasText: older }).waitFor({ timeout: 10000 });
	ctx.ok((await titles()).every((x) => x === older), 'Tippen: nur passende Notiz', (await titles()).join(' | '));
	await page.locator('#nnAsgCreateSearch').fill('');
	await lupe.click();
	await page.locator('.nn-asg-source-result').filter({ hasText: newer }).click();

	// Anlegen mit workspace
	const created = page.waitForResponse((r) => /\/api\/v1\/assignments$/.test(new URL(r.url()).pathname) && r.request().method() === 'POST');
	await page.locator('#nnAsgCreateOk').click();
	const cr = await created;
	ctx.ok(cr.status() === 201, 'Anlegen: 201', String(cr.status()));
	const sent = JSON.parse(cr.request().postData() ?? '{}');
	ctx.ok(sent.workspace === TW && sent.source_note_id === newerId, 'POST mit workspace und Vorlage', JSON.stringify(sent));
	await page.locator('.nn-asg-detail-title').waitFor({ state: 'visible', timeout: 10000 });
	ctx.assertClean('Lupe und Anlegen', m);

	// mobil: Lupe im Feld, Liste sichtbar
	const mm = ctx.mark();
	await page.setViewportSize({ width: 390, height: 844 });
	await openDialog();
	await page.locator('#nnAsgCreateShowAll').click();
	await page.locator('.nn-asg-source-result').first().waitFor({ timeout: 10000 });
	const fits = await page.evaluate(() => {
		const r = document.getElementById('nnAsgCreateResults').getBoundingClientRect();
		return r.left >= 0 && r.right <= window.innerWidth + 1 && r.height > 0;
	});
	ctx.ok(fits, 'mobil: Liste im Bildschirm');
	await ctx.screenshot(page, 'lupe-mobil');
	ctx.assertClean('mobil', mm);
});
