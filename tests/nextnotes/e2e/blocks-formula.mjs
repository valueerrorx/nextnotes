// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// M8a (SPEC §4.7, M8-Plan §2): Formel einfügen über Werkzeugleiste und /Formel, bearbeiten, Inline↔Block, löschen, Neuladen,
// B69 (gespeichert nur der Vertrag), MathLive lazy, 30 Schulformeln MathLive → KaTeX, Tastatur-Isolation, Leser/Material/Sperre,
// Druckansicht, Export, Austeilen, MathLive-Payloads, Mobil/Tablet. 0 CSP/Konsole je Abschnitt, keine Fremd-Anfragen.
import { runSpec, clickMath } from './lib/harness.mjs';
import { apiClient } from './lib/api.mjs';
import { createTeam } from './lib/docker.mjs';

const RUN = `${process.argv[3]}${Date.now().toString(36)}`;
const A = process.env.NN_USER;
const B = process.env.NN_USER2;
const PAYLOADS = [
	'\\htmlData{href=javascript:alert(1)}{x}',
	'\\cssId{evil}{x}',
	'\\class{nn-sheet}{x}',
	'\\htmlStyle{position:fixed;inset:0;background:url(https://example.org/x)}{x}',
	'\\href{javascript:alert(1)}{x}',
	'\\href{file:///etc/passwd}{x}',
];
const SCHOOL = [
	'\\frac{a}{b}', '\\sqrt{x}', '\\sqrt[3]{27}=3', 'x_1+x_2', 'x^{2}', 'a_{n+1}=a_n+d', '\\sum_{i=1}^{n} i=\\frac{n(n+1)}{2}',
	'\\int_0^1 x^2\\,dx', '\\begin{pmatrix}1&2\\\\3&4\\end{pmatrix}', '\\vec{v}', '\\overrightarrow{AB}', '\\alpha+\\beta+\\gamma=180^\\circ',
	'A=\\pi r^2', '\\Delta x', 'g=9{,}81\\,\\text{m/s}^2', 'v=72\\,\\text{km/h}', 'v=\\frac{s}{t}', 'E=mc^2', 'a^2+b^2=c^2',
	'x_{1,2}=\\frac{-b\\pm\\sqrt{b^2-4ac}}{2a}', '\\lim_{x\\to\\infty}\\frac{1}{x}=0', "f'(x)=2x", '\\sin^2\\alpha+\\cos^2\\alpha=1',
	'\\log_{2} 8=3', '\\left|x-1\\right|\\le 2', 'a\\ne b', '3\\cdot 4\\times 2\\div 6', '\\infty', '\\binom{n}{k}', 'p=25\\,\\%',
];

// Elemente mit gefährlicher URL im ganzen DOM, auch in Shadow Roots
function badUrls(page) {
	return page.evaluate(() => {
		const out = [];
		const walk = (root) => {
			root.querySelectorAll('*').forEach((el) => {
				for (const a of ['href', 'src', 'xlink:href', 'action', 'formaction']) {
					const v = el.getAttribute(a);
					if (v && /^\s*(javascript|file|data|vbscript):/i.test(v)) out.push(`${el.tagName}[${a}=${v.slice(0, 40)}]`);
				}
				if (el.shadowRoot) walk(el.shadowRoot);
			});
		};
		walk(document);
		return out;
	});
}

