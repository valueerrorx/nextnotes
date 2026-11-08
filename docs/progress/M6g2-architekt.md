# M6g2 – Architekt (Schüler-Oberfläche Aufgaben)
Status: fertig
## Auftrag
Schüler-Ansicht „Meine Aufgaben“ auf assignments.php, Editor-Banner (Abgabe/Vorlage), Lösch-Dialog mit Zahl + Export, verwaiste Teams (deletion_at), feedback.txt lokalisieren, Speed-Dial-Scopes (falls im Rahmen). Basis 335eb399. Kein Commit. Instanz nextnotes-m6g2, Port 8620.
## Erledigt
- Plan §2.6/§14/M6g gelesen
## Nächster Schritt
- Code lesen: template/JS/CSS assignments, AssignmentsViewService, API submissions
## Offen/Annahmen
- Entscheidung: „Abgabe zurückziehen“ = neuer Übergang unsubmit submitted→assigned (eigene Frist → reopened), Bedingungen wie studentCanEdit mit Frist override ?? due_at; POST /submissions/{id}/unsubmit
- Backend: SubmissionStateService (unsubmit, studentCanUnsubmit, unsubmitTarget, studentLock), SubmissionService::unsubmit, AssignmentService::ownView (effective_due, can_submit, can_unsubmit, lock), Controller+Route
- Tests: RouteDiff/CoreScenario PORT_ONLY, IsolationTest excluded, SubmissionStateTest (Tabelle + testUnsubmit)
- Befund: index.php?note=<Vorlage> → 404 für Lehrkraft (shownView keyFor null) → Fix geplant; Upstream-Speichern schickt folder_id → 400 für Abgabe-Kopien im Ordner → Fix geplant (NoteService)
- Speed-Dial-Scopes: nicht im Rahmen → lassen
- Fixes: NoteService::update (folder_id unverändert bei Vorlage/Abgabe), IndexViewService::shownView (Vorlage ohne Workspace + Banner)
- Banner: AssignmentsViewService::noteBanner, note_display.php (vor Titel), teams.js Handler (flushSave → submit/unsubmit → reload), TeamsUiConfig Texte, CSS nextnotes-layout.css
- Seite: nextnotes-assignments.js (Schüler-Detail, Abgeben/Zurückziehen, Feedback ab returned, Download, Löschen-Dialog, teamHint), CSS, Texte (AssignmentsViewService::texts), l10n de/de_DE
- feedback.txt lokalisiert (IL10N, „Points: %s“, „Feedback by %s“)
- Tests: AssignmentsViewTest::testEditorBannerAndTemplatePage; E2E assignments-m6g erweitert (1b Vorlage, 2b S1-UI, S2-Isolation, S1-Feedback, 8 verwaist + Lösch-Dialog)
## Nächster Schritt
- make build, check-upstream, check-forbidden-sql; Instanz nextnotes-m6g2 (8620) sqlite; PHPUnit Assignments+Page+Routes+Isolation+CoreScenario; E2E assignments-m6g hell+dunkel; abbauen; SPEC/Plan-Doku
- make build ok, check-upstream grün, check-forbidden-sql 0
- Instanz nextnotes-m6g2 sqlite (8621) up, App 0.3.3 aktiv
- Doku: SPEC §9.1/§9.2/§9.5/§9.6, Plan §15
- PHPUnit läuft (Pfade /tmp/nn-tests/php/...)
- Fix: JS open() durch var open in row() verdeckt → openItem; Build neu (Reinstall nach PHPUnit nötig)
- Lauf 1 sqlite: Page 65 OK, Routes 6 OK, ControllerAttribute OK, CoreScenario OK; Assignments 51/1 F (Kopie im Editor readonly: shownView nutzte Workspace-Recht → Fix noteLevel für role=submission); Isolation 1 F (Export-Routen aus M6g1 fehlten in routeTable → ergänzt)
- Lauf 2: AssignmentsView/SubmissionState OK; Isolation Zählwert 136→139; E2E: Vorlagen-Seite hing (backlinks 404 für Vorlage → LinkService leer für role≠note); Lauf 3 E2E hell 132/1 (Wettlauf Listen-Neuladen im Test → waitFor detached)
- Nächster Schritt: Schlusslauf (build, check-upstream, SQL, PHPUnit Assignments/Page/Routes/Isolation/IndirectRead/ControllerAttribute/CoreScenario, E2E hell+dunkel), abbauen
- Schlusslauf sqlite: build/check-upstream grün, check-forbidden-sql 0; PHPUnit Assignments 51 OK, Page 65 OK, Routes 6 OK, Isolation 141 OK, IndirectRead 2 OK, ControllerAttribute 3 OK, CoreScenario 3 OK; E2E assignments-m6g hell+dunkel grün (133); down.sh sqlite Exit 0
- Doku: SPEC §9.5 (Backlinks, Schreibrecht Kopie), Plan §15 Befunde
## Status: fertig
## Review r1 (BLOCKER unsubmit nach Feedback) – Fortsetzung
- Auftrag: studentCanUnsubmit + feedback_at/feedback/points NULL; SPEC §9.2; withdrawConfirm-Warnung; Tests (Feedback→403, closed→409, ausgetreten→403)
- Code: SubmissionStateService::studentCanUnsubmit (feedback_at/feedback/points NULL), SPEC §9.2, withdrawConfirm-Warnung (en/de/de_DE, Seite + Banner), Tests testUnsubmit (Punkte/Feedback → 403, closed → 409) + testUnsubmitAfterLeaving (403)
- Nächster Schritt: Lauf sqlite SubmissionStateTest + AssignmentsViewTest, abbauen
- Lauf r1 sqlite (nextnotes-m6g2/8621): SubmissionStateTest 4 OK (121), AssignmentsViewTest 2 OK (73); down.sh Exit 0
## Status: fertig (inkl. Review r1)
