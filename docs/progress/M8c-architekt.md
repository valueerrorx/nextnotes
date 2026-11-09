# M8c – Architekt (Rechenblock mit Graph)

Status: fertig

## Auftrag
Rechenblock (math.js 15.2.0, function-plot 1.26.0) nach `docs/slices/M8-plan.md` §4 inkl. Done 3/3a (f0..f20, g1..g60, Frist-Hook), Sanitizer/Konverter, Tests, Instanz `nextnotes-m8c` (Port 10600).

## Erledigt
- Plan §4, §1, ADR 0018, M8b-Progress, blocks.js/sheet.js/BlockMarkup/NextBlocks/Renderer gelesen
- npm install --save-exact mathjs@15.2.0 function-plot@1.26.0 (Exit 0)
- build/vendor-entries/mathjs.mjs (Whitelist, Stubs, AST, Budget, run/plotFn/check, _test nur mit Flag), function-plot.mjs (esbuild statt UMD: AMD-sicher, Lizenzprüfung per Metafile)
- bundles.json vendorEntries +2; THIRD-PARTY-NOTICES (+ math.js NOTICE), LICENSES/BSL-1.0.txt
- make build 0 (169 Dateien): mathjs.min.js 372759 B (gz 106607), function-plot.min.js 226072 B (gz 68225)

- tests/nextnotes/node/calc-engine.mjs (10 Tests, Done 1/2/2a/2b/3/3a + Angriffsfälle + Grenzen) node --test Exit 0; Messwerte: f20 6,6 ms heavy, g60 32 Aufrufe, Frist 153 ms, f6 13 ms 300/300, f7 196/300 dead, f40 Vorprüfung null, 200 Deckel-Zeilen 133 ms
- Befunde: `NaN`/`Infinity` sind Parser-Literale (harmlos), `undefined` = leere Zeile, chain/help in math.js ohnehin nicht aus Ausdrücken erreichbar

