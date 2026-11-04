// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// M4h (M4-Plan Done M4h 1–5): Einstellungen laden; Workspace umbenennen, Standard setzen, Hintergrund hochladen; 5 Karten überstehen
// einen Reload; gestrichene Karten weder sichtbar noch über die Suche; Speicher nur eigene Zahlen (Lesefreigabe zählt nicht);
// Export-Karten liefern ZIP; Farben verwalten; fremder Zugriff auf die neuen Routen; je Abschnitt 0 CSP/pageerror/Konsolenfehler/≥ 400.
import { runSpec } from './lib/harness.mjs';
import { apiClient } from './lib/api.mjs';
import { shareRead } from './lib/docker.mjs';

const RUN = `${process.argv[3]}${Date.now().toString(36)}`;
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', 'base64');
const GONE = ['my-profile-card', 'change-password-card', 'two-factor-card', 'app-passwords-card', 'connected-apps-card', 'delete-account-card',
	'language-card', 'timezone-card', 'backup-export-card', 's3-backup-card', 'restore-import-card', 'import-notes-card', 'git-sync-card',
	'offline-notes-card', 'main-font-card', 'markdown-font-card', 'login-display-card', 'icon-sidebar-order-card', 'icon-rail-scale-card',
	'whats-new-card', 'discussions-card', 'discord-card', 'tips-card', 'support-card', 'api-rest-card', 'admin-tools-grid',
	'workspace-activity-emails-card', 'sidebar-offline-marks-card'];

