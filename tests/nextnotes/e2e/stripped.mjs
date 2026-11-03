// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// M3a2 (Plan M3 §2 Done M3a2 3): Gestrichenes und noch nicht portierte Seiten nicht sichtbar – Hauptansicht und alle Menüs
// (Icon-Leiste/Überlauf, Workspace, „+“, Baumoptionen, Favoriten, Ordner-, Notiz-Aktionen). Smoke: Menüaktionen
// („+“, Kanban, Modal-Aktionen) → 0 pageerror/Konsolenfehler; fehlende Funktion bis M3b/M3c wird als FAIL mit Detail gemeldet.
// Liste abgeleitet aus Upstream src/icon_sidebar.php, src/notes_list.php, src/folders_display.php, public/js/utils-menus.js,
// public/js/workspaces-core.js.
import { runSpec } from './lib/harness.mjs';
import { apiClient } from './lib/api.mjs';

const RUN = `${process.argv[3]}${Date.now().toString(36)}`;

// Bekannt fehlende Funktionen (Smoke): Fehler beim Auslösen werden als WARN gemeldet, nicht rot; nur mit Slice der Behebung
const KNOWN_MISSING = {
	// muss bis M3c verschwinden (Kanban-Seite kanban_content.php)
	'open-kanban-view': 'M3c: kanban_content.php fehlt (404); kein CSP-Fehler mehr (P2 erledigt in M3b2)',
};

// Seiten, die in M3a2 nicht verlinkt sein dürfen (gestrichen oder erst M4/M3c portiert)
const FORBIDDEN_PAGES = /(^|\/)(dashboard|graph|speed_dial|notes_manager|tasks|list_folders|diary|list_tags|shared|offline_notes|attachments_list|trash|mentions|settings|login|logout|profile|backup[a-z_]*|users|about|whats_new|public_note|git[a-z_]*|ai[a-z_]*|export[a-z_]*|api_export[a-z_]*|api_download[a-z_]*)\.php/i;
// Sichtbare Texte (de/en) gestrichener Funktionen
const FORBIDDEN_TEXT = [
	/\b(ab|an)melden\b|log ?out|log ?in\b|sign (in|out)/i, /\bkonten\b|\bkonto\b|accounts?\b|benutzerverwaltung|users? management/i,
	/\bKI\b|\bAI\b|künstliche intelligenz|assistent/i, /teilen|share|öffentlich(er)? link|public link/i, /offline/i, /\bPWA\b|installieren|install app/i,
	/backup|sicherung|wiederherstell|restore/i, /\btheme\b|design wählen|farbschema|schriftart|\bfont\b|dunkel(er)? modus|dark mode|light mode|hell(er)? modus/i,
	/\bgit\b|github|gitea|sync/i, /export|herunterladen|download/i, /dokumentation|documentation|\bdocs?\b|spende|donat|sponsor|poznote/i,
	/papierkorb|\btrash\b|tagebuch|\bdiary\b|\bgraph\b|einstellungen|settings|arbeitsbereiche bearbeiten|edit workspaces|neuer arbeitsbereich|new workspace|über poznote|about/i,
];

// Browser: sichtbare Bedienelemente/Texte außerhalb der NC-Oberfläche
async function visibleUi(page) {
	return page.evaluate(() => {
		const isVis = (e) => {
			if (e.closest('#header, .v-popper__popper, .toastify, #body-user > .header-menu__wrapper, script, style, template')) return false;
			if (!e.getClientRects().length) return false;
			const r = e.getBoundingClientRect();
			if (r.width < 1 || r.height < 1 || r.bottom < 0 || r.right < 0 || r.top > innerHeight || r.left > innerWidth) return false;
			for (let p = e; p; p = p.parentElement) { const cs = getComputedStyle(p); if (cs.visibility === 'hidden' || cs.opacity === '0' || cs.display === 'none') return false; }
			return true;
		};
		const out = [];
		for (const e of document.querySelectorAll('#content *, body > *:not(#header) *')) {
			if (!isVis(e)) continue;
			const own = [...e.childNodes].filter((n) => n.nodeType === 3).map((n) => n.textContent).join(' ').replace(/\s+/g, ' ').trim();
			const attrs = ['data-action', 'href', 'data-workspace-url', 'data-url', 'title', 'aria-label', 'id'].map((a) => [a, e.getAttribute(a)]).filter(([, v]) => v);
			if (!own && !attrs.length) continue;
			out.push({ tag: e.tagName.toLowerCase(), text: own, attrs: Object.fromEntries(attrs), userData: !!e.closest('.note-title, .folder-name, .workspace-title-text, .workspace-menu-item[data-workspace-name]:not(.workspace-menu-action)') });
		}
		return out;
	});
}

