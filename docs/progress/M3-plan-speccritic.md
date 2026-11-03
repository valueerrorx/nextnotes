# M3-plan speccritic
Auftrag: Review R1 von docs/slices/M3-plan.md gegen SPEC/ADRs/Inventur/src/Backend; Ergebnis docs/reviews/M3-plan-r1.md.
Erledigt:
- Gelesen: M3-plan.md, SPEC §1, §4, §10–§13, ADR 0004/0006/0007/0012/0013/0015/0016
- OCP-Stichproben: Util::addScript -> <app>/js/ (Vendor in vendor-assets nicht ladbar), EmptyCSP frame-ancestors 'none', addAllowedFrameAncestorDomain @13, getLogoutUrl @35 ok
- src-Stichproben: index.php:467-475, events-auto-save.js:659-700 (keepalive/Beacon), notes_list.php:105 Form-POST + unified-search.js:1097 form.submit()-Fallback, note_display.php Teilen/Offline, note-edit-lock.js:309
- Backend: AttachmentsApiController PDF ohne CSP-Header (Empty -> 'none'), LockService Feld vorhanden, WsShare/AccessService READ vorhanden, InputValidator::heading erlaubt < > "
- Befunde: 4 Blocker, Hinweise -> Review geschrieben
Nächster Schritt: keiner
Status: fertig

## Runde 2
Auftrag: r2 prüfen (Blocker 1-4, Hinweise, Lead-Entscheidungen, B26) -> docs/reviews/M3-plan-r2.md
Erledigt R2: git diff docs/ gelesen, Plan r2 gegrept, Blocker 1-4 erledigt; B26 geprüft (html-sanitize.php:283, keine Content-Writer für data-action gefunden; class/id-Delegation als Auflage) -> docs/reviews/M3-plan-r2.md FREIGABE
Status: fertig
