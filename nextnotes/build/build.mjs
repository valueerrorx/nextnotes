// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// Build der App-Assets aus src/ (SPEC §1.2, M3-Plan §1.1); deterministisch, ohne Netz.
// Aufruf: node build/build.mjs (cwd = nextnotes/); MINIFY=0 schaltet Minify ab, SRC= anderes src/, OUT_DIR= anderes Ziel.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';
import { bumpSpecificity, checkBumped, findBumpAfterPseudo } from './css-bump.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));
const app = path.resolve(here, '..');
const src = path.resolve(process.env.SRC ?? path.join(app, '..', 'src'));
const pub = path.join(src, 'public');
const outDir = path.resolve(process.env.OUT_DIR ?? app);
const minify = process.env.MINIFY !== '0';

let esbuild;
try {
	esbuild = createRequire(import.meta.url)(path.join(here, 'node_modules', 'esbuild'));
} catch {
	console.error('FEHLER: build/node_modules fehlt. Einmalig mit Netz: npm ci --prefix nextnotes/build');
	process.exit(1);
}

// Ausgaben vorher leeren (deterministisch)
for (const d of ['js', 'css', 'i18n']) fs.rmSync(path.join(outDir, d), { recursive: true, force: true });

const bundles = JSON.parse(fs.readFileSync(path.join(here, 'bundles.json'), 'utf8'));
const outputs = new Set();

function die(msg) {
	console.error('FEHLER: ' + msg);
	process.exit(1);
}

function read(rel) {
	const file = path.join(pub, rel);
	if (!fs.existsSync(file)) die(`Quelle fehlt: src/public/${rel}`);
	return fs.readFileSync(file, 'utf8').replace(/\r\n/g, '\n');
}

function write(rel, data) {
	const file = path.join(outDir, rel);
	fs.mkdirSync(path.dirname(file), { recursive: true });
	fs.writeFileSync(file, data);
	outputs.add(rel);
}

// Dateien eines Verzeichnisses rekursiv, sortiert
function walk(dir) {
	const out = [];
	for (const name of fs.readdirSync(dir).sort()) {
		const p = path.join(dir, name);
		if (fs.statSync(p).isDirectory()) out.push(...walk(p).map((f) => path.join(name, f)));
		else out.push(name);
	}
	return out;
}

function minifyJs(code, name) {
	if (!minify) return code;
	return esbuild.transformSync(code, {
		loader: 'js', minifyWhitespace: true, minifySyntax: true, minifyIdentifiers: false,
		legalComments: 'inline', charset: 'utf8', sourcefile: name, logLevel: 'error',
	}).code;
}

function minifyCss(code, name) {
	if (!minify) return code;
	return esbuild.transformSync(code, {
		loader: 'css', minify: true, legalComments: 'inline', charset: 'utf8', sourcefile: name, logLevel: 'error',
	}).code;
}

// Per url() referenzierte Ausgabedateien (Prüfung am Ende)
const urlTargets = [];

function mapAsset(srcRel) {
	for (const v of bundles.vendor) {
		if (srcRel === v.from) return v.to;
		if (srcRel.startsWith(v.from + '/')) return v.to + srcRel.slice(v.from.length);
	}
	return null;
}

