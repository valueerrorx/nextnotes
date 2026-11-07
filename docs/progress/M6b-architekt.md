# M6b – Architekt (Material-Oberfläche)

Status: fertig

## Auftrag
M6-plan §2.6/Done M6b (inkl. 3a): Material-Kennzeichen im Ordnermenü/-baum, Nur-Lesen für Schüler, Duplikat-Meldung Q1, Demo-Seed, E2E `material-m6b`. src-Patches ab P34 (P33 = M6c, `mention-autocomplete.js` nicht anfassen).

## Erledigt
- Plan gelesen

## Nächster Schritt
- Code-Erkundung (Adapter, Views, Ordnermenü)

## Offene Punkte/Annahmen
- Erkundung: Material-Menge per `FolderService::materialSet($byId)` ohne Abfrage (Done-8-Test M6a bleibt: Material-Cache leer, gleiche Abfragezahl); Lehrkraft = `canManageTeam` immer in Team-Workspaces; Hooks: populateFolderActionsMenu/populateNoteActionsMenu (utils-menus.js), duplicateNote (utils-note-create.js) → kein src-Patch nötig (geplant)
- IndexViewService: rights()/materialCopy()/folderWritable(), Baum material/writable, Notiz canWrite je Notiz + materialCopy, view.material (canMark, Texte)
- Templates: folders_display.php/notes_list.php (data-nn-write/-material/-can-mark/-material-copy, Symbol, draggable/dblaction/Schnellknopf je Ordner), note_display.php (data-nn-material-copy)
- KanbanViewService (Material im Zweig → Board nur lesen für Mitglieder), FolderListViewService + folder_rows.php (Symbol, writable je Zeile), TaskService::index `readonly` je Notiz
- P34 src/public/js/tasks-page.js (Kästchen disabled bei readonly) + upstream-patches.txt
- TeamsUiConfig Texte + l10n de/de_DE (10 Schlüssel), CSS nextnotes-layout.css (Symbol, nn-write-hidden, nn-copy-notice)
- Adapter nextnotes-teams.js: Menü-Wrapper populateFolder/NoteActionsMenu, Material-Eintrag (PUT, 409/403), Ordnerliste, Drop-Abwehr, duplicateNote Q1 + Hinweis; bundles.json adapterHooks (+7)
- SPEC §11.1 P34 eingetragen
- SPEC §3 Absatz Material-Oberfläche
- Demo-Seed dev/demo/update.sh (seed_material per App-API als thomas, idempotent; nicht ausgeführt)
- Instanz nextnotes-m6b nginx (Basis 8280, Port 8284) im Hintergrund gestartet
- Funde E2E: events-drag-drop.js setzt draggable immer → Adapter bricht dragstart ohne Schreibrecht ab; P11 setzt Nur-Lesen vor dem Tasklist-Rendern → Adapter umhüllt renderTaskList (Eingaben disabled) + CSS (kein Eingabefeld/Ziehgriff); Hook in bundles.json
- E2E material-m6b nginx hell+dunkel: grün (114 Prüfungen je Theme), Exit 0
- Nächster Schritt: Regression smoke-m5, teams-m5d, folder-actions, editor (hell); Screenshots ansehen; Demo-Seed-Snippet gegen Testinstanz prüfen
- Plan §9 Umsetzung M6b
- Nach E2E-Lauf geändert (noch nicht im Container): CSS Symbol größer/geerbt 0.45, Tasklist-Aktionsknöpfe ausgeblendet; IndexViewService Lehrkraft-Abfrage nur mit Ordnern → nach Regression neu bauen, material-m6b erneut
- Regression hell läuft: smoke-m5 grün, teams-m5d grün, folder-actions/editor offen
- Regression hell nginx smoke-m5/teams-m5d/folder-actions/editor grün (Exit 0); material-m6b nach Rebuild hell+dunkel grün
- Nächster Schritt: sqlite up, PHPUnit gefiltert, Seed-Snippet prüfen
- PHPUnit sqlite gefiltert (Material|TemplateOutput|ViewsM4g|ListPages|PageIsolation|EditorPage|TasksTest|Kanban|FilterReadable|IsolationTest|Team): OK 256 Tests, Exit 0
- Seed-Snippet gegen Testinstanz sqlite zweimal: Ordner Material gekennzeichnet (200), 2 Notizen, idempotent; curl_close entfernt (PHP 8.5 deprecated)
- Abschluss: make build 0, check-upstream 0, check-forbidden-sql 0 Treffer; Instanz nextnotes-m6b abgebaut (down.sh all 0)
