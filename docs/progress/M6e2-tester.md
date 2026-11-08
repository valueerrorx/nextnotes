# M6e2 Tester – Fortschritt
Status: fertig

## Auftrag
M6e2 testen: Routen-Register task-reminder, material-reminder.mjs waitFor+Schalterprobe, neue PHPUnit (Notification, DeadlineJob, Search, task-reminder). Ein Lauf sqlite + E2E hell. Instanz nextnotes-m6e2t, Port 8680.

## Erledigt
- Register: IsolationTest::routeTable (GET task-reminder owned, query task_id {task}), RouteDiffTest::PORT_ONLY, CoreScenarioTest (rec + Port-only-Liste)
- e2e/material-reminder.mjs: waitFor in check/setRemind; remindShown() prüft Schalter aus/an/aus (Notiz) und an (Übersicht), schließt über Abbrechen

- Neu: php/Assignments/{NotificationRows (Trait), AssignmentNotificationTest, AssignmentDeadlineJobTest, AssignmentSearchTest}.php, php/Jobs/TaskReminderShowTest.php
- make build Exit 0; up.sh+install-app sqlite (nextnotes-m6e2t, 8681) Exit 0
- Neue Klassen sqlite: 8 Tests grün (nach Testfixes: status()→subStatus, DELETE confirm)
- Gegenprobe im Container (Repo unverändert, danach zurückgesetzt+diff geprüft): Notifier ohne Eigentümer-/review-Prüfung → 2 rot; Suche Scope erweitert → grün (filterReadable greift), zusätzlich ohne filterReadable → rot (unified: S1 findet S2-Abgabe)
- PHPUnit sqlite --filter '(Assignments|Isolation|RouteDiff|CoreScenario|Reminder|Search)': 217 Tests, 2 FAIL (17:54 min), beide Testregister: assertCount 135→136, GET task-reminder Fall task_id={task} fälschlich als Objekt-Slot (A-task_id auf B-Notiz → 200 korrekt) → task_id '1' wie DELETE. Nachlauf beider: OK (1+3 Tests)
- E2E THEMES=light run-e2e.sh sqlite material-reminder: Exit 0, 45 Prüfungen grün (inkl. 4 Schalter-Prüfungen)
- down.sh sqlite Exit 0, keine Container/Volumes nextnotes-m6e2t; Demo unberührt

## Nächster Schritt
- keiner (Bericht an Lead)

## Offene Punkte/Annahmen
- Vorlagen (workspace_id NULL) und fremde Abgaben (fremder Workspace) sind schon durch den Workspace-Scope aus der Suche; filterReadable ist zweite Linie (Gegenprobe nur mit erweitertem Scope rot)
- Job mariadb (Plan M6e2 Testart) nicht gelaufen: laut Auftrag nur sqlite
- Gegenprobe /notes/search über Apache griff nicht (OPcache), unified (CLI) zeigte rot
