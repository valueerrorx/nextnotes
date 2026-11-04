# M4e-fix – Architekt

Status: fertig

## Auftrag
Papierkorb „Mehr laden“ (H2), Render-Budget Vorschau (H1), Kontrast Gefahr-Knöpfe dunkel (H3), list_folders in-place ohne Voll-Reload (ggf. P24). Tests pages-m4e hell+dunkel, ListPagesTest sqlite+pgsql.

## Erledigt
- Progress-Datei angelegt
- Bestand gesichtet; Entwurf: Papierkorb Keyset-Cursor (trash_more.php GET, Fragment partials/trash_cards.php), Budget 1 MiB; list_folders Fragment per X-Poznote-Fragment: folders_list + P24 (list_folders.js, utils-folder-tree.js, utils-menus.js)
- Eigene Instanz: Scratchpad nc/ (Projekt nextnotes-m4efix, Ports 8141–8144), up sqlite+pgsql gestartet

- Code: NoteMapper (Keyset after in countTrashed/findTrashedPage), TrashViewService (Cursor, remaining, RENDER_BUDGET, Aufgaben-Kürzung), PageController (Fragmente trash_cards, folders_list), templates partials/trash_cards.php, partials/folder_rows.php, fragment_trash.php, fragment_folders.php; trash.php/list_folders.php nutzen Partials; late.js Mehr laden; tokens.css Gefahr-Schrift + hover; layout.css .trash-load-more
- P24: list_folders.js (refreshFolderList), utils-folder-tree.js (showAddedTreeRow, saveFolderName), utils-menus.js (syncSpeedDialAfterFolderChange); upstream-patches.txt +2
- build rc=0 (146), check-upstream grün, check-forbidden-sql 0 rc=0

- Tests: ListPagesTest (+Fragmente/fremder WS, +testTrashCursorPagesThroughAll, +testTrashPreviewRenderBudget, Abfragezähler trash-mehr), TemplateOutputTest Allowlist partials/trash_cards.php
- Befund: ganz geladene Aufgabenlisten (> 16 KiB) wurden nie gerendert ($cut über volle Länge) → json_decode immer, gekürzt = ungültig = leer
- Instanz: install-app + app:enable sqlite/pgsql; PHPUnit sqlite ListPagesTest 4 OK rc=0

- E2E pages-m4e erweitert (1b Mehr laden + H3-Kontrast per Canvas-Farbnormalisierung, Ordner in-place); Lauf 1 sqlite: hell 1 Fehler (oklab-Farbe nicht geparst, Testfehler), dunkel 177 grün
- SPEC §4.3.16 (Mehr laden, Budget, Gefahr-Schrift, Ordner-Fragment), §11.1 P24

- E2E sqlite pages-m4e hell 301 + dunkel 177 grün rc=0 (Kontrast hell 6,03, dunkel 5,56/6,21); Regression THEMES=light folder-actions 100, tree-inplace 148, tag-filter 63 grün rc=0
- PHPUnit ListPages/TemplateOutput/PageIsolation/ControllerAttribute: sqlite 32 OK rc=0, pgsql 32 OK rc=0
- build rc=0 (146), check-upstream grün, check-forbidden-sql 0 rc=0; Instanz down.sh all rc=0, 0 Container/Volumes m4efix; demo/gftest unberührt

## Nächster Schritt
- keiner (Übergabe an Lead)

## Offen / Annahmen
