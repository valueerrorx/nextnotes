# M4b Diff-Review r1 (speccritic)

VERDICT: FREIGABE

Keine Blocker.

## Geprüft
- XSS: /convert-markdown → sanitizeHtml, /convert-html → sanitizeMarkdown, Snapshot render=1 → sanitizeHtml, notes/{id}/convert und Import → applyContent (sanitizeForType + storable). Endausgabe läuft immer durch den Sanitizer; HTML→MD→HTML öffnet keinen Weg vorbei. Kanban-Links: nur index.php?note=ID, http(s), mailto; alles mit p(). Base64-SVG-Anhänge: AttachmentResponse liefert SVG als Download, sandbox-CSP, nosniff. Keine CSP-Änderung.
- Rechte: convert write (fremd 404, lesend 403, Test), assertCanSave wie PATCH; Import requireByKey write + Ordner im Ziel (B15/B44); Snapshot show read.
- Daten: Snapshot (beforeConversion) in der Transaktion; Anhänge in tx, discard() im catch löscht IAppData sofort; storable über applyContent.
- DoS: checkSize vor Parsen an allen vier Stellen (413); Pcre/PcreCalls werfen 413/400 statt leerer Ausgabe; Import 50/16/64 MiB vor Verarbeitung; Rate-Limits 60/min bzw. 20/min. Hashes in upstream-ported.txt stimmen.

## MINOR
1. ImportService.php:139–148: Import mit Base64-Bildern legt die Notiz an, dann inneres update(). Scheitert danach toggleFavorite, rollt die äußere Transaktion die Zeilen zurück, die neuen IAppData-Dateien bleiben aber liegen (discard nur im inneren catch). Unwahrscheinlich; Abhilfe: Favorit vor dem update setzen oder $created nach außen reichen.
2. NoteService.php (update, prepare mit $writable->getWorkspaceId()): PATCH mit Base64-Bildern und gleichzeitigem Workspace-Wechsel prüft die Quote gegen den Quell-Workspace, der Anhang landet im Ziel. Randfall.
3. M4-plan.md §1.2/M4b/Lead-Auflage M4c nennen weiter 1 MiB und `ConversionLimits`; SPEC §4.5 sagt 384 KiB und `ConversionService::MAX_RENDER_INPUT`. Plan nachziehen, damit M4c/M4f die richtige Konstante nehmen.
4. ConversionTest.php:98 prüft < 1,5 s statt der Zielmarke ≤ 1 s aus Done 2; Grenze oder Done-Text angleichen.
5. Snapshot render=1 über 384 KiB → 413 für die ganze Antwort (laut Plan so gewollt). Die Revisionsseite zeigt dann auch den Rohinhalt nicht. Prüfen, ob das Frontend in diesem Fall ohne render=1 nachlädt.
