// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// M3-fix4 (2): Detektor – klickt alle sichtbaren Menüpunkte durch (Rechtsklick-/Slash-Menü in Rich-Text- und Markdown-Notiz,
// Notiz-Werkzeugleiste, Titelmenü, Seitenleisten-Menüs) und bestätigt sich öffnende Dialoge. Jede Antwort 501 (not_implemented) ist FAIL.
// Ergänzt stripped.mjs (Gestrichenes unsichtbar) um „Sichtbares funktioniert ohne 501“.
import { runSpec, KEY_DOC_END } from './lib/harness.mjs';
import { apiClient } from './lib/api.mjs';

const RUN = `${process.argv[3]}${Date.now().toString(36)}`;
// Bestätigen würde Testdaten löschen/verschieben oder die Seite verlassen; Menüpunkt wird geklickt, Dialog nur abgebrochen
const NO_CONFIRM = /delete|remove|trash|empty|archive|move|logout|duplicate/i;
// Löschen ohne Rückfrage (Werkzeugleiste) entfernt die Testnotiz; Papierkorb-API existiert, kein 501 möglich
const SKIP = /delete-note|delete-folder|empty-folder/;
// Aktionen, die je Notiztyp einen anderen Server-Weg nehmen
const TYPE_DEP = new Set(['paste-markdown', 'template']);

