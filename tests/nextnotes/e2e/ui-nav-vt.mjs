// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// Zusatz 7 (UI-nav-mobil): Seitenwechsel per View Transition – Kopf- und Icon-Leiste ohne Animation, Inhaltsfläche beim ersten Bild deckend,
// nur die Wurzel (Inhalt) blendet; reduzierte Bewegung: keine Transition.
// Gegenprobe: VT_STRIP=1 entfernt die Regeln „Kopf-/Icon-Leiste stehen still“ aus nextnotes-layout.css → muss rot werden.
import { runSpec } from './lib/harness.mjs';

const INIT = () => {
	const s = { vt: null, anims: [], surface: null, pseudo: null };
	window.__nnVt = s;
	window.addEventListener('pagereveal', (e) => {
		s.vt = !!e.viewTransition;
		const main = document.querySelector('#nextnotes-root > #right_pane, #nextnotes-root > .nn-page-main');
		if (main) { const c = getComputedStyle(main); s.surface = { opacity: c.opacity, bg: c.backgroundColor }; }
		if (e.viewTransition) e.viewTransition.ready.then(() => {
			const st = (p) => getComputedStyle(document.documentElement, p);
			s.pseudo = { oldHeader: st('::view-transition-old(nn-header)').display, oldRail: st('::view-transition-old(nn-rail)').display };
			// Gegen Leerlauf: Kopf- und Icon-Leiste sind während des Übergangs wirklich eigene Gruppen
			s.names = { header: getComputedStyle(document.getElementById('header')).viewTransitionName, rail: getComputedStyle(document.getElementById('icon_sidebar')).viewTransitionName };
			s.anims = document.getAnimations().map((a) => a.effect?.pseudoElement).filter(Boolean);
		}).catch(() => {});
	});
};

await runSpec(import.meta.url, async (ctx, page, context) => {
	await context.addInitScript(INIT);
	if (process.env.VT_STRIP) {
		ctx.info('Gegenprobe: Stillstand-Regeln für Kopf-/Icon-Leiste entfernt');
		await context.route(/\/nextnotes\/css\/nextnotes-layout\.css/, async (route) => {
			const r = await route.fetch();
			const body = (await r.text()).replace(/::view-transition-group\(nn-header\),[\s\S]*?display:\s*none;\s*\}/, '');
			await route.fulfill({ response: r, body });
		});
	}
	await page.setViewportSize({ width: 1400, height: 900 });
	await page.goto(ctx.appUrl('dashboard.php'), { waitUntil: 'networkidle' });
	for (const [btn, name] of [['#iconSidebarTasksBtn', 'Aufgaben'], ['#iconSidebarHomeBtn', 'Hauptansicht'], ['#iconSidebarDiaryBtn', 'Tagebuch'], ['#iconSidebarSettingsBtn', 'Einstellungen']]) {
		await page.click(btn);
		await page.waitForLoadState('networkidle');
		await page.waitForTimeout(300);
		const s = await page.evaluate(() => window.__nnVt);
		ctx.ok(s.vt === true, `${name}: View Transition`);
		const a = s.anims.join(' ');
		ctx.ok(s.names && s.names.header === 'nn-header' && s.names.rail === 'nn-rail', `${name}: Kopf-/Icon-Leiste eigene Gruppen im Übergang`, JSON.stringify(s.names));
		ctx.ok(!/nn-header|nn-rail/.test(a), `${name}: Kopf- und Icon-Leiste ohne Animation`, a);
		ctx.ok(s.pseudo && s.pseudo.oldHeader === 'none' && s.pseudo.oldRail === 'none', `${name}: altes Bild von Kopf-/Icon-Leiste nicht gezeigt`, JSON.stringify(s.pseudo));
		ctx.ok(/view-transition-(old|new)\(root\)/.test(a), `${name}: Inhalt (Wurzel) blendet`, a);
		const bg = s.surface && s.surface.bg.match(/[\d.]+/g);
		ctx.ok(s.surface && s.surface.opacity === '1' && bg && (bg[3] === undefined || Number(bg[3]) === 1), `${name}: Inhaltsfläche beim ersten Bild deckend`, JSON.stringify(s.surface));
	}
	await page.emulateMedia({ reducedMotion: 'reduce' });
	await page.click('#iconSidebarTasksBtn');
	await page.waitForLoadState('networkidle');
	ctx.ok((await page.evaluate(() => window.__nnVt.vt)) === false, 'reduzierte Bewegung: keine View Transition');
	ctx.assertClean('vt');
});
