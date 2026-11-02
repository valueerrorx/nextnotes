# M2c speccritic (Diff-Review r1)
Auftrag: Diff ggü. fa8d5c15 gegen M2-plan §1/§2 M2c/§5, SPEC, ADR 0005/0010/0014 prüfen; Ergebnis docs/reviews/M2c-diff-r1.md.
## Erledigt
- git status/diff --stat gelesen; src/ unverändert (kein Diff)
(Fortsetzung Erledigt)
- Gelesen: Plan §1/§2 M2c/§5, SPEC-Diff, alle neuen Services/Controller/Mapper/Notifier/Job, Isolation-/RouteDiff-Diff
- OCP stichprobenartig geprüft: IDateTimeZone::getTimeZone(uid) 32, ISearch::filteredSearch 35, IUserConfig::getValues 32, highlight-Rich-Objekt, IApp::markProcessed; UserConfig MAX_NON_LAZY_SIZE 128
- Befunde: B1 task_id ohne Längen-/Existenzprüfung (string(64) → 500 auf pgsql/mariadb); B2 ReminderJob advanceDue schreibt Notizinhalt ohne Schreibrecht (set task-reminder braucht nur read)
- Hinweise: Job-Batch 200 ohne Nachlauf (Verhungern), Settings non-lazy 64KiB×300, PATCH-TOCTOU vs. Task-Zeilensperre, DST-Drift, Plan §2 „Whitelist“ veraltet, ETag/reminder_at
- docs/reviews/M2c-diff-r1.md geschrieben (BLOCKER, 2 Blocker, 6 Hinweise)
## Status
fertig

# Runde 2 (Diff-Review r2)
Auftrag: Nachbesserung zu r1 prüfen (B1, B2, H1–H6); Ergebnis docs/reviews/M2c-diff-r2.md
## Erledigt
- Gelesen: ReminderService/ReminderMapper/Recurrence/TaskService(advanceDue, requireTask)/NoteService::update/NoteSerializer::etag/SettingsService (lazy), SPEC/Plan-Diff
- OCP geprüft: UserConfig::getTypedValue (lazy liest nicht-lazy), hasKey(null), getValues lädt alle
- docs/reviews/M2c-diff-r2.md geschrieben: FREIGABE; Hinweise: syncReminder umgeht 1000er-Grenze, countPending nicht atomar
## Status r2
fertig
