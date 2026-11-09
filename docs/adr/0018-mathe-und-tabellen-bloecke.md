# ADR 0018: Mathe- und Tabellen-Blöcke in Richtext-Notizen

Status: entschieden (Thomas mit Lead, 2026-11-07). Umsetzung **erst nach M7** als M8; vorher nicht einbauen.

## Kontext
Thomas will in Richtext-Notizen (Schule, AHS, Alltag) Formeln, Rechnungen mit Ergebnissen und eine kleine Tabellenkalkulation. Heute gehen Formeln nur in Markdown-Notizen (`$…$`, `$$…$$`, KaTeX 0.16.47 unter `src/public/js/katex`; Sanitizer erlaubt `span[data-math]`). Einfügen soll so einfach sein wie beim Code-Block oder Excalidraw: Toolbar oder Slash-Menü, dann steht ein Feld in der Notiz. Das Feature ist neu und nicht im Upstream.

## Optionen
1. GeoGebra einbetten: Math-Apps unter *GeoGebra Non-Commercial License* (nicht OSI), Standard lädt von `geogebra.org` (Datenschutz Schule), selbst gehostet ~50 MB. Abgelehnt.
2. EuroOffice/Document Server-Tabelle einbetten: viel zu schwer für ein Feld in einer Notiz. Abgelehnt.
3. Fertige Tabellen-Oberfläche: Handsontable unfrei; Univer/Fortune-Sheet mehrere MB, volle Office-Tabelle. Abgelehnt. Jspreadsheet CE (MIT) nur Plan B für die Tabellen-Oberfläche.
4. Formel-Logik einer Tabelle selbst bauen (Bezüge, Bereiche, Abhängigkeiten, Zeilen einfügen): zu viel eigene Wartung. Abgelehnt.
5. **Drei schlanke Blöcke mit kleinen freien Bibliotheken, lokal gehostet, lazy geladen.**

## Entscheidung
Option 5. Alle Bibliotheken liegen lokal (kein CDN), werden über die Lazy-Helfer `poznoteEnsure*` (Adapter, SPEC §4.3.9) erst beim ersten Block geladen und folgen dem NC-Theme hell/dunkel.

### Block 1: Formel (inline und als Block), Slice M8a
- Eingabe: **MathLive** (MIT), WYSIWYG, Bildschirmtastatur für Tablets.
- Anzeige: vorhandenes **KaTeX**. Gespeichert wird LaTeX in `data-math` (inline `span`, Block eigenes Element).
- Einfügen: Toolbar und Slash-Menü `/Formel`.

### Block 2: Aktiver Tabellenblock, Slice M8b
- Rechenkern: **HyperFormula** (GPLv3, `licenseKey: 'gpl-v3'`; mit der AGPL-App vereinbar). Bezüge, Bereiche, Neuberechnung, Zirkelbezug-Erkennung, Bezugsverschiebung bei `addRows`/`addColumns`, deutsche Funktionsnamen (Sprachpaket `deDE`) kommen aus der Engine. **Keine eigene Formel-Logik.**
- Oberfläche: eigenes schlankes Raster (Ziel: wenige hundert Zeilen JS):
  - Start 5×5, „+ Zeile“, „+ Spalte“, Zeile/Spalte löschen; Obergrenze 50 Zeilen × 26 Spalten (A–Z).
  - Formelzeile oben (zeigt Rohinhalt der gewählten Zelle), Zelle zeigt Ergebnis.
  - Tastatur: Pfeile, Enter, Tab, Entf, Escape; Kopieren/Einfügen von Bereichen als Text (TSV, z. B. aus Excel/LibreOffice).
  - Formatierung nur: fett, Dezimalstellen (0–4), Prozent. Dezimalkomma.
  - Fehler sichtbar in der Zelle (`#DIV/0!`, `#NAME?`, `#ZYKLUS!` bzw. Engine-Text), Tooltip mit Klartext.
