// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// UI-fein 1: Nur-Lesen-Notiz voll lesbar (keine Opazität < 1 am Inhalt), Kennzeichen sichtbar, Schreibwerkzeuge gesperrt, Lesewerkzeuge frei.

export async function assertReadonlyView(ctx, page, noteId, { material = false, label = 'S' } = {}) {
	const entry = page.locator(`#entry${noteId}`);
	ctx.ok(await entry.getAttribute('data-nextnotes-readonly') === 'true', `${label}: data-nextnotes-readonly`);
	const dim = await entry.evaluate((e) => {
		const out = [];
		for (let n = e; n && n.id !== 'app-content'; n = n.parentElement) if (Number(getComputedStyle(n).opacity) < 1) out.push(`${n.tagName}#${n.id}.${n.className}`);
		e.querySelectorAll('p, li, span, div').forEach((n) => { if (Number(getComputedStyle(n).opacity) < 1 && !n.closest('button')) out.push(`${n.tagName}.${n.className}`); });
		return out;
	});
	ctx.ok(dim.length === 0, `${label}: Notizinhalt ohne Opazität < 1`, dim.slice(0, 5).join(' '));
	const badge = page.locator(`#note${noteId} .nn-readonly-badge`);
	const text = (await badge.innerText().catch(() => '')).trim();
	ctx.ok(await badge.isVisible().catch(() => false), `${label}: Kennzeichen sichtbar`, text);
	ctx.ok(material ? /^Material – (nur lesen|read only)$/.test(text) : /^(Nur lesen|Read only)$/.test(text), `${label}: Kennzeichen-Text`, text);
	const tb = page.locator(`#note${noteId} .note-edit-toolbar`);
	const state = (sel) => tb.locator(sel).first().evaluate((b) => ({ on: !b.disabled && getComputedStyle(b).pointerEvents !== 'none', op: Number(getComputedStyle(b).opacity) }));
	for (const sel of ['.btn-bold', '.btn-trash', '.btn-move', '.btn-attachment', '.btn-save']) {
		const s = await state(sel);
		ctx.ok(!s.on && s.op < 1, `${label}: ${sel} gesperrt und ausgegraut`, JSON.stringify(s));
	}
	const allowed = ['.btn-reminder', '.btn-snapshot', '.btn-download'];
	if (await tb.locator('.btn-search-replace').count()) allowed.push('.btn-search-replace');
	if (material) allowed.push('.btn-duplicate');
	for (const sel of allowed) {
		const s = await state(sel);
		ctx.ok(s.on && s.op === 1, `${label}: ${sel} bedienbar, nicht ausgegraut`, JSON.stringify(s));
	}
}
