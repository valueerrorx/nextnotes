# UI-tplpick – architekt

Status: fertig · speccritic nötig: ja (Server-Regel geändert, Lead 2026-11-09)

## Auftrag
Erstellen-Dialog Aufgaben: Lupen-Knopf zeigt Notizen ungefiltert; Auswahl und Server-Regel: Vorlage nur aus dem Workspace der Aufgabe (Thomas 2026-11-09). E2E assignment-tplpick.mjs, PHPUnit.

## Analyse (für speccritic)
- Dialog: `js-src/nextnotes-assignments.js` openCreate(). Vorher: Tipp-Suche über alle Team-Workspaces (`island.teamWorkspaces`) via `GET notes/search`, ab 2 Zeichen, limit 20; leerer `q` → 400.
- Datenmodell: Aufgabe hat KEINE Workspace-Spalte, nur `team_id`. Vorlage = eingefrorene Kopie ohne Workspace (`copyAsTemplate`), nur beim Anlegen. Einziger Schreibweg, der die Quelle setzt: `AssignmentService::create`. Austeilen nutzt nur die Vorlage, nie die Quelle (Quelle darf danach gelöscht werden, AssignmentCrudTest).
- Seite `assignments.php` zeigt im Team-Workspace alle Aufgaben des Teams (M6i, mehrere WS desselben Teams zeigen dieselben).
- „Workspace der Aufgabe“ = Workspace, in dem sie angelegt wird (Frame-Workspace, `workspace=` im POST); ohne Parameter der Workspace der Quelle, der dann Team-WS (kind=normal) des Teams sein muss.
- Verschieben der Quell-Notiz in einen anderen Workspace: kein Effekt auf die Aufgabe (Vorlage ist Kopie, `source_note_id` nur Verweis). Nicht neu geregelt.

## Entscheidungen
- UI: Quelle nur aus dem Frame-Workspace (`nextnotes-body`-Insel `data.workspace`), nur wenn er in `teamWorkspaces` steht. Lupe: `GET notes?workspace=&limit=200` (role=note, ohne Papierkorb), Client-Sortierung updated desc, max. 50 + Hinweis; Tipp-Suche `notes/search?workspace=` wie bisher. POST sendet `workspace`.
- Server `requireSourceAllowed` (zentral, nur create): Quelle (nach resolveLinked) in Team-WS kind=normal des Teams UND gleich `workspace=` falls gesetzt. Privat, fremde Freigabe, anderes Team, anderer WS desselben Teams, Abgabe-WS → 403 `source_not_allowed` (bestehendes Muster; Quelle ist schon lesbar, kein Leak). Unbekannter/unlesbarer `workspace` → 404. Papierkorb → 404 (requireNote).
- `createSource` (?create=): zusätzlich nur Notiz aus dem Frame-Workspace.
- Kein Kompatibilitätscode, keine Migration; Austeilen prüft die Quelle nicht (nutzt nur die Vorlage-Kopie).
- Text `sourceNotAllowed` neu: „Nur Notizen dieses Arbeitsbereichs … vorher kopieren“.

## Erledigt
- Worktree auf nextcloud-port (d9883c4b)
- JS: nextnotes-assignments.js (Lupe, Combobox, Scope, payload.workspace); CSS: nextnotes-assignments.css; l10n de/de_DE; AssignmentsViewService (Texte, createSource); AssignmentService::requireSourceAllowed
- SPEC §4.3 Nr. 30 und §9.1 Nr. 1 (Regel)
- Tests: neu tests/nextnotes/php/Assignments/AssignmentSourceWorkspaceTest.php, tests/nextnotes/e2e/assignment-tplpick.mjs; angepasst DistributeTest::testSourceRestriction (privat jetzt 403), AssignmentLifecycleTest::testTeamOnlyWithAssignments (Quelle im Team-WS, danach makePrivate)
- Thomas 2026-11-09: nichts rückwärtskompatibel; Austeilen ohne Quellprüfung (Quelle wird nach Anlegen nie gelesen), keine Sonderfälle

- make -C nextnotes build check-upstream: Exit 0; check-forbidden-sql: 0 Treffer, Exit 0
- Instanz nextnotes-tplpick (sqlite, Port 10501) gestartet, App aktiviert; tests/nextnotes/vendor als Kopie (Symlink geht nicht per tar) – am Ende löschen
- PHPUnit sqlite: Filter Lifecycle+SourceWorkspace OK (11 Tests, 294 Ass.); voller Filter Assignment|Distribute|SubmissionLock|SubmissionState|Material: 82 Tests, 20 Fehler nur Umgebung (Unable to fork / Datei nicht ladbar beim Nutzer-Anlegen, Matrix-Last); Wiederholung der 20 → OK (22 Tests, 802 Ass.)
- AssignmentLifecycleTest O6: makePrivate geht nur bei gelöschtem Team → Workspace per setColumn an L umgehängt
- E2E assignment-tplpick hell+dunkel grün (je 31 Prüfungen)

- E2E Regression hell grün: assignments-m6g 164, teams-m5d 95, material-m6b 144, smoke-m6 48
- pgsql-PHPUnit nicht gelaufen (Matrix-Last im Hauptbaum)
- Aufgeräumt: Links/vendor-Kopie/Logs entfernt, nextnotes-tplpick per down.sh abgebaut

## Nächster Schritt
- keiner (Lead: speccritic Diff-Review, ggf. pgsql)

## Offene Fragen
- `workspace` im POST optional (ohne: Workspace der Quelle gilt). Pflicht machen hieße alle bestehenden Test-Aufrufe ändern (Scope) → Lead entscheiden
- [THOMAS] Aufgabe speichert keinen Workspace; Liste zeigt in allen Team-WS dieselben Aufgaben. Soll die Aufgabe fest an den Anlege-Workspace gebunden und nur dort gelistet werden? (Datenmodell, eigener Slice)
- Bestehende Tests in tests/ angepasst, weil die Regel private Quellen verbietet (Lead bitte bestätigen)
