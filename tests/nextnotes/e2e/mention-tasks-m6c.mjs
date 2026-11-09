// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// M6c (Plan M6 §2.2, Done M6c, P33): „@“-Vorschläge in Tasklist-Feldern im Team-Workspace: neue Aufgabe (Enter wählt, legt nichts an;
// zweites Enter legt an; B erhält Benachrichtigung), Pfeil/Tab/Escape, Bearbeiten-Dialog (Escape schließt nur das Menü, Klick wählt),
// Unteraufgabe neu/bearbeiten, eingebettete Tasklist; privater Workspace ohne Menü. 0 CSP/Konsole.
import { runSpec, KEY_LINE_END } from './lib/harness.mjs';
import { apiClient } from './lib/api.mjs';
import { createTeam, ensureUser, occ } from './lib/docker.mjs';

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
	await apiClient(ctx, ctxB.request, B, process.env.NN_PASS2).defaultWorkspace();
	await apiClient(ctx, ctxC.request, C, process.env.NN_PASS2).defaultWorkspace();
	const notifs = async (req, user, noteId) => {
		const r = await req.get(`${ctx.base}/ocs/v2.php/apps/notifications/api/v2/notifications?format=json`, {
			headers: { Authorization: `Basic ${Buffer.from(`${user}:${process.env.NN_PASS2}`).toString('base64')}`, 'OCS-APIRequest': 'true' },
		});
		const j = await r.json().catch(() => ({}));
		return (j?.ocs?.data ?? []).filter((n) => n.app === 'nextnotes' && n.object_id === String(noteId));
	};
	const content = async (id) => (await api.must('GET', `/notes/${id}`)).note?.content ?? '';
	const waitContent = async (id, pred) => {
		for (let i = 0; i < 20; i++) {
			const c = await content(id);
			if (pred(c)) return c;
			await page.waitForTimeout(500);
		}
		return await content(id);
	};
	const allTexts = (c) => {
		const out = [];
		const walk = (v) => { if (Array.isArray(v)) v.forEach(walk); else if (v && typeof v === 'object') { if (typeof v.text === 'string') out.push(v.text); Object.values(v).forEach(walk); } };
		try { walk(JSON.parse(c)); } catch { /* kein JSON */ }
		return out;
	};
	const menu = page.locator('.mention-autocomplete-menu');
	const menuOpen = () => menu.waitFor({ state: 'visible', timeout: 5000 }).then(() => true, () => false);
	const menuItems = async () => (await menu.locator('.emoji-autocomplete-name').allInnerTexts().catch(() => [])).map((s) => s.trim());

	// Team mit B und C, Team-Workspace, Tasklist mit einer Aufgabe
	const teamId = createTeam(ctx.base, A, `Klasse ${RUN}`, { [B]: 1, [C]: 1 });
	await api.must('POST', '/workspaces', { name: `Team ${RUN}`, team: teamId });
	const TW = ((await api.must('GET', '/workspaces')).workspaces ?? []).map((w) => w.name).find((n) => n.startsWith(`Team ${RUN}`));
	ctx.ok(!!TW, 'Team-Workspace angelegt', TW);
	const tl = (await api.must('POST', '/notes', { heading: `Liste ${RUN}`, type: 'tasklist', workspace: TW,
		content: JSON.stringify([{ id: 1, text: `Erste ${RUN}`, completed: false }]) })).note.id;

	// ---------- 1. Neue Aufgabe: Enter wählt, legt nichts an ----------
	const m1 = ctx.mark();
	await page.goto(ctx.appUrl(`index.php?workspace=${encodeURIComponent(TW)}&note=${tl}`), { waitUntil: 'networkidle' });
	await ctx.selfTest(page);
	const input = page.locator(`#task-input-${tl}`);
	await input.waitFor({ state: 'visible', timeout: 8000 });
	const items = page.locator(`#tasks-list-${tl} > .task-item`);
	const before = await items.count();
	await input.click();
	await page.keyboard.type(`Bitte @${B.slice(0, 2)}`);
	ctx.ok(await menuOpen(), 'neue Aufgabe: „@“ öffnet das Menü');
	ctx.ok((await menuItems()).includes(B), 'Menü nennt B', JSON.stringify(await menuItems()));
	const box = await input.boundingBox();
	const mbox = await menu.boundingBox();
	ctx.ok(!!box && !!mbox && mbox.y >= box.y + box.height - 1, 'Menü unter dem Feld', JSON.stringify({ box, mbox }));
	await ctx.screenshot(page, 'task-input-menu');
	await page.keyboard.press('Enter');
	ctx.ok(await menu.count() === 0, 'Enter: Menü zu');
	ctx.ok(await input.inputValue() === `Bitte @${B} `, 'Enter schreibt „@uid “', await input.inputValue());
	await page.waitForTimeout(300);
	ctx.ok(await items.count() === before, 'Enter bei offenem Menü legt keine Aufgabe an', `${before} → ${await items.count()}`);
	await page.keyboard.type('prüfen');
	await page.keyboard.press('Enter');
	ctx.ok(await items.count() === before + 1, 'zweites Enter legt die Aufgabe an');
	ctx.ok(await input.inputValue() === '', 'Feld danach leer');
	const c1 = await waitContent(tl, (c) => allTexts(c).includes(`Bitte @${B} prüfen`));
	ctx.ok(allTexts(c1).includes(`Bitte @${B} prüfen`), 'gespeichert mit „@uid“');
	let n = [];
	for (let i = 0; i < 10 && n.length === 0; i++) {
		n = await notifs(ctxB.request, B, tl);
		if (n.length === 0) await page.waitForTimeout(500);
	}
	ctx.ok(n.length === 1, 'B erhält die Erwähnung aus der Aufgabe', String(n.length));

	// ---------- 2. Pfeil + Tab, Escape ----------
	await input.click();
	await page.keyboard.type('@');
	ctx.ok(await menuOpen(), '„@“ allein: Menü');
	const list = await menuItems();
	ctx.ok(list.length === 2 && list.includes(B) && list.includes(C) && !list.includes(A), 'Menü: B und C, nicht A selbst', JSON.stringify(list));
	await page.keyboard.press('ArrowDown');
	await page.keyboard.press('Tab');
	ctx.ok(await input.inputValue() === `@${list[1]} `, 'Pfeil runter + Tab wählt den zweiten Eintrag', await input.inputValue());
	await page.keyboard.type('@');
	ctx.ok(await menuOpen(), 'erneut „@“: Menü');
	await page.keyboard.press('Escape');
	ctx.ok(await menu.count() === 0, 'Escape schließt das Menü');
	ctx.ok(await input.inputValue() === `@${list[1]} @`, 'Escape lässt den Text stehen', await input.inputValue());
	ctx.ok(await items.count() === before + 1, 'Tab/Escape legen keine Aufgabe an');
	await input.fill('');
	ctx.assertClean('Neue Aufgabe', m1);

	// ---------- 3. Bearbeiten-Dialog: Escape schließt nur das Menü, Klick wählt ----------
	const m2 = ctx.mark();
	await page.locator(`#tasks-list-${tl} .task-text[data-task-id="1"]`).click();
	const modal = page.locator('#taskEditModal');
	const ta = page.locator('#taskEditTextarea');
	ctx.ok(await modal.waitFor({ state: 'visible', timeout: 5000 }).then(() => true, () => false), 'Bearbeiten-Dialog offen');
	await page.waitForTimeout(150);
	await page.keyboard.press('End');
	await page.keyboard.type(` mit @${C.slice(0, 1)}`);
	ctx.ok(await menuOpen(), 'Dialog: „@“ öffnet das Menü');
	await ctx.screenshot(page, 'task-modal-menu');
	await page.keyboard.press('Escape');
	ctx.ok(await menu.count() === 0, 'Dialog: Escape schließt das Menü');
	ctx.ok(await modal.isVisible(), 'Dialog: Escape lässt den Dialog offen');
	await page.keyboard.type(C.slice(1, 2));
	ctx.ok(await menuOpen(), 'Dialog: Weitertippen öffnet das Menü wieder');
	await menu.locator('.emoji-autocomplete-item', { hasText: C }).click();
	ctx.ok(await menu.count() === 0, 'Klick: Menü zu');
	ctx.ok(await ta.inputValue() === `Erste ${RUN} mit @${C} `, 'Klick schreibt „@uid “', await ta.inputValue());
	ctx.ok(await modal.isVisible(), 'Dialog bleibt nach Auswahl offen');
	await page.keyboard.press('Control+Enter');
	await modal.waitFor({ state: 'hidden', timeout: 5000 }).catch(() => {});
	const c2 = await waitContent(tl, (c) => allTexts(c).includes(`Erste ${RUN} mit @${C}`));
	ctx.ok(allTexts(c2).includes(`Erste ${RUN} mit @${C}`), 'Dialog: gespeichert mit „@uid“');
	n = [];
	for (let i = 0; i < 10 && n.length === 0; i++) {
		n = await notifs(ctxC.request, C, tl);
		if (n.length === 0) await page.waitForTimeout(500);
	}
	ctx.ok(n.length === 1, 'C erhält die Erwähnung aus dem Bearbeiten', String(n.length));
	ctx.assertClean('Bearbeiten-Dialog', m2);

	// ---------- 4. Unteraufgabe: neu (Tab), bearbeiten inline (Enter wählt, zweites Enter speichert) ----------
	const m3 = ctx.mark();
	await page.evaluate((id) => window.openSubtaskInput(1, id), tl);
	const sub = page.locator(`#tasks-list-${tl} .task-subitem-input`);
	await sub.waitFor({ state: 'visible', timeout: 5000 });
	await sub.click();
	await page.keyboard.type(`Teil @${B.slice(0, 2)}`);
	ctx.ok(await menuOpen(), 'Unteraufgabe neu: Menü');
	await page.keyboard.press('Tab');
	ctx.ok(await sub.inputValue() === `Teil @${B} `, 'Unteraufgabe neu: Tab schreibt „@uid “', await sub.inputValue());
	ctx.ok(await page.locator(`#tasks-list-${tl} .task-subitem-text`).count() === 0, 'Tab legt keine Unteraufgabe an');
	await page.keyboard.press('Enter');
	const subText = page.locator(`#tasks-list-${tl} .task-subitem-text`).first();
	ctx.ok(await subText.waitFor({ state: 'visible', timeout: 5000 }).then(() => true, () => false), 'Enter legt die Unteraufgabe an');
	await page.keyboard.press('Escape');
	// Unteraufgabe bearbeiten öffnet den Dialog: Enter (ohne Strg) wählt bei offenem Menü
	await subText.click();
	ctx.ok(await modal.waitFor({ state: 'visible', timeout: 5000 }).then(() => true, () => false), 'Unteraufgabe bearbeiten: Dialog offen');
	await page.waitForTimeout(150);
	await page.keyboard.press('End');
	await page.keyboard.type(` und @${C.slice(0, 2)}`);
	ctx.ok(await menuOpen(), 'Unteraufgabe bearbeiten: Menü');
	await page.keyboard.press('Enter');
	ctx.ok(await ta.inputValue() === `Teil @${B} und @${C} `, 'Enter wählt ohne Zeilenumbruch', JSON.stringify(await ta.inputValue()));
	await page.keyboard.press('Control+Enter');
	await modal.waitFor({ state: 'hidden', timeout: 5000 }).catch(() => {});
	const c3 = await waitContent(tl, (c) => allTexts(c).includes(`Teil @${B} und @${C}`));
	ctx.ok(allTexts(c3).includes(`Teil @${B} und @${C}`), 'Unteraufgabe gespeichert mit beiden Erwähnungen');

	// Inline-Bearbeiten (Rückfall ohne Dialog): Enter wählt, zweites Enter speichert
	await page.evaluate((id) => { const m = document.getElementById('taskEditModal'); window.__nnM6cModal = m; m.remove(); window.editTask(1, id); }, tl);
	const inl = page.locator(`#tasks-list-${tl} .task-edit-input`);
	ctx.ok(await inl.waitFor({ state: 'visible', timeout: 5000 }).then(() => true, () => false), 'Inline-Bearbeiten offen');
	await inl.focus();
	await page.keyboard.press(KEY_LINE_END);
	await page.keyboard.type(` @${B.slice(0, 2)}`);
	ctx.ok(await menuOpen(), 'Inline: Menü');
	await page.keyboard.press('Enter');
	ctx.ok(await inl.isVisible() && (await inl.inputValue()).endsWith(`@${C} @${B} `), 'Inline: Enter wählt, Feld bleibt offen', await inl.inputValue().catch(() => '-'));
	await page.keyboard.type('x');
	await page.keyboard.press('Escape');
	ctx.ok(await inl.count() === 0, 'Inline: Escape ohne Menü bricht ab');
	await page.evaluate(() => document.body.appendChild(window.__nnM6cModal));
	ctx.assertClean('Unteraufgaben', m3);

	// ---------- 5. Eingebettete Tasklist ----------
	const host = await api.note(TW, `Host ${RUN}`, null,
		`<p>Liste:</p><div class="tasklist-embed" data-task-embed="${tl}" contenteditable="false"><a class="tasklist-embed-link" href="index.php?note=${tl}">Liste</a></div><p>Ende</p>`);
	const m4 = ctx.mark();
	await page.goto(ctx.appUrl(`index.php?workspace=${encodeURIComponent(TW)}&note=${host}`), { waitUntil: 'networkidle' });
	const embIn = page.locator('.tasklist-embed-add-input').first();
	await embIn.waitFor({ state: 'visible', timeout: 8000 });
	const embRows = page.locator('.tasklist-embed-task');
	const embBefore = await embRows.count();
	await embIn.click();
	await page.keyboard.type(`Eingebettet @${B.slice(0, 2)}`);
	ctx.ok(await menuOpen(), 'eingebettet: Menü');
	await page.keyboard.press('Enter');
	ctx.ok(await embIn.inputValue() === `Eingebettet @${B} `, 'eingebettet: Enter schreibt „@uid “', await embIn.inputValue());
	await page.waitForTimeout(300);
	ctx.ok(await embRows.count() === embBefore, 'eingebettet: keine Aufgabe angelegt');
	await page.keyboard.press('Enter');
	const c4 = await waitContent(tl, (c) => allTexts(c).includes(`Eingebettet @${B}`));
	ctx.ok(allTexts(c4).includes(`Eingebettet @${B}`), 'eingebettet: Aufgabe mit „@uid“ gespeichert');
	ctx.ok(!(await content(host)).includes(`@${B}`), 'Host-Notiz enthält die Erwähnung nicht');
	ctx.assertClean('Eingebettete Tasklist', m4);

	// ---------- 6. Privater Workspace: kein Menü ----------
	const priv = await api.defaultWorkspace();
	const ptl = (await api.must('POST', '/notes', { heading: `Privat ${RUN}`, type: 'tasklist', workspace: priv, content: '[]' })).note.id;
	const m5 = ctx.mark();
	await page.goto(ctx.appUrl(`index.php?workspace=${encodeURIComponent(priv)}&note=${ptl}`), { waitUntil: 'networkidle' });
	const pin = page.locator(`#task-input-${ptl}`);
	await pin.waitFor({ state: 'visible', timeout: 8000 });
	await pin.click();
	await page.keyboard.type(`@${B.slice(0, 2)}`);
	await page.waitForTimeout(400);
	ctx.ok(await menu.count() === 0, 'privat ohne Freigabe: kein Menü');
	await page.keyboard.press('Enter');
	ctx.ok(await page.locator(`#tasks-list-${ptl} > .task-item`).count() === 1, 'privat: Enter legt die Aufgabe an');
	ctx.assertClean('Privat', m5);
	await ctxB.close();
	await ctxC.close();
});
