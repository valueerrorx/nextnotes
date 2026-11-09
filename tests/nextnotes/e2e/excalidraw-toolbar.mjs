// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// UI-excal (Thomas 2026-11-09, SPEC §4.3): Excalidraw-Werkzeugleiste ohne NC-Kern- und App-Reset-Stile.
// Je Breite (1400, 390): sichtbare Werkzeug-Icons ≥ 1rem (Excalidraw-Standard --lg/--default-icon-size), vollständig im Knopf, Knopf ≥ 2rem
// (mobil Excalidraw 1.8rem) und größer als das Icon, nicht abgeschnitten, versteckte Werkzeug-Inputs unsichtbar (Ursache: revert/NC machten native Radios sichtbar), Zoom/Undo/Menü/Hilfe ohne
// NC-Abstand und in Excalidraw-Größe; Farbfeld behält beim Hover seine Farbe (NC-Hover-Hintergrund); Regelliste in nextnotes-late.js
// vollständig; NC-Elemente außerhalb (Kopfleiste, App-Knöpfe, Dialog) unverändert; Theme folgt NC; 0 CSP/Fehler.
import { runSpec } from './lib/harness.mjs';
import { apiClient } from './lib/api.mjs';

const RUN = `${process.argv[3]}${Date.now().toString(36)}`;

