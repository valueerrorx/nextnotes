VERDICT: BLOCKER

Diff-Review M2c, Runde 1 (Arbeitsbaum gegen fa8d5c15). `src/` ist unverändert. Kein Roh-SQL, keine DB-Zeitfunktionen.

## Blocker
1. `lib/Service/ReminderService.php` `set()` (Task-Zweig): `task_id` wird weder auf Länge noch auf Existenz geprüft. Die Spalte ist `string(64)` (Migration `nextnotes_reminders.task_id`). Ab 65 Zeichen gibt es auf PostgreSQL/MariaDB eine DB-Ausnahme (500), SQLite speichert den Wert. Das Verhalten hängt also von der DB ab. Außerdem lassen sich beliebig viele Zeilen mit erfundenen IDs anlegen. Forderung: Notiz muss `type=tasklist` sein, `task_id` muss als Aufgabe existieren (`TaskService::taskIndex`), sonst 400/404. Länge ≤ 64, sonst 400. Test auf allen drei DBs.
2. `ReminderService::scheduleNext` → `TaskService::advanceDue`: Der Job schreibt Notizinhalt (`dueAt`) im Namen des Erinnerungsbesitzers. Geprüft wird vorher nur `canRead`, und `POST /notes/{id}/task-reminder` verlangt nur `read`. Damit gibt es einen Schreibpfad ohne Schreibrecht. In M2 ist er nicht ausnutzbar (Freigaben sind immer `write`). Ab M5/M6 (Nur-Lesen, gesperrte Abgaben, §9 `studentCanEdit` „Tasks“) kann so ein Leser eine fremde oder gesperrte Notiz ändern. Forderung: `advanceDue` nur, wenn `noteLevel(uid) ≥ write`. Sonst wird die Erinnerung ohne Verschieben von `dueAt` neu angelegt oder die Wiederholung beendet. Testfall dazu.

## Bewertung der Abweichungen
1. `/shared*` liefert leere Listen im Upstream-Format: **akzeptabel**. Kein Datenpfad, und SPEC §4.2 sowie Done 1 sind angepasst.
2. Settings mit Muster und Sperrliste statt Allowlist: **akzeptabel**. Die Werte wirken nur beim Aufrufer, und die Schlüssel, die der Server liest (`snapshots_keep_count`, `note_list_sort`, `notes_without_folders_after_folders`), werden normalisiert bzw. begrenzt. Quota und Grenzen kommen aus `IAppConfig`. XSS bleibt auf den eigenen Account beschränkt (Self-XSS). Einschränkung siehe Hinweis 2.
3. Erinnerungen gehören dem Empfänger, eine neue ersetzt die alte, Wiederholung im Job: **akzeptabel**. `claim` verhindert doppelten Versand, `replace` löscht die beanspruchte Zeile, und ein paralleler Lauf scheitert an `claim`. Einschränkung: Blocker 2.
4. Keine E-Mail, `is_admin=false`, `@uid`, geteiltes Erinnerungsflag mit Benachrichtigung nur für den Setzenden, whats-new nie: **akzeptabel**. Das sind Produktfragen, sie sind für Thomas in STATUS.md vermerkt. `lookup` liefert nur exakte UID-Treffer aus `filteredSearch`.
5. Aufgaben-Schreibzugriffe ohne Bearbeitungssperre, über Zeilensperre serialisiert: **akzeptabel** (ADR 0005: nur Speichern prüft die Sperre). Siehe aber Hinweis 3.
6. B23–B25: **akzeptabel**. Belegt und nachvollziehbar.

## Hinweise
1. `ReminderService::sendDue` arbeitet nur 200 Zeilen pro 5 Minuten ab, älteste zuerst. Viele (auch absichtlich rückdatierte) Erinnerungen verzögern dadurch die aller anderen Benutzer. Empfehlung: in Stapeln bis zur Leerung bzw. bis zu einem Zeitbudget arbeiten.
2. `SettingsService::write`: Bis zu 300 × 64 KiB werden nicht-lazy in `IUserConfig` gespeichert. NC lädt nicht-lazy Werte bei jedem Konfigurationszugriff auf den Benutzer, auch aus fremden Requests (`UserConfig::MAX_NON_LAZY_SIZE`=128). Empfehlung: große Werte lazy speichern oder ein Gesamtbudget je Benutzer (z. B. 1 MiB).
3. `NoteService::update` prüft die Version vor `tx->run` ohne `lockRow`. Speichern im Editor kann deshalb eine gleichzeitige Aufgabenänderung überschreiben (bestand schon seit M2b). Empfehlung: `lockRow` und Versionsprüfung in derselben Transaktion.
4. `Recurrence::nextAfter` rechnet `d`/`w` in UTC-Sekunden. Nach einem Sommerzeitwechsel verschiebt sich die lokale Uhrzeit um 1 h, und Erinnerung und `dueAt` (Wanduhr) laufen auseinander. Empfehlung: in der Zeitzone des Benutzers rechnen.
5. Plan §2 M2c nennt bei `SettingsService` noch „Whitelist“. SPEC §2.2 sagt „ohne Sperrliste“, gemeint ist „außer Sperrliste“. Beides angleichen.
6. `GET /notes/{id}` mit 304: Die Version berücksichtigt `reminder_at` nicht, nach dem Setzen der Erinnerung kann der Wert veraltet sein. `GET /mentions` schreibt (`handled->store`), ein GET mit Seiteneffekt.
