VERDICT: FREIGABE

Diff-Review M2c, Runde 2 (Nachbesserung zu r1). Die Prüfung beruht auf Code-Lektüre. Tests habe ich nicht ausgeführt (das macht der Tester).
- B1 erledigt: `ReminderService::set` begrenzt `task_id` auf ≤ 64 Zeichen (sonst 400) und prüft `TaskService::requireTask` (Notiz ist Tasklist, Aufgabe existiert, sonst 400/404). Pro Benutzer sind höchstens 1000 offene Erinnerungen erlaubt; das Ersetzen einer vorhandenen zählt nicht mit.
- B2 erledigt: `advanceDue` verschiebt `dueAt` nur bei `noteLevel ≥ WRITE`. Ohne Schreibrecht wird die nächste Erinnerung angelegt, der Inhalt bleibt unverändert.
- H1 erledigt: `sendDue` arbeitet in Stapeln, bis ein Stapel unvollständig ist oder 60 s verbraucht sind. Jede Zeile wird gelöscht oder per `claim` beansprucht und fällt damit aus `findDue`. Die Schleife kommt also voran. Die Folgezeile liegt immer nach `now`. Bei `i`-Rhythmen kann sie innerhalb des Budgets erneut fällig werden, das ist korrekt.
- H2 erledigt: lazy lesen und schreiben. Ein schon gespeicherter, nicht-lazy Wert bleibt lesbar (`UserConfig::getTypedValue`). `hasKey(…, null)` prüft beide Caches, deshalb wird ein vorhandener Schlüssel bei 300 nicht abgewiesen.
- H3 erledigt: `NoteService::update` nimmt zuerst `lockRow`, dann folgen `find` und die Versionsprüfung in derselben Transaktion. Task-Pfad und Job sperren in derselben Reihenfolge (Notizzeile zuerst, eine Zeile), daher kein Deadlock zwischen ihnen. Auf PostgreSQL und MariaDB (NC setzt READ COMMITTED) sieht `find` nach erhaltener Sperre den neuesten Commit. Eine `ConflictException` rollt die Transaktion vollständig zurück; es wird kein DB-Fehler abgefangen und weitergearbeitet.
- H4 erledigt: `d`/`w`/`m`/`y` rechnen als Kalenderschritt in der Zeitzone des Empfängers. `dueAt` wird aus dem neuen Auslöser in derselben Zone berechnet, daher konsistent mit `syncReminder`.
- H5 erledigt: Plan §2 M2c und SPEC §2.2/§4.2 stimmen überein („außer Sperrliste“, lazy, 1000 Erinnerungen).
- H6 erledigt für das ETag: ETag = Version + 12-stellige Prüfsumme über die ganze Antwort, `If-Match` vergleicht nur den Teil vor `-`. Die Version (md5-Hex) enthält kein `-`. `GET /mentions` schreibt weiterhin (`handled->store`). Das bleibt ein Hinweis, kein Blocker.

## Hinweise
1. `TaskService::syncReminder` (Aufgabe mit `dueReminder` anlegen bzw. ändern) umgeht die Obergrenze von 1000. Begrenzt ist das nur über die Zahl der Aufgaben. Empfehlung: dieselbe Prüfung dort, sonst 400.
2. `countPending` vor dem Einfügen ist nicht atomar. Parallele Anfragen können die Grenze geringfügig überschreiten. Das ist unkritisch.
