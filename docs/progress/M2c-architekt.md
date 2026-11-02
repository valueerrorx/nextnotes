# M2c – Architekt (Umsetzung)

Status: in Arbeit

## Auftrag
M2c nach `docs/slices/M2-plan.md` umsetzen (Tasks, Mentions, Erinnerungen + ReminderJob + Notifier, Settings, System, Users, Konvertierung 501, /shared*); Parameterkarte/RouteDiff vollständig; run-m2.sh mind. SQLite grün.

## Erledigt
- Gelesen: Plan, SPEC §2/§3/§4/§5/§6/§11.2/§13/§14, M2b-Progress, Upstream Mentions/Tasks/Reminders/Settings/System/Users + lib/mentions.php, tasklists.php, checklists.php, Testsuite (Isolation, RouteDiff, CoreScenario, capture.php)
- OCP geprüft: ISearch::filteredSearch (35), Notification IManager/INotification/INotifier/AlreadyProcessedException(17)/UnknownNotificationException(30), setRichSubject/setParsedSubject, RichObject `highlight` (id, name, link)

## Entwurf (Entscheidungen)
- Erinnerungen je Empfänger (`user_id`); `reminder_at` in Notiz-JSON und GET /notes/{id}/reminder = ungesendete Notiz-Erinnerung des Aufrufers (nach Versand null; NC-Glocke zeigt sie)
- Neue Erinnerung ersetzt alle früheren (auch gesendete) derselben Notiz/Aufgabe des Aufrufers → keine Altlasten
- Wiederholung: Job legt nach Versand die nächste Zeile an; Aufgaben-Erinnerung schiebt `dueAt` mit (wie Upstream dismiss), nur wenn Aufgabe offen
- E-Mail: `email_enabled` immer false (NC-Notifications übernimmt Versand)
- `/reminders*`: leere Listen/0/success (Plan); `/shared`, `/shared/with-me`: Upstream-Form mit leeren Listen statt nacktem `[]` (JS liest `shared_notes`/`items`) → Abweichung, Plan/SPEC angepasst
- Settings: Muster-Allowlist `^[a-z][a-z0-9_]{0,62}$` + Sperrliste (globale/geheime/gestrichene Schlüssel) + Normalisierung bekannter Schlüssel; `language`/`timezone` nur lesend aus NC; `recently_opened_notes` nur lesend über NoteService::openedNoteIds (H3); Wertlänge ≤ 64 KiB, ≤ 300 Schlüssel je Benutzer → Abweichung vom Wort „Whitelist“ (Upstream-Frontend nutzt > 60 teils dynamische Schlüssel)
- Tasks: Schreiben ohne Sperrprüfung (Plan/ADR 0005), Auto-Snapshot wie Speichern
- Mentions: nur Workspaces mit ws_shares; Vorfilter `search_text LIKE %@uid%`, Zählung per Regex wie Upstream; `{ownerId}` ignoriert

- Code fertig (php -l ok): ReminderService, MentionService, WorkspaceService::unshare (Tx, löscht Reminder/Mention-Status des Empfängers), NoteSerializer reminder_at; Controller Tasks/Mentions/Reminders/Settings/System/Users + NotesApi convert*; Notification/Notifier; BackgroundJob/ReminderJob; Application registerNotifierService; info.xml ReminderJob; routes.php 112 API-Routen (Upstream-Reihenfolge per Skript); l10n de
- check-forbidden-sql 0 (exit 0), validate-info ok (exit 0)
- Abweichung: /users/lookup ohne display_name (Fixture-Treue); /users/me is_admin immer false (kein Admin-Sonderweg)

## Nächster Schritt
- Tests: RouteDiff (PENDING leer, 112), Isolation-Tabelle (+excluded/control), ApiTestCase dump/purge, CoreScenario m2c-Schritte; capture.php M2c; Upstream-Fixtures

