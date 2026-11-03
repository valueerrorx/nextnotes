// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// M3c2 (SPEC §4.3.13, §11.1 P14, ADR 0005, ADR 0012): Slash-Befehl Excalidraw → Editor-Seite im NC-Rahmen → Zeichnen →
// Speichern und zurück → Vorschau in der Notiz; fremde Sperre → 423 mit Meldung, Zeichnung bleibt, nach Freigabe gespeichert;
// Lesefreigabe öffnet im Ansichtsmodus ohne Speichern. Je Abschnitt 0 CSP/pageerror/Konsole/≥400.
// M4a (ADR 0012 Nachtrag M4a): Bild einfügen (PNG 200 KB; 3 MB über 1440 px → Verkleinerung über pica), speichern, neu laden.
import { deflateSync, crc32 } from 'node:zlib';
import { runSpec } from './lib/harness.mjs';
import { apiClient } from './lib/api.mjs';
import { share } from './lib/docker.mjs';

const RUN = `${process.argv[3]}${Date.now().toString(36)}`;

// PNG aus Zufallspixeln (komprimiert kaum → Dateigröße ≈ w·h·3)
function png(w, h) {
	const chunk = (type, data) => {
		const len = Buffer.alloc(4); len.writeUInt32BE(data.length);
		const td = Buffer.concat([Buffer.from(type), data]);
		const crc = Buffer.alloc(4); crc.writeUInt32BE(crc32(td) >>> 0);
		return Buffer.concat([len, td, crc]);
	};
	const ihdr = Buffer.alloc(13);
	ihdr.writeUInt32BE(w, 0); ihdr.writeUInt32BE(h, 4); ihdr[8] = 8; ihdr[9] = 2;
	const raw = Buffer.alloc((w * 3 + 1) * h);
	for (let i = 0; i < raw.length; i++) raw[i] = (i % (w * 3 + 1)) === 0 ? 0 : (Math.random() * 256) | 0;
	return Buffer.concat([Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]), chunk('IHDR', ihdr), chunk('IDAT', deflateSync(raw, { level: 1 })), chunk('IEND', Buffer.alloc(0))]);
}

