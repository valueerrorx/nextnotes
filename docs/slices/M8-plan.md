# M8-Plan: Mathe- und Tabellen-Blöcke in Richtext-Notizen

Stand: r2, 2026-11-08, Basis Commit `11ae8f99`. Grundlage: ADR 0018 (entschieden), ADR 0005/0006/0007/0008/0012/0016/0017, SPEC §4.3.9, §4.6, §11.1.

**Änderungen r2** (Review `docs/reviews/M8-plan-r1.md`):
- B1: M8c §4 „Aufwandsgrenzen“ neu – Aufrufzähler + Tiefengrenze in jedem Wrapper benutzerdefinierter Funktionen, Zeitgrenze innerhalb der Auswertung; gleiche Grenze je Zeichenlauf für function-plot-`fn`; Done M8c 3 (Kompositionskette, auch Leser-Graph).
- B2: M8c §4 explizite Aufrufliste, Regel „Aufrufziel nur `SymbolNode`“, Reihenfolge „Referenzen sichern → `import` mit Stubs“, Zuweisungsverbot auf Namensraum-Namen; Testfälle in Done M8c 2.
- Hinweise: MathLive-Payloads + `openUrl` ohne `file:` (M8a); Markdown-Umwandlung mit variabler Zaunlänge und `$`-Behandlung (M8a/M8c); R2 und Done M8b 6 auf „Sperre beim Laden“; Modus zusätzlich an `data-nextnotes-readonly`; Sanitizer prüft Block-Attribute unabhängig von der Klasse, verschachtelte Blöcke werden entfernt; `is_finite`; `HEUTE` gesondert; ADR-0018-Nachtrag in M8a mit Inhalt; Q1 geschlossen (Lead).

Nicht in M8: Markdown-Notizen (haben `$…$` schon), JSXGraph, Diagramme, mehrere Blätter, Zufallsfunktionen, Live-Mehrbenutzer-Bearbeitung. Parallel laufende M7g/M7h werden nicht berührt.

## 0. Befund im Bestand (gelesen, nichts geändert)
| Stelle | Befund | Folge für M8 |
|---|---|---|
| `nextnotes/lib/Service/HtmlSanitizer.php` | PHP-DOM-Sanitizer, Attribut-Whitelist je Tag; `class`/`style`/`id` global; `span[data-math]` erlaubt, `div[data-math]` nicht; `contenteditable` nur an `div`/`iframe`; `isDangerous()` entfernt jedes Attribut mit `javascript:`/`data:`-Text (Ausnahme nur `data-excalidraw`, B35); Laufzeit-UI wird per Klasse entfernt (`isEditorUi`, `.tasklist-embed-widget`) | neue Attribute gezielt; JSON-Attribute von `isDangerous` ausnehmen (sonst löscht Zelltext „data:“ den ganzen Block) |
| `src/public/js/math-renderer.js` | rendert `.math-inline`/`.math-block` **dokumentweit** per MutationObserver mit KaTeX (`trust:false`), auch in HTML-Notizen | Formel-Anzeige ohne neuen Code; KaTeX-Ausgabe im Editor würde aber beim Speichern mitgespeichert (Bug B69, §5) |
| `src/public/js/slash-command.js` | Menüs in privaten Funktionen (`getSlashCommands()`), kein Erweiterungs-Hook; Suche flacht Untermenüs ab | ein Hook-Patch (P38) |
| `nextnotes/templates/partials/note_display.php` | Werkzeugleiste ist App-Template | Knopf ohne `src/`-Patch |
| `src/public/js/tasklist-embed.js` | Vorbild: gespeicherter Marker + Laufzeit-UI, `input`-Event auf dem Editor löst Autosave aus | gleiches Muster, UI aber im Shadow DOM |
| `src/public/js/notes.js` `stripSearchHighlights` | Speicher-Klon per `cloneNode` – Shadow Roots werden nicht geklont | Laufzeit-UI im Shadow DOM gelangt nie in den Inhalt, kein Patch an `notes.js` |
| `src/public/js/note-edit-lock.js` | Sperre/fehlendes Recht setzt `contenteditable=false` am `.noteentry`, `window.isNoteEditingLocked(id)` | Modus der Blöcke folgt diesem Attribut |
| Upstream-Skripte mit Capture-Listenern (`checklist`, `bulletlist`, `emoji-`/`mention-autocomplete`, `slash-command`, `copy-code-on-focus`) | sehen Ereignisse aus dem Shadow DOM mit `target` = Block-Host (`contenteditable=false`) | Pflicht-E2E Tastatur-Isolation (§4 R1) |
| Upstream `pre`/`code`- und `table`-Skripte | scannen alle `pre code` bzw. `table` im Editor | statische Fallbacks: Rechenblock **ohne** `pre/code`; Tabelle als `table` ohne `data-formula` (Upstream-Tabellenformeln unberührt) |
| `ExportService` | Markdown-Export nur für Markdown-/Aufgaben-Notizen; HTML-Notizen → HTML/JSON/ZIP | „Export Markdown“ betrifft nur die Umwandlung HTML→Markdown (`ConversionService::noteToMarkdown`/`htmlToMarkdown`) |
| `SearchTextNormalizer::plainText` | `strip_tags`, Attributwerte zählen nicht | Suche findet den statischen Fallback, nicht das JSON |
| `DuplicateService::copyAsSubmission` | kopiert `content` unverändert | Austeilen ohne Änderung |
| `css/vendor/katex/fonts` | 20 woff2-Dateien **byte-gleich** mit `mathlive@0.111.4/fonts` | MathLive nutzt die vorhandenen KaTeX-Fonts, keine zweite Font-Kopie |

## 1. Gemeinsame Grundlage (in M8a angelegt, von M8b/M8c genutzt)