await runSpec(import.meta.url, async (ctx, page) => {
	const api = apiClient(ctx, page.request);
	const WS = await api.workspace(`ExTb ${RUN}`);
	const n = await api.note(WS, `Werkzeugleiste ${RUN}`, null, '<p>Toolbar</p>');
	const url = ctx.appUrl(`excalidraw_editor.php?note_id=${n}&diagram_id=excalidraw-tb${RUN}&workspace=${encodeURIComponent(WS)}`);
	const m = ctx.mark();

	for (const [w, h] of [[1400, 900], [390, 844]]) {
		await page.setViewportSize({ width: w, height: h });
		await page.goto(url, { waitUntil: 'networkidle' });
		const ready = await page.waitForSelector('.excalidraw canvas', { timeout: 20000 }).then(() => true, () => false);
		ctx.ok(ready, `${w}: Zeichenfläche geladen`);
		await page.waitForTimeout(1000);
		const r = await page.evaluate(() => {
			const rem = parseFloat(getComputedStyle(document.documentElement).fontSize);
			const box = (el) => { const b = el.getBoundingClientRect(); return { l: b.left, t: b.top, r: b.right, b: b.bottom, w: b.width, h: b.height }; };
			const inside = (a, o) => a.l >= o.l - 0.5 && a.t >= o.t - 0.5 && a.r <= o.r + 0.5 && a.b <= o.b + 0.5;
			const ex = document.querySelector('.excalidraw');
			const view = { l: 0, t: 0, r: innerWidth, b: innerHeight };
			const tools = [...document.querySelectorAll('.App-toolbar .ToolIcon')].filter((t) => t.getBoundingClientRect().width > 0).map((t) => {
				const icon = t.querySelector('.ToolIcon__icon'), svg = icon?.querySelector('svg'), input = t.querySelector('input');
				const bi = icon ? box(icon) : null, bs = svg ? box(svg) : null;
				return { name: t.querySelector('[data-testid]')?.dataset.testid ?? t.title ?? '?', bi, bs,
					svgIn: !!(bi && bs && inside(bs, bi)), iconIn: !!(bi && inside(bi, box(ex)) && inside(bi, view) && inside(bi, box(t.closest('.Island, .App-toolbar-content') ?? ex))),
					inputOpacity: input ? getComputedStyle(input).opacity : '0' };
			});
			const buttons = [...document.querySelectorAll('.excalidraw :is(.zoom-actions button, .undo-redo-buttons button, .main-menu-trigger, .help-icon, .App-toolbar__extra-tools-trigger)')]
				.filter((b) => b.getBoundingClientRect().width > 0).map((b) => {
					const cs = getComputedStyle(b), svg = b.querySelector('svg');
					return { cls: b.className.split(' ').find((c) => /zoom|undo|redo|menu|help|extra/.test(c)) ?? b.className, bb: box(b), margin: cs.margin, minH: cs.minHeight,
						svgIn: !svg || inside(box(svg), box(b)), border: cs.borderTopStyle };
				});
			return { rem, dark: ex?.classList.contains('theme--dark') ?? null, mobile: ex?.classList.contains('excalidraw--mobile') ?? null,
				lgIcon: getComputedStyle(ex).getPropertyValue('--lg-icon-size').trim(), tools, buttons };
		});
		ctx.ok(r.dark === (ctx.theme === 'dark'), `${w}: Excalidraw-Theme folgt NC (${ctx.theme}, theme--dark=${r.dark})`);
		ctx.ok(r.tools.length >= 10, `${w}: Werkzeugleiste mit ${r.tools.length} Werkzeugen (mobil=${r.mobile})`);
		const small = r.tools.filter((t) => !t.bs || t.bs.w < r.rem - 0.01 || t.bs.h < r.rem - 0.01);
		ctx.ok(small.length === 0, `${w}: Icons ≥ 1rem = ${r.rem}px (Excalidraw-Standard, --lg-icon-size ${r.lgIcon})`, small.map((t) => `${t.name} ${t.bs?.w}×${t.bs?.h}`).join(', '));
		const out = r.tools.filter((t) => !t.svgIn);
		ctx.ok(out.length === 0, `${w}: jedes Icon vollständig in seinem Knopf`, out.map((t) => t.name).join(', '));
		const minBtn = (r.mobile ? 1.8 : 2) * r.rem;
		const tiny = r.tools.filter((t) => !t.bi || t.bi.w < minBtn - 0.01 || t.bi.h < minBtn - 0.01 || t.bi.w < t.bs.w + 8);
		ctx.ok(tiny.length === 0, `${w}: Werkzeugknöpfe ≥ ${minBtn}px und größer als das Icon`, tiny.map((t) => `${t.name} ${t.bi?.w}×${t.bi?.h}`).join(', '));
		const cut = r.tools.filter((t) => !t.iconIn);
		ctx.ok(cut.length === 0, `${w}: kein Werkzeugknopf abgeschnitten (in Insel, Zeichenbereich und Fenster)`, cut.map((t) => `${t.name || '?'} ${JSON.stringify(t.bi)}`).join(', '));
		const shown = r.tools.filter((t) => t.inputOpacity !== '0');
		ctx.ok(shown.length === 0, `${w}: versteckte Werkzeug-Inputs unsichtbar (keine nativen Radios über den Icons)`, shown.map((t) => `${t.name} opacity ${t.inputOpacity}`).join(', '));
		ctx.ok(r.buttons.length >= (r.mobile ? 2 : 6), `${w}: Menü-/Zoom-/Undo-Knöpfe gefunden (${r.buttons.length})`);
		const bad = r.buttons.filter((b) => b.margin !== '0px' || !['auto', '0px'].includes(b.minH) || b.bb.h < 2 * r.rem - 0.01 || b.bb.w < 1.8 * r.rem || !b.svgIn || b.border === 'outset');
		ctx.ok(bad.length === 0, `${w}: Menü-/Zoom-/Undo-/Hilfe-Knöpfe in Excalidraw-Größe ohne NC-Abstand/Rahmen`, bad.map((b) => `${b.cls} ${b.bb.w}×${b.bb.h} m=${b.margin} minH=${b.minH} ${b.border}`).join(', '));
		await ctx.screenshot(page, `toolbar-${w}`);
	}

	// Eigenschaften-Panel: Farbfeld behält beim Hover seine Farbe (NC-Kern färbt sonst jeden Knopf beim Hover/Fokus)
	{
		await page.setViewportSize({ width: 1400, height: 900 });
		await page.goto(url, { waitUntil: 'networkidle' });
		await page.waitForSelector('.excalidraw canvas', { timeout: 20000 });
		await page.waitForTimeout(800);
		await page.click('[data-testid="toolbar-rectangle"]', { force: true });
		await page.mouse.move(600, 400);
		await page.mouse.down();
		await page.mouse.move(800, 550, { steps: 6 });
		await page.mouse.up();
		const swatch = page.locator('.excalidraw .color-picker__button:not(.active):not(.is-transparent)').first();
		const found = await swatch.waitFor({ timeout: 5000 }).then(() => true, () => false);
		ctx.ok(found, 'Panel: Farbfelder sichtbar');
		if (found) {
			await swatch.hover();
			await page.waitForTimeout(300);
			const c = await swatch.evaluate((el) => {
				const probe = document.createElement('span');
				probe.style.color = getComputedStyle(el).getPropertyValue('--swatch-color');
				document.body.append(probe);
				const want = getComputedStyle(probe).color;
				probe.remove();
				return { got: getComputedStyle(el).backgroundColor, want };
			});
			ctx.ok(c.got === c.want, 'Panel: Farbfeld zeigt beim Hover seine Farbe', `${c.got} statt ${c.want}`);
			await ctx.screenshot(page, 'panel');
		}
	}
	// Nachweis der Regelliste: keine ungeschützte core/css-Regel trifft noch ein Element in .excalidraw (Panel offen, Hover auf Farbfeld)
	{
		const left = await page.evaluate(() => {
			const els = [...document.querySelectorAll('.excalidraw *')];
			const hits = new Set();
			const walk = (rules) => { for (const ru of rules) {
				if (ru.cssRules && !ru.selectorText) { walk(ru.cssRules); continue; }
				if (!ru.selectorText) continue;
				for (const part of ru.selectorText.split(/,(?![^(]*\))/)) {
					if (part.includes(':not(.excalidraw *)') || /::?(before|after|placeholder|-webkit|-moz|selection|first-)/.test(part)) continue;
					try { if (els.some((e) => e.matches(part))) hits.add(part.trim()); } catch { /* ungültig im Browser */ }
				}
			} };
			for (const ss of document.styleSheets) { if (!/\/core\/css\//.test(ss.href || '')) continue; try { walk(ss.cssRules); } catch { /* fremd */ } }
			return [...hits];
		});
		ctx.ok(left.length === 0, 'Regelliste vollständig: keine ungeschützte NC-Kern-Regel greift in Excalidraw', left.slice(0, 10).join(' | '));
	}

	// NC-Elemente außerhalb von Excalidraw (Kopfleiste, App-Knöpfe, Dialog) unverändert: Stile mit und ohne Schutz gleich
	{
		await page.evaluate(() => { window.alert('UI-excal Prüfdialog'); });
		await page.waitForSelector('.alert-modal-overlay .alert-modal-button', { timeout: 5000 }).catch(() => {});
		const P = ['width', 'height', 'margin', 'padding', 'border', 'border-radius', 'background-color', 'color', 'font-size', 'font-weight', 'line-height', 'cursor', 'box-sizing', 'box-shadow', 'opacity', 'display'];
		const diff = await page.evaluate((P) => {
			const els = [...document.querySelectorAll('#header *, .poznote-toolbar, .poznote-toolbar *, .alert-modal-overlay, .alert-modal-overlay *')];
			const snap = () => els.map((e) => { const cs = getComputedStyle(e); return P.map((p) => cs.getPropertyValue(p)).join('|'); });
			const a = snap();
			const strip = (rules) => { for (const ru of rules) { if (ru.cssRules && !ru.selectorText) strip(ru.cssRules); else if (ru.selectorText?.includes(':where(:not(.excalidraw *))')) ru.selectorText = ru.selectorText.replace(/(^|[\s>+~(,])(?=:where\(:not\(\.excalidraw \*\)\))/g, '$1*').split(':where(:not(.excalidraw *))').join(''); } }; // `a *` serialisiert als `a :where(…)`
			for (const ss of document.styleSheets) { if (!/\/core\/css\//.test(ss.href || '')) continue; try { strip(ss.cssRules); } catch { /* fremd */ } }
			const b = snap();
			const bad = [];
			els.forEach((e, i) => { if (a[i] !== b[i]) { const x = a[i].split('|'), y = b[i].split('|'); bad.push(`${e.tagName}.${String(e.className?.baseVal ?? e.className).slice(0, 30)} ${P.filter((p, k) => x[k] !== y[k]).map((p) => `${p}: ${x[P.indexOf(p)]} → ${y[P.indexOf(p)]}`).join('; ')}`); } });
			return { n: els.length, modal: !!document.querySelector('.alert-modal-overlay'), bad };
		}, P);
		ctx.ok(diff.n > 20 && diff.modal, `Außerhalb: ${diff.n} Elemente aus Kopfleiste, App-Knöpfen und Dialog geprüft`);
		ctx.ok(diff.bad.length === 0, 'Außerhalb: NC-Kopfleiste, App-Knöpfe und Dialog unverändert', diff.bad.slice(0, 10).join(', '));
		await page.click('.alert-modal-overlay .alert-modal-button').catch(() => {});
	}
	ctx.assertClean('Excalidraw-Werkzeugleiste', m);
});
