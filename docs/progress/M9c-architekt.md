# M9c Architekt – OneNote-Seiten-Konverter
Status: fertig

## Auftrag
Reiner Konverter Graph-Seiten-HTML → Next-Notes-HTML + Ressourcenliste (`lib/OneNote/Convert/`), Ausgabe durch HtmlSanitizer, Fixtures mit Golden-Ausgabe, PHPUnit `OneNote/Convert*`.

## Erledigt
- Plan M9 §5/§6/§9 M9c, ADR 0020, SPEC §4.7, HtmlSanitizer, checklist.js, Learn-Doku (input-output-html, abs-pos, note-tags, get-content) gelesen
- Code: `nextnotes/lib/OneNote/Convert/{PageConverter,PageWalker,ConvertResult,ConvertResource,StyleFilter,MathMl}.php`
- Ressourcen-URL-Regel: `GraphClient::resourceIdFromUrl` (M9a, nur lesend genutzt)
- l10n `de.json`/`de_DE.json`: 6 Schlüssel angehängt
- `nextnotes/build/check-upstream.sh` (g): `lib/OneNote/Convert` aufgenommen
- Fixtures `tests/nextnotes/fixtures/onenote/` (9 HTML, Quelle je Datei), `golden.php`, `gen-expected.php`, `expected.json` (geprüft)
- Tests `tests/nextnotes/php/OneNote/ConvertGoldenTest.php`, `ConvertLimitsTest.php`
- `make -C nextnotes build` Exit 0; `make -C nextnotes check-upstream` Exit 0; `check-forbidden-sql.sh` Exit 0
- Instanz `nextnotes-m9c` (NN_PORT_BASE=11200, sqlite): `run-phpunit.sh sqlite --filter 'Convert(Golden|Limits)Test'` 34/34 Exit 0; `--filter OneNote` 61/61 Exit 0; danach `down.sh all` Exit 0
- Befund libxml 2.9.14 (NC-Image) vs. 2.15 (Host): `embed` ohne Schluss-Tag schluckt Folgeinhalt; Tiefe 256 wird mit LIBXML_NOERROR still abgeschnitten (kein Fehler abfragbar) → UNWRAP-Liste, Tiefen-Vorprüfung (240)

## Nächster Schritt
- keiner (Lead: SPEC-Text unten übernehmen; M9b an PageConverter anbinden)

## Offene Punkte / Annahmen
- Dateiort laut Lead `lib/OneNote/Convert/` statt `lib/OneNote/PageConverter.php` (Plan)
- Abweichungen vom Plan §5: fehlendes top/left = 120/48 (Learn abs-pos) statt 0; geteilte Bilder in Lage-/Dokumentreihenfolge (Learn: data-index kann falsch sein); alle Kontrollkästchen-Kennzeichen (to-do, to-do-priority-1/2, discuss-with-*, schedule-meeting, call-back, client-request) → Checkliste; `span`-Formatierung → strong/em/u/s; Standardfarben (Schwarz, Weiß, OneNote-Überschriftenblau) entfallen (Dunkelmodus); leerer Titel → vorhandener Schlüssel „Untitled note“
- M9b hat parallel `lib/OneNote/{PageConverterInterface,ConvertedPage,BasicPageConverter}.php` angelegt (andere Ergebnisform) → Lead entscheidet
- Gleichungen/Freihand im echten Graph-HTML nicht belegt (Abnahmetest M9e)

