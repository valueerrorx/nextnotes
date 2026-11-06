// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// check-upstream (b) und (d): Upstream-Listen (JSON von upstream-lists.php auf stdin) gegen bundles.json; Adapter-Namen in src/.
// Aufruf: php build/upstream-lists.php <src> | node build/check-upstream.mjs <src>
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const src = path.resolve(process.argv[2]);
const pub = path.join(src, 'public');
const b = JSON.parse(fs.readFileSync(path.join(here, 'bundles.json'), 'utf8'));
const up = JSON.parse(fs.readFileSync(0, 'utf8'));
const errors = [];

const re = (p) => new RegExp('^' + p.replace(/[.+^${}()|[\]\\]/g, '\\$&').replace(/\*/g, '[^/]*') + '$');
const matches = (list, f) => Object.keys(list).some((p) => re(p).test(f));
const keep = (l) => l.filter((f) => !matches(b.exclude, f));

function same(label, upstream, ours) {
	const want = keep(upstream);
	if (JSON.stringify(want) === JSON.stringify(ours)) return;
	const missing = want.filter((f) => !ours.includes(f));
	const extra = ours.filter((f) => !want.includes(f));
	errors.push(`${label}: weicht von Upstream ab` + (missing.length ? `; fehlt: ${missing.join(', ')}` : '') + (extra.length ? `; zu viel: ${extra.join(', ')}` : '') + (!missing.length && !extra.length ? '; Reihenfolge' : ''));
}

// (b) index_js.php / index_css.php / dark_mode_css.php
for (const g of Object.keys(up.js)) same(`js.${g} (index_js.php)`, up.js[g], b.js[g] ?? []);
for (const g of Object.keys(b.js)) if (!up.js[g]) errors.push(`js.${g}: keine Upstream-Gruppe`);
for (const g of Object.keys(up.css)) same(`css.${g} (index_css.php)`, up.css[g], b.css[g] ?? []);
same('css.dark (dark_mode_css.php)', up.dark.map((f) => 'css/dark-mode/' + f), b.css.dark);

// Dateien in css/dark-mode/ müssen gelistet oder ausgeschlossen sein
const darkFiles = fs.readdirSync(path.join(pub, 'css', 'dark-mode')).filter((f) => f.endsWith('.css')).sort();
for (const f of darkFiles) {
	const rel = 'css/dark-mode/' + f;
	if (!up.dark.includes(f) && !matches(b.exclude, rel)) errors.push(`${rel}: nicht in dark_mode_css.php`);
	if (!b.css.dark.includes(rel) && !matches(b.exclude, rel)) errors.push(`${rel}: nicht in bundles.json css.dark`);
}

// css_assets.php: Gruppen und Seiten
for (const [g, files] of Object.entries(up.groups)) same(`cssPageGroups.${g} (css_assets.php)`, files, b.cssPageGroups[g] ?? []);
for (const g of Object.keys(b.cssPageGroups)) if (!up.groups[g]) errors.push(`cssPageGroups.${g}: keine Upstream-Gruppe`);
for (const [p, files] of Object.entries(up.manifest)) {
	if (matches(b.excludePages, p)) continue;
	if (!b.cssPages[p]) errors.push(`cssPages.${p}: neue Upstream-Seite, weder gelistet noch in excludePages`);
	else same(`cssPages.${p} (css_assets.php)`, files, b.cssPages[p]);
}
for (const p of Object.keys(b.cssPages)) if (!up.manifest[p]) errors.push(`cssPages.${p}: keine Upstream-Seite`);

// Eigene Seiten: Skript-Reihenfolge der Upstream-Seite (ohne exclude/omit), CSS aus cssPages
for (const [p, def] of Object.entries(b.pages)) {
	if (p === '_doc') continue;
	if (!b.cssPages[p]) errors.push(`pages.${p}: keine cssPages-Liste`);
	const file = path.join(pub, p + '.php');
	const html = fs.existsSync(file) ? fs.readFileSync(file, 'utf8') : '';
	if (html === '') { errors.push(`pages.${p}: src/public/${p}.php fehlt`); continue; }
	const scripts = [...html.matchAll(/(?:src="|poznoteAsset\(')(js\/[^"'?]+\.js)/g)].map((m) => m[1]).filter((f) => !matches(def.omit ?? {}, f));
	same(`pages.${p}.js (src/public/${p}.php)`, [...new Set(scripts)], def.js);
	// O8: direkte Asset-Tags außerhalb von js/ bzw. css_assets.php müssen gelistet, ausgeschlossen oder begründet weggelassen sein
	const direct = [...html.matchAll(/(?:src="|href="|poznoteAsset\(')((?:css|pwa)\/[^"'?]+\.(?:css|js)|[^"'?/:]+\.js)/g)].map((m) => m[1]);
	for (const f of new Set(direct)) {
		if (!(b.cssPages[p] ?? []).includes(f) && !matches(b.exclude, f) && !matches(def.omit ?? {}, f)) errors.push(`pages.${p}: direktes Asset ${f} weder in cssPages noch exclude/omit`);
	}
}

// Einzeldateien und Vendor existieren
for (const f of b.jsSingle) if (!fs.existsSync(path.join(pub, f))) errors.push(`jsSingle ${f}: fehlt in src/public`);
for (const def of Object.values(b.cssSingle)) if (!fs.existsSync(path.join(pub, def.src))) errors.push(`cssSingle ${def.src}: fehlt in src/public`);
for (const v of b.vendor) if (!fs.existsSync(path.join(pub, v.from))) errors.push(`vendor ${v.from}: fehlt in src/public`);

// (d) Upstream-Namen, an denen die Adapter hängen
for (const h of b.adapterHooks) {
	const file = path.join(pub, h.file);
	const text = fs.existsSync(file) ? fs.readFileSync(file, 'utf8') : '';
	if (!text.includes(h.text)) errors.push(`Adapter-Haken fehlt: "${h.text}" in ${h.file} (genutzt von ${h.by})`);
}

for (const e of errors) console.error('FEHLER: ' + e);
if (errors.length) process.exit(1);
console.log('check-upstream (b, d): Bundles und Adapter-Namen passen');
