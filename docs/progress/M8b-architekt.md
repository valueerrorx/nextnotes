Status: fertig

# M8b – architekt (Tabellenblock / Rechentabelle)

Auftrag: M8-plan §3 (M8b) + §1 (Shadow-DOM-Mount, Fallback-Neuerzeugung) umsetzen; Review-M8a-Hinweise 1–5; Tests PHPUnit + E2E blocks-sheet.mjs, Instanz nextnotes-m8b Port 10400.

## Erledigt
- Plan, Review M8a, Progress M8a gelesen; ADR 0018, SPEC §4.7, Bestand gelesen
- `npm install --save-exact --ignore-scripts hyperformula@3.5.0` (build/package.json, Lockfile: hyperformula, chevrotain 6.5.0, regexp-to-ast 0.4.0, tiny-emitter 2.1.0) Exit 0
- LICENSES/GPL-3.0-only.txt (aus /usr/share/licenses/spdx)
- build/vendor-entries/hyperformula.mjs; Probe-Bündel 818927 B / gz 181378; nur `eval(fn)` (chevrotain toFastProperties, kein String → keine CSP)
- PHP: HtmlSanitizer div+data-nn-sheet; BlockMarkup::validSheet + Klasse nn-sheet; NextBlocks: Tabelle (oberste Ebene → eigene MD-Tabelle, `|`→`\|`; sonst Host auspacken)

- LO-Referenzwerte: soffice 26.8 headless, 46 Formeln (Scratchpad lo/gen.py) → fixture.json; Node-Probe Whitelist 49 Namen, Fixture 0 Fehler
- Befund: HF 3.5.0 GANZZAHL schneidet ab (-3,7 → -3) → eigenes INT-Plugin (Math.floor); arrayColumnSeparator ';', arrayRowSeparator '|'
- js-src/nextnotes-sheet.js (neu, Engine+Whitelist+3 Plugins+Raster), nextnotes-blocks.js (Shadow-Mount, Fallback-Neuerzeugung, poznoteBlockEvent, insertSheet, Formel-Limit 10 000), bootstrap (poznoteEnsureHyperFormula, Capture-Isolation R1)
- BlocksUiConfig +31 Texte, l10n de/de_DE; css-src nextnotes-blocks-shadow.css (neu), blocks.css, printview.css; THIRD-PARTY-NOTICES; build.mjs §3b vendorEntries + Notices-Prüfung; bundles.json vendorEntries
- make build Exit 0 (167 Dateien); gebautes Bündel + Modul: Fixture 0 Fehler
- Größen: hyperformula.min.js 818927 (gz 181392), nextnotes-sheet.js 23438 (gz 8363), nextnotes-blocks.js 13079 (gz 4226) > 12 KiB Ziel, shadow.css 4606
- Tests: php/Security/SheetMarkupTest.php (43 Fälle), php/Scenario/SheetConversionTest.php (4), fixtures/sheet/{functions.json,gen-libreoffice.py}; Host-PHPUnit (Scratchpad php/boot.php) grün
- NextBlocks: verschachtelter Sheet-Host wird ausgepackt (touched-Flag)
- Instanz nextnotes-m8b sqlite (Port 10401) up; App musste per occ app:enable aktiviert werden (install-app.sh aktiviert nicht)
- E2E tests/nextnotes/e2e/blocks-sheet.mjs neu; Fixes: Fokus nach Stylesheet-load (inst.focus), Maus bearbeitet per Doppelklick (Touch: zweites Antippen → Formelzeile), Slash-Suche „/Tabelle“ (Leerzeichen schließt Upstream-Menü), Autosave abwarten vor Navigation
- blocks-sheet hell: grün 178 Prüfungen (Exit 0)
- blocks.js 12178 B (≤ 12 KiB): insertSheet → NextNotesSheet.host(), Zeiger-Isolation ins Modul, Dokument-Observer zusammengelegt
- Doku: SPEC §4.7 Punkte 2/3/6–9, §11.1 P38 Spalten, §13 M8b, Adapter-Punkt 9; ADR 0018 Nachtrag M8b; user.md „Formeln und Rechentabellen“; CHANGELOG
- make build 0, check-upstream 0, check-notices 0
- E2E: blocks-sheet hell+dunkel grün (178/178 je Thema, run12); blocks-formula hell 147 grün, smoke-m6 hell 48 grün, editor hell 119 grün (Einzellauf; im Sammellauf 1× Beacon 503 = SQLite busy, flüchtig)
- Testfix: Austeilen – B verlässt die Abgabe vor DELETE (sonst 404 vom Poll)
- PHPUnit Container sqlite, Filter Sheet|Block|Sanitizer|Conversion|Markdown|NextBlocks|Formula: 142 OK (Exit 0); SheetMarkupTest Anführungszeichen-unabhängig
- Volle PHPUnit-Suite läuft (dauert > 25 min; erster Versuch abgebrochen, parallel zum Teillauf → SQLite busy)
- Volle PHPUnit-Suite abgebrochen (~60 min, Testbudget); Teilmenge s. o. grün
- E2E-Ergänzung Review M8a 2: Tabelle > 256 KiB abgelehnt, Formel > 10 000 Zeichen abgelehnt; blocks-sheet hell+dunkel 186/186 grün (run15)
- Instanz nextnotes-m8b abgebaut (down.sh all, Exit 0); demo/gftest unberührt

