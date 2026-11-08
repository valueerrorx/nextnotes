# M6e2 Architekt – Fortschritt
Status: fertig

## Auftrag
M6e2: AssignmentDeadlineJob, Benachrichtigungen assignment_*/submission_* (Q4/Q5), Suche (eigene Abgaben, nie Vorlagen); Zusatz GET task-reminder (nur eigene Erinnerung) + Adapter-Vorbelegung. Nicht anfassen: nextnotes-layout.css, e2e/layout-left.mjs.

## Erledigt
- Kontext gelesen (Plan M6e2/§2.4/§6/§11, SPEC §6, Notifier, SearchService, DistributionService, SubmissionService)

- Neu: Service/AssignmentNotifications.php, Service/AssignmentDeadlineService.php, BackgroundJob/AssignmentDeadlineJob.php (info.xml, Version 0.3.2, keine Migration)
- Sendepunkte: DistributionService::reconcile (assigned), SubmissionService giveBack/reopen, AssignmentService::update (Verlängerung → deadline_notified_at NULL + alte Sammelmeldung weg)
- Mapper: AssignmentMapper findDeadlinePassed/markDeadlineNotified/idsOpenDistributed; SubmissionMapper assignmentIdsOverdue (JOIN)/assignmentIdsWithLeft/statusCounts

- Notifier: prepareAssignment (assigned/returned/reopened nur eigene Abgabe; deadline_passed nur review), l10n de/de_DE
- Suche: NoteMapper::findList Filter roles; SearchService search/provider über filterReadable
- GET task-reminder: ReminderService::getTask, RemindersApiController::showTask, routes.php
- Adapter nextnotes-teams.js openTaskDueModal: eigener Wert per GET
- SPEC §4.2, §5, §6, §7, §14 nachgezogen

- Fix: NoteMapper::LIST_COLUMNS um assignment_id/submission_id (filterReadable verwarf sonst eigene Abgaben aus Listen-Abfragen)
- make build EXIT 0, make check-upstream grün, check-forbidden-sql 0 Treffer, validate-info OK
- Instanz nextnotes-m6e2 sqlite (8621) hoch, App 0.3.2, AssignmentDeadlineJob registriert
- Gegenprobe (Scratch-Test außerhalb tests/, M6e2ProbeTest): OK 56 Assertions (assigned an S1/S2/X, nicht L; returned mit Namen L, S2/L → AlreadyProcessed; reopened mit Frist; Job 2× → je 1 Sammelmeldung an L und M, not_distributed 1, S2 missed; Suche S1 eigene, nie S2/Vorlage, L keine; resolve fremd 404; task-reminder eigene/fremde/404/400; nach Löschen AlreadyProcessed + markProcessed)

- PHPUnit sqlite (Assignments, ReminderJob, Search, Isolation, FilterReadable, IndirectRead, RouteDiff, CoreScenario, Tasks): 217 Tests, 3 Fehler, alle erwartet = neue Route GET /notes/{}/task-reminder fehlt in Test-Registern (IsolationTest::routeTable, RouteDiffTest::PORT_ONLY, CoreScenarioTest Fixture) → Tester (tests/ nicht meine Datei)

- E2E material-reminder hell: Original-Spec rot (prüft Dialog synchron, Adapter öffnet nach GET); Scratch-Kopie mit waitFor + Probe „eigener Wert an/aus“ grün (39 Prüfungen) → Tester: waitFor in check()/setRemind ergänzen
- Plan M6 §12 Umsetzung M6e2 ergänzt
- Instanz nextnotes-m6e2 abgebaut (down.sh sqlite), Demo unberührt

## Nächster Schritt
- keiner (Bericht an Lead)

## Offene Punkte/Annahmen
- /changes, Backlinks, resolve: findList filtert role=note schon → nur Testnachweis
