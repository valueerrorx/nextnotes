// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// UI-dashws (Thomas 2026-11-09): im Dashboard über mehrere Workspaces führt ein Klick auf den Workspace-Kopf, die Brotkrumen,
// das Kennzeichen einer Karte oder eine Karte selbst in diesen Workspace (?workspace=) und merkt ihn als zuletzt geöffneten,
// sodass Ansichten ohne Parameter danach in ihm stehen. Aufgabenliste (UI-dashcard): wie Notizkarte, kein Modal.
// Team-Workspace mit Material-Ordner (Mitglied). 0 CSP/Konsole.
import { runSpec } from './lib/harness.mjs';
import { apiClient } from './lib/api.mjs';
import { createTeam } from './lib/docker.mjs';

const RUN = `${process.argv[3]}${Date.now().toString(36)}`;
const A = process.env.NN_USER;
const B = process.env.NN_USER2;

await runSpec(import.meta.url, async (ctx, page, context, browser) => {
	const api = apiClient(ctx, page.request);
	const ws1 = await api.defaultWorkspace();
	const ws2 = `Bereich ${RUN}`;
	await api.workspace(ws2);
	const note1 = `Eins ${RUN}`;
	const note2 = `Zwei ${RUN}`;
	const list2 = `Liste ${RUN}`;
	await api.note(ws1, note1, null, '<p>a</p>');
	await api.note(ws2, note2, null, '<p>b</p>');
	await api.must('POST', '/notes', { heading: list2, type: 'tasklist', workspace: ws2,
		content: JSON.stringify([{ id: 1, text: 'Lesen', completed: false, important: false, noteId: 0 }]) });

	// Team-Workspace: A Lehrkraft, B Mitglied; Material-Ordner mit Notiz
	const teamId = createTeam(ctx.base, A, `Klasse ${RUN}`, { [B]: 1 });
	const TW = `Team ${RUN}`;
	await api.must('POST', '/workspaces', { name: TW, team: teamId });
	const folderName = `Mappe ${RUN}`;
	const M = await api.folder(TW, folderName);
	const matNote = `Blatt ${RUN}`;
	await api.note(TW, matNote, M, '<p>Arbeitsblatt</p>');
	await api.must('PUT', `/folders/${M}/material`, { material: true });

	const setLast = (a, ws) => a.must('PUT', '/settings/last_opened_workspace', { value: ws });
	const setScope = (a, value) => a.must('PUT', '/settings/dashboard_scope', { value: JSON.stringify(value) });
	const lastOpened = async (a) => {
		const r = await a.setting('last_opened_workspace');
		return typeof r.body === 'object' && r.body !== null ? (r.body.value ?? r.body.data?.value ?? '') : String(r.body ?? '');
	};
	// keepalive-PUT läuft neben der Navigation: kurz warten, bis der Wert ankommt
	const waitLast = async (a, ws) => {
		let v = '';
		for (let i = 0; i < 25; i++) {
			v = await lastOpened(a);
			if (v === ws) return v;
			await new Promise((r) => setTimeout(r, 200));
		}
		return v;
	};
	const isIndexIn = (u, ws) => u.pathname.endsWith('/index.php') && u.searchParams.get('workspace') === ws;
	const clickTo = async (p, locator, ws) => {
		await Promise.all([p.waitForURL((u) => isIndexIn(u, ws), { timeout: 15000 }), locator.click()]);
		await p.waitForLoadState('networkidle');
		return new URL(p.url());
	};
	const sidebarWs = (p) => p.locator('#left_col .workspace-title-text').first().textContent({ timeout: 10000 }).then((t) => t.trim(), () => '');
	const groupLink = (p, ws) => p.locator('#dashboardGrid .dash-group-title a.nn-dash-ws-link', { has: p.locator('.dash-group-name', { hasText: ws }) });
	// Board öffnen; Upstream stellt den zuletzt geöffneten Ordner wieder her (localStorage) → zurück zur Wurzel
	const board = async (p) => {
		await p.goto(ctx.appUrl('dashboard.php'), { waitUntil: 'networkidle' });
		const home = p.locator('#dashboardBreadcrumb .bc-home');
		if (await home.isVisible().catch(() => false)) await home.click();
	};

	await setScope(api, { scope: 'list', ws: [ws1, ws2] });
	const m = ctx.mark();

	// 1. Workspace-Kopf ist ein Link in die Hauptansicht des Workspace
	await setLast(api, ws1);
	await board(page);
	await ctx.selfTest(page);
	const head = groupLink(page, ws2);
	ctx.ok(await head.count() === 1, 'Kopf der Gruppe ist ein Link');
	const hd = await head.evaluate((a) => ({ href: a.getAttribute('href'), color: getComputedStyle(a).color, title: getComputedStyle(a.closest('.dash-group-title')).color,
		deco: getComputedStyle(a).textDecorationLine, mark: !!a.querySelector('.dash-ws-dot, .lucide-layers') }));
	ctx.ok(hd.href === `index.php?workspace=${encodeURIComponent(ws2).replace(/%20/g, '+')}` || hd.href === `index.php?workspace=${encodeURIComponent(ws2)}`,
		'Kopf-Link zielt auf index.php?workspace=', hd.href);
	ctx.ok(hd.color === hd.title && hd.deco === 'none' && hd.mark, 'Kopf-Link sieht aus wie der Kopf (Farbe, ohne Unterstrich, mit Kennzeichen)', JSON.stringify(hd));
	await head.hover();
	ctx.ok(await head.evaluate((a) => getComputedStyle(a).backgroundColor) !== 'rgba(0, 0, 0, 0)', 'Kopf-Link mit Hover-Fläche');
	await ctx.screenshot(page, 'groups');
	let u = await clickTo(page, head, ws2);
	ctx.ok(isIndexIn(u, ws2) && !u.searchParams.get('note'), 'Kopf → Hauptansicht des Workspace', u.search);
	ctx.ok(await sidebarWs(page) === ws2, 'Seitenleiste zeigt den gewählten Workspace');
	ctx.ok(await waitLast(api, ws2) === ws2, 'Kopf-Klick merkt last_opened_workspace', await lastOpened(api));

	// 2. Danach stehen Ansichten ohne Parameter im gewählten Workspace
	await page.goto(ctx.appUrl('list_tags.php'), { waitUntil: 'networkidle' });
	const chip = (await page.locator('[data-nn-ws-chip] .poznote-page-title-workspace-name').textContent()).trim();
	ctx.ok(chip === ws2, 'list_tags.php ohne Parameter steht im gewählten Workspace', chip);
	await board(page);
	const dbw = await page.evaluate(() => ({ ws: document.body.dataset.workspace, rail: document.getElementById('iconSidebarTagsBtn')?.getAttribute('href') ?? '',
		groups: document.querySelectorAll('#dashboardGrid .dash-group-title').length }));
	ctx.ok(dbw.ws === ws2 && new URL(dbw.rail, ctx.base).searchParams.get('workspace') === ws2, 'dashboard.php ohne Parameter: Seiten-Workspace und Icon-Leiste im gewählten Workspace', JSON.stringify(dbw));
	ctx.ok(dbw.groups === 2, 'gespeicherter Dashboard-Bereich bleibt (zwei Gruppen)', JSON.stringify(dbw));

	// 3. Notizkarte → Notiz im Workspace
	await setLast(api, ws1);
	await board(page);
	u = await clickTo(page, page.locator('#dashboardGrid .dash-note-card .dash-card-link', { hasText: note2 }), ws2);
	ctx.ok(isIndexIn(u, ws2) && !!u.searchParams.get('note'), 'Notizkarte → index.php?note=&workspace=', u.search);
	ctx.ok(await sidebarWs(page) === ws2, 'Notizkarte: Seitenleiste im Workspace der Notiz');
	ctx.ok(await waitLast(api, ws2) === ws2, 'Notizkarte merkt last_opened_workspace');

	// 4. Aufgabenliste (UI-dashcard): Karte wechselt wie eine Notizkarte in den Workspace, kein Modal; Klick auf eine Aufgabe ebenso
	await setLast(api, ws1);
	await board(page);
	const listCard = page.locator('#dashboardGrid .dash-note-card', { hasText: list2 });
	const lc = await listCard.evaluate((c) => ({ modal: !!document.getElementById('dashboardNoteModal'), api: typeof window.poznoteDashboardNoteModal,
		inputs: c.querySelectorAll('input, button:not(.dash-card-pin), [contenteditable]').length, tasks: c.querySelectorAll('.board-card-tasks li').length }));
	ctx.ok(!lc.modal && lc.api === 'undefined' && lc.inputs === 0 && lc.tasks === 1, 'Aufgabenlisten-Karte ohne Modal und ohne bedienbare Häkchen', JSON.stringify(lc));
	u = await clickTo(page, listCard.locator('.board-card-tasks li').first(), ws2);
	ctx.ok(isIndexIn(u, ws2) && !!u.searchParams.get('note'), 'Aufgabenlisten-Karte → index.php?note=&workspace=', u.search);
	ctx.ok(await sidebarWs(page) === ws2, 'Aufgabenliste: Seitenleiste im Workspace der Liste');
	ctx.ok(await waitLast(api, ws2) === ws2, 'Aufgabenlisten-Karte merkt last_opened_workspace');

	// 5. Kennzeichen in der Kartenfußzeile → Hauptansicht des Workspace
	await setLast(api, ws1);
	await board(page);
	u = await clickTo(page, page.locator('#dashboardGrid .dash-note-card', { hasText: note2 }).locator('.board-card-ws'), ws2);
	ctx.ok(isIndexIn(u, ws2) && !u.searchParams.get('note'), 'Karten-Kennzeichen → Hauptansicht des Workspace', u.search);
	ctx.ok(await waitLast(api, ws2) === ws2, 'Karten-Kennzeichen merkt last_opened_workspace');
	ctx.assertClean('dashboard-ws A', m);

	// 6. Team-Workspace als Mitglied: Material-Ordner, Brotkrumen, Material-Karte, Ordner-Kennzeichen
	const ctxB = await browser.newContext({ viewport: { width: 1400, height: 900 }, locale: 'de-DE' });
	await ctx.attach(ctxB);
	const pb = await ctxB.newPage();
	await ctx.login(pb, B, process.env.NN_PASS2);
	const apiB = apiClient(ctx, ctxB.request, B, process.env.NN_PASS2);
	const privB = await apiB.defaultWorkspace();
	await setScope(apiB, { scope: 'list', ws: [privB, TW] });
	const mb = ctx.mark();
	await setLast(apiB, privB);
	await board(pb);
	ctx.ok(await groupLink(pb, TW).count() === 1, 'B: Team-Workspace mit Kopf-Link');
	const folderCard = pb.locator('#dashboardGrid .dash-folder-card', { hasText: folderName });
	await folderCard.locator('.dash-card-name').click();
	const crumb = pb.locator('#dashboardBreadcrumb a.bc-workspace');
	await crumb.waitFor({ state: 'visible', timeout: 8000 });
	ctx.ok(new URL(await crumb.evaluate((a) => a.href)).searchParams.get('workspace') === TW, 'B: Brotkrumen nennen den Team-Workspace als Link');
	await ctx.screenshot(pb, 'team-folder');
	u = await clickTo(pb, pb.locator('#dashboardGrid .dash-note-card .dash-card-link', { hasText: matNote }), TW);
	ctx.ok(isIndexIn(u, TW) && !!u.searchParams.get('note'), 'B: Material-Karte → Notiz im Team-Workspace', u.search);
	ctx.ok(await sidebarWs(pb) === TW, 'B: Seitenleiste im Team-Workspace');
	ctx.ok(await waitLast(apiB, TW) === TW, 'B: Material-Karte merkt last_opened_workspace');
	await pb.goto(ctx.appUrl('list_folders.php'), { waitUntil: 'networkidle' });
	const chipB = (await pb.locator('[data-nn-ws-chip] .poznote-page-title-workspace-name').textContent()).trim();
	ctx.ok(chipB === TW, 'B: Ordner-Ansicht ohne Parameter im Team-Workspace', chipB);

	await setLast(apiB, privB);
	await board(pb);
	await pb.locator('#dashboardGrid .dash-folder-card', { hasText: folderName }).locator('.dash-card-ws').click();
	await pb.waitForURL((x) => isIndexIn(x, TW), { timeout: 15000 }).catch(() => {});
	ctx.ok(isIndexIn(new URL(pb.url()), TW), 'B: Kennzeichen der Ordnerkarte → Hauptansicht statt Ordner', pb.url());
	ctx.ok(await waitLast(apiB, TW) === TW, 'B: Ordner-Kennzeichen merkt last_opened_workspace');

	// Brotkrumen-Link
	await setLast(apiB, privB);
	await board(pb);
	await pb.locator('#dashboardGrid .dash-folder-card', { hasText: folderName }).locator('.dash-card-name').click();
	u = await clickTo(pb, pb.locator('#dashboardBreadcrumb a.bc-workspace'), TW);
	ctx.ok(isIndexIn(u, TW), 'B: Brotkrumen-Workspace → Hauptansicht', u.search);
	ctx.assertClean('dashboard-ws B', mb);

	// Aufräumen: gespeicherter Bereich zurück auf die Vorgabe
	await setScope(api, { scope: 'all' });
	await setScope(apiB, { scope: 'all' });
	await setLast(api, ws1);
	await setLast(apiB, privB);
});
