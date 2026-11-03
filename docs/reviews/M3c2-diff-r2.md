# M3c2 Diff-Review r2 (speccritic)

VERDICT: BLOCKER

Geprüft: Arbeitsbaum gegen HEAD 710792b3, Schwerpunkt B37-Nacharbeit (Pcre, HtmlSanitizer, ContentUrlRewriter::toServer, NoteService:79-85, Excalidraw/Duplicate/InputValidator, WorkspaceMapper::lockRows, Templates). Proben liefen per PHP-CLI im Scratchpad (r2a–r2d). r1-Blocker (Markdown mit Diagramm ab 1 MB wurde geleert) ist behoben: 8 MB-Attribut bleibt erhalten, Regex-Fehler führen zu 413 ohne Änderung.

## Blocker
1. CPU-DoS durch `LIBXML_PARSEHUGE` ohne Obergrenze für die Inhaltsgröße. Fundstelle: `HtmlSanitizer.php:148` zusammen mit der Ahnenschleife `HtmlSanitizer.php:168-177`. Erreichbar über `NoteService::applyContent` (PATCH/Anlegen von Notizen der Typen `note`/`html`/`tasklist`, die kein JSON sind).
   - Es gibt keine Grenze für die Inhaltsgröße. `InputValidator`/`NoteService` prüfen die Länge nicht, nur Excalidraw ist auf 7 MiB begrenzt. PATCH unterliegt nicht `post_max_size`, es greifen nur `memory_limit` und das Body-Limit des Webservers.
   - PARSEHUGE hebt die Verschachtelungsgrenze von libxml von 256 auf 2048 (gemessen, libxml 2.15.4). Die Schleife läuft für jedes Element über alle Vorfahren, der Aufwand ist also O(Elemente × Tiefe).
   - Messung mit verschachtelten `<span>` (2000 tief, wiederholt): 1,3 MB brauchen 6,5 s CPU, 5,2 MB 26 s, linear steigend. Ein angemeldeter Nutzer kann damit mit wenigen parallelen PATCH-Anfragen die PHP-Worker blockieren. Ohne HUGE war der Faktor 8-mal kleiner, dafür wurde der Rest des Dokuments still abgeschnitten.
   - Was fehlt: (a) eine Obergrenze für den Notizinhalt in `applyContent` vor dem Sanitizer, die mit 413 abbricht (z. B. 16 MiB, passend zu 2 × MAX_DATA_BYTES). Sie gehört in SPEC B37 und in einen Probe-Fall. (b) Die Flags `inCode`/`inSvg` linear bestimmen, z. B. per rekursivem Durchlauf, der sie vom Elternknoten erbt, statt für jedes Element die Vorfahren abzulaufen. (a) allein reicht nicht, denn 16 MiB verschachtelter Inhalt bräuchten etwa 80 s.

## Hinweise (kein Blocker)
- Event-Handler-Scanner (`HtmlSanitizer.php:321-341`). Nach Browser-Regeln korrekt für `/` als Trenner (`<img/src=x/onerror=…>`: der Browser liest `src="x/onerror=…"`, der Scanner auch), fehlende Leerzeichen nach Anführungszeichen (`src="x"onerror=` wird jetzt entfernt, alt nicht), `>` im Wert, Großschreibung, `\f`/`\n`, NUL, `<svg>`/`<math>`, unvollständige Tags und mehrere Handler pro Tag. Entities in Attributnamen dekodiert der Browser nicht, das ist also unkritisch.
  - Regression gegenüber alt: Der Scanner kennt keinen Kontext für Kommentare und RCDATA/RAWTEXT. Beispiele: `<!-- <p title="--><img src=x onerror=alert(1)>">` und dasselbe mit `<textarea>`, `<title>`, `<noscript>`, `<xmp>`, `<iframe>`, `<noembed>`. Dort hält er das `<img …>` für einen Attributwert und lässt `onerror` stehen, das alte Muster hat es entfernt.
  - Derzeit nicht ausnutzbar: Markdown wird per `p()` ausgegeben (`note_display.php:154`). `markdown-parser.js` und Upstream-`markdown_parser.php` escapen jedes HTML und bauen erlaubte Tags aus Allowlists neu.
  - Vorschlag: Bei `<!--` bis `-->` springen und bei RAWTEXT/RCDATA-Tags bis zum passenden Schluss-Tag, ohne Attribut-Scan darin. Dazu Probe-Fälle.
- `WorkspaceService::delete` (`WorkspaceService.php:281-305`) sperrt nur die Quelle, nicht das gewählte Ziel. Szenario: Derselbe Nutzer löscht parallel ws1 (Ziel T) und T. Die Notizen aus ws1 landen in T, das danach gelöscht ist; sie bleiben in der DB, sind aber unsichtbar. Vorschlag: Nach der Wahl Quelle und Ziel mit einem `lockRows([ws, target])` sperren (eine Anweisung, also feste PK-Reihenfolge) und bei Ergebnis < 2 mit 409 bzw. 404 abbrechen.
- `lockRows` ist sonst in Ordnung: Es ist eine Anweisung mit IN auf den PK (InnoDB und PG sperren in Indexreihenfolge). NC setzt `ATTR_FOUND_ROWS`, MySQL meldet also die gefundenen Zeilen. `SET id = id` geht über den QB, ist portabel und funktioniert unter SQLite, MariaDB und PG. In `moveNotes` wird nach dem Sperren neu gelesen.
- `AttachmentService::stripReferences` (`AttachmentService.php:365-367`) nutzt weiter `preg_replace … ?? $content`. Der Inhalt bleibt bei einem Fehler unverändert und bereinigt, aber der Verweis bleibt still stehen. Nicht in B37 gelistet, besser über `Pcre`.
- Ab einer Tiefe über 2048 schneidet libxml den Rest des Dokuments weiterhin still ab (Datenverlust, schon vorher vorhanden). Vorschlag: libxml-Fehler „Excessive depth“ erkennen und mit 413 abbrechen.
- `InputValidator::heading`/`workspaceTags`: Ungültiges UTF-8 führt jetzt zu 400 statt zu einem leeren Titel bzw. dem Überspringen des Tags. Das ist bewusst strenger, M2-Tests prüfen.

## Ohne Befund
- `Pcre` wirft bei jedem Fehler (UTF-8: 400, sonst 413), alle von B37 genannten Pfade sind umgestellt.
- `sanitizeMarkdown` ist linear (8 MB-Attribut in 0,01 s, 3 M Backticks, 2 M `<`). Ein ungeschlossenes `<script>` mit über 1 M `<`/Zeilen im Absatz führt zu 413 ohne Datenverlust.
- Blockmuster wie upstream (bis zum ersten Schluss-Tag, ohne Leerzeile).
- `NoteService:79-85` ist gleichwertig zum alten Regex.
- `toServer`: Differential-Fuzz alt gegen neu mit 300 k Eingaben. Abweichungen gibt es nur bei mehreren URLs im selben Token; dort verschluckte alt eine URL, neu bleibt verlustfrei.
- `JSON_INVALID_UTF8_SUBSTITUTE` ist in allen vier Templates gesetzt.
