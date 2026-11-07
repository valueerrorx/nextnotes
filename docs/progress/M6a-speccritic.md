# M6a speccritic
Auftrag: Diff-Review r1 M6a (Material-Ordner Backend) gegen 0604336e; Ergebnis docs/reviews/M6a-r1.md
Erledigt:
- Plan §2.1/§2.4/§3 M6a/§6/§8, Review M6-plan-r2 gelesen
- Diff AccessService, Db, Migration, FolderService, NoteService, DuplicateService, Tag/Trash/Workspace/TeamLifecycle/Diary/Quota, Tests/Fixtures/SPEC/ADR gelesen
- grep Schreibwege (requireNote read, requireFolder read, writableWorkspaceIds, findListByIds, Controller-DB-Zugriffe, Compat, Import, Excalidraw, Task, Lock, Reminder)
- Befund B1: FolderService::restoreDeleted 611-623 ohne Kettenpruefung
- Review geschrieben: docs/reviews/M6a-r1.md (BLOCKER, 1 Blocker, 4 Hinweise)
Naechster Schritt: keiner (r2 nach Fix)
Status: fertig