## SPEC-Text (Vorschlag für den Lead)
**OneNote-Seiten-Konverter** (`OneNote\Convert\PageConverter`, M9c, rein: kein Netz, keine DB): `convert(html, IL10N)` → `ConvertResult` (`html`, `resources` ≤ 100 in Dokumentreihenfolge als `ConvertResource{index,id,kind image|file,name,type}`, `skippedResources`, `ink`, `tooLarge`). Ablauf: Eingabe > 16 MiB oder > `HtmlSanitizer::MAX_TAGS` → Hinweisnotiz; DOM über `loadHtmlQuiet` ohne PARSEHUGE; Elementtiefe ≥ 240 → Hinweisnotiz (libxml 2.9 schneidet bei 256 still ab); `body`-Kinder stabil nach (top, left) in px sortiert (fehlend 120/48), positionierte `div` ausgepackt; Positivliste: `p`, `h1–h6`, `ul/ol/li`, `table/thead/tbody/tfoot/tr/td/th/caption` (`colspan`/`rowspan` 2–1000), `blockquote`, `pre`, `br`, `hr`, `a` (nur `http/https/mailto`, druckbares ASCII ≤ 4096), Zeichenformate → `strong/em/u/s/sup/sub/cite/code/mark/small`; samt Inhalt entfernt: `script, style, template, noscript, head, title, frame(set), noframes, select, option, optgroup, textarea, svg, button`; nur Element entfernt: `meta, link, base, embed, param, source, track, area, input, applet, form, audio, video, picture, map, dialog, datalist, canvas`; Rest ausgepackt. Stile: nur `color`, `background-color` (Hex, Name, rgb/rgba; ohne Schwarz/Weiß/Überschriftenblau), `font-weight`, `font-style`, `text-decoration`, `text-align`, `list-style-type` (Schlüsselwörter); an `span` werden fett/kursiv/unterstrichen/durchgestrichen zu Elementen. Kennzeichen: Kontrollkästchen-Formen → Upstream-Checkliste (`ul.checklist > li.checklist-item[.checklist-item-checked] > input.checklist-checkbox[checked] + span.checklist-text`), angrenzende To-dos in einer Liste, nummerierte Liste zählt danach mit `start` weiter; `important/question/critical/idea` → ⭐❓❗💡; übrige entfallen. Bilder (`data-fullres-src`, sonst `src`) und Dateien (`object[data]`) nur über `GraphClient::resourceIdFromUrl`; gleiche ID = gleicher Index; ab der 101. bzw. bei fremdem Host Text „[Bild nicht übernommen]“/„[Datei nicht übernommen: Name]“. Platzhalter `img[src]`/`a[href]` = `#nn-onenote-res-{index}`; `applyResources(html, index → '/api/v1/notes/{n}/attachments/{a}' | null, IL10N)` ersetzt sie (andere Werte → Text) und sanitisiert erneut. Video-`iframe` → Link „Video: URL“ (nur `https:`, sonst Text). MathML (`math`, auch mit Präfix) → Formel-Vertrag §4.7 (TeX-Annotation bevorzugt, sonst Präsentations-MathML; abgesetzt nur als einziger Absatzinhalt), nicht umwandelbar → Text. InkML-Elemente/-Typen → „[Freihand nicht übernommen]“, Zähler `ink`. Ergebnis immer `HtmlSanitizer::sanitizeHtml` und Fixpunkt; > 16 MiB → Hinweisnotiz. Hilfen: `title(title, level, IL10N)` („› “ je Ebene, max. 5, leer → „Unbenannte Notiz“, ≤ 1000 Zeichen), `timestamp(ISO 8601)` → Unix|null. Tests: `OneNote/ConvertGoldenTest`, `ConvertLimitsTest`; Golden `fixtures/onenote/expected.json` (`gen-expected.php`, Host-PHP mit `.ref`).

## fix (M9c-fix, Review r1)
Status: fertig
Auftrag: Blocker PARSEHUGE im Konverter; Hinweise 2 (URL `\z`), 3 (Golden libxml-unabhängig), 4 (Import/Renderer PARSEHUGE, Sanitizer-Tiefe 2048), 1 (SPEC-Abschnitt + Plan §5).
### Erledigt
- Review, Repros (scratchpad/t), Parse-Stellen gelesen
- Befund: libxml 2.15 PARSEHUGE: html+body+2046 Elemente ok, 2047 fatal → MAX_DEPTH 2048 offene Elemente ab Dokument; Kürzungsmeldungen „value too long“ (2.15), „huge text node“ (2.9.14), Level 2; „Tag huge invalid“ existiert → nur Mehrwort-Phrasen
- `HtmlSanitizer`: `MAX_DEPTH`, `loadHtmlComplete()` (PARSEHUGE, Fatal/Kürzung/Tiefe → TooLargeException), `elementDepth()`; sanitizeDom nutzt es
- `PageConverter`: convert/applyResources über loadHtmlComplete, eigenes depth() entfernt, URL-Muster `\z`
- `ImportService::tasksFromHtml`, `MarkdownRenderer::sanitizeMarkdownExcalidrawContainer` über loadHtmlComplete
- `ConvertLimitsTest`: Huge-Fälle auf AFTER erhalten, neu `testHugeTextNode`; Host 2.15 + Image 2.9.14 per Skript gleich (scratchpad/fx/conv.php)
- Test `tests/nextnotes/php/Security/SanitizerHugeTest.php` (Sanitizer 12 MiB/Tiefe 2046 ok, 2047/300000 → TooLarge; Import-Tasklist; Excalidraw-Render)
- Golden: `golden.php` `Golden::canonical()` (eigene Serialisierung), `gen-expected.php` Doku + Image-Gegenprobe; expected.json neu: Host == Image (diff leer), einzige Änderung `checked` → `checked="checked"`
- `docs/SPEC.md` neu §4.8 „OneNote-Konverter (M9c)“ (nur dieser Abschnitt), `docs/slices/M9-plan.md` §5: 120/48, alle Kontrollkästchen-Kennzeichen, geteilte Bilder in Lagereihenfolge, loadHtmlComplete
- `make -C nextnotes build` Exit 0, `make -C nextnotes check-upstream` Exit 0
- Instanz nextnotes-m9c (11200) up/install/enable Exit 0; `run-phpunit.sh sqlite --filter 'OneNote|Convert|Sanitizer|Import|Markdown|Render'` 158/158 Exit 0; `--filter 'SanitizerHugeTest|ConvertLimitsTest|ConvertGoldenTest'` 38/38 OK; `down.sh all` Exit 0
- Host 2.15.4 vs Image 2.9.14 per Skript (scratchpad/fx/conv.php, ir.php): Konverter, Sanitizer, Import, Render gleich
### Nächster Schritt
- keiner
### Offene Punkte
- `ExportFormatter`/`NextBlocks` parsen mit PARSEHUGE, aber ohne Fehler-/Tiefenprüfung (nicht im Auftrag)
- Bestandsnotizen tiefer als 2046 (nur unter 2.9 speicherbar gewesen) lassen sich nicht mehr speichern (413)