await runSpec(import.meta.url, async (ctx, page) => {
	const api = apiClient(ctx, page.request);
	const WS = await api.defaultWorkspace();
	const fid = await api.folder(WS, `Detektor ${RUN}`);
	const html = await api.note(WS, `Detektor RT ${RUN}`, fid, '<p>Hallo Welt</p><p>Zweite Zeile</p>');
	const md = (await api.must('POST', '/notes', { heading: `Detektor MD ${RUN}`, content: '# Titel\n\nHallo Welt\n', type: 'markdown', workspace: WS, folder_id: fid })).note.id;
	const hits = [];
	let current = '';
	page.on('response', (r) => {
		if (r.status() === 501) hits.push(`${current} → 501 ${r.request().method()} ${new URL(r.url()).pathname.replace(/.*\/apps\/nextnotes/, '')}`);
	});
	// beforeunload annehmen, sonst bricht die nächste Navigation ab
	page.on('dialog', (d) => (d.type() === 'beforeunload' ? d.accept() : d.dismiss()).catch(() => {}));
	const open = async (id) => {
		await page.goto(ctx.appUrl(`index.php?workspace=${encodeURIComponent(WS)}&note=${id}`), { waitUntil: 'load' });
		await page.locator(`#entry${id}`).waitFor({ state: 'attached', timeout: 10000 });
		await page.waitForTimeout(400);
	};
	// kurze Wartezeiten: nicht erreichbare Einträge kosten sonst je 30 s
	page.setDefaultTimeout(5000);
	// Nach dem Klick: sichtbaren Dialog füllen und bestätigen (außer NO_CONFIRM)
	const settle = async (label) => {
		await page.waitForTimeout(500);
		const confirm = !NO_CONFIRM.test(label);
		const dlg = page.locator('.modal:visible, .alert-modal-overlay:visible').last();
		if (await dlg.count()) {
			for (const ta of await dlg.locator('textarea:visible').all()) await ta.fill('# Kopf\n\n**fett** Text').catch(() => {});
			for (const inp of await dlg.locator('input[type=text]:visible').all()) if (!(await inp.inputValue().catch(() => 'x'))) await inp.fill('Test').catch(() => {});
			const btn = dlg.locator(confirm ? '.btn-primary:visible, .alert-modal-btn-primary:visible, button.primary:visible' : '.btn-cancel:visible').first();
			if (await btn.count()) { await btn.click({ timeout: 2000 }).catch(() => {}); await page.waitForTimeout(1200); }
		}
	};
	const fire = async (label, go) => {
		if (SKIP.test(label)) { ctx.info(`${label}: übersprungen (zerstörend)`); return; }
		current = label;
		const before = hits.length;
		try { await go(); await settle(label); } catch (e) { ctx.info(`${label}: nicht ausführbar (${String(e.message).split('\n')[0].slice(0, 100)})`); }
		ctx.ok(hits.length === before, `${label}: kein 501`, hits.slice(before).join(' | '));
	};

	// Bearbeitbare Fläche: Rich-Text #entry, Markdown erst in den Bearbeiten-Modus (Standard Vorschau)
	const editor = async (id) => {
		if (id === md) {
			await page.evaluate((n) => window.switchToEditMode && window.switchToEditMode(String(n)), id);
			await page.waitForTimeout(400);
			return page.locator(`#entry${id}`).locator('xpath=ancestor::*[contains(concat(" ",@class," ")," notecard ")][1]').locator('[contenteditable="true"]').first();
		}
		return page.locator(`#entry${id}`);
	};
	// Rechtsklick-Menü: alle Pfade bis zum Blatt
	const ctxMenu = async (id) => {
		const box = await (await editor(id)).boundingBox();
		await page.mouse.click(box.x + 40, box.y + 12, { button: 'right' });
		await page.locator('.slash-command-menu').first().waitFor({ state: 'visible', timeout: 3000 });
	};
	const slashMenu = async (id) => {
		await (await editor(id)).click();
		await page.keyboard.press(KEY_DOC_END);
		await page.keyboard.press('Enter');
		await page.keyboard.type('/');
		await page.locator('.slash-command-menu').first().waitFor({ state: 'visible', timeout: 3000 });
	};
	const paths = async (opener) => {
		const out = [];
		const tops = await page.$$eval('.slash-command-menu:not(.slash-command-submenu) .slash-command-item[data-command-id]', (els) => els.map((e) => [e.getAttribute('data-command-id'), e.getAttribute('data-has-submenu') === 'true']));
		for (const [top, sub] of tops) {
			if (!sub) { out.push([top]); continue; }
			await page.locator(`.slash-command-menu [data-command-id="${top}"]`).hover();
			await page.waitForTimeout(250);
			const subs = await page.$$eval('.slash-command-submenu .slash-command-item[data-submenu-id]', (els) => els.map((e) => [e.getAttribute('data-submenu-id'), e.getAttribute('data-has-sub-submenu') === 'true']));
			// Große Gruppen gleicher Art (Farben, Sprachen, Datumsformen) teilen eine Aktion: erster Eintrag
			const sample = subs.length > 6 ? [subs[0], ...subs.filter(([, d]) => d)] : subs;
			for (const [s, deeper] of [...new Map(sample.map((x) => [x[0], x])).values()]) {
				if (!deeper) { out.push([top, s]); continue; }
				await page.locator(`.slash-command-submenu [data-submenu-id="${s}"]`).first().hover();
				await page.waitForTimeout(250);
				const leaves = await page.$$eval('.slash-command-submenu .slash-command-item[data-sub-submenu-id]', (els) => els.map((e) => e.getAttribute('data-sub-submenu-id')));
				for (const l of leaves.length > 6 ? [leaves[0]] : leaves) out.push([top, s, l]);
				await page.locator(`.slash-command-menu [data-command-id="${top}"]`).hover();
				await page.waitForTimeout(150);
			}
		}
		return out;
	};
	const clickPath = async (p) => {
		if (p.length === 1) return page.locator(`.slash-command-menu [data-command-id="${p[0]}"]`).click();
		await page.locator(`.slash-command-menu [data-command-id="${p[0]}"]`).hover();
		await page.waitForTimeout(200);
		if (p.length === 2) return page.locator(`.slash-command-submenu [data-submenu-id="${p[1]}"]`).first().click();
		await page.locator(`.slash-command-submenu [data-submenu-id="${p[1]}"]`).first().hover();
		await page.waitForTimeout(200);
		return page.locator(`.slash-command-submenu [data-sub-submenu-id="${p[2]}"]`).first().click();
	};
	const seen = new Set();
	for (const [kind, id] of [['RT', html], ['MD', md]]) {
		for (const [mname, opener] of [['Rechtsklick', ctxMenu], ['Slash', slashMenu]]) {
			await open(id);
			let list = [];
			try { await opener(id); list = await paths(opener); } catch (e) { ctx.info(`${kind} ${mname}: Menü nicht geöffnet (${String(e.message).split('\n')[0]})`); continue; }
			ctx.info(`${kind} ${mname}: ${list.length} Einträge`);
			for (const p of list) {
				// Blatt-ID je Notiztyp einmal (gleiche Aktion in Rechtsklick- und Slash-Menü)
				const leaf = p[p.length - 1];
				const key = TYPE_DEP.has(leaf) ? `${kind}:${leaf}` : leaf;
				if (seen.has(key)) continue;
				seen.add(key);
				await fire(`${kind} ${mname} ${p.join(' › ')}`, async () => { await open(id); await opener(id); await clickPath(p); });
			}
		}
	}

	// Notiz-Werkzeugleiste und Titelmenü
	for (const [kind, id] of [['RT', html], ['MD', md]]) {
		await open(id);
		const acts = await page.$$eval(`.note-edit-toolbar [data-action]`, (els) => [...new Set(els.filter((e) => e.getClientRects().length).map((e) => e.getAttribute('data-action')))]);
		for (const a of acts.filter((x) => !seen.has(`tb:${x}`) && seen.add(`tb:${x}`))) await fire(`${kind} Werkzeugleiste ${a}`, async () => { await open(id); await page.locator(`.note-edit-toolbar [data-action="${a}"]:visible`).first().click({ timeout: 3000 }); });
		await open(id);
		await page.locator('.innernote .note-title-heading .css-title').first().click({ button: 'right' }).catch(() => {});
		const n = await page.locator('#noteTitleMenu [role="menuitem"]:visible, #noteTitleMenu button:visible').count();
		// Farbfelder und Größen teilen je eine Aktion: Stichprobe in der Rich-Text-Notiz
		for (const i of (kind === 'RT' ? [...new Set([0, n - 1])] : []).filter((x) => x >= 0 && x < n)) {
			await fire(`${kind} Titelmenü #${i}`, async () => {
				await open(id);
				await page.locator('.innernote .note-title-heading .css-title').first().click({ button: 'right' });
				await page.locator('#noteTitleMenu [role="menuitem"]:visible, #noteTitleMenu button:visible').nth(i).click();
			});
		}
	}

	// Seitenleiste: alle sichtbaren Einträge der Menüs
	const menus = [
		['Notiz-Aktionen', `.note-actions-toggle[data-note-id="${html}"]`, '#note-actions-menu'],
		['Ordner-Aktionen', `.folder-actions-toggle[data-folder-id="${fid}"]`, '#folder-actions-menu'],
		['Workspace-Menü', '.sidebar-title', '#workspaceMenu'],
		['Baumoptionen', '#sidebarTreeOptionsBtn', '#tree-options-menu'],
		['Erstellen', '#sidebarCreateBtn', '#create-menu'],
	];
	const openMenu = async (trigger) => {
		const t = page.locator(trigger).first();
		const row = t.locator('xpath=ancestor::*[contains(concat(" ",@class," ")," folder-toggle ") or contains(concat(" ",@class," ")," note-list-item ")][1]');
		if (await row.count()) await row.hover().catch(() => {});
		await t.click({ force: true, timeout: 3000 });
		await page.waitForTimeout(400);
	};
	for (const [label, trigger, menuSel] of menus) {
		await open(html);
		if (!(await openMenu(trigger).then(() => true, () => false))) { ctx.info(`${label}: Auslöser ${trigger} nicht erreichbar`); continue; }
		const items = await page.$$eval(`${menuSel} [data-action], ${menuSel} .create-note-option`, (els) => els.filter((e) => e.getClientRects().length).map((e) => e.getAttribute('data-action') ? `[data-action="${e.getAttribute('data-action')}"]` : `.create-note-option[data-type="${e.getAttribute('data-type')}"]`));
		ctx.info(`${label}: ${items.length} Einträge`);
		for (const sel of [...new Set(items)]) {
			await fire(`${label} ${sel}`, async () => { await open(html); await openMenu(trigger); await page.locator(`${menuSel} ${sel}`).first().click(); });
		}
	}
	ctx.info(`501 gesamt: ${hits.length}`);
	for (const h of hits) ctx.info(`  ${h}`);
});
