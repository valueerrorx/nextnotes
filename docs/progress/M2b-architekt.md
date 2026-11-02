# M2b – Architekt (Umsetzung)

Status: fertig (Nachbesserung r1 fertig)

## Auftrag
M2b nach `docs/slices/M2-plan.md` umsetzen (Anhänge, Snapshots, Suche, Sperre, Lesepfade, Duplizieren, Unified Search, Jobs); Parameterkarte/Fixtures ergänzen; Done-Prüfungen mind. SQLite.

## Erledigt
- Plan, SPEC §2/§3, ADR 0005, M2a-Code, Testsuite, Upstream (Snapshots/Attachments/Backlinks/Graph/Notes lock/search/resolve/changes/duplicate, Folders duplicate, db_master lock) gelesen; OCP geprüft (IAppData/ISimpleFolder::getOrCreateFolder 35, FileDisplayResponse, UserRateLimit(limit,period), IProvider/SearchResult::paginated, TimedJob, IUserManager::getDisplayName 25, IMimeTypeDetector::detectContent 18, IQueryBuilder selectDistinct/leftJoin, IFunctionBuilder sum/lower)
- Upstream-Image `nextnotes-upstream-poznote:6.136.2` lokal vorhanden → kein Netz nötig

## Entwurf (Entscheidungen)
- Neue Dateien: Db Attachment/Snapshot/Lock(+Mapper); Service AttachmentService, SnapshotService, LockService, SearchService, LinkService, ChangesService, DuplicateService (eigener Service gegen DI-Zyklus Note↔Folder); Controller AttachmentsApi, SnapshotsApi, GraphApi (graph+backlinks); Search/NotesProvider; BackgroundJob LockCleanupJob, SnapshotRetentionJob
- Sperre: acquire=heartbeat (bedingtes UPDATE sonst INSERT, Unique→Re-Read, sonst 409), release nur uid+session; Speichern wie Upstream validateCurrentNoteEditLock (siehe unten, ersetzt ersten Entwurf)
- Snapshot-Schlüssel `<lokales Datum>--<id>`; Tags in Snapshots = null (keine Spalte, keine Migration); „snapshot_only“-Anhänge entfallen
- Anhang-IDs als String im JSON; Fehler-JSON zusätzlich `message` (wie Upstream AttachmentsController)
- Quota: Summe Anhänge in Workspaces des Workspace-Besitzers

- Code geschrieben (php -l ok, validate-info ok, check-forbidden-sql 0): Db Attachment/Snapshot/Lock(+Mapper), NoteMapper-Filter types/content_any; Services Lock, Snapshot, Attachment, Duplicate, Search, Link, Changes; NoteService (update/beacon: Sperre 423 + Auto-Snapshot; setTags Auto-Snapshot; restoreSnapshot; purge um Anhänge/Snapshots/Sperren); Controller AttachmentsApi, SnapshotsApi, GraphApi, NotesApi (+10 Methoden), FoldersApi duplicate; Middleware message auch für Attachments; Search/NotesProvider; Jobs; Application registerSearchProvider; info.xml background-jobs; routes.php 76 API-Routen

- Smoke sqlite (curl, scratchpad/api.sh, Benutzer alice/bob): Suche Akzent/Groß ok, B sieht nichts; Upload/Download Bytes gleich, GIF inline, SVG attachment+CSP sandbox, .exe 400; Sperre 409 (andere Sitzung), 423 Speichern, Heartbeat/Release nur haltende Sitzung, B 404; Snapshots anlegen/listen/zeigen/wiederherstellen/löschen, Schlüssel ungültig 400; Duplizieren Notiz (Anhänge kopiert, URLs umgeschrieben) und Ordner (Links umgebogen); Backlinks/Graph/Changes; Anhang move (Kopie wenn referenziert)/delete (Verweise entfernt); permanent delete entfernt appdata attachments/<id>; OCS Unified Search B leer, A paginiert; Jobs per occ background-job:execute ok
- Fix: MIME-Fallback nach Dateiendung (SVG wurde als text/plain erkannt)
- Hinweis: opcache revalidate 60 s nach install-app im laufenden Container

