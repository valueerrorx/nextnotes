# M2c – Tester
Status: fertig

## Auftrag
Tests M2c (RouteDiff vollständig, ReminderJob/Notifier, Mentions, Tasks, Settings, Konvertierung 501) + run-m2.sh auf sqlite/mariadb/pgsql frisch, Gegenprobe, down.sh all.

## Erledigt
- Plan M2c, SPEC §2.2/§3/§4/§5/§6/§11.2/§13 gelesen; Architekten-Testdiff (RouteDiff/Isolation/CoreScenario/ApiTestCase) gesichtet: RouteDiff PENDING leer + DONE = Plan §0, 112 Routen ok
- sqlite-Dev-Instanz läuft (up/install/enable)
- ApiClient::parallel: optionaler JSON-Body (abwärtskompatibel)
- tests/nextnotes/php/Jobs/ReminderJobTest.php (9 Tests): `run-phpunit.sh sqlite --filter ReminderJobTest` Exit 0. Befund Harness: im PHPUnit-Prozess ist notifications-App nicht geladen → notify still verworfen; setUp lädt sie (wie cron.php)

- tests/nextnotes/php/Scenario/TasksTest.php (5 Tests) sqlite: 4 PASS, testInvalidDueAtTimeOfDay FAIL (Befund: due_at '2026-02-28T25:99' → 200 gespeichert)
- tests/nextnotes/php/Scenario/MentionsUsersTest.php (4 Tests) sqlite PASS

- tests/nextnotes/php/Scenario/SettingsSystemTest.php (7 Tests) sqlite: 6 PASS, version-Schlüssel im Test korrigiert (current_version)
- Alle obigen Läufe = Stand VOR Lead-Nachbesserung (Zusatzauftrag Lead: Isolation/CoreScenario task-reminder auf echte Aufgabe, neue Fälle task-reminder-Validierung, Leserecht-Wiederholung, DST, >200 fällig, lazy Settings, ETag/If-Match, if_version 409)

- NACH Lead-Stand (install-app sqlite neu kopiert): Isolation/CoreScenario task-reminder auf {tl}/{task} bzw. echte ID; ReminderJobTest +5 (Validierung, 1000-Limit, >200, Leserecht-Wiederholung, DST); SettingsSystemTest +lazy; NoteVersionTest (ETag, if_version parallel) → `--filter 'ReminderJobTest|CoreScenario'` Exit 0, `--filter 'NoteVersionTest|SettingsSystemTest'` Exit 0

- Volle Suite sqlite (Lead-Stand): 222 Tests, 2 FAIL (Exit 1): Isolation DELETE task-reminder Variante task_id=A auf B-Tasklist → 200 (idempotent, kein Leck; Testentwurf → task_id als Literal '1', Kommentar); TasksTest Uhrzeit 25:99 (Produktbefund)
- Code-Hash vor Gegenprobe: scratchpad/code-before.sha, notifier.sha

- Isolation sqlite nach Anpassung: 114 Tests Exit 0
- Gegenprobe: canRead-Prüfung in Notifier::prepare entfernt → ReminderJobTest 2 FAIL (unshare, Mapper), Exit 1; zurückgesetzt, sha256 Notifier + Gesamtcode identisch (d854a191…)

- ENDLAUF (Lead-Stand, Code-Hash vor/nach d854a191… gleich): `dev/nextcloud/run-m2.sh sqlite|mariadb|pgsql` einzeln, frisch: je rc=1; je 222 Tests, einzige Failure TasksTest::testInvalidDueAtTimeOfDay; mariadb zusätzlich 1 PHP-Warnung dns_get_record (DnsPinMiddleware, kein Netz, ausgelöst in Isolation GET reminder) – umgebungsbedingt
- `dev/nextcloud/down.sh all` Exit 0, keine Container/Volumes übrig

## Nächster Schritt
- keiner (Bericht an Lead)

## Offene Punkte
- Befund: due_at mit ungültiger Uhrzeit ('2026-02-28T25:99', 'T24:00', 'T12:60') → 200 und gespeichert (B25 sinngemäß)
- Isolation DELETE task-reminder: task_id kein Objekt-Slot (idempotentes Löschen eigener Erinnerungen, kein Leck) – Abweichung vom Lead-Wunsch {task}
- Harness: in PHPUnit notifications-App per loadApp laden (sonst notify still verworfen); Annahme: Cron lädt alle Apps
- Mention-Benachrichtigungen (§6 `mention`) laut Plan O10 erst M4 – nicht getestet
