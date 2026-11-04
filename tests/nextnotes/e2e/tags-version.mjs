// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// M4g-fix (P27, Review M4g-r1 Hinweis 6): Ordner-Tags und offene Notiz. Ohne fremde Änderung übernimmt die Notiz die neue Version
// (Speichern 200); hat B die Notiz vorher geändert, bleibt die alte Basis und A bekommt beim Speichern 409 (B bleibt erhalten).
import { runSpec } from './lib/harness.mjs';
import { apiClient } from './lib/api.mjs';
import { share } from './lib/docker.mjs';

const RUN = `${process.argv[3]}${Date.now().toString(36)}`;

await runSpec(import.meta.url, async (ctx, page) => {
	if (ctx.theme !== 'light') {
		ctx.info('nur hell (Logik ohne Theme)');
		return;
	}
	const api = apiClient(ctx, page.request);
	const apiB = apiClient(ctx, page.request, process.env.NN_USER2, process.env.NN_PASS2);
	const WS = await api.workspace(`Tags ${RUN}`);
	const fid = await api.folder(WS, `Ordner ${RUN}`);
	const id = await api.note(WS, `Offen ${RUN}`, fid, '<p>start</p>');
	share(ctx.base, process.env.NN_USER, WS, process.env.NN_USER2, 'write');

	const m0 = ctx.mark();
	await page.goto(ctx.appUrl(`index.php?workspace=${encodeURIComponent(WS)}&note=${id}`), { waitUntil: 'networkidle' });
	await page.locator(`#entry${id}`).waitFor({ state: 'visible', timeout: 15000 });
	// Basisversion erst nach dem ersten Abgleich bekannt (ohne Basis speichert der Editor ungeprüft)
	await page.waitForFunction((n) => !!window.getLiveNoteContentVersion(n), String(id), { timeout: 20000 });

	// Ordner-Tag setzen, refreshOpenNotesTags mit der Antwort, dann mit der Basis des Tabs speichern; ein Abgleich dazwischen = neuer Versuch
	const tagThenSave = (tag, text) => page.evaluate(async ([n, f, tg, txt]) => {
		const base = window.getLiveNoteContentVersion(n);
		const r = await fetch(`/api/v1/folders/${f}/tags`, {
			method: 'POST', credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
			body: JSON.stringify({ tags: [tg], workspace: document.body.getAttribute('data-workspace') || '', include_subfolders: true }),
		});
		const data = await r.json();
		if (window.getLiveNoteContentVersion(n) !== base) return { retry: true };
		window.refreshOpenNotesTags(data.versions);
		const v = (data.versions || {})[n] || {};
		const after = window.getLiveNoteContentVersion(n);
		const s = await fetch(`/api/v1/notes/${n}`, {
			method: 'PATCH', credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
			body: JSON.stringify({ content: `<p>${txt}</p>`, if_version: after }),
		});
		return { tagStatus: r.status, base, from: v.from, to: v.to, after, saveStatus: s.status };
	}, [String(id), fid, tag, text]);
	const attempt = async (tag, text, before) => {
		for (let i = 0; i < 3; i++) {
			if (before) await before();
			const res = await tagThenSave(tag, text);
			if (!res.retry) return res;
			ctx.info('Abgleich dazwischen, Wiederholung');
		}
		throw new Error('dreimal Abgleich dazwischen');
	};

	// 1. Ohne fremde Änderung: Basis = from → to übernommen, Speichern 200
	const a = await attempt(`a${RUN}`, 'A ohne Konflikt');
	ctx.ok(a.tagStatus === 200, 'Ordner-Tag: 200', String(a.tagStatus));
	ctx.ok(!!a.from && a.base === a.from, 'ohne fremde Änderung: Basis = Version vor dem Tag', JSON.stringify(a));
	ctx.ok(a.after === a.to && a.from !== a.to, 'ohne fremde Änderung: neue Version übernommen', JSON.stringify(a));
	ctx.ok(a.saveStatus === 200, 'ohne fremde Änderung: Speichern 200 (kein 409)', String(a.saveStatus));
	// eigene Speicherung gibt die neue Basis (wie der Editor)
	const cur = await api.must('GET', `/notes/${id}?workspace=${encodeURIComponent(WS)}`);
	await page.evaluate(([n, v]) => window.setLiveNoteContentVersion(n, v), [String(id), cur.note.version]);

	// 2. B ändert die Notiz, dann setzt A einen Ordner-Tag → Basis bleibt, Speichern 409, Text von B bleibt
	const off = ctx.allow('status', new RegExp(`^409 PATCH .*/notes/${id}$`), 'erwartet: Konflikt nach fremder Änderung');
	const offC = ctx.allow('console', /409|Conflict/, 'Browser loggt erwarteten 409');
	const b = await attempt(`b${RUN}`, 'A überschreibt', async () => {
		await apiB.must('PATCH', `/notes/${id}`, { content: `<p>B ${RUN} ${Date.now()}</p>` });
	});
	ctx.ok(b.tagStatus === 200, 'Ordner-Tag nach fremder Änderung: 200', String(b.tagStatus));
	ctx.ok(!!b.from && b.base !== b.from, 'fremde Änderung: Basis ≠ Version vor dem Tag', JSON.stringify(b));
	ctx.ok(b.after === b.base, 'fremde Änderung: Basis nicht übernommen', JSON.stringify(b));
	ctx.ok(b.saveStatus === 409, 'fremde Änderung: Speichern 409', String(b.saveStatus));
	const kept = await api.must('GET', `/notes/${id}?workspace=${encodeURIComponent(WS)}`);
	ctx.ok(String(kept.note.content).includes(`B ${RUN}`), 'Änderung von B bleibt erhalten', String(kept.note.content).slice(0, 120));
	ctx.assertClean('Ordner-Tags und offene Notiz', m0);
	off();
	offC();
});
