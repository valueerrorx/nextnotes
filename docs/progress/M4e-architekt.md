# M4e – Architekt (Umsetzung)

Status: in Arbeit

## Auftrag
Seiten trash, list_tags, list_folders, attachments_list, notes_manager, create + Icon-Leiste (Plan M4-plan.md §M4e). Tests: PageIsolation, XSS, Abfragezähler ≤ 15, E2E pages-m4e hell+dunkel, menu-501 hell, PHPUnit ViewServices 3 DBs.

## Erledigt
- Progress-Datei angelegt
- Bestand gesichtet: PageController, IndexViewService, PageAssets, bundles.json/check-upstream (pages), icon_sidebar, Upstream-Seiten + JS, Tests (PageIsolation, TemplateOutput, Abfragezähler über getInner()->getStats())
- Entwurf: PageFrameService (Workspace wie index, Rahmen) + TrashViewService, TagListViewService, FolderListViewService; Templates je Seite im NC-Rahmen (#nextnotes-root.nextnotes-listpage, Rail wie index ohne has-icon-sidebar); Bündel pages.<seite> = Upstream-Skriptliste; create-Karten-Delegation in nextnotes-late.js

- PHP: NoteMapper (contentPrefixes, countTrashed, findTrashedPage, countByFolder), NoteTagMapper::countsInWorkspace; View/PageFrameService (IndexViewService delegiert Workspace-Auflösung), TrashViewService, TagListViewService, FolderListViewService; PageController (trash, trashSearch POST, listTags, listFolders, attachmentsList, notesManager, create, listPage); routes +7; CspFactory NOTE_VIEWS + trash
- Templates: trash, list_tags, list_folders, attachments_list, notes_manager, create, partials/page_title; partials/icon_sidebar mit 6 Einträgen
- bundles.json pages.<6 Seiten> (Upstream-Listen), css-src/nextnotes-layout.css (Rail-Höhe, Listen-Seiten, list_folders-Stil), js-src: bootstrap replaceQuery (B59), late: create-Karten
- make build rc=0 (146 Dateien)

## Nächster Schritt
- keiner (Übergabe an Lead)

## Offen / Annahmen
- trash ?sort= gilt nur für den Request, kein Setting-Schreiben per GET (B58)
- Trash-Vorschau: erste 16 KiB je Notiz (substring in SQL), Markdown über ConversionService::renderSnapshot
- Seitentitel: Workspace-Chip ohne Umschaltmenü (page-title-workspace-menu.js nicht im Bündel)
- check-upstream grün (nach Kopfkommentar page_title); eigene Instanz nextnotes-m4e (Kopie dev/nextcloud im Scratchpad, Ports 8131–8134), sqlite up rc=0
- E2E pages-m4e (neu) hell grün 156; Befunde behoben: body-Klassen (shared-page/trash-page/… setzen body padding/overflow) entfernt; Rail-Trenner (NC [class^=icon-] min-height) ; --pz-danger auf --color-element-error (NC35 --color-error ist hell, weiße Schrift unlesbar)
- XSS-Abschnitt in pages-m4e: Fund attachments-list.js escapeHtml ohne Anführungszeichen (Attribut-Ausbruch Notiztitel) → src-Patch P23 / B60
- pages-m4e sqlite hell 276 + dunkel 153 grün
- Nächst: PHPUnit (PageIsolation 6 Routen, ViewService-Abfragezähler 1 000, TemplateOutput-Allowlist, Controller-Attribute), SPEC-Doku, nginx-E2E, menu-501, 3-DB-Lauf
- Tests: tests/nextnotes/php/Page/ListPagesTest.php (neu), PageIsolationTest +testListPagesForeignWorkspaceFallsBackToOwn, TemplateOutputTest Allowlist/Inseln, ControllerAttributeTest Allowlists; sqlite gezielt 30 Tests OK rc=0
- SPEC: §4.3.16, §4.6 trash-CSP, §11.1 P23, §11.2 B11 (erledigt), B58–B60, §14 substring/count; upstream-patches.txt + attachments-list.js
- build rc=0, check-upstream grün, check-forbidden-sql 0 Treffer rc=0
- PHPUnit sqlite komplett 349 Tests OK rc=0; mariadb + pgsql ListPagesTest+PageIsolationTest 14 OK rc=0; E2E nginx pages-m4e hell 276/dunkel 153 grün, menu-501 hell 92 grün; mariadb stripped (FORBIDDEN-Liste angepasst) 126, frame 143, tag-filter 63, folder-actions 100 grün; Instanz nextnotes-m4e abgebaut (down.sh all, nur eigene Volumes)

## Status: fertig
