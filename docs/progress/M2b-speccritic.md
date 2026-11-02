# M2b speccritic (Diff-Review r1)
Auftrag: Diff ggü. ed41a3b9 gegen M2-plan §1/§2-M2b/§5, SPEC, ADR 0005/0012 prüfen; Ergebnis docs/reviews/M2b-diff-r1.md.
## Status: fertig (r1, r2)
## Erledigt
- git status/diff gelesen; src/ unverändert (diff --stat leer, Exit 0)
- Gelesen: Attachments-, Snapshot-, Search-, Lock-, Link-, Changes-, DuplicateService, Controller, Jobs, NotesProvider, routes/info.xml
- OCP-Stichprobe: getOrCreateFolder 35.0.0, detectContent 18, paginated 20, getUploadedFile 6, Response::getHeaders (Header überschreiben CSP)
- Review geschrieben: docs/reviews/M2b-diff-r1.md (BLOCKER: 2 Doku-Punkte B22/Plan-Disposition, B13 ohne Sitzung)
## Offen
- Tests M2b.3–5 fehlen im Baum (Tester)
- r2: Blocker 1+2 erledigt, lockOwner/Verknüpfung/Snapshot-Besitzer geprüft; docs/reviews/M2b-diff-r2.md FREIGABE
