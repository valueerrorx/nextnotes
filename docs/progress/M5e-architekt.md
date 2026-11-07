# M5e-architekt
Auftrag: Testisolation UserDeletionTest nach TeamLifecycleTest klären; M5e (Isolation/IndirectRead Team-Fälle, smoke-m5, run-m5.sh, volle Matrix inkl. TTFB auf nextnotes-m5e).
Status: fertig
## Erledigt
## Nächster Schritt
- Ursache UserDeletionTest analysieren
## Offen
- Instanz nextnotes-m5e (NN_PORT_BASE=8290, sqlite 8291) up, build rc=0, App aktiv
- dev/nextcloud/scripts/probe-circles-off.sh neu (bash -n ok, noch nicht gelaufen)
- run-m4.sh → run-m5.sh (git mv) + probe-circles-off in Phase 1; README angepasst
- Repro läuft: sqlite PHPUnit Teams|Page|Access|Scenario|Routes (scratchpad/pu1.log); Hypothese: Job schluckt Ausnahme (Job::start catch Throwable) → Ursache im nextcloud.log
- tests/nextnotes/e2e/smoke-m5.mjs + docker.mjs removeTeamMember (node --check ok, noch nicht gelaufen)
- tests/nextnotes/php/Teams/TeamIsolationTest.php neu (Ex-Mitglied, eingeladen, eingefroren, Positivkontrolle Mitglied; Seiten/Exporte/Compat/indirekt); IndirectReadTest leaks/m4Views → public static; php -l ok, noch nicht gelaufen
- pgsql-Umgebung (8293) up; TeamIsolationTest 1. Lauf rot (429 ZIP-Rate-Limit) → resetRateLimits (ratelimit_entries); 2. Lauf pgsql OK (1 Test, 89 Ass.); IndirectReadTest pgsql OK
- pgsql: smoke-m5 hell grün (27), probe-circles-off grün, budget.mjs + Teams-Budget (docker.mjs seed/dropBudgetTeams) grün: TTFB 78 → 82 ms, API gleich
- URSACHE UserDeletionTest (Testfehler, kein Produktfehler): JobList::getJobsIterator baut je Zeile per Server::get dieselbe geteilte Job-Instanz (SimpleContainer::query registriert Auto-Wiring shared); deletionJobs() sammelte Instanzen → runDeletionJobs startet N× nur das zuletzt gebaute Argument. Reihenfolge ohne ORDER BY: SQLite nutzt Index job_argument_hash (EXPLAIN belegt) → nach Hash. Liegen Rest-Jobs (TeamLifecycleTest: Kontolöschungen k/m, Workspaces per sweep weg, Jobzeilen bleiben) vor, ist C's Job nur zufällig der letzte. Probe im Container: 3 Jobs → gleiche spl_object_id, Reihenfolge a1,c3,b2.
- Fix tests/.../UserDeletionTest.php: deletionJobs() → [id => argument], runDeletionJobs setzt je Zeile setId/setArgument vor start; neuer Job per array_diff_key statt end(); php -l ok
- Baseline sqlite Teams|Page|Access|Scenario|Routes (alter Testcode) 327 OK (20 min) – Fehler tritt nur mit Rest-Jobs auf
- Repro deterministisch sqlite: 6 Löschjobs vorab eingereiht → alter UserDeletionTest rot „alte C-Workspaces 2≠0“; neuer Test (setId string, Ref Job::setId(string)) mit 19 Rest-Jobs 3 OK, danach 0 Jobs übrig
- pgsql Teams|UserDeletionTest 67 OK (inkl. TeamIsolationTest)
- probe-circles-off räumt Team/Workspace auf; sqlite grün; docker.mjs dropTeams(owner) generisch; smoke-m5 räumt auf
- sqlite E2E smoke-m5 + budget hell/dunkel grün (27/51); Teams-Budget TTFB 37 → 40 ms
- docs/slices/M5-plan.md: Absatz „Umsetzung M5e“
## Nächster Schritt
- down.sh all, dann run-m5.sh (NN_PROJECT=nextnotes-m5e, NN_PORT_BASE=8290) im Hintergrund, Log scratchpad/run-m5.log
- run-m5.sh gestartet (Hintergrund), Log scratchpad/run-m5.log + run-m5-logs/
- run-m5: Build reproduzierbar (161), Phase 1 grün sqlite/mariadb/nginx (PHPUnit 458 sqlite+mariadb Exit 0, probe-grenzen mariadb, probe-circles-off alle drei); pgsql läuft
- Phase 1 pgsql grün (PHPUnit 458 OK, probe-grenzen, probe-circles-off); Phase 2 E2E läuft
- Lead-Anweisung (Matrix noch nicht starten) kam während des Laufs → run-m5.sh in Phase 2 abgebrochen (sqlite-E2E: 38 Specs×Theme grün, 0 rot, u. a. budget/smoke-m4/smoke-m5/teams-m5d hell); nginx-E2E nicht gelaufen; Instanz nextnotes-m5e abgebaut (down.sh all rc=0)
- Dateien von M5d2 (IndexViewService, MentionService, AppInfo/Application, l10n) nicht angefasst
## Nächster Schritt
- nach Freigabe Lead: run-m5.sh komplett (NN_PROJECT=nextnotes-m5e, NN_PORT_BASE=8290), danach Status fertig
- Freigabe Lead nach M5d2 (8841b09a): volle Matrix neu gestartet
- Lauf 2: Build reproduzierbar; Phase 1 grün sqlite (459, 1 Skip), mariadb (459 OK), nginx
- Lauf 2: Phase 1 pgsql grün (459 OK), alle 4 probe-circles-off grün; Phase 2 E2E läuft
- Lauf 2: sqlite E2E grün (66 Spec-Läufe = 33×2 Themes, 0 rot, 82 min seit Start); nginx läuft
- Lauf 2 (nach M5d2 8841b09a) run-m5.sh rc=0, 131 min: alle 4 Umgebungen grün; nginx E2E 33×2 grün; Instanz durch run-m5 abgebaut, keine Container/Volumes nextnotes-m5e