- Nicht enthalten: Diagramme, mehrere Blätter, Sortieren/Filter, bedingte Formatierung, Zellverbund, Ausfüllen per Ziehen (ggf. später Strg+D).
- Speichern: Rohinhalte (Formeln, Werte, Format) als JSON im Block **und** eine statische HTML-Tabelle mit den Ergebnissen. Suche, Export, Druck, Vorschau und Leser ohne Bearbeitungsrecht sehen die Tabelle ohne JS-Engine.
- **Funktionsumfang bewusst klein (Whitelist).** Andere Funktionen ergeben `#NAME?`. Ob per Plugin-Auswahl (`unregisterAllFunctions`/`registerFunctionPlugin`) oder Namensprüfung, entscheidet der Architekt im Slice (prüfen gegen HyperFormula-Doku).
  - Rechnen: `+ − * / ^ %`, Klammern, Vergleiche, `&` (Text verketten), Bezüge `A1`, `$A$1`, Bereiche `A1:B5`
  - Summen/Zählen: `SUMME`, `MITTELWERT`, `MIN`, `MAX`, `ANZAHL`, `ANZAHL2`, `ZÄHLENWENN`, `SUMMEWENN`
  - Runden/Zahlen: `RUNDEN`, `AUFRUNDEN`, `ABRUNDEN`, `GANZZAHL`, `ABS`, `REST`
  - Mathematik: `WURZEL`, `POTENZ`, `PI`, `EXP`, `LN`, `LOG`, `LOG10`, `SIN`, `COS`, `TAN`, `ARCSIN`, `ARCCOS`, `ARCTAN`, `BOGENMASS`, `GRAD`
  - Logik: `WENN`, `UND`, `ODER`, `NICHT`
  - Statistik (AHS-Oberstufe): `MEDIAN`, `MODUS.EINF`, `STABW.S`, `STABW.N`, `VAR.S`, `VAR.P`, `QUARTILE.INKL`, `KORREL`, `STEIGUNG`, `ACHSENABSCHNITT`
  - Wahrscheinlichkeit (Matura): `BINOM.VERT`, `NORM.VERT`, `NORM.INV`
  - Datum: `HEUTE`
  - Liste nur mit Thomas erweitern.
- Einfügen: Toolbar und Slash-Menü `/Tabelle rechnen` (die normale Richtext-Tabelle bleibt unverändert).

### Block 3: Rechenblock („Algebra-Spalte“), Slice M8c
- Zeilen wie `a = 3`, `b = a^2 + 1`, `5 km / 20 min to km/h`; Ergebnis rechts je Zeile.
- Rechenkern: **math.js** (Apache-2.0), eingeschränkte Instanz (kein `import`, `createUnit`, `evaluate` in Ausdrücken o. ä.; Liste im Slice festlegen). Variablen über Zeilen, Einheiten, Brüche.
- Optional `f(x) = …` zeichnet einen kleinen Graphen mit **function-plot** (MIT). JSXGraph (Geometrie) nur nach Rückfrage bei Thomas.
- Gespeichert wird nur der Eingabetext; Ergebnisse werden beim Anzeigen neu berechnet, zusätzlich statische Ergebnisse für Export/Suche wie beim Tabellenblock.
- Einfügen: Toolbar und Slash-Menü `/Rechnen`.

Reihenfolge M8a → M8b → M8c (Thomas: Tabellenblock wichtiger als Rechenblock).

