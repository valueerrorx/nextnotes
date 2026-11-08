# M7h architekt
Auftrag: Ausgetretene Schüler verlieren Zugriff auf Aufgaben/Abgaben (404 überall); Teammitglieder ohne App-Freigabe beim Austeilen überspringen. Doku SPEC/ADR0008/admin/user, Tests, 3 DBs.
Status: fertig
## Erledigt
- Analyse: zentrale Stelle AccessService (assignmentAllows read, requireSubmission, submissionNoteLevel, visibleAssignmentIds c, visibility/computeLevel für kind=assignments über linked_team_id); Notifier Schüler-Zweig; DistributionService::canReceive (isEnabledForUser).
- Regel: aktives Team → Schüler erreicht eigene Abgaben nur als Mitglied (Level ≥ 1, Marke egal); verwaist/eingefroren → nur ohne Austrittsmarke.
- Code: SubmissionMapper (ownWithTeam, hasLeftIn), AccessService (studentAccess, studentTeamOpen, ownReadable, visibility/computeLevel, requireSubmission, visibleAssignmentIds), AssignmentService (own nur mit studentAccess), Notifier, DistributionService::canReceive (IAppManager) – php -l ok
- Export: AssignmentExportService::ownSubmission (ohne Zugriffsprüfung) für occ export-user; UserExportService reachableOnly, NextNotesMigrator übergibt true (Selbstauskunft ohne unerreichbare Abgaben) – php -l ok
- SPEC §3/§8/§9.1/§9.3/§9.4/§9.6/§9.7/§13 angepasst
- Doku: ADR 0008 Nachtrag M7h (e durchgestrichen), admin.md §3/§9/§10, user.md, SPEC §8 export-user; js-src Kommentar
- Tests: AssignmentIsolationTest::testFormerMemberAndFrozen neu (404-Sweep Ex-Mitglied, Lehrkraft-Gegenprobe, eingefroren, Wiedereintritt), AssignmentLifecycleTest::testLeaveKeepsSubmissionForTeacher, SubmissionStateTest unsubmit 404, AssignmentsViewTest Kommentar, DistributeTest::testGroupRestrictedMemberSkipped, E2E assignments-m6g Schritt 7c
- make build Exit 0, check-upstream Exit 0, check-forbidden-sql Exit 0
- Instanzen nextnotes-m7h (9900) sqlite/mariadb/pgsql hochgefahren, App aktiv (Setup-Skript /tmp/claude-1000/m7h/setup.sh)
- PHPUnit sqlite gezielt (Isolation/Lifecycle/SubmissionState/Distribute): 23/24 grün, Gruppentest brauchte sleep(4) (AppConfig-Cache TTL 3 s) → danach grün
- Test ergänzt: Admin/UserExportTest::testLeftStudentExport (export-user mit, Migrator ohne Abgabe nach Austritt), Migrator-Hilfsfunktion extrahiert; DistributeTest sleep(4)
- PHPUnit gefiltert (346 Tests) sqlite + mariadb: je 2 Fehler (MaterialAccessTest::testMarkingAndErrorCodes, TeamLeaveTest::testSystemManagedContentNeverLeaves: synthetische kind=assignments-Workspaces ohne echtes Team → jetzt 404) → Fixtures auf echtes Team umgestellt; Nachlauf sqlite+mariadb (9 Tests inkl. UserExportTest) grün; BlockMarkupTest-Fehler stammt von parallelem Agenten (M8a, Klasse nicht installiert)
- pgsql gefiltert: dieselben 2 erwarteten Fehler; Nachlauf (9 Tests) grün
- E2E assignments-m6g hell (sqlite): grün, 164 Prüfungen, Exit 0
- Instanzen nextnotes-m7h abgebaut (down.sh je DB Exit 0, keine Container/Volumes)
## Nächster Schritt
- keiner (Bericht an Lead)
## Offen
- Migrator (user_migration, vom Benutzer ausgelöst) exportiert nur erreichbare Abgaben → [THOMAS]
- Rolle: tests/ eigentlich verboten, Lead beauftragt Tests ausdrücklich → umgesetzt, im Bericht vermerkt
- Schüler verwaister/eingefrorener Teams ohne Marke behalten Lesen (Matrix S unverändert) → [THOMAS] im Bericht
# fix (M7h-fix, Review r1)
Auftrag: Blocker ZIP-Export ohne Workspace (Aufgabentitel/Ordner) + Hinweise 1–5; Tests 3 DBs (nextnotes-m7h, 9900).
Status: fertig
## Erledigt
- Aufrufer `ownWorkspaceIds` (grep lib/): nur ZipExportService::attachments (Z.175) und ZipExportService::scope (Z.210 → entries, structured)
- Weitere Wege mit `findOwned($uid)` ohne Kind: WorkspaceService::listVisible (→ storage, keys, templates, Mentions, teamWorkspaceKeys, paletteOwner), TeamLifecycleService::makePrivate (nur display_order, unkritisch)
- Befund: listVisible enthielt unerreichbare Schüler-Workspaces → storage zählt mit (Hinweis 4), templates() liefert Notizen aus Ordner „Templates“/„Vorlagen“ (Aufgabentitel) → zentral filtern
- Code: AccessService::ownWorkspaceIds filtert über visibility (Schüler-WS nur bei studentTeamOpen); WorkspaceService::listVisible eigene nur erreichbare (Hinweis 4 + templates/keys) – php -l ok
- Code Hinweis 1: SubmissionMapper::clearLeftFor, TeamLifecycleService::teamDeleted → clearLeftOfMembers (Mitglieder beim DestroyingCircleEvent, Fehler → Marke bleibt) – php -l ok
- Test AssignmentIsolationTest: Needles Titel/Ordner (needles()), leak() entpackt ZIP, Sweep Kompat-GET auch ohne Query, Exporte ohne WS + Storage in testFormerMemberAndFrozen, neu testTeamDeletedAfterLeaveAndRejoin – php -l ok
- Doku: SPEC §3 Zeile Ex-Schüler („alle“-Wege), §8 Team gelöscht (Marken), §12 R18 (Lead nannte §14 = OCP-Liste → Risiken stehen in §12); user.md Satz Hinweis 5
- build/check-upstream/forbidden-sql Exit 0; Instanzen nextnotes-m7h (9900) sqlite/mariadb/pgsql hoch (Exit 0)
- Befund Hinweis 4: storage zählt nur role=note → Kopien wurden nie gezählt; Test prüft stattdessen listVisible ohne Schüler-WS nach Austritt
- sqlite testFormerMemberAndFrozen grün; Mutationsprobe (ownWorkspaceIds alt) → rot bei api_export_structured.php, Fix zurückgespielt (Achtung: nach install-app apachectl graceful wegen opcache)
- Mutationsprobe clearLeftOfMembers aus → testTeamDeletedAfterLeaveAndRejoin rot; zurückgespielt; sqlite neuer Test grün
- PHPUnit Filter Assignment|Submission|Team|Material|Isolation|Admin|Export|Settings: sqlite/mariadb/pgsql je OK 376 Tests, 13130 Assertions, Exit 0
- Instanzen nextnotes-m7h abgebaut (down.sh je DB Exit 0, keine Container/Volumes)
## Nächster Schritt
- keiner (Bericht an Lead)
## Offen
- Lead nannte SPEC §14 für Risiko; §14 ist OCP-Liste → R18 in §12 Risiken
- Hinweis 2 nicht gefixt (kein offensichtlicher kleiner Fix: Mitgliedschaft nach Löschung nicht prüfbar), nur R18
