// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// Performance-Grenzen (Plan M3 r2 §3.4). Grenzen ändert nur der Lead.
// Seiten: NN_BUDGET_PATH (kommagetrennt; Standard index.php und ab M4i dashboard.php, Plan M4 M4i).
// M5e (Plan M5 §3 M5e): Hauptansicht mit 20 Teams/100 Team-Workspaces: API-Requests unverändert, TTFB ≤ +30 ms (NN_BUDGET_TEAMS=0 aus).
import { gzipSync } from 'node:zlib';
import { runSpec, APP_SEGMENT } from './lib/harness.mjs';
import { dropTeams, seedBudgetTeams } from './lib/docker.mjs';

const KB = 1000; // Grenzen in KB = 1 000 Byte (strenger als KiB)
const LIMIT_JS = 1190 * KB;
const LIMIT_CSS = 230 * KB;
const LIMIT_API = 8;
const LIMIT_TTFB_MS = 500;
const TEAMS = 20;
const TEAM_WS_PER_TEAM = 5;
const LIMIT_TEAMS_TTFB_DELTA_MS = 30;
// Bekannte doppelte API-Requests: nur mit Begründung und Slice, in dem sie behoben werden
const KNOWN_DUPLICATES = {};

await runSpec(import.meta.url, async (ctx, page, context) => {
	for (const path of (process.env.NN_BUDGET_PATH ?? 'index.php,dashboard.php').split(',').map((p) => p.trim()).filter(Boolean)) {
		ctx.info(`=== Budget ${path}`);
		const m = ctx.mark();
		const resp = await page.goto(ctx.appUrl(path), { waitUntil: 'networkidle' });
		ctx.ok(resp?.status() === 200, `${path} HTTP 200`, `HTTP ${resp?.status()}`);
		await page.waitForTimeout(500);

		const rs = ctx.responses.slice(m.r);
		const isApp = (u) => new URL(u).pathname.includes(APP_SEGMENT) || /\/(custom_)?apps\/nextnotes\//.test(new URL(u).pathname);
		async function gz(type) {
			let raw = 0;
			let packed = 0;
			const files = [];
			for (const r of rs.filter((x) => x.type === type && isApp(x.url) && x.status === 200)) {
				const body = await r.resp.body();
				const g = gzipSync(body, { level: 9 }).length;
				raw += body.length;
				packed += g;
				files.push([new URL(r.url).pathname.split('/nextnotes/')[1], g]);
			}
			return { raw, packed, files };
		}
		const js = await gz('script');
		const css = await gz('stylesheet');
		ctx.info(`JS  ${js.files.length} Dateien, roh ${(js.raw / KB).toFixed(0)} KB, gzip -9 ${(js.packed / KB).toFixed(1)} KB (Grenze ${LIMIT_JS / KB})`);
		for (const [f, g] of js.files.sort((a, b) => b[1] - a[1])) ctx.info(`    ${(g / KB).toFixed(1).padStart(7)} KB ${f}`);
		ctx.info(`CSS ${css.files.length} Dateien, roh ${(css.raw / KB).toFixed(0)} KB, gzip -9 ${(css.packed / KB).toFixed(1)} KB (Grenze ${LIMIT_CSS / KB})`);
		for (const [f, g] of css.files.sort((a, b) => b[1] - a[1])) ctx.info(`    ${(g / KB).toFixed(1).padStart(7)} KB ${f}`);
		ctx.ok(js.files.length > 0 && js.packed <= LIMIT_JS, `${path}: JS gzip ≤ ${LIMIT_JS / KB} KB`, `${(js.packed / KB).toFixed(1)} KB`);
		ctx.ok(css.files.length > 0 && css.packed <= LIMIT_CSS, `${path}: CSS gzip ≤ ${LIMIT_CSS / KB} KB`, `${(css.packed / KB).toFixed(1)} KB`);

		// Lazy-Bibliotheken beim Seitenaufbau nicht geladen (KaTeX-CSS ist laut Plan §1.2 Teil der Seite)
		const lazy = ctx.requests.slice(m.q).filter((r) => /mermaid|excalidraw|katex[^/]*\.js|katex\/fonts/i.test(new URL(r.url).pathname));
		ctx.ok(lazy.length === 0, `${path}: Mermaid/KaTeX-JS/-Fonts/Excalidraw: 0 Requests beim Seitenaufbau`, lazy.map((r) => r.url).join(' | '));

		// API-Requests bis networkidle
		const api = ctx.requests.slice(m.q).filter((r) => /\/api\/v1\//.test(new URL(r.url).pathname));
		const keyOf = (r) => `${r.method} ${new URL(r.url).pathname.replace(/^.*\/api\/v1\//, '')}${new URL(r.url).search}`;
		ctx.info(`API-Requests bis networkidle: ${api.length}`);
		for (const r of api) ctx.info(`    ${keyOf(r)}`);
		const counts = {};
		for (const r of api) counts[keyOf(r)] = (counts[keyOf(r)] ?? 0) + 1;
		const dups = Object.entries(counts).filter(([, n]) => n > 1);
		for (const [k, n] of dups) if (KNOWN_DUPLICATES[k]) ctx.info(`WARN bekannt doppelt (${n}×): ${k} – ${KNOWN_DUPLICATES[k]}`);
		const newDups = dups.filter(([k]) => !KNOWN_DUPLICATES[k]);
		ctx.ok(newDups.length === 0, `${path}: keine (unbekannten) doppelten API-Requests`, newDups.map(([k, n]) => `${n}× ${k}`).join(' | '));
		ctx.ok(api.length <= LIMIT_API, `${path}: API-Requests ≤ ${LIMIT_API}`, `${api.length}`);
		ctx.ok(!api.some((r) => /\/system\/i18n/.test(r.url)), `${path}: kein /system/i18n`);
		ctx.assertClean(`${path}: Seitenaufbau`, m);

		// TTFB warm, Median aus 5
		const ttfb = [];
		for (let i = 0; i < 5; i++) {
			await page.goto(ctx.appUrl(path), { waitUntil: 'load' });
			ttfb.push(await page.evaluate(() => { const n = performance.getEntriesByType('navigation')[0]; return n.responseStart - n.requestStart; }));
		}
		ttfb.sort((a, b) => a - b);
		ctx.info(`TTFB ms: ${ttfb.map((v) => v.toFixed(0)).join(', ')}`);
		ctx.ok(ttfb[2] <= LIMIT_TTFB_MS, `${path}: TTFB Median ≤ ${LIMIT_TTFB_MS} ms`, `${ttfb[2].toFixed(0)} ms`);

		// Zweiter Aufruf: App-Assets aus dem Cache (kein Body übertragen)
		await page.goto(ctx.appUrl(path), { waitUntil: 'networkidle' });
		const entries = await page.evaluate(() => performance.getEntriesByType('resource')
			.filter((e) => /\/nextnotes\/(js|css)\//.test(new URL(e.name).pathname))
			.map((e) => ({ url: e.name, transfer: e.transferSize, body: e.encodedBodySize })));
		const fetched = entries.filter((e) => e.transfer > 0 && e.transfer >= e.body);
		ctx.ok(entries.length > 0 && fetched.length === 0, `${path}: warmer Cache: ${entries.length} App-Assets ohne erneute Übertragung`, fetched.map((e) => `${e.url} ${e.transfer}`).join(' | '));
	}

	if ((process.env.NN_BUDGET_TEAMS ?? '1') === '1') {
		// Median aus 15 warmen Aufrufen; API-Requests eines Aufbaus bis networkidle
		const measure = async (label) => {
			const m = ctx.mark();
			await page.goto(ctx.appUrl('index.php'), { waitUntil: 'networkidle' });
			await page.waitForTimeout(500);
			const api = ctx.requests.slice(m.q).filter((r) => /\/api\/v1\//.test(new URL(r.url).pathname))
				.map((r) => `${r.method} ${new URL(r.url).pathname.replace(/^.*\/api\/v1\//, '')}`).sort();
			ctx.assertClean(`Teams-Budget ${label}: Seitenaufbau`, m);
			const ttfb = [];
			for (let i = 0; i < 15; i++) {
				await page.goto(ctx.appUrl('index.php'), { waitUntil: 'load' });
				ttfb.push(await page.evaluate(() => { const n = performance.getEntriesByType('navigation')[0]; return n.responseStart - n.requestStart; }));
			}
			ttfb.sort((a, b) => a - b);
			ctx.info(`Teams-Budget ${label}: TTFB Median ${ttfb[7].toFixed(0)} ms (${ttfb.map((v) => v.toFixed(0)).join(', ')}); API ${api.length}: ${api.join(' | ')}`);
			return { ttfb: ttfb[7], api };
		};
		ctx.info(`=== Budget index.php mit ${TEAMS} Teams / ${TEAMS * TEAM_WS_PER_TEAM} Team-Workspaces`);
		const before = await measure('ohne Teams');
		const ids = seedBudgetTeams(ctx.base, process.env.NN_USER, TEAMS, TEAM_WS_PER_TEAM);
		try {
			const auth = `Basic ${Buffer.from(`${process.env.NN_USER}:${process.env.NN_PASS}`).toString('base64')}`;
			const list = await (await page.request.get(ctx.appUrl('api/v1/workspaces'), { headers: { Authorization: auth, 'OCS-APIRequest': 'true' } })).json();
			const teamWs = (list.workspaces ?? []).filter((w) => w.team).length;
			ctx.ok(ids.length === TEAMS && teamWs >= TEAMS * TEAM_WS_PER_TEAM, `Teams-Budget: ${TEAMS} Teams, ${TEAMS * TEAM_WS_PER_TEAM} Team-Workspaces sichtbar`, `${ids.length} Teams, ${teamWs} Team-Workspaces`);
			const after = await measure('mit Teams');
			ctx.ok(JSON.stringify(after.api) === JSON.stringify(before.api), 'Teams-Budget: API-Requests unverändert', `${before.api.length} → ${after.api.length}`);
			ctx.ok(after.ttfb <= before.ttfb + LIMIT_TEAMS_TTFB_DELTA_MS, `Teams-Budget: TTFB ≤ +${LIMIT_TEAMS_TTFB_DELTA_MS} ms`,
				`${before.ttfb.toFixed(0)} → ${after.ttfb.toFixed(0)} ms (${(after.ttfb - before.ttfb).toFixed(0)})`);
		} finally {
			dropTeams(ctx.base, 'nn-budget-owner', ids);
		}
	}
	ctx.assertClean('gesamter Lauf');
});