## Pflichtprüfungen je Slice
- CSP (ADR 0006, 0012): kein `eval`/`new Function`; Inline-Styles der Bibliotheken prüfen. Keine neue CSP-Ausnahme ohne Lead-Entscheidung.
- Sanitizer: neue Elemente/Attribute nur gezielt erlauben (JSON in `data-*`, kein Skript, kein HTML aus Zellinhalten; Zelltext immer als Text rendern). Speccritic-Review Pflicht (Sicherheit).
- Rechte/Sperre (ADR 0005): Leser und gesperrte Notizen zeigen nur die statische Darstellung bzw. schreibgeschützte Engine.
- Export (ADR 0008): Formel als `$…$`/`$$…$$`, Tabellenblock als Markdown-Tabelle mit Ergebnissen (Formeln optional als Kommentar/JSON-Anhang, im Slice festlegen), Rechenblock als Codeblock mit Ergebnissen.
- Austeilen/Einsammeln (M6): Blöcke werden beim Kopieren der Notiz unverändert mitkopiert.
- Bundle-Größe messen und in STATUS festhalten; nichts im Haupt-Bundle, alles lazy.
- `src/`-Änderungen in SPEC §11.1 listen (Upstream-Updates, ADR 0007).

## Konsequenzen
- Drei neue Vendor-Bibliotheken (MathLive, HyperFormula, math.js, optional function-plot) mit Lizenzhinweisen im Repo; Updates wie andere Vendor-Bibliotheken.
- Neuer Markup-Vertrag in Notizen (Block-Elemente mit JSON + statischem Fallback) – stabil halten, sonst Migration nötig.
- Kein Upstream-Feature: Poznote-Updates können Sanitizer/Toolbar berühren; Patches klein halten.

## Nachtrag M8a (2026-11-08, Lead-Entscheidungen aus M8-Plan r2, gilt für M8b/M8c)
- (a) Leser des Rechenblocks sehen die gespeicherten, statischen Ergebnisse, keine Neuberechnung; nur der Graph wird bei Lesern gezeichnet, unter denselben Aufwandsgrenzen (M8-Plan §4).
- (b) `VERSION` und `BEREICH.VERSCHIEBEN` sind in HyperFormula geschützt und nicht entfernbar. ~~harmlos; sie bleiben aktiv~~ – korrigiert in M8b-fix: nicht harmlos, beide gesperrt (Nachtrag M8b-fix).
- (c) Ein Zirkelbezug zeigt den Engine-Code `#CYCLE!` (nicht `#ZYKLUS!`), Klartext im Tooltip.
- (d) Der Tabellenblock ist fest deutsch (Funktionsnamen, `;`, Dezimalkomma), unabhängig von der NC-Sprache.
- Formel (M8a): MathLive 0.111.4 (MIT) lokal unter `js/vendor/mathlive/`, lazy beim ersten Öffnen; keine CSP-Ausnahme (Laden per `strict-dynamic`, keine Sounds, Fonts aus dem KaTeX-Vendor). Markup-Vertrag und Sanitizer-Regeln: SPEC §4.7.

## Nachtrag M8b (2026-11-08, Architekt, Slice M8b)
- Tabelle: HyperFormula 3.5.0 als esbuild-Bündel aus den ES-Modulen mit `deDE` (`js/vendor/hyperformula/`, 819 KB, 181 KB gz), lazy nur für schreibbare Blöcke; GPL-3.0-Text unter `LICENSES/GPL-3.0-only.txt`. Keine CSP-Ausnahme (einziger Treffer `eval(fn)` in chevrotain bekommt eine Funktion, keinen Text).
- Whitelist per Plugin-Auswahl wie geplant. Zusätzlich zu `MODUS.EINF` und `ACHSENABSCHNITT` ersetzt ein eigenes Plugin `GANZZAHL`, weil HF 3.5.0 zur Null hin rundet (Calc/Excel: abrunden). Funktionsumfang unverändert.
- Rückgängig/Wiederholen über eigene Schnappschüsse (Inhalt + Format) statt HF-`undo`, weil das Format nicht in der Engine liegt; die Engine wird dabei neu aufgebaut (≤ 1 300 Zellen).
- Tastatur-Isolation (Risiko R1) über einen Capture-Listener am `window` im Adapter `nextnotes-bootstrap.js`, der vor allen Upstream-Listenern läuft und Ereignisse aus der Block-UI nur an den Block reicht. Kein `src/`-Patch.
- Slash-Suche: Upstream schließt das Menü beim Leerzeichen; „/Tabelle“ zeigt „Tabelle rechnen“.

