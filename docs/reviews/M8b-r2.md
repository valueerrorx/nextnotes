# Review M8b r2 (Diff gegen HEAD 381633be, Arbeitsbaum nach M8b-fix)

VERDICT: BLOCKER

Geprüft: nextnotes-sheet.js (guard/code/calls/cost/limited/setCells/change/structure/paste/history), HF 3.5.0 `es/parser/LexerConfig.mjs` + `parser-consts.mjs` + `FormulaParser.mjs`, blocks.js (mount/catch/poznoteBlockEvent), bootstrap (Capture dragover/drop), NextBlocks::cellText + SheetConversionTest, sheet-engine.mjs (`node --test …/sheet-engine.mjs` 10/10, Exit 0). Messskripte: Scratchpad `hf/r2/p1–p5.cjs` (gebautes `js/vendor/hyperformula/hyperformula.min.js` + `js-src/nextnotes-sheet.js`, Hauptkontext).

## Blocker
1. **Prüfung lässt sich über Blattnamen in `'…'` mit `"` umgehen (B2 und Budget offen)** (`nextnotes-sheet.js` `STR`/`code()`, Z. 182–184). Der HF-Lexer liest `'x"'!B2` als Zellbezug mit Blattnamen. `code()` sieht das `"` darin als Beginn einer Zeichenkette und blendet alles bis zum nächsten `"` aus. Danach sehen `calls()`, `depth()`, `{`-Prüfung und `cost()` die versteckten Aufrufe nicht mehr. Nachgemessen:
   - `=SUMME('x"'!B2;NN_GESPERRT_OFFSET(B2;0;0;1000000;1);"")`: `calls` = `["SUMME"]`, die Engine rechnet 1,4 s, die Blatthöhe steigt auf 1 000 001. Mit `100000000` bricht Node mit Speicherüberlauf ab (Exit 134). Der Name lässt sich eingeben, weil `OffsetProcedureName` `/NN_GESPERRT_OFFSET/i` ist, und er steht im ausgelieferten Quelltext.
   - Budget: `=WENN(0;'x"'!B2;MODUS.EINF(A1:Z30;…×130;""))` in 520 Zellen dauert 9,6 s, ohne den Trick 102 ms (geblockt). Dasselbe gilt für `VERSION()`, Matrix-Literale und Klammertiefe.
   - Weg: Eingabe, Einfügen und Laden. Jede Person mit Schreibrecht kann damit den Tab jeder anderen Person mit Schreibrecht zum Absturz bringen, wie in r1.
   - Nötig: `code()` muss wie HF tokenisieren. Strings und `'…'` (mit `''`) in einem Durchlauf von links prüfen. Einfacher geht es so: Steht außerhalb einer Zeichenkette `'` oder `!`, gilt die Formel als gesperrt (ein Blatt, kein Blattbezug nötig).
   - Tests in sheet-engine.mjs: die beiden Formeln oben → `#NAME?`, < 200 ms, Blattgröße unverändert.

## Hinweise
1. Budget unterschätzt `Sheet1!A1:Sheet1!Z30` (gezählt 26 statt 780). 170 Zellen MODUS.EINF mit 45 solchen Bereichen brauchen 1,3 s. Die Sperre aus Blocker 1 (`!` außerhalb von Strings) behebt das gleich mit.
2. Platzhalter (B1) sind aus: `useWildcards:false`, `useRegularExpressions:false`. Sonst gibt es keinen Regex-Pfad aus Nutzereingaben (Kriterien und Vergleiche ohne Regex). Ohne Befund.
3. Lexer-Abgleich sonst ohne Befund. Geprüft: Groß-/Kleinschreibung (beide `toUpperCase`, auch `ı`/`ſ` gleich), Unicode-Bereich identisch (`À-ʯ`), Leerraum oder Zeilenumbruch vor `(` (CALL `\s*` ist strenger als HF), `\"`-Escape wie HF, Namen mit `.`/`_`, `A1:A2:B2` → Fehler. Benannte Ausdrücke gibt es nicht. Wege in die Engine: Laden/Undo/Redo (`limited`), Bearbeiten/Einfügen/Ausschneiden/Leeren (`setCells`→`guard`). Zeilen- und Spaltenoperationen erzeugen keine neuen Formeln. Füllen und Engine-Copy/Paste gibt es nicht.
4. Budget-Messung legitimer Formeln am Limit (256 × Bereich 780 Zellen): MODUS.EINF 132 ms, MEDIAN 100, QUARTILE 88, KORREL 52, ZÄHLENWENN 46 ms. Kette über 1 300 Zellen 20 ms. 1 300 × BINOM/NORM.INV/POTENZ 41 ms. `A:Z` wird gekappt. Unter 1 s, ohne Befund.
5. H1 (Modul vor Shadow Root, `.catch` → unmount, Fallback bleibt), H2 (Zellgrenze in `change()`), H4 (cellText, Roundtrip-Test inkl. `a\|b`) sind korrekt. H3: `poznoteBlockEvent` gibt nur `true` zurück, wenn `path[0]` im Shadow Root eines eingehängten Blocks liegt. Drops außerhalb von Blöcken bleiben unberührt. Rest: Drop auf den Light-DOM-Fallback vor dem Laden des Stylesheets geht an Upstream. Das nächste `write` ersetzt ihn, es entsteht kein Datenschaden.
6. `notify`/`showNotification`: kein Blocker. Upstream definiert global nur `showNotificationPopup` (`src/public/js/ui.js:43`). Ladefehler und `formulaTooLong` (M8a) bleiben stumm, der statische Fallback bleibt sichtbar, Daten gehen nicht verloren. Empfehlung: `notify` auf `showNotificationPopup` umstellen.
7. M8-plan Z. 43 nennt die Suche noch als „/Tabelle rechnen“. Z. 175 ist korrigiert.