### 1.1 Architektur
- **Kern `nextnotes/js-src/nextnotes-blocks.js`** (Hauptansicht, nach `nextnotes-teams`, Ziel ≤ 12 KB min): Registry der Blocktypen, Slash-Einträge (Hook P38), Werkzeugleisten-Menü, Einhängen per `MutationObserver` auf `.noteentry`, Modusbestimmung, Speicher-Auslöser. Bibliotheken und Block-UIs **lazy** (`poznoteEnsureScript`), nichts im Upstream-Bündel `nextnotes-app.js`.
- **Lazy-Module** (js-src, je eigene Datei): `nextnotes-formula.js` (M8a), `nextnotes-sheet.js` (M8b), `nextnotes-calc.js` (M8c).
- **Lazy-Helfer** im Adapter (SPEC §4.3.9, `nextnotes-bootstrap.js`): `poznoteEnsureMathLive`, `poznoteEnsureHyperFormula`, `poznoteEnsureMathjs`, `poznoteEnsureFunctionPlot` analog `poznoteEnsureKatex` (Promise, dedupliziert, `?v=`). Laden per `<script>` aus Nonce-Skript → durch `strict-dynamic` gedeckt (ADR 0006).
- **Laufzeit-UI im Shadow DOM** (`attachShadow({mode:'open'})`) am Block-Host. Der Light DOM enthält nur den gespeicherten Vertrag (JSON-Attribut + statischer Fallback). Folgen: UI nie im gespeicherten HTML, keine Upstream-/NC-Kern-CSS im Raster (ADR 0017-Problem entfällt), Fallback automatisch unsichtbar, solange der Shadow Root ohne `<slot>` hängt. NC-Theme-Variablen (`--color-*`) erben in den Shadow DOM.
- **Styles im Shadow Root**: `<link rel="stylesheet" href="css/nextnotes-blocks-shadow.css?v=">` (style-src `'self'`), Host bis `load` mit `visibility:hidden`. Light-DOM-Styles (Formel-Fokus, statische Fallbacks, Popover) in `css-src/nextnotes-blocks.css`, Selektoren ≥ 1,1,0 (ADR 0017).
- **Ereignis-Isolation**: Shadow Root stoppt in der Bubble-Phase `keydown/keyup/keypress/beforeinput/input/paste/copy/cut/compositionend/drop`; Capture-Listener von Upstream sehen nur den Host (nicht editierbar). Prüfung per E2E (§4 R1).
- **Speichern**: Block ändert Light DOM (JSON-Attribut + Fallback neu erzeugt), danach `noteentry.dispatchEvent(new Event('input',{bubbles:true}))` wie `tasklist-embed`. Auslösen nur bei Commit (Enter/Tab/Blur/Übernehmen), nicht je Taste. Server-Pfad unverändert (PATCH mit Sperr- und Rechteprüfung, ADR 0005).
- **Fremdänderung am Fallback** (Suchen/Ersetzen, Such-Markierung): Kern beobachtet `characterData/childList` im Light DOM eines lebenden Blocks und erzeugt den Fallback aus dem JSON neu.
- **Modus**: schreibbar ⇔ `noteentry.isContentEditable && !noteentry.hasAttribute('data-nextnotes-readonly') && !window.isNoteEditingLocked(id)`. `data-nextnotes-readonly` setzt das App-Template bei fehlendem Schreibrecht; es schützt auch dann, wenn Upstream `contenteditable` am `.noteentry` zurücksetzt. Beobachtet werden `contenteditable` und `data-nextnotes-readonly` am `.noteentry` (Sperre erworben/verloren, P11). Wechsel baut die UI im neuen Modus neu auf. Nur die Hauptansicht lädt `nextnotes-blocks.js`; alle anderen Ansichten (Druck, Papierkorb, Tagebuch, Dashboard, Verlauf, Abgabe-Iframe, Export) zeigen den statischen Fallback bzw. KaTeX.
- **Einfügen**: an der Einfügemarke auf oberster Ebene des Editors (Absatz wird geteilt, danach leerer `<p>` wie `insertTaskListEmbedHtml`); in `li`/`td`/Callout wird hinter dem umgebenden Block eingefügt. Nur Notiztyp `note` (Richtext); Markdown-/Aufgaben-Notizen bieten die Einträge nicht an.
- **Werkzeugleiste**: ein Knopf `btn-nn-blocks` (Icon `lucide-sigma`) in `note_display.php`, nur bei Typ `note` und `canWrite`, öffnet ein kleines Menü (Formel, Formel-Block; ab M8b „Tabelle rechnen“; ab M8c „Rechnen“); gleicher Eintrag im Mobil-Menü (`trigger-mobile-action`). Eigene Delegation in `nextnotes-blocks.js` (`data-action="nn-blocks-menu"`), keine Upstream-Funktion.
- **Slash-Menü**: Untermenü „Einfügen“ erhält die Einträge über den Hook; IDs `nn-formula`, `nn-formula-block`, `nn-sheet`, `nn-calc`; Icons `lucide-sigma`, `lucide-table`, `lucide-calculator` (in `lucide.css` vorhanden). Suche „/Formel“, „/Tabelle“ (Eintrag „Tabelle rechnen“; Upstream schließt das Menü beim Leerzeichen), „/Rechnen“ findet sie über die Upstream-Abflachung.
- **Texte**: NC-l10n (`t('nextnotes', …)`), `nextnotes/l10n/de*.json`.
- **Vendor-Herkunft**: Neue Bibliotheken sind kein Upstream-Vendor. Sie kommen als exakt gepinnte `dependencies` in `nextnotes/build/package.json` (+ Lockfile mit Integrity), der Build bleibt offline (`npm ci` einmalig, wie esbuild). `bundles.json` erhält `npmVendor` (Kopie unverändert) und `vendorEntries` (esbuild-IIFE aus `nextnotes/build/vendor-entries/*.mjs`, deterministisch, Lizenzkommentare `eof`). `dist.sh` bleibt unverändert (Lockfile-Vergleich deckt die neuen Pakete ab).
- **Lizenzen**: `THIRD-PARTY-NOTICES.md` und `LICENSES/` je Slice: MIT (MathLive, function-plot, fraction.js, complex.js, decimal.js, typed-function, seedrandom, escape-latex, javascript-natural-sort, tiny-emitter, regexp-to-ast, events, built-in-math-eval, interval-arithmetic-eval, math-codegen, mr-parser, extend, double-bits, nextafter, @babel/runtime), ISC (d3-*), BSD-3-Clause (d3-ease), BSL-1.0 (interval-arithmetic), Apache-2.0 (math.js inkl. dessen `NOTICE`, chevrotain), GPL-3.0-only (HyperFormula; mit AGPL-3.0 kombinierbar nach GPLv3 §13, ADR 0018).

