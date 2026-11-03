# M2d – Tester

Auftrag: UserDeletionTest (§13 M2.8), SQLite-Upload 201/413|201/503 nie 500, expected-m1.json + 5 Indizes, Abschlusslauf run-m1/run-m2, Kriterien M2.1–8 belegen, Gegenprobe ws_shares.

Status: fertig

## Erledigt
- Start, Progress-Datei angelegt
- expected-m1.json: 5 Indizes ergänzt (nn_note_cby, nn_note_uby, nn_att_cby, nn_snap_cby, nn_men_user); alle 5 in SPEC §2.1 vorhanden

- AttachmentTest Parallel-Upload: SQLite nur 201/413|201/503 (+Retry-After), nie 500; NoteVersionTest: 500 = Fehler, SQLite 200/503+Retry-After erlaubt
- neu tests/nextnotes/php/Scenario/UserDeletionTest.php (Krit. 8 + UID-Wiederverwendung; testSweepCleansAbortedDeletion nach Lead-Zusatz DeletedWorkspaceSweepJob)

- testSweepContinuesAfterFailingToken (Lead-Zusatz 2: sweep fängt Fehler je Kennung)
- sqlite gezielt `run-phpunit.sh sqlite --filter 'UserDeletionTest|testParallelUploadsRespectQuota|NoteVersionTest'` → 6 Tests OK, Exit 0 (Stand mit beiden Lead-Zusätzen)
- Gegenprobe: ws_shares-Löschung in UserDataService::neutralize entfernt → UserDeletionTest rot (ws_shares.share_with/created_by), Exit 1; zurückgesetzt, sha256 abf97a52…078c identisch; sqlite down

- SPEC-grep M2.5 → 0 Treffer (rc=1)
- gestartet: run-m1.sh (alle Envs) + run-m2.sh sqlite mariadb pgsql, Logs im Scratchpad run-m1.log/run-m2.log

- run-m1.sh (sqlite mariadb pgsql nginx) rc=0, Schema OK je DB (inkl. 5 neue Indizes)
- run-m2.sh sqlite mariadb pgsql rc=0, je 225 Tests/7267 Assertions OK; check-forbidden-sql 0; kein SQLite-Hinweis 500/503
- down.sh all rc=0, keine Container/Volumes übrig
- Alle Endläufe mit Stand inkl. beider Lead-Zusätze (DeletedWorkspaceSweepJob, sweep fängt Fehler je Kennung)

## Nächster Schritt
- keiner (Bericht an Lead)

## Offene Punkte/Annahmen
- Lead-Zusatz während Lauf: Produktionscode mit DeletedWorkspaceSweepJob geändert; alle Läufe ab jetzt mit diesem Stand
- Sweep-Test: je Workspace eine Transaktion → leerer Standard-Workspace der fehlerhaften Kennung wird vor dem Fehler gelöscht; geprüft: Workspaces mit Notizen unverändert (SPEC §5 konform)
- Gegenprobe zeigt nur Spalten-Check rot (erste Assertion); Zugriffsprüfungen danach nicht einzeln gegengeprüft