## Nächster Schritt
- keiner (Bericht an Lead)

## Offen/Annahmen
- check-notices.mjs (dev/) deckt vendorEntries nicht ab → Prüfung in build.mjs (Metafile vs. THIRD-PARTY-NOTICES), Änderung an check-notices nur im Bericht

# fix (M8b-fix, Blocker aus docs/reviews/M8b-r1.md)
Status: fertig
Auftrag: B1 Platzhalter aus, B2 geschützte Funktionen unerreichbar, WAHR/FALSCH, H1–H5, Ressourcen-Grenzen (Spill, A:A), Tests.
## Erledigt
- Node-Probe (Scratchpad m8bfix/probe*.cjs, stress*.cjs, s3/s4.cjs): vorher 49 deDE-Namen inkl. VERSION/BEREICH.VERSCHIEBEN erreichbar, außerdem alle englischen IDs (SUM …) per Kanon-Fallback; „BEREICH VERSCHIEBEN(“ (Regex-Punkt) ging auch
- Befunde Ressourcen: SUMME/MEDIAN/MODUS.EINF mit 80 Bereichen × 650 Zellen 3–26 s; Matrix-Literal {…} wächst über 26 Spalten (400); Klammertiefe ≥ 200 → Stack-Überlauf (Throw); BINOM/NORM/A:A/1:1/A1:A1000000 unkritisch
- nextnotes-sheet.js: useWildcards/useRegularExpressions false; TRUE/FALSE in IDS; OFFSET-Übersetzung → NN_GESPERRT_OFFSET ('(?!)' scheitert an chevrotain-Optimierung); guard() (Whitelist-Namen, kein {, Tiefe ≤ 64) → '=NN.GESPERRT()'; cost()/total() Budget 200 000 Bereichszellen (Laden: limited(), Edit: setCells wirft HEAVY vor HF, change() prüft nach Strukturänderung); H2 Zellgrenze in change(); structure() behält gesperrte Formeltexte; functionNames = erreichbar, registeredNames neu
- Budget-Messung (Node, Hauptkontext; vm-Kontext verfälscht ×4): schlimmster Fall am Budget 130 ms (MODUS.EINF), vorher 26 s
- H1 blocks.js: Modul vor Shadow Root mounten, .catch → <slot>; H3: dragover/drop in Capture-Liste, poznoteBlockEvent preventDefault + dropEffect none + drag-over-Klasse weg
- H4 NextBlocks::cellText: |*_~=$[]<>` escapen, Zelle mit \ → Code-Span (Renderer liest \\\| als Lauf); Rückweg per Host-PHP (Scratchpad m8bfix/md3.php) 17/17 OK
- l10n de/de_DE + BlocksUiConfig: sheetCellLong, sheetHeavy
- Doku: SPEC §4.7 Punkte 3/7/9, ADR 0018 (b) korrigiert + Nachtrag M8b-fix, M8-plan §3 (harmlos, Konfiguration, Done 1/2), user.md, CHANGELOG
- tests/nextnotes/node/sheet-engine.mjs neu (10 Tests, node --test) Exit 0; Gegenprobe useWildcards:true → Timeout > 10 s
- SheetConversionTest::testTableCellsRoundTripAsText (H4) Host-PHPUnit 5/5 OK
- Drop-Abwehr in bootstrap (Capture) statt blocks.js (Größe); blocks.js min 12246 B (≤ 12 KiB); Fehlerpfad: unmount + Eintrag bleibt
- make build 0, check-upstream 0, check-notices (node dev/nextcloud/scripts/check-notices.mjs nextnotes .) 0
- E2E blocks-sheet ergänzt: Namen 49/51, VERSION/BV/WAHR im UI, ZÄHLENWENN im Browser, H2 (=A9+…×333), H3 (Drop + Gegenprobe Absatz), H1 (eigene Seite, route-Hook mount wirft)
- Instanz nextnotes-m8b sqlite up 0, install 0, app:enable, apache2ctl -k graceful 0
- PHPUnit Container sqlite Filter Sheet|Block|Sanitizer|Conversion|Markdown|NextBlocks|Formula: 144 OK, Exit 0
- E2E Lauf 1: structure() warf (var at überschattete Parameter) → pos; H2-Prüfung 999 statt 1000 Zeichen (Testfehler)
- E2E hell grün: blocks-sheet 216 Prüfungen, blocks-formula 147 (run-e2e Exit 0); kein CSS geändert → dunkel entfällt
- node --test sheet-engine 10/10 Exit 0; make build 0; check-upstream 0; check-notices 0; blocks.js 12246 B
- Instanz abgebaut (down.sh all, Exit 0); demo/gftest/redis unberührt
## Nächster Schritt
- keiner (Bericht an Lead)
## Offen/Annahmen (fix)
- notify() in blocks.js ruft window.showNotification; in der Hauptansicht offenbar nicht definiert → Hinweise (Ladefehler, Zu groß) erscheinen nur in der Meldungszeile des Blocks bzw. gar nicht (Ladefehler); nicht im Scope
- Englische Funktionsnamen (SUM …) jetzt #NAME? (vorher per HF-Kanon-Fallback erreichbar)
- Port-Renderer: \\\| wird als Lauf gelesen (Upstream-Verhalten, nicht CommonMark); umgangen per Code-Span, nicht gefixt

# fix2 (M8b-fix2, Blocker aus docs/reviews/M8b-r2.md)
Status: fertig
Auftrag: Tokenizer (' und ! außerhalb Strings sperren), OFFSET-Name untippbar, Dimensionsprüfung nach Engine-Aufrufen, notify→showNotificationPopup, M8-plan Z. 43, Tests sheet-engine.mjs, p2–p5 vorher/nachher.
## Erledigt
- Baseline (Scratchpad hf/r2): p2 OFFSET 1367 ms Höhe 1 000 001, calls ["SUMME"]; p3 520×130 versteckt 9804 ms / offen 106 ms; p4 Sheet1!-Bereich 520×45 1250 ms; p5 alle < 171 ms
- HF-Lexer gelesen: String-Regex mit \-Escape (`""` = zwei Strings, gleiche Abdeckung); nur Strings und '…'-Blattnamen enthalten " oder '; Lexfehler → Formelfehler; getCellFormula liefert #BEZUG! nach Zeilenlöschung → ErrorLiteral als Token zulassen
- OFFSET-Name: nie-passende Muster ((?!), [^\s\S], \b\B, $^, Lookbehind) scheitern an chevrotain → Name "'NN_GESPERRT_OFFSET" (enthält ', code() sperrt jede ' außerhalb Strings); Scratchpad fix2/off.cjs
- nextnotes-sheet.js: code() Ein-Durchlauf-Scanner (null bei ' / ! / offener String), calls null, guard/cost; setCells → SHEETREF (Hinweis sheetRef); fits() nach buildFromArray/setCellContents/change; kill() → statisch + sheetLimit, frozen Fallback; evaluate → null bei LIMIT; say() nur im Block
- blocks.js: notify → showNotificationPopup (sonst console.warn), Formeleditor-Ladefehler über notify, ctx.notify entfernt
- BlocksUiConfig + l10n de/de_DE: sheetLimit, sheetRef
- code() mit Lauf-Regex PLAIN (p5 „small ranges“ 233 → 170 ms)
- Nachher: p2 alle #NAME? (46/11/9 ms, Höhe 2, calls null); p3 520×130 versteckt #NAME? 61 ms, offen 110 ms; p4 alle Sheet1!-Formen #NAME?, Budget 20 ms; p5 unverändert (MODUS 126, small 170 ms)
- tests/nextnotes/node/sheet-engine.mjs: +7 Tests (r2 p2/p3/p4, '-Varianten inkl. ’‘＇′, ! und ' in Strings, #BEZUG!, interner Name jede Schreibweise, zweite Linie evaluate null); node --test 17/17 Exit 0
- E2E blocks-sheet: Hooks auf modalAlert.alert (showNotificationPopup → Modal), registrierter Name "'NN_GESPERRT_OFFSET", neue Prüfung „' abgelehnt mit Hinweis“
- Doku: SPEC §4.7 Punkt 7, ADR 0018 Nachtrag M8b-fix2, M8-plan Z. 43 + §155, user.md, CHANGELOG
- Worker-Untersuchung: HF braucht kein DOM, ginge im Worker; aber Raster nutzt Engine synchron (getCellValue/DetailedType/Formula nach addRows, numberToDate) → asynchrones Protokoll, Engine-Schicht neu (~1–2 Slices), Bündel im Worker extra laden; NC-CSP setzt kein worker-src → Rückfall script-src mit Nonce/strict-dynamic, Worker-URL vermutlich blockiert → CSP-Ausnahme per ADR; Nutzen: Zeitlimit per terminate() gegen Endlosrechnung, Speicher nur teilweise (Worker-OOM kann je nach Browser den Renderer treffen). Empfehlung: nicht jetzt, Tokenizer-Sperre + Budget reichen
- make build 0 (167 Dateien), check-upstream 0, check-notices 0; blocks.js 12211 B (≤ 12 KiB), sheet.js 27967 B (gz 9916)
- Instanz nextnotes-m8b (10400) up/install/app:enable/graceful 0; E2E hell: blocks-sheet 217 grün, blocks-formula 147 grün (Exit 0)
- PHPUnit Filter Sheet|Block|Sanitizer|Formula|NextBlocks: 132 OK (Exit 0)
- Instanz abgebaut (down.sh all 0); demo/gftest/redis unberührt
## Nächster Schritt
- keiner (Bericht an Lead)
## Offen/Annahmen
- tests/: E2E blocks-sheet.mjs angepasst (Hooks showNotification → modalAlert.alert, sonst Modal im Lauf; Name "'NN_GESPERRT_OFFSET"; neue Prüfung) – außerhalb sheet-engine.mjs, Folge von Punkt 4
- Tabellen-Hinweise nur im Block (kein Modal je abgelehnter Eingabe); Popup nur Ladefehler/Formel zu lang; Fallback ohne Popup: console.warn
- HF-String-Escape ist \" (nicht ""); "" = zwei Strings, gleiche Abdeckung; Scanner folgt HF exakt
