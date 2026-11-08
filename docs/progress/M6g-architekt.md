# M6g – Architekt (Oberfläche Aufgaben + ZIP)
Status: in Arbeit
## Auftrag
M6g laut M6-plan (Tabelle, §2.6, Done M6g); > 1 500 Zeilen → nur M6g1 (Lehrkraft-Ansicht, Erstellen inkl. Notiz-Menü, Austeilen, Abgabenliste, Feedback/Punkte, Rückgabe/Wiederöffnen, Lesemodus, ZIP, Meldungs-Links), M6g2 beschreiben. Basis 3b090b83. Kein Commit. Instanz nextnotes-m6g, Port 8620.
## Entscheidungen
- Teilung: Schätzung gesamt ≈ 2 700 Zeilen → M6g1 jetzt.
- Assets: build.mjs `ownPages` (Seite ohne Upstream-Vorlage, Upstream-Rahmenskripte + CSS-Liste) → nextnotes-page-assignments.{js,css}; eigenes JS/CSS js-src/nextnotes-assignments.js, css-src/nextnotes-assignments.css.
- Lesemodus: iframe der Druckansicht (api_export_note.php inline) → frame-src 'self' auf assignments (ADR 0012 Nachtrag).
- Rail sichtbar: teamsAvailable ∧ (memberTeams ≠ [] ∨ eigene/verwaiste Aufgaben) – ohne Level-Abfragen je Seite.
- Texte: NC-l10n (IL10N), Label „Assignments“ → de vorläufig „Aufgaben (Team)“.
- Material-zuerst im Dialog: nicht in M6g1 (Notiz-Menü an Material-Notizen deckt „aus Material“).
## Erledigt
- Plan/Code gelesen
- routes.php: page#assignments, assignments_api#export, #exportSubmission
- AssignmentExportService (neu), ExportService::archiveFile, ZipExportService::locked public, CompatController::fileResponse public, AssignmentsApiController export-Methoden (php -l ok)
- Tests angepasst: ControllerAttributeTest (Allowlists), PageIsolationTest (COVERED + B-Liste), TemplateOutputTest
## Nächster Schritt
- PageController::assignments, AssignmentsViewService, PageAssets, build ownPages, Template, Rail, JS, CSS, l10n, Notifier-Links, teams.js Notiz-Menü
## Offen/Annahmen
- (Fortsetzung) PageController::assignments + Rail (railLabel), AssignmentsViewService, PageAssets OWN_ASSETS, bundles ownPages + build.mjs + check-upstream.mjs, Template assignments.php, js-src/nextnotes-assignments.js, css-src/nextnotes-assignments.css, l10n de/de_DE, Notifier-Links (assignments.php?assignment=/?team=), teams.js Notiz-Menü + body data-nn-teacher (IndexViewService), CspFactory + ADR 0012 Nachtrag, PageCspTest
- AssignmentService: submissions_by_status, not_distributed/quota_full (DistributionService::pending), deletion_at
- Lead-Zusatz Speed Dial: Rail-Eintrag weg, speed_dial.php → 303 dashboard.php?workspace=, SpeedDialViewService/Template/Bündel weg (excludePages), CSS-Reste weg, PHP-Tests angepasst (ViewsM4g, PageIsolation, TeamIsolation, IndirectRead, TemplateOutput), SPEC Punkt 28 + §4.2 + §9.1.6 + §14
## Nächster Schritt (Stand)
- E2E-Listen speed_dial anpassen, make build, check-upstream, PHPUnit-Tests (AssignmentExportTest, AssignmentsViewTest), E2E assignments-m6g, Lauf
- occ transfer: Hinweis bei Zustand unknown (Command/AssignmentTransfer.php), SPEC §9.7 (M6f-r1 H3)
- Tests neu: php/Assignments/AssignmentExportTest.php, AssignmentCleanupRunTest.php (M6f-r1 H2, run() mit gemockter Zeit), AssignmentsViewTest.php; e2e/assignments-m6g.mjs; E2E-Listen ohne speed_dial
- make build ok, check-upstream grün, check-forbidden-sql 0 (Exit 0)
## Nächster Schritt
- Instanz nextnotes-m6g (8620) hoch, PHPUnit-Teilmenge + E2E, abbauen; Doku Plan §14/ADR 0008
- Lead-Zusatz 2: Icon-Leiste Gruppen start | content (Graph…Anhänge) | utility (Papierkorb); ui-nav prüft Trennlinien; SPEC Punkt 28
- Lead-Zusatz 3: Graph ans Ende der Mitte (nach Anhänge); ui-nav Reihenfolge/Trennlinien angepasst; SPEC Punkt 28
- Lauf 1 sqlite (nextnotes-m6g/8620): Page OK 65, ControllerAttribute OK, IndirectRead OK, TeamIsolation OK; Assignments 48 Tests 2 F (Export-Test: Feedback nur ab submitted → Test korrigiert); RouteDiff/CoreScenario: Export-Routen in PORT_ONLY-Listen nachgetragen
- Lauf 2 läuft: neue Assignment-Tests, Routes, CoreScenario, E2E assignments-m6g/views-m4g/ui-nav (hell+dunkel)
- Befund E2E: Menüeintrag fehlte in Team-Workspaces ohne Ordner (rights.teacher nur mit Ordnern) → AssignmentsViewService::teachesAny für data-nn-teacher (IndexViewService); Team-Vorauswahl aus ?create über createTeam (Leserecht + Lehrkraft geprüft); Student-Export der Aufgabe = 403 (sieht Aufgabe, AccessTest-Konvention)
- E2E assignments-m6g hell+dunkel grün (66 Prüfungen); Schlusslauf (PHPUnit-Teilmenge + views-m4g/ui-nav) läuft
- Schlusslauf sqlite: Assignments 48 OK, Page 65 OK, Routes 6 OK, CoreScenario 3 OK, ControllerAttribute/IndirectRead/TeamIsolation OK; E2E views-m4g, ui-nav hell+dunkel grün; assignments-m6g hell+dunkel grün (66); check-upstream grün; check-forbidden-sql 0; down.sh all Exit 0
## Status: fertig (M6g1); M6g2 offen (siehe Plan §14)
## Review M6g1-r1 (Blocker) – Fortsetzung
- TeamService::members Request-Cache (teamId|level), resetCache leert; Test php/Assignments/AssignmentMembersCacheTest.php (Spy auf Container → guarded-Aufrufer members)
- deletion_at: Code bleibt (alle Leser), Plan §14 korrigiert
- IndexViewService::canAssignFrom (eigener normaler WS + teachesAny, oder Team-WS als Lehrkraft); speedDialReserved-Text + l10n de/de_DE
- Speed-Dial-Scopes Background/NoteService: nicht trivial → Plan §14 notiert, nicht geändert
- Nächster Schritt: Lauf sqlite Assignments + Teams + AssignmentsViewTest, E2E assignments-m6g hell; abbauen
- Lauf r1-Fix sqlite: Assignments 49 OK (inkl. AssignmentMembersCacheTest), Teams 65 OK, ViewsM4g 9 OK; E2E assignments-m6g hell grün (66); check-upstream grün; check-forbidden-sql 0; down.sh all Exit 0
## Status: fertig (M6g1 inkl. Review r1)
