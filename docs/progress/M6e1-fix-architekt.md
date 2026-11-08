# M6e1-fix – Architekt
Status: fertig
Auftrag: Review M6e1-r1 umsetzen: Blocker reorder (Rolle/Ordnerwechsel), H1 userExists, H2 resetMissed, H4 Rate-Limit distribute, H5 Plan §11; close→missed prüfen.
## Erledigt
- Blocker: NoteService::reorder (Seitenleiste: role≠note+Ordnerwechsel → 403, Zielordner requireFolder write; beide tx mit lockForWrite; ws null → 400); updateLocked: role≠note Ordner-/WS-Wechsel → 403 (folder_id 0/leerer Pfad)
- Grep setFolderId/setWorkspaceId: move/archive/restore/removeFromFolder über manage; FolderService-Wege über requireFolder (assignments-WS READ, assignment_id-Ordner 403) → ok
- H1 DistributionService userExists→EXISTS; H2 resetMissed student_uid NOT NULL; H4 UserRateLimit 10/60 distribute; H5 Plan §11
- close (Thomas): SubmissionService teacherAction/locked erlauben feedback/return nach close, reopen 409; SPEC §9.2, Plan §11
- Tests (Lead-Auftrag): SubmissionStateTest close-Block, SubmissionLockTest::testSubmissionNeverChangesFolder
- PATCH-Guard wieder entfernt: update() lehnt Ortsfelder für role≠note schon ab (400, M6d)
- build=0, check-upstream=0, check-forbidden-sql=0; Instanz nextnotes-m6e1 sqlite up
- Gegenprobe (beide reorder-Guards aus): Sortieren A→Ordner B 404 (findList nur role=note) → Blocker praktisch nicht ausnutzbar, jetzt explizit 403
- Lauf sqlite (Assignments, MaterialIsolation, Access\IsolationTest, CoreScenario): OK 167 Tests, 7535 Assertions, 15:13 min, Exit 0; Instanz abgebaut
## Nächster Schritt
- Bericht an Lead
## Offen
- reorder Ordnerwechsel prüft jetzt requireFolder(write) auf Zielordner (auch Material) – Verhaltensänderung für normale Notizen
