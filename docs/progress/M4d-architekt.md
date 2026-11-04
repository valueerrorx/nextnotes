# M4d – Architekt (Umsetzung)

Status: fertig

## Auftrag
ZIP-Exporte (Ordner, alle Notizen, strukturiert, Anhänge; B45/B47), Ordner-Menü „Herunterladen“, „Alle exportieren“, ExportTempCleanupJob, Sperre 1 Export/Benutzer, Content-Length; Nacharbeit AttachmentService flush in offener Transaktion.

## Erledigt
- Plan, STATUS, M4c-Progress gelesen; Bestand erkundet (ExportService/Formatter, CompatController, Upstream api_export_{folder,entries,structured,attachments}, import_helpers)
- Nacharbeit r2: AttachmentService createFromString/store/move registrieren tx->onRollback(deleteFileNow) direkt nach newRow; discardNew + catch/flush entfernt; ExcalidrawService catch entfernt; EmbeddedImageService ohne eigenes onRollback/Transaction (php -l ok)

- ExportArchive (neu, ZIP-Bau/Staging/unique B57), ExportService::zip darauf umgestellt (+attachmentEntries, zipPaths, frontMatter, addAttachment public)
- ZipExportService (neu): folder/entries/structured/attachments, collect (linked über filterReadable, Grenze vorab via NoteMapper::contentBytes), Sperre ILockingProvider → 429 (TooManyRequestsException neu)
- NoteMapper findListByIds/contentBytes/contentByIds; AccessService::ownWorkspaceIds; ExportFormatter zipName/asciiName/downloadAttributes
- CompatController exportFolder/Entries/Structured/Attachments (10/min), disposition() RFC 5987; routes +3
- ExportTempCleanupJob (neu) + info.xml, Version 0.1.2 (Job-Registrierung bei Upgrade)
- check-upstream (g) + ZipExportService; upstream-ported.txt +4

- UI: folders_display.php Menüpunkt download-folder; tests/e2e/stripped.mjs erlaubt download-folder
- Eigene Instanz: Kopie dev/nextcloud → scratchpad/nc, Projekt nextnotes-m4d, Ports 8121–8124; sqlite up, App 0.1.2 aktiv; curl-Smoke aller 4 ZIP-Exporte 200
- Tests neu: fixtures/export/{dataset,summary,gen-upstream,check-import}.php, upstream.json (Upstream-Code im sqlite-Container erzeugt; zeigt B45 bei entries+attachments), php/Scenario/ExportZipTest.php (8 Tests), e2e/export-zip.mjs; run-phpunit.sh stagt src/import_helpers.php

- PHPUnit sqlite --filter ExportZipTest rc=0 (8 Tests, 294 Assertions); Befund: PHPUnit-Prozess nutzt prozesslokale Sperre → Test sperrt im Nebenprozess
- SPEC: §4.5 ZIP-Exporte, §5 ExportTempCleanupJob, §11.2 B45/B57, B47-Zusatz, §14 OCP; B57-Test in testScope ergänzt
- PHPUnit sqlite breit (Export|Attachment|Excalidraw|Conversion|PageIsolation|TemplateOutput|JobsTest|RouteDiff|PageRoutes|ControllerAttribute|DenyByDefault|Csrf|IndirectRead|IsolationTest|CoreScenario|UserDeletion|WorkspaceDelete): 199 Tests, 3 Fehler (Allowlist NoCSRF, PageIsolation erwartete 501) → Tests angepasst; Wiederholung ControllerAttribute|PageIsolation|ExportZip rc=0 (22 Tests, 1137 Assertions)
- PHPUnit pgsql --filter ExportZipTest|ExportTest rc=0 (17 Tests, 628 Assertions); pgsql abgebaut
- Lastprobe pgsql: 200 MiB-Anhang „alle Anhänge“ 6,3 s, PHP-Heap +8 MB, sha256 gleich, Temp leer; HTTP 200 6,9 s; Abbruch nach 1 MiB → Temp leer
- E2E nginx export-zip hell 32 / dunkel 11 Prüfungen rc=0 (Ordner-Menü braucht Hover)
- check-upstream rc=0 (f: 14), check-forbidden-sql 0

- PHPUnit Wiederholung nach Feinschliff (ExportArchive Abbruchpfad, RFC-5987 /u): ExportZip|Export|ExcalidrawSave|AttachmentTest sqlite rc=0 (30 Tests, 853 Assertions)
- E2E nginx hell: menu-501 92 rc=0; stripped 126 (nach Ausnahme für Text in [data-action=download-folder]) + export-zip 32 rc=0
- check-upstream rc=0, check-forbidden-sql 0; Instanz nextnotes-m4d abgebaut (down.sh all rc=0), Demo/nc35-gftest unberührt

## Nächster Schritt
- keiner (Bericht an Lead)

## Offene Punkte / Annahmen
- Sperre: ILockingProvider statt IMemcache::add (Locking-Memcache ohne Konfiguration = NullCache, add() immer true); DB-Rückfall, TTL max(3600, max_execution_time)
- [[…]] in ZIP-Exporten unaufgelöst wie Upstream (kein Titel-Leck, keine Abfragen)
- „Alle exportieren“: Upstream hat keinen UI-Eintrag (startDownload nur window-Funktion) → nichts einzublenden
- B57: gleiche ZIP-Pfade überschreiben sich (Upstream) → Suffix _<id>
