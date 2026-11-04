// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// M4b: Benutzerwege der Umwandlung – Markdown/Rich Text einfügen, Notiz umwandeln (beide Richtungen), Vorlage anderen Typs,
// Datei-Drop in die Seitenleiste, Revisions-Vorschau (render=1). Kein 501, keine CSP-Verletzung.
import { runSpec } from './lib/harness.mjs';
import { apiClient } from './lib/api.mjs';

const RUN = Date.now().toString(36);

await runSpec(import.meta.url, async (ctx, page) => {
	const api = apiClient(ctx, page.request);
	const WS = await api.defaultWorkspace();
	const fid = await api.folder(WS, `Umwandeln ${RUN}`);
	// Vorlagen-Ordner „Templates“ je Lauf als Unterordner (Name muss genau so heißen)
	const tpl = (await api.must('POST', '/folders', { folder_name: 'Templates', parent_folder_id: fid, workspace: WS })).folder_id;
	const rt = await api.note(WS, `RT ${RUN}`, fid, '<p>Start</p>');
	const mdNote = async (heading, content) => (await api.must('POST', '/notes', { heading, content, type: 'markdown', workspace: WS, folder_id: fid })).note.id;
	const md = await mdNote(`MD ${RUN}`, '# Titel\n\nStart\n');
	await api.must('POST', '/notes', { heading: `Vorlage MD ${RUN}`, content: '## Aus Vorlage\n\n**vorlagefett**\n', type: 'markdown', workspace: WS, folder_id: tpl });
	await api.note(WS, `Vorlage RT ${RUN}`, tpl, '<h2>RT Vorlage</h2><p><strong>rtfett</strong></p>');
	const hits = [];
	page.on('response', (r) => { if (r.status() === 501) hits.push(`${r.request().method()} ${new URL(r.url()).pathname}`); });
	page.on('dialog', (d) => (d.type() === 'beforeunload' ? d.accept() : d.dismiss()).catch(() => {}));
	const content = async (id) => String((await api.must('GET', `/notes/${id}`)).note.content);
	const open = async (id) => {
		await page.goto(ctx.appUrl(`index.php?workspace=${encodeURIComponent(WS)}&note=${id}`), { waitUntil: 'load' });
		await page.locator(`#entry${id}`).waitFor({ state: 'attached', timeout: 10000 });
		await page.waitForTimeout(400);
	};
	const editable = async (id, isMd) => {
		if (isMd) {
			await page.evaluate((n) => window.switchToEditMode && window.switchToEditMode(String(n)), id);
			await page.waitForTimeout(400);
			return page.locator(`#entry${id}`).locator('xpath=ancestor::*[contains(concat(" ",@class," ")," notecard ")][1]').locator('[contenteditable="true"]').first();
		}
		return page.locator(`#entry${id}`);
	};
	const insertMenu = async (id, isMd, leaf) => {
		const box = await (await editable(id, isMd)).boundingBox();
		await page.mouse.click(box.x + 40, box.y + 10, { button: 'right' });
		await page.locator('.slash-command-menu [data-command-id="insert"]').hover();
		await page.locator(`.slash-command-submenu [data-submenu-id="${leaf}"]`).first().click();
	};
	const waitSaved = async (id, re, what) => {
		let c = '';
		for (let i = 0; i < 20 && !re.test(c); i++) { await page.waitForTimeout(500); c = await content(id); }
		ctx.ok(re.test(c), what, c.slice(0, 200));
	};
	const m0 = ctx.mark();

	// 1. Rich-Text-Notiz: „Markdown einfügen“ → convert-markdown, Vorschau, Einfügen
	await open(rt);
	await insertMenu(rt, false, 'paste-markdown');
	const ins = page.locator('#insertMarkdownModal');
	await ins.waitFor({ state: 'visible', timeout: 5000 });
	const conv = page.waitForResponse((r) => r.url().includes('/convert-markdown'), { timeout: 10000 });
	await page.locator('#insertMarkdownSource').fill('## Eingefügt\n\n**mdfett** und [Link](https://example.org)');
	ctx.ok((await conv).status() === 200, 'convert-markdown 200');
	await page.locator('#insertMarkdownPreview strong').first().waitFor({ timeout: 5000 });
	ctx.ok((await page.locator('#insertMarkdownPreview strong').first().textContent()) === 'mdfett', 'Vorschau zeigt Rich Text');
	await page.locator('#insertMarkdownInsertBtn').click();
	await waitSaved(rt, /<strong>mdfett<\/strong>/, 'Markdown als Rich Text in der Notiz gespeichert');

	// 2. Markdown-Notiz: „Rich Text einfügen“ → convert-html
	await open(md);
	await insertMenu(md, true, 'paste-markdown');
	await page.locator('#pasteMarkdownModal').waitFor({ state: 'visible', timeout: 5000 });
	const conv2 = page.waitForResponse((r) => r.url().includes('/convert-html'), { timeout: 10000 });
	await page.evaluate(() => {
		const dt = new DataTransfer();
		dt.setData('text/html', '<h3>Kopiert</h3><p><b>htmlfett</b> <i>kursiv</i></p>');
		dt.setData('text/plain', 'Kopiert');
		document.getElementById('pasteMarkdownDropzone').dispatchEvent(new ClipboardEvent('paste', { clipboardData: dt, bubbles: true, cancelable: true }));
	});
	ctx.ok((await conv2).status() === 200, 'convert-html 200');
	await page.waitForFunction(() => /\*\*htmlfett\*\*/.test(document.getElementById('pasteMarkdownPreview').value), null, { timeout: 5000 }).catch(() => {});
	const pv = await page.locator('#pasteMarkdownPreview').inputValue();
	ctx.ok(/### Kopiert/.test(pv) && /\*\*htmlfett\*\*/.test(pv), 'Vorschau zeigt Markdown', pv);
	await page.locator('#pasteMarkdownInsertBtn').click();
	await waitSaved(md, /\*\*htmlfett\*\*/, 'Rich Text als Markdown in der Notiz gespeichert');

	// 3. Vorlage anderen Typs: Markdown-Vorlage in Rich-Text-Notiz, Rich-Text-Vorlage in Markdown-Notiz
	for (const [id, isMd, name, re] of [[rt, false, `Vorlage MD ${RUN}`, /<strong>vorlagefett<\/strong>/], [md, true, `Vorlage RT ${RUN}`, /\*\*rtfett\*\*/]]) {
		await open(id);
		const box = await (await editable(id, isMd)).boundingBox();
		await page.mouse.click(box.x + 40, box.y + 10, { button: 'right' });
		await page.locator('.slash-command-menu [data-command-id="template"]').click();
		const item = page.locator('#templatePickerModal .template-picker-item', { hasText: name }).first();
		await item.waitFor({ state: 'visible', timeout: 5000 });
		const c = page.waitForResponse((r) => /\/convert-(html|markdown)$/.test(new URL(r.url()).pathname), { timeout: 10000 }).catch(() => null);
		await item.click();
		const resp = await c;
		ctx.ok(resp?.status() === 200, `Vorlage in ${isMd ? 'Markdown' : 'Rich Text'}: Umwandlung 200`, resp ? String(resp.status()) : 'keine Anfrage');
		await page.waitForTimeout(300);
		if (isMd) await page.locator('.btn-save:visible').first().click().catch(() => {});
		await waitSaved(id, re, `Vorlage ${name} umgewandelt eingefügt`);
	}

	// 4. Notiz umwandeln über das Notizmenü: Rich Text → Markdown → Rich Text
	// Base64-Bild wird beim Speichern zum Anhang und bleibt nach der Umwandlung als Markdown-Bild erhalten
	const png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
	const conv3 = await api.note(WS, `Wandel ${RUN}`, fid, '<p>x</p>');
	await api.must('PATCH', `/notes/${conv3}`, { content: `<h2>Wandel</h2><p><strong>stark</strong> <a href="https://example.org">l</a> <img src="data:image/png;base64,${png}" alt="Punkt"></p><ul><li>eins</li></ul>` });
	const atts = (await api.must('GET', `/notes/${conv3}/attachments`)).attachments ?? [];
	ctx.ok(atts.length === 1, 'Base64-Bild als Anhang gespeichert', JSON.stringify(atts).slice(0, 160));
	for (const [target, re] of [['markdown', /## Wandel[\s\S]*\*\*stark\*\*/], ['html', /<h2>Wandel<\/h2>[\s\S]*<strong>stark<\/strong>/]]) {
		await open(conv3);
		// Upstream blendet .btn-convert am Desktop aus (utilities.css); Weg über das ⋮-Menü
		ctx.ok(await page.locator(`.note-edit-toolbar .btn-convert[data-convert-to="${target}"]`).count() === 1, `Umwandeln-Knopf → ${target} vorhanden`);
		await page.locator('.note-edit-toolbar .mobile-more-btn').first().click();
		await page.locator('.mobile-toolbar-menu [data-selector=".btn-convert"]').first().click();
		await page.locator('#convertNoteModal').waitFor({ state: 'visible', timeout: 5000 });
		const r = page.waitForResponse((x) => /\/notes\/\d+\/convert$/.test(new URL(x.url()).pathname), { timeout: 10000 });
		// nach Erfolg lädt utils-note-rename.js die Seite neu: auf das Laden warten, sonst kollidiert die nächste Navigation
		const reloaded = page.waitForEvent('load', { timeout: 15000 }).catch(() => null);
		await page.locator('#confirmConvertBtn').click();
		ctx.ok((await r).status() === 200, `Umwandeln → ${target}: 200`);
		await reloaded;
		await page.waitForTimeout(500);
		const n = (await api.must('GET', `/notes/${conv3}`)).note;
		ctx.ok(n.type === (target === 'html' ? 'note' : 'markdown') && re.test(String(n.content)), `Notiz ist ${target}`, String(n.content).slice(0, 160));
		if (target === 'markdown') ctx.ok(/!\[Punkt\]\([^)]*\/attachments\/\d+\)/.test(String(n.content)), 'Markdown verweist auf den Anhang', String(n.content));
	}

	ctx.ok(/<img[^>]*\/attachments\/\d+/.test(String((await api.must('GET', `/notes/${conv3}`)).note.content)), 'Bild nach Hin- und Rückumwandlung');
	await open(conv3);
	ctx.ok(await page.locator(`#entry${conv3} img[src*="/attachments/"]`).count() === 1, 'Bild in der Notiz sichtbar');

	// Kanban-Karte zeigt Links (#1672)
	const kf = await api.folder(WS, `Board ${RUN}`);
	await api.note(WS, `Karte ${RUN}`, kf, `<p>Siehe <a href="https://example.org/k">extern</a> und <a href="index.php?note=${conv3}" class="note-internal-link">intern</a></p>`);
	await page.goto(ctx.appUrl(`index.php?workspace=${encodeURIComponent(WS)}&note=${rt}`), { waitUntil: 'networkidle' });
	await page.evaluate((f) => window.openKanbanView && window.openKanbanView(f), kf);
	const card = page.locator('.kanban-card-snippet').first();
	await card.waitFor({ state: 'visible', timeout: 8000 }).catch(() => {});
	ctx.ok(await card.locator('a[href="https://example.org/k"][target="_blank"]').count() === 1, 'Kanban-Karte: externer Link');
	ctx.ok(await card.locator(`a.note-internal-link[data-note-id="${conv3}"]`).count() === 1, 'Kanban-Karte: interner Link');

	// 5. Revisions-Vorschau einer Markdown-Notiz (render=1)
	await api.must('POST', `/notes/${md}/snapshot`, { manual: true });
	await page.goto(ctx.appUrl(`revisions.php?note_id=${md}&workspace=${encodeURIComponent(WS)}`), { waitUntil: 'networkidle' });
	await page.locator('#revisionsTabContent').click().catch(() => {});
	await page.locator('[data-md-mode="preview"]').click().catch(() => {});
	await page.locator('#revisionsBody .markdown-preview strong').first().waitFor({ timeout: 5000 }).catch(() => {});
	ctx.ok(await page.locator('#revisionsBody .markdown-preview strong').count() > 0, 'Revisions-Vorschau gerendert');

	// 6. Dateien auf einen Ordner der Seitenleiste ziehen → Import in diesen Ordner
	const dropFolder = await api.folder(WS, `Import ${RUN}`);
	await open(rt);
	const r6 = page.waitForResponse((r) => r.url().includes('api_import_dropped_notes.php'), { timeout: 15000 });
	await page.evaluate(([f, run]) => {
		const header = document.querySelector(`.folder-header[data-folder-id="${f}"]`);
		const dt = new DataTransfer();
		dt.items.add(new File([`---\ntitle: Import MD ${run}\n---\n**importfett**`], 'a.md', { type: 'text/markdown' }));
		dt.items.add(new File([`<p>Import HTML ${run}</p>`], `Import HTML ${run}.html`, { type: 'text/html' }));
		header.dispatchEvent(new DragEvent('drop', { dataTransfer: dt, bubbles: true, cancelable: true }));
	}, [dropFolder, RUN]);
	const resp6 = await r6;
	ctx.ok(resp6.status() === 200, 'Drop-Import 200', await resp6.text());
	const listed = await api.must('GET', `/notes?workspace=${encodeURIComponent(WS)}&folder_id=${dropFolder}`).catch(() => null);
	const names = (listed?.notes ?? []).map((n) => n.heading);
	ctx.info(`importiert: ${names.join(', ')}`);
	await page.waitForTimeout(1200);
	ctx.ok(await page.locator(`#left_col .note-list-item`, { hasText: `Import MD ${RUN}` }).count() > 0, 'importierte Notiz in der Seitenleiste');
	await ctx.screenshot(page, 'convert-import');

	ctx.ok(hits.length === 0, 'kein 501', hits.join(' | '));
	ctx.assertClean('Umwandeln/Import', m0);
});
