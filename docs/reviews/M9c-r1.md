# M9c Diff-Review r1 (speccritic)

VERDICT: BLOCKER

## Blocker
1. **Stille Kürzung im Konverter, weil er ohne PARSEHUGE parst** (`nextnotes/lib/OneNote/Convert/PageConverter.php` `convert()`, `loadHtmlQuiet(..., LIBXML_HTML_NODEFDTD)`).
   libxml meldet „value too long“ (2.15) bzw. „huge text node“ (2.9.14) nur als Level 2 (ERROR). Die Prüfung auf `LIBXML_ERR_FATAL` greift deshalb nicht. Die Seite wird ohne Hinweis unvollständig angelegt (`tooLarge=false`).
   Reproduktion (Konverter direkt, Host 2.15.4 und `nextcloud:35.0.2-apache` 2.9.14, `docker run --rm`, Repo nur lesend gemountet):
   - Attribut über 10 MB, z. B. `<p><img src="…/resources/a/$value" alt="A×11 MiB">x</p><p>AFTER</p>`: Unter 2.15 bleibt nur `<p><img src="#nn-onenote-res-0"></p>`, „x“ und „AFTER“ fehlen.
   - Text über 10 MB, z. B. `<p>` mit 12 MiB Text, danach `<p>AFTER</p>`: Unter 2.9.14 wird bei 10 000 007 Byte abgeschnitten, „AFTER“ fehlt.
   - Mit `LIBXML_PARSEHUGE` bleibt der Inhalt in beiden Versionen vollständig und es gibt keinen Fehler (Roh-libxml geprüft).
   Das widerspricht B37: Der Sanitizer nutzt PARSEHUGE genau deshalb und lässt nie still kürzen.
   Behebung: PARSEHUGE auch in `convert()` setzen. Die Tiefe begrenzen dann die eigene Grenze 240 und der Fatal-Check (2.15: Tiefe 2048 ist fatal). Alternativ jede libxml-Meldung ≥ ERROR als `tooLarge` werten.
   Den Test `ConvertLimitsTest::testNestingAndHugeAttributes` anpassen: Er sichert die Kürzung derzeit ab (`assertLessThan(100, …)`). Er soll prüfen, dass nachfolgender Inhalt („AFTER“) erhalten bleibt. Dazu einen Fall mit Text über 10 MB ergänzen.

## Geprüft, ohne Befund
- **href/src:** Der Konverter gibt `a[href]` nur mit `http`/`https`/`mailto` und druckbarem ASCII ohne Anführungszeichen aus. `img[src]` und `a[href]` von Dateien sind nur Platzhalter. Ein `#…`-href aus der Quelle wird zu Text, Platzhalter lassen sich also nicht einschleusen. Getestet mit `javascript:`, `https:javascript:`, Steuerzeichen, `#nn-onenote-res-0`.
- **StyleFilter:** Nur Positivliste. Farbe nur als Hex, Wort, rgb/rgba. `url()`, `expression`, `var()`, `\63` werden verworfen (geprüft).
- **Video:** Erzeugt nie ein `iframe`. Ein Link entsteht nur bei `https:` ohne `"'<>\` und Leerzeichen, sonst Text.
- **MathML/Annotation:** Landet in `data-math` als Text. KaTeX läuft mit `trust:false`. Kein neuer Angriffsweg gegenüber Formeln, die Benutzer selbst eingeben.
- **`applyResources`:** Idempotent, ein zweiter Aufruf ändert nichts. Fremde, relative, `javascript:`- und `//`-URLs sowie Indizes, die keine Ziffern sind, werden zu Text. Danach läuft erneut der Sanitizer.
- **Sanitizer allgemein (Befund des Architekten zu B37):** Der Befund trifft nicht zu.
  - Unter 2.9.14 ist der Tiefen-Fatal ohne PARSEHUGE sehr wohl über `libxml_get_last_error` abfragbar (Level 3).
  - Der Sanitizer parst mit PARSEHUGE. Unter 2.9.14 hat er dann keine Tiefengrenze (300 000 Ebenen vollständig), unter 2.15 ist ab 2048 Ebenen Schluss mit Fatal → `TooLargeException`. Es wird also nicht still gekürzt.
  - Sicherheitsrelevant wäre eine Kürzung ohnehin nicht: Die Ausgabe entsteht nur aus dem bereinigten DOM, Abgeschnittenes fehlt nur.
- **Tiefe im Konverter:** Ab 239 Ebenen gibt es in beiden Versionen eine Hinweisnotiz.
- **`embed`:** Folgeinhalt bleibt erhalten.
- **Verstärkung durch `span`:** Aus 230 Ebenen werden 1153 Ebenen. Das liegt unter 2048, also kein Abbruch.
- **Treue:** Lesereihenfolge, Checkliste, colspan/rowspan, Emojis und `start` bei geteilten Listen sind sinnvoll und durch die Golden-Tests abgedeckt.

## Hinweise
1. Abweichungen vom Plan §5 stehen im Bericht, sind aber nicht in Plan/SPEC nachgezogen:
   - fehlendes top/left = 120/48 statt 0
   - geteilte Bilder in Dokumentreihenfolge statt nach `data-index`
   - weitere Kontrollkästchen-Kennzeichen werden zu Checklisten
   Beim Übernehmen des SPEC-Texts ausdrücklich nennen.
2. Das URL-Muster in `applyResources` endet auf `$`. Damit passt auch eine URL mit abschließendem `\n` (Ergebnis `…/2%0A`). Harmlos, weil die Werte von M9d kommen. Trotzdem besser `\z` oder den Modifikator `D` verwenden.
3. `expected.json` wird auf dem Host (2.15) erzeugt, der Test läuft im Image (2.9.14). Die Serialisierung unterscheidet sich, z. B. bei Attributen mit `"`: `alt='x" …'` gegenüber `alt="x&quot; …"`. Künftige Fixtures mit Anführungszeichen in Attributen können deshalb nur in einer Umgebung grün sein.
4. Außerhalb von M9c, schon vorher vorhanden:
   - Der Sanitizer speichert unter 2.9.14 beliebig tiefe Notizen.
   - `ImportService.php:378` und `MarkdownRenderer.php:709` parsen ohne PARSEHUGE und können so still kürzen.
   - Gesondert prüfen (Lead entscheidet).
5. Done 2 (Abhaken im Editor, E2E) und Done 6 (Liste/Info-Dialog) sind laut Plan erst nach M9b prüfbar. In M9c sind nur `title()`/`timestamp()` abgedeckt.
