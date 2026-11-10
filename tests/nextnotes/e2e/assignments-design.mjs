// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// UI-asgdesign (SPEC §4.3 Nr. 35, Thomas 2026-11-10): Aufgaben-Ansicht mit Karten (Fläche bzw. Rahmen, Radius) für Aufgaben und Abgaben,
// Meta-Leiste als Pillen mit Icons, Fälligkeit farbig (überfällig Fehler-, bald Warnfarbe), Schüler-Avatar (NC-Kernroute), Verantwortlich mit Initialen.
// Lehrkraft-Liste, Aufgabendetail mit Abgaben, Schülersicht; 1400 und 390 px ohne Überlauf; 0 CSP/Konsole.
import { runSpec } from './lib/harness.mjs';
import { apiClient } from './lib/api.mjs';
import { createTeam, ensureUser, setAssignmentDue } from './lib/docker.mjs';

const RUN = `${process.argv[3]}${Date.now().toString(36)}`;
const A = process.env.NN_USER;
const B = process.env.NN_USER2;
const C = `nnd2${process.argv[3]}`;
const H = 3600;
const now = () => Math.floor(Date.now() / 1000);

// Karte: Fläche oder Rahmen, Radius > 0
const CARD = (sel) => [...document.querySelectorAll(sel)].map((e) => {
	const s = getComputedStyle(e);
	const bg = s.backgroundColor;
	return { fill: bg !== 'rgba(0, 0, 0, 0)' && bg !== 'transparent', border: parseFloat(s.borderTopWidth) >= 1 && s.borderTopStyle !== 'none', radius: parseFloat(s.borderTopLeftRadius) };
});
// Überlauf: Seite, Inhaltsfläche und jedes Element der Aufgaben-Ansicht innerhalb der Inhaltsfläche
const OVERFLOW = () => {
	const main = document.querySelector('main.nn-page-main');
	const mr = main.getBoundingClientRect();
	// nur für Screenreader (clip-path) zählt nicht
	const srOnly = (e) => { for (let x = e; x; x = x.parentElement) if (getComputedStyle(x).clipPath.startsWith('inset')) return true; return false; };
	const out = [...document.querySelectorAll('.nn-asg *')].filter((e) => e.getClientRects().length && !e.closest('[hidden]') && !srOnly(e)).filter((e) => {
		const r = e.getBoundingClientRect();
		return r.width > 0 && (r.right > mr.right + 1 || r.left < mr.left - 1);
	}).map((e) => e.className || e.tagName);
	return { page: document.scrollingElement.scrollWidth - window.innerWidth, main: main.scrollWidth - main.clientWidth, out: out.slice(0, 5) };
};
// Farbe eines Theme-Werts im Seitenkontext
const COLOR = (v) => {
	const p = document.createElement('span');
	p.style.color = `var(${v})`;
	document.querySelector('#nextnotes-root').appendChild(p);
	const c = getComputedStyle(p).color;
	p.remove();
	return c;
};

