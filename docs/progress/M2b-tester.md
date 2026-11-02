# M2b – Tester

Auftrag: M2b-Tests + Abnahme (Anhänge, Snapshots, Suche, Sperre ADR 0005, indirekte Lesepfade, Unified Search, Jobs); run-m2.sh auf sqlite/mariadb/pgsql frisch; Gegenprobe.

Status: fertig

## Erledigt
- Progress-Datei angelegt
- Plan M2b, SPEC §3/§5/§7/§11.2/§13, ADR 0005 gelesen; Architekten-Diff Isolation/RouteDiff/CoreScenario/ApiClient geprüft
- ApiTestCase: Helfer ownWsId/newWorkspace/newNote ergänzt
- tests/nextnotes/php/Lock/LockServiceTest.php (5 Tests) – sqlite: `dev/nextcloud/run-phpunit.sh sqlite --filter LockServiceTest` Exit 0
- Dev-Instanz sqlite läuft (für Iteration), am Ende down.sh all
- tests/nextnotes/php/Search/UnifiedSearchTest.php (2 Tests) sqlite Exit 0; ApiClient::absolute() für OCS
- tests/nextnotes/php/Access/IndirectReadTest.php (resolve/backlinks/graph/changes/search/Anhang/Snapshot/Sperre, ohne Freigabe + nach Entzug) sqlite Exit 0
- tests/nextnotes/php/Scenario/AttachmentTest.php (Bytes/Header 8 Typen, B22, Fremdzugriff 404, B21 move/duplicate 403, Quota 413) sqlite Exit 0; Konfig per occ + 4 s (AppConfig LOCAL_CACHE_TTL 3 s)
- tests/nextnotes/php/Jobs/JobsTest.php (Registrierung, LockCleanupJob, SnapshotRetentionJob per start() mit Zeitmock, Purge Einzel/Leeren, Rollback) sqlite Exit 0
- ADR-Probe (temporär, gelöscht): A ohne Sitzung PATCH trotz Sperre A/s1 → 200; PATCH mit Sitzung ohne Sperre erwirbt Sperre implizit
- Volle Suite sqlite (Dev): 149 Tests OK, Exit 0; check-forbidden-sql 0 Treffer; validate-info OK
- Gegenprobe (AttachmentService::download ohne requireNote; LockMapper::takeOver ohne holder_uid): rot – IsolationTest GET Anhang, LockServiceTest x2, AttachmentTest, IndirectReadTest (Exit 1). Zurückgesetzt: git diff nextnotes/ + untracked sha256 identisch zum Ausgangsstand
- run-m2.sh (Stand vor Lead-Nachbesserung) abgebrochen nach sqlite-Teil (Exit 143, ungültig)
- Lead-Zusatz: Produktionscode nachgebessert → Tests ergänzt: AttachmentTest (PNG/PDF/SVG/HTML/txt/download Header + frame-ancestors, Quota Besitzer, parallele Uploads, Verknüpfung), LockServiceTest B13-HTTP, JobsTest keep_count; ApiClient::parallelUpload
- Neue Tests sqlite (Lead-Stand): AttachmentTest/LockServiceTest/JobsTest grün. Befunde: PDF-CSP = NC-Standard mit frame-ancestors 'none' (Lead wollte überall 'self'; SPEC B22 sagt NC-Standard) → PDF im Test ausgenommen; SQLite: paralleler Upload → 500 'database is locked' (LockWaitTimeout bei UPDATE nextnotes_workspaces), Quote nie überschritten; Verknüpfung: nur eine je Notiz
- ENDLAUF (Lead-Stand, Code-Hash vor/nach gleich): `dev/nextcloud/run-m2.sh sqlite mariadb pgsql` Exit 0; je DB 153 Tests OK (sqlite/mariadb/pgsql PHPUnit Exit 0); 3 SQLite-Hinweise parallele Uploads 500
- `dev/nextcloud/down.sh all` Exit 0, keine Container/Volumes übrig

## Nächster Schritt
- keiner (Bericht an Lead)

## Offene Punkte
- Architekten-Diff: Lücken (Entzug, Unified Search, Jobs/Purge, B bei Sperre, Quota) durch neue Tests geschlossen
- ADR 0005/B13: Speichern ohne Sitzung durch dasselbe Konto passiert Sperre; mit Sitzung ohne Sperre implizit erworben – inzwischen in SPEC B13 festgeschrieben, Test deckt es
- PDF-Anhang: NC-Standard-CSP frame-ancestors 'none' (nicht 'self'), Lead-Vorgabe vs. SPEC B22 klären
- SQLite: paralleler Upload 500 'database is locked' statt 413
- Gegenprobe lief auf Stand vor Lead-Nachbesserung