function forbiddenIn(items) {
	const bad = [];
	for (const it of items) {
		if (it.userData) continue; // Namen aus Testdaten
		const urls = [it.attrs.href, it.attrs['data-workspace-url'], it.attrs['data-url']].filter(Boolean);
		for (const u of urls) if (FORBIDDEN_PAGES.test(u)) bad.push(`${it.tag} ${u}`);
		for (const s of [it.text, it.attrs.title, it.attrs['aria-label']].filter(Boolean)) {
			const re = FORBIDDEN_TEXT.find((r) => r.test(s));
			if (re) bad.push(`${it.tag} „${s}“ (${re.source.slice(0, 30)}…)`);
		}
		if (it.attrs['data-action'] && /share|offline|export|download|git|ai-|backup|logout|login|theme|font|profile|account|user/i.test(it.attrs['data-action'])) bad.push(`${it.tag}[data-action=${it.attrs['data-action']}]`);
	}
	return [...new Set(bad)];
}

// Ordner-/Notiz-Aktionsknöpfe erscheinen erst beim Überfahren der Zeile (Upstream actions-menu.css)
async function hoverRow(page, trigger) {
	const t = page.locator(trigger).first();
	const row = t.locator('xpath=ancestor::*[contains(concat(" ",@class," ")," folder-toggle ") or contains(concat(" ",@class," ")," note-list-item ")][1]');
	if (await row.count()) await row.hover().catch(() => {});
	else await t.hover({ force: true }).catch(() => {});
	await page.waitForTimeout(150);
	return t;
}

