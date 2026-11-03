# M3b2 speccritic (Diff-Review r1)
Auftrag: Arbeitsbaum gegen HEAD prüfen (Anhänge, PDF, Audio, Such-POST, P2, Tag-Filter) → docs/reviews/M3b2-diff-r1.md
## Erledigt
- Start; HEAD laut git log 7906491e (Auftrag nennt 7182dc2f)
## Nächster Schritt
- Diff lesen
## Offen
- Diff gelesen (git diff HEAD, audio_player.php); geprüft: CSRF page#search, history-Patch, PDF-CSP (EmptyCSP nicht gemerged, CSPMiddleware), audio_player Rechte (download()=requireNote read+checkWorkspace), CspFactory default frame-ancestors self, Sanitizer data-action, Tasklist-IDs normalisiert, LIKE-Escaping NoteMapper, tagsFor chunked
- Befund: P1 (new Function, B3) und P3 (javascript:) nicht im Diff, Plan Z.86/127 ordnet sie M3b2 zu; data-onclick nirgends gesetzt → toter Pfad
- docs/reviews/M3b2-diff-r1.md geschrieben: FREIGABE, 3 Hinweise
## Status: fertig
