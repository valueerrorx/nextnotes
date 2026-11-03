// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// M3-fix4 (5), B54: Trefferanzeige von „Suchen und Ersetzen“ ist Text, nie „[object Object]“; beim Laden eingefangenes t() liefert Strings.
import { runSpec } from './lib/harness.mjs';
import { apiClient } from './lib/api.mjs';

await runSpec(import.meta.url, async (ctx, page) => {
	const api = apiClient(ctx, page.request);
	const WS = await api.defaultWorkspace();
	const nid = await api.note(WS, `Suche ${Date.now().toString(36)}`, null, '<p>Hallo Welt Hallo du Hallo</p>');
	const m = ctx.mark();
	await page.goto(ctx.appUrl(`index.php?workspace=${encodeURIComponent(WS)}&note=${nid}`), { waitUntil: 'networkidle' });
	await page.waitForTimeout(600);

	// globale tr aus events-utils.js/attachments.js (beim Laden gebunden)
	const tr = await page.evaluate(() => [typeof window.tr === 'function' ? typeof window.tr('search_replace.results', {}, 'results') : 'fehlt', window.t('common.cancel'), window.t('core', 'Cancel')]);
	ctx.ok(tr[0] === 'string', 'globale tr() liefert String', tr[0]);
	ctx.ok(tr[1] === 'Abbrechen', 'window.t(Schlüssel) übersetzt', tr[1]);
	ctx.ok(typeof tr[2] === 'string', 'NC-Aufruf t(app, text) liefert String', String(tr[2]));

	await page.locator(`#entry${nid}`).click();
	await page.locator('[data-action="open-search-replace-modal"]:visible').first().click();
	const input = page.locator(`#searchInput${nid}`);
	await input.waitFor({ state: 'visible', timeout: 5000 });
	await input.fill('Hallo');
	await page.waitForTimeout(600);
	const count = (await page.locator(`#searchCount${nid}`).textContent()) ?? '';
	ctx.ok(/^3 Ergebnisse$/.test(count.trim()), 'Trefferanzeige „3 Ergebnisse“', count);
	ctx.ok(!/object/i.test(count), 'kein [object Object]', count);
	await input.fill('Welt');
	await page.waitForTimeout(600);
	const one = (await page.locator(`#searchCount${nid}`).textContent()) ?? '';
	ctx.ok(/^1 Ergebnis$/.test(one.trim()), 'Trefferanzeige „1 Ergebnis“', one);
	await input.fill('xyzxyz');
	await page.waitForTimeout(600);
	const none = (await page.locator(`#searchCount${nid}`).textContent()) ?? '';
	ctx.ok(/^Keine Ergebnisse$/.test(none.trim()), 'Trefferanzeige „Keine Ergebnisse“', none);
	ctx.assertClean('Suchen', m);
});
