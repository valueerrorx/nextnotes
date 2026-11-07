# M6a-fix – Architekt

Status: fertig

## Auftrag
Nacharbeit M6a laut docs/reviews/M6a-r1.md: Blocker restoreDeleted (noteLevel<WRITE → 403) + Test/Gegenprobe; Hinweis 1 (Q1 folder_id doku), 2 (trash_material leeren bei make_private/Verlassen), 3 (Zähler Done 8), 4 (Restgröße Plan §8). Instanz nextnotes-m6af.

## Erledigt
- Review + M6a-Progress gelesen
- Blocker: FolderService::restoreDeleted je Notiz noteLevel < WRITE → 403 ($restricted entfernt)
- Hinweis 2: NoteMapper::clearTrashMaterial; Aufrufe TeamLifecycleService::makePrivate, FolderService::move (Verlassen), WorkspaceService::moveContents (Ziel nicht Team)

- Tests MaterialAccessTest: testTrashMarking (gefälschter Snapshot neu/vorhandener Ordner → 403, n1 bleibt in M/U), testLeavingClearsMaterial (trash_material 0 nach Ordner-Verlassen und make_private), testReadPathsUnchanged (IndexViewService::build Zähler getStats executed mit/ohne Kennzeichen gleich)

- Instanz nextnotes-m6af NN_PORT_BASE=8270 up sqlite/mariadb/pgsql rc=0; make build rc=0
- sqlite --filter Material: 12 Tests OK 512 Ass., Exit 0

- Gegenprobe (sqlite): alte Prüfung in restoreDeleted → testTrashMarking rot (201 statt 403); noteLevel-Aufruf in IndexViewService::build → testReadPathsUnchanged rot; Dateien wiederhergestellt, neu deployt
- check-upstream grün rc=0, check-forbidden-sql 0 Treffer rc=0
- Docs: Plan §8 (Nacharbeit, Q1 folder_id für M6b, Restgröße Hinweis 4), SPEC §2.1 trash_material, §3 Tabelle Q1 + Material-Abschnitt

- PHPUnit --filter 'Material|Teams|FilterReadable|IsolationTest|CoreScenario|Folder|Trash': sqlite 471 Tests OK (1 skipped) Exit 0 (Filter griff faktisch voll); mariadb 233 OK 9624 Ass. Exit 0; pgsql 233 OK 9624 Ass. Exit 0
- Instanz nextnotes-m6af abgebaut (down.sh all)

## Nächster Schritt
- keiner (Bericht an Lead)

## Offene Punkte/Annahmen