await runSpec(import.meta.url, async (ctx, page, context, browser) => {
	const api = apiClient(ctx, page.request);
	const content = async (id, c = api) => (await c.must('GET', `/notes/${id}`)).note?.content ?? '';
	const waitFor = async (fn, ms = 10000) => {
		for (const end = Date.now() + ms; Date.now() < end; await page.waitForTimeout(250)) if (await fn()) return true;
		return fn();
	};
	const mlRequests = (since) => ctx.requests.slice(since.q).filter((r) => /mathlive|nextnotes-formula/.test(r.url));
	const foreign = (since) => ctx.requests.slice(since.q).filter((r) => { try { return new URL(r.url).origin !== new URL(ctx.base).origin && !r.url.startsWith('data:'); } catch { return true; } });
	const dialogs = [];
	context.on('page', (p) => p.on('dialog', (d) => { dialogs.push(d.message()); d.dismiss().catch(() => {}); }));
	page.on('dialog', (d) => { dialogs.push(d.message()); d.dismiss().catch(() => {}); });
	await context.addInitScript(() => {
		window.__nnOpened = [];
		window.open = function (u) { window.__nnOpened.push(String(u)); return null; };
	});

	const WS = await api.workspace(`Formel ${RUN}`);
	const id = await api.note(WS, `Formeln ${RUN}`, null, `<p>Start ${RUN}</p><p>Zweiter Absatz</p>`);
	const noteUrl = ctx.appUrl(`index.php?workspace=${encodeURIComponent(WS)}&note=${id}`);
	const entry = page.locator(`#entry${id}`);
	const pop = page.locator('.nn-formula-popover');
	const field = pop.locator('math-field');
	// Fokus wandert nach dem Einhängen ins Formelfeld (setTimeout im Popover)
	const focused = (p = page) => p.waitForFunction(() => document.activeElement?.tagName === 'MATH-FIELD', null, { timeout: 5000 }).then(() => true, () => false);

	// 1. Laden ohne MathLive; Werkzeugleiste → Formel (inline), Eingabe, Enter
	let m = ctx.mark();
	await page.goto(noteUrl, { waitUntil: 'networkidle' });
	await ctx.selfTest(page);
	ctx.ok(ctx.requests.some((r) => /\/js\/nextnotes-blocks\.js/.test(r.url)), 'nextnotes-blocks.js geladen');
	ctx.ok(mlRequests(m).length === 0, 'Done 8: MathLive/Formel-Modul nicht beim Laden der Notiz', mlRequests(m).map((r) => r.url).join(' | '));
	await entry.locator('p').first().click();
	await page.keyboard.press('End');
	await page.locator(`#note${id} .btn-nn-blocks`).click();
	await page.locator('.nn-blocks-menu [data-nn-block="nn-formula"]').click();
	await pop.waitFor({ state: 'visible', timeout: 15000 });
	ctx.ok(mlRequests(m).some((r) => /mathlive\.min\.js/.test(r.url)), 'Done 8: MathLive lädt beim ersten Öffnen des Popovers');
	await field.waitFor({ state: 'visible' });
	ctx.ok(await focused(), 'Popover: Fokus im Formelfeld (Werkzeugleiste)');
	await page.keyboard.type('x^2');
	await page.keyboard.press('ArrowRight');
	await page.keyboard.type('+1');
	await page.keyboard.press('Enter');
	await pop.waitFor({ state: 'detached', timeout: 5000 });
	const inl = entry.locator('span.math-inline[data-math]');
	ctx.ok(await inl.count() === 1 && (await inl.getAttribute('data-math')) === 'x^2+1', 'Werkzeugleiste: Inline-Formel eingefügt', await inl.getAttribute('data-math').catch(() => ''));
	ctx.ok(await inl.locator('.katex').count() === 1, 'Inline-Formel mit KaTeX angezeigt');
	ctx.ok(await waitFor(async () => (await content(id)).includes('<span class="math-inline" data-math="x^2+1" contenteditable="false">x^2+1</span>')), 'gespeichert exakt nach Vertrag (inline)');
	await ctx.screenshot(page, 'inline');
	ctx.assertClean('Werkzeugleiste inline', m);

	// 2. /Formel-Block über das Slash-Menü (Suche), Bruch tippen
	m = ctx.mark();
	await entry.locator('p', { hasText: 'Zweiter Absatz' }).click();
	await page.keyboard.press('End');
	await page.keyboard.press('Enter');
	await page.keyboard.type('/Formel-Block');
	await page.locator('.slash-command-menu').first().waitFor({ state: 'visible', timeout: 5000 });
	await page.waitForTimeout(400);
	const slashItems = await page.locator('.slash-command-menu').first().innerText();
	ctx.ok(/Formel-Block/.test(slashItems), 'Slash-Suche findet „Formel-Block“', slashItems.replace(/\s+/g, ' ').slice(0, 120));
	await page.keyboard.press('Enter');
	await pop.waitFor({ state: 'visible', timeout: 10000 });
	ctx.ok(await pop.locator('.nn-formula-toggle input').isChecked(), 'Formel-Block: Umschalter „eigene Zeile“ an');
	ctx.ok(await focused(), 'Popover: Fokus im Formelfeld (Slash)');
	await page.keyboard.type('1/2');
	await page.keyboard.press('Enter');
	await pop.waitFor({ state: 'detached', timeout: 5000 });
	const blk = entry.locator('div.math-block[data-math]');
	const frac = await blk.getAttribute('data-math').catch(() => '');
	ctx.ok(await blk.count() === 1 && /^\\frac/.test(frac), 'Slash: Block-Formel (Bruch) eingefügt', frac);
	ctx.ok(await blk.evaluate((el) => el.parentElement.classList.contains('noteentry')), 'Block auf oberster Ebene des Editors');
	ctx.ok(await waitFor(async () => (await content(id)).includes(`<div class="math-block" data-math="${frac}" contenteditable="false">${frac}</div>`)), 'gespeichert exakt nach Vertrag (Block)');
	// Slash-Suche „Formel“ findet beide Einträge
	await entry.locator('p', { hasText: 'Zweiter Absatz' }).click();
	await page.keyboard.press('End');
	await page.keyboard.type(' /Formel');
	await page.locator('.slash-command-menu').first().waitFor({ state: 'visible', timeout: 5000 });
	await page.waitForFunction(() => /Formel-Block/.test(document.querySelector('.slash-command-menu')?.innerText ?? ''), null, { timeout: 5000 }).catch(() => {});
	const both = await page.locator('.slash-command-menu').first().innerText();
	ctx.ok(/Formel\b/.test(both) && /Formel-Block/.test(both), 'Slash-Suche „/Formel“ zeigt Formel und Formel-Block', both.replace(/\s+/g, ' ').slice(0, 120));
	await page.keyboard.press('Escape');
	await page.keyboard.press('Backspace');
	for (let i = 0; i < 7; i++) await page.keyboard.press('Backspace');
	ctx.assertClean('Slash-Block', m);

	// 3. Tastatur-Isolation (R1): / : @ Enter-frei, Tab im Feld öffnen kein Menü und ändern die Notiz nicht
	m = ctx.mark();
	const before = await entry.evaluate((e) => e.textContent);
	await clickMath(inl);
	await pop.waitFor({ state: 'visible', timeout: 5000 });
	ctx.ok(await focused(), 'Popover: Fokus im Formelfeld (Klick)');
	await page.keyboard.press('End');
	await page.keyboard.type('/:@ ');
	await page.keyboard.press('Tab');
	await page.waitForTimeout(500);
	const menus = await page.evaluate(() => [...document.querySelectorAll('.slash-command-menu, .emoji-autocomplete-menu, .mention-autocomplete-menu')].filter((el) => el.getClientRects().length > 0 && getComputedStyle(el).visibility !== 'hidden').map((el) => el.className));
	ctx.ok(menus.length === 0, 'Done 6: kein Slash-/Emoji-/Erwähnungsmenü aus dem Formelfeld', menus.join(','));
	ctx.ok(await entry.evaluate((e) => e.textContent) === before, 'Done 6: Notiz unverändert beim Tippen im Formelfeld');
	await page.keyboard.press('Escape');
	await pop.waitFor({ state: 'detached', timeout: 5000 });
	ctx.ok((await inl.getAttribute('data-math')) === 'x^2+1', 'Esc verwirft die Änderung');
	ctx.assertClean('Tastatur-Isolation', m);

	// 4. Bearbeiten: Inline → Block, Block → Inline, Löschen; Neuladen identisch
	m = ctx.mark();
	await clickMath(inl);
	await pop.waitFor({ state: 'visible' });
	await field.evaluate((f) => { f.value = 'a^2+b^2=c^2'; });
	await pop.locator('.nn-formula-toggle input').check();
	await pop.locator('.nn-formula-apply').click();
	await pop.waitFor({ state: 'detached' });
	ctx.ok(await entry.locator('div.math-block[data-math="a^2+b^2=c^2"]').count() === 1 && await entry.locator('span.math-inline[data-math]').count() === 0, 'Inline → Block');
	const fracSel = frac.replace(/\\/g, '\\\\');
	const fracBlock = entry.locator(`div.math-block[data-math="${fracSel}"]`);
	await fracBlock.click();
	await pop.waitFor({ state: 'visible' });
	await pop.locator('.nn-formula-toggle input').uncheck();
	await pop.locator('.nn-formula-apply').click();
	await pop.waitFor({ state: 'detached' });
	ctx.ok(await entry.locator(`span.math-inline[data-math="${fracSel}"]`).count() === 1 && await fracBlock.count() === 0, 'Block → Inline');
	await clickMath(entry.locator('span.math-inline[data-math]'));
	await pop.waitFor({ state: 'visible' });
	await pop.locator('.nn-formula-delete').click();
	await pop.waitFor({ state: 'detached' });
	ctx.ok(await entry.locator('[data-math]').count() === 1, 'Löschen entfernt die Formel');
	const saved = async () => { const c = await content(id); return (c.match(/data-math=/g) ?? []).length === 1 && c.includes('data-math="a^2+b^2=c^2"'); };
	ctx.ok(await waitFor(saved), 'Bearbeitungen gespeichert', (await content(id)).slice(0, 400));
	const stored = await content(id);
	ctx.ok(!/katex|<math|mathnormal/.test(stored), 'gespeichert ohne KaTeX/MathML', stored.slice(0, 200));
	await page.goto(noteUrl, { waitUntil: 'networkidle' });
	await page.waitForFunction((n) => document.querySelector(`#entry${n} [data-math] .katex`), id, { timeout: 15000 });
	ctx.ok(await entry.locator('div.math-block[data-math="a^2+b^2=c^2"] .katex').count() === 1, 'Neuladen: Formel identisch und gerendert');
	ctx.assertClean('Bearbeiten/Neuladen', m);

	// 5. B69: Markdown-Notiz mit $x^2$ → Richtext, im Editor öffnen und speichern → nur Vertrag
	m = ctx.mark();
	const md = (await api.must('POST', '/notes', { heading: `B69 ${RUN}`, workspace: WS, type: 'markdown', content: 'Wert $x^2$ hier\n' })).note.id;
	await api.must('POST', `/notes/${md}/convert`, { target: 'html' });
	await page.goto(ctx.appUrl(`index.php?workspace=${encodeURIComponent(WS)}&note=${md}`), { waitUntil: 'networkidle' });
	await page.waitForFunction((n) => document.querySelector(`#entry${n} .math-inline .katex`), md, { timeout: 15000 });
	await page.locator(`#entry${md}`).click();
	await page.keyboard.press('End');
	await page.keyboard.type(` gespeichert${RUN}`);
	ctx.ok(await waitFor(async () => (await content(md)).includes(`gespeichert${RUN}`)), 'B69: Notiz mit gerenderter KaTeX-Ausgabe gespeichert');
	const b69 = await content(md);
	ctx.ok(b69.includes('<span class="math-inline" data-math="x^2" contenteditable="false">x^2</span>') && !/katex|<math/.test(b69), 'Done 2 (B69): gespeichert nur der Vertrag', b69.slice(0, 200));
	ctx.assertClean('B69', m);

	// 6. 30 Schulformeln: MathLive-Ausgabe (latex-expanded) rendert in KaTeX ohne ParseError
	m = ctx.mark();
	const parse = await page.evaluate(async (list) => {
		await window.poznoteEnsureMathLive();
		await window.poznoteEnsureKatex();
		const box = document.createElement('div');
		box.style.cssText = 'position:fixed;left:-9999px;top:0;width:600px';
		document.body.appendChild(box);
		const out = [];
		for (const src of list) {
			const mf = new window.MathfieldElement();
			box.appendChild(mf);
			mf.value = src;
			const latex = mf.getValue('latex-expanded');
			try { window.katex.renderToString(latex, { throwOnError: true, trust: false }); out.push(null); } catch (e) { out.push(`${src} → ${latex}: ${e.message}`); }
			mf.remove();
		}
		box.remove();
		return out;
	}, SCHOOL);
	ctx.ok(SCHOOL.length === 30 && parse.every((e) => e === null), 'Done 3: 30 Schulformeln MathLive → KaTeX ohne ParseError', parse.filter(Boolean).join(' | '));
	ctx.ok(!ctx.requests.slice(m.q).some((r) => /sounds\//.test(r.url)), 'keine Anfrage auf sounds/');
	ctx.assertClean('Schulformeln', m);

	// 7. Payloads (Done 9): Anzeige und Popover, Klick ins Feld
	m = ctx.mark();
	const unAllow = ctx.allow('console', /Invalid URL: (javascript:alert\(1\)|file:\/\/\/etc\/passwd)/, 'MathLive lehnt den Link beim Rendern ab (Payload-Test Done 9)');
	const evil = await api.note(WS, `Payload ${RUN}`, null, PAYLOADS.map((p) => `<p><span class="math-inline" data-math="${p.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;')}">x</span></p>`).join(''));
	await page.goto(ctx.appUrl(`index.php?workspace=${encodeURIComponent(WS)}&note=${evil}`), { waitUntil: 'networkidle' });
	await page.waitForFunction((n) => document.querySelectorAll(`#entry${n} [data-math] .katex`).length >= 6, evil, { timeout: 15000 }).catch(() => {});
	const hosts = page.locator(`#entry${evil} [data-math]`);
	ctx.ok(await hosts.count() === PAYLOADS.length, 'Payloads gespeichert als Formeln', String(await hosts.count()));
	for (let i = 0; i < PAYLOADS.length; i++) {
		await clickMath(hosts.nth(i));
		await pop.waitFor({ state: 'visible', timeout: 10000 });
		const box = await field.boundingBox();
		if (box) await page.mouse.click(box.x + Math.min(30, box.width / 2), box.y + box.height / 2, { modifiers: ['Control'] });
		if (box) await page.mouse.click(box.x + Math.min(30, box.width / 2), box.y + box.height / 2);
		await page.waitForTimeout(300);
		const cover = await page.evaluate(() => {
			const root = document.querySelector('.nn-formula-popover');
			const r = root.getBoundingClientRect();
			const pts = [[3, 3], [window.innerWidth - 3, window.innerHeight - 3], [3, window.innerHeight - 3]];
			return { inView: r.width <= window.innerWidth && r.height < window.innerHeight * 0.8, corners: pts.filter(([x, y]) => root.contains(document.elementFromPoint(x, y))).length };
		});
		ctx.ok(cover.inView && cover.corners === 0, `Payload ${i + 1}: Popover deckt die Seite nicht ab`, JSON.stringify(cover));
		await page.keyboard.press('Escape');
		await pop.waitFor({ state: 'detached', timeout: 5000 }).catch(() => {});
	}
	const bad = await badUrls(page);
	ctx.ok(bad.length === 0, 'Done 9: kein href/src auf javascript:/file:/data: im DOM', bad.join(' | '));
	const opened = await page.evaluate(() => window.__nnOpened);
	ctx.ok(opened.length === 0, 'Done 9: kein Fenster geöffnet', opened.join(' | '));
	ctx.ok(dialogs.length === 0, 'Done 9: kein alert/Dialog', dialogs.join(' | '));
	ctx.ok(await page.evaluate(() => !document.getElementById('evil') && !document.querySelector('.nn-sheet')), 'Done 9: \\cssId/\\class ohne Wirkung im Light DOM');
	ctx.ok(!ctx.requests.slice(m.q).some((r) => /example\.org/.test(r.url)), 'Done 9: keine Anfrage an example.org');
	ctx.ok(foreign(m).length === 0, 'nur Anfragen an die eigene Origin', foreign(m).map((r) => r.url).slice(0, 3).join(' | '));
	await ctx.screenshot(page, 'payloads');
	ctx.assertClean('Payloads', m);
	unAllow();

	// 8. Druckansicht und Export
	m = ctx.mark();
	await page.goto('about:blank');
	await page.goto(ctx.appUrl(`api_export_note.php?id=${id}&format=html&disposition=inline`));
	await page.waitForFunction(() => window.poznotePrintReady, null, { timeout: 15000 });
	await page.evaluate(() => window.poznotePrintReady);
	ctx.ok(await page.locator('#nextnotes-root .math-block .katex').count() === 1, 'Druckansicht: Formel mit KaTeX');
	ctx.ok(mlRequests(m).length === 0, 'Druckansicht lädt kein MathLive');
	await ctx.screenshot(page, 'print');
	const exp = await page.request.get(ctx.appUrl(`api_export_note.php?id=${id}&format=html`));
	const expHtml = await exp.text();
	ctx.ok(exp.status() === 200 && expHtml.includes('data-math="a^2+b^2=c^2"') && expHtml.includes('>a^2+b^2=c^2</div>'), 'HTML-Export: Vertrag mit LaTeX-Text', `HTTP ${exp.status()}`);
	const json = (await api.must('GET', `/notes/${id}`)).note.content;
	ctx.ok(json.includes('class="math-block" data-math="a^2+b^2=c^2"'), 'JSON: Rohinhalt');
	ctx.assertClean('Druck/Export', m);

	// 9. Team: Material (Leser), fremde Sperre, Austeilen
	m = ctx.mark();
	const teamId = createTeam(ctx.base, A, `Formel ${RUN}`, { [B]: 1 });
	const TW = `Formel T ${RUN}`;
	await api.must('POST', '/workspaces', { name: TW, team: teamId });
	const M = (await api.must('POST', '/folders', { folder_name: `Material ${RUN}`, workspace: TW })).folder_id;
	const FORM = '<p>Satz <span class="math-inline" data-math="\\sqrt{2}">x</span></p><div class="math-block" data-math="\\frac{a}{b}">y</div>';
	const mat = await api.note(TW, `Mat ${RUN}`, M, FORM);
	await api.must('PUT', `/folders/${M}/material`, { material: true });
	const shared = await api.note(TW, `Gemeinsam ${RUN}`, null, FORM);
	const ctxB = await browser.newContext({ viewport: { width: 1400, height: 900 }, locale: 'de-DE' });
	await ctx.attach(ctxB);
	const apiB = apiClient(ctx, ctxB.request, B, process.env.NN_PASS2);
	const pb = await ctxB.newPage();
	await ctx.login(pb, B, process.env.NN_PASS2);
	const popB = pb.locator('.nn-formula-popover');
	const mB = ctx.mark();
	await pb.goto(ctx.appUrl(`index.php?workspace=${encodeURIComponent(TW)}&note=${mat}`), { waitUntil: 'networkidle' });
	await pb.waitForFunction((n) => document.querySelectorAll(`#entry${n} [data-math] .katex`).length === 2, mat, { timeout: 15000 }).catch(() => {});
	ctx.ok(await pb.locator(`#entry${mat}`).getAttribute('data-nextnotes-readonly') === 'true', 'Material: nur lesen');
	ctx.ok(await pb.locator(`#entry${mat} [data-math] .katex`).count() === 2, 'Done 4: Leser sieht Formeln');
	ctx.ok(await pb.locator(`#note${mat} .btn-nn-blocks`).count() === 0, 'Leser: kein Formel-Knopf');
	await clickMath(pb.locator(`#entry${mat} [data-math]`).first());
	await pb.waitForTimeout(800);
	ctx.ok(await popB.count() === 0, 'Done 4: Leser – kein Popover');
	ctx.ok(mlRequests(mB).length === 0, 'Leser lädt kein MathLive');
	await ctx.screenshot(pb, 'reader');

	// Sperre: A öffnet „Gemeinsam“ (Sperre beim Laden), B sieht Banner → kein Popover; A verlässt → B bearbeitet ohne Neuladen
	ctx.allow('status', new RegExp(`409 POST .*/notes/${shared}/lock`), 'Sperrtest: B erhält 409');
	ctx.allow('console', /status of 409/, 'Sperrtest: 409 als Netzwerkfehler');
	await page.goto(ctx.appUrl(`index.php?workspace=${encodeURIComponent(TW)}&note=${shared}`), { waitUntil: 'networkidle' });
	await page.waitForTimeout(1000);
	await pb.goto(ctx.appUrl(`index.php?workspace=${encodeURIComponent(TW)}&note=${shared}`), { waitUntil: 'networkidle' });
	const banner = await pb.locator('.note-edit-lock-banner').first().waitFor({ state: 'visible', timeout: 10000 }).then(() => true, () => false);
	ctx.ok(banner, 'Sperre: B sieht das Sperr-Banner');
	await clickMath(pb.locator(`#entry${shared} [data-math]`).first());
	await pb.waitForTimeout(800);
	ctx.ok(await popB.count() === 0, 'Done 4: fremde Sperre – kein Popover');
	await page.goto(noteUrl, { waitUntil: 'networkidle' });
	const free = await (async () => {
		for (const end = Date.now() + 20000; Date.now() < end; await pb.waitForTimeout(500)) {
			if ((await pb.locator(`#entry${shared}`).getAttribute('contenteditable')) === 'true' && await pb.locator('.note-edit-lock-banner').count() === 0) return true;
		}
		return false;
	})();
	ctx.ok(free, 'Sperre frei: B ohne Neuladen bearbeitbar');
	await clickMath(pb.locator(`#entry${shared} [data-math]`).first());
	ctx.ok(await popB.waitFor({ state: 'visible', timeout: 10000 }).then(() => true, () => false), 'Done 4: nach Freigabe öffnet das Popover ohne Neuladen');
	await pb.keyboard.press('Escape');
	ctx.assertClean('Leser/Sperre', m);

	// Austeilen: Kopie enthält dieselben Formeln
	m = ctx.mark();
	const asg = (await api.must('POST', '/assignments', { workspace: TW, title: `Formel ${RUN}`, due_at: Math.floor(Date.now() / 1000) + 7200, source_note_id: shared })).assignment;
	await api.must('POST', `/assignments/${asg.id}/distribute`, {});
	const sub = (await api.must('GET', `/assignments/${asg.id}/submissions`)).submissions.find((s) => s.student_uid === B);
	const copy = sub ? await content(sub.note_id, apiB) : '';
	const src = await content(shared);
	const formulas = (h) => (h.match(/<(?:span|div) class="math-(?:inline|block)" data-math="[^"]*" contenteditable="false">[^<]*<\/(?:span|div)>/g) ?? []).join('|');
	ctx.ok(!!sub && formulas(copy) === formulas(src) && formulas(src).split('|').length === 2, 'Austeilen: Kopie enthält dieselben Formeln', formulas(copy));
	await pb.goto(ctx.appUrl(`index.php?note=${sub?.note_id}`), { waitUntil: 'networkidle' });
	await pb.waitForFunction((n) => document.querySelectorAll(`#entry${n} [data-math] .katex`).length === 2, sub?.note_id, { timeout: 15000 }).catch(() => {});
	ctx.ok(await pb.locator(`#entry${sub?.note_id} [data-math] .katex`).count() === 2, 'Austeilen: Schüler sieht die Formeln');
	await api.call('DELETE', `/assignments/${asg.id}`, { confirm: true });
	ctx.assertClean('Austeilen', m);
	await ctxB.close();

	// 10. Mobil 390 px und Tablet: Popover als Blatt (≤ 800 px), Bildschirmtastatur bei Touch; Hauptseite gibt die Sperre frei
	await page.goto('about:blank');
	await page.waitForTimeout(1000);
	for (const [label, vp] of [['mobil', { width: 390, height: 844 }], ['tablet', { width: 820, height: 1180 }]]) {
		m = ctx.mark();
		const mc = await browser.newContext({ viewport: vp, locale: 'de-DE', isMobile: label === 'mobil', hasTouch: true });
		await ctx.attach(mc);
		const mp = await mc.newPage();
		await ctx.login(mp, A, process.env.NN_PASS);
		await mp.goto(noteUrl, { waitUntil: 'networkidle' });
		const host = mp.locator(`#entry${id} [data-math]`).first();
		await host.waitFor({ state: 'visible', timeout: 15000 });
		await mp.waitForFunction((n) => window.NextNotesBlocks?.writable(document.querySelector(`#entry${n}`)), id, { timeout: 15000 }).catch(() => {});
		await clickMath(host, 'tap');
		const mpop = mp.locator('.nn-formula-popover');
		ctx.ok(await mpop.waitFor({ state: 'visible', timeout: 15000 }).then(() => true, () => false), `${label}: Popover öffnet per Tippen`);
		const sheet = await mpop.evaluate((el) => ({ sheet: el.classList.contains('is-sheet'), bottom: Math.round(window.innerHeight - el.getBoundingClientRect().bottom), w: Math.round(el.getBoundingClientRect().width) }));
		ctx.ok(label === 'mobil' ? sheet.sheet && sheet.bottom === 0 && sheet.w === vp.width : !sheet.sheet, `${label}: ${label === 'mobil' ? 'unteres Blatt' : 'Popover an der Formel'}`, JSON.stringify(sheet));
		await mpop.locator('math-field').tap();
		const kb = await mp.waitForFunction(() => { const k = document.querySelector('.ML__keyboard'); return k && k.getBoundingClientRect().height > 50; }, null, { timeout: 8000 }).then(() => true, () => false);
		ctx.ok(kb, `${label}: Bildschirmtastatur erscheint bei Touch`);
		await mp.waitForTimeout(600);
		const overlap = await mpop.evaluate((el) => { const k = document.querySelector('.ML__keyboard .MLK__plate') || document.querySelector('.ML__keyboard'); const kt = k ? k.getBoundingClientRect().top : window.innerHeight; return Math.round(el.getBoundingClientRect().bottom - kt); });
		ctx.ok(overlap <= 1, `${label}: Popover nicht von der Bildschirmtastatur verdeckt`, `Überlappung ${overlap}px`);
		const small = await mpop.evaluate((el) => [...el.querySelectorAll('button, label')].filter((b) => b.getBoundingClientRect().height < 34).length);
		ctx.ok(small === 0, `${label}: Bedienelemente ≥ 34 px`, String(small));
		await ctx.screenshot(mp, `popover-${label}`);
		await mpop.locator('.nn-formula-cancel').tap();
		await mc.close();
		ctx.assertClean(label, m);
	}
	ctx.assertClean('gesamter Lauf');
});
