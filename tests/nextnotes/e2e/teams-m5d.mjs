// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// M5d (Plan M5 §2.7): Freigabe-Dialog mit Suche (bearbeiten → nur lesen → aufheben), kein /users/profiles; Team-Workspace über
// „Neuer Workspace“ mit Team-Auswahl, Kennzeichen im Menü, B ohne Verwalten; Erwähnung im Team; Banner eingefroren/verwaist,
// Umwandeln; Editor Nur-Lesen ohne Sperr-Request; Erstellen-Menü schließt per Escape und Klick direkt nach Öffnen (B65). 0 CSP/Konsole.
import { runSpec } from './lib/harness.mjs';
import { apiClient } from './lib/api.mjs';
import { createTeam, ensureUser, occ, orphanTeam } from './lib/docker.mjs';

const RUN = `${process.argv[3]}${Date.now().toString(36)}`;
const A = process.env.NN_USER;
const B = process.env.NN_USER2;
const C = 'carol';

await runSpec(import.meta.url, async (ctx, page, context, browser) => {
	ensureUser(ctx.base, C, 'Carol Mitlesend', process.env.NN_PASS2);
	occ(ctx.base, 'app:enable', 'notifications');
	const api = apiClient(ctx, page.request);
	const ctxB = await browser.newContext();
	const ctxC = await browser.newContext();
	const apiB = apiClient(ctx, ctxB.request, B, process.env.NN_PASS2);
	const apiC = apiClient(ctx, ctxC.request, C, process.env.NN_PASS2);
	await apiB.defaultWorkspace();
	await apiC.defaultWorkspace();

	// ---------- 1. Freigabe-Dialog ----------
	const WS = `Teilen ${RUN}`;
	await api.must('POST', '/workspaces', { name: WS });
	await page.goto(ctx.appUrl(`settings.php?workspace=${encodeURIComponent(WS)}#section=settings-workspaces-section-grid`), { waitUntil: 'networkidle' });
	await ctx.selfTest(page);
	const m = ctx.mark();
	const row = page.locator(`.ws-row[data-ws="${WS}"]`);
	const openShare = async () => {
		await row.locator('.ws-actions-toggle').click();
		await row.locator('.btn-share-toggle').click();
		await page.waitForSelector('.nn-share-modal', { state: 'visible', timeout: 5000 });
	};
	await openShare();
	const searchResp = page.waitForResponse((r) => r.url().includes('/api/v1/users/search') && r.status() === 200);
	await page.fill('.nn-share-search', 'car');
	await searchResp;
	const hit = page.locator(`.nn-share-result[data-uid="${C}"]`);
	ctx.ok(await hit.waitFor({ state: 'visible', timeout: 5000 }).then(() => true, () => false), 'Suche „car“ findet carol');
	await hit.click();
	ctx.ok(await page.locator('.nn-share-recipient select').inputValue() === 'write', 'Standard „bearbeiten“');
	await ctx.screenshot(page, 'share-dialog');
	let save = page.waitForResponse((r) => r.url().includes('workspaces.php') && r.request().method() === 'POST');
	await page.click('.nn-share-modal .btn-primary');
	ctx.ok((await save).status() === 200, 'Teilen → 200');
	await page.waitForSelector('.nn-share-modal', { state: 'detached', timeout: 5000 });
	const keyC = ((await apiC.must('GET', '/workspaces')).workspaces ?? []).map((w) => w.name).find((n) => n.startsWith(WS));
	ctx.ok(!!keyC, 'C sieht den Workspace', keyC);
	const noteC = await apiC.call('POST', '/notes', { heading: `Von C ${RUN}`, content: '<p>c</p>', workspace: keyC });
	ctx.ok(noteC.status === 201 || noteC.status === 200, `C schreibt (${noteC.status})`);

	await openShare();
	ctx.ok(await page.locator(`.nn-share-recipient[data-uid="${C}"]`).count() === 1, 'Dialog zeigt bestehenden Empfänger');
	await page.selectOption(`.nn-share-recipient[data-uid="${C}"] select`, 'read');
	save = page.waitForResponse((r) => r.url().includes('workspaces.php') && r.request().method() === 'POST');
	await page.click('.nn-share-modal .btn-primary');
	ctx.ok((await save).status() === 200, 'nur lesen → 200');
	await page.waitForSelector('.nn-share-modal', { state: 'detached', timeout: 5000 });
	const denied = await apiC.call('POST', '/notes', { heading: `Nicht ${RUN}`, content: '<p>x</p>', workspace: keyC });
	ctx.ok(denied.status === 403, `C nur lesen: Schreiben 403 (${denied.status})`);
	const idC = noteC.body?.note?.id;
	ctx.ok((await apiC.call('GET', `/notes/${idC}`)).status === 200, 'C liest weiter');

	await openShare();
	save = page.waitForResponse((r) => r.url().includes('workspaces.php') && r.request().method() === 'POST');
	await page.click('.nn-share-modal .btn-danger');
	ctx.ok((await save).status() === 200, 'Freigabe aufheben → 200');
	ctx.ok((await apiC.call('GET', `/notes/${idC}`)).status === 404, 'C nach Aufheben 404');
	ctx.ok(!ctx.requests.slice(m.q).some((r) => r.url.includes('/users/profiles')), 'kein Request auf /users/profiles');
	ctx.assertClean('Freigabe-Dialog', m);

	// ---------- 2. Team-Workspace über „Neuer Workspace“ ----------
	const teamName = `Klasse ${RUN}`;
	const teamId = createTeam(ctx.base, A, teamName, { [B]: 1 });
	ctx.ok(/^[A-Za-z0-9]+$/.test(teamId), 'Team angelegt', teamId);
	const start = await api.defaultWorkspace();
	await page.goto(ctx.appUrl(`index.php?workspace=${encodeURIComponent(start)}`), { waitUntil: 'networkidle' });
	const m2 = ctx.mark();
	await page.locator('.sidebar-title').first().click({ force: true });
	await page.locator('#workspaceMenu [data-action="new-workspace"]').first().click();
	await page.waitForSelector('#createWorkspaceModal', { state: 'visible', timeout: 5000 });
	const option = page.locator(`#createWorkspaceTeamSelect option[value="${teamId}"]`);
	ctx.ok(await option.waitFor({ state: 'attached', timeout: 5000 }).then(() => true, () => false), 'Team in der Auswahl');
	const TW = `Mathe ${RUN}`;
	await page.fill('#createWorkspaceInput', TW);
	await page.selectOption('#createWorkspaceTeamSelect', teamId);
	const created = page.waitForResponse((r) => r.url().includes('/api/v1/workspaces') && r.request().method() === 'POST');
	await page.click('#confirmCreateWorkspaceBtn');
	ctx.ok((await created).status() === 201, 'Team-Workspace → 201');
	ctx.ok(JSON.parse((await created).request().postData() || '{}').team === teamId, 'POST enthält team');
	await page.waitForFunction((n) => new URL(location.href).searchParams.get('workspace') === n, TW, { timeout: 10000 }).catch(() => null);
	const wsB = ((await apiB.must('GET', '/workspaces')).workspaces ?? []).find((w) => w.name === TW);
	ctx.ok(wsB?.team === teamName, 'B sieht Team-Workspace mit Teamname', JSON.stringify(wsB));
	await page.locator('.sidebar-title').first().click({ force: true });
	const mark = page.locator(`#workspaceMenu .workspace-menu-item[data-workspace-name="${TW}"] .nn-team-mark`);
	ctx.ok(await mark.waitFor({ state: 'visible', timeout: 5000 }).then(() => true, () => false), 'Team-Kennzeichen im Workspace-Menü');
	ctx.ok((await mark.innerText()).includes(teamName), 'Kennzeichen nennt das Team');
	await ctx.screenshot(page, 'team-menu');
	await page.keyboard.press('Escape');
	const settingsB = await ctxB.request.get(ctx.appUrl('settings.php'), { headers: { Authorization: `Basic ${Buffer.from(`${B}:${process.env.NN_PASS2}`).toString('base64')}` } });
	const htmlB = await settingsB.text();
	const rowB = (htmlB.match(new RegExp(`<li class="ws-row[^"]*" data-ws="${TW}">([\\s\\S]*?)</li>`)) || [])[1] || '';
	ctx.ok(rowB.includes('nn-team-badge') && !rowB.includes('workspace-rename-action') && !rowB.includes('btn-delete'), 'B: Kennzeichen, keine Verwalten-Einträge');

	// ---------- 3. Erwähnung im Team ----------
	await page.goto(ctx.appUrl(`index.php?workspace=${encodeURIComponent(TW)}`), { waitUntil: 'networkidle' });
	ctx.ok(await page.evaluate((b) => Array.isArray(window.PoznoteMentionMembers) && window.PoznoteMentionMembers.includes(b), B), 'B in den Erwähnungs-Vorschlägen');
	const mentionNote = await api.note(TW, `Erwähnung ${RUN}`, null, `<p>Hallo @${B} und @${C}</p>`);
	const notif = async (req, user) => {
		const r = await req.get(`${ctx.base}/ocs/v2.php/apps/notifications/api/v2/notifications?format=json`, {
			headers: { Authorization: `Basic ${Buffer.from(`${user}:${process.env.NN_PASS2}`).toString('base64')}`, 'OCS-APIRequest': 'true' },
		});
		const j = await r.json().catch(() => ({}));
		return (j?.ocs?.data ?? []).filter((n) => n.app === 'nextnotes' && n.object_id === String(mentionNote));
	};
	ctx.ok((await notif(ctxB.request, B)).length === 1, 'B erhält die Erwähnung');
	ctx.ok((await notif(ctxC.request, C)).length === 0, 'C (kein Mitglied) erhält nichts');
	ctx.assertClean('Team-Workspace', m2);

	// ---------- 4. Banner eingefroren ----------
	const noteRo = await api.note(TW, `Lesen ${RUN}`);
	occ(ctx.base, 'app:disable', 'circles');
	try {
		let frozen = false;
		for (let i = 0; i < 15 && !frozen; i++) {
			await page.goto(ctx.appUrl(`index.php?workspace=${encodeURIComponent(TW)}`), { waitUntil: 'networkidle' });
			frozen = await page.locator('.nn-team-banner-frozen').count() === 1;
			if (!frozen) await page.waitForTimeout(1000);
		}
		ctx.ok(frozen, 'Banner „eingefroren“ bei deaktiviertem Circles');
		await ctx.screenshot(page, 'banner-frozen');
	} finally {
		occ(ctx.base, 'app:enable', 'circles');
	}

	// ---------- 5. Banner verwaist, Nur-Lesen, Umwandeln ----------
	orphanTeam(ctx.base, A, teamId);
	const locks = [];
	page.on('request', (r) => { if (r.method() === 'POST' && /\/lock(\?|$)/.test(r.url())) locks.push(r.url()); });
	let orphaned = false;
	for (let i = 0; i < 15 && !orphaned; i++) {
		await page.goto(ctx.appUrl(`index.php?workspace=${encodeURIComponent(TW)}&note=${noteRo}`), { waitUntil: 'networkidle' });
		orphaned = await page.locator('.nn-team-banner-orphaned').count() === 1;
		if (!orphaned) await page.waitForTimeout(1000);
	}
	const m5 = ctx.mark();
	ctx.ok(orphaned, 'Banner „verwaist“');
	ctx.ok(await page.locator(`#entry${noteRo}[contenteditable="false"], .noteentry[contenteditable="false"]`).count() > 0, 'Editor nur lesen');
	ctx.ok(locks.length === 0, 'kein Sperr-Request', locks.join(' '));
	await ctx.screenshot(page, 'banner-orphaned');
	await page.click('.nn-team-convert');
	await page.waitForSelector('.alert-modal-button.primary, .modal-alert .btn-primary', { state: 'visible', timeout: 5000 }).catch(() => null);
	const dialogText = await page.locator('body').innerText();
	ctx.ok(/anderer Mitglieder|other members/.test(dialogText), 'Dialog nennt: alle Notizen gehen mit');
	const convert = page.waitForResponse((r) => r.url().includes('/make-private') && r.request().method() === 'POST');
	await page.locator('.alert-modal-button.primary, .modal-alert .btn-primary').last().click();
	ctx.ok((await convert).status() === 200, 'Umwandeln → 200');
	await page.waitForLoadState('networkidle');
	ctx.ok(await page.locator('.nn-team-banner').count() === 0, 'nach Umwandeln kein Banner');
	const own = ((await api.must('GET', '/workspaces')).workspaces ?? []).find((w) => w.name.startsWith(TW));
	ctx.ok(own && own.team === null, 'Workspace jetzt privat', JSON.stringify(own));
	ctx.assertClean('Banner/Umwandeln', m5);

	// ---------- 6. Erstellen-Menü (B65) ----------
	await page.goto(ctx.appUrl(`index.php?workspace=${encodeURIComponent(start)}`), { waitUntil: 'networkidle' });
	const menu = page.locator('#create-menu');
	await page.click('#sidebarCreateBtn');
	ctx.ok(await menu.evaluate((el) => el.classList.contains('show')), 'Erstellen-Menü offen');
	await page.keyboard.press('Escape');
	ctx.ok(!(await menu.evaluate((el) => el.classList.contains('show'))), 'Escape schließt das Erstellen-Menü');
	// Klick außerhalb unmittelbar nach dem Öffnen (gleicher Task-Durchlauf wie das Öffnen)
	await page.evaluate(() => {
		document.getElementById('sidebarCreateBtn').click();
		document.getElementById('right_col').dispatchEvent(new MouseEvent('click', { bubbles: true }));
	});
	ctx.ok(!(await menu.evaluate((el) => el.classList.contains('show'))), 'Klick direkt nach dem Öffnen schließt');
	await page.click('#sidebarCreateBtn');
	await page.mouse.click(900, 600);
	ctx.ok(!(await menu.evaluate((el) => el.classList.contains('show'))), 'Klick außerhalb schließt');
	ctx.assertClean('Erstellen-Menü', m5);
	await ctxB.close();
	await ctxC.close();
});