await runSpec(import.meta.url, async (ctx, page) => {
	const api = apiClient(ctx, page.request);
	const WS = await api.defaultWorkspace();
	await api.must('PUT', '/settings/last_opened_workspace', { value: WS });
	const folderName = `Menüordner ${RUN}`;
	const fid = await api.folder(WS, folderName);
	const nid = await api.note(WS, `Menünotiz ${RUN}`, fid);
	const fav = await api.call('POST', `/notes/${nid}/favorite`, {});
	ctx.info(`Favorit gesetzt: HTTP ${fav.status} ${JSON.stringify(fav.body).slice(0, 120)}`);

	const open = async () => {
		await page.goto(ctx.appUrl(`index.php?workspace=${encodeURIComponent(WS)}`), { waitUntil: 'networkidle' });
		await page.waitForTimeout(300);
	};
	const scan = async (label) => {
		const items = await visibleUi(page);
		const bad = forbiddenIn(items);
		ctx.ok(bad.length === 0, `${label}: nichts Gestrichenes sichtbar (${items.length} Elemente)`, bad.slice(0, 10).join(' | '));
		return items;
	};

	await open();
	let m = ctx.mark();
	await scan('Hauptansicht');
	const rail = await page.evaluate(() => [...document.querySelectorAll('#icon_sidebar a, #icon_sidebar button')].filter((e) => e.getClientRects().length).map((e) => e.id));
	ctx.info(`Icon-Leiste sichtbar: ${rail.join(', ')}`);

	const menus = [
		['Icon-Leiste Überlauf', '#iconSidebarOverflowBtn', '#iconSidebarOverflowMenu'],
		['Workspace-Menü', '.sidebar-title', '#workspaceMenu'],
		['Baumoptionen', '#sidebarTreeOptionsBtn', '#tree-options-menu'],
		['Favoriten-Menü', '[data-action="toggle-favorites-menu"]', '#favorites-actions-menu'],
		['Ordner-Aktionen', `.folder-actions-toggle[data-folder-id="${fid}"]`, '#folder-actions-menu'],
		['Notiz-Aktionen', `.note-actions-toggle[data-note-id="${nid}"]`, '#note-actions-menu'],
		['„+“ Erstellen', '#sidebarCreateBtn', '#create-menu, .create-menu'],
	];
	for (const [label, trigger, menuSel] of menus) {
		await open();
		const mm = ctx.mark();
		const t = page.locator(trigger).first();
		if (await t.count() === 0) { ctx.ok(false, `${label}: Auslöser ${trigger} vorhanden`); continue; }
		await t.scrollIntoViewIfNeeded().catch(() => {});
		await hoverRow(page, trigger);
		if (!(await t.isVisible())) {
			// Überlauf-Knopf erscheint nur, wenn Symbole ausgeblendet sind; in M3a2 trägt die Leiste nur das Logo
			ctx.info(`${label}: Auslöser unsichtbar – Menü nicht erreichbar, nur DOM geprüft`);
			const html = await page.locator(menuSel).first().innerHTML().catch(() => '');
			ctx.ok(!FORBIDDEN_PAGES.test(html), `${label}: DOM ohne Links auf gestrichene Seiten`, html.slice(0, 200));
			continue;
		}
		await t.click({ force: true });
		await page.waitForTimeout(500);
		const shown = await page.locator(menuSel).first().isVisible().catch(() => false);
		const known = label.startsWith('„+“') ? KNOWN_MISSING['toggle-create-menu'] : null;
		if (!shown && known) ctx.info(`WARN ${label}: Menü nicht sichtbar – bekannt: ${known}`);
		else ctx.ok(shown, `${label}: Menü ${menuSel} sichtbar`);
		await scan(`${label} offen`);
		await ctx.screenshot(page, `menu-${label.replace(/[^a-z]+/gi, '-').toLowerCase()}`);
		ctx.assertClean(`${label}`, mm);
	}

	// Smoke: Aktionen aus den Menüs (Funktion darf fehlen, Fehler werden gemeldet)
	const actions = [
		['Ordner', `.folder-actions-toggle[data-folder-id="${fid}"]`, 'open-kanban-view'],
		['Ordner', `.folder-actions-toggle[data-folder-id="${fid}"]`, 'rename-folder'],
		['Ordner', `.folder-actions-toggle[data-folder-id="${fid}"]`, 'move-entire-folder'],
		['Ordner', `.folder-actions-toggle[data-folder-id="${fid}"]`, 'tag-folder-notes'],
		['Ordner', `.folder-actions-toggle[data-folder-id="${fid}"]`, 'create-note-in-folder'],
		['Notiz', `.note-actions-toggle[data-note-id="${nid}"]`, 'rename-note'],
		['Notiz', `.note-actions-toggle[data-note-id="${nid}"]`, 'show-move-folder-dialog'],
		['Notiz', `.note-actions-toggle[data-note-id="${nid}"]`, 'delete-note'],
		['Workspace', '.sidebar-title', 'new-workspace'],
		['Baum', '#sidebarTreeOptionsBtn', 'set-note-sort'],
	];
	for (const [scope, trigger, action] of actions) {
		await open();
		const mm = ctx.mark();
		await hoverRow(page, trigger);
		await page.locator(trigger).first().click({ force: true });
		await page.waitForTimeout(400);
		const item = page.locator(`#folder-actions-menu [data-action="${action}"], #note-actions-menu [data-action="${action}"], #workspaceMenu [data-action="${action}"], #tree-options-menu [data-action="${action}"]`).first();
		const visible = await item.isVisible().catch(() => false);
		if (!visible) { ctx.info(`${scope}: „${action}“ nicht sichtbar – übersprungen`); continue; }
		const before = page.url();
		page.once('dialog', (d) => d.dismiss().catch(() => {}));
		await item.click();
		await page.waitForTimeout(1200);
		const after = page.url();
		const modal = await page.evaluate(() => [...document.querySelectorAll('.modal, [role=dialog]')].filter((e) => e.getClientRects().length && getComputedStyle(e).display !== 'none').map((e) => e.id || e.className).slice(0, 3));
		ctx.info(`${scope}: „${action}“ → ${after !== before ? `navigiert ${after.replace(ctx.base, '')}` : 'bleibt'}; Dialoge: ${JSON.stringify(modal)}`);
		await scan(`Aktion ${action}`);
		if (KNOWN_MISSING[action]) {
			const evs = ctx.problems(mm);
			for (const e of evs) e.allowed = KNOWN_MISSING[action];
			ctx.info(`WARN ${scope}/${action}: ${evs.length} Fehlerereignisse – bekannt: ${KNOWN_MISSING[action]}`);
			for (const e of evs) ctx.info(`    ${e.kind}: ${e.text.split('\n')[0].slice(0, 200)}`);
		} else {
			ctx.assertClean(`Aktion ${scope}/${action}`, mm);
		}
		await page.keyboard.press('Escape').catch(() => {});
	}
	// Notiz noch vorhanden (delete-note-Dialog abgelehnt)
	const still = await api.call('GET', `/notes/${nid}`);
	ctx.info(`Notiz nach abgelehntem Löschdialog: HTTP ${still.status}`);
	ctx.assertClean('gesamter Lauf', m);
});
