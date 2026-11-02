# M2a speccritic (Diff-Review r1)
Status: fertig
Auftrag: Arbeitsbaum M2a gegen docs/slices/M2-plan.md (M2a, §1), SPEC §2/3/4/11.2/13/14, ADRs prüfen; 10 Abweichungen bewerten; Ergebnis docs/reviews/M2a-diff-r1.md.
## Erledigt
- Plan, SPEC-Diff gelesen; src/ unverändert (git status leer)
- Gelesen: AccessService, ApiController, Middleware, routes (53 M2a-Routen), alle Api-Controller + Services, Mapper, InputValidator, Serializer, UrlRewriter, HtmlSanitizer, Transaction, FileCleanupQueue, dev-Skripte, bootstrap/phpunit.xml, capture.php
- OCP-Stichprobe ok (Middleware, insertIgnoreConflict, escapeLikeParameter, getUserLanguage, getDisplayName, NotModifiedMiddleware)
- Images: lokale Tags = gleiche Image-IDs, Versionen stimmen (docker inspect)
- Review geschrieben: docs/reviews/M2a-diff-r1.md (BLOCKER: 1; Hinweise H1–H4)
## Offene Punkte
- tests/nextnotes/php leer → Done 2–9 hängen am Tester
- r2 gestartet: Nachbesserung prüfen
## Runde 2 (Status: fertig)
- Geprüft: WorkspaceService::delete (Ziel ungeteilt/nicht Archives, Ersatz-WS in tx), WsShareMapper::sharedWorkspaceIds, HtmlSanitizer B19, InputValidator (Apostroph), NoteService show/beacon, SPEC B18–B20, Plan-Notizen
- Ergebnis: docs/reviews/M2a-diff-r2.md FREIGABE, Hinweise N1 (Zielwahl außerhalb tx), N2 (Apostroph-Escaping M3)