### 1.1a Gemeinsame Sanitizer- und Konverterregeln (r2)
- **Host-Erkennung über das Attribut, nicht die Klasse**: `BlockMarkup` prüft jedes `data-nn-sheet`, `data-nn-calc` und `data-math` an jedem Element, auf dem es nach der Tag-Whitelist überhaupt erlaubt ist, gleich welche Klassen es trägt. Ungültig → nur das Attribut entfernt. Gültig → Host-Klasse (`nn-sheet`/`nn-calc`/`math-inline` bzw. `math-block`) und `contenteditable="false"` werden gesetzt. Der Client erkennt Hosts ebenfalls nur am Attribut. Trägt ein Element mehr als eines der drei Attribute, werden alle entfernt.
- **Verschachtelte Blöcke**: Ein Element mit gültigem Block-Attribut innerhalb eines anderen Block-Hosts (also im statischen Fallback) wird **samt Inhalt entfernt**. Der Fallback stammt immer aus dem Client und enthält nie Blöcke; eingeschmuggelte Hosts haben dort keinen Zweck.
- **Zahlen in JSON**: `json_decode` liefert für `1e999` `INF`. Jede Zahl muss `is_finite` sein, sonst ist das Attribut ungültig.
- **Markdown-Umwandlung** (`NextBlocks`): Codezäune werden aus Backticks gebaut, Länge = max(3, längste Backtick-Folge im Inhalt + 1). LaTeX in `$…$`/`$$…$$`: ein `$` ohne vorangestellten `\` wird zu `\$`, Leerzeilen werden zu einem Zeilenumbruch, bei Inline-Formeln werden Zeilenumbrüche zu Leerzeichen. Zerbricht der Round-Trip trotzdem (Test), wird die Formel als Code-Span mit Zaun nach derselben Längenregel ausgegeben. Testfälle: LaTeX `a$b`, `\$5`, LaTeX mit Leerzeile, Rechenblock-`src` mit ```` ``` ```` und `` ` ``; der nachfolgende Absatz bleibt jeweils ein Absatz.

### 1.2 Gemessene Bibliotheken (npm-Metadaten, Tarballs im Scratchpad entpackt, nichts im Repo)
| Paket (Version laut `npm view`) | Datei im Paket bzw. Bündel | roh | gzip -9 | brotli |
|---|---|---|---|---|
| mathlive 0.111.4 (MIT) | `mathlive.min.js` (UMD, Global `MathLive`) | 848 KB | 229 KB | 188 KB |
| hyperformula 3.5.0 (GPL-3.0-only; chevrotain 6.5.0, tiny-emitter) | `dist/hyperformula.full.min.js` | 989 KB | 209 KB | 169 KB |
| dto. | **esbuild-IIFE aus ESM + `deDE`** (geplant) | 819 KB | 181 KB | 149 KB |
| mathjs 15.2.0 (Apache-2.0) | `lib/browser/math.js` (alles) | 650 KB | 175 KB | 144 KB |
| dto. | **esbuild-IIFE, Whitelist-Factories** (geplant, §3.3) | 365 KB | 103 KB | 87 KB |
| function-plot 1.26.0 (MIT, d3 gebündelt) | `dist/function-plot.js` (UMD, Global `functionPlot`) | 211 KB | 62 KB | 54 KB |
| KaTeX (vorhanden, unverändert) | Fonts für MathLive mitbenutzt | 0 | 0 | 0 |
Zusatz im App-Paket ≈ 2,2 MB roh. Geladen wird je Notiz nur, was ein Block braucht: Formel bearbeiten +229 KB gz (Anzeige 0, KaTeX vorhanden), Tabelle schreibbar +181 KB, Rechenblock +103 KB (+62 KB mit Graph). MathLive-Sounds (240 KB) werden nicht ausgeliefert. Messwerte und Ladezeiten trägt der Lead nach jedem Slice in STATUS ein.

### 1.3 CSP-Befund (Grep über die Auslieferungsdateien, Kontext gelesen)
| Bibliothek | `eval`/`new Function` | Inline-Styles | Worker | WASM | Netz | Ergebnis |
|---|---|---|---|---|---|---|
| MathLive 0.111.4 | keine | `<style>`, `adoptedStyleSheets`, `style`-Attribute in innerHTML (gedeckt durch NC `style-src 'unsafe-inline'`, wie KaTeX) | keine | keine | `fetch(currentScript.src,{method:'HEAD'})` nur bei relativem `fontsDirectory`; Sounds per `fetch` + `AudioContext` | ohne Ausnahme, wenn `MathfieldElement.fontsDirectory` = absolute URL (mit Origin) auf `css/vendor/katex/fonts/` und `soundsDirectory = null`; `openUrl` (`\href`-Klick) von Haus aus http/https/file; wird überschrieben (§2: nur http/https) |
| HyperFormula 3.5.0 `full.min.js` | `Function("return this")` (webpack-`global`-Shim, nur ohne `globalThis`) und `new Function` in chevrotain `generateParserFactory` (von HF nicht aufgerufen) | keine | keine | keine | keine | läuft ohne Ausnahme; geplantes esbuild-Bündel aus ESM enthält **kein** `Function(` mehr |
| math.js 15.2.0 | keine (seit v4, Doku „Security“) | keine | keine | keine | keine | ohne Ausnahme |
| function-plot 1.26.0 | `new Function` in `built-in-math-eval`/`interval-arithmetic-eval` (math-codegen) **bei String-Ausdrücken**; `globalThis`-Shim | d3 setzt `element.style` (CSSOM) | keine | keine | keine | nur mit `fn` als JS-Funktion, `graphType:'polyline'`, `sampler:'builtIn'`, ohne `derivative`/String-`fn` → keine Verletzung; sonst `script-src`-Verstoß. Pflicht-E2E |
Keine neue CSP-Ausnahme, keine Änderung an `CspFactory`. Prüfung je Slice: E2E zählt `securitypolicyviolation` = 0 in Hauptansicht (schreibend, Leser, Sperre), Druckansicht, Papierkorb-Vorschau, Abgabe-Lesemodus.

### 1.4 `src/`-Patches (vollständige Liste für M8)
| # | Datei | Änderung | Slice |
|---|---|---|---|
| P38 | `src/public/js/slash-command.js` (`getSlashCommands()`, Untermenü `insert`, nach Eintrag `table`) | eine Zeile: `...(typeof window.poznoteSlashInsertItems === 'function' ? window.poznoteSlashInsertItems() : []),` – generischer Hook, liefert Einträge `{id, icon, label, action}`; ohne Adapter wirkungslos | M8a |
Sonst kein `src/`-Patch: Werkzeugleiste (App-Template), Speicher-Klon (Shadow DOM), KaTeX-Anzeige (vorhandener Renderer), Sanitizer/Konverter (App-PHP). `check-upstream` prüft den Hook-Namen (`adapterHooks`).

### 1.5 Hell/Dunkel (gilt für alle Blöcke)
Nur NC-Variablen (`--color-main-text`, `--color-main-background`, `--color-background-hover`, `--color-border`, `--color-primary-element`, `--color-error`, `--color-warning`, `--border-radius-element`, `--default-clickable-area`). KaTeX und Graph nutzen `currentColor`; MathLive-Variablen (`--caret-color`, `--selection-background-color`, `--keyboard-*`) aus NC-Variablen. E2E-Screenshot hell und dunkel je Block (Kontrast Fehlerzelle, Auswahl, Achsen).

### 1.6 Mobil/Tablet (gilt für alle Blöcke)
Bedienelemente ≥ `--default-clickable-area`; Blöcke scrollen waagrecht in sich (`overflow-x:auto`), nie die Seite. ≤ 800 px (Grenze wie `isMobileDevice`): Formel-Popover als unteres Blatt, Tabelle bearbeitet über die Formelzeile (Antippen = auswählen, zweites Antippen/Enter = bearbeiten, Tastatur öffnet nur dort). E2E auf 390 × 844 und 820 × 1180 (Tablet, Touch-Emulation).

## 2. M8a – Formel (inline und Block)

**Scope**: Formel einfügen/bearbeiten/löschen in Richtext-Notizen, Anzeige mit vorhandenem KaTeX, Eingabe mit MathLive im Popover. Gemeinsame Grundlage §1 (Kern, Hook P38, Lazy-Helfer, Build-Erweiterung `npmVendor`, Sanitizer-Normalisierung, Konverter-Vorstufe). Fix B69.

**Dateien**: `src/public/js/slash-command.js` (P38) · `nextnotes/build/upstream-patches.txt` (bereits gelistet, Eintrag bleibt) · `nextnotes/build/{package.json,package-lock.json,bundles.json,build.mjs}` (mathlive, `npmVendor`, Hook-Prüfung) · `nextnotes/js-src/{nextnotes-blocks.js (neu), nextnotes-formula.js (neu), nextnotes-bootstrap.js}` · `nextnotes/css-src/nextnotes-blocks.css` (neu), `nextnotes-printview.css` · `nextnotes/lib/Service/{HtmlSanitizer.php, BlockMarkup.php (neu), ConversionService.php}` · `nextnotes/lib/Service/Convert/NextBlocks.php` (neu, keine Änderung an Port-Dateien) · `nextnotes/lib/Service/View/PageAssets.php` · `nextnotes/templates/partials/note_display.php` · `nextnotes/l10n/*` · `nextnotes/THIRD-PARTY-NOTICES.md`, `nextnotes/LICENSES/` · Doku: SPEC (neuer §4.7 „Blöcke“, §4.3.9, §11.1 P38, §11.2 B69, §13 M8), `docs/app/user.md`, `CHANGELOG.md` · `docs/adr/0018-*.md` **Nachtrag** (in M8a, gilt für M8b/M8c): (a) Leser des Rechenblocks sehen gespeicherte, statische Ergebnisse, keine Neuberechnung; nur der Graph wird bei Lesern gezeichnet, unter denselben Aufwandsgrenzen (§4). (b) `VERSION` und `BEREICH.VERSCHIEBEN` sind in HyperFormula geschützt, nicht entfernbar und harmlos; sie bleiben aktiv (Lead-Entscheidung, akzeptiert), die Whitelist gilt sonst unverändert. (c) Zirkelbezug zeigt den Engine-Code `#CYCLE!` (nicht `#ZYKLUS!`), Klartext im Tooltip. (d) Tabellenblock ist fest deutsch (Funktionsnamen, `;`, Dezimalkomma), unabhängig von der NC-Sprache (Lead-Entscheidung Q1).

**Markup-Vertrag** (stabil, versionslos, weil nur LaTeX):
```html
<span class="math-inline" data-math="LATEX" contenteditable="false">LATEX</span>
<div class="math-block" data-math="LATEX" contenteditable="false">LATEX</div>
```
- Inhalt = LaTeX als Text (statischer Fallback für Suche, ZIP-HTML ohne KaTeX, Lesen ohne JS). `data-math` ist maßgeblich. Markdown-Notizen und `MarkdownRenderer` bleiben bei `span.math-block` (Altbestand); der Sanitizer behandelt `span.math-block` gleich.
- Im Editor ersetzt `math-renderer.js` den Inhalt durch KaTeX; das ist Anzeige, gespeichert wird nach Normalisierung wieder der Text.

**Sanitizer-Regeln** (`HtmlSanitizer` + `BlockMarkup::normalizeMath`):
- `div` erlaubt zusätzlich `data-math`; `data-math` (wie `data-nn-sheet`, `data-nn-calc`) von `isDangerous` ausgenommen (Text, nie URL; KaTeX `trust:false`).
- Gemeinsame Regeln §1.1a (Attribut statt Klasse, Verschachtelung).
- Element mit `data-math` (Klasse `math-block` bei `div`, sonst `math-inline`, sofern keine der beiden vorhanden): Wert ≤ 10 000 Zeichen (sonst `data-math` entfernt → normaler Text), alle Kinder ersetzt durch einen Textknoten = `data-math`, `contenteditable="false"` gesetzt. Ohne `data-math`: normales Element.
- Wirkt in jedem `sanitizeHtml`-Aufruf (Speichern, Import, `/convert-markdown`, Excalidraw-Speichern), nicht in `sanitizeMarkdown`.

**Bedienung**: Klick/Enter auf Formel (schreibbar) öffnet Popover (an `body`, NC-Optik) mit `<math-field>`, Vorschau entfällt (MathLive ist WYSIWYG), Knöpfe Übernehmen (Enter), Abbrechen (Esc), Löschen, Umschalter Inline/Block. Übernahme schreibt `mf.getValue('latex-expanded')` (Standard-LaTeX ohne MathLive-Makros) in `data-math` und Text, rendert neu, löst Speichern aus, Einfügemarke hinter die Formel. Neue Formel: leerer Platzhalter, Abbrechen entfernt ihn. MathLive-Einstellungen: `fontsDirectory` absolut auf KaTeX-Fonts, `soundsDirectory = null`, `mathVirtualKeyboardPolicy = 'auto'` (Touch zeigt Bildschirmtastatur), `MathfieldElement.openUrl` überschrieben: nur `http:`/`https:` (per `new URL`), Öffnen mit `noopener`, alles andere (`file:`, `javascript:`, `data:`, relativ) wird ignoriert. Leere Formel beim Übernehmen = Löschen.
`data-math` stammt auch von Fremd-Autoren (Team, Austeilen) und wird in `<math-field>` geladen; Abwehr: MathLive escaped Attribute, `\htmlData`-`href` nur http/https, `\htmlStyle` über `validateInlineStyle` (Stichprobe 0.111.4), KaTeX-Anzeige mit `trust:false`. Belegt durch Done M8a 9.

**Bearbeitungsrecht und Sperre**: ohne Schreibrecht/fremde Sperre kein Popover, Formel nur angezeigt; Wechsel zur Laufzeit über Modusbeobachtung (§1.1). Keine neue Route.

**Export**: HTML/Druck/ZIP: gespeichertes HTML (Druckansicht rendert KaTeX über vorhandenen `math-renderer`; ZIP-HTML zeigt LaTeX-Text). JSON: Rohinhalt. Umwandlung HTML→Markdown (`NextBlocks::toMarkdownPre`, vor `HtmlToMarkdown`, gleiche Platzhalter-Technik wie `EmbeddedDiagrams`): inline `$LATEX$`, Block `\n$$\nLATEX\n$$\n`, `$`/Leerzeilen nach §1.1a. Rückweg Markdown→HTML liefert über den bestehenden Renderer wieder `math-inline`/`math-block` (Round-Trip-Test).

**Austeilen**: Kopie unverändert (DuplicateService); keine Änderung.

**Done (M8a)**:
1. Einfügen über Werkzeugleiste und `/Formel` (inline) bzw. „Formel-Block“, Bearbeiten, Löschen, Inline↔Block; nach Neuladen identisch; gespeichertes HTML exakt nach Vertrag (kein KaTeX-Markup, kein `<math>`).
2. B69: Markdown→HTML umgewandelte Notiz mit `$x^2$`, im Editor geöffnet und gespeichert → gespeicherter Inhalt enthält nur den Vertrag (vorher KaTeX-Ausgabe/Doppeltext).
3. MathLive-Ausgabe für eine Liste von 30 Schulformeln (Bruch, Wurzel, Index, Summe, Integral, Matrix 2×2, Vektorpfeil, griechisch, Einheiten mit `\text`) rendert in KaTeX 0.16.47 ohne `ParseError` (`throwOnError:true` im Test).
4. Leser (Lesefreigabe, Material) und fremde Sperre: Formel sichtbar, kein Popover; nach Freigabe der Sperre bearbeitbar ohne Neuladen.
5. CSP 0 Verletzungen (Hauptansicht schreibend/lesend, Druckansicht); keine Netzanfrage außer `'self'` (Request-Log), keine Anfrage auf `sounds/`.
6. Tastatur-Isolation (R1) für das Popover: `/`, `:`, `@`, Enter, Tab in `<math-field>` öffnen kein Slash-/Emoji-/Erwähnungsmenü und ändern die Notiz nicht.
7. Mobil 390 px und Tablet: Popover als Blatt, Bildschirmtastatur erscheint bei Touch; hell/dunkel Screenshots.
8. Bundle: `nextnotes-blocks.js` ≤ 12 KB min; MathLive lädt erst beim ersten Öffnen des Popovers (Netzwerk-Log); Größe in STATUS.
9. MathLive-Payloads: gespeichertes `data-math` mit `\htmlData{href=javascript:alert(1)}{x}`, `\cssId{evil}{x}`, `\class{nn-sheet}{x}`, `\htmlStyle{position:fixed;inset:0;background:url(https://example.org/x)}{x}`, `\href{javascript:alert(1)}{x}`, `\href{file:///etc/passwd}{x}` – im Popover geöffnet und angeklickt sowie in der KaTeX-Anzeige: kein Skript läuft (`dialog`/`alert`-Hook bleibt stumm), kein Element mit `href`/`src` auf `javascript:`/`file:`/`data:` im DOM, keine Netzanfrage nach `example.org`, kein Fenster öffnet sich, Popover deckt die Seite nicht ab.
10. Markdown-Umwandlung: Testfälle aus §1.1a (`a$b`, `\$5`, Leerzeile im LaTeX) brechen die Struktur nicht; Round-Trip ergibt wieder den Vertrag.
11. Sanitizer: `data-math` an `span`/`div` ohne Math-Klasse wird erkannt und normalisiert (Verschachtelungsregel §1.1a wird mit den Block-Attributen in M8b/M8c geprüft).
**Testnachweis**: PHPUnit `Security/BlockMarkupTest` (Normalisierung inline/Block/`span.math-block`, Erkennung ohne Klasse, Verschachtelung, Länge, `data:`-Text in `data-math` bleibt, Kinder-Ersatz, `contenteditable`, kein Einfluss auf Markdown-Notizen), Konverter-Test HTML→MD→HTML inkl. Krit. 10; bestehende Konverter-Fixtures: Abweichungen nur durch gefüllte Math-Spans (bewusst, B69). E2E neu `blocks-formula.mjs` (Krit. 1, 2, 4–7, 9), `lists-katex.mjs` und `editor.mjs` grün. `check-upstream` grün (P38, Hook-Name). Review speccritic Pflicht (Sanitizer).

## 3. M8b – Aktiver Tabellenblock

**Scope**: Rechenraster mit HyperFormula (Bezüge, Bereiche, Neuberechnung, Zirkelbezug, Bezugsverschiebung kommen aus der Engine), eigenes schlankes Raster (Ziel ≤ 600 Zeilen), Funktionsumfang laut ADR-Whitelist, statische Ergebnis-Tabelle für alle Nicht-Editor-Sichten. Grenzen/Funktionen/Formatierung exakt wie ADR 0018 Block 2.

**Dateien**: `nextnotes/build/{package.json,package-lock.json,bundles.json,build.mjs}`, neu `nextnotes/build/vendor-entries/hyperformula.mjs` · `nextnotes/js-src/{nextnotes-sheet.js (neu), nextnotes-blocks.js, nextnotes-bootstrap.js}` · neu `nextnotes/css-src/nextnotes-blocks-shadow.css`, `nextnotes-blocks.css`, `nextnotes-printview.css` · `nextnotes/lib/Service/{HtmlSanitizer.php, BlockMarkup.php}`, `Convert/NextBlocks.php` · `note_display.php` (Menüeintrag) · l10n · Lizenzen · Doku (SPEC §4.7, ADR 0018 Nachtrag, user.md, CHANGELOG).

**Markup-Vertrag (v1)**:
```html
<div class="nn-sheet" contenteditable="false" data-nn-sheet='{"v":1,"lang":"deDE","rows":5,"cols":5,"cells":[["Menge",2,"=SUMME(B1:B3)",null,null],…],"fmt":{"C1":{"b":1,"d":2,"p":0}}}'>
  <table class="nn-sheet-table"><tbody>
    <tr><td>Menge</td><td class="nn-num">2</td><td class="nn-num"><strong>7,00</strong></td><td></td><td></td></tr>
    <tr><td class="nn-err">#DIV/0!</td>…</tr>
  </tbody></table>
</div>
```
- `cells`: `rows`×`cols`, Zeilen zuerst; Zelle = `null` | Zahl (endlich, serverseitig `is_finite`) | Wahrheitswert | Text (≤ 1 000 Zeichen); Text mit führendem `=` ist Formel in der Sprache `lang`. Zahlen werden als JSON-Zahl gespeichert, nie als formatierter Text. `fmt`: Schlüssel A1-Adresse, `b` fett (0/1), `d` Dezimalstellen 0–4 (fehlt = automatisch), `p` Prozent (0/1).
- Statischer Fallback: volle Rastergröße, Ergebnis formatiert (Dezimalkomma, Prozent, Fett als `<strong>`), Klassen `nn-num` (rechtsbündig), `nn-err` (Fehlercode als Text), Datum als Datum. Kein `data-formula`, kein `pre/code`, keine Spaltenköpfe.
- Version `v` erlaubt spätere Erweiterung; unbekannte Version → nur Fallback, nicht bearbeitbar (Hinweis).

**Sanitizer-Regeln** (`BlockMarkup::validSheet`, aufgerufen aus `cleanAttributes`):
- `div` erlaubt `data-nn-sheet`; von `isDangerous` ausgenommen.
- Gültig nur: Wert ≤ 256 KiB, `json_decode` ohne Fehler (Tiefe ≤ 4), `v` = 1, `lang` ∈ {`deDE`}, `rows` 1–50, `cols` 1–26, `cells` exakt `rows`×`cols` mit erlaubten Typen, `fmt`-Schlüssel `/^[A-Z][1-9][0-9]?$/` innerhalb des Rasters mit erlaubten Werten, keine weiteren Schlüssel. Sonst wird **nur das Attribut** entfernt (Block bleibt als statische Tabelle).
- Gemeinsame Regeln §1.1a: Prüfung an jedem `div` mit dem Attribut, unabhängig von der Klasse; gültig → Klasse `nn-sheet` und `contenteditable="false"` gesetzt; Block-Hosts im Fallback werden samt Inhalt entfernt; jede Zahl `is_finite`. Fallback-Tabelle durchläuft sonst den normalen Sanitizer (kein Sonderrecht).
- Client rendert Zellinhalte ausschließlich per `textContent`; JSON nur per `JSON.parse` in Arrays/Objekte ohne Prototyp-Zugriff (`Object.hasOwn`).

**Whitelist-Technik HyperFormula (Entscheidung)**: Plugin-Auswahl statt Namensprüfung. Beim Laden des Moduls einmalig: Plugins der Whitelist-IDs mit `HyperFormula.getFunctionPlugin(id)` sichern → `HyperFormula.unregisterAllFunctions()` → je ID `HyperFormula.registerFunction(id, plugin)`; danach Prüfung `getRegisteredFunctionNames('deDE')` = Whitelist ∪ {`VERSION`, `'NN_GESPERRT_OFFSET`} (von HF geschützt, nicht entfernbar), sonst Modul bricht mit Konsolenfehler ab und Blöcke bleiben statisch. **Korrektur M8b-fix**: nicht harmlos – `BEREICH.VERSCHIEBEN` ignoriert `maxRows`/`maxColumns` (Speicherüberlauf); beide sind gesperrt durch eine Formel-Prüfung vor der Engine (nur deutsche Whitelist-Namen, keine `{…}`, Klammertiefe ≤ 64 → sonst `#NAME?`) und `OFFSET` heißt im Sprachpaket intern `'NN_GESPERRT_OFFSET` (M8b-fix2; `'`/`!` außerhalb von Zeichenketten sperren die Formel, Blattgröße nach jedem Engine-Aufruf geprüft); Rechenbudget 200 000 Bereichszellen je Blatt (SPEC §4.7.7, ADR 0018 Nachtrag M8b-fix).
Begründung: offizielle API (`.d.ts` 3.5.0: `unregisterAllFunctions`, `registerFunction`, `getFunctionPlugin`), Parser kennt fremde Namen gar nicht → `#NAME?` aus der Engine, kein eigener Formel-Lexer. Probe im Scratchpad (Node, esbuild-Bündel): 47 Namen registriert, `HYPERLINK`/`INDIREKT` → `#NAME?`, `SUMME`, `WURZEL`, `&`, `%`, Dezimalkomma, `#DIV/0!`, `#CYCLE!` korrekt.
- IDs (deDE-Namen aus HF-Sprachpaket): SUM, AVERAGE, MIN, MAX, COUNT, COUNTA, COUNTIF, SUMIF, ROUND, ROUNDUP, ROUNDDOWN, INT, ABS, MOD, SQRT, POWER, PI, EXP, LN, LOG, LOG10, SIN, COS, TAN, ASIN, ACOS, ATAN, RADIANS, DEGREES, IF, AND, OR, NOT, MEDIAN, STDEV.S, STDEV.P, VAR.S, VAR.P, QUARTILE.INC, CORREL, SLOPE, BINOM.DIST, NORM.DIST, NORM.INV, TODAY.
- **Lücke**: HF 3.5.0 hat **kein** `MODE.SNGL` (MODUS.EINF) und **kein** `INTERCEPT` (ACHSENABSCHNITT). Entscheidung: zwei kleine `FunctionPlugin`-Unterklassen über die offizielle Plugin-API (je ≤ 30 Zeilen, deutsche Übersetzung beim `registerFunction`), Ergebnis gegen LibreOffice-Referenzwerte getestet. Kein Eingriff in Bezüge/Abhängigkeiten (ADR-Option 4 bleibt ausgeschlossen).
- **Sprache (Q1, Lead-Entscheidung)**: Tabellenblock fest deutsch (Funktionsnamen, `;`, Dezimalkomma), unabhängig von der NC-Sprache des Kontos; nur die UI-Texte (Knöpfe, Tooltips) folgen NC-l10n. `lang` im JSON bleibt für spätere Erweiterung.
- Konfiguration: `licenseKey:'gpl-v3'`, `language:'deDE'`, `decimalSeparator:','`, `functionArgSeparator:';'`, `thousandSeparator:''`, `maxRows:50`, `maxColumns:26`, `useArrayArithmetic:false`, `evaluateNullToZero:true`, `useWildcards:false`, `useRegularExpressions:false` (M8b-fix: keine Platzhalter in ZÄHLENWENN/SUMMEWENN, ReDoS). Eine HF-Instanz je Block, nur im Schreibmodus.
- Fehlertexte: Anzeige des Engine-Codes (`#DIV/0!`, `#NAME?`, `#WERT!`, `#ZAHL!`, `#BEZUG!`, `#NV`, `#CYCLE!`), Tooltip/`aria-describedby` mit eigenem Klartext je `ErrorType` (l10n), nicht der englischen Engine-Meldung.

**Raster (Shadow DOM)**: Formelzeile (Adresse + Eingabefeld, zeigt Rohinhalt), Tabelle mit Spalten A–Z/Zeilen 1–50, „+ Zeile“, „+ Spalte“, Kontextmenü/Knöpfe „Zeile/Spalte löschen“ (HF `addRows`/`removeRows`/`addColumns`/`removeColumns` → Bezüge verschieben), Formatknöpfe Fett, Dezimalstellen −/+, Prozent. Tastatur: Pfeile, Enter (unten), Tab (rechts), Shift+Tab, Entf (leeren), Esc (Bearbeiten abbrechen), F2/Tippen (bearbeiten), Strg+C/Strg+V als TSV (Bereich, höchstens bis Rastergrenze, `=` am Anfang = Formel), Strg+Z/Y im Block über HF `undo/redo`. Auswahl als Bereich per Shift+Pfeile/Ziehen. ARIA `role="grid"`.

**Bearbeitungsrecht und Sperre**: schreibbar → Shadow-UI mit HF (lazy). Leser, Material, abgegebene Abgabe, fremde Sperre, Prüfphase der Sperre → **statische Tabelle ohne Engine** (Ergebnisse sind gespeichert; `HEUTE()` zeigt den Stand der letzten Speicherung). Wechsel des Modus ohne Neuladen. Kein eigener Speicherweg, keine neue Route; 423/403 des PATCH greifen wie bei Text.

**Export**: HTML/Druck/ZIP/`html_embedded`/Verlauf/Abgabe-Lesemodus: statische Tabelle (Print-CSS: Rahmen, `nn-num` rechts). JSON: Rohinhalt mit Formeln. Umwandlung HTML→Markdown: `NextBlocks` entfernt Host und JSON, die Fallback-Tabelle wird durch den vorhandenen Tabellen-Konverter zur Markdown-Tabelle **mit Ergebnissen**.
**Export-Format der Tabellenformeln (Entscheidung)**: keine Formeln im Markdown (weder Kommentar noch JSON-Anhang). Begründung: HTML-, JSON- und ZIP-Export enthalten den Block verlustfrei (`data-nn-sheet`); Markdown kennt keine Formeln, ein Kommentar wäre versteckte Nutzlast und bräuchte Rückübersetzung im portierten `MarkdownRenderer` (Port-Datei, B46-Regel). Folge: Umwandeln in Markdown friert Ergebnisse ein; steht in `user.md`, der Umwandeln-Dialog bleibt unverändert.

**Austeilen**: Kopie unverändert; Schüler rechnen in der eigenen Kopie (schreibbar bis Abgabe), Vorlage bleibt unberührt; Lehrkraft sieht im Lesemodus die statische Tabelle.

**Hell/Dunkel, Mobil**: §1.5/§1.6; Zellbreite min. 64 px, Formelzeile volle Breite, Knöpfe in Überlaufmenü ≤ 600 px.

**Done (M8b)**:
1. `/Tabelle` (Slash-Suche, Eintrag „Tabelle rechnen“; Upstream schließt das Menü beim Leerzeichen) und Werkzeugleiste fügen 5×5 ein; Eingabe `2`, `3,5`, `=SUMME(A1:A2)` → 5,5; Neuladen identisch; JSON und Fallback nach Vertrag.
2. Zeile oberhalb einfügen und Spalte löschen verschieben Bezüge (`=SUMME(A2:A3)`); Zirkelbezug zeigt `#CYCLE!` mit deutschem Tooltip; `=HYPERLINK("x")`, `=INDIREKT("A1")`, `=ZUFALLSZAHL()` → `#NAME?`; M8b-fix: `VERSION()`, `BEREICH.VERSCHIEBEN(…)`, englische Namen (`SUM`, `OFFSET`) und `{…}` → `#NAME?`, `WAHR()`/`FALSCH()` rechnen, Platzhalter-Kriterium `"*a*a*a*b"` < 200 ms.
3. Alle 47 Whitelist-Namen (inkl. MODUS.EINF, ACHSENABSCHNITT) außer `HEUTE` liefern die LibreOffice-Referenzwerte eines Fixture-Blatts (Toleranz 1e-9). `HEUTE` gesondert, da ohne festen Referenzwert: `=HEUTE()` zeigt das lokale Datum des Testlaufs als Datum (Vergleich mit dem Browser-Datum, Lauf nicht um Mitternacht), `=HEUTE()-HEUTE()` = 0.
4. Grenzen: 51. Zeile/27. Spalte nicht anlegbar; TSV-Einfügen 60×30 kappt auf 50×26 mit Hinweis.
5. Tastatur laut Liste; Tastatur-Isolation (R1): Tippen von `/`, `:`, `@`, `- `, `[ ]`, Enter, Tab, Strg+Z in Zellen/Formelzeile ändert nichts außerhalb des Blocks und öffnet kein Upstream-Menü.
6. Leser/Material/fremde Sperre: statische Tabelle, **kein** Laden von `hyperformula-*.js` (Netzwerk-Log); Sperre frei → bearbeitbar ohne Neuladen. Team-Workspace: A öffnet die Notiz (Sperre wird beim Laden erworben, `note-edit-lock.js` `handleCurrentNoteLoaded` → `acquireLock`) → B öffnet dieselbe Notiz, sieht das Sperr-Banner und den Block statisch; A schließt → B kann ohne Neuladen bearbeiten. Zusätzlich: `.noteentry` mit `data-nextnotes-readonly` und per Skript gesetztem `contenteditable="true"` → Block bleibt statisch.
7. Sicherheit: gespeichertes Attribut mit `"<img src=x onerror=…>"` als Zelltext erscheint nur als Text (Editor, Fallback, Druck); manipuliertes JSON (falscher Typ, 51 Zeilen, Zusatzschlüssel, `__proto__`, 300 KiB, Zahl `1e999`) → Attribut entfernt, Tabelle bleibt; Zelltext `javascript:`/`data:` behält den Block; `data-nn-sheet` an `div` ohne Klasse wird geprüft und erkannt; `div[data-nn-sheet]` oder Formel (`data-math`) im Fallback eines Tabellenblocks wird samt Inhalt entfernt (`data-nn-calc` folgt in M8c).
8. Austeilen: Kopie enthält identisches Attribut; Schüler ändert Zelle → Vorlage unverändert.
9. Umwandeln HTML→Markdown ergibt Markdown-Tabelle mit Ergebnissen; Suche findet Zelltext und Ergebnis, nicht Formeltext.
10. CSP 0 Verletzungen; hell/dunkel, 390 px, Tablet; Bündelgröße in STATUS.
**Testnachweis**: PHPUnit `Security/BlockMarkupTest` (Sheet-Schema gültig/ungültig je Regel inkl. `1e999`/`is_finite`, Erkennung ohne Klasse, Verschachtelung, `isDangerous`-Ausnahme, Fallback-Sanitizing), Konverter-Test; E2E neu `blocks-sheet.mjs` (Krit. 1–10), Fixture-Blatt für Krit. 3 unter `tests/nextnotes/fixtures/sheet/`; `smoke-m6` (Austeilen) grün. Review speccritic Pflicht (Sanitizer, Rechte/Sperre).

## 4. M8c – Rechenblock („Algebra-Spalte“)

**Scope**: Zeilenweise Rechnung mit math.js (Variablen über Zeilen, Einheiten, Brüche als Anzeige), Ergebnis rechts je Zeile; optional Graph (function-plot) für Funktionen mit einem Parameter. Gespeichert: Eingabetext + statische Ergebnisse.

**Dateien**: `nextnotes/build/{package.json,package-lock.json,bundles.json,build.mjs}`, neu `nextnotes/build/vendor-entries/mathjs.mjs` · `nextnotes/js-src/{nextnotes-calc.js (neu), nextnotes-blocks.js, nextnotes-bootstrap.js}` · CSS wie M8b · `BlockMarkup.php`, `HtmlSanitizer.php`, `Convert/NextBlocks.php` · `note_display.php` · l10n · Lizenzen · Doku.

**Markup-Vertrag (v1)**:
```html
<div class="nn-calc" contenteditable="false" data-nn-calc='{"v":1,"src":"a = 3\nb = a^2 + 1\n5 km / 20 min to km/h","graph":1}'>
  <p class="nn-calc-line"><span class="nn-calc-in">a = 3</span> <span class="nn-calc-out">3</span></p>
  <p class="nn-calc-line"><span class="nn-calc-in">b = a^2 + 1</span> <span class="nn-calc-out">10</span></p>
  <p class="nn-calc-line"><span class="nn-calc-in">5 km / 20 min to km/h</span> <span class="nn-calc-out">15 km/h</span></p>
  <p class="nn-calc-graph">Graph: f(x)</p>
</div>
```
`src` ≤ 20 000 Zeichen, ≤ 200 Zeilen, Zeile ≤ 500 Zeichen; `graph` 0/1 (Standard 1, sobald eine Funktion mit einem Parameter existiert; Knopf „Graph ausblenden“). Fehlerzeile: `<span class="nn-calc-out nn-err">Fehler: …</span>`. Kein `pre/code` (Upstream-Codeblock-Skripte).

**Sanitizer-Regeln**: `div` erlaubt `data-nn-calc`, von `isDangerous` ausgenommen; gültig nur JSON ≤ 64 KiB mit genau `v`=1, `src` (String, Grenzen oben), `graph` ∈ {0,1}; sonst Attribut entfernt (Fallback bleibt). Gemeinsame Regeln §1.1a: Prüfung an jedem `div` mit dem Attribut unabhängig von der Klasse, gültig → Klasse `nn-calc` und `contenteditable="false"`; Block-Hosts im Fallback samt Inhalt entfernt; `data-nn-sheet` und `data-nn-calc` am selben Element → beide entfernt. Fallback normal bereinigt. Client rendert Ein-/Ausgaben per `textContent`.

**math.js-Einschränkung (Entscheidung, drei Schichten)**:
1. **Eigenes Bündel aus Whitelist-Factories** (`vendor-entries/mathjs.mjs`, `create({...}, {predictable:true})`, Zahlentyp `number`; exportiert als Global `NextNotesMath` nur `{check, run, plotFn}`, nicht die Instanz): add, subtract, multiply, divide, pow, unaryMinus, unaryPlus, mod, abs, sqrt, cbrt, nthRoot, exp, log, log10, log2, round, floor, ceil, fix, sign, gcd, lcm, factorial, combinations, permutations, mean, median, std, variance, sum, hypot, sin, cos, tan, asin, acos, atan, atan2, pi, e, equal, unequal, smaller, larger, smallerEq, largerEq, and, or, not, xor, unit, to, number, evaluate (nur für den eigenen Aufruf), format. **Ohne** `min`/`max` (sonst liest der Parser `20 min` als Funktion – Probe: mit ihnen scheitert `5 km / 20 min to km/h`, ohne ergibt es 15 km/h), ohne Matrix-Erzeuger, Zufall, BigNumber, Fraction-Typ, Complex, `simplify`, `derivative`, `rationalize`, `createUnit`.
2. **Reihenfolge im Bündel (Pflicht)**: (a) Instanz erzeugen; (b) **eigene Referenzen sichern**: `const parse = math.parse, compile = math.compile, evaluate = math.evaluate` (nur im Modul-Closure, nie global); (c) danach **ein** `math.import({...}, {override:true})` mit werfenden Stubs und den gedeckelten Wrappern (`factorial`, `combinations`, `permutations`, Argument ≤ 10 000). Schicht 3 und Graph nutzen ausschließlich die gesicherten Referenzen. Probe r2 (Node, `math.js` 15.2.0): gesichertes `evaluate('1+2')` = 3 und `parse('2*3').compile().evaluate()` = 6 nach dem Import; Ausdrücke `evaluate("1")`, `parse("1")`, `config({number:"BigNumber"})` treffen den Stub. Stubs (math.js-Doku „Security“): `import`, `createUnit`, `reviver`, `replacer`, `evaluate`, `parse`, `compile`, `config`, `typed`, `chain`, `simplify`, `derivative`, `resolve`, `matrix`, `help`. Probe: im Whitelist-Bündel waren `evaluate("…")`, `parse(…)` und **`config({number:"BigNumber"})`** aus einem Ausdruck heraus aufrufbar und änderten die Instanz – daher Pflicht.
3. **AST-Whitelist vor dem Auswerten** (`parse` aus dem eigenen Code, dann Prüfung): erlaubt `ConstantNode`, `SymbolNode`, `OperatorNode` (inkl. `to`), `ParenthesisNode`, `ConditionalNode`, `RelationalNode`, `FunctionNode` (Name ∈ Whitelist oder in früherer Zeile definierte Funktion), `AssignmentNode` (nur Symbol-Ziel), `FunctionAssignmentNode`. Verboten: `ArrayNode`, `AccessorNode`, `IndexNode`, `ObjectNode`, `RangeNode`, `BlockNode` (mehrere Anweisungen). `SymbolNode` nur: Parameter, früher definierter Name, Konstante (`pi`, `e`), gültige Einheit (`math.Unit.isValuelessUnit`). Funktionsrumpf darf nur Parameter, Konstanten, Einheiten, Whitelist-Funktionen und **in früheren Zeilen** definierte Namen nutzen; eine Funktion darf nicht neu definiert werden → keine (wechselseitige) Rekursion.
- **Aufrufbare Funktionen (abschließend)**: Ein `FunctionNode` ist nur gültig, wenn sein Aufrufziel (`node.fn`) ein `SymbolNode` ist **und** dessen Name in dieser Liste steht oder eine in einer früheren Zeile definierte Funktion ist: `abs, sqrt, cbrt, nthRoot, exp, log, log10, log2, round, floor, ceil, fix, sign, gcd, lcm, mod, pow, hypot, factorial, combinations, permutations, mean, median, std, variance, sum, sin, cos, tan, asin, acos, atan, atan2`. Alles andere ist aus Ausdrücken **nicht aufrufbar**, auch wenn es im Bündel steckt – ausdrücklich `evaluate`, `parse`, `compile`, `import`, `config`, `createUnit`, außerdem `unit`, `number`, `format`, `to`, `typed`, `chain`, `reviver`, `replacer`, `help` und die Operator-Funktionen (`add` … `xor`). Prüfung per `Object.hasOwn` gegen eine eingefrorene Liste.
- **Operatoren**: `OperatorNode` nur mit `fn` ∈ {`add`, `subtract`, `multiply`, `divide`, `pow`, `mod`, `unaryMinus`, `unaryPlus`, `factorial`, `equal`, `unequal`, `smaller`, `larger`, `smallerEq`, `largerEq`, `and`, `or`, `not`, `xor`, `to`}; sonst Fehler.
- **Namen**: Ziel von `AssignmentNode`/`FunctionAssignmentNode` und Parameternamen dürfen weder im math.js-Namensraum (`name in math`) ~~noch Einheit~~ (Umsetzung M8c: Einheiten dürfen verdeckt werden, `b` ist eine Einheit, Done 1) noch bereits definiert sein (Parameter dürfen Variablen verdecken) → `sin = 3`, `evaluate = 1`, `f(x)=…` ein zweites Mal werden abgelehnt.
- **Aufwandsgrenzen (kein Worker unter der NC-CSP)**: Zeilen-/Längengrenzen oben; `factorial`/`combinations`/`permutations` gedeckelt (s. o.). Benutzerdefinierte Funktionen werden **nicht** von math.js angelegt: `FunctionAssignmentNode` wird nicht ausgewertet, sondern der geprüfte Rumpf mit der gesicherten `compile` übersetzt und als Wrapper in den Scope gelegt. **Jeder Wrapper** prüft vor dem Rumpf ein gemeinsames Budget-Objekt der laufenden Auswertung:
  - Aufrufzähler: höchstens **10 000** Aufrufe benutzerdefinierter Funktionen je Auswertung,
  - Tiefe: höchstens **32** verschachtelte Aufrufe (Zähler hoch vor, runter nach dem Rumpf, auch bei Fehler),
  - Zeit: `performance.now()` gegen Frist **150 ms** ab Beginn der Auswertung.
  Überschreitung wirft einen eigenen Fehler; die aktuelle Zeile zeigt „Fehler: Rechenaufwand zu groß“, alle folgenden Zeilen „nicht berechnet“. „Auswertung“ = ein Durchlauf über alle Zeilen (nach 300 ms Entprellung); dieselbe Frist wird zusätzlich zwischen den Zeilen geprüft. Ohne benutzerdefinierte Funktionen ist der Aufwand je Zeile durch Länge und Deckel begrenzt. Begründung: Probe r2 – Kette `f0(x)=x+1`, `f1(x)=f0(x)+f0(x)` usw. bis `f22` braucht ohne Grenze 1 345 ms in **einer** Zeile; Rekursionsverbot allein reicht nicht.
- **Zahlenformat**: Eingabe Dezimalkomma **und** Dezimalpunkt (`3,5` = `3.5`), Argumente mit `;` (wie Tabellenblock); Umsetzung durch einen kleinen Vorlauf außerhalb von Zeichenketten (`,` zwischen Ziffern → `.`, `;` → `,`, anderes `,` → Fehler „Argumente mit ; trennen“). Ausgabe mit Dezimalkomma, bis 10 signifikante Stellen; nicht ganzzahliges Ergebnis zusätzlich als Bruch, wenn Nenner ≤ 1 000 und Abweichung < 1e-12 (z. B. `1/3 + 1/6` → `0,5 = 1/2`). Funktionsnamen englisch wie Taschenrechner (`sqrt`, `sin`, `round`), Liste in `user.md`.

**Graph**: function-plot nur mit `fn` als JS-Funktion (`NextNotesMath.plotFn`: gesicherte `compile` des geprüften Rumpfs, im selben Wrapper-Budget), `graphType:'polyline'`, `sampler:'builtIn'`, keine String-Ausdrücke, kein `derivative`/`tip`-Ausdruck; höchstens 5 Funktionen, x −10…10, Zoomen/Verschieben (d3-zoom) ohne Speicherung. Achsen/Gitter per Shadow-CSS aus NC-Variablen. `nSamples: 300` fest.
- **Grenze je Zeichenlauf**: Zeichenlauf = ein synchroner Aufruf von function-plot (Erstzeichnen oder je Zoom-/Verschiebe-Schritt). Der erste `fn`-Aufruf ohne aktiven Lauf startet ein neues Budget und plant per `queueMicrotask` dessen Ende; damit hängt die Abgrenzung nicht an function-plot-Interna. Budget je Lauf über alle Funktionen und Stützstellen: höchstens **50 000** Aufrufe benutzerdefinierter Funktionen, Tiefe 32, Frist **100 ms**. Bei Überschreitung liefert `fn` für den Rest des Laufs `NaN` (kein Wurf in d3); nach dem Lauf wird der Graph entfernt, Zoom abgeschaltet und „Graph zu aufwendig“ angezeigt – es folgen keine weiteren Läufe.
- **Vorprüfung**: vor dem ersten Zeichnen ein Probeaufruf je Funktion bei x = 0 unter ~~frischem Budget~~ einem gemeinsamen Budget für alle Funktionen (M8c-fix), als eigene Task vor dem Zeichnen; ~~Aufrufe × 300 Stützstellen > 50 000 → kein Graph~~ sprengt der Probeaufruf das Laufbudget (z. B. Tiefe) → kein Graph, gleicher Hinweis (Umsetzung M8c, ADR 0018 Nachtrag M8c: sonst bestünde `f7` die Vorprüfung nicht, Done 3a).

**Bearbeitungsrecht und Sperre**: schreibbar → Shadow-UI (Textfeld zeilenweise, Ergebnisse rechts, live). Leser/Sperre → statischer Fallback mit gespeicherten Ergebnissen (keine Neuberechnung der Zeilen, ADR-0018-Nachtrag); hat der Block `graph`=1 und eine Funktion, wird **nur der Graph** lazy nachgeladen (Shadow Root mit `<slot>` zeigt den Fallback darüber). Dafür werden die Zeilen bis zur letzten Funktionsdefinition unter AST-Prüfung und Auswertungs-Budget ausgeführt (Funktionen dürfen frühere Variablen nutzen); das Zeichnen läuft unter der Grenze je Zeichenlauf. Der gespeicherte `src` gilt dabei als fremder, möglicherweise manipulierter Text. Keine Route, Speichern wie Text.

**Export**: HTML/Druck/ZIP: statische Zeilen + Hinweiszeile „Graph: f(x)“ (kein Bild). JSON: Rohinhalt. Umwandlung HTML→Markdown: Codeblock ohne Sprache, ~~Zaunlänge nach §1.1a~~ Zaun aus drei Backticks, Zeile nur aus ```` ``` ```` + ` #` (Umsetzung M8c: der Port-Renderer kennt nur Dreierzäune), je Zeile `Eingabe  # = Ergebnis` (`#` ist Kommentar in math.js, der Block bleibt als Eingabe gültig), Fehler `# Fehler: …`.

**Austeilen**: Kopie unverändert.

**Done (M8c)**:
1. `/Rechnen` fügt Block ein; `a = 3`, `b = a^2 + 1` → 10; `5 km / 20 min to km/h` → 15 km/h; `sin(30 deg)` → 0,5; `3,5 * 2` → 7; `1/3 + 1/6` → `0,5 = 1/2`; Neuladen identisch.
2. Abgelehnt mit Fehlerzeile, Instanz unverändert: `import({x:1})`, `evaluate("2+2")`, `parse("1")`, `compile("1")`, `config({number:"BigNumber"})` (danach `0.1+0.2` weiter Zahl), `createUnit("foo")`, `unit("5 m")`, `format(1)`, `[1,2,3]`, `a.b`, `x = evaluate`, `sin = 3`, `evaluate = 1`, `f(x) = f(x-1)`, wechselseitige Rekursion über Neudefinition, `combinations(1e9; 5e8)` und `170000!` (≤ 50 ms bis Fehler).
2a. Aufrufziel (Node-Test gegen das gebaute Bündel `NextNotesMath.check`): `f(1)(2)` und `(sin)(1)` werden nie als Aufruf eines Nicht-Symbols ausgeführt (math.js liest beide als implizite Multiplikation; `(sin)(1)` scheitert am nackten Symbol `sin`, `f(1)(2)` ergibt `f(1)·2`); ein programmatisch gebauter `FunctionNode` mit `ParenthesisNode`- bzw. `FunctionNode`-Ziel wird von `check` abgelehnt.
2b. Stub trotz umgangener AST-Prüfung (Node-Test): `evaluate("1")`, `parse("1")`, `compile("1")`, `import({})`, `config({})`, `createUnit("foo")` über die gesicherten Referenzen **ohne** `check` ausgewertet → jeweils Stub-Fehler; die gesicherten Referenzen selbst funktionieren weiter (`1+2` = 3).
3. Aufwandsgrenzen, je Grenze ein eigener Fall (Lead-Korrektur nach Review r2, Aufrufzahl von `fN` = 2^(N+1) − 1): **Zähler** – `f0(x) = x + 1`, `f1(x) = f0(x) + f0(x)`, …, `f20(x) = f19(x) + f19(x)`, `f20(1)` (Tiefe 21 < 32, ~2 Mio. Aufrufe > 10 000): die Zeile zeigt nach **≤ 250 ms** ab Auswertungsbeginn „Fehler: Rechenaufwand zu groß“, folgende Zeilen „nicht berechnet“; kein Long Task > 300 ms (PerformanceObserver); unmittelbar danach nimmt die Notiz außerhalb des Blocks Text an. **Tiefe** – `g1(x)=x`, `g2(x)=g1(x)` … `g60`, `g60(1)` → Fehler durch Tiefengrenze (33 Aufrufe, Zähler nicht erreicht). **Frist** – in `calc-guard.mjs` per Test-Hook verlangsamter Wrapper (Zähler und Tiefe unterschritten) → Abbruch durch die 150-ms-Frist. 200 leichte erlaubte Zeilen (z. B. `aN = N * 2`) werden alle berechnet, Long Task ≤ 300 ms; bei 200 schweren erlaubten Zeilen darf die Frist restliche Zeilen als „nicht berechnet“ markieren, Long Task ≤ 300 ms. Hinweis Umsetzung: Der AST-Prüfer prüft `FunctionNode.fn` im Aufrufkontext gegen die Aufrufliste (nicht mit der allgemeinen SymbolNode-Regel), damit `sin(1)` erlaubt bleibt.
3a. Graph-Pfad bei Lesern und Inhabern (Attribut per API gespeichert, `nSamples` 300, Laufbudget 50 000): `f6(x)` (127 × 300 = 38 100 Aufrufe) wird gezeichnet; `f7(x)` (255 × 300 = 76 500) besteht die Vorprüfung bei x = 0 und wird **nur durch das Laufbudget** abgebrochen → Hinweis „Graph zu aufwendig“; `f40(x)` bricht an der Tiefe ab. Jeweils kein Long Task > 300 ms, Zoom-/Ziehgesten lösen keine weiteren Läufe aus, Tab bleibt bedienbar. Die Vorprüfung ist nur Heuristik; die harte Grenze ist das Laufbudget.
4. `f(x) = x^2 - 2` zeigt Graph (hell/dunkel); Leser sieht Fallback + Graph, ohne Textfeld; **0 CSP-Verletzungen**, insbesondere kein `script-src`-Verstoß aus function-plot.
5. Tastatur-Isolation (R1) im Textfeld wie M8b Krit. 5.
6. Sicherheit: `src` mit `<script>`/`<img onerror>` erscheint nur als Text; manipuliertes JSON (Zusatzschlüssel, `graph:2`, 201 Zeilen, `v:1e999`) → Attribut entfernt; `data-nn-calc` ohne Klasse wird erkannt; Rechenblock im Fallback eines anderen Blocks und umgekehrt → samt Inhalt entfernt; `data-nn-sheet` und `data-nn-calc` an einem Element → beide entfernt.
7. Umwandlung HTML→Markdown wie oben (`src` mit ```` ``` ```` bleibt im Codeblock, nachfolgender Absatz bleibt Absatz); Austeilen wie M8b Krit. 8; Suche findet Eingabe und Ergebnis.
8. Mobil/Tablet, Bündelgröße in STATUS.
**Testnachweis**: PHPUnit `Security/BlockMarkupTest` (Calc-Schema, `is_finite`, Klasse/Verschachtelung/Doppel-Attribut), Konverter-Test (Zaunlänge); Node-Skript ohne Browser `tests/nextnotes/node/calc-engine.mjs` gegen das gebaute Bündel (M8c-fix: dazu `tests/nextnotes/node/block-queue.mjs` für die Rechenwarteschlange) (Krit. 2, 2a, 2b, Zähler/Tiefe/Frist ohne Browser); E2E neu `blocks-calc.mjs` (Krit. 1, 3–8, 3a). Abschluss M8: volle Matrix (`run-m7.sh` bzw. Nachfolger) einmal am Meilenstein-Ende (Test-Zeitbudget). Review speccritic Pflicht (Sanitizer, Ausführung fremder Ausdrücke).

## 5. Bekannte Upstream-Bugs (in M8a in SPEC §11.2 aufnehmen)
- **B69 (vermutet, in M8a per Test belegen)**: HTML-Notizen mit `.math-inline`/`.math-block` (entstehen bei Markdown→HTML-Umwandlung) werden im Editor von `math-renderer.js` mit KaTeX gefüllt; beim Speichern landet die KaTeX-Ausgabe (Spans mit `style`, MathML) im Inhalt, der Sanitizer packt `<math>`-Elemente aus → Doppeltext in Suche/Export. Fix: Normalisierung §2 (serverseitig, kein `src/`-Patch). Nebeneffekt: HTML-/Druck-Export einer Markdown-Notiz enthält in den Math-Spans jetzt den LaTeX-Text statt leer.

## 6. Risiken
| # | Risiko | Gegenmaßnahme |
|---|---|---|
| R1 | Upstream-Capture-Listener (`checklist`, `bulletlist`, `emoji-`/`mention-autocomplete`, `slash-command`, `copy-code-on-focus` Undo) reagieren auf Tasten im Shadow-DOM-Feld (Ziel = Host) | Pflicht-E2E je Block; Befund → Abfangen im Kern (Capture auf `window` vor Upstream nur für Ereignisse mit `composedPath()` im Block), kein `src/`-Patch; zweiter Fehlschlag → Lead |
| R2 | Sperre wird beim Laden der Notiz erworben (`handleCurrentNoteLoaded` → `acquireLock`), nicht bei Eingabe; geht sie verloren (Ablauf, fremde Übernahme), muss der Block statisch werden | Modusbeobachtung (§1.1); E2E M8b Krit. 6 („A öffnet → B sieht Banner, Block statisch“) |
| R3 | MathLive-LaTeX nicht KaTeX-kompatibel | `latex-expanded`, Formelliste M8a Krit. 3 |
| R4 | Ladezeit MathLive (229 KB gz) im Schul-WLAN | nur beim Bearbeiten; Spinner im Popover; Messung in STATUS |
| R5 | Rechenlast im Hauptthread (math.js), auch durch Funktionskomposition; Leser rechnen Autoren-Text für Graphen | AST-Whitelist; Aufrufzähler, Tiefe, Frist in jedem Wrapper je Auswertung und je Zeichenlauf; Leser ohne Zeilen-Neuberechnung; Done M8c 3/3a |
| R6 | NC streicht künftig `style-src 'unsafe-inline'` | betrifft auch KaTeX (Bestand); dann eigene ADR |
| R7 | function-plot-Pfad mit `new Function` bleibt im Bündel | nur Funktions-`fn`; E2E zählt Verletzungen; Code-Kommentar an der Aufrufstelle |
| R8 | Upstream-Merge berührt `getSlashCommands()` | P38 eine Zeile, `check-upstream` prüft Hook |
| R9 | JSON-Attribut verbraucht die 4-KiB-Kartenvorschau (Dashboard/Tagebuch) | kosmetisch, dokumentiert; Block steht selten am Notizanfang |
| R10 | Konverter-Fixtures ändern sich durch B69-Fix | Abweichungen einzeln als bewusst markieren |
| R11 | GPL-3.0-only (HyperFormula) im AGPL-Paket | ADR 0018 entschieden; Lizenztext + Hinweis im Paket |
| R12 | Markup-Vertrag später ändern heißt Migration | `v`-Feld, unbekannte Version = nur Fallback |

## 7. [THOMAS]-Fragen
- Keine offen. **Q1 geschlossen (Lead, r2)**: Tabellenblock fest deutsch, unabhängig von der NC-Sprache; Rechenblock wie §4 (Dezimalkomma/-punkt, `;`, englische Funktionsnamen) für alle Konten.

## 8. Reihenfolge und Gates
M8a → M8b → M8c (ADR). Je Slice: speccritic gibt Plan frei → Umsetzung → tester (Slice-Stufe, sqlite/Apache, E2E des Slices + `editor`, `lists-katex`, `smoke-m6`) + speccritic Diff-Review (Sanitizer/Sicherheit) → Lead-Commit. Volle Matrix nur nach M8c.
