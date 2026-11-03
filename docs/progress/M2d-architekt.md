# M2d – Architekt (Umsetzung)

Auftrag: Kontolöschung (synchroner UserDeletedListener + UserDataDeletionJob) nach Plan §2 M2d; Nacharbeit SQLite-Upload 500 → lockOwner zuerst, Sperr-Timeout → 503 Retry-After; run-m2.sh alle DBs + Gesamt-Exit; Abschlusslauf 3 DBs.

Status: fertig (inkl. Nachbesserung r1)

## Erledigt
- Plan/STATUS gelesen

- Code gesichtet (AttachmentService/DuplicateService Quota, Middleware, Transaction, Mapper, Migration, run-m2.sh, NC User::delete, JobList-Argumentgrenze 4000)
- Entwurf: Listener setzt owner_type=deleted + owner_id=Token '#<hex>' (keine UID, '#' in NC-UIDs unzulässig, Unique nn_ws_asg bleibt frei); Job-Argument {token} statt ID-Liste (JobList max 4000 Zeichen); neue Migration Version000200 mit Indizes created_by/updated_by (notes, snapshots, attachments) + mentions user_id

- Quota: `AttachmentService::quotaFor` (vor Tx, Vorprüfung) + `enforceQuota` (erste Anweisung: lockOwner, Summe neu); Aufrufer store/move/DuplicateService angepasst; `assertQuota` entfernt
- Middleware: `OCP\DB\Exception` mit REASON_LOCK_WAIT_TIMEOUT/DEADLOCK → 503 + Retry-After 2
- Neu: Service/UserDataService.php, BackgroundJob/UserDataDeletionJob.php, Listener/UserDeletedListener.php, Migration/Version000200Date20261103000000.php; Application registriert Listener; WorkspaceService::reset public; info.xml 0.1.1; php -l ok

- SPEC: §2.1 owner_id-Löschkennung + 5 Indizes, §4.1 503/Retry-After, §5 Job-Argument {token}, §8 Listener
- run-m2.sh: alle Envs, Gesamt-Exit, KEEP=1 lässt rote stehen, Trap INT/TERM; README; check-forbidden-sql 0 Treffer

- Smoke sqlite (scratchpad smoke.php, nicht im Repo): vor Löschung alle 11 UID-Spalten belegt; nach Listener 0 Treffer, ws owner_type=deleted/owner_id=#token, Job in oc_jobs {token}; neuer C: nur neuer Workspace, Level auf A-ws 0, keine Notizen; `occ background-job:execute` → deleted-ws weg, Anhang-Ordner leer, Snapshots/Anhänge 0, C-Notiz in A bleibt (created_by NULL), neuer C behält Workspace; 2. Lauf ok

- `run-m2.sh sqlite mariadb pgsql` rc=0, je 222 Tests/7075 Assertions, kein SQLite-Hinweis 500/locked; `down.sh all` rc=0

## Nächster Schritt
- tester: UserDeletionTest (Krit. 8, UID-Wiederverwendung), Upload-Erwartung SQLite 201/413|201/503, expected-m1.json um 5 Indizes (run-m1 sonst rot)

## Offene Punkte/Annahmen
- Abweichung Plan: Job-Argument {token} statt Workspace-IDs (IJobList max 4000 Zeichen); owner_id = Token statt UID
- Neue Migration 000200 (Indizes) → check-schema/expected-m1.json muss tester anpassen; run-m1 nicht gelaufen
- Listener wirft bei Fehler weiter (nach Log); Reparatur über occ nextnotes:delete-user-data (M7)
- 503 auch für REASON_DEADLOCK (vorübergehend wie Timeout)

## Nachbesserung r1 (docs/reviews/M2d-diff-r1.md, keine Container, tests/ tabu)
- Plan: eigener TimedJob DeletedWorkspaceSweepJob (täglich) → UserDataService::sweep (Zeitbudget, je Kennung purgeWorkspaces mit Deadline); info.xml; SPEC §5 + §8 Hinweis CleanupDeletedUsers
- Erledigt: UserDataService::purgeWorkspaces(token, ?deadline) + sweep(budget) (max 1000 Kennungen, Budget-Abbruch zwischen Workspaces); neu BackgroundJob/DeletedWorkspaceSweepJob.php (86400 s, 300 s Budget), info.xml registriert; SPEC §5 Zeile + §8 Fehlerfall/Restrisiko; php -l ok, check-forbidden-sql 0, validate-info ok; kein Docker-Lauf (tester parallel)
- r2-Hinweis: sweep fängt Throwable je Kennung, loggt (LoggerInterface), macht weiter; php -l ok, check-forbidden-sql 0
