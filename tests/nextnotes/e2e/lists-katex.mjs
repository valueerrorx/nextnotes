// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// M7-fix: Listenzeichen im Notizinhalt trotz NC-Reset (ul{list-style:none}); UI-Listen und Checklisten ohne Punkte;
// KaTeX bei Direktaufruf ?note= ohne 404 (Asset-Helfer vor dem App-Bundle, SPEC §4.3.9).
import { runSpec } from './lib/harness.mjs';
import { apiClient } from './lib/api.mjs';

const RT = '<ul><li>a<ul><li>b<ul><li>c</li></ul></li></ul></li></ul><ol><li>eins</li><li>zwei</li></ol>'
	+ '<ul class="checklist"><li class="checklist-item"><input type="checkbox"> erledigen</li></ul>'
	+ '<p>Formel <span class="math-inline" data-math="x^2+1"></span></p>';
const MD = '- a\n  - b\n\n1. eins\n2. zwei\n\n- [ ] Aufgabe\n\n$$\\frac{1}{2}$$\n';

// Berechnete Listen-Stile in root (Selektor relativ), Markierung = list-style-type oder ::before-Zähler (Editor-Nummerierung, Upstream)
async function lists(page, root) {
	return page.evaluate((root) => {
		const r = document.querySelector(root);
		if (!r) return null;
		const st = (el) => {
			const cs = getComputedStyle(el);
			const li = el.querySelector(':scope > li');
			const before = li ? getComputedStyle(li, '::before').content : 'none';
			return { type: cs.listStyleType, pad: parseFloat(cs.paddingInlineStart), before };
		};
		const pick = (s) => [...r.querySelectorAll(s)].map(st);
		return {
			ul: pick('ul:not(.checklist, .task-list, .dash-list)'),
			ol: pick('ol'),
			check: pick('ul:is(.checklist, .task-list)'),
			katex: r.querySelectorAll('.katex').length,
		};
	}, root);
}
const marked = (l) => l.type !== 'none' || /counter/.test(l.before);