- PHP: BlockMarkup::validCalc (+CALC_* Konstanten, Klasse nn-calc), HtmlSanitizer div+data-nn-calc, NextBlocks::calc/codeBlock (Dreierzaun, ```-Zeile + " #")
- tests/nextnotes/php/Security/CalcMarkupTest.php (37) + Scenario/CalcConversionTest.php (4): Host-PHPUnit OK; Filter Sheet|Block|Formula|Calc auf Host: 3 Fehler OCP\Server (nur Container) + BlockMarkupTest Quoting (libxml Host, vorbestehend?) → im Container prüfen

- plotFn: gemeinsames Laufbudget über alle Funktionen (Liste → Liste), Test f6+f6 → Abbruch
- js-src/nextnotes-calc.js (neu, 7274 B min, gz 3034), blocks.js (ITEMS a/b, BLOCKS n/view-Regex, insertBlock, Lesemodus mit Graph + <slot>), bootstrap poznoteEnsureMathjs/FunctionPlot, BlocksUiConfig +26 Texte, l10n de/de_DE (Format 1/2 Leerzeichen erhalten), CSS shadow/blocks/printview
- make build 0 (170 Dateien); blocks.js 12450 B (> 12 KiB Ziel um 162 B)
- Instanz nextnotes-m8c (10600) up.sh sqlite gestartet (Hintergrund)

- Instanz: up 0, install-app 0, occ app:enable, apache2ctl -k graceful 0
- function-plot.mjs: CJS-Interop (default ist exports-Objekt) → typeof-Prüfung
- E2E tests/nextnotes/e2e/blocks-calc.mjs neu: hell + dunkel grün (153 Prüfungen je Thema, run e2e5)

- blocks-sheet hell grün (217)
- Doku: SPEC §4.7 Punkte 2/3/10–13 + Adapter Punkt 9 + §13 M8c, ADR 0018 Nachtrag M8c, M8-Plan (Vorprüfung, Namen, Zaun durchgestrichen + Begründung), user.md, CHANGELOG
- make check-upstream 0, check-notices 0

- blocks-formula hell grün (147)
- PHPUnit Container sqlite Filter Sheet|Block|Sanitizer|Calc|Conversion|Markdown|NextBlocks|Formula: 185 OK, Exit 0 (Host-Abweichung BlockMarkupTest = libxml-Quoting, im Container grün)
- Größen: mathjs.min.js 372892 B (gz 105353, br 89366), function-plot.min.js 226127 (gz 67987, br 59632), nextnotes-calc.js 7274 (gz 3034), blocks.js 12450 (gz 4103), shadow.css 7741
- node --test calc-engine 10/10, sheet-engine 17/17
- Instanz abgebaut (down.sh all 0); demo/gftest/redis unberührt

## Nächster Schritt
- keiner (Bericht an Lead)

## Offene Punkte/Annahmen
- Befund: `b`, `t`, `s`, `m`, `g`, `h` sind math.js-Einheiten; Plan-Regel „Name nicht Einheit“ widerspricht Done 1 (`b = a^2 + 1`) → Namen dürfen Einheiten verdecken (Abweichung, in SPEC/ADR)
- Done 3a: f7 (255 × 300 > 50 000) würde die ×300-Vorprüfung nicht bestehen → Vorprüfung nur „Probeaufruf bei x = 0 sprengt das Laufbudget“ (Heuristik), harte Grenze Laufbudget
- Parameter dürfen Variablen verdecken (sonst `x = 2`, `f(x) = …` abgelehnt)
- Markdown-Zaun: Port-Renderer kennt nur ``` → fester Dreierzaun, Zeilen nie nur ``` (Ergebnis-Kommentar)

## fix (M8c-fix, Blocker aus docs/reviews/M8c-r1.md)
Status: fertig
Auftrag: Warteschlange + Seitenbudget für alle Blöcke (calc + sheet), gemeinsame Vorprüfung + draw in eigener Task, Einheiten-Regel vorab, internmap-Notice, Plan-Testname.

### Erledigt
- vorher: `node a7.mjs 20` → 6209 ms in einer Task; `node a5.mjs` → k50 probe 389 + draw 102 = 490 ms (k56+ probe 105, nicht gezeichnet)

- Texte blockDeferred/calcGraphDeferred: BlocksUiConfig.php, l10n de/de_DE
- blocks.js: Warteschlange (enqueue/pump, Folgeschritte vorn, PAGE_MS 1000, Reset bei leerer Schlange), mount → Job, deferred-Knopf, plot-Lib vorab; NextNotesBlocks.queue
- calc.js: draw = Vorprüfung + Zeichnen als Jobs (Token gegen veraltete), over-Knopf, user nach erster Auswertung
- mathjs.mjs: plotFn ein Vorprüfungsbudget; Namen vorab gesammelt (assigned) → nie Einheit
- make build 0; nachher a5: k50 probe 108 ms, nicht gezeichnet (vorher 490); a7 (altes Schema, unverändert 6197 ms, misst nicht die Schlange) → a7q mit Schlange (scratchpad/calc2/a7q.mjs): 20 Blöcke 4 gerechnet, max Task 157 ms, Summe 1093 ms
- Tests: calc-engine +Einheiten-Regel +5×90 ms (180 ms, abgelehnt) 10/10; neu tests/nextnotes/node/block-queue.mjs 3/3; sheet-engine 17/17
- Lizenzprüfung: internmap fehlt (inline d3-array); quickselect (Agafonkin, ISC) in d3-array; lruQueue (Nowak, ISC) in math.js; CSparse-Dateien nicht im Output (bytesInOutput 0)

- CSS .nn-block-deferred (shadow.css); Notices +internmap/quickselect/lruQueue; build.mjs prüft auch package.json-Abhängigkeiten (Test: internmap entfernt → Build-Fehler)
- Doku: ADR 0018 (Einheiten-Text, Nachtrag M8c-fix), SPEC §4.7.11/12/14, M8-Plan (Vorprüfung, Testdatei), user.md, CHANGELOG
- make build 0, check-upstream 0, check-notices 0
- E2E blocks-calc: Abschnitt 3b (12 schwere Blöcke Inhaber + Klick) und Leser-Fall
- Instanz nextnotes-m8c (10600) wird gestartet

- Instanz up/install/enable 0, graceful 0 (Service nc-sqlite)
- E2E calc hell Lauf 1: 3 Fehler Konsole – function-plot wirft bei leerem data (split: [data[0]]); Ursache Infinity/NaN-only
- Fix: plotFn ±Infinity → NaN; try/catch um functionPlot; Build-Patch bundles.json patches + build.mjs (esbuild.build async, Plugin, genau ein Treffer sonst die; Test: falsches from → FEHLER); Build reproduzierbar (sha256 gleich)
- plot-Lib vorab nur bei Funktionsdefinition (fn-Regex), sonst bricht „function-plot noch nicht“
- E2E-Fall f(x)=1/0 + g(x)=x ergänzt; Doku SPEC/ADR/Notices (Patch)

- E2E hell grün: blocks-calc 169, blocks-sheet 217, blocks-formula 147 (0 CSP); blocks-calc Meldungen mit max Long Task ergänzt
- Messung final: a5 k50 probe 108 ms (nicht gezeichnet); a7 (altes Schema) 6220 ms unverändert; a7q 20 Blöcke 4 gerechnet, max Task 156 ms, Summe 1101 ms
- node: calc-engine 10/10, sheet-engine 17/17, block-queue 3/3

- E2E blocks-calc hell+dunkel grün (169 je Thema; viele Blöcke: Inhaber max Long Task 240/250 ms, 2/12 gerechnet; Leser 216/235 ms, 4/12), blocks-sheet dunkel grün (217)
- PHPUnit Container Filter Block|Calc|Sheet|Formula|PageAssets|Config: 122 OK, Exit 0
- Instanz abgebaut (down.sh all 0); demo/gftest/redis unberührt; build 0, check-upstream 0, check-notices 0

### Nächster Schritt
- keiner (Bericht an Lead)

### Offen
- Long Tasks im Browser 216–250 ms bei schweren Blöcken (Auswertung 150 ms + DOM/Layout): unter 300, Marge klein
- tests/ geändert (Auftrag Lead: Node-/E2E-Tests), obwohl Rollenregel tests/ ausschließt

### Offen
