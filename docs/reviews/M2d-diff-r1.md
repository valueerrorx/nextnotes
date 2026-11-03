# M2d Diff-Review r1 (speccritic)

VERDICT: BLOCKER

1. Job nicht wiederaufnehmbar – `nextnotes/lib/BackgroundJob/UserDataDeletionJob.php`, `Service/UserDataService::purgeWorkspaces`. `QueuedJob::start` entfernt den Job vor `run()` (`.ref/nextcloud-server/lib/public/BackgroundJob/QueuedJob.php:26-33`). Abbruch (Timeout, Fatal, SQLite „database is locked“, IAppData-Fehler) nach dem ersten Workspace hinterlässt `owner_type=deleted`-Workspaces samt Notizen/Anhängen dauerhaft; nichts löscht sie je (kein anderer Job/Pfad sucht `owner_type=deleted`). Widerspricht SPEC §8 / ADR 0008 f („werden gelöscht“) und Done M2d 1 („keine Zeile owner_type=deleted mehr“) im Fehlerfall. Forderung: tokenunabhängiger Nachlauf, z. B. täglicher Sweep (bestehender `SnapshotRetentionJob` oder eigener `TimedJob`) über alle `owner_type=deleted`-Workspaces mit `purgeWorkspaces` je Kennung; SPEC §5 entsprechend; Test: Job nach erstem Workspace abbrechen (Exception) → Sweep löscht den Rest.

## Bewertung
- (a) Token statt ID-Liste: tragfähig. `#` ist in NC-UIDs unzulässig (`lib/private/User/Manager.php:752`), zudem trennt `owner_type=deleted` vom Rechteweg; `nn_ws_asg` (owner_id, linked_team_id) bleibt eindeutig (je Team höchstens ein `assignments`-WS je Benutzer, NULL-Mehrfach in allen 3 DBs erlaubt); 64-bit-Kollision folgenlos (beide Mengen werden gelöscht). Argument klein, keine UID im Job.
- (b) Rethrow: gewollt und richtig. Exception bricht `User::delete()` vor `deleteAllUserConfig` ab (`lib/private/User/User.php:330-333`), das Flag `core/deleted` bleibt; NCs `CleanupDeletedUsers` (täglich, `lib/private/User/BackgroundJobs/CleanupDeletedUsers.php`) wiederholt die Löschung inkl. Listener. `neutralize` ist idempotent (eine Transaktion, Job-Insert im selben Commit). Restrisiko: Wird die UID vor diesem Wiederholungslauf neu angelegt, setzt NC das Flag zurück und überspringt – dann erbt die neue UID. Hinweis in SPEC §8 aufnehmen (Admin sieht Fehler; M7 `delete-user-data` ist nicht der Wiederholungspfad).
- (c) Konsistent: SPEC §8 („private Workspaces inkl. Notizen … gelöscht“), Done M2d 1 (C's Notiz in A's WS bleibt) und STATUS „Vorläufig (M2d)“ stimmen überein; bis zum Joblauf kein Zugriff (AccessService::computeLevel → NONE für `deleted`, auch über ws_shares).

## Geprüft ohne Befund
- Neutralisierung: alle UID-Spalten des Schemas (workspaces.owner_id/created_by, ws_shares.share_with/created_by, notes.created_by/updated_by, attachments/snapshots.created_by, reminders.user_id, locks.holder_uid, mentions_handled.user_id) abgedeckt; erste Anweisung schreibt; M5/M6-Punkte als Kommentar.
- Job: Transaktion je Workspace, IAppData erst nach äußerstem Commit (`Transaction::run`, depth 0); Mapper chunken IN-Listen (1000); zweiter Lauf no-op.
- Migration: nur `addIndex` mit `hasIndex`-Guard, Namen ≤ 11 Zeichen, DB-neutral; Version 0.1.1 löst sie aus; SPEC §3 nachgezogen.
- 503 nur für `REASON_LOCK_WAIT_TIMEOUT`/`REASON_DEADLOCK` (OCP @since 30/21), Fehlerformat gleich, `Retry-After`.
- Quota: Vorprüfung vor der Transaktion, `lockOwner` als erste Anweisung + Nachprüfung unter Sperre (Upload, Kopie, Duplikat Notiz/Ordner).
- `run-m2.sh`: alle Umgebungen laufen, Exit 1 bei einer roten; `KEEP=1` nur für rote; INT/TERM räumt auf. `src/` unverändert.
