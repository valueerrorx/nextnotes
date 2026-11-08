# M6d – Architekt (Aufgaben-Kern, Backend)

Auftrag: M6d nach `docs/slices/M6-plan.md` r2 (§2.3, §2.3a, Done M6d 1–7, Review-Hinweise 1/7/8) umsetzen, Tests 3 DBs, Instanz `nextnotes-m6d` Port 8620. Nicht anfassen: CSS, settings.php, nextnotes-teams.js, Upstream-JS (paralleler UI-Agent).

## Erledigt
- Plan und Review r2 gelesen

- Instanz `NN_PROJECT=nextnotes-m6d NN_PORT_BASE=8620` up.sh sqlite rc=0 (8621)
- Migration Version000600Date20261110000000 (assignments, submissions), info.xml 0.3.1
- Db: Assignment/Submission + Mapper; NoteMapper::idsForAssignment, FolderMapper::findByAssignment, WorkspaceMapper::findQuotaOwned, AttachmentMapper::sizeForTeamAssignments
- QuotaService: quotaForNote/quotaForAssignment/quotaForTeam, used(), enforce sperrt Team-Zeile zuerst (0 Zeilen → 404), Benutzer ohne kind=assignments; AttachmentService usage/quotaForNote; Excalidraw/EmbeddedImage(Note)/Conversion umgestellt
- NoteService: update für role≠note ohne Umzug/Ordner/Tagebuch (400), Status-Neuprüfung nach lockRow; delete/restore/moveToFolder/removeFromFolder/archive + TrashService::destroy → requireNote 'manage' (role≠note 403)
- AccessService: kind=assignments READ, noteLevel Vorlage/Abgabe, assignmentAllows/requireAssignment (inkl. review), visibleAssignmentIds, requireSubmission, filterReadable eigene Abgaben, requireFolder write auf assignment_id 403

- DuplicateService::copyAsTemplate; AssignmentService (index/show/create/update/delete/submissions/submission), AssignmentDeletion::purge, AssignmentsApiController + 7 Routen; WorkspaceService::requireTeamForCreate public, listMenu (Q3), ensureDefault nur normal; IndexViewService workspaceMap, PageFrameService Startseite ohne kind=assignments
- make build rc=0; deploy sqlite (scratchpad deploy.sh) → 0.3.1; check-schema sqlite OK (expected-m1.json um 2 Tabellen ergänzt)

## Nächster Schritt
- Tests tests/nextnotes/php/Assignments/ (AccessTest Matrix+3a, CrudTest, QuotaTest), FilterReadableTest erweitern

## Offen/Annahmen
- Tests geschrieben: tests/nextnotes/php/Assignments/{AssignmentTestCase,AssignmentAccessTest(Matrix, 3a, Vorlage/visibleIds, Done 6, FilterReadable 3b),AssignmentCrudTest,AssignmentQuotaTest}.php
- Fix: SnapshotService::keepDaysFor bei Notiz ohne Workspace (Vorlage) → Standard (sonst 404 beim Bearbeiten der Vorlage)
- sqlite Assignments: 10/10 grün
- Nächster Schritt: Routen-Tests (Isolation/RouteDiff/CoreScenario) anpassen, Regression sqlite, Gegenproben, mariadb/pgsql, Checks, Doku
- IsolationTest (+7 Routen, 129), RouteDiffTest PORT_ONLY, CoreScenarioTest erlaubte Abweichungen angepasst
- check-upstream rc=0, check-forbidden-sql 0 Treffer rc=0
- Umfang: neue Prod-Dateien 960 Z., Änderungen bestehend ca. 350 Z. (inkl. fremder UI-Diffs in Stat), Tests 730 Z. → Prod < 1.500, kein Split
- mariadb/pgsql: up + HEAD(0.3.0)-Install via scratchpad old-install.sh läuft
- Doku: SPEC §2.1, §3 (Tabelle, Invariante, Aufgaben-Block), §9.5, §9.7 (review); Plan §10 Umsetzung M6d
- mariadb+pgsql: HEAD 0.3.0 installiert → Upgrade 0.3.1 (deploy.sh); check-schema OK (13 Tabellen) mysql rc=0, pgsql rc=0
- sqlite Isolation|RouteDiff|CoreScenario|FilterReadable: 160 Tests, 1 Fehler CoreScenario `GET /tasks readonly` zusätzlich (Altlast aus 6c9d36f0/M6b, nicht M6d)
- mariadb + pgsql (Assignments|FilterReadable|TeamLeave|TeamAccess|TeamQuota): je OK 35 Tests/1156 Assertions, Exit 0
- Gegenproben sqlite: Abgabe-Notiz mit read statt review, Vorlage mit read statt review, quotaForNote ohne Aufgaben-Zweig → 3/3 rot; zurückgesetzt, Assignments wieder OK 16/549
- sqlite Regression Material|Teams|Notes|Attachment|Quota|Snapshot|Trash|Duplicate: 490 Tests; 1 Fehler TeamLifecycleTest (Konstruktor AccessService) → Test angepasst, OK; 1 Altlast CoreScenario (s. o.)
- make build rc=0; Instanz nextnotes-m6d abgebaut (down.sh all)
- Status: fertig
- Lead r1: allowed-diff.json +2 extra (GET /tasks checklists[].readonly, notes[].readonly); Plan §10 Abweichung Hinweis 5; Instanz neu hoch, Core/Isolation/RouteDiff laufen
- sqlite CoreScenario|Isolation|RouteDiff: OK 157 Tests/8064 Assertions, Exit 0; Instanz abgebaut. Status: fertig