await runSpec(import.meta.url, async (ctx, page) => {
	const api = apiClient(ctx, page.request);
	const WS = await api.workspace(`Einst ${RUN}`);
	// Standard-Workspace am Ende zurücksetzen (Folge-Specs wie frame erwarten „Notizen“)
	const defaultBefore = (await api.setting('default_workspace')).body?.value ?? '';
	const wq = `workspace=${encodeURIComponent(WS)}`;
	await api.note(WS, `Notiz ${RUN}`);
	const settingsUrl = (extra = '') => ctx.appUrl(`settings.php?${wq}${extra}`);
	const open = async (p, url, label) => {
		const m = ctx.mark();
		const resp = await p.goto(url, { waitUntil: 'networkidle' });
		ctx.ok(resp?.status() === 200, `${label}: 200`, String(resp?.status()));
		return m;
	};

	// 1. Seite: Abschnitte, gestrichene Karten fehlen, Speicher geladen
	let m = await open(page, settingsUrl(), 'Einstellungen');
	await ctx.selfTest(page);
	ctx.ok(await page.locator('#icon_sidebar #iconSidebarSettingsBtn.icon-sidebar-btn-active').count() === 1, 'Icon-Leiste: Einstellungen aktiv');
	const navCount = await page.locator('#settings-nav .settings-nav-item').count();
	ctx.ok(navCount === 11, 'Navigation: 11 Abschnitte', String(navCount));
	for (const id of GONE) ctx.ok(await page.locator(`#${id}`).count() === 0, `gestrichen: #${id}`);
	await page.locator('#storage-stats-user-card .settings-storage-row').first().waitFor({ state: 'attached', timeout: 10000 });
	ctx.ok(await page.locator('#storage-stats-user-card .settings-storage-row').count() === 2, 'Speicher: Notizen und Anhänge');
	await ctx.screenshot(page, 'settings');
	ctx.assertClean('Einstellungen', m);

	// 2. Suche findet gestrichene Funktionen nicht, vorhandene schon
	m = ctx.mark();
	const visibleCards = () => page.evaluate(() => [...document.querySelectorAll('.settings-content .home-card')].filter((c) => c.offsetParent !== null).map((c) => c.id));
	for (const term of ['Discord', 'Passwort', 'Backup', 'Git-Sync']) {
		await page.locator('#home-search-input').fill(term);
		await page.waitForTimeout(300);
		const hits = await visibleCards();
		ctx.ok(hits.length === 0, `Suche „${term}“: keine Karte`, hits.join(','));
	}
	await page.locator('#home-search-input').fill('Markdown');
	await page.waitForTimeout(300);
	ctx.ok((await visibleCards()).length > 0, 'Suche „Markdown“: Karten');
	await page.locator('#home-search-input').fill('');
	ctx.assertClean('Suche', m);

	// 3. Fünf Karten ändern, nach Reload erhalten
	m = ctx.mark();
	const flag = async (k) => ['1', 'true'].includes(String((await api.setting(k)).body?.value));
	const backlinksBefore = await flag('backlinks_at_bottom');
	const afterFoldersBefore = await flag('notes_without_folders_after_folders');
	await page.locator('.settings-nav-item[data-section="settings-note-content-section-grid"]').click();
	await page.locator('#backlinks-at-bottom-card').click();
	await page.locator('#slash-menu-trigger-select').selectOption('alt-slash');
	await page.locator('#tasklist-insert-order-select').selectOption('top');
	await page.locator('.settings-nav-item[data-section="settings-diary-section-grid"]').click();
	await page.locator('#diary-note-type-select').selectOption('markdown');
	await page.locator('.settings-nav-item[data-section="settings-sidebar-section-grid"]').click();
	await page.locator('#notes-without-folders-card').click();
	await page.waitForLoadState('networkidle');
	await page.waitForTimeout(500);
	await page.reload({ waitUntil: 'networkidle' });
	const vals = await Promise.all(['backlinks_at_bottom', 'slash_menu_trigger', 'tasklist_insert_order', 'diary_default_note_type', 'notes_without_folders_after_folders']
		.map(async (k) => (await api.setting(k)).body?.value));
	ctx.ok(['1', 'true'].includes(String(vals[0])) !== backlinksBefore, 'Rückverweise unten umgeschaltet', String(vals[0]));
	ctx.ok(vals[1] === 'alt-slash', 'Befehlsmenü gespeichert', String(vals[1]));
	ctx.ok(vals[2] === 'top', 'Aufgaben oben gespeichert', String(vals[2]));
	ctx.ok(vals[3] === 'markdown', 'Tagebuch-Format gespeichert', String(vals[3]));
	ctx.ok(['1', 'true'].includes(String(vals[4])) !== afterFoldersBefore, 'Notizen nach Ordnern umgeschaltet', String(vals[4]));
	ctx.ok(await page.locator('#slash-menu-trigger-select').inputValue() === 'alt-slash', 'nach Reload: Auswahl Befehlsmenü');
	ctx.ok(await page.locator('#diary-note-type-select').inputValue() === 'markdown', 'nach Reload: Tagebuch-Format');
	ctx.ok(await page.locator(`#backlinks-at-bottom-status.${backlinksBefore ? 'disabled' : 'enabled'}`).count() === 1, 'nach Reload: Rückverweise-Status');
	ctx.assertClean('Fünf Karten', m);

	// 4. Workspaces: Standard setzen, Umbenennen, Hintergrund
	m = ctx.mark();
	await page.locator('.settings-nav-item[data-section="settings-workspaces-section-grid"]').click();
	const saved = page.waitForResponse((r) => r.request().method() === 'PUT' && r.url().includes('/settings/default_workspace'));
	await page.locator('#default-workspace-select').selectOption(WS);
	ctx.ok((await saved).status() === 200, 'Standard-Workspace gespeichert');
	const row = page.locator(`.ws-row[data-ws="${WS}"]`);
	await row.locator('.ws-actions-toggle').click();
	await row.locator('.workspace-background-action').click();
	await page.locator('#backgroundImageModal').waitFor({ state: 'visible', timeout: 10000 });
	await page.locator('#backgroundImageInput').setInputFiles({ name: 'bg.png', mimeType: 'image/png', buffer: PNG });
	const up = page.waitForResponse((r) => r.request().method() === 'POST' && r.url().includes('api_upload_background.php'));
	await page.locator('#saveBackgroundBtn').click();
	ctx.ok((await up).status() === 200, 'Hintergrund hochgeladen');
	await page.waitForLoadState('networkidle');
	const bg = await page.request.get(ctx.appUrl(`api_upload_background.php?${wq}`));
	ctx.ok((await bg.json()).exists === true, 'Hintergrund vorhanden');
	const NEW = `${WS} neu`;
	await page.reload({ waitUntil: 'networkidle' });
	await row.locator('.ws-actions-toggle').click();
	await row.locator('.workspace-rename-action').click();
	await page.locator('#renameNewName').fill(NEW);
	const renamed = page.waitForResponse((r) => r.request().method() === 'POST' && r.url().includes('workspaces.php'));
	await page.locator('#confirmRenameBtn').click();
	ctx.ok((await renamed).status() === 200, 'Umbenennen gesendet');
	await page.waitForLoadState('networkidle');
	await page.goto(ctx.appUrl(`settings.php?workspace=${encodeURIComponent(NEW)}#section=settings-workspaces-section-grid`), { waitUntil: 'networkidle' });
	ctx.ok(await page.locator(`.ws-row[data-ws="${NEW}"]`).count() === 1, 'nach Reload: neuer Name in der Liste');
	ctx.ok(await page.locator('#default-workspace-select').inputValue() === NEW, 'nach Reload: Standard folgt dem Umbenennen', await page.locator('#default-workspace-select').inputValue());
	const bg2 = await page.request.get(ctx.appUrl(`api_upload_background.php?workspace=${encodeURIComponent(NEW)}`));
	ctx.ok((await bg2.json()).exists === true, 'Hintergrund bleibt nach Umbenennen');
	await ctx.screenshot(page, 'settings-workspaces');
	ctx.assertClean('Workspaces', m);
	const wqNew = `workspace=${encodeURIComponent(NEW)}`;

	// 5. Farben verwalten: Farbe hinzufügen, gespeichert
	m = ctx.mark();
	await page.locator('.settings-nav-item[data-section="settings-display-section-grid"]').click();
	await page.locator('#note-color-palette-card').click();
	await page.locator('#noteColorPaletteModal').waitFor({ state: 'visible', timeout: 10000 });
	const before = await page.locator('#noteColorPaletteList > *').count();
	await page.locator('#noteColorPaletteAddBtn').click();
	const pal = page.waitForResponse((r) => r.request().method() === 'PUT' && r.url().includes('/settings/note_color_palette'));
	await page.locator('#saveNoteColorPaletteBtn').click();
	ctx.ok((await pal).status() === 200, 'Farbpalette gespeichert');
	const palette = JSON.parse((await api.setting('note_color_palette')).body?.value || '[]');
	ctx.ok(palette.length === before + 1, 'Farbpalette: ein Eintrag mehr', `${before} → ${palette.length}`);
	ctx.assertClean('Farben verwalten', m);

	// 6. Export-Karten liefern ZIP
	m = ctx.mark();
	await page.locator('.settings-nav-item[data-section="settings-export-import-section-grid"]').click();
	for (const id of ['structured-export-card', 'structured-export-all-card', 'attachments-export-card']) {
		const href = await page.locator(`#${id}`).getAttribute('href');
		const r = await page.request.get(new URL(href, page.url()).toString());
		const body = await r.body();
		ctx.ok(r.status() === 200 && body.subarray(0, 2).toString() === 'PK', `Export ${id}: ZIP`, `${r.status()} ${r.headers()['content-type']}`);
	}
	const [dl] = await Promise.all([page.waitForEvent('download', { timeout: 20000 }), page.locator('#structured-export-card').click()]);
	ctx.ok(/\.zip$/i.test(dl.suggestedFilename()), 'Export-Karte: Download', dl.suggestedFilename());
	ctx.assertClean('Export', m);

	// 7. workspaces.php GET → Einstellungen, Bereich Workspaces
	const wr = await page.request.get(ctx.appUrl(`workspaces.php?${wqNew}`), { maxRedirects: 0 });
	ctx.ok(wr.status() === 303 && /settings\.php\?workspace=.*#section=settings-workspaces-section-grid$/.test(wr.headers().location ?? ''), 'workspaces.php GET → 303 Einstellungen', `${wr.status()} ${wr.headers().location}`);

	// 8. Fremder Zugriff: B sieht nur eigene Zahlen und eigene Workspaces; Lesefreigabe zählt nicht
	{
		shareRead(ctx.base, process.env.NN_USER, NEW, process.env.NN_USER2);
		const ctxB = await page.context().browser().newContext({ viewport: { width: 1400, height: 900 }, locale: 'de-DE' });
		await ctx.attach(ctxB);
		const pb = await ctxB.newPage();
		await ctx.login(pb, process.env.NN_USER2, process.env.NN_PASS2);
		const apiB = apiClient(ctx, pb.request, process.env.NN_USER2, process.env.NN_PASS2);
		const stats = async () => {
			const html = await (await pb.request.get(ctx.appUrl(`storage-stats-user.php?fragment=1&${wqNew}`))).text();
			return Number((html.match(/settings-storage-value">([\d.,]+)/) ?? [])[1]?.replace(/[.,]/g, ''));
		};
		const n0 = await stats();
		await apiB.note(await apiB.defaultWorkspace(), `B ${RUN}`);
		const n1 = await stats();
		ctx.ok(n1 === n0 + 1, 'Speicher B: eigene Notiz zählt', `${n0} → ${n1}`);
		await api.note(NEW, `A2 ${RUN}`);
		ctx.ok(await stats() === n1, 'Speicher B: Notiz im lesend geteilten Workspace zählt nicht');
		m = await open(pb, ctx.appUrl(`settings.php?${wqNew}#section=settings-workspaces-section-grid`), 'Einstellungen B');
		ctx.ok(await pb.locator(`.ws-row[data-ws="${NEW}"]`).count() === 0, 'B: fremder Workspace nicht in der Liste');
		const ren = await pb.request.post(ctx.appUrl('workspaces.php'), {
			headers: { requesttoken: await pb.evaluate(() => document.head.dataset.requesttoken || '') },
			form: { action: 'rename', name: NEW, new_name: `${NEW} B` },
		});
		ctx.ok([403, 404].includes(ren.status()), 'B: fremden Workspace umbenennen abgewiesen', String(ren.status()));
		ctx.allow('status', /workspaces\.php/, 'erwartete Abweisung (fremder Workspace)');
		await ctx.screenshot(pb, 'settings-b');
		ctx.assertClean('Einstellungen B', m);
		await ctxB.close();
	}

	// 9. Über: Icon-Leiste hebt „Über“ hervor, Abschnitt sichtbar
	m = await open(page, ctx.appUrl(`settings.php?${wqNew}&open=about`), 'Über');
	ctx.info(`Über-URL: ${page.url()}`);
	ctx.ok(await page.locator('#iconSidebarAboutBtn.icon-sidebar-btn-active').count() === 1, 'Icon-Leiste: Über aktiv');
	await ctx.screenshot(page, 'settings-about');
	ctx.ok(await page.locator('#check-updates-card').isVisible(), 'Über: Version sichtbar', await page.evaluate(() => document.querySelector('.settings-nav-item.is-active')?.dataset.section ?? '-'));
	ctx.assertClean('Über', m);
	await api.must('PUT', '/settings/default_workspace', { value: defaultBefore });
});
