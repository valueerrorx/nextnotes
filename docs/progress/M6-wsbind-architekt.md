# M6-wsbind – architekt (Phase Plan)
Status: fertig (Plan r3)
## Auftrag
Plan: Aufgabe fest an Workspace binden (`assignments.workspace_id`, Pflicht im POST). Ergebnis `docs/slices/M6-wsbind-plan.md`. Kein Code.
## Erledigt
- Progress-Datei angelegt
- Lead: tplpick als e258fcd5 committet → Hauptbaum gelesen; Review-Hinweise aus docs/reviews/UI-tplpick-r1.md aufgenommen (Plan §4.1)
- Gelesen: SPEC §2.1/§3/§8/§9.1/§9.6, ADR 0008, AssignmentService, AssignmentMapper, Assignment-Entity, AccessService (assignmentAllows, visibleAssignmentIds), AssignmentsViewService, nextnotes-assignments.js (inScope/homeTeams, browse), WorkspaceDeletion, WorkspaceService (assignmentsKey, requireTeamForCreate), Notifier-Links, WorkspaceResourceProvider, NoteService::sortList, upgrade-path.sh, AssignmentTestCase
- Befund: Workspace-Owner wechselt nur bei Anlegen, makePrivate, Kontolöschung (nur user) → kein Verschieben zwischen Teams
- Entscheidung: workspace_id nullable, Bindung = Anzeige-/Filterregel, Rechte bleiben team-basiert
- Plan geschrieben: docs/slices/M6-wsbind-plan.md
- r2 nach Entscheidungen Thomas/Lead: NOT NULL + Migration löscht Altaufgaben per AssignmentDeletion::purge; Löschen ohne Ziel löscht Aufgaben; make-private 409; Teams-Ressource je Workspace; Lösch-Dialog mit Aufgabenzahl
- Befund: .ref/nextcloud-server ohne lib/ (git status zeigt gestagte Löschungen) → Migration-DI ungeprüft
- r3 nach Review r1 (BLOCKER): make-private erlaubt (Variante a), Invariante nur aktives Team, L1 nach team_state, Löschen ohne Ziel mit confirm/Vorprüfung/Team-Sperre/409/Teilabbruch, lockRows-Rückgabe vor copyAsTemplate, workspace_id nur bei Leserecht, Migration Leerprüfung+Kommentar, Done-Kriterien ergänzt
## Nächster Schritt
- keiner (Plan r3 freigegeben)

## Offen
- Demo verliert Aufgaben beim Upgrade (Thomas informieren)

