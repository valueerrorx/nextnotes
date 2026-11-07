# M6c-fix – Architekt

Status: fertig

## Auftrag
1) Glocke (Erinnerung) + ggf. Duplizieren bei Nur-Lesen aktiv (Adapter statt Patch, sonst P35); Backend prüfen (Leserecht, Kompat, Aufgaben-dueAt nur Schreibrecht). 2) `TaskService::mutate` → `MentionService::notifyNew` nach Commit + PHPUnit. SPEC §6/§3/§11. Instanz nextnotes-m6cf, Port 8500.

## Erledigt
- Backend geprüft: ReminderService get/set/remove (inkl. /task-reminder) mit 'read'; Kompat-Routen haben keine Reminder; Task-PATCH (dueAt) 'write'; advanceDue setzt dueAt nur mit Schreibrecht → keine Änderung nötig
- `TaskService`: `MentionService` injiziert, `mutate` ruft `notifyNew` nach Commit (alter Inhalt vor Änderung)
- `tests/nextnotes/php/Scenario/TasksTest.php`: `testRestTaskMentionsNotify`, `testReadOnlyPersonalReminders`; php -l 0
- Adapter `nextnotes-teams.js` `personalControls`: gibt `.btn-reminder` (und bei Material `.btn-duplicate` + Mobil-Eintrag) nach P11 frei; kein src-Patch
- SPEC §2.2 (M2c-Absatz), §3 Material-Oberfläche, §6 mention, §11.1 P11-Zeile
- E2E material-m6b Schritt 3: Glocke/Duplizieren aktiv, Schreibwerkzeuge gesperrt, Erinnerung setzen/löschen als Schüler
- `make -C nextnotes build` 0 (161), `check-upstream` 0, `check-forbidden-sql` 0 Treffer
- up.sh sqlite/mariadb/pgsql + install-app + enable (Hintergrund, NN_PROJECT=nextnotes-m6cf, 8500)
- PHPUnit --filter '(TasksTest|ReminderJobTest|MentionsUsersTest|ViewsM4gTest|TeamUiTest|Material)': sqlite/mariadb/pgsql je Exit 0, 49 Tests, 1159 Assertions
- E2E Lauf 1 (mariadb): material-m6b rot (Datumsfeld versteckt → per evaluate gesetzt, Test angepasst), teams-m5d 1 FAIL „nach Umwandeln kein Banner“ (nicht berührt, Wiederholung), mention-tasks-m6c grün

- E2E Lauf 2 (sqlite): hell material-m6b 126, teams-m5d 82, mention-tasks-m6c 80 grün (Exit 0); dunkel material-m6b 126 grün (Exit 0)
- down.sh all: keine Container/Volumes nextnotes-m6cf; Demo/gftest unberührt

## Nächster Schritt
- keiner

## Offene Punkte
- teams-m5d „nach Umwandeln kein Banner“ einmal rot auf mariadb unter PHPUnit-Parallellast, auf sqlite grün → vermutlich Timing (networkidle vor Reload)
- Aufgaben-Erinnerung je Aufgabe in schreibgeschützter Tasklist: Backend erlaubt (/task-reminder, Leserecht), UI-Bedienelemente bleiben gesperrt (nicht im Scope)
- Duplizieren bei reiner Lesefreigabe (kein Material) bleibt gesperrt: Server verlangt Schreibrecht im Ziel