// url() relativ zur Ausgabedatei umschreiben (wie poznoteRewriteCssUrls); bei strict muss das Ziel existieren (Vendor-CSS: woff/ttf-Rückfälle fehlen upstream)
function rewriteUrls(css, srcRel, outRel, strict = true) {
	return css.replace(/url\(\s*(['"]?)(?![a-z][a-z0-9+.-]*:|\/|#)([^'")]+)\1\s*\)/gi, (m, q, ref) => {
		const clean = ref.split(/[?#]/)[0];
		const suffix = ref.slice(clean.length);
		const assetSrc = path.posix.normalize(path.posix.join(path.posix.dirname(srcRel), clean));
		const assetOut = mapAsset(assetSrc);
		if (!assetOut) die(`url(${ref}) in ${srcRel}: kein Ausgabeziel für ${assetSrc}`);
		if (strict) urlTargets.push(assetOut);
		return `url('${path.posix.relative(path.posix.dirname(outRel), assetOut)}${suffix}')`;
	});
}

function stripFontFaces(css) {
	const match = bundles.stripFontFace?.match;
	if (!match) return css;
	return css.replace(/@font-face\s*\{[^}]*\}/g, (block) => (block.includes(match) ? '' : block));
}

// Familienname in font-family/font-Werten ersetzen (z. B. 'Inter' → var(--font-face)), auch vor !important
function rewriteFontFamily(css) {
	const r = bundles.fontFamilyRewrite;
	if (!r) return css;
	const name = r.from.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
	const family = new RegExp(`(^|,)(\\s*)(['"]?)${name}\\3(?=\\s*(?:,|!|$))`, 'g');
	return css.replace(/(font(?:-family)?\s*:)([^;{}]*)/gi, (m, prop, value) => prop + value.replace(family, `$1$2${r.to}`));
}

// Spezifitäts-Zusatz (ADR 0017, css-bump.mjs); Regelzahl gleich, kein Zusatz hinter Pseudo-Element
function cssFile(rel, outRel) {
	const before = rewriteUrls(rewriteFontFamily(stripFontFaces(read(rel))), rel, outRel);
	const after = bumpSpecificity(before);
	const errors = checkBumped(before, after);
	if (errors.length) die(`${rel}: ${errors.slice(0, 5).join('; ')}`);
	return after;
}

// Minifizierte Ausgabe erneut prüfen (esbuild darf Selektoren nicht umstellen)
function writeCss(name, code) {
	const min = minifyCss(code, name);
	const bad = findBumpAfterPseudo(min);
	if (bad.length) die(`${name}: Zusatz hinter Pseudo-Element: ${bad.slice(0, 5).join(' ')}`);
	write(name, min);
}

// 1. JS-Gruppen wie index_js.php
for (const [group, files] of Object.entries(bundles.js)) {
	const name = `js/nextnotes-${group}.js`;
	const code = files.map((f) => `/* --- ${f} --- */\n${read(f)}\n;\n`).join('');
	write(name, minifyJs(code, name));
}
for (const f of bundles.jsSingle) {
	write(`js/${path.posix.basename(f)}`, minifyJs(read(f), f));
}

// 2. CSS-Gruppen wie index_css.php / dark_mode_css.php
for (const [group, files] of Object.entries(bundles.css)) {
	const name = `css/nextnotes-${group}.css`;
	const code = files.map((f) => `/* --- ${f} --- */\n${cssFile(f, name)}\n`).join('');
	writeCss(name, code);
}
for (const [out, def] of Object.entries(bundles.cssSingle)) {
	const name = `css/${out}.css`;
	let code = cssFile(def.src, name);
	if (def.media) code = `@media ${def.media} {\n${code}\n}\n`;
	writeCss(name, code);
}
// Eigene Seiten (bundles.json pages): CSS aus cssPages (Gruppen aufgelöst), JS wie die Upstream-Seite
for (const [page, def] of Object.entries(bundles.pages)) {
	if (page === '_doc') continue;
	const css = (bundles.cssPages[page] ?? []).flatMap((f) => (f.startsWith('@') ? bundles.cssPageGroups[f] : [f]));
	if (!css.length) die(`pages.${page}: keine cssPages-Liste`);
	const cssName = `css/nextnotes-page-${page}.css`;
	writeCss(cssName, css.map((f) => `/* --- ${f} --- */\n${cssFile(f, cssName)}\n`).join(''));
	const jsName = `js/nextnotes-page-${page}.js`;
	write(jsName, minifyJs(def.js.map((f) => `/* --- ${f} --- */\n${read(f)}\n;\n`).join(''), jsName));
}
for (const f of walk(path.join(app, 'css-src'))) {
	write(`css/${f}`, fs.readFileSync(path.join(app, 'css-src', f)));
}

// 3. Vendor unverändert (CSS mit umgeschriebenen url())
for (const v of bundles.vendor) {
	const from = path.join(pub, v.from);
	if (!fs.existsSync(from)) die(`Vendor fehlt: src/public/${v.from}`);
	const files = fs.statSync(from).isDirectory() ? walk(from).map((f) => [path.posix.join(v.from, f), path.posix.join(v.to, f)]) : [[v.from, v.to]];
	for (const [s, o] of files) {
		if (v.rewriteUrls) write(o, rewriteUrls(read(s), s, o, false));
		else write(o, fs.readFileSync(path.join(pub, s)));
	}
}

// 4. i18n: JSON für /system/i18n und gecachte JS-Datei je Sprache (en eingemischt)
function merge(base, over) {
	const out = { ...base };
	for (const [k, v] of Object.entries(over)) {
		out[k] = v && typeof v === 'object' && !Array.isArray(v) && out[k] && typeof out[k] === 'object' && !Array.isArray(out[k]) ? merge(out[k], v) : v;
	}
	return out;
}
const i18nDir = path.join(src, 'i18n');
const en = JSON.parse(fs.readFileSync(path.join(i18nDir, 'en.json'), 'utf8'));
const newNoteTitles = [];
for (const f of fs.readdirSync(i18nDir).filter((n) => /^[a-z]{2}(-[a-z]{2})?\.json$/.test(n)).sort()) {
	const lang = f.slice(0, -5);
	const raw = fs.readFileSync(path.join(i18nDir, f));
	write(`i18n/${f}`, raw);
	const strings = lang === 'en' ? en : merge(en, JSON.parse(raw.toString('utf8')));
	write(`js/i18n/nextnotes-i18n-${lang}.js`, `window.__NEXTNOTES_I18N__=${JSON.stringify({ lang, strings })};\n`);
	const title = strings?.index?.note?.new_note;
	if (typeof title === 'string' && title.trim() !== '' && !newNoteTitles.includes(title)) newNoteTitles.push(title);
}
// Standardtitel aller Sprachen (Upstream getDefaultNoteTitles) für PageTranslator
write('i18n/default-note-titles.json', JSON.stringify(newNoteTitles) + '\n');

// 5. Eigene Adapter
for (const f of walk(path.join(app, 'js-src'))) {
	write(`js/${f}`, minifyJs(fs.readFileSync(path.join(app, 'js-src', f), 'utf8'), f));
}

// Jede per url() referenzierte Datei muss ausgegeben sein
for (const rel of urlTargets) {
	if (!outputs.has(rel)) die(`url()-Ziel ${rel} nicht im Build`);
}
console.log(`build: ${outputs.size} Dateien${minify ? '' : ' (MINIFY=0)'}`);
