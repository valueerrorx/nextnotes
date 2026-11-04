# M4c Diff-Review r1 (speccritic)

VERDICT: BLOCKER

1. nextnotes/lib/Service/ExportFormatter.php:183-186 (`cleanContent`): `libxml_use_internal_errors(true)` + `loadHTML(... LIBXML_PARSEHUGE)` ohne `LIBXML_NOERROR|LIBXML_NOWARNING` auf dem gespeicherten Rohinhalt (bis 16 MiB, bis 500.000 Tags), VOR dem HtmlSanitizer. Genau das Muster, das SPEC B39 als Speicher-DoS behebt ("Fehler nicht sammeln"); libxml 2.9 (NC-35-Image) meldet je HTML5-Tag (`mark`, `details`, `figure` …) einen Fehler, PHP sammelt alle. Auslösbar von jedem Leser (auch Lesefreigabe), 30/min, auf allen Formaten außer json/markdown (auch Download ohne Rate-Limit). Auf Host-libxml 2.15 nicht reproduzierbar (0 Fehler), im Container nicht gemessen. Nötig: Flags wie `HtmlSanitizer::sanitize` (Z. 174) oder Handler ohne Sammeln; Test mit 500.000 `<mark>` im Container (Speicher/Zeit wie B39 dokumentiert).

Geprüft ohne Befund:
- B47-Abweichung (`ExportService::resolveReferences`, `findList([$wsId])`): Rechte sind nach SPEC §3/ADR 0011/0014 rein workspaceweit (keine Einzel-Notiz-/Ordner-Freigaben, auch nicht in M5/M6); `findList` filtert `role='note'` und Papierkorb, Vorlagen/Abgaben fallen heraus. Leserecht auf die Quelle (bzw. das Ziel nach `resolveLinked`) = Leserecht auf alle gelisteten Notizen. Kein Titel-Leck.
- B42 `AccessService::resolveLinked` (eine Stufe, fremd/fehlend/linked → 404); `IndexViewService::shownView` nimmt Schreibrecht aus dem Ziel-Workspace; Test PATCH → 403 vorhanden.
- Export-Rechte: `source()` read + `?workspace=` gegen die angefragte Notiz; B48 inline nur html, sonst `DataDownloadResponse`/attachment; nosniff in allen Zweigen; Anhänge nur `forNotes([id])`, `ownImage`/`toZipPaths` nur eigene note_id+aid.
- KaTeX: `math-renderer.js` rendert `data-math` mit `trust: false` per `katex.render` (DOM, kein innerHTML) → `\href`/`\html*`/`\includegraphics` gesperrt, kein XSS.
- CSP: `export_print` nur in `NOTE_VIEWS`, keine weitere Lockerung; ADR-0012-Nachtrag vorhanden.
- Transaction: depth bei Commit-Fehler korrekt, Rollback ohne Transaktion abgefangen, Jobs ≥ Ebene laufen, bei äußerem Commit verworfen.
- Grenzen: ZIP-Summe vor Temp-Ordner, 32 MiB-Embed vor dem Lesen → 413, Ordner im finally gelöscht.

Hinweise:
- Transaction.php:72-84: Jobs einer bereits erfolgreich committeten Unter-Transaktion gleicher Ebene laufen mit, wenn eine spätere Geschwister-Transaktion zurückrollt und der Aufrufer die Ausnahme fängt (NC nutzt Savepoints, Connection.php:191) → Dateien zu bestehenden Zeilen gelöscht. Heute kein Aufrufer so (ImportService: je Datei äußerste Ebene). Jobs beim inneren Commit auf Ebene-1 herabstufen.
- KaTeX 0.16.9 (`js/vendor/katex`): CVE-2024-28243/28244 (maxExpand-Umgehung, Client-DoS) erst in 0.16.10 behoben; `data-math` ist jetzt auch in gespeicherten HTML-Notizen erlaubt, ein Schreiber eines geteilten Workspace kann Lesern den Tab einfrieren. Vendor aktualisieren.
- resolveReferences liest per Mapper am AccessService vorbei (SPEC §3 "kein Lesen per Mapper am Service vorbei"); Begründung im Code, aber bei künftigen Notiz-Rechten fragil. Mit `filterReadable` auf die Liste (ohne Zusatzabfrage) wäre es regelkonform. Lädt zudem alle Notizen des Workspace je Export (bei großen Workspaces teuer).
- Plan M4c Done 5 / §1.3 nennen "Notiz nicht verfügbar" und `filterReadable`; Umsetzung `common.not_available` und Workspace-Liste. Plan-Text an SPEC B47 angleichen.
