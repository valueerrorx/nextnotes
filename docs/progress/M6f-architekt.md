# M6f – Architekt (Lebenszyklus Aufgaben)

Status: fertig

## Auftrag
Austritt (`left_team_at`, `onLeft`), Kontolöschung Lehrkraft (synchron, transferOwnership), `occ nextnotes:assignment:transfer`, Herabstufung (CleanupJob), Team-Löschung inkl. Teams nur mit Aufgaben (O6), `beforePurge`, Benachrichtigungen mit Aufgabenzahl. Plus M6e2-r1 Punkte 1–4.

## Erledigt
- Plan/SPEC gelesen (M6-plan §2.5, Done M6f, SPEC §5, §6, §8, §9.7)

- Code gelesen (Lifecycle, Cleanup, UserData, Listener, Notifier, Assignment*, Distribution, Mapper, TeamService)
- Instanz nextnotes-m6f sqlite (8621) hoch (up.sh exit 0)
- Entwurf: neue Klasse `AssignmentOwnership` (transfer/onLeft/releaseDeleted/checkDemoted/adminTransfer), `TeamService::teachers` strikt, `Command/AssignmentTransfer`; memberLeft auch ohne Team-Workspace (Aufgaben), markLeft alle Status; teamDeleted bei Zeile/Workspaces; beforePurge mit Budget, deleteOrphaned nur ohne Aufgaben (Sperre); Notifier transferred + team-Empfänger owner; M6e2-r1 1–4


## Offen/Annahmen
- Code: TeamService::teachers, AssignmentMapper (findByTeam/existsForTeam/findOwned/setOwner/ownersInActiveTeams/countOwnedWithSubmissions), SubmissionMapper (markLeft, assignmentIdsWithLeft nur aktive Teams), WorkspaceMapper::findAssignmentWorkspacesForTeam, neu Service/AssignmentOwnership.php, Command/AssignmentTransfer.php, UserDataService (releaseDeleted + owner_uid NULL), TeamLifecycleService (teamDeleted O6, notifyOwners, isRelevant, memberLeft), MembershipsRemovedListener, AssignmentDeletion (onlyOrphaned), TeamCleanupService (checkDemoted, purge/beforePurge, deleteOrphaned unter Sperre), DistributionService notify-Flag, AssignmentService notify:false, Notifier (transferred, deadline-Guard, team-Empfänger owner, ohne SubmissionStateService), AssignmentNotifications::transferred, info.xml 0.3.3 + Command; tests CleanupTestCase Konstruktoren
- l10n de/de_DE ergänzt (transferred, Team-Meldung nur Aufgaben, Plural Abgaben)
- Tests: neu tests/nextnotes/php/Assignments/AssignmentLifecycleTest.php (10 Tests); TeamLifecycleTest Mock isRelevant; CleanupTestCase Konstruktoren
- make build EXIT 0; install-app sqlite; App 0.3.3, occ-Befehl registriert
- PHPUnit sqlite --filter AssignmentLifecycleTest: OK 10/241 (Exit 0) – Entwicklungslauf
- Doku: SPEC §5/§6/§8/§9.7, ADR 0008 Nachtrag M6f, Plan §13 Umsetzung M6f
- check-forbidden-sql 0 Treffer (Exit 0); validate-info OK; check-upstream ROT nur durch fremde Arbeitsbaum-Änderung (UI-rail: templates/create.php gelöscht → „cssPages.create“), nicht M6f
- Befund: paralleler Slice UI-rail ändert gleichzeitig routes.php, PageController, templates, Page-Tests, TeamIsolationTest (nicht meine Dateien)
- Laufend: PHPUnit sqlite Abschlusslauf (Assignment|Distribute|Submission|TeamLifecycle|CleanupJob|OrphanCheck|TeamLeave|UserDeletion|CoreScenario); mariadb/pgsql hochfahren für AssignmentLifecycleTest (neues SQL: Joins)
- mariadb/pgsql (Projekt nextnotes-m6f, 8622/8623): AssignmentLifecycleTest+CleanupJobTest OK 26/473 je DB (Exit 0); run-phpunit-Seitenprüfung 303 durch fremde UI-rail-Änderung (Index → dashboard.php), daher Scratch-Kopie des Runners ohne Seitenprüfung
- PHPUnit sqlite Abschlusslauf: 90 Tests, 3 Fehler (erwartet: AssignmentNotificationTest::testDeadlineNoticeRights wegen Frist-Guard, UserDeletionTest 2× Konstruktor) → Tests angepasst, die 3 gezielt nachgeprüft OK (Exit 0)
- Instanzen nextnotes-m6f (sqlite/mariadb/pgsql) abgebaut; Demo/gftest unberührt

## Nächster Schritt
- keiner (Bericht an Lead)

## Offen/Annahmen
- left_team_at für alle Status (nicht nur offen/abgegeben); Links der Meldungen bis M6g auf index.php
- check-upstream rot nur durch UI-rail-Arbeitsbaum (templates/create.php)