await runSpec(import.meta.url, async (ctx, page, context, browser) => {
	const user = process.env.NN_USER, user2 = process.env.NN_USER2;
	const api = apiClient(ctx, page.request);
	// eigener Kontext für Benutzer 2: page.request teilt die Sitzungs-Cookies von Benutzer 1, NC meldet dann über das Cookie an
	const reqCtx2 = await browser.newContext();
	const api2 = apiClient(ctx, reqCtx2.request, user2, process.env.NN_PASS2);
	const WS = await api.workspace(`Exc ${RUN}`);
	const WSW = await api.workspace(`ExcW ${RUN}`);
	const n1 = await api.note(WS, `Zeichnung ${RUN}`, null, `<p>Excalidraw ${RUN}</p>`);
	const n2 = await api.note(WSW, `Gesperrt ${RUN}`, null, `<p>Sperre ${RUN}</p>`);
	share(ctx.base, user, WS, user2, 'read');
	share(ctx.base, user, WSW, user2, 'write');
	const content = async (id) => (await api.must('GET', `/notes/${id}`)).note?.content ?? '';
	const dialogs = []; // native Dialoge (sollten nicht vorkommen; modal-alerts.js ersetzt alert)
	page.on('dialog', (d) => { dialogs.push(d.message()); d.dismiss().catch(() => {}); });
	const rect = async (x, y) => {
		await page.click('[data-testid="toolbar-rectangle"]', { force: true });
		await page.mouse.move(x, y);
		await page.mouse.down();
		await page.mouse.move(x + 200, y + 150, { steps: 8 });
		await page.mouse.up();
		await page.waitForTimeout(800);
	};
	const editorReady = () => page.waitForSelector('.excalidraw canvas', { timeout: 20000 }).then(() => true, () => false);

	// A. Slash-Befehl → Editor → Zeichnen → Speichern und zurück → Vorschau
	{
		await page.goto(ctx.appUrl(`index.php?workspace=${encodeURIComponent(WS)}&note=${n1}`), { waitUntil: 'networkidle' });
		await ctx.selfTest(page);
		const entry = `#entry${n1}`;
		await page.waitForSelector(entry);
		const m = ctx.mark();
		await page.evaluate((sel) => {
			const el = document.querySelector(sel);
			el.focus();
			const r = document.createRange();
			r.selectNodeContents(el);
			r.collapse(false);
			getSelection().removeAllRanges();
			getSelection().addRange(r);
		}, entry);
		await page.keyboard.press('Enter');
		await page.keyboard.type('/excali');
		const item = await page.waitForFunction(() => {
			const menu = document.querySelector('.slash-command-menu.show');
			const sel = menu?.querySelector('.selected');
			return !!menu && menu.innerText.trim() === 'Excalidraw' && !!sel;
		}, null, { timeout: 5000 }).then(() => true, () => false);
		ctx.ok(item, 'A: Slash-Menü zeigt Excalidraw');
		const nav = page.waitForURL((u) => u.pathname.endsWith('/excalidraw_editor.php'), { timeout: 15000 }).then(() => true, () => false);
		await page.keyboard.press('Enter');
		ctx.ok(await nav, 'A: Slash-Befehl öffnet excalidraw_editor.php', page.url());
		ctx.ok(await editorReady(), 'A: Zeichenfläche geladen');
		await page.waitForTimeout(1500);
		const info = await page.evaluate(() => {
			const tb = document.querySelector('.poznote-toolbar')?.getBoundingClientRect();
			const hd = document.querySelector('#header')?.getBoundingClientRect();
			return { dark: document.querySelector('.excalidraw')?.classList.contains('theme--dark') ?? null, tbTop: tb?.top ?? -1, hdBottom: hd?.bottom ?? 0,
				appH: document.getElementById('app')?.getBoundingClientRect().height ?? 0, save: !!document.getElementById('saveBtn') };
		});
		ctx.ok(info.dark === (ctx.theme === 'dark'), `A: Editor-Theme folgt NC (${ctx.theme}, theme--dark=${info.dark})`);
		ctx.ok(info.tbTop >= info.hdBottom - 1 && info.appH > 300, `A: Leiste unter NC-Kopf, Fläche sichtbar (${info.tbTop}/${info.hdBottom}/${info.appH})`);
		ctx.ok(info.save, 'A: Speichern-Knopf für Besitzer');
		await ctx.screenshot(page, 'editor');
		await rect(500, 400);
		const back = page.waitForURL((u) => u.pathname.endsWith('/index.php') && u.searchParams.get('note') === String(n1), { timeout: 20000 }).then(() => true, () => false);
		await page.click('#saveAndExitBtn');
		ctx.ok(await back, 'A: Speichern und schließen kehrt zur Notiz zurück');
		await page.waitForLoadState('networkidle');
		const img = await page.waitForFunction((sel) => {
			const i = document.querySelector(`${sel} .excalidraw-container img`);
			return i && i.complete && i.naturalWidth > 0;
		}, entry, { timeout: 15000 }).then(() => true, () => false);
		ctx.ok(img, 'A: Notiz zeigt die Vorschau');
		const saved = await content(n1);
		const raw = saved.match(/data-excalidraw=(?:"([^"]*)"|'([^']*)')/);
		const data = (() => { try { return JSON.parse((raw?.[1] ?? raw?.[2])?.replace(/&quot;/g, '"').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&#0?39;/g, "'").replace(/&amp;/g, '&') ?? '{}'); } catch { return {}; } })();
		ctx.ok((data.elements ?? []).filter((e) => e.type === 'rectangle' && !e.isDeleted).length === 1, 'A: gespeichertes Diagramm enthält das Rechteck');
		ctx.ok(saved.includes(`Excalidraw ${RUN}`), 'A: Notiztext erhalten');
		ctx.ok(dialogs.length === 0 && !(await page.$('.alert-modal-overlay')), 'A: keine Fehlermeldung', dialogs.join(' | '));
		await ctx.screenshot(page, 'note');
		ctx.assertClean('A Slash/Editor/Speichern', m);
		await page.goto('about:blank'); // pagehide gibt die Sperre der Notiz frei
	}

	// B. Fremde Sperre (ADR 0005) → 423 mit Meldung, Zeichnung bleibt im Editor; nach Freigabe gespeichert
	{
		const lock = (action) => reqCtx2.request.post(ctx.appUrl(`api/v1/notes/${n2}/lock${action}`), {
			headers: { Authorization: `Basic ${Buffer.from(`${user2}:${process.env.NN_PASS2}`).toString('base64')}`, 'OCS-APIRequest': 'true', 'X-Editor-Session-ID': `e2e-${RUN}` },
			failOnStatusCode: false });
		const got = await lock('');
		ctx.ok(got.status() === 200, `B: Benutzer 2 hält die Sperre (HTTP ${got.status()})`);
		const before = await content(n2);
		const m = ctx.mark();
		const off = [ctx.allow('status', /^423 POST .*\/api_save_excalidraw\.php/, 'erwartet: fremde Sperre'),
			ctx.allow('console', /Save error|status of 423 .*api_save_excalidraw\.php/, 'erwartet: Editor/Browser protokollieren das abgelehnte Speichern')];
		await page.goto(ctx.appUrl(`excalidraw_editor.php?note_id=${n2}&diagram_id=excalidraw-e2e${RUN}&workspace=${encodeURIComponent(WSW)}`), { waitUntil: 'networkidle' });
		ctx.ok(await editorReady(), 'B: Editor geladen');
		await page.waitForTimeout(1500);
		await rect(450, 380);
		const resp = page.waitForResponse((r) => r.url().includes('api_save_excalidraw.php'), { timeout: 20000 });
		await page.click('#saveBtn');
		const r1 = await resp;
		ctx.ok(r1.status() === 423, `B: Speichern trotz fremder Sperre → 423 (HTTP ${r1.status()})`);
		// modal-alerts.js ersetzt window.alert durch ein DOM-Modal (.alert-modal)
		const modal = await page.waitForSelector('.alert-modal-overlay .alert-modal-body', { timeout: 5000 }).then((e) => e.innerText(), () => '');
		ctx.ok(/bearbeitet|gesperrt/i.test(modal) && !/being edited|locked/i.test(modal), 'B: Benutzer sieht eine deutsche Meldung zur Sperre (B38)', modal || 'kein Modal');
		await page.click('.alert-modal-overlay .alert-modal-button').catch(() => {});
		await page.waitForSelector('.alert-modal-overlay', { state: 'detached', timeout: 5000 }).catch(() => {});
		ctx.ok(page.url().includes('excalidraw_editor.php'), 'B: Editor bleibt offen');
		ctx.ok(await content(n2) === before, 'B: Notiz unverändert');
		off.forEach((f) => f());
		const rel = await lock('/release');
		ctx.ok(rel.status() === 200, `B: Sperre freigegeben (HTTP ${rel.status()})`);
		const resp2 = page.waitForResponse((r) => r.url().includes('api_save_excalidraw.php'), { timeout: 20000 }).catch(() => null);
		await page.click('#saveBtn');
		const r2 = await resp2;
		ctx.ok(r2?.status() === 200, `B: erneutes Speichern nach Freigabe → 200 (HTTP ${r2?.status()})`);
		const after = await content(n2);
		ctx.ok(after.includes(`id="excalidraw-e2e${RUN}"`) && after.includes(`Sperre ${RUN}`), 'B: Diagramm eingefügt, Text erhalten');
		ctx.assertClean('B Sperre', m);
		await page.goto('about:blank');
	}

	// C. Lesefreigabe: Ansichtsmodus, ohne Speichern
	{
		const diagram = (await content(n1)).match(/class="excalidraw-container"[^>]*\bid="([^"]+)"|\bid="([^"]+)"[^>]*class="excalidraw-container"/);
		const did = diagram?.[1] ?? diagram?.[2] ?? '';
		ctx.ok(did !== '', `C: Diagramm-ID ermittelt (${did})`);
		const c2 = await browser.newContext({ viewport: { width: 1400, height: 900 }, locale: 'de-DE' });
		await ctx.attach(c2);
		const p2 = await c2.newPage();
		await ctx.login(p2, user2, process.env.NN_PASS2);
		const m = ctx.mark();
		const resp = await p2.goto(ctx.appUrl(`excalidraw_editor.php?note_id=${n1}&diagram_id=${encodeURIComponent(did)}`), { waitUntil: 'networkidle' });
		await p2.waitForSelector('.excalidraw canvas', { timeout: 20000 }).catch(() => {});
		await p2.waitForTimeout(1500);
		const ro = await p2.evaluate(() => ({
			save: !!document.getElementById('saveBtn') || !!document.getElementById('saveAndExitBtn'),
			back: !!document.getElementById('cancelBtn'),
			view: !!document.querySelector('.excalidraw.excalidraw--view-mode'),
			tools: !!document.querySelector('[data-testid="toolbar-rectangle"]'),
		}));
		ctx.ok(resp?.status() === 200, `C: Editor lesend HTTP ${resp?.status()}`);
		ctx.ok(!ro.save && ro.back, 'C: ohne Speichern-Knöpfe, mit Zurück');
		ctx.ok(ro.view && !ro.tools, `C: Ansichtsmodus (P14) ohne Zeichenwerkzeuge (view=${ro.view}, tools=${ro.tools})`);
		await ctx.screenshot(p2, 'readonly');
		ctx.assertClean('C Lesefreigabe', m);
		await p2.goto('about:blank');
		await c2.close();
	}
	// D. M4a: Bild einfügen → speichern → Vorschau in der Notiz → neu laden zeigt das Bild im Editor
	// Chromium nutzt sonst showOpenFilePicker (kein filechooser-Ereignis); ohne ihn fällt browser-fs-access auf <input type=file> zurück
	await page.addInitScript(() => { delete Window.prototype.showOpenFilePicker; delete window.showOpenFilePicker; });
	for (const [label, w, h] of [['200KB', 260, 260], ['3MB', 2000, 500]]) {
		const n3 = await api.note(WS, `Bild ${label} ${RUN}`, null, `<p>Bild ${RUN}</p>`);
		const did = `excalidraw-img${label}${RUN}`;
		const bytes = png(w, h);
		const m = ctx.mark();
		// ADR 0012 Nachtrag M4a, Fall (b): pica prüft WebAssembly, die NC-CSP blockiert es, Verkleinerung läuft über den JS-Rückfall
		const off = ctx.allow('csp', /^script-src wasm-eval @.*\/js\/vendor\/excalidraw-dist\/excalidraw-assets\/vendor-[0-9a-f]+\.js:/, 'erwartet: pica-WASM blockiert, JS-Rückfall (ADR 0012 Nachtrag M4a)');
		await page.goto(ctx.appUrl(`excalidraw_editor.php?note_id=${n3}&diagram_id=${did}&workspace=${encodeURIComponent(WS)}`), { waitUntil: 'networkidle' });
		ctx.ok(await editorReady(), `D ${label}: Editor geladen`);
		await page.waitForTimeout(1200);
		const chooser = page.waitForEvent('filechooser', { timeout: 10000 });
		await page.click('[data-testid="toolbar-image"]', { force: true });
		await (await chooser).setFiles({ name: `bild-${label}.png`, mimeType: 'image/png', buffer: bytes });
		await page.waitForTimeout(1500);
		await page.mouse.move(600, 420);
		await page.mouse.click(600, 420);
		await page.waitForTimeout(2500);
		const resp = page.waitForResponse((r) => r.url().includes('api_save_excalidraw.php'), { timeout: 30000 }).catch(() => null);
		await page.click('#saveBtn');
		const r = await resp;
		ctx.ok(r?.status() === 200, `D ${label}: Speichern ${bytes.length} B PNG → 200 (HTTP ${r?.status()})`);
		const saved = await content(n3);
		const raw = saved.match(/data-excalidraw=(?:"([^"]*)"|'([^']*)')/);
		const data = (() => { try { return JSON.parse((raw?.[1] ?? raw?.[2])?.replace(/&quot;/g, '"').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&#0?39;/g, "'").replace(/&amp;/g, '&') ?? '{}'); } catch { return {}; } })();
		const images = (data.elements ?? []).filter((e) => e.type === 'image' && !e.isDeleted);
		const files = Object.values(data.files ?? {});
		ctx.ok(images.length === 1 && files.length === 1 && /^data:image\//.test(files[0]?.dataURL ?? ''), `D ${label}: Diagramm enthält das Bild (Elemente ${images.length}, Dateien ${files.length}, ${files[0]?.mimeType ?? '-'}, ${files[0]?.dataURL?.length ?? 0} Zeichen)`);
		if (w > 1440) ctx.ok((files[0]?.dataURL?.length ?? Infinity) < bytes.length * 4 / 3, `D ${label}: Bild über 1440 px verkleinert gespeichert (JS-Rückfall)`);
		await page.goto(ctx.appUrl(`excalidraw_editor.php?note_id=${n3}&diagram_id=${did}&workspace=${encodeURIComponent(WS)}`), { waitUntil: 'networkidle' });
		ctx.ok(await editorReady(), `D ${label}: neu geladen`);
		await page.waitForTimeout(1500);
		await ctx.screenshot(page, `image-${label}`);
		await page.goto(ctx.appUrl(`index.php?workspace=${encodeURIComponent(WS)}&note=${n3}`), { waitUntil: 'networkidle' });
		const prev = await page.waitForFunction((id) => {
			const i = document.querySelector(`#entry${id} .excalidraw-container img`);
			return i && i.complete && i.naturalWidth > 0;
		}, n3, { timeout: 15000 }).then(() => true, () => false);
		ctx.ok(prev, `D ${label}: Notiz zeigt die Vorschau mit Bild`);
		const wasm = ctx.events.slice(m.e).filter((e) => e.kind === 'csp' && e.allowed);
		ctx.ok(wasm.every((e) => e.text.startsWith('script-src wasm-eval ')), `D ${label}: einzige CSP-Meldung ist pica-WASM (${wasm.length}×)`);
		ctx.assertClean(`D Bild ${label}`, m);
		off();
		await page.goto('about:blank');
	}

	ctx.ok((await api2.call('GET', `/notes/${n1}`)).status === 200, 'Gegenprobe: Benutzer 2 liest die Notiz per API');
	await reqCtx2.close();
});
