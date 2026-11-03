// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// check-upstream (e2): Spezifitäts-Zusatz (ADR 0017) mit Chromium als CSS-Parser prüfen. Rot, wenn
// (1) Chromium von den Upstream-Quellen mit Zusatz weniger Stilregeln annimmt als ohne,
// (2) eine Regel der gebauten Bundles nur durch den Zusatz ungültig ist (ohne Zusatz gültig),
// (3) ein Zusatz hinter einem Pseudo-Element steht. Aufruf: node check-css.mjs <src> <css-dir>; CHROMIUM= Pfad.
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { BUMP_GLOBAL, BUMP_SCOPED, NOT_NC_CHROME, bumpSpecificity, findBumpAfterPseudo, mapRules } from './css-bump.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));
const pub = path.join(path.resolve(process.argv[2] ?? path.join(here, '..', '..', 'src')), 'public');
const cssDir = path.resolve(process.argv[3] ?? path.join(here, '..', 'css'));
const chromium = process.env.CHROMIUM ?? '/usr/bin/chromium';
const bundles = JSON.parse(fs.readFileSync(path.join(here, 'bundles.json'), 'utf8'));

let fail = false;
const err = (msg) => { console.error('FEHLER: ' + msg); fail = true; };
if (!fs.existsSync(chromium)) { console.error(`FEHLER: Chromium fehlt (${chromium}, CHROMIUM=)`); process.exit(2); }

// Bundle-Name → Upstream-Quelltext ohne Zusatz (wie build.mjs zusammengesetzt)
const read = (rel) => fs.readFileSync(path.join(pub, rel), 'utf8');
const sources = {};
for (const [g, files] of Object.entries(bundles.css)) sources[`nextnotes-${g}.css`] = files.map(read).join('\n');
for (const [n, def] of Object.entries(bundles.cssSingle)) sources[`${n}.css`] = def.media ? `@media ${def.media} {\n${read(def.src)}\n}` : read(def.src);
for (const p of Object.keys(bundles.pages)) {
	if (p !== '_doc') sources[`nextnotes-page-${p}.css`] = bundles.cssPages[p].flatMap((f) => (f.startsWith('@') ? bundles.cssPageGroups[f] : [f])).map(read).join('\n');
}

const sheets = {};
const lists = {};
const strip = (sel) => [BUMP_GLOBAL, BUMP_SCOPED, NOT_NC_CHROME].reduce((s, b) => s.split(b).join(''), sel);
for (const [n, text] of Object.entries(sources)) {
	sheets['vor:' + n] = text;
	sheets['nach:' + n] = bumpSpecificity(text);
	const file = path.join(cssDir, n);
	if (!fs.existsSync(file)) { err(`${n} fehlt in ${cssDir} (zuerst make build)`); continue; }
	const built = fs.readFileSync(file, 'utf8');
	const bad = findBumpAfterPseudo(built);
	if (bad.length) err(`${n}: ${bad.length}× Zusatz hinter Pseudo-Element, z. B. ${bad.slice(0, 3).join(' ')}`);
	const rules = [];
	mapRules(built, (sels) => { const l = sels.join(','); rules.push([l, strip(l)]); return l; });
	lists[n] = rules;
}

const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'nn-check-css-'));
let res;
try {
	const data = JSON.stringify({ sheets, lists }).replace(/</g, '\\u003c');
	const script = `const d=${data};const ok=(t)=>{const s=new CSSStyleSheet();try{s.replaceSync(t);}catch(e){return -1;}`
		+ 'const w=(r)=>{let k=0;for(const x of r){if(x.type===1)k++;else if(x.cssRules)k+=w(x.cssRules);}return k;};return w(s.cssRules);};'
		+ 'const r={count:{},broken:{}};for(const [n,t] of Object.entries(d.sheets))r.count[n]=ok(t);'
		+ 'for(const [n,l] of Object.entries(d.lists))r.broken[n]=l.filter(([a,b])=>ok(a+"{}")===0&&ok(b+"{}")===1).map(([a])=>a);'
		+ 'document.getElementById("out").textContent="NNCSS"+JSON.stringify(r)+"NNCSS";';
	fs.writeFileSync(path.join(tmp, 'check.html'), `<!doctype html><html><body><pre id="out"></pre><script>${script}</script></body></html>`);
	const dom = execFileSync(chromium, ['--headless', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
		`--user-data-dir=${path.join(tmp, 'profile')}`, '--dump-dom', 'file://' + path.join(tmp, 'check.html')],
	{ encoding: 'utf8', maxBuffer: 64 * 1024 * 1024, timeout: 120000, stdio: ['ignore', 'pipe', 'ignore'] });
	const m = /NNCSS(.*?)NNCSS/s.exec(dom);
	if (!m) throw new Error('kein Ergebnis aus Chromium');
	res = JSON.parse(m[1].replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&'));
} finally {
	fs.rmSync(tmp, { recursive: true, force: true });
}

for (const n of Object.keys(sources)) {
	const a = res.count['vor:' + n];
	const b = res.count['nach:' + n];
	if (a !== b) err(`${n}: Chromium nimmt ohne Zusatz ${a}, mit Zusatz ${b} Stilregeln an`);
	const broken = res.broken[n] ?? [];
	if (broken.length) err(`${n}: ${broken.length} Regeln nur durch den Zusatz ungültig, z. B. ${broken.slice(0, 3).join(' | ')}`);
	if (a === b && !broken.length && lists[n]) console.log(`check-upstream (e2): ${n} ${a} Stilregeln vor/nach Zusatz gleich, ${lists[n].length} Bundle-Regeln geprüft`);
}
// natürliches Ende: process.exit() blieb unter Node 24 sporadisch im Worker-Join hängen (M3-fix4)
process.exitCode = fail ? 1 : 0;
