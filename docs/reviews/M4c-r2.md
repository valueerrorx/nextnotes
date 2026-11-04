# M4c Diff-Review r2 (speccritic)

VERDICT: FREIGABE

Geprüft (Working Tree gegen HEAD):
1. B39 behoben: `HtmlSanitizer::loadHtmlQuiet` (HtmlSanitizer.php:168-177) setzt internal_errors=false, NOERROR|NOWARNING, Handler nur um loadHTML, stellt Zustand im finally her; `libxml_get_last_error` (Z. 186) bleibt für B37 nutzbar. Alle DOM-Ladestellen gehen darüber: ExportFormatter.php:184, ImportService.php:378, MarkdownRenderer.php:709. Volltextsuche in lib/ (case-insensitiv) nach loadhtml/loadxml/simplexml/XMLReader/xml_parser_create/Dom\HTMLDocument/libxml_: keine weitere Stelle. Regel (h) (check-upstream.sh:80) läuft grün. Messwerte B39-Nachtrag (22/30 MB statt 184/187 MB Heap bei 500.000 `<mark>`) plausibel.
2. Transaction.php: inneres Rollback kürzt nur eigene Löschaufträge (`truncate($queued)`), onRollback-Jobs werden beim inneren Commit auf die äußere Ebene herabgestuft (r1-Hinweis behoben, Geschwister-Rollback lässt sie stehen), depth bei Commit-Fehler korrekt, äußerstes Commit leert onRollback und flusht. NC nutzt Savepoints (Connection.php:191), daher stimmig.
3. `NoteMapper::findReferenced` (NoteMapper.php:164-191): workspace_id, role='note', trashed_at NULL; LIKE via `escapeLikeParameter` (Backslash), SQLite/OCI hängen `ESCAPE '\'` an (SqliteExpressionBuilder.php:22), MySQL/PostgreSQL Standard-Escape `\`. Kandidaten werden in ExportService.php:287-296 exakt über `fold(trim(heading))` gefiltert (MySQL-ci-Kollation liefert nur mehr Kandidaten). Chunking deckt alle IDs/Titel ab, leere Eingaben → keine Abfrage. `filterReadableBeside` (AccessService.php:144) gleichwertig zu filterReadable, da Quelle per requireNote(read)+resolveLinked geladen (ExportService.php:132-137) und Rechte workspaceweit.
4. Rate-Limit: CompatController.php:127 `#[UserRateLimit(limit: 30, period: 60)]` auf downloadNote, ebenso exportNote.
KaTeX: src und gebautes Vendor 0.16.47 (≥ 0.16.10). Plan-Text an B47 angeglichen.

Hinweise (kein Blocker):
- AttachmentService.php:257-260 `discardNew` (und die catch-Zweige Z. 344, 439) rufen `cleanup->flush()`. Liefe das je innerhalb einer offenen äußeren Transaktion, würden deren noch nicht committete Löschaufträge sofort ausgeführt; rollt die äußere zurück, fehlen Dateien zu wiederhergestellten Zeilen (Datenverlust). Heute nicht erreichbar: alle Aufrufer (ExcalidrawService::write, upload, move) laufen nur von Controllern auf Ebene 0. Schweregrad: latent/mittel. Empfehlung: `deleteFileNow` direkt bzw. `tx->onRollback` statt flush.
- check-upstream (h) prüft nur `load(HTML|XML|HTMLFile)(` case-sensitiv; `->loadhtml(`, `simplexml_load_*`, `XMLReader`, `Dom\HTMLDocument::createFrom*` würden durchrutschen. Heute nicht genutzt; Regex mit `-i` und diesen Namen erweitern.
