// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// M3b1 (SPEC §4.3 Punkte 8, 10; §11.1 P7, P9, P10; §11.2 B4, B26, B30, B31, B32; ADR 0005): Editor in #right_col.
// „+“ → Bearbeiten → Speichern → Reload; Fragment + Adresszeile (B31); Beacon mit Token, ohne → 412 (B4);
// > 64 KiB beim Schließen (B30); Tippen während Speichern (B32); Sperre/duplizierter Tab nur lesbar (P7);
// eingeschleuste Bedienelemente wirkungslos (B26); Tags mit '; Verschieben-Dialoge. Je Abschnitt 0 CSP/pageerror/Konsole.
import { runSpec } from './lib/harness.mjs';
import { apiClient } from './lib/api.mjs';
import { execFileSync } from 'node:child_process';

// Lesefreigabe hat keine HTTP-Route (Teams ab M5) → Testhilfe im Nextcloud-Container der Instanz (Port der base-url)
function shareRead(base, owner, wsName, target) {
	const port = new URL(base).port;
	const dk = (...a) => execFileSync('docker', a, { encoding: 'utf8' }).trim();
	const web = dk('ps', '--filter', `publish=${port}`, '--format', '{{.Names}}').split('\n')[0];
	const project = dk('inspect', '-f', '{{index .Config.Labels "com.docker.compose.project"}}', web);
	const nc = dk('ps', '--filter', `label=com.docker.compose.project=${project}`, '--format', '{{.Names}}').split('\n')
		.find((c) => { try { dk('exec', c, 'php', '-r', "exit(is_file('/var/www/html/occ') ? 0 : 1);"); return true; } catch { return false; } });
	const php = `require '/var/www/html/lib/base.php';
		$qb = \\OCP\\Server::get(\\OCP\\IDBConnection::class)->getQueryBuilder();
		$id = (int)$qb->select('id')->from('nextnotes_workspaces')->where($qb->expr()->eq('owner_type', $qb->createNamedParameter('user')))
			->andWhere($qb->expr()->eq('owner_id', $qb->createNamedParameter($argv[1])))->andWhere($qb->expr()->eq('name', $qb->createNamedParameter($argv[2])))
			->executeQuery()->fetchOne();
		\\OCP\\Server::get(\\OCA\\NextNotes\\Service\\WorkspaceService::class)->share($argv[1], $id, $argv[3], 'read');
		echo $id;`;
	return dk('exec', '-u', 'www-data', nc, 'php', '-r', php, '--', owner, wsName, target);
}

const RUN = Date.now().toString(36);

