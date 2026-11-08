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
