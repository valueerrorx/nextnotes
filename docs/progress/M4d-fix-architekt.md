# M4d-fix – Architekt

Status: fertig

## Auftrag
Review r1 M4d: Sperrschlüssel ≤64 (Blocker), B57 addStream, HTML-Fehlerseite 429/413/404 bei Navigation, Doku (SPEC §4.5, ADR 0008, M4-plan).

## Erledigt
- Progress/Review gelesen
- P1: ZipExportService::lockKey (md5, 49 Zeichen); grep: einziger ILockingProvider-Nutzer der App; testOneExportPerUser mit 64-Zeichen-UID + oc_file_locks-Prüfung (lint ok)
- P2: ExportArchive reserve()/claim(), addString/addStream mit Suffix und Rückgabe Pfad; ZipExportService::writeNotes reserviert attachments/<datei> vorab; testAttachmentFolderCollision (Ordner + structured)
- P3: ApiExceptionMiddleware Navigation (Accept text/html, kein XHR) bei downloadNote/exportNote/export* → templates/export_error.php (Guest, Status 404/413/429); l10n de/de_DE +5; Rate-Limit-429 kommt vor App-Middleware → NC core/429 (geprüft MiddlewareDispatcher); Test testExportErrorPage + 429-Seite in testOneExportPerUser; ApiClient: eigenes Accept hat Vorrang
- Instanz nextnotes-m4dfix (scratchpad/nc, Ports 8131–8134) sqlite/pgsql/mariadb up, App 0.1.2; kein memcache.locking → DBLockingProvider
- PHPUnit 1. Lauf: B57-Test rot (claim gab Vormerkung an Notiz frei) → claim(reservedOk nur addStream) gefixt; 2. Lauf ExportZipTest sqlite/pgsql/mariadb rc=0 (10 Tests, 382 Assertions, 1 Notice → createStub)
- Nachweis alter Schlüssel: pgsql DbalException varchar(64); mariadb kein Fehler (INSERT IGNORE kürzt still)
- P4: SPEC §4.5 ZIP-Absatz (Schlüssel, Fehlerseite, Betrieb) + B57-Zeile; ADR 0008 Nachtrag M4d; M4-plan „Alle exportieren“ gestrichen, Lead-Annahme Cleanup, Auflage-Hinweis ILockingProvider; nextnotes/README.md Abschnitt Betrieb
- build rc=0 (134 Dateien); check-upstream rc=0 (nach Kopfkommentar „eigene Vorlage“ in export_error.php); check-forbidden-sql 0
- PHPUnit ExportZip|Export|ControllerAttribute|PageIsolation|TemplateOutput sqlite/pgsql/mariadb je rc=0 (40 Tests, 1741 Assertions)
- E2E nginx hell: export-zip 36 + stripped 126 rc=0; export-zip allein erneut 36 rc=0
- Instanz nextnotes-m4dfix abgebaut (down.sh all rc=0, keine Volumes übrig); Demo/nc35-gftest unberührt

## Nächster Schritt
- keiner (Bericht an Lead) (PHPUnit 3 DBs, E2E nginx hell, build, checks)

## Offene Punkte / Annahmen
- Rate-Limit-429 bei Navigation: NC-Kernseite core/429 („Too many requests“), kein eigener Text (App-Middleware läuft nach Security-Middleware nicht)
- Folder-Export benennt Notizen <Titel>_<id> → dort keine Kollision; B57-Fall nur strukturiert real
- tests/Support/ApiClient: eigenes Accept hat Vorrang (nötig für HTML-Test)
