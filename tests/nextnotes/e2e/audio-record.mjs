// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// M3-fix4 (1): Rechtsklick → Einfügen → „Audio aufnehmen“ mit Fake-Mikrofon: Dialog, Aufnahme, Anhang gespeichert, Player in der Notiz.
// Feature-Policy der Hauptansicht erlaubt das Mikrofon nur für 'self' (ADR 0012 Nachtrag M3-fix4).
import { runSpec } from './lib/harness.mjs';
import { apiClient } from './lib/api.mjs';

const ARGS = ['--use-fake-device-for-media-stream', '--use-fake-ui-for-media-stream'];

await runSpec(import.meta.url, async (ctx, page, context) => {
	const api = apiClient(ctx, page.request);
	const WS = await api.defaultWorkspace();
	const nid = await api.note(WS, `Audio ${Date.now().toString(36)}`, null, '<p>Vor der Aufnahme</p>');
	await context.grantPermissions(['microphone'], { origin: ctx.base });
	const url = ctx.appUrl(`index.php?workspace=${encodeURIComponent(WS)}&note=${nid}`);

	const head = await page.request.get(url);
	const fp = head.headers()['feature-policy'] ?? '';
	ctx.ok(/microphone 'self'/.test(fp) && !/microphone 'none'/.test(fp), 'Hauptansicht: Feature-Policy microphone \'self\'', fp);
	const other = (await page.request.get(ctx.appUrl(`attachments.php?note_id=${nid}&workspace=${encodeURIComponent(WS)}`))).headers()['feature-policy'] ?? '';
	ctx.ok(/microphone 'none'/.test(other), 'Anhang-Seite: Mikrofon bleibt gesperrt', other);

	const m = ctx.mark();
	await page.goto(url, { waitUntil: 'networkidle' });
	await page.waitForTimeout(600);
	ctx.ok(await page.evaluate(() => typeof window.openAudioRecorder === 'function'), 'openAudioRecorder geladen');
	const box = await page.locator(`#entry${nid}`).boundingBox();
	await page.mouse.click(box.x + 40, box.y + 10, { button: 'right' });
	await page.locator('.slash-command-menu [data-command-id="insert"]').hover();
	await page.locator('.slash-command-submenu [data-submenu-id="record-audio"]').click();
	const modal = page.locator('#dictateModal');
	await modal.waitFor({ state: 'visible', timeout: 5000 }).catch(() => {});
	ctx.ok(await modal.isVisible(), 'Dialog „Audio aufnehmen“ sichtbar');
	ctx.ok(await page.locator('#dictateTranscribeBtn').count() === 0, 'kein Transkribieren-Knopf (ADR 0010)');

	await page.locator('#dictateStartBtn').click();
	await page.waitForFunction(() => !document.getElementById('dictateStopBtn').disabled, null, { timeout: 5000 }).catch(() => {});
	await page.waitForTimeout(2200);
	ctx.ok((await page.locator('#dictateTimer').textContent()) !== '0:00', 'Aufnahme läuft (Zeit zählt)', await page.locator('#dictateTimer').textContent());
	ctx.ok(!(await page.locator('#dictateError').isVisible()), 'keine Fehlermeldung', await page.locator('#dictateError').textContent());

	const upload = page.waitForResponse((r) => /\/api\/v1\/notes\/\d+\/attachments$/.test(new URL(r.url()).pathname) && r.request().method() === 'POST', { timeout: 15000 }).catch(() => null);
	await page.locator('#dictateStopBtn').click();
	const resp = await upload;
	ctx.ok([200, 201].includes(resp?.status()), 'Aufnahme als Anhang gespeichert', resp ? String(resp.status()) : 'kein Upload');
	await page.waitForTimeout(1500);
	ctx.ok(!(await modal.isVisible()), 'Dialog geschlossen');
	const player = page.locator(`#entry${nid} iframe[src*="audio_player"], #entry${nid} audio`).first();
	await player.waitFor({ state: 'attached', timeout: 5000 }).catch(() => {});
	ctx.ok(await player.count() === 1, 'Player in der Notiz');
	await ctx.screenshot(page, 'audio-record');

	// gespeichert: Anhang an der Notiz, Inhalt verweist auf den Player
	await page.waitForTimeout(3000);
	const note = await api.must('GET', `/notes/${nid}`);
	const content = String(note.note?.content ?? note.content ?? '');
	ctx.ok(/audio_player|<audio/.test(content), 'Player im gespeicherten Inhalt', content.slice(0, 200));
	const att = await api.must('GET', `/notes/${nid}/attachments`);
	const list = att.attachments ?? att;
	ctx.ok(Array.isArray(list) && list.some((a) => /^audio\//.test(a.file_type ?? '')), 'Audio-Anhang vorhanden', JSON.stringify(list).slice(0, 200));
	ctx.assertClean('Aufnahme', m);
}, { args: ARGS });
