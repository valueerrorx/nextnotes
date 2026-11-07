# M6a – Architekt

Status: in Arbeit

## Auftrag
Material-Ordner Backend (Plan `docs/slices/M6-plan.md` r2: M6a, §2.1, §2.4-Hinweis, Q1, Done M6a 1–9). Review r2 Hinweis 2 (Q1 `linked`: Ziel muss selbst Material sein, sonst 403) und 6 (Kommentar Sperr-Reihenfolge mehrere Besitzer). SPEC/ADR-Nachträge M6a. Tests inkl. MaterialIsolationTest auf 3 DBs, Instanz `nextnotes-m6a`.

## Erledigt
- Plan + Review gelesen

## Nächster Schritt
- Code lesen (AccessService, FolderService, NoteService, …)

## Offene Punkte/Annahmen
- Instanz `NN_PROJECT=nextnotes-m6a NN_PORT_BASE=8260` up.sh all rc=0 (Ports 8261–8264)
- Migration Version000500Date20261109000000 (folders.material, notes.trash_material), info.xml 0.3.0, Entities, NoteMapper LIST_COLUMNS, expected-m1.json
- Mapper: FolderMapper materialTree/workspacesWithMaterial/clearMaterial/clearFavorites(exclude); NoteMapper clearFavorites(exclude)/markTrashMaterial
- AccessService: materialFolderIds(Workspace), resetMaterialCache, isMaterialNote, isMaterialReadOnly, materialRestricted, filterWritable, requireNoMaterial; noteLevel/requireFolder mit Material (Plan-Name writeExcludedFolderIds → filterWritable + materialRestricted/materialFolderIds, in SPEC nennen)
- FolderService: resolvePath(uid,…) mit Schreibprüfung, create (Pfad/Elternpfad/Tagebuch), delete (trash_material, Zweig), restoreDeleted (material nur Lehrkraft, 403 trash_material), move (Zweig, Sperre, clearMaterial beim Verlassen), reorder (placeAround, feste Material-Geschwister), changeTags (filterWritable), archive (Zweig, merged), setMaterial + Route PUT folders/{id}/material, Ordner-JSON material/material_inherited
- NoteService (resolvePath uid, verknüpfte Notizen filterWritable, moveToFolder per Name requireFolder, clearFavorites exclude, reorder speed_dial placeAround, restore trash_material=0, archive Material-Ziel), TagService/TrashService filterWritable, DuplicateService Q1 copyMaterialToPrivate (+ linked Ziel Hinweis 2, Ordner-Duplikat requireNoMaterial, material kopiert), DiaryService (resolvePath uid, legacy markDiary write), TeamLifecycleService::makePrivate clearMaterial, WorkspaceService::moveContents clearMaterial, QuotaService Kommentar Hinweis 6
- noteLevel: Material nur bei Schreibprüfung (requireNote read/canRead ohne Zusatzabfrage)
- make build rc=0 (161), check-upstream grün, check-forbidden-sql 0
- Migration: sqlite frisch 0.3.0; mariadb+pgsql 0.2.1 (git HEAD) → 0.3.0 Upgrade; check-schema OK alle drei (Skripte scratchpad deploy.sh/old-install.sh)
- Nächster Schritt: Tests tests/nextnotes/php/Material/{MaterialAccessTest,MaterialIsolationTest}.php
- Tests: Material/MaterialTestCase.php, MaterialAccessTest.php (8 Tests: Done 1–4, 5b, 6, 7, 8) sqlite OK 281 Ass.
- Nächster Schritt: MaterialIsolationTest (Done 5, 5a, 5c, 9)
- MaterialIsolationTest (3 Tests: Done 5, 5a, 5c inkl. Hinweis 2); Fix: Q1 nur ohne ausdrückliches folder_id (Duplikat in Material-Ordner → 403); Material sqlite 11 OK 446 Ass.
- Nächster Schritt: Gegenprobe (Mutationen), dann Regression sqlite, mariadb/pgsql, E2E
- Gegenprobe (Opcache: deploy.sh mit apachectl graceful): M1 noteLevel-Material aus → testStudentReadsButCannotWrite rot (S PATCH 200); M2 Cache-Reset nach move weg → testCacheAfterFolderMoveInProcess rot; M3 TagService ohne filterWritable → testCollectiveWriters rot. Dateien wiederhergestellt
- Docs: SPEC §2.1 (material, trash_material), §3 (Tabelle + Abschnitt Material-Ordner), §4.2 (PUT folders/{id}/material), §13 M6 Krit. 11/12; ADR 0003 Nachtrag M6a; Plan §8 Umsetzung M6a (grep-Beleg, Abweichungen)
- laufen: PHPUnit sqlite voll (bkhfl2x4r), mariadb/pgsql gefiltert (b1g8ksa6s)
- Fund: CleanupTestCase baut TeamLifecycleService positionsweise → FolderMapper ergänzt (Test-Anpassung)
- PHPUnit mariadb + pgsql (Material|FilterReadable|TeamLeaveTest|TeamAccessTest): OK 27 Tests/990 Assertions, Exit 0 (nach deploy 0.3.0)
- PHPUnit sqlite voll: 470 Tests, 17 Errors (CleanupTestCase, schon gefixt) + 3 Failures (IsolationTest routeTable, RouteDiffTest PORT_ONLY, CoreScenario allowed-diff) → Testerwartungen angepasst, Nachlauf
- E2E nginx light smoke-m5 + teams-m5d: grün (rc=0)
- Testanpassungen: IsolationTest (routeTable-Eintrag PUT /folders/{}/material, control 400; Anzahl 122), RouteDiffTest + CoreScenarioTest PORT_ONLY, fixtures/allowed-diff.json (5 extra-Schlüssel material/material_inherited)
- PHPUnit sqlite Nachlauf (IsolationTest|CoreScenarioTest|CleanupJobTest|OrphanCheck|RouteDiff|TeamLifecycle): OK, Exit 0; Rest des Vollaufs (450) war grün
- Instanz nextnotes-m6a abgebaut (down.sh all rc=0, keine Container)
- Diff gesamt: 31 Dateien, +1333/−54 (inkl. Tests, Docs)

Status: fertig