await runSpec(import.meta.url, async (ctx, page) => {
	const api = apiClient(ctx, page.request);
	const ws = await api.defaultWorkspace();
	const stamp = Date.now();
	const rt = await api.note(ws, `M7fix Richtext ${stamp}`, null, RT);
	const md = (await api.must('POST', '/notes', { heading: `M7fix Markdown ${stamp}`, workspace: ws, type: 'markdown', content: MD })).note.id;
	const math = await api.note(ws, `M7fix Formel ${stamp}`, null, '<p>Inline <span class="math-inline" data-math="x^2"></span></p><p><span class="math-block" data-math="\\frac{1}{2}"></span></p>');
	const app = `${ctx.base}/index.php/apps/nextnotes`;

	// 0. Formel-Notiz per ?note= direkt (M7c-Befund: KaTeX seitenrelativ → 404, nichts gerendert)
	let m = ctx.mark();
	await page.goto(`${app}/index.php?note=${math}`, { waitUntil: 'networkidle' });
	const rendered = await page.evaluate(() => ['.math-inline', '.math-block'].map((s) => document.querySelectorAll(`#right_col .noteentry ${s} .katex`).length));
	ctx.ok(rendered.every((n) => n > 0), 'Formel-Notiz ?note=: Inline- und Blockformel gerendert', JSON.stringify(rendered));
	const kj = ctx.responses.slice(m.r).filter((r) => /katex\.min\.js/.test(r.url));
	ctx.ok(kj.length === 1 && kj[0].status === 200 && kj[0].url.includes('/nextnotes/js/vendor/katex/'), 'katex.min.js genau einmal vom App-Pfad', kj.map((r) => `${r.status} ${r.url}`).join(' | '));
	ctx.assertClean('Formel-Notiz ?note=', m);

	// 1. Richtext per ?note= direkt: Zeichen je Ebene, Einzug, Nummern, Checkliste ohne Punkt, KaTeX ohne 404
	await page.goto(`${app}/index.php?note=${rt}`);
	await page.waitForFunction(() => document.querySelector('#right_col .noteentry .katex'), null, { timeout: 15000 }).catch(() => {});
	let l = await lists(page, '#right_col .noteentry');
	ctx.ok(l && l.ul.map((u) => u.type).join(',') === 'disc,circle,square', 'Richtext: disc/circle/square je Ebene', JSON.stringify(l?.ul));
	ctx.ok(l && l.ul[0].pad >= 30, 'Richtext: Einzug der Aufzählung', JSON.stringify(l?.ul[0]));
	ctx.ok(l && l.ol.length === 1 && l.ol.every(marked), 'Richtext: Nummernliste markiert', JSON.stringify(l?.ol));
	ctx.ok(l && l.check.length === 1 && l.check.every((c) => c.type === 'none'), 'Richtext: Checkliste ohne Punkte', JSON.stringify(l?.check));
	ctx.ok(l && l.katex > 0, 'Richtext ?note=: .katex gerendert');
	await ctx.screenshot(page, 'richtext');

	// 2. UI-Listen außerhalb des Notizinhalts (Menüs, Leisten, NC-Kopf) bleiben ohne Zeichen
	const ui = await page.evaluate(() => [...document.querySelectorAll('#header ul, #nextnotes-root ul, #nextnotes-root ol')]
		.filter((u) => !u.closest('.noteentry, .diary-journal-body, .paste-markdown-preview, .insert-markdown-preview'))
		.map((u) => ({ id: u.id || u.className || u.parentElement.className, type: getComputedStyle(u).listStyleType }))
		.filter((u) => u.type !== 'none'));
	const uiCount = await page.evaluate(() => document.querySelectorAll('#header ul, #nextnotes-root ul').length);
	ctx.ok(uiCount > 0 && ui.length === 0, `UI-Listen ohne Zeichen (${uiCount} geprüft)`, JSON.stringify(ui));

	// 3. Markdown per ?note= direkt: disc/decimal, Aufgabenliste ohne Punkt, KaTeX
	m = ctx.mark();
	await page.goto(`${app}/index.php?note=${md}`);
	await page.waitForFunction(() => document.querySelector('#right_col .markdown-preview .katex'), null, { timeout: 15000 }).catch(() => {});
	l = await lists(page, '#right_col .markdown-preview');
	ctx.ok(l && l.ul.length >= 1 && l.ul.every((u) => u.type !== 'none' && u.pad > 0), 'Markdown: Aufzählung mit Zeichen und Einzug', JSON.stringify(l?.ul));
	ctx.ok(l && l.ol.length === 1 && l.ol.every(marked) && l.ol[0].pad > 0, 'Markdown: Nummernliste markiert', JSON.stringify(l?.ol));
	ctx.ok(l && l.check.length === 1 && l.check.every((c) => c.type === 'none'), 'Markdown: Aufgabenliste ohne Punkte', JSON.stringify(l?.check));
	ctx.ok(l && l.katex > 0, 'Markdown ?note=: .katex gerendert');
	await ctx.screenshot(page, 'markdown');

	// 4. Druckansicht (auch Abgabe-iframe): gleiche Listen, KaTeX
	await page.goto(`${app}/api_export_note.php?id=${rt}&format=html&disposition=inline`);
	await page.waitForFunction(() => window.poznotePrintReady, null, { timeout: 15000 });
	await page.evaluate(() => window.poznotePrintReady);
	l = await lists(page, '#nextnotes-root .noteentry');
	ctx.ok(l && l.ul.map((u) => u.type).join(',') === 'disc,circle,square' && l.ul[0].pad >= 30, 'Druckansicht: Aufzählung je Ebene mit Einzug', JSON.stringify(l?.ul));
	ctx.ok(l && l.ol.every(marked) && l.check.every((c) => c.type === 'none'), 'Druckansicht: Nummern ja, Checkliste nein', JSON.stringify(l));
	ctx.ok(l && l.katex > 0, 'Druckansicht: .katex gerendert');
	await ctx.screenshot(page, 'print');

	// 5. Papierkorb-Vorschau
	await api.must('DELETE', `/notes/${rt}?workspace=${encodeURIComponent(ws)}`);
	await page.goto(`${app}/trash.php?workspace=${encodeURIComponent(ws)}`);
	const card = `#note${rt} .trash-note-preview .noteentry`;
	await page.waitForSelector(card, { timeout: 15000 });
	l = await lists(page, card);
	ctx.ok(l && l.ul.length >= 1 && l.ul[0].type === 'disc', 'Papierkorb-Vorschau: Aufzählungszeichen', JSON.stringify(l?.ul));
	await ctx.screenshot(page, 'trash');
	ctx.assertClean('gesamter Lauf');
});