## Nachtrag M8b-fix (2026-11-09, Lead-Entscheidungen aus Review M8b r1)
- **Kontext**: `BEREICH.VERSCHIEBEN` ignoriert `maxRows`/`maxColumns` (1e6 Zeilen in 1,4 s, 1e8 → Speicherüberlauf); Platzhalter in ZÄHLENWENN bauen Regex mit `.*` (ReDoS > 60 s); englische IDs (`SUM`, `VERSION`) erreichte der Parser über seinen Kanon-Fallback; Matrix-Literale wachsen über 26 Spalten; viele große Bereiche rechnen bis 26 s. Jede Person mit Schreibrecht hätte so den Editor aller anderen eingefroren.
- **Optionen**: (1) Sprachpaket umbenennen; (2) Formel-Prüfung vor der Engine (Funktionsnamen gegen Whitelist); (3) beides.
- **Entscheidung**: (3). Prüfung vor `buildFromArray`/`setCellContents`: nur deutsche Whitelist-Namen, keine `{…}`, Klammertiefe ≤ 64, sonst `#NAME?`; `OFFSET` im Sprachpaket intern umbenannt. Platzhalter und Regex aus (`useWildcards:false`, `useRegularExpressions:false`), `*`/`?` sind normale Zeichen. Rechenbudget 200 000 Bereichszellen je Blatt. `WAHR()`/`FALSCH()` auf der Whitelist.
- **Konsequenzen**: Platzhalter in ZÄHLENWENN/SUMMEWENN gibt es in Next Notes nicht (Abweichung von Calc/Excel, steht in `user.md`). Englische Funktionsnamen und Matrix-Literale ergeben `#NAME?`. Sehr große Blätter mit vielen Bereichsformeln lehnt der Client ab (Hinweis). Die Prüfung muss bei HF-Updates gegen den Lexer (Namens- und Zeichenkettenmuster) abgeglichen werden.

## Nachtrag M8b-fix2 (2026-11-09, Review M8b r2)
- **Kontext**: Blattnamen `'x"'!B2` enthalten `"`; die Prüfung hielt es für eine Zeichenkette und übersah dahinter liegende Aufrufe (`BEREICH.VERSCHIEBEN` → 1e6 Zeilen, Budget 9,6 s). `Sheet1!A1:Sheet1!Z30` unterlief das Budget. Der interne Name `NN_GESPERRT_OFFSET` war eingebbar.
- **Optionen**: (1) Blattnamen im Scanner nachbilden; (2) `'` und `!` außerhalb von Zeichenketten sperren; Worker mit Zeitlimit (nur untersucht).
- **Entscheidung**: (2), ein Durchlauf von links wie der HF-Lexer; `OFFSET` heißt `'NN_GESPERRT_OFFSET` (nie passende Muster scheitern an der chevrotain-Optimierung); zweite Linie über `getSheetDimensions` nach jedem Engine-Aufruf (über 50 × 26 → Engine verwerfen, Block statisch).
- **Konsequenzen**: Blattbezüge und `'`/`!` außerhalb von Anführungszeichen gibt es nicht (Hinweis beim Bearbeiten, `#NAME?` beim Laden); Fehlerwerte wie `#BEZUG!` bleiben gültig.

