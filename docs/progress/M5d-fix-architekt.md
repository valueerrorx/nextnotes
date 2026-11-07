# M5d-fix Architekt — Status: fertig
Auftrag: Hinweise 1–5 aus docs/reviews/M5d-r1.md umsetzen (R16 JSON-Farben, share_workspace ohne allowed_users → 400, Kanban-Docblock, update.sh/Demo-Doku, docker.mjs-Guard). Tests + E2E teams-m5d (NN_PROJECT=nextnotes-m5df).
## Erledigt
- H1: WorkspaceService::paletteOwner; NoteSerializer, FolderService::folderView(uid), Note/FolderService::setColor → [color, hex]; Controller angepasst (php -l ok)
- H2: CompatController::shareList ohne null/''-Sonderfall (→400); PageIsolationTest Z.421 auf '[]' + 2 neue 400-Asserts; SPEC §(workspaces.php) + ADR 0014 Nachtrag
- H3: Kanban-Docblock verschoben
- H4: update.sh apps=$(…) statt Pipe (bash -n ok, nicht ausgeführt); neu dev/demo/README.md (Konten/Passwörter)
- H5: docker.mjs guard() in ncContainer (Port 8090, Name/Projekt nextnotes-demo|nc35-gftest); node --check ok
- TeamUiTest::testNoteColorPalettes um JSON-API (Notiz/Ordner, PUT color, GET) erweitert
- make build rc0 (161), check-upstream grün, check-forbidden-sql 0
- up.sh sqlite (8491) + install-app + app:enable rc0
- PHPUnit TeamUiTest+ShareDialogTest: OK 7/180 rc0
- PHPUnit Teams+Page+Access+Scenario+Routes: 327 Tests, 1 Failure UserDeletionTest:291 (alte C-Workspaces 2≠0); Einzellauf UserDeletionTest OK 3/191 → reihenfolgeabhängig, nicht vom Fix berührt
- E2E sqlite light teams-m5d: grün, 62 Prüfungen (36 s)
- Guard-Probe: ncContainer(8090) bricht vor docker ab; 8491 → nextnotes-m5df-nc-sqlite-1
- Repro: TeamLifecycleTest + UserDeletionTest zusammen → gleicher Failure :291 (reihenfolgeabhängig; im vollen Lauf läuft Teams nach Scenario)
- down.sh sqlite rc0; Demo unberührt
- SPEC §12 R16 ergänzt (JSON color_hex)
## Nächster Schritt
keiner
## Offen
- Test-Isolation UserDeletionTest nach TeamLifecycleTest (tests/, nicht Scope) → Lead
- tests/ geändert auf Anweisung Lead (PageIsolationTest, TeamUiTest, e2e/lib/docker.mjs)
