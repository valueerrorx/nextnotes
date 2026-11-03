// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// M3-ui1: Schnellknöpfe der Ordnerzeile (Kanban, „Hier erstellen“). Sichtbarkeit (Hover/Fokus/aktiv/Touch), Aktionen wie die
// Menüpunkte, Name-Klick klappt nur, Tastatur, nach In-place-Neuaufbau von #left_col, Lesefreigabe ohne „+“.
import { runSpec } from './lib/harness.mjs';
import { apiClient } from './lib/api.mjs';
import { shareRead } from './lib/docker.mjs';

const RUN = `${process.argv[3]}${Date.now().toString(36)}`;

await runSpec(import.meta.url, async (ctx, page, context, browser) => {
	const api = apiClient(ctx, page.request);
	const WS = await api.workspace(`Schnell ${RUN}`);
	const A = await api.folder(WS, `A ${RUN}`);
	const B = await api.folder(WS, `B ${RUN}`);
	const C = await api.folder(WS, `C ${RUN}`);
	const noteA = await api.note(WS, `In A ${RUN}`, A, '<p>a</p>');
	await api.note(WS, `In C ${RUN}`, C, '<p>c</p>');

	const row = (p, fid) => p.locator(`#left_col .folder-header[data-folder-key="folder_${fid}"] > .folder-toggle`);
	const quick = (p, fid, action) => row(p, fid).locator(`> .folder-actions > button.nn-folder-quick[data-action="${action}"]`);
	const width = async (loc) => (await loc.boundingBox())?.width ?? 0;
	const waitFor = async (fn, ms = 8000) => {
		for (const end = Date.now() + ms; Date.now() < end; await page.waitForTimeout(150)) if (await fn()) return true;
		return fn();
	};
	const kanbanOpen = async (fid) => (await page.locator(`#kanban-view-container[data-folder-id="${fid}"]`).count()) === 1;
	const display = (fid) => page.locator(`#folder-${fid}`).evaluate((el) => el.style.display);
	const parkMouse = () => page.mouse.move(1390, 890);

	await page.goto(ctx.appUrl(`index.php?workspace=${encodeURIComponent(WS)}&note=${noteA}`), { waitUntil: 'networkidle' });
	await ctx.selfTest(page);
	await page.waitForSelector(`#entry${noteA}`);

	// 1. Markup: je Ordner zwei echte Knöpfe vor dem Drei-Punkte-Schalter, Beschriftung wie die Menüpunkte
	{
		const m = ctx.mark();
		const menuLabel = async (action) => (await page.locator(`#folder-actions-menu [data-action="${action}"] span`).first().textContent()).trim();
		const kLabel = await menuLabel('open-kanban-view');
		const cLabel = await menuLabel('create-note-in-folder');
		for (const fid of [A, B, C]) {
			const k = quick(page, fid, 'open-kanban-view');
			const c = quick(page, fid, 'create-note-in-folder');
			ctx.ok((await k.count()) === 1 && (await c.count()) === 1, `Markup ${fid}: Kanban- und „+“-Knopf vorhanden`);
			ctx.ok(await k.evaluate((el) => el.tagName === 'BUTTON' && el.type === 'button'), `Markup ${fid}: Kanban ist <button type=button>`);
			ctx.ok(await k.getAttribute('title') === kLabel && await k.getAttribute('aria-label') === kLabel, `Markup ${fid}: Kanban title/aria-label = Menütext`, kLabel);
			ctx.ok(await c.getAttribute('title') === cLabel && await c.getAttribute('aria-label') === cLabel, `Markup ${fid}: „+“ title/aria-label = Menütext`, cLabel);
			const order = await row(page, fid).locator('> .folder-actions > *').evaluateAll((els) => els.map((e) => e.dataset.action));
			ctx.ok(order.join(',') === 'open-kanban-view,create-note-in-folder,toggle-folder-actions-menu', `Markup ${fid}: Reihenfolge vor dem Drei-Punkte-Menü`, order.join(','));
		}
		ctx.ok((await page.locator('#left_col .favorites-section .nn-folder-quick').count()) === 0, 'Markup: Favoriten-Bereich ohne Schnellknöpfe');
		ctx.assertClean('Markup', m);
	}

	// 2. Sichtbarkeit: verborgen ohne Hover, sichtbar bei Hover und beim aktiven Ordner (A enthält die offene Notiz)
	{
		await parkMouse();
		await page.waitForTimeout(200);
		ctx.ok(await width(quick(page, B, 'open-kanban-view')) <= 1, 'Sicht: B ohne Hover verborgen');
		ctx.ok(await width(quick(page, A, 'open-kanban-view')) >= 16 && await width(quick(page, A, 'create-note-in-folder')) >= 16, 'Sicht: aktiver Ordner A zeigt beide Knöpfe ohne Hover');
		await row(page, B).hover();
		ctx.ok(await width(quick(page, B, 'open-kanban-view')) >= 16 && await width(quick(page, B, 'create-note-in-folder')) >= 16, 'Sicht: B bei Hover sichtbar');
		const style = await quick(page, B, 'open-kanban-view').evaluate((el) => { const s = getComputedStyle(el); return `${s.backgroundColor}|${s.borderTopWidth}`; });
		ctx.ok(/rgba\(0, 0, 0, 0\)|transparent/.test(style) && style.endsWith('|0px'), 'Sicht: Knopf ohne NC-Kernrahmen/-hintergrund', style);
		await ctx.screenshot(page, 'hover');
	}

	// 3. Name-Klick klappt nur ein/aus (kein Kanban, kein Erstellen-Menü)
	{
		const m = ctx.mark();
		const before = await display(C);
		await row(page, C).locator('.folder-name').click();
		ctx.ok(await waitFor(async () => (await display(C)) !== before), 'Name: Klick klappt C um', before);
		await page.waitForTimeout(400);
		ctx.ok(!(await kanbanOpen(C)), 'Name: kein Kanban geöffnet');
		ctx.ok(!(await page.locator('#create-menu.show').count()), 'Name: kein Erstellen-Menü');
		await row(page, C).locator('.folder-name').click();
		ctx.ok(await waitFor(async () => (await display(C)) === before), 'Name: zweiter Klick klappt zurück');
		ctx.assertClean('Name', m);
	}

	// 4. Kanban-Knopf öffnet die Kanban-Ansicht des Ordners, klappt nicht um
	{
		const m = ctx.mark();
		const before = await display(C);
		await row(page, C).hover();
		await quick(page, C, 'open-kanban-view').click();
		ctx.ok(await waitFor(() => kanbanOpen(C)), 'Kanban: Ansicht von C geöffnet');
		ctx.ok((await display(C)) === before, 'Kanban: Ordner C nicht umgeklappt');
		ctx.ok((await page.locator(`#kanban-view-container .kanban-card`).count()) >= 1, 'Kanban: Karte der Notiz in C sichtbar');
		ctx.assertClean('Kanban', m);
	}

	// 5. „+“ öffnet das Erstellen-Menü für den Ordner; Notiz landet in B
	{
		const m = ctx.mark();
		const before = await display(B);
		const count = await page.locator(`#folder-${B} .links_arbo_left`).count();
		await row(page, B).hover();
		await quick(page, B, 'create-note-in-folder').click();
		ctx.ok(await waitFor(async () => (await page.locator('#create-menu.show').count()) === 1), '„+“: Erstellen-Menü offen');
		ctx.ok(await page.locator('#subfolderOption').isVisible(), '„+“: Menü im Ordner-Modus (Unterordner-Eintrag sichtbar)');
		ctx.ok((await display(B)) === before, '„+“: Ordner B nicht umgeklappt');
		await page.locator('#create-menu.show .create-note-option[data-type="html"]').click();
		ctx.ok(await waitFor(async () => (await page.locator(`#left_col #folder-${B} .links_arbo_left[data-folder-id="${B}"]`).count()) === count + 1, 12000), '„+“: neue Notiz in B in der Seitenleiste');
		ctx.ok(await waitFor(async () => (await page.locator('#create-menu.show').count()) === 0), '„+“: Menü geschlossen');
		ctx.assertClean('Erstellen', m);
	}

	// 6. Tastatur: Tab erreicht die Knöpfe, Fokus macht sie sichtbar, Enter/Leertaste lösen aus (nicht die Baumnavigation)
	{
		const m = ctx.mark();
		await parkMouse();
		await quick(page, C, 'open-kanban-view').focus();
		ctx.ok(await width(quick(page, C, 'open-kanban-view')) >= 16, 'Tastatur: fokussierter Kanban-Knopf sichtbar');
		await page.keyboard.press('Tab');
		ctx.ok(await page.evaluate((c) => document.activeElement?.matches(`button.nn-folder-quick[data-action="create-note-in-folder"][data-folder-id="${c}"]`), C), 'Tastatur: Tab → „+“ derselben Zeile');
		ctx.ok(await width(quick(page, C, 'create-note-in-folder')) >= 16, 'Tastatur: fokussierter „+“-Knopf sichtbar');
		await page.keyboard.press(' ');
		ctx.ok(await waitFor(async () => (await page.locator('#create-menu.show').count()) === 1), 'Tastatur: Leertaste auf „+“ öffnet Erstellen-Menü');
		await page.keyboard.press('Escape');
		await page.mouse.click(1390, 890);
		await waitFor(async () => (await page.locator('#create-menu.show').count()) === 0);
		await quick(page, B, 'open-kanban-view').focus();
		await page.keyboard.press('Enter');
		ctx.ok(await waitFor(() => kanbanOpen(B)), 'Tastatur: Enter auf Kanban öffnet Ansicht von B');
		ctx.ok(!(await page.locator('#left_col .tree-inline-input').count()), 'Tastatur: Enter startet kein Umbenennen');
		ctx.assertClean('Tastatur', m);
	}

	// 7. Nach In-place-Neuaufbau von #left_col (Fragment) funktionieren die Knöpfe weiter
	{
		const m = ctx.mark();
		const name = `Neu ${RUN}`;
		const frag = page.waitForResponse((r) => r.request().headers()['x-poznote-fragment'] === 'left_col', { timeout: 10000 });
		await page.evaluate(() => window.newFolder());
		const input = page.locator('#left_col .tree-inline-input');
		await input.waitFor({ timeout: 5000 });
		await input.fill(name);
		await input.press('Enter');
		await frag;
		const hdr = page.locator(`#left_col .folder-header[data-folder="${name}"]`);
		ctx.ok(await waitFor(async () => (await hdr.count()) === 1), 'Neuaufbau: neuer Ordner links');
		const fid = await hdr.getAttribute('data-folder-id');
		ctx.ok((await quick(page, fid, 'open-kanban-view').count()) === 1 && (await quick(page, fid, 'create-note-in-folder').count()) === 1, 'Neuaufbau: neuer Ordner hat beide Knöpfe');
		await row(page, fid).hover();
		await quick(page, fid, 'open-kanban-view').click();
		ctx.ok(await waitFor(() => kanbanOpen(fid)), 'Neuaufbau: Kanban-Knopf des neuen Ordners öffnet Ansicht');
		await row(page, A).hover();
		await quick(page, A, 'create-note-in-folder').click();
		ctx.ok(await waitFor(async () => (await page.locator('#create-menu.show').count()) === 1), 'Neuaufbau: „+“ eines alten Ordners öffnet Menü');
		await page.keyboard.press('Escape');
		await page.mouse.click(1390, 890);
		ctx.assertClean('Neuaufbau', m);
	}

	// 8. Touch (hasTouch → hover: none): Knöpfe ohne Hover sichtbar
	{
		const tctx = await browser.newContext({ viewport: { width: 1400, height: 900 }, locale: 'de-DE', hasTouch: true });
		await ctx.attach(tctx);
		const pt = await tctx.newPage();
		await ctx.login(pt);
		const m = ctx.mark();
		await pt.goto(ctx.appUrl(`index.php?workspace=${encodeURIComponent(WS)}`), { waitUntil: 'networkidle' });
		ctx.ok(await pt.evaluate(() => matchMedia('(hover: none)').matches), 'Touch: (hover: none) aktiv');
		ctx.ok(await width(quick(pt, B, 'open-kanban-view')) >= 16 && await width(quick(pt, B, 'create-note-in-folder')) >= 16, 'Touch: Knöpfe ohne Hover sichtbar');
		await quick(pt, C, 'open-kanban-view').tap();
		ctx.ok(await (async () => { for (let i = 0; i < 40; i++) { if (await pt.locator(`#kanban-view-container[data-folder-id="${C}"]`).count()) return true; await pt.waitForTimeout(200); } return false; })(), 'Touch: Tippen auf Kanban öffnet Ansicht');
		ctx.assertClean('Touch', m);
		await tctx.close();
	}

	// 9. Lesefreigabe (bob): Kanban ja, „+“ nein
	{
		const roWs = `Schnell-RO ${RUN}`;
		await api.workspace(roWs);
		const R = await api.folder(roWs, `R ${RUN}`);
		await api.note(roWs, `RO ${RUN}`, R, '<p>r</p>');
		shareRead(ctx.base, process.env.NN_USER, roWs, process.env.NN_USER2);
		const bctx = await browser.newContext({ viewport: { width: 1400, height: 900 }, locale: 'de-DE' });
		await ctx.attach(bctx);
		const pb = await bctx.newPage();
		await ctx.login(pb, process.env.NN_USER2, process.env.NN_PASS2);
		const m = ctx.mark();
		await pb.goto(ctx.appUrl(`index.php?workspace=${encodeURIComponent(roWs)}`), { waitUntil: 'networkidle' });
		ctx.ok((await row(pb, R).count()) === 1, 'Lesefreigabe: Ordner R sichtbar');
		ctx.ok((await quick(pb, R, 'open-kanban-view').count()) === 1, 'Lesefreigabe: Kanban-Knopf vorhanden');
		ctx.ok((await quick(pb, R, 'create-note-in-folder').count()) === 0, 'Lesefreigabe: kein „+“-Knopf');
		await row(pb, R).hover();
		await quick(pb, R, 'open-kanban-view').click();
		ctx.ok(await (async () => { for (let i = 0; i < 40; i++) { if (await pb.locator(`#kanban-view-container[data-folder-id="${R}"]`).count()) return true; await pb.waitForTimeout(200); } return false; })(), 'Lesefreigabe: Kanban öffnet');
		ctx.assertClean('Lesefreigabe', m);
		await bctx.close();
	}
});