await runSpec(import.meta.url, async (ctx, page, context) => {
	const api0 = apiClient(ctx, page.request);
	// SQLite: 503 bei Schreibkonflikt (SPEC M2d) → wiederholen
	const api = { ...api0, async note(...a) {
		for (let i = 0; ; i++) {
			try { return await api0.note(...a); } catch (e) { if (i > 4 || !/503/.test(e.message)) throw e; await page.waitForTimeout(1500); }
		}
	} };
	const WS = await api.defaultWorkspace();
	await api.must('PUT', '/settings/last_opened_workspace', { value: WS });
	const noteUrl = (id) => ctx.appUrl(`index.php?workspace=${encodeURIComponent(WS)}${id ? `&note=${id}` : ''}`);
	const open = async (p, id) => {
		await p.goto(noteUrl(id), { waitUntil: 'networkidle' });
		await p.waitForTimeout(600);
	};
	const content = async (id) => (await api.must('GET', `/notes/${id}`)).note?.content ?? '';
	const waitFor = async (fn, ms = 8000) => {
		for (const end = Date.now() + ms; Date.now() < end; await page.waitForTimeout(300)) if (await fn()) return true;
		return fn();
	};

	// 1. Anlegen über „+“, Bearbeiten, Speichern, Reload
	{
		await open(page);
		const m = ctx.mark();
		await page.click('#sidebarCreateBtn');
		await page.waitForTimeout(300);
		ctx.ok(await page.locator('#create-menu').isVisible(), '„+“-Menü sichtbar');
		await Promise.all([page.waitForLoadState('networkidle'), page.click('#create-menu [data-type="html"]')]);
		await page.waitForTimeout(1200);
		const entry = page.locator('#right_col .noteentry');
		ctx.ok(await entry.count() === 1, 'neue Notiz in #right_col geöffnet', page.url());
		const id = await entry.getAttribute('data-note-id');
		await entry.click();
		await page.keyboard.type(`Hallo ${RUN}`);
		ctx.ok(await waitFor(async () => (await content(id)).includes(`Hallo ${RUN}`)), 'Autosave speichert');
		await page.reload({ waitUntil: 'networkidle' });
		await page.waitForTimeout(500);
		ctx.ok((await page.locator(`#entry${id}`).innerText().catch(() => '')).includes(`Hallo ${RUN}`), 'Text nach Reload da');
		ctx.ok(page.url().includes('/apps/nextnotes/'), 'Reload bleibt in der App', page.url());
		await ctx.screenshot(page, 'editor');
		ctx.assertClean('Anlegen/Bearbeiten', m);
	}

	// 2. Fragment-Laden + Adresszeile (B31/P9)
	{
		const a = await api.note(WS, `Frag A ${RUN}`, null, '<p>AAA</p>');
		const b = await api.note(WS, `Frag B ${RUN}`, null, '<p>BBB</p>');
		await open(page, a);
		const m = ctx.mark();
		await page.click(`.note-list-item a[data-note-id="${b}"]`);
		await page.waitForTimeout(1500);
		const frag = ctx.responses.slice(m.r).find((r) => r.url.includes(`note=${b}`));
		ctx.ok(!!frag && frag.status === 200, 'Fragment-Request 200', frag ? `${frag.status} ${frag.url}` : 'kein Request');
		ctx.ok((await page.locator(`#right_col #entry${b}`).innerText()).includes('BBB'), 'Notiz B per Fragment in #right_col');
		ctx.ok(page.url().includes('/apps/nextnotes/index.php?') && page.url().includes(`note=${b}`), 'B31: Adresszeile in der App', page.url());
		await page.reload({ waitUntil: 'networkidle' });
		ctx.ok(page.url().includes('/apps/nextnotes/') && (await page.locator(`#entry${b}`).count()) === 1, 'B31: Reload zeigt Notiz B in der App', page.url());
		ctx.assertClean('Fragment', m);
	}

	// 3. Schließen < 64 KiB und > 64 KiB (B30)
	for (const [label, size] of [['< 64 KiB', 10], ['> 64 KiB (B30)', 70000]]) {
		const id = await api.note(WS, `Schliessen ${size} ${RUN}`, null, `<p>${'x'.repeat(size)}</p>`);
		const p2 = await context.newPage();
		await open(p2, id);
		await p2.locator(`#entry${id}`).click();
		await p2.keyboard.press('Control+End');
		await p2.keyboard.type(` zu${size}${RUN}`);
		await p2.waitForTimeout(500);
		p2.on('dialog', (d) => d.accept().catch(() => {}));
		await p2.close({ runBeforeUnload: true });
		ctx.ok(await waitFor(async () => (await content(id)).includes(`zu${size}${RUN}`)), `Schließen ${label}: Inhalt gespeichert`);
	}

	// 4. Beacon mit Token (B4), ohne Token → 412
	{
		const id = await api.note(WS, `Beacon ${RUN}`, null, '<p>alt</p>');
		await open(page, id);
		const m = ctx.mark();
		ctx.allow('console', /fetch gesperrt|Emergency fetch/, 'Notfallpfad erzwungen (fetch gesperrt)');
		ctx.allow('pageerror', /fetch gesperrt/, 'Notfallpfad erzwungen (fetch gesperrt)');
		ctx.allow('status', /412 POST .*beacon/, 'Gegenprobe ohne Token');
		await page.evaluate(() => { window.fetch = () => { throw new Error('fetch gesperrt (Test)'); }; });
		await page.locator(`#entry${id}`).click();
		await page.keyboard.type(` beacon${RUN}`);
		await page.waitForTimeout(500);
		const beacon = page.waitForRequest((r) => r.url().includes(`/notes/${id}/beacon`), { timeout: 5000 }).catch(() => null);
		await page.evaluate((nid) => window.emergencySave(nid), String(id));
		const req = await beacon;
		const tok = await page.evaluate(() => document.head.dataset.requesttoken);
		ctx.ok(!!req && (req.postData() || '').includes('name="requesttoken"') && (req.postData() || '').includes(tok), 'B4: Beacon mit Feld requesttoken', req ? req.url() : 'kein Beacon');
		ctx.ok(await waitFor(async () => (await content(id)).includes(`beacon${RUN}`)), 'B4: Beacon speichert');
		const resp = ctx.responses.slice(m.r).find((r) => r.url.includes('/beacon'));
		ctx.ok(resp?.status === 200, 'B4: Beacon HTTP 200', String(resp?.status));
		const noTok = await page.request.post(ctx.appUrl(`api/v1/notes/${id}/beacon`), { multipart: { content: 'x' }, failOnStatusCode: false });
		ctx.ok(noTok.status() === 412, 'B4: Beacon ohne Token → 412', String(noTok.status()));
		ctx.ok(!(await content(id)).startsWith('x'), 'B4: ohne Token nichts gespeichert');
		await page.goto('about:blank');
	}

	// 5. Tippen während laufendem Speichern (B32/P10)
	{
		const id = await api.note(WS, `B32 ${RUN}`, null, '<p>start</p>');
		await open(page, id);
		const m = ctx.mark();
		let delayed = 0;
		const bodies = [];
		page.on('request', (r) => { if (r.method() === 'PATCH' && r.url().includes(`/notes/${id}`)) bodies.push(r.postData() || ''); });
		await page.route(`**/api/v1/notes/${id}`, async (route) => {
			if (route.request().method() === 'PATCH' && delayed++ === 0) await new Promise((r) => setTimeout(r, 2500));
			await route.continue();
		});
		await page.locator(`#entry${id}`).click();
		await page.keyboard.press('Control+End');
		await page.keyboard.type(` eins${RUN}`);
		await page.evaluate(() => document.querySelector('.btn-save')?.click());
		await page.waitForTimeout(400);
		ctx.ok(delayed >= 1, 'B32: erstes Speichern läuft (verzögert)', String(delayed));
		await page.keyboard.type(` zwei${RUN}`);
		ctx.ok(await waitFor(async () => (await content(id)).includes(`zwei${RUN}`), 12000), 'B32: Tippen während Speichern wird gespeichert');
		ctx.ok(bodies.length >= 2 && bodies.slice(1).some((b) => b.includes(`zwei${RUN}`)), 'B32: zweiter PATCH mit neuem Text', `${bodies.length} PATCH`);
		await page.unroute(`**/api/v1/notes/${id}`);
		await page.reload({ waitUntil: 'networkidle' });
		const txt = await page.locator(`#entry${id}`).innerText();
		ctx.ok(txt.includes(`eins${RUN}`) && txt.includes(`zwei${RUN}`), 'B32: nach Reload beide Teile da', txt.slice(0, 120));
		ctx.assertClean('B32', m);
	}

	// 6. Sperre (P7): zweiter Tab nur lesbar mit Banner, kein PATCH; Übergabe nach Schließen
	{
		const id = await api.note(WS, `Sperre ${RUN}`, null, '<p>gesperrt</p>');
		ctx.allow('status', new RegExp(`409 POST .*/notes/${id}/lock`), 'Sperrtest: zweite Sitzung erhält 409');
		ctx.allow('console', /409/, 'Sperrtest: 409 als Netzwerkfehler');
		const t1 = await context.newPage();
		await open(t1, id);
		const t2 = await context.newPage();
		await open(t2, id);
		await t2.waitForTimeout(800);
		ctx.ok((await t2.locator('.note-edit-lock-banner').innerText().catch(() => '')) !== '', 'P7: Tab 2 zeigt Sperr-Banner');
		ctx.ok((await t2.locator(`#entry${id}`).getAttribute('contenteditable')) === 'false', 'P7: Tab 2 nicht editierbar');
		ctx.ok(await t2.locator('.note-edit-lock-banner button').count() === 0, 'P7: kein Übernahme-Knopf');
		const patches = [];
		t2.on('request', (r) => { if (r.method() === 'PATCH') patches.push(r.url()); });
		await t2.locator(`#entry${id}`).click({ force: true });
		await t2.keyboard.type('xyz');
		await t2.waitForTimeout(3500);
		ctx.ok(patches.length === 0, 'P7: Tippen in Tab 2 erzeugt keinen PATCH', patches.join(','));
		await t1.close({ runBeforeUnload: true });
		await t2.waitForTimeout(5000);
		ctx.ok((await t2.locator(`#entry${id}`).getAttribute('contenteditable')) === 'true', 'P7: Tab 2 erhält Sperre nach Schließen von Tab 1');
		ctx.ok((await content(id)).includes('gesperrt') && !(await content(id)).includes('xyz'), 'P7: Inhalt unverändert');
		await t2.close();
	}

	// 7. Duplizierter Tab (ADR 0005): eigene Editor-Sitzung, Nur-Lesen, kein Datenverlust
	{
		const id = await api.note(WS, `Dup ${RUN}`, null, '<p>dup</p>');
		ctx.allow('status', new RegExp(`409 POST .*/notes/${id}/lock`), 'Duplikat: 409');
		const t1 = await context.newPage();
		await open(t1, id);
		const [t2] = await Promise.all([context.waitForEvent('page'), t1.evaluate(() => window.open(location.href))]);
		await t2.waitForLoadState('networkidle');
		await t2.waitForTimeout(1500);
		const s1 = await t1.evaluate(() => sessionStorage.getItem('poznote_editor_session_id'));
		const s2 = await t2.evaluate(() => sessionStorage.getItem('poznote_editor_session_id'));
		ctx.ok(s1 && s2 && s1 !== s2, 'Kopie hat eigene Editor-Sitzung', `${s1} / ${s2}`);
		ctx.ok((await t2.locator(`#entry${id}`).getAttribute('contenteditable')) === 'false', 'Kopie: Nur-Lesen');
		ctx.ok((await t2.locator('.note-edit-lock-banner').count()) === 1, 'Kopie: Banner');
		await t1.locator(`#entry${id}`).click();
		await t1.keyboard.press('End');
		await t1.keyboard.type(` eins${RUN}`);
		await t2.locator(`#entry${id}`).click({ force: true });
		await t2.keyboard.type(' zwei');
		ctx.ok(await waitFor(async () => (await content(id)).includes(`eins${RUN}`)), 'Duplikat: Text aus Tab 1 gespeichert');
		await t1.reload({ waitUntil: 'networkidle' });
		await t1.waitForTimeout(1500);
		ctx.ok((await t1.locator(`#entry${id}`).getAttribute('contenteditable')) === 'true', 'Reload Tab 1 behält Sperre');
		ctx.ok(!(await content(id)).includes(' zwei'), 'Duplikat: Kopie hat nichts gespeichert');
		await t2.close(); await t1.close();
	}

	// 8. B26: eingeschleuste data-action / #right_col / #unified-search-form ohne Wirkung
	{
		const victim = await api.note(WS, `Opfer ${RUN}`, null, '<p>bleibt</p>');
		const html = `<p>vor</p><button class="btn-trash note-action-btn" data-action="delete-note" data-note-id="${victim}" id="inj1">K1</button>`
			+ `<div id="right_col">R</div><div id="unified-search-form">U</div><span class="note-actions-toggle folder-toggle" id="b26span">S</span>`
			+ `<a class="btn-move" data-action="show-move-folder-dialog" id="inj2">M</a>`;
		const id = await api.note(WS, `B26 ${RUN}`, null, html);
		ctx.ok(!/data-action/.test(await content(id)), 'B26: Sanitizer entfernt data-action');
		await open(page, id);
		const m = ctx.mark();
		const dlg = []; page.on('dialog', (d) => { dlg.push(d.message()); d.dismiss().catch(() => {}); });
		for (const sel of [`#entry${id} #inj1`, `#entry${id} #right_col`, `#entry${id} #unified-search-form`, `#entry${id} #b26span`, `#entry${id} #inj2`]) {
			if (await page.locator(sel).count()) { await page.locator(sel).first().click({ force: true }); await page.waitForTimeout(400); }
		}
		const modal = await page.evaluate(() => [...document.querySelectorAll('.modal')].filter((e) => getComputedStyle(e).display !== 'none').map((e) => e.id));
		ctx.ok(modal.length === 0 && dlg.length === 0, 'B26: kein Dialog geöffnet', `${modal.join(',')} ${dlg.join(',')}`);
		ctx.ok((await api.call('GET', `/notes/${victim}`)).status === 200, 'B26: Opfernotiz unverändert');
		ctx.ok(await page.evaluate(() => document.querySelectorAll('#right_col').length >= 1 && document.getElementById('right_col').parentElement.id === 'right_pane'), 'B26: Seiten-#right_col intakt');
		ctx.ok(page.url().includes(`note=${id}`), 'B26: keine Navigation', page.url());
		ctx.assertClean('B26', m);
	}

	// 9. Tags mit Apostroph
	{
		const id = await api.note(WS, `Tags ${RUN}`, null, '<p>t</p>');
		await api.must('PATCH', `/notes/${id}`, { tags: "l'été, O'Neil", workspace: WS });
		await open(page, id);
		const m = ctx.mark();
		const tags = await page.locator('.note-tags-row').innerText();
		ctx.ok(tags.includes("l'été") && tags.includes("O'Neil"), 'Tags mit Apostroph angezeigt', tags);
		await page.click('.icon_tag');
		await page.waitForTimeout(600);
		ctx.ok(await page.locator('#tagsModal').isVisible(), 'Tag-Dialog offen');
		ctx.ok((await page.locator('#tagsModalTagsList').innerText()).includes("l'été"), "Tag-Dialog zeigt l'été");
		ctx.assertClean('Tags', m);
	}

	// 8b. B26-Gegenprobe: berechtigte Elemente im Notizinhalt wirken weiter
	{
		await context.grantPermissions(['clipboard-read', 'clipboard-write'], { origin: ctx.base });
		const target = await api.note(WS, `Linkziel ${RUN}`, null, '<p>ZIEL</p>');
		const html = '<ul class="checklist"><li class="checklist-item"><input type="checkbox" class="checklist-checkbox"><span class="checklist-text">Punkt</span></li></ul>'
			+ `<p><a href="index.php?note=${target}" class="note-internal-link" data-note-id="${target}" data-note-reference="true">zum Ziel</a></p>`
			+ '<pre><code>kopier mich</code></pre>';
		const id = await api.note(WS, `B26 Gegen ${RUN}`, null, html);
		await open(page, id);
		const m = ctx.mark();
		await page.locator(`#entry${id} .checklist-checkbox`).first().click();
		ctx.ok(await waitFor(async () => /checklist-item-checked|checked/.test(await content(id))), 'B26-Gegenprobe: Checkliste abhaken speichert');
		await page.locator(`#entry${id} pre`).first().click();
		await page.waitForTimeout(400);
		const copyBtn = page.locator(`#note${id} .code-block-copy-btn`).first();
		if (await copyBtn.count()) {
			await copyBtn.click({ force: true });
			await page.waitForTimeout(500);
			const clip = await page.evaluate(() => navigator.clipboard.readText().catch(() => ''));
			ctx.ok(clip.includes('kopier mich'), 'B26-Gegenprobe: Codeblock kopieren wirkt', clip);
		} else ctx.ok(false, 'B26-Gegenprobe: Kopierknopf am Codeblock vorhanden');
		await page.locator(`#entry${id} a.note-internal-link`).first().click();
		ctx.ok(await waitFor(async () => (await page.locator(`#right_col #entry${target}`).count()) === 1), 'B26-Gegenprobe: interner Link öffnet Zielnotiz', page.url());
		ctx.assertClean('B26-Gegenprobe', m);
	}

	// 8c. data-action mit Prototyp-Namen ohne Wirkung (Adapter-Dispatch)
	{
		const id = await api.note(WS, `Proto ${RUN}`, null, '<p>p</p>');
		await open(page, id);
		const m = ctx.mark();
		const url = page.url();
		await page.evaluate((nid) => {
			const e = document.getElementById('entry' + nid);
			for (const a of ['constructor', 'toString', '__proto__', 'hasOwnProperty', 'valueOf']) {
				const b = document.createElement('span'); b.dataset.action = a; b.className = 'proto-probe'; b.textContent = a; e.appendChild(b);
			}
		}, id);
		// nur im Notizinhalt (nur dort kann Inhalt eingeschleust werden); el.click() ohne Mauskoordinaten
		const n = await page.locator('.proto-probe').count();
		for (let i = 0; i < n; i++) {
			const label = await page.locator('.proto-probe').nth(i).evaluate((el) => { el.click(); return `${el.parentElement.id || el.parentElement.tagName}:${el.dataset.action}`; });
			await page.waitForTimeout(150);
			ctx.ok(page.url() === url, `Prototyp-Aktion ${label}: keine Navigation`, page.url());
			if (page.url() !== url) break;
		}
		ctx.assertClean('Prototyp-Aktionen (constructor/toString)', m);
	}

	// 8d. Lesefreigabe: Editor nicht editierbar, kein PATCH
	{
		const wsName = `Lesen ${RUN}`;
		await api.workspace(wsName);
		const id = await api.note(wsName, `Geteilt ${RUN}`, null, '<p>nur lesen</p>');
		shareRead(ctx.base, process.env.NN_USER, wsName, process.env.NN_USER2);
		const ctxB = await page.context().browser().newContext({ viewport: { width: 1400, height: 900 }, locale: 'de-DE' });
		await ctx.attach(ctxB);
		const pb = await ctxB.newPage();
		await ctx.login(pb, process.env.NN_USER2, process.env.NN_PASS2);
		const m = ctx.mark();
		const patches = [];
		const locks = [];
		pb.on('request', (r) => { if (r.method() === 'PATCH') patches.push(r.url()); if (r.method() === 'POST' && /\/lock(\?|$)/.test(r.url())) locks.push(r.url()); });
		await pb.goto(ctx.appUrl(`index.php?workspace=${encodeURIComponent(wsName)}&note=${id}`), { waitUntil: 'networkidle' });
		await pb.waitForTimeout(1500);
		const ce = await pb.locator(`#entry${id}`).getAttribute('contenteditable');
		ctx.ok(ce !== 'true', 'Lesefreigabe: Editor nicht editierbar', String(ce));
		await pb.locator(`#entry${id}`).click({ force: true });
		await pb.keyboard.type('fremd');
		await pb.waitForTimeout(3500);
		ctx.ok(patches.length === 0, 'Lesefreigabe: kein PATCH', patches.join(','));
		ctx.ok(locks.length === 0, 'Lesefreigabe: kein Sperr-POST', locks.join(','));
		ctx.ok(await pb.locator('.note-edit-lock-banner').count() === 0, 'Lesefreigabe: kein Sperr-Banner');
		ctx.ok(!(await content(id)).includes('fremd'), 'Lesefreigabe: Inhalt unverändert');
		ctx.assertClean('Lesefreigabe', m);
		await ctxB.close();
	}

	// 10. Verschieben-Dialoge (Werkzeugleiste, Ordnerzeile) ohne Konsolenfehler; Verschieben wirkt
	{
		const folder = `Ziel ${RUN}`;
		const fid = await api.folder(WS, folder);
		const id = await api.note(WS, `Move ${RUN}`, null, '<p>m</p>');
		await open(page, id);
		const m = ctx.mark();
		await page.locator(`#note${id} .btn-move`).evaluate((b) => b.click()); // Desktop: Knopf im Überlauf-Menü
		await page.waitForTimeout(800);
		ctx.ok(await page.locator('#moveNoteFolderModal').isVisible(), 'Verschieben-Dialog (Werkzeugleiste) offen');
		const opts = await page.locator('#moveNoteTargetSelect option').allInnerTexts();
		ctx.ok(opts.some((o) => o.includes(folder)), 'Zielordner auswählbar', opts.join('|'));
		await page.locator('#moveNoteFolderModal [data-action="close-modal"]').click();
		await page.waitForTimeout(300);
		ctx.ok(!(await page.locator('#moveNoteFolderModal').isVisible()), 'Dialog schließt');
		await page.locator(`#note${id} .folder_name`).first().click();
		await page.waitForTimeout(800);
		ctx.ok(await page.locator('#moveNoteFolderModal').isVisible(), 'Verschieben-Dialog (Ordnerzeile) offen');
		const val = await page.locator('#moveNoteTargetSelect option', { hasText: folder }).first().getAttribute('value');
		await page.selectOption('#moveNoteTargetSelect', val);
		await page.click('#moveActionButton');
		ctx.ok(await waitFor(async () => (await api.must('GET', `/notes/${id}`)).note?.folder_id === fid), 'Notiz verschoben', String(fid));
		await page.waitForTimeout(800);
		ctx.assertClean('Verschieben', m);
	}

	ctx.assertClean('gesamter Lauf');
});