## Offene Punkte/Annahmen
- Code fertig (php -l ok): Exception NotImplemented; Db Reminder/MentionHandled(+Mapper), WsShareMapper::recipients, NoteMapper::idsInWorkspace/lockRow; SettingsService (API-Regeln); NoteService purge(+Reminder/Mentions/markProcessed), saveContent, openedSetting; Recurrence; TaskService
- Tests angepasst: RouteDiff (PENDING leer, DONE alle, 112), ApiTestCase dump/purge (+reminders, mentions_handled), Isolation (36 neue Einträge, Seeds tl/task/sub/mn mit Drittbenutzer-Freigabe, Kind `excluded`, `control`-Status), CoreScenario m2cSteps + recAny
- capture.php M2c-Schritte (Datenschlüssel strings/settings nur Typ; lookup als Upstream-Admin); Upstream `up --no-build` (kein Netz), 113 Fixtures, alte 77 identisch; 36 neue kopiert; allowed-diff: Konvertierung (3× status/missing/extra); Upstream gestoppt
- SPEC: §2.2 Vereinfachungen M2c, §4.2 Settings/Shared, §11.2 B23–B25, §14 ergänzt; Plan Done M2c.1 (/shared Upstream-Form)
- sqlite-Umgebung läuft (up, install-app, enable); run-phpunit --filter RouteDiff|ControllerAttribute|CoreScenario → Exit 0 (7 Tests); Volllauf läuft (Log scratchpad/p2.log)
- run-phpunit sqlite voll: 189 Tests, 6614 Assertions, Exit 0
- Smoke im Container: ReminderService/Notifier (2 versendet, kein Doppelversand, Wiederholung neue Zeile, de/en-Betreff, unshare → AlreadyProcessed + Empfänger-Erinnerung weg, purge → Benachrichtigungen 0) ok
- down.sh all + upstream down.sh ausgeführt
Status: fertig

## Nachbesserung r1 (docs/reviews/M2c-diff-r1.md), keine Container, keine tests/nextnotes/php
- B1 ReminderService::set: task_id ≤ 64 (400), TaskService::requireTask (kein tasklist 400, Aufgabe fehlt 404); zusätzlich ≤ 1000 offene Erinnerungen je Benutzer (ReminderMapper::countPending)
- B2 TaskService::advanceDue(uid, …, newTrigger): dueAt nur mit WRITE, sonst Erinnerung läuft ohne Inhaltsänderung weiter; dueAt = Wanduhr des neuen Auslösers in Besitzer-TZ
- H1 sendDue: Stapel 200 bis leer oder 60 s; H2 Settings lazy (get/set lazy=true, hasKey null); H3 NoteService::update lockRow + Version in Tx; H4 Recurrence::nextAfter d/w/m/y kalendarisch in TZ (lokal geprüft: DST 27.→31.03. 09:00 ok)
- H6 ETag = version-Prüfsumme(JSON), If-Match nur Teil vor '-'; GET /mentions-Schreiben = Upstream, in SPEC §2.2 vermerkt; H5 Plan/SPEC angeglichen
- capture.php: task-reminder mit echter Aufgaben-ID; php -l alles ok; check-forbidden-sql 0 Treffer
- Tests NICHT angepasst (Verbot): Isolation task-reminder-Einträge (n1/'t1') und CoreScenario ('t9') brauchen echte Tasklist/Aufgabe → tester
Status: fertig

## Tester-Befund r2 (TimeOfDay, syncReminder-Cap, dns_get_record)
- InputValidator::validDateTime (Datum + Std ≤23, Min/Sek ≤59) in TaskService due_at, ReminderService::parse, Recurrence::dueToTimestamp
- ReminderMapper::MAX_PENDING + atLimit(); genutzt in ReminderService::set und TaskService::syncReminder
- dns_get_record: Ursache password_policy HIBP (api.pwnedpasswords.com) bei IUserManager::createUser in ApiTestCase (Trace per Instrumentierung im Container belegt), nicht App-Code; Fix dev/nextcloud/up.sh: has_internet_connection=false → Trace leer
- run-m2.sh einzeln frisch: sqlite rc=0, mariadb rc=0, pgsql rc=0 (je 222 Tests, 7075 Assertions, keine Warnung); down.sh all
- SPEC B25 + Obergrenze ergänzt
Status: fertig