## Nachtrag M8c (2026-11-09, Architekt, Slice M8c)
- **Kontext**: Rechenblock nach M8-Plan §4 (math.js 15.2.0, function-plot 1.26.0). Beim Bau zeigten sich drei Widersprüche im Plan: (1) `b`, `t`, `s`, `m`, `g`, `h` sind math.js-Einheiten, die Regel „Name ist keine Einheit“ lehnte `b = a^2 + 1` (Done 1) ab; (2) die Vorprüfung „Aufrufe bei x = 0 × 300 > 50 000“ verwirft `f7`, Done 3a verlangt aber, dass `f7` sie besteht und erst das Laufbudget greift; (3) der Port-Renderer kennt nur Zäune aus drei Backticks, längere Zäune (Plan §1.1a) würden nicht gelesen.
- **Optionen**: je Punkt Plan-Regel beibehalten (Done unerfüllbar) oder Regel anpassen.
- **Entscheidung**: (1) Namen dürfen Einheiten verdecken: ein Name, der irgendwo im Block zugewiesen wird (Variable oder Funktion), ist im ganzen Block nie Einheit (Namen werden vorab aus allen Zeilen gesammelt); vor seiner Zuweisung ergibt er „unbekannt“, nie rückwirkend eine andere Bedeutung (M8c-fix, Review r1); Namen des math.js-Namensraums, Aufrufliste und `pi`/`e` bleiben gesperrt, Parameter dürfen Variablen verdecken. (2) Vorprüfung nur noch „Probeaufruf bei x = 0 sprengt das Laufbudget“ (z. B. Tiefe bei `f40`), ein Budget für alle Funktionen eines Blocks (M8c-fix); harte Grenze ist das Laufbudget je Zeichenlauf, gemeinsam über alle Funktionen. (3) Codeblock immer mit drei Backticks; eine Zeile nur aus ```` ``` ```` bekommt ` #` (math.js-Kommentar). function-plot als esbuild-Bündel statt der UMD-Datei (UMD würde sich bei einem `define.amd` auf der Seite nicht als Global anmelden; Lizenzprüfung per Metafile). Keine CSP-Ausnahme: `new Function` steckt nur im Pfad für Text-Ausdrücke, der Block übergibt immer Funktionen.
- **Konsequenzen**: Wer `t = 5` schreibt, kann `t` (Tonne) in diesem Block nicht mehr als Einheit nutzen, auch nicht in früheren Zeilen. Ein Graph, dessen Probeaufruf billig ist, rechnet bis zum Laufbudget und wird dann entfernt. Markdown-Rückweg liefert Codeblöcke, die der Port und Poznote gleich lesen.

## Nachtrag M8c-fix (2026-11-09, Architekt, Review M8c r1)
- **Kontext**: Rechenbudget galt je Block; alle Blöcke einer Seite liefen nach dem Laden in einer Task (20 schwere Blöcke: 6,2 s eingefroren). Vorprüfung mit frischem Budget je Funktion: ein Block bis ~750 ms.
- **Optionen**: Web Worker (unter der NC-CSP nicht ohne Ausnahme); Budget je Block verkleinern (trifft normale Blöcke); Warteschlange mit Seitenbudget.
- **Entscheidung**: Rechenwarteschlange in `nextnotes-blocks.js` für alle Blöcke (Tabelle und Rechnen): jeder Schritt (Einhängen mit Auswertung, Vorprüfung, Zeichnen) eine eigene Task (`setTimeout 0`), Folgeschritte vor dem nächsten Block. Automatische Schritte teilen 1 s Rechenzeit je Seite bzw. Moduswechsel (neu, sobald die Schlange leer ist); darüber bleibt der Fallback mit Knopf „Nicht berechnet. Zum Rechnen klicken.“ bzw. „Graph nicht gezeichnet. Zum Zeichnen klicken.“ Vom Nutzer ausgelöste Schritte (Eingabe, Klick, neu eingefügter Block) laufen immer. Die Graph-Bibliothek lädt das Einhängen vorab, wenn der Block eine Funktionsdefinition enthält. function-plot 1.26.0 wirft ohne gültige Stützstelle; Build-Patch in `bundles.json` (`patches`, genau ein Treffer, sonst Build-Abbruch) statt Fork.
- **Konsequenzen**: längste Task je Block ≤ 150 ms (Auswertung) bzw. 100 ms (Vorprüfung, Zeichenlauf); Seiten mit vielen schweren Blöcken rechnen nur die ersten vollständig.
