# M3c2 Diff-Review r1 (speccritic)

VERDICT: BLOCKER

Geprüft: Arbeitsbaum gegen HEAD 710792b3 (git diff + ExcalidrawService.php, excalidraw_editor.php, probe-m3c2*) gegen M3-plan „Teilung M3c“, SPEC §4.2/§4.3 Nr. 13/§11.1 P14/§11.2 B35–B36, ADR 0005, 0012, 0017.

## Blocker
1. Datenverlust: Markdown-Notiz mit Diagramm > ca. 1 MB wird geleert.
   Fundstelle: `nextnotes/lib/Service/HtmlSanitizer.php:307-308` (`sanitizeMarkdown`, `(<[a-zA-Z][^<>]*?)[\s\/]+on\w+…` mit `?? ''`), erreichbar über `ExcalidrawService::write` → `NoteService::saveContent` → `applyContent` (ExcalidrawService.php:205; Grenze 7 MiB in Zeile 27).
   Szenario: `api_save_excalidraw.php` mit `action=save_embedded_diagram`, Markdown-Notiz, `diagram_data` ≥ ca. 1 MB (z. B. ein eingefügtes Foto im Diagramm). Das maskierte Attribut ist ein einziges Tag ohne `<>`. Der Lazy-Quantor überschreitet `pcre.backtrack_limit` (1 000 000), `preg_replace` liefert null, `?? ''` macht daraus den Inhalt '' und die ganze Notiz wird leer gespeichert. Der Vollmodus (`placeFull`) auf einer Markdown-Notiz ist genauso betroffen, ebenso jedes spätere PATCH der Notiz.
   Lokal nachgestellt (PHP 8.5 CLI, JIT an, Standardlimits): bei 500 kB unverändert; bei 1 MB, 2 MB und 7 MB ist die Ausgabe 0 Bytes, Fehler „Backtrack limit exhausted“. Der HTML-Pfad (`sanitizeHtml`, libxml) bleibt bei 7,2 MB intakt, der Tag-Scanner in ExcalidrawService ebenfalls (getestet).
   Was fehlt: Ein Regex-Fehler darf nie zu leerem Inhalt führen. Der Sanitizer muss dann einen Fehler werfen, und der Speicherweg muss mit 413/400 abbrechen. Ohne Bereinigung durchzulassen ist keine Lösung. Alternativ die Muster linear machen (z. B. Tag-weise per possessivem Tag-Scanner wie TAG in ExcalidrawService). Dazu ein Probe-Fall „Markdown + Diagramm ≥ 2 MB → Inhalt erhalten“ und ein Fall „> 7 MiB → 413“; beide fehlen in probe-m3c2.sh.

## Hinweise (kein Blocker)
- `WorkspaceService::moveNotes` (WorkspaceService.php:~330): Ziel und Quelle werden vor `tx->run` aufgelöst. Löscht ein paralleler `delete` das Ziel, zeigen die Notizen auf eine gelöschte Workspace-ID und sind unsichtbar. Besser: in der Transaktion neu lesen bzw. sperren, wie bei `delete` (Review r2 N1).
- `moveNotes`/`reorder` verlangen `manage`. Ab M5 (`teamLevel`) könnten Team-Workspaces `manage` liefern; der Kommentar „nur eigene“ (Quota B17) ist dann nicht mehr erzwungen. In M5 ausdrücklich `owner_type=user && owner_id=uid` prüfen.
- `ExcalidrawService::write`: Eine Vorschau, die ein Snapshot zeigt, bleibt erhalten. Beim späteren Ausdünnen der Snapshots räumt sie niemand mehr auf, das Speichervolumen wächst. Für den CleanupJob (M5) vormerken.
- `contains`-Nadel endet auf `"`, `references()` nicht. Das ist uneinheitlich, aber konservativ.
- Template: `JSON_THROW_ON_ERROR` ohne `JSON_INVALID_UTF8_SUBSTITUTE`: Ein Titel mit ungültigem UTF-8 führt zu 500 statt zur Seite.

## Ohne Befund
Rechte: fremd 404, Lesefreigabe 403, alle 8 `workspaces.php`-Aktionen (Probe Z. 85–106), `share/unshare` erst nach `manage` → 501. CSRF: kein `NoCSRFRequired` auf POST (Probe 412). Sperre: `assertCanSave(uid, id, '')` → 423 wie PATCH (ADR 0005). Escaping: `print_unescaped` nur für JSON mit `JSON_HEX_*`, Titel per `p()`. B35: `data-*` wird nicht ausgeführt, `markdown-parser.js` nimmt es ebenso aus, Speicherung per `htmlspecialchars`/DOM; kein XSS-Weg gefunden. SVG-Vorschau: Prüfung plus Auslieferung als Download mit `sandbox`-CSP und `nosniff` (AttachmentResponse). Präfix-Tricks (`<x:script>`) sind damit nicht ausführbar. SnapshotMapper::contains: portabel (QB, `escapeLikeParameter`), begrenzt auf `note_id`. `delete`-Umbau verhaltensgleich. CSP: keine neue Lockerung (ADR 0012 Nachtrag). P14 minimal und in §11.1 sowie upstream-patches.txt gelistet. Vorläufige Entscheidungen (delete wie B18, Teilen 501 bis M5) sind in SPEC §4.2 und den M4/M5-Vormerkungen dokumentiert und stimmen mit der Spec überein.