- Tests angepasst: RouteDiff (PENDING nur M2c, DONE, 76), ControllerAttribute (Allowlist show, ≥76), ApiTestCase dump/purge (+attachments/snapshots/locks), ApiClient::upload, IsolationTest (23 Routen, Seed att/snap/title, upload-Fälle, scoped query)
- run-phpunit.sh sqlite: 133 Tests, nur Fixture-Abdeckung rot (erwartet) → Isolation grün
- capture.php um M2b-Schritte erweitert; Upstream per `docker compose up --no-build` (kein Netz), 77 Fixtures, M2a-Fixtures unverändert; 23 neue nach tests/nextnotes/fixtures/upstream
- CoreScenarioTest: m2bSteps (gleiche Schritte + Asserts Bytes/Header/SVG/409/423/Restore/Akzentsuche)

- Szenario sqlite grün (3 Tests) nach Fix: POST /notes/{id}/duplicate → 200 wie Upstream (Plan nannte 201 nicht; Fixture zeigt 200); allowed-diff unverändert (keine Schlüsselabweichung)
- Anhang-Anzeige an Upstream angeglichen: inline PDF/Bild/Audio/Video/Textformate, SVG immer Download, CSP sandbox außer PDF (Browser-PDF-Viewer)
- LockService::assertCanSave an Upstream validateCurrentNoteEditLock angeglichen: ohne Sitzung blockiert nur Sperre eines anderen Kontos; mit Sitzung ohne Sperre implizit erwerben; fremde Sitzung → 423
- SPEC §2.2 Vereinfachungen M2b, §11.2 B21 (Kopieren/Verschieben aus geteilten WS braucht manage), B22 (SVG nie inline)
- Upstream gestoppt (dev/upstream/down.sh)
- run-m2.sh sqlite mariadb pgsql gestartet (Log scratchpad/run-m2.log) – lief VOR den letzten zwei Anpassungen (Anhang-Anzeige, assertCanSave) → danach erneut nötig

- SPEC §14 um IMimeTypeDetector, getOrCreateFolder (35), getUploadedFile, Search-Klassen ergänzt
- Erster run-m2-Lauf abgebrochen (veralteter Stand), down.sh all; neu gestartet mit aktuellem Code (Log scratchpad/run-m2.log)

- `dev/nextcloud/run-m2.sh sqlite mariadb pgsql` Exit 0: je 133 Tests / 3369 Assertions grün, validate-info ok, check-forbidden-sql 0, mysql.utf8mb4 true
- `dev/nextcloud/down.sh all`, `dev/upstream/down.sh` ausgeführt; keine Container mehr

## Nächster Schritt
- keiner (Bericht an Lead)

## Nachbesserung r1 (docs/reviews/M2b-diff-r1.md; keine Container, nur php -l)
- Plan §2 M2b + SPEC B22: Auslieferungsregel (Inline-Liste, SVG/HTML attachment, nosniff, CSP sandbox außer PDF + Grund); Done M2b.2 je Typ PDF/PNG/SVG/HTML/.txt
- Plan §2 M2b + SPEC B13: Speichern ohne Session / mit Session ohne Sperre
- CSP + `frame-ancestors 'self'`
- Quote: `WorkspaceMapper::lockOwner` (leeres UPDATE, DB-neutral; forUpdate() wirft auf SQLite laut Doctrine DefaultSelectSQLBuilder) + Prüfung in der Transaktion (store, move, Duplizieren); SPEC §2.2 Entscheidung Thomas festgehalten
- store: fopen geprüft und per finally geschlossen
- Verknüpfung: index und download beide Ziel (Frontend öffnet Ziel-ID; Upstream-Download löst auf); Upload auf Verknüpfung → 400
- Snapshot-Aufbewahrung (Anlegen + Job) nach `snapshots_keep_count` des Workspace-Besitzers; SnapshotMapper::autoNoteOwnersBefore ersetzt noteIdsWithAutoBefore
- check-forbidden-sql 0, php -l alle ok

## Offene Punkte/Annahmen
- Tests nur so weit ergänzt, dass Suite grün bleibt; vollständige M2b-Tests (LockServiceTest, Jobs per start(), Purge-AppData, Unified-Search-Cursor) schreibt der tester
- PDF ohne sandbox-CSP (nur NC-Standard-CSP)
- Quota je Workspace-Besitzer (Annahme)
