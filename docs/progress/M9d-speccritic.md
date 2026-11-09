# M9d speccritic

Status: fertig

## Auftrag
Diff-Review r1 M9d (Ressourcen, Quote) gegen HEAD f2848346; Ergebnis docs/reviews/M9d-r1.md

## Erledigt
- Gelesen: Plan M9d/§2/§4/§6, Reviews M9a-r1/M9b-r1, Diff lib (HttpGate, LimitedSink, ResourceFetcher, ResourceBatch, GraphClient, ImportService, ImportRun, Attachment-/NoteService, Mapper), Tests (Namen, ResourceFetcherTest), SPEC/ADR/Plan-Diff
- OCP-Stichprobe: detectContent, newFile(resource), getTemporaryFile, NC Client (Accept-Encoding, sink), FOUND_ROWS
- curl-Test docker run --rm nextcloud:35: gzip/deflate/gzip,gzip/chunked Bombe → Abbruch bei 1 MiB, rc 0
- Review geschrieben: docs/reviews/M9d-r1.md (VERDICT: BLOCKER, 1 Blocker, 5 Hinweise)

## Nächster Schritt
- keiner (r2 nach Fix)

## Offen
- Blocker 1: Temp-Reste nach SIGKILL (cleanOld ruft niemand)