await runSpec(import.meta.url, async (ctx, page, context, browser) => {
	ensureUser(ctx.base, C, 'Clara Design', process.env.NN_PASS2);
	const api = apiClient(ctx, page.request);
	const ctxB = await browser.newContext({ viewport: { width: 1400, height: 900 }, locale: 'de-DE' });
	await ctx.attach(ctxB);
	const apiB = apiClient(ctx, ctxB.request, B, process.env.NN_PASS2);

	// Daten: Team (L, S1, S2), Team-Workspace; A1 ausgeteilt (S1 gibt ab), A2 Entwurf bald fällig, A3 überfällig
	const teamId = createTeam(ctx.base, A, `Design ${RUN}`, { [B]: 1, [C]: 1 });
	const TW = `Design ${RUN}`;
	await api.must('POST', '/workspaces', { name: TW, team: teamId });
	const src = await api.note(TW, `Blatt ${RUN}`, null, `<p>Arbeitsblatt ${RUN}</p>`);
	const mk = async (title, due, points) => (await api.must('POST', '/assignments', { workspace: TW, title, due_at: due, source_note_id: src, ...(points ? { max_points: points } : {}) })).assignment.id;
	const a1 = await mk(`Lesetagebuch mit einem recht langen Titel für den Umbruch ${RUN}`, now() + 7 * 24 * H, 10);
	const a2 = await mk(`Vokabeln ${RUN}`, now() + 12 * H);
	const a3 = await mk(`Referat ${RUN}`, now() + 2 * H);
	await api.must('POST', `/assignments/${a1}/distribute`);
	await api.must('POST', `/assignments/${a3}/distribute`);
	setAssignmentDue(ctx.base, a3, now() - 60);
	const s1 = (await api.must('GET', `/assignments/${a1}/submissions`)).submissions.find((s) => s.student_uid === B);
	await apiB.must('POST', `/submissions/${s1.id}/submit`);

	const listUrl = ctx.appUrl(`assignments.php?workspace=${encodeURIComponent(TW)}`);
	const ready = async (p) => p.locator('#nnAsgList[aria-busy="false"]').waitFor({ state: 'attached', timeout: 10000 });
	const noOverflow = async (p, label) => {
		const o = await p.evaluate(OVERFLOW);
		ctx.ok(o.page <= 1 && o.main <= 1 && o.out.length === 0, `${label}: kein Überlauf`, JSON.stringify(o));
	};
	const colorOf = async (p, sel) => p.locator(sel).first().evaluate((e) => getComputedStyle(e).color).catch(() => '');

	// ---------- 1. Lehrkraft-Liste (1400 px) ----------
	let m = ctx.mark();
	await page.goto(listUrl, { waitUntil: 'networkidle' });
	await ready(page);
	await page.locator(`.nn-asg-item[data-assignment-id="${a1}"]`).waitFor({ timeout: 10000 });
	await ctx.screenshot(page, 'teacher-list');
	const cards = await page.evaluate(CARD, '.nn-asg-item');
	ctx.ok(cards.length === 3 && cards.every((c) => (c.fill || c.border) && c.radius >= 4), 'Liste: Aufgaben als Karten (Fläche/Rahmen, Radius)', JSON.stringify(cards));
	for (const id of [a1, a2, a3]) {
		const pills = await page.locator(`.nn-asg-item[data-assignment-id="${id}"] .nn-asg-meta .nn-asg-pill`).count();
		ctx.ok(pills >= 2, `Liste ${id}: Meta-Pillen (Frist, Klasse …)`, String(pills));
	}
	ctx.ok(await page.locator(`.nn-asg-item[data-assignment-id="${a1}"] .nn-asg-pill.nn-asg-due .lucide`).count() === 1, 'Frist-Pille mit Icon');
	const err = await page.evaluate(COLOR, '--color-error-text');
	const warn = await page.evaluate(COLOR, '--color-warning-text');
	const due3 = await colorOf(page, `.nn-asg-item[data-assignment-id="${a3}"] .nn-asg-due`);
	ctx.ok(await page.locator(`.nn-asg-item[data-assignment-id="${a3}"] .nn-asg-due.nn-asg-due-passed`).count() === 1 && due3 === err, 'überfällig: Fehlerfarbe', `${due3} / ${err}`);
	const due2 = await colorOf(page, `.nn-asg-item[data-assignment-id="${a2}"] .nn-asg-due`);
	ctx.ok(await page.locator(`.nn-asg-item[data-assignment-id="${a2}"] .nn-asg-due.nn-asg-due-soon`).count() === 1 && due2 === warn, 'bald fällig: Warnfarbe', `${due2} / ${warn}`);
	const due1 = await page.locator(`.nn-asg-item[data-assignment-id="${a1}"] .nn-asg-due`).getAttribute('class');
	ctx.ok(!/passed|soon/.test(due1), 'später fällig: neutral', due1);
	ctx.ok(/Fällig/.test(await page.locator(`.nn-asg-item[data-assignment-id="${a1}"]`).innerText()), 'Frist-Text bleibt');
	// Hover hebt die Karte hervor
	const before = await page.locator(`.nn-asg-item[data-assignment-id="${a1}"] .nn-asg-item-main`).evaluate((e) => getComputedStyle(e).backgroundColor);
	await page.locator(`.nn-asg-item[data-assignment-id="${a1}"] .nn-asg-item-main`).hover();
	await page.waitForTimeout(250);
	const after = await page.locator(`.nn-asg-item[data-assignment-id="${a1}"] .nn-asg-item-main`).evaluate((e) => getComputedStyle(e).backgroundColor);
	ctx.ok(before !== after, 'Karte: Hover-Zustand', `${before} → ${after}`);
	await page.mouse.move(0, 0);
	await noOverflow(page, 'Liste 1400');
	ctx.assertClean('Lehrkraft-Liste', m);

	// ---------- 2. Aufgabendetail mit Abgaben (1400 px) ----------
	m = ctx.mark();
	await page.locator(`.nn-asg-item[data-assignment-id="${a1}"] .nn-asg-item-main`).click();
	await page.locator('.nn-asg-table tbody tr').nth(1).waitFor({ timeout: 10000 });
	await page.waitForFunction(() => [...document.querySelectorAll('.nn-asg-avatar img')].every((i) => i.complete), null, { timeout: 10000 }).catch(() => {});
	await ctx.screenshot(page, 'teacher-detail');
	const subCards = await page.evaluate(() => [...document.querySelectorAll('.nn-asg-table tbody tr')].map((tr) => {
		const first = getComputedStyle(tr.cells[0]);
		const bg = first.backgroundColor;
		return { fill: bg !== 'rgba(0, 0, 0, 0)', radius: parseFloat(first.borderTopLeftRadius) };
	}));
	ctx.ok(subCards.length === 2 && subCards.every((c) => c.fill && c.radius >= 4), 'Abgaben: Karten mit Fläche und Radius', JSON.stringify(subCards));
	ctx.ok(await page.locator('.nn-asg-table .nn-asg-chip-submitted').count() === 1 && await page.locator('.nn-asg-table .nn-asg-chip-assigned').count() === 1, 'Abgaben: Status-Pillen');
	ctx.ok(await page.locator('.nn-asg-table tbody tr.nn-asg-sub-attention').count() === 1, 'Abgaben: abgegebene hervorgehoben');
	const avatars = await page.evaluate(() => [...document.querySelectorAll('.nn-asg-table .nn-asg-avatar')].map((a) => ({ img: !!a.querySelector('img') && a.querySelector('img').naturalWidth > 0, text: a.textContent })));
	ctx.ok(avatars.length === 2 && avatars.every((a) => a.img), 'Abgaben: NC-Avatar je Schüler', JSON.stringify(avatars));
	ctx.ok(await page.locator('.nn-asg-detail > .nn-asg-meta .nn-asg-pill').count() >= 3, 'Detail: Meta-Pillen (Frist, Klasse, Punkte, Verantwortlich)');
	const owner = page.locator('.nn-asg-detail > .nn-asg-meta .nn-asg-person');
	ctx.ok(await owner.count() === 1 && /\S/.test(await owner.locator('.nn-asg-avatar').innerText().catch(() => '')), 'Detail: Verantwortlich mit Initialen', await owner.innerText().catch(() => ''));
	await noOverflow(page, 'Detail 1400');
	ctx.assertClean('Detail', m);

	// ---------- 3. Mobil 390 px: Liste und Detail ----------
	m = ctx.mark();
	await page.setViewportSize({ width: 390, height: 844 });
	await page.goto(listUrl, { waitUntil: 'networkidle' });
	await ready(page);
	await page.locator(`.nn-asg-item[data-assignment-id="${a1}"]`).waitFor({ timeout: 10000 });
	await ctx.screenshot(page, 'teacher-list-mobile');
	const full = await page.evaluate(() => {
		const list = document.querySelector('.nn-asg-items').getBoundingClientRect();
		return [...document.querySelectorAll('.nn-asg-item')].map((e) => Math.abs(e.getBoundingClientRect().width - list.width));
	});
	ctx.ok(full.length === 3 && full.every((d) => d < 1), 'mobil: Karten volle Breite', JSON.stringify(full));
	await noOverflow(page, 'Liste 390');
	await page.goto(`${listUrl}&assignment=${a1}`, { waitUntil: 'networkidle' });
	await page.locator('.nn-asg-table tbody tr').nth(1).waitFor({ timeout: 10000 });
	await page.waitForFunction(() => [...document.querySelectorAll('.nn-asg-avatar img')].every((i) => i.complete), null, { timeout: 10000 }).catch(() => {});
	await ctx.screenshot(page, 'teacher-detail-mobile');
	const rows = await page.evaluate(() => {
		const table = document.querySelector('.nn-asg-table').getBoundingClientRect();
		return [...document.querySelectorAll('.nn-asg-table tbody tr')].map((tr) => {
			const r = tr.getBoundingClientRect();
			const s = getComputedStyle(tr);
			return { dw: Math.abs(r.width - table.width), fill: s.backgroundColor !== 'rgba(0, 0, 0, 0)', radius: parseFloat(s.borderTopLeftRadius) };
		});
	});
	ctx.ok(rows.length === 2 && rows.every((r) => r.dw < 1 && r.fill && r.radius >= 4), 'mobil: Abgaben als Karten volle Breite', JSON.stringify(rows));
	await noOverflow(page, 'Detail 390');
	ctx.assertClean('Mobil', m);
	await page.setViewportSize({ width: 1400, height: 900 });

	// ---------- 4. Schülersicht: Liste und eigene Aufgabe (1400 und 390 px) ----------
	const pb = await ctxB.newPage();
	await ctx.login(pb, B, process.env.NN_PASS2);
	m = ctx.mark();
	await pb.goto(listUrl, { waitUntil: 'networkidle' });
	await ready(pb);
	await pb.locator(`.nn-asg-item[data-assignment-id="${a1}"]`).waitFor({ timeout: 10000 });
	await ctx.screenshot(pb, 'student-list');
	const sCards = await pb.evaluate(CARD, '.nn-asg-item');
	ctx.ok(sCards.length === 2 && sCards.every((c) => (c.fill || c.border) && c.radius >= 4), 'Schüler: Aufgaben als Karten', JSON.stringify(sCards));
	ctx.ok(await pb.locator(`.nn-asg-item[data-assignment-id="${a3}"] .nn-asg-chip-missed`).count() === 1, 'Schüler: Status-Pille „Verpasst“');
	ctx.ok(await pb.locator(`.nn-asg-item[data-assignment-id="${a1}"] .nn-asg-meta .nn-asg-pill`).count() >= 2, 'Schüler: Meta-Pillen');
	await noOverflow(pb, 'Schüler-Liste 1400');
	await pb.locator(`.nn-asg-item[data-assignment-id="${a1}"] .nn-asg-item-main`).click();
	await pb.locator('.nn-asg-open-copy').waitFor({ state: 'visible', timeout: 10000 });
	await ctx.screenshot(pb, 'student-detail');
	ctx.ok(await pb.locator('.nn-asg-detail > .nn-asg-meta .nn-asg-pill').count() >= 3, 'Schüler-Detail: Meta-Pillen');
	await noOverflow(pb, 'Schüler-Detail 1400');
	await pb.setViewportSize({ width: 390, height: 844 });
	await pb.goto(listUrl, { waitUntil: 'networkidle' });
	await ready(pb);
	await pb.locator(`.nn-asg-item[data-assignment-id="${a1}"]`).waitFor({ timeout: 10000 });
	await ctx.screenshot(pb, 'student-list-mobile');
	await noOverflow(pb, 'Schüler-Liste 390');
	ctx.assertClean('Schüler', m);
});