# Phase Umsetzung
Status: fertig
## Auftrag
Plan r3 umsetzen (+ r2-Hinweise H1–H3), kein Kompatibilitätscode, nicht committen.
## Erledigt
- Worktree auf e258fcd5; Plan/Reviews/Progress aus Hauptbaum kopiert
- Code fertig (php -l ok): Migration Version000800Date20261112000000, info.xml 0.9.3, Assignment/AssignmentMapper (idsForWorkspace, rebindWorkspace, countByWorkspace), AssignmentService::create/view, WorkspaceService::assignmentKey, WorkspaceDeletion::delete, AssignmentsViewService (page/createSource/assignmentUrl, Texte), SettingsViewService+settings.php (data-team-assignments), TeamsUiConfig (Texte), Notifier, WorkspaceResourceProvider, NoteService sort=recent, JS assignments/teams, templates/assignments.php, l10n de/de_DE
- Abweichung: TeamsUiConfig + templates/assignments.php zusätzlich (Texte/Insel); sourceMore gefiltert ohne all.more bleibt mit exakter Zahl
- Doku fertig: SPEC §2.1/§3/§4.2/§4.3 Nr.30/§8/§9.1/§9.5/§9.6, ADR 0021 (neu, H1 Grenze), ADR 0008 Nachtrag, docs/app user/admin/upgrade, CHANGELOG
- Tests: expected-m1.json (workspace_id, nn_asg_ws), AssignmentTestCase::createAssignment (workspace aus Quelle + Team-Prüfung, wsKeyOfNote), CrudTest/SourceWorkspaceTest/ViewTest/QuotaTest/DistributeTest/MaterialIsolationTest/TeamResourceProviderTest angepasst; neu AssignmentWorkspaceBindTest, AssignmentMigrationTest (eigener ISchemaWrapper-Stub, H2)
- E2E: smoke-m6, blocks-*, tplpick, screenshots.mjs (workspace statt team), assignments-m6g Abschnitt 8 (L1); upgrade-path.sh (wsbind-Erwartung, api_status) + upgrade-probe.php (note-ws, ws-exists)
- E2E neu: tests/nextnotes/e2e/assignment-wsbind.mjs; Singular-Texte deleteLastAssignment/deleteMoveAssignment
- CHANGELOG: Unreleased (leer) + „0.9.3 – 2026-11-09“ (validate-info verlangt beides; Lead prüfen)
- Links: nextnotes/build/node_modules, dev/nextcloud/node_modules (Symlink), tests/nextnotes/vendor (Kopie) – am Ende entfernen
- make build check-upstream Exit 0; check-forbidden-sql 0 Treffer Exit 0; validate-info Exit 0
- Instanz nextnotes-wsbind sqlite (10601) hoch, App 0.9.3 aktiv; Logs in .cache/wsbind/
- 409-Test: HTTP-Race nur MariaDB/pgsql (SQLite: Anfrage läuft erst nach Commit), zusätzlich deterministisch in-process (Stub purge legt Kandidat an)
- PHPUnit sqlite AssignmentWorkspaceBindTest|AssignmentMigrationTest: 8 Tests, 270 Ass., 1 skip (HTTP-Race), Exit 0
- pgsql (nextnotes-wsbindpg, 10703) + mariadb (nextnotes-wsbindmy, 10802): App aktiv; check-schema sqlite/pgsql/mariadb Exit 0
- Neue Tests pgsql 8/8 (290 Ass.), mariadb 8/8 (290 Ass.) Exit 0; mariadb danach abgebaut
- Breiter sqlite-Lauf 1: 158 Errors = Too many open files (password_policy list-19.php) → ulimit-Fix lokal in run-phpunit.sh (nicht committen); 1 echter Fehler IsolationTest POST /assignments (team→workspace) angepasst
- WorkspaceDeletion: $others() nur einmal vor der Tx (kosmetisch) – nach den laufenden Breitläufen App neu installieren + Bind-Tests wiederholen
- make dist baut aus HEAD (ohne Änderungen) → Paket aus Arbeitsbaum per scratchpad/wtpkg.sh, check-dist OK (0.9.3, 491 Einträge)
- upgrade-path sqlite 1378e5d8 → 0.9.3 grün Exit 0 (Aufgaben gelöscht, smoke-m6 hell 48 ok); Projekt danach von selbst abgebaut
- upgrade-path pgsql 1378e5d8 → 0.9.3 grün Exit 0 (smoke-m6 hell 48 ok); Projekt abgebaut
- PHPUnit sqlite breit (pu3, ulimit-Fix): 317 Tests, 12757 Ass., 1 skip, Exit 0
- App sqlite neu installiert (WorkspaceDeletion kosmetisch); Bind+Migration+Lifecycle 18 Tests, 1 skip, Exit 0
- E2E assignment-wsbind: Lauf 1 rot (hell: Login-Timeout unter Last; dunkel: Lösch-Button im Zeilenmenü) → Spec öffnet .ws-actions-toggle; Lauf 2 hell 45 + dunkel 45 grün Exit 0
- PHPUnit pgsql breit (pg2): 317 Tests, 12777 Ass., 0 skip, Exit 0 (vor kosmetischer Änderung); Nachlauf Bind/Migration/Lifecycle nach Neuinstallation läuft (1. Versuch: timeout 115 s abgebrochen, ohne Limit neu)
- Regression hell (e2e3): tplpick 31, teams-m5d 95, material-m6b 144, smoke-m6 48 grün; assignments-m6g rot (140 ok): assignments.php ohne ?workspace= = zuletzt geöffneter (Team-)Workspace → Spec öffnet eigenen Workspace ausdrücklich (auch in assignment-wsbind); Lauf e2e4 (m6g + wsbind hell/dunkel) läuft
- PHPUnit pgsql Nachlauf Bind/Migration/Lifecycle: 18 Tests, 589 Ass., Exit 0
- E2E e2e4 sqlite: assignments-m6g hell/dunkel 165, assignment-wsbind hell/dunkel 45, Exit 0
- Aufräumen: run-phpunit.sh (ulimit) zurückgesetzt; Projekte nextnotes-wsbind/-wsbindpg/-wsbindmy/-wsbindup abgebaut; Links + vendor-Kopie entfernt; Demo unberührt
## Nächster Schritt
- keiner; Bericht an Lead
