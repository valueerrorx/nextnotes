// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// check-notices.mjs <app-dir> <repo-root>: THIRD-PARTY-NOTICES.md deckt alle ausgelieferten Vendor-Bibliotheken ab,
// LICENSES-Verweise existieren, CHANGELOG-Version = info.xml-Version (M7c). Offline, ohne Abhängigkeiten.
import { readFileSync, existsSync, readdirSync, statSync } from 'node:fs';
import { join } from 'node:path';

const [app, repo] = process.argv.slice(2);
if (!app || !repo) { console.error('Aufruf: check-notices.mjs <app-dir> <repo-root>'); process.exit(2); }
let fail = 0;
const err = (m) => { console.error(`FEHLER: ${m}`); fail = 1; };
const read = (p) => readFileSync(p, 'utf8');

const notices = read(join(app, 'THIRD-PARTY-NOTICES.md'));
const bundles = JSON.parse(read(join(app, 'build/bundles.json')));
const vendor = JSON.parse(read(join(repo, 'vendor.json')));

// Alle von bundles.json referenzierten Upstream-Pfade (relativ zu src/public)
const refs = new Set();
const walk = (v) => {
	if (typeof v === 'string') refs.add(v);
	else if (Array.isArray(v)) v.forEach(walk);
	else if (v && typeof v === 'object') Object.values(v).forEach(walk);
};
for (const k of ['js', 'jsSingle', 'css', 'cssSingle', 'cssPageGroups', 'cssPages', 'pages', 'ownPages']) walk(bundles[k]);
const vendorFrom = (bundles.vendor || []).map((v) => v.from);

const rel = (p) => p.replace(/^src\/public\//, '').replace(/\/$/, '');
const shipped = (lib) => lib.files.some((f) => {
	const p = rel(f.path);
	return refs.has(p) || vendorFrom.some((v) => p === v || p.startsWith(v + '/') || v.startsWith(p + '/'));
});

// Kennungen im Build, die eine Bibliothek verraten, auch wenn bundles.json sie anders einbindet
const markers = { 'lucide-static': /\blucide-[a-z]/, devicon: /\bdevicon-[a-z]/ };
const builtText = (dir, ext) => {
	if (!existsSync(dir)) return '';
	let out = '';
	for (const e of readdirSync(dir)) {
		const p = join(dir, e);
		if (statSync(p).isDirectory()) { if (e !== 'vendor') out += builtText(p, ext); }
		else if (p.endsWith(ext)) out += read(p);
	}
	return out;
};
const builtCss = builtText(join(app, 'css'), '.css');
if (!builtCss) err('Build-Ausgabe css/ fehlt (zuerst make build)');

const row = (id) => notices.split('\n').find((l) => l.startsWith('|') && l.includes('`' + id + '`'));
const libFiles = (lib) => lib.files.flatMap((f) => {
	const v = (bundles.vendor || []).find((x) => rel(f.path) === x.from || rel(f.path).startsWith(x.from + '/'));
	if (!v) return [];
	const p = join(app, v.to + rel(f.path).slice(v.from.length));
	if (!rel(f.path).endsWith('.js')) return [];
	if (!existsSync(p)) err(`Build-Datei ${p.slice(app.length + 1)} fehlt (zuerst make build)`);
	return [p];
});

const covered = [];
for (const lib of vendor.libraries) {
	const viaBundles = shipped(lib);
	const viaMarker = markers[lib.id] && markers[lib.id].test(builtCss);
	if (!viaBundles && !viaMarker) continue;
	covered.push(lib.id);
	const r = row(lib.id);
	if (!r) { err(`${lib.id} wird ausgeliefert, fehlt aber in THIRD-PARTY-NOTICES.md`); continue; }
	if (!r.includes(lib.license.split(' ')[0])) err(`${lib.id}: Lizenz ${lib.license} nicht in der Tabellenzeile`);
	if (lib.build) {
		// gebündelte Pakete: jedes Laufzeit-Paket der Lockfile mit Version
		const lock = JSON.parse(read(join(repo, lib.build.lockfile)));
		for (const [k, v] of Object.entries(lock.packages || {})) {
			if (!k || v.dev) continue;
			const name = k.replace(/^.*node_modules\//, '');
			if (!notices.includes('`' + name + '@' + v.version + '`')) err(`${lib.id}: Paket ${name}@${v.version} fehlt in den Hinweisen`);
		}
		continue;
	}
	const ver = (r.split('|')[2] || '').trim();
	const files = libFiles(lib);
	if (files.length) {
		// Version wie ausgeliefert (Vendor-Update in src/ möglich, z. B. KaTeX P22)
		if (!files.some((p) => existsSync(p) && read(p).includes(ver))) err(`${lib.id}: Version ${ver} nicht in ${files.map((p) => p.slice(app.length + 1)).join(', ')}`);
	} else if (ver !== lib.version) {
		err(`${lib.id}: Version ${ver} statt ${lib.version} (vendor.json)`);
	}
}
for (const id of Object.keys(markers)) {
	if (markers[id].test(builtCss) && !covered.includes(id)) err(`Kennung ${id} im Build, aber kein vendor.json-Eintrag`);
}

// Verweise auf Lizenztexte existieren
for (const m of notices.matchAll(/`(LICENSES\/[^`]+)`/g)) {
	if (!existsSync(join(app, m[1]))) err(`${m[1]} fehlt`);
}
for (const f of ['COPYING', 'LICENCE', 'CHANGELOG.md']) if (!existsSync(join(app, f))) err(`${f} fehlt`);

// CHANGELOG: [Unreleased] und erste Version = info.xml
const info = read(join(app, 'appinfo/info.xml'));
const infoVer = (info.match(/<version>([^<]+)<\/version>/) || [])[1];
const cl = existsSync(join(app, 'CHANGELOG.md')) ? read(join(app, 'CHANGELOG.md')) : '';
if (!/^## \[Unreleased\]\s*$/m.test(cl)) err('CHANGELOG.md: Abschnitt "## [Unreleased]" fehlt');
const clVer = (cl.match(/^## (\d+\.\d+\.\d+)\b.*$/m) || [])[1];
if (clVer !== infoVer) err(`CHANGELOG.md: erste Version ${clVer} ≠ info.xml ${infoVer}`);
else if (!new RegExp(`^## ${clVer.replace(/\./g, '\\.')} – \\d{4}-\\d{2}-\\d{2}$`, 'm').test(cl)) err(`CHANGELOG.md: Überschrift "## ${clVer} – JJJJ-MM-TT" erwartet`);

if (fail) process.exit(1);
console.log(`Hinweise: ${covered.length} Bibliotheken (${covered.join(', ')}), CHANGELOG ${clVer} = info.xml`);
